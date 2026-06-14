# Job Tracker Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fold the standalone Job Tracker into the main site as a Clerk-gated `/jobs` section, sharing MySQL, the Clerk login, and the Vite build.

**Architecture:** Port the tracker's PDO backend into the Slim app (new MySQL tables via Phinx; `JobRepository` + `JobSettingsRepository` + a thin JSON `JobController` under a Clerk-gated `/jobs` route group). Reuse the tracker's Svelte 5 SPA as a third Vite island (`islands/jobs.js`), reskinned to the site's NeoBrutalism `theme.css` tokens, with dark mode riding the site's existing `.dark` cookie switcher. Starts empty (no data migration). The standalone PHP front controller / SQLite / Basic Auth are retired.

**Tech Stack:** PHP 8.2 / Slim 4 / PDO / MySQL / Phinx / Twig; Svelte 5 + Vite; PHPUnit; Vitest + jsdom.

**Spec:** `docs/superpowers/specs/2026-06-13-jobtracker-integration-design.md`
**Tracker source (port from):** `/Volumes/Storage/Code/jobtracker`

---

## File Structure

**Create (backend):**
- `db/migrations/<ts>_create_job_tracker_tables.php` — `jobs` + `job_settings` tables.
- `app/Support/ValidationException.php` — thrown by repos on bad input → JSON 400.
- `app/Repositories/JobRepository.php` — ported job CRUD (PDO/MySQL); owns `added`/`updated`.
- `app/Repositories/JobSettingsRepository.php` — single-row `stale_days` (ensure-row on read).
- `app/Controllers/JobController.php` — JSON API + renders the `/jobs` shell.
- `app/views/jobs.twig` — thin authed shell hosting the SPA island.
- `tests/Repositories/JobRepositoryTest.php`, `tests/Repositories/JobSettingsRepositoryTest.php`, `tests/Http/JobsAuthTest.php`.

**Create (frontend):**
- `islands/jobtracker/` — the SPA, copied from `jobtracker/frontend/src/` (App.svelte, components/, lib/, styles/global.css).
- `islands/jobs.js` — Vite entry mounting the SPA via `mountIslands`.
- `islands/jobs.test.js` — Vitest island-mount test.

**Modify:**
- `app/routes.php` — add the Clerk-gated `/jobs` group + build the controller.
- `vite.config.js` — third rollup input `islands/jobs.js`.
- `app/bootstrap.php` — add `/jobs` to the public-cache-middleware exclusion.
- `islands/jobtracker/lib/api.js`, `lib/state.svelte.js`, `components/Header.svelte`, `components/SettingsPopover.svelte`, `styles/global.css` — trim accent/theme, repoint API, reskin.
- `CLAUDE.md` — document the `/jobs` section.

---

## Phase 1 — Backend

### Task 1: Migration — `jobs` + `job_settings`

**Files:**
- Create: `db/migrations/<ts>_create_job_tracker_tables.php` (generated)

- [ ] **Step 1: Generate the migration**

Run: `vendor/bin/phinx create CreateJobTrackerTables`
This writes `db/migrations/<timestamp>_create_job_tracker_tables.php` with an empty `change()`.

- [ ] **Step 2: Fill in the migration**

Replace the generated class body's `change()` with:

```php
    public function change(): void
    {
        $this->table('jobs')
            ->addColumn('company', 'string', ['limit' => 255])
            ->addColumn('role', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 32])
            ->addColumn('link', 'string', ['limit' => 1024, 'default' => ''])
            ->addColumn('salary', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('location', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('added', 'date')
            ->addColumn('updated', 'date')
            ->addIndex(['status'])
            ->addIndex(['updated'])
            ->create();

        $this->table('job_settings')
            ->addColumn('stale_days', 'integer', ['default' => 14])
            ->create();
    }
```

(`description`/`notes` are `text` nullable; the repo coalesces null→''. The single
settings row is created lazily by the repository, not seeded here, so `change()`
stays purely structural and reversible.)

- [ ] **Step 3: Apply to dev + test DBs**

Run: `vendor/bin/phinx migrate -e development && vendor/bin/phinx migrate -e testing`
Expected: both report the migration applied (`== CreateJobTrackerTables: migrated`).

- [ ] **Step 4: Verify tables exist**

