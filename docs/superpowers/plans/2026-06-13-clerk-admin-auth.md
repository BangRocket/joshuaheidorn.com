# Clerk Admin Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bespoke PHP password/session login for `/admin` with Clerk as the single identity provider.

**Architecture:** Clerk owns identity. The browser uses `svelte-clerk` client components mounted as an admin-only Svelte island (sign-in UI + account/sign-out control). The PHP backend gates every `/admin/*` request by verifying Clerk's `__session` cookie with `clerk/clerk-sdk-php` (networkless JWT verification). PHP sessions are kept **only** for CSRF tokens. A single-admin lockdown is enforced two ways: Clerk restricted sign-ups + a PHP-side `ADMIN_CLERK_USER_ID` allowlist in the middleware.

**Tech Stack:** PHP 8.2 / Slim 4 / PSR-7, `clerk/clerk-sdk-php`, Svelte 5 + Vite, `svelte-clerk`, Twig, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-13-clerk-admin-auth-design.md`

---

## File Structure

**Create:**
- `app/Support/ClerkAuth.php` — wraps Clerk's `AuthenticateRequest`; pure `decide()` authorization + `isAdmin()` request glue (fail-closed).
- `islands/admin.js` — Vite entry mounting admin-only Clerk islands via the `[data-island]` registry.
- `islands/AdminLogin.svelte` — `<ClerkProvider><SignIn/></ClerkProvider>` for the login page.
- `islands/AdminUserButton.svelte` — `<ClerkProvider><UserButton/></ClerkProvider>` for the admin chrome.
- `tests/Support/ClerkAuthTest.php` — unit tests for `ClerkAuth::decide()`.
- `tests/Http/AdminAuthTest.php` — HTTP tests for the gate (unauthenticated → redirect; login page reachable).

**Modify:**
- `composer.json` — add `clerk/clerk-sdk-php`.
- `package.json` — add `svelte-clerk` (+ `@clerk/clerk-js` if a required peer).
- `vite.config.js` — second rollup input (`islands/admin.js`).
- `app/config.php` — add `clerk` config block from env.
- `app/bootstrap.php` — build `ClerkAuth`, pass to routes; add `clerk_pk` Twig global.
- `app/routes.php` — accept `$clerkAuth`, wire it into `AuthMiddleware`, drop `AuthController` PDO + POST login/logout routes.
- `app/Middleware/AuthMiddleware.php` — session-start for CSRF + Clerk gate.
- `app/Controllers/Admin/AuthController.php` — `loginForm()` only.
- `app/Support/Auth.php` — keep `start()` (session bootstrap for CSRF); remove password methods.
- `app/views/admin/login.twig` — Clerk login island instead of email/password form.
- `app/views/admin/layout.twig` — `UserButton` island instead of POST logout form; load `admin.js`.
- `tests/Support/AuthTest.php` — drop password tests; keep session-mode test.
- `.env.example`, `CLAUDE.md` — Clerk env + docs.

**Delete:**
- `bin/user.php` — admin user now lives in Clerk.
- `app/Repositories/UserRepository.php` — no remaining consumers.

The `users` table is left **dormant** (its migration is untouched).

---

## Task 1: Backend dependency + config wiring

**Files:**
- Modify: `composer.json` (via `composer require`)
- Modify: `app/config.php`
- Modify: `app/bootstrap.php:14-19`
- Modify: `.env.example`

- [ ] **Step 1: Install the Clerk PHP SDK**

Run:
```bash
composer require clerkinc/backend-php
```
(The GitHub repo is `clerk/clerk-sdk-php`, but its Packagist package name is `clerkinc/backend-php` — Clerk's official SDK published under the legacy `clerkinc` vendor; same `Clerk\Backend\` namespace.)
Expected: composer adds `clerkinc/backend-php` to `require` and writes `composer.lock`. Verify the namespace exists:
```bash
ls vendor/clerk/clerk-sdk-php/src/Helpers/Jwks/AuthenticateRequest.php
```
Expected: the file path prints (confirms `Clerk\Backend\Helpers\Jwks\AuthenticateRequest`).

- [ ] **Step 2: Add a `clerk` block to config**

In `app/config.php`, add this key to the returned array (alongside `db`, `paths`):

```php
    'clerk' => [
        'publishable_key' => $_ENV['CLERK_PUBLISHABLE_KEY'] ?? '',
        'secret_key' => $_ENV['CLERK_SECRET_KEY'] ?? '',
        'admin_user_id' => $_ENV['ADMIN_CLERK_USER_ID'] ?? '',
        'app_url' => $_ENV['APP_URL'] ?? 'http://127.0.0.1:8088',
    ],
