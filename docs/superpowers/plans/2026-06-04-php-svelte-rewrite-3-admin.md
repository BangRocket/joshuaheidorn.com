# PHP + Svelte Rewrite — Plan 3: Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A single-user admin at `/admin` to author/manage all content (posts, projects, pages, media, resume, skills, settings, menu) in the browser, writing to the MySQL the public site reads.

**Architecture:** Session auth (PHP native sessions, started only for `/admin`), CSRF on every mutating form, an auth middleware guarding the `/admin` group. A generic content controller drives posts/projects/pages CRUD from a per-type config (3 concrete usages → one shared controller + two templates). Media uploads land in `public/uploads`. Resume/skills/settings are edited as validated JSON documents that the controllers expand into the normalized tables, so the public repositories are unchanged.

**Tech Stack:** PHP 8.x, Slim 4, Twig, PDO (all from Plans 1–2). No new Composer deps.

**Spec:** `docs/superpowers/specs/2026-06-04-php-svelte-rewrite-design.md`
**Depends on:** Plans 1–2 (schema, repositories, Slim app, public site all present).

## Prerequisites

- Docker MySQL up (`docker compose up -d`), dev DB seeded (`php bin/seed.php`).
- This harness: prefix `php`/`composer`/`vendor/bin/*` Bash calls with `dangerouslyDisableSandbox: true`.

## Decisions specific to this plan

- **Resume/skills/settings via JSON-document editors.** The repeatable-row form UI is deferred; the admin shows a `<textarea>` of the current JSON, validates on save, and rewrites the normalized tables (`resume_meta`/`experience`/`education`, `skill_categories`/`skills`, `settings`/`menu_items`). Storage stays normalized; only the editing UX is simplified. (Documented spec fallback.)
- **Tags/categories** are managed inline on the post/project forms (comma-separated); the controller slugifies, upserts `terms`, and syncs `term_relationships`. No separate taxonomy admin.
- **Single user.** Created via `bin/user.php`. No registration/multi-user.

## File Structure (created by this plan)

```
.env.example                      # + ADMIN_EMAIL, ADMIN_PASSWORD, APP_ENV
bin/user.php                      # create/update the admin user
app/Support/Auth.php              # session login/check/logout
app/Support/Csrf.php              # CSRF token issue/validate
app/Middleware/AuthMiddleware.php # starts session, guards /admin
app/Repositories/UserRepository.php
app/Repositories/MediaRepository.php
app/Repositories/AdminContentRepository.php  # generic CRUD for posts/projects/pages
app/Repositories/TaxonomyWriteRepository.php # upsert terms + sync relationships
app/Repositories/ResumeWriteRepository.php
app/Repositories/SettingsWriteRepository.php
app/Controllers/Admin/AuthController.php
app/Controllers/Admin/DashboardController.php
app/Controllers/Admin/ContentController.php   # generic posts/projects/pages
app/Controllers/Admin/MediaController.php
app/Controllers/Admin/ResumeController.php
app/Controllers/Admin/SettingsController.php
app/views/admin/layout.twig
app/views/admin/login.twig
app/views/admin/dashboard.twig
app/views/admin/content_list.twig
app/views/admin/content_form.twig
app/views/admin/media.twig
app/views/admin/resume.twig
app/views/admin/settings.twig
public/css/admin.css
tests/Support/AuthTest.php
tests/Support/CsrfTest.php
tests/Repositories/AdminContentRepositoryTest.php
```

---

## Task 1: Auth + CSRF foundation

**Files:**
- Modify: `.env.example`
- Create: `app/Support/Auth.php`, `app/Support/Csrf.php`, `app/Repositories/UserRepository.php`, `bin/user.php`, `tests/Support/AuthTest.php`, `tests/Support/CsrfTest.php`

- [ ] **Step 1: Add admin env vars to `.env.example`**

Append:
```dotenv
APP_ENV=dev
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=change-me
```
Then copy the same keys into your local `.env` with a real password.