Run: `docker compose exec -T db mysql -uroot -e "SHOW TABLES" joshuaheidorn | grep -E 'jobs|job_settings'`
Expected: both `jobs` and `job_settings` printed.

- [ ] **Step 5: Commit**

```bash
git add db/migrations
git commit -m "Add jobs + job_settings tables (Job Tracker)"
```

---

### Task 2: `ValidationException` + `JobRepository` (TDD)

**Files:**
- Create: `app/Support/ValidationException.php`
- Create: `app/Repositories/JobRepository.php`
- Test: `tests/Repositories/JobRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Repositories/JobRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\JobRepository;
use App\Support\Database;
use App\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class JobRepositoryTest extends TestCase
{
    private function repo(): JobRepository
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('DELETE FROM jobs');
        return new JobRepository($pdo);
    }

    public function test_create_sets_dates_and_returns_record(): void
    {
        $repo = $this->repo();
        $job = $repo->create(['company' => 'Acme', 'status' => 'applied', 'role' => 'Dev']);

        $this->assertSame('Acme', $job['company']);
        $this->assertSame('applied', $job['status']);
        $this->assertIsInt($job['id']);
        $this->assertSame($job['added'], $job['updated']); // same day on create
    }

    public function test_create_requires_company(): void
    {
        $this->expectException(ValidationException::class);
        $this->repo()->create(['company' => '   ', 'status' => 'applied']);
    }

    public function test_create_rejects_bad_status(): void
    {
        $this->expectException(ValidationException::class);
        $this->repo()->create(['company' => 'Acme', 'status' => 'nonsense']);
    }

    public function test_status_change_resets_updated_clock(): void
    {
        $repo = $this->repo();
        $job = $repo->create(['company' => 'Acme', 'status' => 'applied']);
        // Backdate `updated` so a status change is observable.
        $pdo = (function () { return $this->pdo; })->call($repo);
        $pdo->exec("UPDATE jobs SET updated = '2020-01-01' WHERE id = " . $job['id']);

        $sameStatus = $repo->update($job['id'], ['company' => 'Acme', 'status' => 'applied']);
        $this->assertSame('2020-01-01', $sameStatus['updated']); // unchanged

        $changed = $repo->update($job['id'], ['company' => 'Acme', 'status' => 'interviewed']);
        $this->assertNotSame('2020-01-01', $changed['updated']); // reset to today
    }

    public function test_update_unknown_id_returns_null(): void
    {
        $this->assertNull($this->repo()->update(999999, ['company' => 'X', 'status' => 'applied']));
    }

    public function test_delete_removes_row(): void
    {
        $repo = $this->repo();
        $job = $repo->create(['company' => 'Acme', 'status' => 'applied']);
        $this->assertTrue($repo->delete($job['id']));
        $this->assertNull($repo->find($job['id']));
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `vendor/bin/phpunit --filter JobRepositoryTest`
Expected: FAIL — `Class "App\Support\ValidationException" not found` / `App\Repositories\JobRepository` not found.

- [ ] **Step 3: Create `ValidationException`**

Create `app/Support/ValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/** Thrown when request data fails validation; surfaced to the client as HTTP 400. */
final class ValidationException extends \RuntimeException
{
}
```

- [ ] **Step 4: Create `JobRepository`**

Create `app/Repositories/JobRepository.php` (ported from
`jobtracker/backend/src/JobRepository.php`; namespace + `ValidationException`
import adjusted; SQL is MySQL-compatible as-is):

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ValidationException;
use PDO;

/**
 * CRUD for job applications. The server owns the `added`/`updated` dates:
 * `added` is set once on create; `updated` is refreshed only when the status
 * changes ("status change resets the clock").
 */
final class JobRepository
{
    public const STATUSES = [
        'saved', 'applied', 'submitted', 'interviewed', 'offer', 'rejected', 'ghosted',
    ];

    private const TEXT_FIELDS = ['company', 'role', 'link', 'salary', 'location', 'description', 'notes'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM jobs ORDER BY updated DESC, id DESC')->fetchAll();
        return array_map([$this, 'cast'], $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $fields = $this->sanitize($data);
        if ($fields['company'] === '') {
            throw new ValidationException('Company is required.');
        }
        $status = $this->validStatus($data['status'] ?? 'applied');
        $today = self::today();

        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (company, role, status, link, salary, location, description, notes, added, updated)
             VALUES (:company, :role, :status, :link, :salary, :location, :description, :notes, :added, :updated)'
        );
        $stmt->execute([
            ':company' => $fields['company'],
            ':role' => $fields['role'],
            ':status' => $status,
            ':link' => $fields['link'],
            ':salary' => $fields['salary'],
            ':location' => $fields['location'],
            ':description' => $fields['description'],
            ':notes' => $fields['notes'],
            ':added' => $today,
            ':updated' => $today,
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null  null when the id does not exist
     * @throws ValidationException
     */
    public function update(int $id, array $data): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $fields = $this->sanitize($data);
        if ($fields['company'] === '') {
            throw new ValidationException('Company is required.');
        }
        $status = $this->validStatus($data['status'] ?? $existing['status']);
        $updated = $status !== $existing['status'] ? self::today() : $existing['updated'];

        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET company = :company, role = :role, status = :status, link = :link,
                salary = :salary, location = :location, description = :description, notes = :notes,
                updated = :updated
             WHERE id = :id'
        );
        $stmt->execute([
            ':company' => $fields['company'],
            ':role' => $fields['role'],
            ':status' => $status,
            ':link' => $fields['link'],
            ':salary' => $fields['salary'],
            ':location' => $fields['location'],
            ':description' => $fields['description'],
            ':notes' => $fields['notes'],
            ':updated' => $updated,
            ':id' => $id,
        ]);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function sanitize(array $data): array
    {
        $out = [];
        foreach (self::TEXT_FIELDS as $f) {
            $out[$f] = trim((string) ($data[$f] ?? ''));
        }
        return $out;
    }

    /** @throws ValidationException */
    private function validStatus(mixed $status): string
    {
        $status = is_string($status) ? $status : '';
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Invalid status: ' . $status);
        }
        return $status;
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** Normalize column types for JSON output (MySQL returns strings). */
    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        foreach (['description', 'notes'] as $f) {
            $row[$f] = (string) ($row[$f] ?? '');
        }
        return $row;
    }
}
```