```

- [ ] **Step 3: Update `.env.example`**

Replace the `ADMIN_EMAIL` / `ADMIN_PASSWORD` lines in `.env.example` with:

```
APP_URL=http://127.0.0.1:8088
CLERK_PUBLISHABLE_KEY=pk_test_xxx
CLERK_SECRET_KEY=sk_test_xxx
ADMIN_CLERK_USER_ID=user_xxx
```

- [ ] **Step 4: Build `ClerkAuth` in bootstrap and expose the publishable key to Twig**

In `app/bootstrap.php`, after the `$twig->getEnvironment()->addGlobal('assets', $assets);` line, add:

```php
$twig->getEnvironment()->addGlobal('clerk_pk', $config['clerk']['publishable_key']);

$clerkAuth = new App\Support\ClerkAuth(
    $config['clerk']['secret_key'],
    [$config['clerk']['app_url']],
    $config['clerk']['admin_user_id'],
);
```

Then change the routes invocation from:

```php
(require __DIR__ . '/routes.php')($app, $pdo, $twig);
```
to:
```php
(require __DIR__ . '/routes.php')($app, $pdo, $twig, $clerkAuth);
```

- [ ] **Step 5: Verify nothing is broken yet**

Run:
```bash
php -l app/config.php && php -l app/bootstrap.php
```
Expected: `No syntax errors detected` for both. (`ClerkAuth` does not exist yet — that's Task 2; the app is not booted in this step.)

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock app/config.php app/bootstrap.php .env.example
git commit -m "Add Clerk PHP SDK and config wiring"
```

---

## Task 2: `ClerkAuth` support class (TDD)

**Files:**
- Create: `app/Support/ClerkAuth.php`
- Test: `tests/Support/ClerkAuthTest.php`

The authorization decision is a **pure static** (`decide()`) so it is unit-testable without a live Clerk token. The networkless JWT verification (`isAdmin()`) is integration glue, covered by manual E2E (see Task 9).

- [ ] **Step 1: Write the failing test**