- [ ] **Step 2: Write `tests/Support/CsrfTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_token_is_stable_within_session(): void
    {
        $this->assertSame(Csrf::token(), Csrf::token());
    }

    public function test_validate_accepts_current_token_rejects_others(): void
    {
        $token = Csrf::token();
        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('nope'));
        $this->assertFalse(Csrf::validate(null));
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/phpunit --filter CsrfTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Write `app/Support/Csrf.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }
}
```

- [ ] **Step 5: Write `tests/Support/AuthTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\UserRepository;
use App\Support\Auth;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function test_attempt_succeeds_with_correct_password(): void
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('DELETE FROM users');

        $repo = new UserRepository($pdo);
        $repo->upsert('admin@test.local', 'Admin', 's3cret-pass');

        $_SESSION = [];
        $this->assertTrue(Auth::attempt($pdo, 'admin@test.local', 's3cret-pass'));
        $this->assertTrue(Auth::check());
        $this->assertFalse(Auth::attempt($pdo, 'admin@test.local', 'wrong'));
    }
}
```

- [ ] **Step 6: Write `app/Repositories/UserRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function upsert(string $email, string $name, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $this->findByEmail($email);
        if ($existing) {
            $stmt = $this->pdo->prepare('UPDATE users SET name = :n, password_hash = :h WHERE id = :id');
            $stmt->execute(['n' => $name, 'h' => $hash, 'id' => $existing['id']]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash) VALUES (:e, :n, :h)'
        );
        $stmt->execute(['e' => $email, 'n' => $name, 'h' => $hash]);
    }
}
```

- [ ] **Step 7: Write `app/Support/Auth.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\UserRepository;
use PDO;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
            ]);
            session_start();
        }
    }

    public static function attempt(PDO $pdo, string $email, string $password): bool
    {
        $user = (new UserRepository($pdo))->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['uname'] = $user['name'];
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
```

- [ ] **Step 8: Write `bin/user.php`**

```php
<?php

declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';

$email = $_ENV['ADMIN_EMAIL'] ?? null;
$password = $_ENV['ADMIN_PASSWORD'] ?? null;
$name = $_ENV['ADMIN_NAME'] ?? 'Admin';

if (!$email || !$password) {
    fwrite(STDERR, "Set ADMIN_EMAIL and ADMIN_PASSWORD in .env first.\n");
    exit(1);
}

$pdo = App\Support\Database::connect($config['db']);
(new App\Repositories\UserRepository($pdo))->upsert($email, $name, $password);

echo "Admin user {$email} created/updated.\n";
```

- [ ] **Step 9: Run tests**

Run: `vendor/bin/phpunit --filter "AuthTest|CsrfTest"`
Expected: PASS (3 tests).

- [ ] **Step 10: Create the admin user + commit**

Run:
```bash
php bin/user.php
```
Expected: "Admin user ... created/updated." Then:
```bash
git add .env.example app/Support/Auth.php app/Support/Csrf.php app/Repositories/UserRepository.php bin/user.php tests/Support/AuthTest.php tests/Support/CsrfTest.php
git commit -m "Add auth + CSRF foundation and admin user CLI"
```

---

## Task 2: Auth middleware, admin layout, login + dashboard

**Files:**
- Create: `app/Middleware/AuthMiddleware.php`, `app/Controllers/Admin/AuthController.php`, `app/Controllers/Admin/DashboardController.php`, `app/views/admin/layout.twig`, `app/views/admin/login.twig`, `app/views/admin/dashboard.twig`, `public/css/admin.css`
- Modify: `app/routes.php`, `app/bootstrap.php` (exclude `/admin` from public cache)

- [ ] **Step 1: Write `app/Middleware/AuthMiddleware.php`**

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        Auth::start();
        $path = $request->getUri()->getPath();

        if (!Auth::check() && $path !== '/admin/login') {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 2: Write `app/Controllers/Admin/AuthController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Auth;
use App\Support\Csrf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(private Twig $twig, private PDO $pdo)
    {
    }

    public function loginForm(Request $request, Response $response): Response
    {
        if (Auth::check()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return $this->twig->render($response, 'admin/login.twig', [
            'csrf' => Csrf::token(),
            'error' => null,
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $this->twig->render($response->withStatus(400), 'admin/login.twig', [
                'csrf' => Csrf::token(), 'error' => 'Invalid session token. Try again.',
            ]);
        }
        if (Auth::attempt($this->pdo, (string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''))) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return $this->twig->render($response->withStatus(401), 'admin/login.twig', [
            'csrf' => Csrf::token(), 'error' => 'Invalid email or password.',
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        Auth::logout();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }
}
```

- [ ] **Step 3: Write `app/Controllers/Admin/DashboardController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(private Twig $twig, private PDO $pdo)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $counts = [];
        foreach (['posts', 'projects', 'pages', 'media'] as $t) {
            $counts[$t] = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        }
        return $this->twig->render($response, 'admin/dashboard.twig', [
            'user' => $_SESSION['uname'] ?? 'Admin',
            'counts' => $counts,
        ]);
    }
}
```

- [ ] **Step 4: Write `app/views/admin/layout.twig`**

```twig
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>{% block title %}Admin{% endblock %} — Admin</title>
    <link rel="stylesheet" href="/css/admin.css" />
</head>
<body class="admin">
    <header class="admin-bar">
        <a href="/admin" class="admin-brand">Admin</a>
        <nav class="admin-nav">
            <a href="/admin/posts">Posts</a>
            <a href="/admin/projects">Projects</a>
            <a href="/admin/pages">Pages</a>
            <a href="/admin/media">Media</a>
            <a href="/admin/resume">Resume</a>
            <a href="/admin/settings">Settings</a>
            <a href="/" target="_blank">View site ↗</a>
        </nav>
        <form method="post" action="/admin/logout" class="admin-logout">
            <button type="submit">Log out</button>
        </form>
    </header>
    <main class="admin-main">{% block content %}{% endblock %}</main>
</body>
</html>
```

- [ ] **Step 5: Write `app/views/admin/login.twig`**

```twig
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Log in — Admin</title>
    <link rel="stylesheet" href="/css/admin.css" />
</head>
<body class="admin admin-login-page">
    <form method="post" action="/admin/login" class="admin-login">
        <h1>Admin</h1>
        {% if error %}<p class="admin-error">{{ error }}</p>{% endif %}
        <input type="hidden" name="csrf" value="{{ csrf }}" />
        <label>Email<input type="email" name="email" required autofocus /></label>
        <label>Password<input type="password" name="password" required /></label>
        <button type="submit">Log in</button>
    </form>
</body>
</html>
```

- [ ] **Step 6: Write `app/views/admin/dashboard.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% block title %}Dashboard{% endblock %}
{% block content %}
<h1>Welcome, {{ user }}</h1>
<div class="admin-cards">
    <a class="admin-card" href="/admin/posts"><strong>{{ counts.posts }}</strong> Posts</a>
    <a class="admin-card" href="/admin/projects"><strong>{{ counts.projects }}</strong> Projects</a>
    <a class="admin-card" href="/admin/pages"><strong>{{ counts.pages }}</strong> Pages</a>
    <a class="admin-card" href="/admin/media"><strong>{{ counts.media }}</strong> Media</a>
</div>
{% endblock %}
```

- [ ] **Step 7: Write `public/css/admin.css`** (utilitarian, not retro — admin chrome)

```css
:root { --a-border:#d0d0d0; --a-bg:#fff; --a-text:#1a1a1a; --a-accent:#0066cc; --a-muted:#666; }
* { box-sizing: border-box; }
body.admin { margin:0; font-family: system-ui, sans-serif; color:var(--a-text); background:#f5f5f5; line-height:1.5; }
.admin-bar { display:flex; align-items:center; gap:1.5rem; padding:0.75rem 1.5rem; background:#111; color:#fff; }
.admin-brand { font-weight:700; color:#fff; text-decoration:none; }
.admin-nav { display:flex; gap:1rem; flex:1; flex-wrap:wrap; }
.admin-nav a { color:#ddd; text-decoration:none; font-size:0.9rem; }
.admin-nav a:hover { color:#fff; }
.admin-logout button { background:none; border:1px solid #555; color:#ddd; padding:0.3rem 0.7rem; border-radius:4px; cursor:pointer; }
.admin-main { max-width:900px; margin:2rem auto; padding:0 1.5rem; }
.admin-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; }
.admin-card { display:block; padding:1.5rem; background:var(--a-bg); border:1px solid var(--a-border); border-radius:8px; text-decoration:none; color:var(--a-text); }
.admin-card strong { display:block; font-size:2rem; }
.admin-login-page { display:grid; place-items:center; min-height:100vh; }
.admin-login { background:#fff; padding:2rem; border:1px solid var(--a-border); border-radius:8px; width:320px; display:flex; flex-direction:column; gap:1rem; }
.admin-login label, .admin-form label { display:flex; flex-direction:column; gap:0.3rem; font-size:0.9rem; font-weight:500; }
.admin-login input, .admin-form input, .admin-form textarea, .admin-form select { padding:0.5rem; border:1px solid var(--a-border); border-radius:4px; font:inherit; width:100%; }
.admin-login button, .admin-form button, .admin-actions button { background:var(--a-accent); color:#fff; border:none; padding:0.6rem 1.2rem; border-radius:4px; cursor:pointer; font:inherit; }
.admin-error { color:#b00020; margin:0; }
.admin-table { width:100%; border-collapse:collapse; background:#fff; }
.admin-table th, .admin-table td { text-align:left; padding:0.6rem 0.8rem; border-bottom:1px solid var(--a-border); }
.admin-table a { color:var(--a-accent); }
.admin-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; }
.admin-form { display:flex; flex-direction:column; gap:1rem; background:#fff; padding:1.5rem; border:1px solid var(--a-border); border-radius:8px; }
.admin-form textarea { min-height:300px; font-family:ui-monospace,monospace; }
.admin-actions { display:flex; gap:0.75rem; align-items:center; }
.admin-actions .danger { background:#b00020; }
.admin-media-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:1rem; }
.admin-media-grid figure { margin:0; background:#fff; border:1px solid var(--a-border); border-radius:6px; padding:0.5rem; font-size:0.75rem; word-break:break-all; }
.admin-media-grid img { width:100%; height:90px; object-fit:cover; border-radius:4px; }
.admin-hint { color:var(--a-muted); font-size:0.85rem; }
```

- [ ] **Step 8: Wire the admin group in `app/routes.php`** (add before the closing `};`)

```php
    // ----- Admin -----
    $authCtrl = new \App\Controllers\Admin\AuthController($twig, $pdo);
    $dashCtrl = new \App\Controllers\Admin\DashboardController($twig, $pdo);

    $app->get('/admin/login', [$authCtrl, 'loginForm']);
    $app->post('/admin/login', [$authCtrl, 'login']);
    $app->post('/admin/logout', [$authCtrl, 'logout']);
    $app->get('/admin', [$dashCtrl, 'index']);

    // Guard everything under /admin (login redirect handled inside the middleware).
    $app->add(new \App\Middleware\AuthMiddleware());
```

> Note: `$app->add(...)` adds middleware globally, but `AuthMiddleware` only redirects when the path is under `/admin` is NOT what we wrote — it redirects for any unauthenticated path except `/admin/login`. Fix: scope it. Replace the global add with a route group. See Step 9.

- [ ] **Step 9: Use a route group so the middleware only guards `/admin`**

Replace the admin wiring from Step 8 with:
```php
    $authCtrl = new \App\Controllers\Admin\AuthController($twig, $pdo);
    $dashCtrl = new \App\Controllers\Admin\DashboardController($twig, $pdo);
    $contentCtrl = new \App\Controllers\Admin\ContentController($twig, $pdo); // Task 3
    $mediaCtrl = new \App\Controllers\Admin\MediaController($twig, $pdo);     // Task 4
    $adminResumeCtrl = new \App\Controllers\Admin\ResumeController($twig, $pdo); // Task 5
    $adminSettingsCtrl = new \App\Controllers\Admin\SettingsController($twig, $pdo); // Task 6

    $app->group('/admin', function ($group) use ($authCtrl, $dashCtrl, $contentCtrl, $mediaCtrl, $adminResumeCtrl, $adminSettingsCtrl) {
        $group->get('/login', [$authCtrl, 'loginForm']);
        $group->post('/login', [$authCtrl, 'login']);
        $group->post('/logout', [$authCtrl, 'logout']);
        $group->get('', [$dashCtrl, 'index']);

        foreach (['posts', 'projects', 'pages'] as $type) {
            $group->get("/{$type}", [$contentCtrl, 'index']);
            $group->get("/{$type}/new", [$contentCtrl, 'create']);
            $group->post("/{$type}", [$contentCtrl, 'store']);
            $group->get("/{$type}/{id}/edit", [$contentCtrl, 'edit']);
            $group->post("/{$type}/{id}", [$contentCtrl, 'update']);
            $group->post("/{$type}/{id}/delete", [$contentCtrl, 'destroy']);
        }

        $group->get('/media', [$mediaCtrl, 'index']);
        $group->post('/media', [$mediaCtrl, 'upload']);
        $group->post('/media/{id}/delete', [$mediaCtrl, 'destroy']);

        $group->get('/resume', [$adminResumeCtrl, 'edit']);
        $group->post('/resume', [$adminResumeCtrl, 'save']);

        $group->get('/settings', [$adminSettingsCtrl, 'edit']);
        $group->post('/settings', [$adminSettingsCtrl, 'save']);
    })->add(new \App\Middleware\AuthMiddleware());
```

> The `ContentController`, `MediaController`, admin `ResumeController`, and `SettingsController` are built in Tasks 3–6. To verify Task 2 alone, temporarily wire only `/login`, `/logout`, and `` (dashboard) inside the group and add the rest as those tasks land. The `ContentController->index` etc. must exist before this full group parses — so build Task 3–6 controllers before replacing the group, or stub them. Simplest: do Steps 8–9 wiring incrementally as each controller is created.

- [ ] **Step 10: Exclude `/admin` from public cache in `app/bootstrap.php`**

Change the cache middleware condition:
```php
    if ($request->getMethod() === 'GET'
        && !str_starts_with($request->getUri()->getPath(), '/api')
        && !str_starts_with($request->getUri()->getPath(), '/admin')) {
        return $response->withHeader('Cache-Control', 'public, max-age=300');
    }
```

- [ ] **Step 11: Verify login flow** (wire only login/logout/dashboard in the group for now)

Run:
```bash
php -S 127.0.0.1:8088 -t public public/router.php &
sleep 1
echo "unauth /admin -> $(curl -s -o /dev/null -w '%{redirect_url} %{http_code}' http://127.0.0.1:8088/admin)"
echo "login page -> $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8088/admin/login)"
kill %1
```
Expected: `/admin` → 302 to `/admin/login`; login page 200.

- [ ] **Step 12: Commit**

```bash
git add app/Middleware app/Controllers/Admin/AuthController.php app/Controllers/Admin/DashboardController.php app/views/admin app/routes.php app/bootstrap.php public/css/admin.css
git commit -m "Add admin auth middleware, login, and dashboard"
```

---

## Task 3: Generic content CRUD (posts / projects / pages)

A per-type config drives one controller + two templates. Tags/categories are edited as comma-separated text and synced to `terms`/`term_relationships`.

**Files:**
- Create: `app/Repositories/AdminContentRepository.php`, `app/Repositories/TaxonomyWriteRepository.php`, `app/Controllers/Admin/ContentController.php`, `app/views/admin/content_list.twig`, `app/views/admin/content_form.twig`, `tests/Repositories/AdminContentRepositoryTest.php`

- [ ] **Step 1: Write `app/Repositories/AdminContentRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminContentRepository
{
    /** Column whitelist per type — only these are ever written. */
    private const COLUMNS = [
        'posts' => ['slug', 'title', 'excerpt', 'body_md', 'featured_image_id', 'status', 'published_at'],
        'projects' => ['slug', 'title', 'summary', 'body_md', 'source_url', 'external_url', 'featured', 'featured_image_id', 'status', 'published_at'],
        'pages' => ['slug', 'title', 'body_md', 'status', 'published_at'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function all(string $type): array
    {
        $this->assertType($type);
        return $this->pdo->query("SELECT id, title, slug, status, published_at FROM `{$type}` ORDER BY COALESCE(published_at, created_at) DESC")->fetchAll();
    }

    public function find(string $type, int $id): ?array
    {
        $this->assertType($type);
        $stmt = $this->pdo->prepare("SELECT * FROM `{$type}` WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $data @return int inserted id */
    public function create(string $type, array $data): int
    {
        $this->assertType($type);
        $cols = array_values(array_filter(self::COLUMNS[$type], fn ($c) => array_key_exists($c, $data)));
        $place = array_map(fn ($c) => ':' . $c, $cols);
        $sql = "INSERT INTO `{$type}` (" . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bind($cols, $data));
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(string $type, int $id, array $data): void
    {
        $this->assertType($type);
        $cols = array_values(array_filter(self::COLUMNS[$type], fn ($c) => array_key_exists($c, $data)));
        $set = implode(',', array_map(fn ($c) => "`{$c}` = :{$c}", $cols));
        $stmt = $this->pdo->prepare("UPDATE `{$type}` SET {$set} WHERE id = :id");
        $stmt->execute($this->bind($cols, $data) + ['id' => $id]);
    }

    public function delete(string $type, int $id): void
    {
        $this->assertType($type);
        $stmt = $this->pdo->prepare("DELETE FROM `{$type}` WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    private function bind(array $cols, array $data): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $data[$c];
        }
        return $out;
    }

    private function assertType(string $type): void
    {
        if (!isset(self::COLUMNS[$type])) {
            throw new \InvalidArgumentException("Unknown content type: {$type}");
        }
    }
}
```

- [ ] **Step 2: Write `app/Repositories/TaxonomyWriteRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Slug;
use PDO;

final class TaxonomyWriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    private function termId(string $taxonomy, string $label): int
    {
        $slug = Slug::make($label);
        $stmt = $this->pdo->prepare('SELECT id FROM terms WHERE taxonomy = :t AND slug = :s LIMIT 1');
        $stmt->execute(['t' => $taxonomy, 's' => $slug]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $ins = $this->pdo->prepare('INSERT INTO terms (taxonomy, slug, label) VALUES (:t, :s, :l)');
        $ins->execute(['t' => $taxonomy, 's' => $slug, 'l' => $label]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Replace all relationships of one taxonomy for a content item.
     * @param array<int,string> $labels
     */
    public function sync(string $taxonomy, string $contentType, int $contentId, array $labels): void
    {
        // Remove existing relationships for this taxonomy + content item.
        $del = $this->pdo->prepare(
            'DELETE tr FROM term_relationships tr
             JOIN terms t ON t.id = tr.term_id
             WHERE t.taxonomy = :tax AND tr.content_type = :type AND tr.content_id = :cid'
        );
        $del->execute(['tax' => $taxonomy, 'type' => $contentType, 'cid' => $contentId]);

        $ins = $this->pdo->prepare(
            'INSERT INTO term_relationships (term_id, content_type, content_id) VALUES (:term, :type, :cid)'
        );
        foreach (array_unique(array_filter(array_map('trim', $labels))) as $label) {
            $ins->execute(['term' => $this->termId($taxonomy, $label), 'type' => $contentType, 'cid' => $contentId]);
        }
    }

    /** @return array<int,string> labels for a content item's taxonomy */
    public function labels(string $taxonomy, string $contentType, int $contentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.label FROM term_relationships tr
             JOIN terms t ON t.id = tr.term_id
             WHERE t.taxonomy = :tax AND tr.content_type = :type AND tr.content_id = :cid
             ORDER BY t.label'
        );
        $stmt->execute(['tax' => $taxonomy, 'type' => $contentType, 'cid' => $contentId]);
        return array_column($stmt->fetchAll(), 'label');
    }
}
```

- [ ] **Step 3: Write `app/Controllers/Admin/ContentController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\AdminContentRepository;
use App\Repositories\MediaRepository;
use App\Repositories\TaxonomyWriteRepository;
use App\Support\Csrf;
use App\Support\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ContentController
{
    /** type => [singular, contentType, taxonomies, fields] */
    private const TYPES = [
        'posts' => ['singular' => 'Post', 'contentType' => 'post', 'taxonomies' => ['tag', 'category'], 'hasExcerpt' => true, 'hasSummary' => false, 'hasFeatured' => false, 'hasLinks' => false, 'hasImage' => true, 'urlPrefix' => '/posts/'],
        'projects' => ['singular' => 'Project', 'contentType' => 'project', 'taxonomies' => ['tag'], 'hasExcerpt' => false, 'hasSummary' => true, 'hasFeatured' => true, 'hasLinks' => true, 'hasImage' => true, 'urlPrefix' => '/projects/'],
        'pages' => ['singular' => 'Page', 'contentType' => 'page', 'taxonomies' => [], 'hasExcerpt' => false, 'hasSummary' => false, 'hasFeatured' => false, 'hasLinks' => false, 'hasImage' => false, 'urlPrefix' => '/pages/'],
    ];

    private AdminContentRepository $content;
    private TaxonomyWriteRepository $tax;
    private MediaRepository $media;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->content = new AdminContentRepository($pdo);
        $this->tax = new TaxonomyWriteRepository($pdo);
        $this->media = new MediaRepository($pdo);
    }

    private function typeFromPath(Request $request): string
    {
        // /admin/{type}/...  -> second segment
        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        return $segments[1];
    }

    public function index(Request $request, Response $response): Response
    {
        $type = $this->typeFromPath($request);
        return $this->twig->render($response, 'admin/content_list.twig', [
            'type' => $type,
            'cfg' => self::TYPES[$type],
            'items' => $this->content->all($type),
            'user' => $_SESSION['uname'] ?? 'Admin',
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->form($response, $this->typeFromPath($request), null);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $type = $this->typeFromPath($request);
        $item = $this->content->find($type, (int) $args['id']);
        if (!$item) {
            return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
        }
        return $this->form($response, $type, $item);
    }

    private function form(Response $response, string $type, ?array $item): Response
    {
        $cfg = self::TYPES[$type];
        $tags = [];
        foreach ($cfg['taxonomies'] as $tx) {
            $tags[$tx] = $item ? implode(', ', $this->tax->labels($tx, $cfg['contentType'], (int) $item['id'])) : '';
        }
        return $this->twig->render($response, 'admin/content_form.twig', [
            'type' => $type,
            'cfg' => $cfg,
            'item' => $item,
            'tagValues' => $tags,
            'media' => $this->media->all(),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $type = $this->typeFromPath($request);
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $id = $this->content->create($type, $this->fields($type, $data));
        $this->syncTax($type, $id, $data);
        return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $type = $this->typeFromPath($request);
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $this->content->update($type, $id, $this->fields($type, $data));
        $this->syncTax($type, $id, $data);
        return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $type = $this->typeFromPath($request);
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $this->content->delete($type, (int) $args['id']);
        return $response->withHeader('Location', "/admin/{$type}")->withStatus(302);
    }

    /** Build the column => value map from the submitted form for this type. */
    private function fields(string $type, array $data): array
    {
        $cfg = self::TYPES[$type];
        $title = trim((string) ($data['title'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? '')) ?: Slug::make($title);
        $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $publishedAt = trim((string) ($data['published_at'] ?? ''));

        $fields = [
            'title' => $title,
            'slug' => $slug,
            'body_md' => (string) ($data['body_md'] ?? ''),
            'status' => $status,
            'published_at' => $publishedAt !== '' ? $publishedAt : ($status === 'published' ? date('Y-m-d H:i:s') : null),
        ];
        if ($cfg['hasExcerpt']) {
            $fields['excerpt'] = (string) ($data['excerpt'] ?? '');
        }
        if ($cfg['hasSummary']) {
            $fields['summary'] = (string) ($data['summary'] ?? '');
        }
        if ($cfg['hasLinks']) {
            $fields['source_url'] = trim((string) ($data['source_url'] ?? '')) ?: null;
            $fields['external_url'] = trim((string) ($data['external_url'] ?? '')) ?: null;
        }
        if ($cfg['hasFeatured']) {
            $fields['featured'] = !empty($data['featured']) ? 1 : 0;
        }
        if ($cfg['hasImage']) {
            $fields['featured_image_id'] = ($data['featured_image_id'] ?? '') !== '' ? (int) $data['featured_image_id'] : null;
        }
        return $fields;
    }

    private function syncTax(string $type, int $id, array $data): void
    {
        $cfg = self::TYPES[$type];
        foreach ($cfg['taxonomies'] as $tx) {
            $labels = array_filter(array_map('trim', explode(',', (string) ($data['tax_' . $tx] ?? ''))));
            $this->tax->sync($tx, $cfg['contentType'], $id, $labels);
        }
    }
}
```

- [ ] **Step 4: Write `app/views/admin/content_list.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% block title %}{{ cfg.singular }}s{% endblock %}
{% block content %}
<div class="admin-toolbar">
    <h1>{{ cfg.singular }}s</h1>
    <a class="admin-card" href="/admin/{{ type }}/new">+ New {{ cfg.singular }}</a>
</div>
<table class="admin-table">
    <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Published</th><th></th></tr></thead>
    <tbody>
        {% for item in items %}
            <tr>
                <td><a href="/admin/{{ type }}/{{ item.id }}/edit">{{ item.title }}</a></td>
                <td>{{ item.slug }}</td>
                <td>{{ item.status }}</td>
                <td>{{ item.published_at ? item.published_at|date('Y-m-d') : '—' }}</td>
                <td><a href="{{ cfg.urlPrefix }}{{ item.slug }}" target="_blank">view ↗</a></td>
            </tr>
        {% else %}
            <tr><td colspan="5">None yet.</td></tr>
        {% endfor %}
    </tbody>
</table>
{% endblock %}
```

- [ ] **Step 5: Write `app/views/admin/content_form.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% block title %}{{ item ? 'Edit' : 'New' }} {{ cfg.singular }}{% endblock %}
{% block content %}
<h1>{{ item ? 'Edit' : 'New' }} {{ cfg.singular }}</h1>
<form class="admin-form" method="post" action="/admin/{{ type }}{{ item ? '/' ~ item.id : '' }}">
    <input type="hidden" name="csrf" value="{{ csrf }}" />
    <label>Title<input type="text" name="title" value="{{ item.title }}" required /></label>
    <label>Slug <span class="admin-hint">(blank = from title)</span><input type="text" name="slug" value="{{ item.slug }}" /></label>

    {% if cfg.hasExcerpt %}<label>Excerpt<textarea name="excerpt" style="min-height:80px">{{ item.excerpt }}</textarea></label>{% endif %}
    {% if cfg.hasSummary %}<label>Summary<textarea name="summary" style="min-height:80px">{{ item.summary }}</textarea></label>{% endif %}

    <label>Body (Markdown)<textarea name="body_md">{{ item.body_md }}</textarea></label>

    {% if cfg.hasLinks %}
        <label>Live URL<input type="url" name="external_url" value="{{ item.external_url }}" /></label>
        <label>Source URL<input type="url" name="source_url" value="{{ item.source_url }}" /></label>
    {% endif %}

    {% for tx in cfg.taxonomies %}
        <label>{{ tx|capitalize }}s <span class="admin-hint">(comma-separated)</span><input type="text" name="tax_{{ tx }}" value="{{ tagValues[tx] }}" /></label>
    {% endfor %}

    {% if cfg.hasImage %}
        <label>Featured image
            <select name="featured_image_id">
                <option value="">— none —</option>
                {% for m in media %}<option value="{{ m.id }}" {{ item and item.featured_image_id == m.id ? 'selected' }}>{{ m.filename }}</option>{% endfor %}
            </select>
        </label>
    {% endif %}

    {% if cfg.hasFeatured %}<label style="flex-direction:row;align-items:center;gap:0.5rem"><input type="checkbox" name="featured" value="1" style="width:auto" {{ item and item.featured ? 'checked' }} /> Featured</label>{% endif %}

    <label>Status
        <select name="status">
            <option value="draft" {{ item and item.status == 'draft' ? 'selected' }}>Draft</option>
            <option value="published" {{ item and item.status == 'published' ? 'selected' }}>Published</option>
        </select>
    </label>
    <label>Published at <span class="admin-hint">(YYYY-MM-DD HH:MM:SS, blank = now when publishing)</span><input type="text" name="published_at" value="{{ item.published_at }}" /></label>

    <div class="admin-actions">
        <button type="submit">Save</button>
        <a href="/admin/{{ type }}">Cancel</a>
    </div>
</form>

{% if item %}
<form class="admin-actions" method="post" action="/admin/{{ type }}/{{ item.id }}/delete" onsubmit="return confirm('Delete this {{ cfg.singular|lower }}?')" style="margin-top:1rem">
    <input type="hidden" name="csrf" value="{{ csrf }}" />
    <button type="submit" class="danger">Delete</button>
</form>
{% endif %}
{% endblock %}
```

- [ ] **Step 6: Write `tests/Repositories/AdminContentRepositoryTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\AdminContentRepository;
use Tests\DatabaseTestCase;

final class AdminContentRepositoryTest extends DatabaseTestCase
{
    public function test_create_update_delete_round_trip(): void
    {
        $repo = new AdminContentRepository($this->pdo);

        $id = $repo->create('posts', [
            'slug' => 'hello', 'title' => 'Hello', 'excerpt' => 'Hi',
            'body_md' => '# Hello', 'status' => 'published', 'published_at' => '2026-06-04 12:00:00',
        ]);
        $this->assertGreaterThan(0, $id);

        $repo->update('posts', $id, ['title' => 'Updated', 'slug' => 'hello', 'body_md' => 'x', 'status' => 'draft', 'published_at' => null]);
        $this->assertSame('Updated', $repo->find('posts', $id)['title']);

        $repo->delete('posts', $id);
        $this->assertNull($repo->find('posts', $id));
    }
}
```

- [ ] **Step 7: Wire ContentController routes** — done by Task 2 Step 9's group (it already references `$contentCtrl`). Ensure `MediaController`, admin `ResumeController`, `SettingsController` exist (Tasks 4–6) or wire ContentController routes now and add the others as built.

- [ ] **Step 8: Run the repo test**

Run: `vendor/bin/phpunit --filter AdminContentRepositoryTest`
Expected: PASS.

- [ ] **Step 9: Verify CRUD via curl (authenticated)** — log in to get a session cookie, then create a post.

```bash
php -S 127.0.0.1:8088 -t public public/router.php &
sleep 1
JAR=/tmp/cj.txt
CSRF=$(curl -s -c $JAR http://127.0.0.1:8088/admin/login | grep -o 'name="csrf" value="[^"]*' | sed 's/.*value="//')
curl -s -b $JAR -c $JAR -d "csrf=$CSRF&email=$ADMIN_EMAIL&password=$ADMIN_PASSWORD" http://127.0.0.1:8088/admin/login -o /dev/null
FORMCSRF=$(curl -s -b $JAR http://127.0.0.1:8088/admin/posts/new | grep -o 'name="csrf" value="[^"]*' | sed 's/.*value="//')
curl -s -b $JAR -d "csrf=$FORMCSRF&title=Test+Post&body_md=Hello&status=draft&tax_tag=Demo" http://127.0.0.1:8088/admin/posts -o /dev/null -w "create -> %{http_code}\n"
curl -s -b $JAR http://127.0.0.1:8088/admin/posts | grep -c "Test Post"
kill %1
```
Expected: create → 302; the list contains "Test Post". (Set `ADMIN_EMAIL`/`ADMIN_PASSWORD` env to match `.env` before running.)

- [ ] **Step 10: Commit**

```bash
git add app/Repositories/AdminContentRepository.php app/Repositories/TaxonomyWriteRepository.php app/Controllers/Admin/ContentController.php app/views/admin/content_list.twig app/views/admin/content_form.twig tests/Repositories/AdminContentRepositoryTest.php app/routes.php
git commit -m "Add generic content CRUD admin for posts/projects/pages"
```

---

## Task 4: Media library

**Files:**
- Create: `app/Repositories/MediaRepository.php`, `app/Controllers/Admin/MediaController.php`, `app/views/admin/media.twig`

- [ ] **Step 1: Write `app/Repositories/MediaRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MediaRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM media ORDER BY created_at DESC')->fetchAll();
    }

    public function create(string $filename, string $path, ?int $w, ?int $h, ?string $mime, string $alt = ''): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO media (filename, path, alt, width, height, mime) VALUES (:f, :p, :a, :w, :h, :m)'
        );
        $stmt->execute(['f' => $filename, 'p' => $path, 'a' => $alt, 'w' => $w, 'h' => $h, 'm' => $mime]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
```

- [ ] **Step 2: Write `app/Controllers/Admin/MediaController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\MediaRepository;
use App\Support\Csrf;
use App\Support\Slug;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class MediaController
{
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];

    private MediaRepository $media;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->media = new MediaRepository($pdo);
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/media.twig', [
            'items' => $this->media->all(),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function upload(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withHeader('Location', '/admin/media?error=upload')->withStatus(302);
        }
        $mime = $file->getClientMediaType();
        if (!isset(self::ALLOWED[$mime])) {
            return $response->withHeader('Location', '/admin/media?error=type')->withStatus(302);
        }

        $ext = self::ALLOWED[$mime];
        $base = Slug::make(pathinfo($file->getClientFilename() ?? 'image', PATHINFO_FILENAME)) ?: 'image';
        $name = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $dest = dirname(__DIR__, 3) . '/public/uploads/' . $name;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        $file->moveTo($dest);

        $w = $h = null;
        $info = @getimagesize($dest);
        if ($info !== false) {
            [$w, $h] = $info;
        }
        $this->media->create($name, '/uploads/' . $name, $w, $h, $mime);

        return $response->withHeader('Location', '/admin/media')->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $item = $this->media->find((int) $args['id']);
        if ($item) {
            $path = dirname(__DIR__, 3) . '/public' . $item['path'];
            if (str_starts_with($item['path'], '/uploads/') && is_file($path)) {
                @unlink($path);
            }
            $this->media->delete((int) $item['id']);
        }
        return $response->withHeader('Location', '/admin/media')->withStatus(302);
    }
}
```

- [ ] **Step 3: Write `app/views/admin/media.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% block title %}Media{% endblock %}
{% block content %}
<h1>Media</h1>
{% if error %}<p class="admin-error">Upload failed ({{ error }}).</p>{% endif %}
<form class="admin-form" method="post" action="/admin/media" enctype="multipart/form-data" style="margin-bottom:1.5rem">
    <input type="hidden" name="csrf" value="{{ csrf }}" />
    <label>Upload image <span class="admin-hint">(jpg, png, gif, webp, svg)</span><input type="file" name="file" accept="image/*" required /></label>
    <div class="admin-actions"><button type="submit">Upload</button></div>
</form>
<div class="admin-media-grid">
    {% for m in items %}
        <figure>
            <img src="{{ m.path }}" alt="{{ m.alt }}" />
            <figcaption>{{ m.filename }}</figcaption>
            <form method="post" action="/admin/media/{{ m.id }}/delete" onsubmit="return confirm('Delete this file?')">
                <input type="hidden" name="csrf" value="{{ csrf }}" />
                <button type="submit" class="danger" style="margin-top:0.4rem;font-size:0.75rem;padding:0.2rem 0.5rem">Delete</button>
            </form>
        </figure>
    {% else %}
        <p>No media yet.</p>
    {% endfor %}
</div>
{% endblock %}
```

- [ ] **Step 4: Verify upload** (authenticated; create a tiny PNG and upload)

```bash
php -S 127.0.0.1:8088 -t public public/router.php &
sleep 1
JAR=/tmp/cj.txt
CSRF=$(curl -s -c $JAR http://127.0.0.1:8088/admin/login | grep -o 'name="csrf" value="[^"]*' | sed 's/.*value="//')
curl -s -b $JAR -c $JAR -d "csrf=$CSRF&email=$ADMIN_EMAIL&password=$ADMIN_PASSWORD" http://127.0.0.1:8088/admin/login -o /dev/null
printf '\x89PNG\r\n\x1a\n' > /tmp/x.png  # minimal header; or copy a real png
FCSRF=$(curl -s -b $JAR http://127.0.0.1:8088/admin/media | grep -o 'name="csrf" value="[^"]*' | head -1 | sed 's/.*value="//')
curl -s -b $JAR -F "csrf=$FCSRF" -F "file=@uploads/01KPXYQ4TX1TD6MQB6YJR583KX.jpg" http://127.0.0.1:8088/admin/media -o /dev/null -w "upload -> %{http_code}\n"
curl -s -b $JAR http://127.0.0.1:8088/admin/media | grep -c "admin-media-grid"
kill %1
```
Expected: upload → 302; media grid present.

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/MediaRepository.php app/Controllers/Admin/MediaController.php app/views/admin/media.twig
git commit -m "Add media library (upload/list/delete)"
```

---

## Task 5: Resume + skills editors (JSON documents)

Edit resume + skills as JSON; the controller validates and rewrites the normalized tables.

**Files:**
- Create: `app/Repositories/ResumeWriteRepository.php`, `app/Controllers/Admin/ResumeController.php`, `app/views/admin/resume.twig`

- [ ] **Step 1: Write `app/Repositories/ResumeWriteRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ResumeWriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $resume name/headline/headlines/summary/contact/experience/education */
    public function saveResume(array $resume): void
    {
        $this->pdo->exec('DELETE FROM resume_meta');
        $stmt = $this->pdo->prepare(
            'INSERT INTO resume_meta (name, headline, headlines, summary, contact) VALUES (:n, :h, :hs, :s, :c)'
        );
        $stmt->execute([
            'n' => $resume['name'] ?? '',
            'h' => $resume['headline'] ?? null,
            'hs' => json_encode($resume['headlines'] ?? [], JSON_UNESCAPED_SLASHES),
            's' => $resume['summary'] ?? null,
            'c' => json_encode($resume['contact'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
        ]);

        $this->pdo->exec('DELETE FROM experience');
        $exp = $this->pdo->prepare(
            'INSERT INTO experience (company, role, start, end, location, bullets, tags, sort) VALUES (:co,:ro,:st,:en,:lo,:bu,:ta,:so)'
        );
        foreach (array_values($resume['experience'] ?? []) as $i => $j) {
            $exp->execute([
                'co' => $j['company'] ?? '', 'ro' => $j['role'] ?? '', 'st' => $j['start'] ?? null,
                'en' => $j['end'] ?? null, 'lo' => $j['location'] ?? null,
                'bu' => json_encode($j['bullets'] ?? [], JSON_UNESCAPED_SLASHES),
                'ta' => json_encode($j['tags'] ?? [], JSON_UNESCAPED_SLASHES), 'so' => $i,
            ]);
        }

        $this->pdo->exec('DELETE FROM education');
        $edu = $this->pdo->prepare(
            'INSERT INTO education (school, degree, start, end, location, sort) VALUES (:sc,:de,:st,:en,:lo,:so)'
        );
        foreach (array_values($resume['education'] ?? []) as $i => $e) {
            $edu->execute([
                'sc' => $e['school'] ?? '', 'de' => $e['degree'] ?? '', 'st' => $e['start'] ?? null,
                'en' => $e['end'] ?? null, 'lo' => $e['location'] ?? null, 'so' => $i,
            ]);
        }
    }

    /** @param array<int,array{name:string,items:array}> $categories */
    public function saveSkills(array $categories): void
    {
        $this->pdo->exec('DELETE FROM skills');
        $this->pdo->exec('DELETE FROM skill_categories');
        $cat = $this->pdo->prepare('INSERT INTO skill_categories (name, sort) VALUES (:n, :s)');
        $skill = $this->pdo->prepare('INSERT INTO skills (category_id, name, proficiency, sort) VALUES (:c,:n,:p,:s)');
        foreach (array_values($categories) as $ci => $category) {
            $cat->execute(['n' => $category['name'] ?? '', 's' => $ci]);
            $cid = (int) $this->pdo->lastInsertId();
            foreach (array_values($category['items'] ?? []) as $si => $item) {
                $skill->execute(['c' => $cid, 'n' => $item['name'] ?? '', 'p' => $item['proficiency'] ?? null, 's' => $si]);
            }
        }
    }
}
```

- [ ] **Step 2: Write `app/Controllers/Admin/ResumeController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\ResumeRepository;
use App\Repositories\ResumeWriteRepository;
use App\Support\Csrf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ResumeController
{
    private ResumeRepository $read;
    private ResumeWriteRepository $write;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->read = new ResumeRepository($pdo);
        $this->write = new ResumeWriteRepository($pdo);
    }

    public function edit(Request $request, Response $response): Response
    {
        $meta = $this->read->meta();
        $resume = [
            'name' => $meta['name'], 'headline' => $meta['headline'] ?? '',
            'headlines' => $meta['headlines'], 'summary' => $meta['summary'] ?? '',
            'contact' => $meta['contact'],
            'experience' => $this->read->experience(),
            'education' => $this->read->education(),
        ];
        return $this->twig->render($response, 'admin/resume.twig', [
            'resumeJson' => json_encode($resume, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'skillsJson' => json_encode($this->read->skills(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
            'error' => $request->getQueryParams()['error'] ?? null,
            'saved' => $request->getQueryParams()['saved'] ?? null,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $resume = json_decode((string) ($data['resume'] ?? ''), true);
        $skills = json_decode((string) ($data['skills'] ?? ''), true);
        if (!is_array($resume) || !is_array($skills)) {
            return $response->withHeader('Location', '/admin/resume?error=json')->withStatus(302);
        }
        $this->write->saveResume($resume);
        $this->write->saveSkills($skills);
        return $response->withHeader('Location', '/admin/resume?saved=1')->withStatus(302);
    }
}
```

- [ ] **Step 3: Write `app/views/admin/resume.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% block title %}Resume{% endblock %}
{% block content %}
<h1>Resume &amp; Skills</h1>
{% if saved %}<p class="admin-hint">Saved.</p>{% endif %}
{% if error %}<p class="admin-error">Invalid JSON — nothing was saved.</p>{% endif %}
<form class="admin-form" method="post" action="/admin/resume">
    <input type="hidden" name="csrf" value="{{ csrf }}" />
    <label>Resume JSON <span class="admin-hint">(name, headline, headlines[], summary, contact{}, experience[], education[])</span>
        <textarea name="resume" spellcheck="false">{{ resumeJson }}</textarea>
    </label>
    <label>Skills JSON <span class="admin-hint">([{ name, items: [{ name, proficiency }] }])</span>
        <textarea name="skills" spellcheck="false">{{ skillsJson }}</textarea>
    </label>
    <div class="admin-actions"><button type="submit">Save</button></div>
</form>
{% endblock %}
```

- [ ] **Step 4: Verify** (authenticated GET renders the JSON)

```bash
php -S 127.0.0.1:8088 -t public public/router.php &
sleep 1
JAR=/tmp/cj.txt
CSRF=$(curl -s -c $JAR http://127.0.0.1:8088/admin/login | grep -o 'name="csrf" value="[^"]*' | sed 's/.*value="//')
curl -s -b $JAR -c $JAR -d "csrf=$CSRF&email=$ADMIN_EMAIL&password=$ADMIN_PASSWORD" http://127.0.0.1:8088/admin/login -o /dev/null
curl -s -b $JAR http://127.0.0.1:8088/admin/resume | grep -c '"experience"'
kill %1
```
Expected: ≥1 (the resume JSON renders in the textarea).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/ResumeWriteRepository.php app/Controllers/Admin/ResumeController.php app/views/admin/resume.twig
git commit -m "Add resume + skills JSON editors"
```

---

## Task 6: Settings + menu editor

**Files:**
- Create: `app/Repositories/SettingsWriteRepository.php`, `app/Controllers/Admin/SettingsController.php`, `app/views/admin/settings.twig`

- [ ] **Step 1: Write `app/Repositories/SettingsWriteRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsWriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    /** @param array<int,array{label:string,url:string,target?:string}> $items */
    public function saveMenu(array $items): void
    {
        $this->pdo->exec('DELETE FROM menu_items');
        $stmt = $this->pdo->prepare('INSERT INTO menu_items (label, url, target, sort) VALUES (:l,:u,:t,:s)');
        foreach (array_values($items) as $i => $item) {
            $stmt->execute([
                'l' => $item['label'] ?? '', 'u' => $item['url'] ?? '#',
                't' => $item['target'] ?? null, 's' => $i,
            ]);
        }
    }
}
```

- [ ] **Step 2: Write `app/Controllers/Admin/SettingsController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\SettingsRepository;
use App\Repositories\SettingsWriteRepository;
use App\Support\Csrf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SettingsController
{
    private SettingsRepository $read;
    private SettingsWriteRepository $write;

    public function __construct(private Twig $twig, private PDO $pdo)
    {
        $this->read = new SettingsRepository($pdo);
        $this->write = new SettingsWriteRepository($pdo);
    }

    public function edit(Request $request, Response $response): Response
    {
        $settings = $this->read->all();
        return $this->twig->render($response, 'admin/settings.twig', [
            'settings' => $settings,
            'menuJson' => json_encode($this->read->menu(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'csrf' => Csrf::token(),
            'user' => $_SESSION['uname'] ?? 'Admin',
            'saved' => $request->getQueryParams()['saved'] ?? null,
            'error' => $request->getQueryParams()['error'] ?? null,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $response->withStatus(400);
        }
        $this->write->setSetting('site_title', (string) ($data['site_title'] ?? ''));
        $this->write->setSetting('site_tagline', (string) ($data['site_tagline'] ?? ''));

        $menu = json_decode((string) ($data['menu'] ?? ''), true);
        if (!is_array($menu)) {
            return $response->withHeader('Location', '/admin/settings?error=json')->withStatus(302);
        }
        $this->write->saveMenu($menu);
        return $response->withHeader('Location', '/admin/settings?saved=1')->withStatus(302);
    }
}
```

- [ ] **Step 3: Write `app/views/admin/settings.twig`**

```twig
{% extends 'admin/layout.twig' %}
{% block title %}Settings{% endblock %}
{% block content %}
<h1>Settings</h1>
{% if saved %}<p class="admin-hint">Saved.</p>{% endif %}
{% if error %}<p class="admin-error">Invalid menu JSON — nothing was saved.</p>{% endif %}
<form class="admin-form" method="post" action="/admin/settings">
    <input type="hidden" name="csrf" value="{{ csrf }}" />
    <label>Site title<input type="text" name="site_title" value="{{ settings.site_title }}" /></label>
    <label>Site tagline<input type="text" name="site_tagline" value="{{ settings.site_tagline }}" /></label>
    <label>Primary menu JSON <span class="admin-hint">([{ label, url, target }])</span>
        <textarea name="menu" spellcheck="false">{{ menuJson }}</textarea>
    </label>
    <div class="admin-actions"><button type="submit">Save</button></div>
</form>
{% endblock %}
```

- [ ] **Step 4: Verify + full suite**

```bash
php -S 127.0.0.1:8088 -t public public/router.php &
sleep 1
JAR=/tmp/cj.txt
CSRF=$(curl -s -c $JAR http://127.0.0.1:8088/admin/login | grep -o 'name="csrf" value="[^"]*' | sed 's/.*value="//')
curl -s -b $JAR -c $JAR -d "csrf=$CSRF&email=$ADMIN_EMAIL&password=$ADMIN_PASSWORD" http://127.0.0.1:8088/admin/login -o /dev/null
curl -s -b $JAR -o /dev/null -w "settings -> %{http_code}\n" http://127.0.0.1:8088/admin/settings
kill %1
vendor/bin/phpunit
```
Expected: settings → 200; full suite green.

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/SettingsWriteRepository.php app/Controllers/Admin/SettingsController.php app/views/admin/settings.twig
git commit -m "Add settings + menu editor"
```

---

## Task 7: Cleanup — remove old Astro source, rewrite docs

**Files:**
- Delete: old Astro app files
- Rewrite: `README.md`, `CLAUDE.md`

- [ ] **Step 1: Remove the superseded Astro/EmDash source** (keep `src/data/*.json`, `seed/`, `uploads/` as importer references)

```bash
git rm -r src/pages src/layouts src/components src/styles src/live.config.ts src/worker.ts src/lib src/utils src/components 2>/dev/null
git rm astro.config.mjs wrangler.jsonc emdash-env.d.ts worker-configuration.d.ts tsconfig.json components.json 2>/dev/null
git rm -r .astro .wrangler 2>/dev/null || true
```
> Verify nothing still references these before deleting. `src/data/` must remain.

- [ ] **Step 2: Rewrite `CLAUDE.md`** to describe the PHP + Slim + Twig + MySQL + Svelte stack, the `app/` layout, the Docker MySQL + Homebrew PHP dev setup, the `php bin/seed.php` import, `bin/user.php`, the `yarn build` step, and the test command `vendor/bin/phpunit`. Remove all EmDash/Cloudflare/Astro guidance.

- [ ] **Step 3: Rewrite `README.md`** with the new stack, local setup (docker compose up, composer install, yarn install && yarn build, phinx migrate, seed, create user), and deploy steps (SSH + composer install --no-dev + phinx migrate).

- [ ] **Step 4: Full suite + final route sweep**

Run `vendor/bin/phpunit` (all green) and the public route sweep from Plan 2 Task 15.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Remove legacy Astro/EmDash source; rewrite README and CLAUDE.md"
```

> `git add -A` is acceptable here because this commit is specifically the deletion + docs rewrite; review `git status` first.

---

## Self-Review

**Spec coverage (admin slice):**
- Single-user session auth + CSRF + hardened cookie + login → Tasks 1–2. ✓
- Auth middleware guarding `/admin` → Task 2. ✓
- CRUD for posts/projects/pages (title, auto-slug, Markdown, excerpt/summary, status, published_at, featured, tag/category pickers, featured-image picker) → Task 3. ✓
- Media library (upload/list/delete) → Task 4. ✓
- Resume/skills editors (normalized storage via JSON-doc UI — documented fallback) → Task 5. ✓
- Settings + menu editor → Task 6. ✓
- Cleanup + docs → Task 7. ✓

**Placeholder scan:** New code complete. Tasks 2/3 note an ordering subtlety (the route group references controllers from Tasks 3–6) — resolve by wiring routes incrementally as controllers are created, or build Tasks 3–6 controllers before finalizing the group.

**Type/name consistency:** `Auth::{start,attempt,check,logout}`, `Csrf::{token,validate}`, repository method names (`upsert`, `findByEmail`, `all/find/create/update/delete`, `sync/labels`, `saveResume/saveSkills`, `setSetting/saveMenu`) are consistent between controllers and repositories; admin controllers live under `App\Controllers\Admin\`; the content type slugs (`posts`/`projects`/`pages`) and `contentType` singulars (`post`/`project`/`page`) match the public `term_relationships.content_type` values written by the Plan 1 importer.

**Security notes for the executor:**
- All mutating routes validate CSRF.
- Upload MIME is whitelisted; filenames are slugified + randomized; deletes are constrained to `/uploads/`.
- `AdminContentRepository` whitelists columns per type (no mass-assignment).
- Consider adding a login rate-limit (e.g., session attempt counter) — noted as a hardening follow-up.