- [ ] **Step 5: Run the test — expect pass**

Run: `vendor/bin/phpunit --filter JobRepositoryTest` (docker DB up; `docker compose up -d`)
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Support/ValidationException.php app/Repositories/JobRepository.php tests/Repositories/JobRepositoryTest.php
git commit -m "Add JobRepository (ported to Slim/MySQL) + ValidationException"
```

---

### Task 3: `JobSettingsRepository` (TDD)

**Files:**
- Create: `app/Repositories/JobSettingsRepository.php`
- Test: `tests/Repositories/JobSettingsRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Repositories/JobSettingsRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\JobSettingsRepository;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class JobSettingsRepositoryTest extends TestCase
{
    private function repo(): JobSettingsRepository
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('DELETE FROM job_settings');
        return new JobSettingsRepository($pdo);
    }

    public function test_get_creates_default_row(): void
    {
        $this->assertSame(['stale_days' => 14], $this->repo()->get());
    }

    public function test_update_clamps_stale_days(): void
    {
        $repo = $this->repo();
        $this->assertSame(['stale_days' => 90], $repo->update(['stale_days' => 9999]));
        $this->assertSame(['stale_days' => 3], $repo->update(['stale_days' => 1]));
        $this->assertSame(['stale_days' => 30], $repo->update(['stale_days' => 30]));
    }

    public function test_update_ignores_unknown_keys(): void
    {
        $repo = $this->repo();
        $this->assertSame(['stale_days' => 14], $repo->update(['accent' => 'x', 'theme' => 'dark']));
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `vendor/bin/phpunit --filter JobSettingsRepositoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the repository**

Create `app/Repositories/JobSettingsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Single-row Job Tracker settings. Only `stale_days` survives the integration
 * (accent/theme were dropped — the site's theme switcher owns dark mode). The
 * row is created lazily so no migration seed is needed.
 */
final class JobSettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{stale_days:int} */
    public function get(): array
    {
        $this->pdo->exec('INSERT IGNORE INTO job_settings (id, stale_days) VALUES (1, 14)');
        $row = $this->pdo->query('SELECT stale_days FROM job_settings WHERE id = 1')->fetch();
        return ['stale_days' => (int) $row['stale_days']];
    }

    /**
     * @param array<string,mixed> $data partial update; only `stale_days` is honored.
     * @return array{stale_days:int}
     */
    public function update(array $data): array
    {
        $current = $this->get();
        $stale = $current['stale_days'];
        if (array_key_exists('stale_days', $data)) {
            $stale = max(3, min(90, (int) $data['stale_days'])); // clamp 3–90
        }
        $stmt = $this->pdo->prepare('UPDATE job_settings SET stale_days = :s WHERE id = 1');
        $stmt->execute([':s' => $stale]);
        return $this->get();
    }
}
```

(`INSERT IGNORE ... (id, stale_days) VALUES (1, 14)` requires `id = 1`; the
migration's auto-increment PK accepts an explicit id, and `IGNORE` makes it a
no-op once the row exists.)

- [ ] **Step 4: Run the test — expect pass**

Run: `vendor/bin/phpunit --filter JobSettingsRepositoryTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/JobSettingsRepository.php tests/Repositories/JobSettingsRepositoryTest.php
git commit -m "Add JobSettingsRepository (stale_days only)"
```

---

### Task 4: `JobController` + routes + shell + HTTP gate test (TDD)

**Files:**
- Create: `app/Controllers/JobController.php`
- Create: `app/views/jobs.twig`
- Modify: `app/routes.php`
- Modify: `app/bootstrap.php` (cache exclusion)
- Test: `tests/Http/JobsAuthTest.php`

- [ ] **Step 1: Write the failing HTTP test**

Create `tests/Http/JobsAuthTest.php` (mirrors `tests/Http/AdminAuthTest.php`):

```php
<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Support\Database;
use App\Support\Importer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class JobsAuthTest extends TestCase
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
        $_ENV['CLERK_SECRET_KEY'] = 'sk_test_dummy';
        $_ENV['ADMIN_CLERK_USER_ID'] = 'user_dummy';
        return require $root . '/app/bootstrap.php';
    }

    public function test_jobs_page_redirects_when_signed_out(): void
    {
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/jobs');
        $res = $app->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login', $res->getHeaderLine('Location'));
    }

    public function test_jobs_api_is_gated_when_signed_out(): void
    {
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/jobs/api/jobs');
        $res = $app->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login', $res->getHeaderLine('Location'));
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `vendor/bin/phpunit --filter JobsAuthTest`
Expected: FAIL — no `/jobs` route (404, not 302).

- [ ] **Step 3: Create the controller**

Create `app/Controllers/JobController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\JobRepository;
use App\Repositories\JobSettingsRepository;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class JobController
{
    public function __construct(
        private Twig $twig,
        private JobRepository $jobs,
        private JobSettingsRepository $settings,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'jobs.twig', []);
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, $this->jobs->all());
    }

    public function store(Request $request, Response $response): Response
    {
        try {
            $job = $this->jobs->create((array) $request->getParsedBody());
            return $this->json($response, $job, 201);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $job = $this->jobs->update((int) $args['id'], (array) $request->getParsedBody());
            return $job === null
                ? $this->json($response, ['error' => 'Not found'], 404)
                : $this->json($response, $job);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $ok = $this->jobs->delete((int) $args['id']);
        return $ok
            ? $this->json($response, ['ok' => true])
            : $this->json($response, ['error' => 'Not found'], 404);
    }

    public function getSettings(Request $request, Response $response): Response
    {
        return $this->json($response, $this->settings->get());
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        return $this->json($response, $this->settings->update((array) $request->getParsedBody()));
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

- [ ] **Step 4: Register the `/jobs` group in routes**

In `app/routes.php`, near the other repo/controller construction (before the
`$app->group('/admin', …)` block), add:

```php
    $jobCtrl = new \App\Controllers\JobController(
        $twig,
        new \App\Repositories\JobRepository($pdo),
        new \App\Repositories\JobSettingsRepository($pdo),
    );
```

Then add this group AFTER the `/admin` group, reusing the same Clerk middleware:

```php
    $app->group('/jobs', function ($group) use ($jobCtrl) {
        $group->get('', [$jobCtrl, 'page']);
        $group->get('/api/jobs', [$jobCtrl, 'list']);
        $group->post('/api/jobs', [$jobCtrl, 'store']);
        $group->put('/api/jobs/{id}', [$jobCtrl, 'update']);
        $group->delete('/api/jobs/{id}', [$jobCtrl, 'destroy']);
        $group->get('/api/settings', [$jobCtrl, 'getSettings']);
        $group->put('/api/settings', [$jobCtrl, 'saveSettings']);
    })->add(new \App\Middleware\AuthMiddleware($clerkAuth));
```

(The `AuthMiddleware` redirects any signed-out request in the group to
`/admin/login`. `JsonBodyParserMiddleware`/Slim's body parsing: Slim parses JSON
bodies when `Content-Type: application/json` is set and the body-parsing
middleware is active — confirm the app adds `addBodyParsingMiddleware()`; if not,
add `$app->addBodyParsingMiddleware();` in `bootstrap.php` so `getParsedBody()`
returns the decoded JSON for the PUT/POST handlers.)

- [ ] **Step 5: Create the `/jobs` shell**

Create `app/views/jobs.twig`:

```twig
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Job Tracker</title>
    <script>
        // Same FOUC-prevention + theme cookie logic as the public layout.
        (function () {
            var c = document.cookie, i = c.indexOf("theme=");
            var theme = i >= 0 ? c.slice(i + 6).split(";")[0] : null;
            if (theme === "dark" || theme === "light") document.documentElement.classList.add(theme);
            else if (window.matchMedia("(prefers-color-scheme: dark)").matches) document.documentElement.classList.add("dark");
        })();
    </script>
    {% for href in assets.css('islands/jobs.js') %}<link rel="stylesheet" href="{{ href }}" />{% endfor %}
</head>
<body>
    <div data-island="JobTracker"></div>
    {% if assets.js('islands/jobs.js') %}<script type="module" src="{{ assets.js('islands/jobs.js') }}"></script>{% endif %}
</body>
</html>
```

- [ ] **Step 6: Exclude `/jobs` from the public cache middleware**

In `app/bootstrap.php`, the public cache middleware skips `/api` and `/admin`.
Add `/jobs` so authed pages are never edge-cached. Change:

```php
        && !str_starts_with($request->getUri()->getPath(), '/admin')) {
```
to:
```php
        && !str_starts_with($request->getUri()->getPath(), '/admin')
        && !str_starts_with($request->getUri()->getPath(), '/jobs')) {
```

- [ ] **Step 7: Run the test — expect pass**

Run: `vendor/bin/phpunit --filter JobsAuthTest` then the full suite `vendor/bin/phpunit`.
Expected: JobsAuthTest PASS (2 tests); full suite green.

- [ ] **Step 8: Commit**

```bash
git add app/Controllers/JobController.php app/views/jobs.twig app/routes.php app/bootstrap.php tests/Http/JobsAuthTest.php
git commit -m "Add Clerk-gated /jobs routes + JSON API + shell"
```

---

## Phase 2 — Frontend

### Task 5: Copy the SPA, add the island entry + third Vite input

**Files:**
- Create: `islands/jobtracker/**` (copied), `islands/jobs.js`
- Modify: `vite.config.js`

- [ ] **Step 1: Copy the SPA source into the repo**

Run (copies App.svelte, components/, lib/, styles/ — NOT main.js, which the
island entry replaces):

```bash
mkdir -p islands/jobtracker
cp /Volumes/Storage/Code/jobtracker/frontend/src/App.svelte islands/jobtracker/App.svelte
cp -R /Volumes/Storage/Code/jobtracker/frontend/src/components islands/jobtracker/components
cp -R /Volumes/Storage/Code/jobtracker/frontend/src/lib islands/jobtracker/lib
cp -R /Volumes/Storage/Code/jobtracker/frontend/src/styles islands/jobtracker/styles
```

- [ ] **Step 2: Create the island entry**

Create `islands/jobs.js`:

```js
import { mountIslands } from "./mount-islands.js";
import "./jobtracker/styles/global.css";
import JobTracker from "./jobtracker/App.svelte";

mountIslands({ JobTracker });
```

(`App.svelte` takes no props and self-initializes via `onMount` → `init()`, so
`mountIslands` mounting it into `<div data-island="JobTracker">` is enough. The
`global.css` import makes Vite emit it as this entry's CSS in the manifest.)

- [ ] **Step 3: Add the third Vite input**

In `vite.config.js`, change the input map to:

```js
        rollupOptions: {
            input: {
                main: "islands/main.js",
                admin: "islands/admin.js",
                jobs: "islands/jobs.js",
            },
        },
```

- [ ] **Step 4: Build**

Run: `yarn build`
Expected: build succeeds; `grep -o '"islands/jobs.js"' public/assets/.vite/manifest.json` prints it.
NOTE: the components have **no scoped `<style>`**, so the existing
`svelte({ emitCss: false })` is fine — all styling comes from the imported
`global.css`. If the build errors on a missing import inside a copied file, fix
the relative path; do not change `emitCss`.

- [ ] **Step 5: Commit**

```bash
git add islands/jobtracker islands/jobs.js vite.config.js
git commit -m "Copy Job Tracker SPA as islands/jobs.js (third Vite entry)"
```

---

### Task 6: Trim the ported frontend (API base, drop accent/theme)

**Files:**
- Modify: `islands/jobtracker/lib/api.js`, `lib/state.svelte.js`,
  `components/Header.svelte`, `components/SettingsPopover.svelte`

- [ ] **Step 1: Repoint the API base**

In `islands/jobtracker/lib/api.js`, change:
```js
const API = 'api';
```
to:
```js
const API = '/jobs/api';
```

- [ ] **Step 2: Trim the store — drop accent/theme, keep stale_days**

In `islands/jobtracker/lib/state.svelte.js`:

Change the default settings line:
```js
const DEFAULT_SETTINGS = { accent: '0.62 0.16 35', stale_days: 14, theme: 'light' };
```
to:
```js
const DEFAULT_SETTINGS = { stale_days: 14 };
```

Delete the entire `applyAccent()` and `applyTheme()` functions (the
`/* ---------------- theme / accent ---------------- */` block).

In `init()`, remove the `applyTheme();` call in the `finally` block (dark mode is
the site switcher's job now). The `finally` becomes:
```js
  } finally {
    store.loading = false;
  }