Create `tests/Support/ClerkAuthTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ClerkAuth;
use PHPUnit\Framework\TestCase;

final class ClerkAuthTest extends TestCase
{
    public function test_authenticated_admin_is_allowed(): void
    {
        $this->assertTrue(ClerkAuth::decide(true, 'user_abc', 'user_abc'));
    }

    public function test_authenticated_non_admin_is_denied(): void
    {
        $this->assertFalse(ClerkAuth::decide(true, 'user_other', 'user_abc'));
    }

    public function test_signed_out_is_denied(): void
    {
        $this->assertFalse(ClerkAuth::decide(false, null, 'user_abc'));
    }

    public function test_empty_admin_id_denies_everyone(): void
    {
        // Misconfiguration must fail closed, not open.
        $this->assertFalse(ClerkAuth::decide(true, '', ''));
        $this->assertFalse(ClerkAuth::decide(true, 'user_abc', ''));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
vendor/bin/phpunit --filter ClerkAuthTest
```
Expected: FAIL — `Error: Class "App\Support\ClerkAuth" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/ClerkAuth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Clerk\Backend\Helpers\Jwks\AuthenticateRequest;
use Clerk\Backend\Helpers\Jwks\AuthenticateRequestOptions;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Verifies Clerk session cookies and authorizes the single admin user.
 *
 * Identity is owned by Clerk. PHP sessions are no longer used for auth
 * (only for CSRF tokens — see App\Support\Auth::start()).
 */
final class ClerkAuth
{
    /** @param string[] $authorizedParties */
    public function __construct(
        private string $secretKey,
        private array $authorizedParties,
        private string $adminUserId,
    ) {
    }

    /**
     * Pure authorization decision: is this verified session our single admin?
     * Fails closed when the allowlist is unconfigured (empty admin id).
     */
    public static function decide(bool $authenticated, ?string $subject, string $adminUserId): bool
    {
        return $authenticated
            && $adminUserId !== ''
            && $subject !== null
            && hash_equals($adminUserId, $subject);
    }

    /**
     * Verify the request's Clerk `__session` cookie (networkless) and authorize it.
     * Any verification error denies access (fail closed).
     */
    public function isAdmin(Request $request): bool
    {
        try {
            $options = new AuthenticateRequestOptions(
                secretKey: $this->secretKey,
                authorizedParties: $this->authorizedParties,
            );
            $state = AuthenticateRequest::authenticateRequest($request, $options);
            $subject = $state->getPayload()?->sub ?? null;

            return self::decide($state->isAuthenticated(), $subject, $this->adminUserId);
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
vendor/bin/phpunit --filter ClerkAuthTest
```
Expected: PASS (4 tests, OK).

- [ ] **Step 5: Commit**

```bash
git add app/Support/ClerkAuth.php tests/Support/ClerkAuthTest.php
git commit -m "Add ClerkAuth: verify Clerk session + single-admin allowlist"
```

---

## Task 3: Gate `/admin` with Clerk in the middleware (TDD)

**Files:**
- Modify: `app/Middleware/AuthMiddleware.php`
- Modify: `app/routes.php:70,94` (controller construction + middleware wiring + routes)
- Test: `tests/Http/AdminAuthTest.php`

- [ ] **Step 1: Write the failing HTTP test**

Create `tests/Http/AdminAuthTest.php` (mirrors the boot pattern in `tests/Http/RouteSmokeTest.php`):

```php
<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Support\Database;
use App\Support\Importer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdminAuthTest extends TestCase
{
    private function app()
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/app/config.php';

        $pdo = Database::connect($config['db_test']);
        (new Importer($pdo, [
            'base' => $root,
            'uploads_src' => $root . '/uploads',
            'uploads_dest' => sys_get_temp_dir() . '/jh_uploads_test',
        ]))->run();

        $_ENV['DB_NAME'] = $config['db_test']['name'];
        putenv('DB_NAME=' . $config['db_test']['name']);
        // Dummy Clerk creds so the app boots; no __session cookie is sent,
        // so verification returns signed-out without any network call.
        $_ENV['CLERK_SECRET_KEY'] = 'sk_test_dummy';
        $_ENV['ADMIN_CLERK_USER_ID'] = 'user_dummy';

        return require $root . '/app/bootstrap.php';
    }

    public function test_admin_dashboard_redirects_when_signed_out(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');
        $response = $app->handle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function test_login_page_is_reachable_when_signed_out(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/login');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
vendor/bin/phpunit --filter AdminAuthTest
```
Expected: FAIL — `AuthMiddleware` still calls the removed `Auth::check()` (fatal) / routes pass `$clerkAuth` the old middleware doesn't accept. (Boot error or wrong status.)

- [ ] **Step 3: Rewrite the middleware**

Replace the entire contents of `app/Middleware/AuthMiddleware.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\ClerkAuth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private ClerkAuth $clerk)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        Auth::start(); // PHP session is kept only for CSRF tokens.
        $path = $request->getUri()->getPath();

        // The login page hosts the Clerk sign-in island; it must be reachable
        // while signed out.
        if ($path === '/admin/login') {
            return $handler->handle($request);
        }

        if (!$this->clerk->isAdmin($request)) {
            return (new SlimResponse())
                ->withHeader('Location', '/admin/login')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Wire `$clerkAuth` through routes and trim auth routes**

In `app/routes.php`:

Change the closure signature (top of file) from `function ($app, $pdo, $twig) {` to:
```php
function ($app, $pdo, $twig, $clerkAuth) {
```

Change the `AuthController` construction (line ~70) from:
```php
    $authCtrl = new \App\Controllers\Admin\AuthController($twig, $pdo);
```
to:
```php
    $authCtrl = new \App\Controllers\Admin\AuthController($twig);
```

Inside the `/admin` group, remove the POST login and POST logout routes — delete these two lines:
```php
        $group->post('/login', [$authCtrl, 'login']);
        $group->post('/logout', [$authCtrl, 'logout']);
```
(Keep `$group->get('/login', [$authCtrl, 'loginForm']);`.)

Change the middleware attachment from:
```php
    })->add(new \App\Middleware\AuthMiddleware());
```
to:
```php
    })->add(new \App\Middleware\AuthMiddleware($clerkAuth));
```

> Note: `AuthController` is updated in Task 4; after this step `loginForm()` still works because it does not use PDO. The test in this task only exercises the gate and the (still-present) login form route.

- [ ] **Step 5: Run the test to verify it passes**

Run:
```bash
vendor/bin/phpunit --filter AdminAuthTest
```
Expected: PASS (2 tests). Signed-out `/admin` → 302 to `/admin/login`; `/admin/login` → 200.

- [ ] **Step 6: Commit**

```bash
git add app/Middleware/AuthMiddleware.php app/routes.php tests/Http/AdminAuthTest.php
git commit -m "Gate /admin via ClerkAuth in AuthMiddleware"
```

---

## Task 4: Slim down `AuthController` and `Auth`

**Files:**
- Modify: `app/Controllers/Admin/AuthController.php`
- Modify: `app/Support/Auth.php`

- [ ] **Step 1: Reduce `AuthController` to the login form only**

Replace the entire contents of `app/Controllers/Admin/AuthController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(private Twig $twig)
    {
    }

    /**
     * Renders the page that hosts the Clerk sign-in island. Sign-in, sign-out,
     * and "already signed in" redirects are handled client-side by Clerk JS.
     */
    public function loginForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/login.twig', []);
    }
}
```

- [ ] **Step 2: Reduce `Auth` to the session bootstrap**

Replace the entire contents of `app/Support/Auth.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PHP session bootstrap. Identity is now owned by Clerk (see ClerkAuth);
 * the PHP session survives only to back CSRF tokens (see Csrf).
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Refuse attacker-supplied session IDs (fixation defense).
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
            ]);
            session_start();
        }
    }
}
```

- [ ] **Step 3: Verify syntax**

Run:
```bash
php -l app/Controllers/Admin/AuthController.php && php -l app/Support/Auth.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Run the admin gate tests again (no regression)**

Run:
```bash
vendor/bin/phpunit --filter AdminAuthTest
```
Expected: PASS (2 tests) — `loginForm()` still renders without PDO.

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/Admin/AuthController.php app/Support/Auth.php
git commit -m "Trim AuthController to login form; Auth to session bootstrap"
```

---

## Task 5: JS dependencies, Vite second entry, and Clerk islands

**Files:**
- Modify: `package.json` (via `yarn add`)
- Modify: `vite.config.js`
- Create: `islands/admin.js`
- Create: `islands/AdminLogin.svelte`
- Create: `islands/AdminUserButton.svelte`

- [ ] **Step 1: Install svelte-clerk**

Run:
```bash
yarn add svelte-clerk
```
Expected: `svelte-clerk` added to `dependencies`. If yarn reports an **unmet peer dependency** on `@clerk/clerk-js`, also run:
```bash
yarn add @clerk/clerk-js
```
(Skip the second command if no peer warning appears.)

- [ ] **Step 2: Add the second Vite entry**

In `vite.config.js`, change the `rollupOptions` block from:
```js
        rollupOptions: {
            input: "islands/main.js",
        },