```

In `patchSettings()`, remove both `applyTheme();` calls so it reads:
```js
export async function patchSettings(patch) {
  Object.assign(store.settings, patch);
  try {
    store.settings = await api.updateSettings(patch);
  } catch (e) {
    store.error = e.message;
  }
}
```

- [ ] **Step 3: Rewire the Header dark toggle to the site switcher**

In `islands/jobtracker/components/Header.svelte`, replace the `toggleTheme`
function (which called `patchSettings({ theme })`) with one that flips the site's
`.dark` class + `theme` cookie (the mechanism `layout.twig` uses):

```js
  function toggleTheme() {
    const root = document.documentElement;
    const dark = root.classList.toggle('dark');
    root.classList.remove(dark ? 'light' : 'dark');
    document.cookie = `theme=${dark ? 'dark' : 'light'};path=/;max-age=31536000;samesite=lax`;
  }
```

And change the toggle button's icon condition from `store.settings.theme === 'dark'`
to read the class directly — replace:
```svelte
      {#if store.settings.theme === 'dark'}
```
with:
```svelte
      {#if typeof document !== 'undefined' && document.documentElement.classList.contains('dark')}
```

(If reactivity of the icon matters, this is cosmetic; the toggle itself works.
Leave the surrounding sun/moon markup unchanged.)

- [ ] **Step 4: Drop the accent picker from SettingsPopover**

In `islands/jobtracker/components/SettingsPopover.svelte`, remove the
`<h4>Accent color</h4>` block and the accent swatch loop (the `.sw` buttons and
the `ACCENTS` array in the `<script>`). Keep the stale-days control intact. The
component should now render only the stale-days setting.

- [ ] **Step 5: Build — expect success**

Run: `yarn build`
Expected: build succeeds (no references to the removed `applyAccent`/`applyTheme`
or accent state remain — `grep -rn "applyAccent\|applyTheme\|settings.accent\|settings.theme" islands/jobtracker` returns nothing).

- [ ] **Step 6: Commit**

```bash
git add islands/jobtracker
git commit -m "Job Tracker frontend: repoint API to /jobs/api, drop accent/theme (site switcher owns dark)"
```

---

### Task 7: Reskin `global.css` to NeoBrutalism

**Files:**
- Modify: `islands/jobtracker/styles/global.css`

This is a design pass: the components draw entirely from CSS custom properties, so
redefining the token block does most of the work; the structural rules
(borders/radii/shadows/fonts) then get NeoBrutalism treatment. Verify visually.

- [ ] **Step 1: Replace the token block (`:root` + the dark override)**

Replace the top `:root { … }` token definitions and the `[data-theme="dark"] { … }`
block with site-palette aliases (mapping the tracker's tokens onto `theme.css`),
and switch dark mode to the site's `:root.dark` class:

```css
:root {
  /* NeoBrutalism palette, aliased from the site's theme.css tokens. */
  --accent: var(--color-accent);            /* #ffdb33 */
  --accent-soft: var(--color-surface);      /* light yellow */
  --accent-ink: var(--color-text);          /* black ink on accent areas */
  --bg: var(--color-bg-subtle);             /* page */
  --surface: var(--color-bg);               /* cards/inputs (white) */
  --ink: var(--color-text);
  --muted: var(--color-muted);
  --line: var(--color-border);              /* black borders */
  --line-strong: var(--color-border);
  --radius: 6px;                            /* squared-ish */
  --shadow: 4px 4px 0 var(--color-shadow);  /* yellow offset shadow */
  --sans: inherit;                          /* inherit the site body font */
  --mono: ui-monospace, SFMono-Regular, Menlo, monospace;
}

:root.dark {
  --surface: var(--color-bg);               /* theme.css .dark already remaps --color-* */
  --bg: var(--color-bg-subtle);
}
```

(Because `--bg`/`--surface`/`--ink`/etc. alias `--color-*`, the site's existing
`:root.dark` overrides in `theme.css` flow through automatically — the explicit
`:root.dark` block above only needs entries where the tracker wants something
different from the alias.)

- [ ] **Step 2: Apply NeoBrutalism structural rules**

Throughout `islands/jobtracker/styles/global.css`, make these substitutions so the
look reads as NeoBrutalism rather than soft cards:
- Card/input/button borders: change `1px solid var(--line)` / `var(--line-strong)`
  to `2px solid var(--color-border)`.
- `.card` (and `.modal`, `.pop`): set `box-shadow: var(--shadow);` (the yellow
  offset) and `border: 2px solid var(--color-border);`.
- Reduce pill/rounded radii: `border-radius: 9px` / `16px` / `20px` → `var(--radius)`
  (or `0` for a harder look on buttons/cards — designer's call).
- `.btn.primary` stays `background: var(--accent); color: var(--color-text);` with
  a `2px solid var(--color-border)` border and the offset shadow.
- Headings (`h1–h4`, `.chart h3`, stat labels): the site applies Archivo Black to
  headings globally via `theme.css`; ensure the tracker's heading elements use real
  heading tags or add `font-family: var(--font-head)` where it uses styled `<div>`s.
- Chart fills (`.bar`, `.ft`, donut segments): use `var(--accent)` /
  `var(--accent-soft)` with `2px solid var(--color-border)` so bars/funnel read as
  bordered yellow blocks.

- [ ] **Step 3: Build + verify in the browser**

Run: `yarn build`, then launch the app (use the **run** skill or
`php -S 127.0.0.1:8088 -t public public/router.php`) and visit `/jobs`. Because
`/jobs` is Clerk-gated, either sign in (dev instance) or temporarily check the
rendered SPA by pointing a browser at the built island in a throwaway HTML harness.
Confirm: yellow accents, black borders, offset shadows, Archivo Black headings;
toggle dark mode via the header button and confirm the `.dark` palette applies;
charts/table/modal are legible in both themes.

- [ ] **Step 4: Commit**

```bash
git add islands/jobtracker/styles/global.css
git commit -m "Reskin Job Tracker to NeoBrutalism (site theme.css tokens + .dark)"
```

---

### Task 8: Vitest island-mount test

**Files:**
- Create: `islands/jobs.test.js`

- [ ] **Step 1: Write the test**

Create `islands/jobs.test.js`:

```js
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { mountIslands } from "./mount-islands.js";
import JobTracker from "./jobtracker/App.svelte";

describe("jobs island", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = "";
        // App.svelte's init() calls fetch('/jobs/api/...'); stub it.
        vi.stubGlobal("fetch", vi.fn(() =>
            Promise.resolve({ ok: true, text: () => Promise.resolve("[]") }),
        ));
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it("mounts the tracker and removes the SSR fallback", () => {
        document.body.innerHTML = '<div data-island="JobTracker">loading…</div>';
        const host = document.querySelector("[data-island]");

        mountIslands({ JobTracker }, document);

        // The component mounted (its root markup is present)...
        expect(host.children.length).toBeGreaterThan(0);
        // ...and the bare SSR fallback text node is gone.
        const bareText = [...host.childNodes].filter(
            (n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== "",
        );
        expect(bareText).toEqual([]);
    });
});
```

- [ ] **Step 2: Run it — expect pass**

Run: `yarn test`
Expected: the new test passes alongside the existing `mount-islands` tests.
(If `App.svelte` reaches for browser APIs jsdom lacks during init, stub them in
`beforeEach` similarly to `fetch`; keep the assertion on mount + fallback removal.)

- [ ] **Step 3: Commit**

```bash
git add islands/jobs.test.js
git commit -m "Add Vitest mount test for the jobs island"
```

---

## Phase 3 — Cleanup, docs, E2E

### Task 9: Documentation

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update CLAUDE.md**

- **Stack → Svelte line:** add the jobs island to the island list (`Typewriter`,
  `Search` public; `AdminLogin`, `AdminUserButton` admin; `JobTracker` at `/jobs`),
  and note three Vite entries (`main`/`admin`/`jobs`).
- **Architecture:** note the Clerk-gated `/jobs` group (`JobController` +
  `JobRepository`/`JobSettingsRepository`) and that the Job Tracker SPA lives in
  `islands/jobtracker/`.
- **Content model → Tables:** add `jobs` + `job_settings`.
- Note the standalone Job Tracker (old `public/jobs` / `jobs.joshuaheidorn.com`)
  is retired in favor of `/jobs`. The `deploy.sh` `public/jobs` exclude can stay
  (harmless) or be removed in a follow-up.

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "Document the /jobs Job Tracker section"
```

---

### Task 10: Manual E2E (behind Clerk)

Not automatable (needs a real Clerk session). Perform before merging.

- [ ] **Step 1: Build + run**

```bash
yarn build && docker compose up -d
php -S 127.0.0.1:8088 -t public public/router.php
```

- [ ] **Step 2: Verify behind Clerk**

Signed out, visit `/jobs` → redirects to `/admin/login`. Sign in (dev instance),
then at `/jobs`: add a job (company+status required; validation errors surface),
edit it (status change bumps the "updated" clock; a non-status edit does not), see
the stat cards + the three charts recompute, filter/sort/search the table, set the
stale-days threshold and confirm the ⚑ stale flag responds, toggle dark mode via
the header (the site `.dark` palette applies), delete a job. Confirm `/jobs/api/*`
returns 302 to `/admin/login` when signed out (e.g. in a private window).

---

## Self-Review Notes

- **Spec coverage:** data model → Task 1; backend repos + validation → Tasks 2–3;
  API + auth + shell + cache-exclusion → Task 4; SPA reuse + third entry → Task 5;
  trim accent/theme + API base → Task 6; NeoBrutalism reskin + site dark switcher →
  Task 7; island test → Task 8; docs → Task 9; manual E2E → Task 10. CSRF reasoning
  (JSON + Clerk + SameSite) is realized by the gated group + JSON controller (Task 4).
- **Placeholders:** backend/test/migration steps carry full code. The reskin (Task 7)
  is intentionally rule-driven + browser-verified rather than final pixel CSS — a
  genuine design pass, not a hand-wave; the exact token block is given, and the
  structural rules are concrete substitutions. The verbatim-copied SPA components are
  referenced by source path (a precise instruction, not a placeholder).
- **Type/name consistency:** `JobRepository`/`JobSettingsRepository` method names
  (`all/find/create/update/delete`, `get/update`) match the controller calls; route
  paths (`/jobs`, `/jobs/api/*`) match `api.js`'s `/jobs/api` base and the
  `JobsAuthTest`; the island name `JobTracker` matches the `data-island` attribute,
  the `islands/jobs.js` registry, and the Vitest test; manifest key `islands/jobs.js`
  matches `assets.js('islands/jobs.js')` in `jobs.twig`.
- **Open implementation-time checks:** (1) whether `bootstrap.php` already calls
  `addBodyParsingMiddleware()` (needed for JSON `getParsedBody()` in PUT/POST);
  (2) MySQL `id` explicit-insert for the settings row under the auto-increment PK;
  (3) any browser API the SPA's `init()` touches under jsdom in the island test.