```
to:
```js
        rollupOptions: {
            input: {
                main: "islands/main.js",
                admin: "islands/admin.js",
            },
        },
```
(Manifest keys remain the source paths `islands/main.js` / `islands/admin.js`, which is what `assets.js('islands/admin.js')` looks up.)

- [ ] **Step 3: Create the admin island entry**

Create `islands/admin.js`:

```js
import { mount } from "svelte";
import AdminLogin from "./AdminLogin.svelte";
import AdminUserButton from "./AdminUserButton.svelte";

const REGISTRY = { AdminLogin, AdminUserButton };

for (const el of document.querySelectorAll("[data-island]")) {
    const Component = REGISTRY[el.dataset.island];
    if (!Component) continue;
    const props = el.dataset.props ? JSON.parse(el.dataset.props) : {};
    mount(Component, { target: el, props });
}
```

- [ ] **Step 4: Create the login island**

Create `islands/AdminLogin.svelte`:

```svelte
<script>
    import { ClerkProvider, SignIn } from "svelte-clerk/client";

    let { publishableKey } = $props();

    // A missing key would render a blank, non-functional sign-in — fail loudly.
    if (!publishableKey) {
        console.error("[AdminLogin] missing publishableKey (CLERK_PUBLISHABLE_KEY)");
    }
</script>

<ClerkProvider {publishableKey}>
    <!-- hash routing keeps Clerk's multi-step flow inside this one page
         (no SvelteKit catch-all route exists to handle path routing). -->
    <SignIn routing="hash" forceRedirectUrl="/admin" />
</ClerkProvider>
```

> Verified during implementation: `SignIn`/`UserButton`/`ClerkProvider` must all be imported from **`svelte-clerk/client`** (the pure-Svelte-5 exports). The package **root** (`svelte-clerk`) re-exports SvelteKit-only versions (they import `$app/state`/`$app/navigation`) which break a plain Vite build.

- [ ] **Step 5: Create the user-button island**

Create `islands/AdminUserButton.svelte`:

```svelte
<script>
    import { ClerkProvider, UserButton } from "svelte-clerk/client";

    let { publishableKey } = $props();

    // A missing key would render a blank, non-functional control — fail loudly.
    if (!publishableKey) {
        console.error("[AdminUserButton] missing publishableKey (CLERK_PUBLISHABLE_KEY)");
    }
</script>

<ClerkProvider {publishableKey}>
    <UserButton afterSignOutUrl="/admin/login" />
</ClerkProvider>
```

> Implementation-time check: confirm the installed `svelte-clerk` exposes `SignIn`/`UserButton` from the package root and `ClerkProvider` from `svelte-clerk/client`, and that `SignIn` accepts `routing`/`forceRedirectUrl` and `UserButton` accepts `afterSignOutUrl` (these are standard Clerk component props). Adjust import paths/prop names to the installed version if they differ.

- [ ] **Step 6: Build the bundles**

Run:
```bash
yarn build
```
Expected: build succeeds; `public/assets/.vite/manifest.json` contains both `islands/main.js` and `islands/admin.js` entries. Verify:
```bash
grep -o '"islands/admin.js"' public/assets/.vite/manifest.json
```
Expected: prints `"islands/admin.js"`.

- [ ] **Step 7: Commit**

```bash
git add package.json yarn.lock vite.config.js islands/admin.js islands/AdminLogin.svelte islands/AdminUserButton.svelte
git commit -m "Add svelte-clerk admin islands and second Vite entry"
```

---

## Task 6: Admin templates

**Files:**
- Modify: `app/views/admin/login.twig`
- Modify: `app/views/admin/layout.twig`

- [ ] **Step 1: Replace the login form with the Clerk island**

Replace the entire contents of `app/views/admin/login.twig` with:

```twig
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Log in — Admin</title>
    <link rel="stylesheet" href="/css/admin.css" />
    {% for href in assets.css('islands/admin.js') %}<link rel="stylesheet" href="{{ href }}" />{% endfor %}
</head>
<body class="admin admin-login-page">
    <main class="admin-login">
        <h1>Admin</h1>
        <div data-island="AdminLogin" data-props='{{ {"publishableKey": clerk_pk}|json_encode }}'></div>
    </main>
    {% if assets.js('islands/admin.js') %}<script type="module" src="{{ assets.js('islands/admin.js') }}"></script>{% endif %}
</body>
</html>
```

- [ ] **Step 2: Replace the logout form with the UserButton island and load admin.js**

In `app/views/admin/layout.twig`:

Add the admin island stylesheet in `<head>`, after the existing admin.css `<link>`:
```twig
    {% for href in assets.css('islands/admin.js') %}<link rel="stylesheet" href="{{ href }}" />{% endfor %}
```

Replace the logout form block:
```twig
        <form method="post" action="/admin/logout" class="admin-logout">
            <button type="submit">Log out</button>
        </form>
```
with the Clerk user-button island:
```twig
        <div class="admin-user" data-island="AdminUserButton" data-props='{{ {"publishableKey": clerk_pk}|json_encode }}'></div>
```

Add the admin bundle script immediately before `</body>`:
```twig
    {% if assets.js('islands/admin.js') %}<script type="module" src="{{ assets.js('islands/admin.js') }}"></script>{% endif %}
```

- [ ] **Step 3: Verify templates render (no Twig syntax errors)**

Run:
```bash
vendor/bin/phpunit --filter AdminAuthTest
```
Expected: PASS (2 tests) — `/admin/login` still renders 200 with the new template (the `clerk_pk` global resolves to the dummy/empty value in tests).

- [ ] **Step 4: Commit**

```bash
git add app/views/admin/login.twig app/views/admin/layout.twig
git commit -m "Render Clerk sign-in + UserButton islands in admin templates"
```

---

## Task 7: Retire the PHP password auth

**Files:**
- Delete: `bin/user.php`
- Delete: `app/Repositories/UserRepository.php`
- Modify: `tests/Support/AuthTest.php`

- [ ] **Step 1: Confirm `UserRepository` has no remaining consumers**

Run:
```bash
grep -rn "UserRepository" app bin tests
```
Expected: matches **only** in `bin/user.php` and `tests/Support/AuthTest.php` (both edited/removed in this task). If any other file references it, stop and reassess.

- [ ] **Step 2: Delete the dead files**

Run:
```bash
git rm bin/user.php app/Repositories/UserRepository.php
```

- [ ] **Step 3: Rewrite `AuthTest` to cover only the surviving behavior**

Replace the entire contents of `tests/Support/AuthTest.php` with:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function test_start_enables_strict_session_mode(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        ini_set('session.use_strict_mode', '0');

        Auth::start();

        $this->assertSame('1', ini_get('session.use_strict_mode'));
        session_destroy();
    }
}
```

- [ ] **Step 4: Run the full test suite**

Run:
```bash
vendor/bin/phpunit
```
Expected: PASS — all tests green, including `ClerkAuthTest`, `AdminAuthTest`, the reduced `AuthTest`, and the pre-existing `RouteSmokeTest`. No reference to `Auth::attempt`/`UserRepository` remains.

- [ ] **Step 5: Commit**

```bash
git add tests/Support/AuthTest.php
git commit -m "Remove PHP password auth (bin/user.php, UserRepository); keep users table dormant"
```

---

## Task 8: Documentation

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update CLAUDE.md**

Make these edits in `CLAUDE.md`:

- In **Stack**, add Clerk to the auth description, e.g. after the Composer/Phinx line:
  `- **Clerk** (`clerk/clerk-sdk-php` + `svelte-clerk`) — admin identity provider; PHP verifies the Clerk \`__session\` cookie networklessly, sign-in/out UI is a Svelte island.`
- In **Architecture → support utilities**, replace `Auth` with `Auth` (session bootstrap for CSRF) and add `ClerkAuth` (Clerk session verification + single-admin allowlist).
- In **Commands**, remove the `php bin/user.php` line and add:
  `- Admin user is created in the Clerk dashboard (restricted sign-ups + email allowlist); set \`ADMIN_CLERK_USER_ID\` in \`.env\`.`
- In **Content model → Editing** and **Deploy**, replace any `ADMIN_EMAIL`/`ADMIN_PASSWORD` mentions with the Clerk env vars (`CLERK_PUBLISHABLE_KEY`, `CLERK_SECRET_KEY`, `ADMIN_CLERK_USER_ID`, `APP_URL`).
- In **Out of scope for v1**, remove "A login rate-limit is a noted hardening follow-up." (Clerk handles brute-force/rate-limiting) and note that dropping the dormant `users` table is deferred.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "Document Clerk admin auth in CLAUDE.md"
```

---

## Task 9: Manual end-to-end verification (Clerk dev instance)

Not automatable in PHPUnit (requires a live Clerk token). Perform manually before merging.

- [ ] **Step 1: Configure a Clerk development instance**

In the Clerk dashboard: create/choose a **development** instance. Under **Restrictions**, set sign-up mode to **Restricted** and allowlist your email. Create your admin user (sign yourself up). Copy its **user id** (`user_…`) into `.env` as `ADMIN_CLERK_USER_ID`, and set `CLERK_PUBLISHABLE_KEY` / `CLERK_SECRET_KEY` / `APP_URL=http://127.0.0.1:8088`.

- [ ] **Step 2: Build and run locally**

Run:
```bash
yarn build
docker compose up -d
php -S 127.0.0.1:8088 -t public public/router.php
```

- [ ] **Step 3: Verify the gate and the flows**

Check, in a browser:
1. Visiting `http://127.0.0.1:8088/admin` while signed out → redirects to `/admin/login`.
2. The login page shows the Clerk `<SignIn>` widget; signing in as the allowlisted admin → lands on `/admin`.
3. Admin pages show the `UserButton`; signing out → returns to `/admin/login`.
4. (Authorization backstop) Temporarily set `ADMIN_CLERK_USER_ID` to a different value, reload an admin page → you are redirected to `/admin/login` even though Clerk reports you signed in. Restore the correct id afterward.
5. View a public page (e.g. `/`) and confirm Clerk's JS is **not** loaded there (only `islands/main.js`).

- [ ] **Step 4: Confirm the full suite still passes**

Run:
```bash
vendor/bin/phpunit
```
Expected: all green.

---

## Self-Review Notes

- **Spec coverage:** model & flow → Tasks 2–6; single-admin lockdown (both layers) → `ClerkAuth::decide` + Task 9 Step 1 (Clerk restricted sign-ups); `__session` cookie transport → `ClerkAuth::isAdmin` (SDK reads the cookie); config/env → Task 1; build/second-entry → Task 5; retire PHP auth (leave `users` dormant) → Tasks 4 & 7; docs → Task 8; testing → Tasks 2, 3, 7, 9. The spec's "honor handshake redirect" item is intentionally dropped — the installed SDK's `RequestState` exposes no handshake state (verified against source); a lapsed short-lived cookie simply bounces through `/admin/login`, where Clerk JS re-establishes the session. Note this deviation when updating the spec.
- **Placeholders:** none — every code/edit step shows full content; the two implementation-time checks (svelte-clerk prop/import names; nothing else references `UserRepository`) have explicit verification steps with fallbacks.
- **Type/name consistency:** `ClerkAuth::decide(bool,?string,string)` and `isAdmin(Request)` used identically in middleware; routes closure arity (`$clerkAuth`) matches `bootstrap.php`'s call; manifest key `islands/admin.js` matches `assets.js('islands/admin.js')` in both templates; `clerk_pk` Twig global set in bootstrap and consumed in both templates.
