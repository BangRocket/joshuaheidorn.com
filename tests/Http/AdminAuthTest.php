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
        $location = $response->getHeaderLine('Location');
        $this->assertStringStartsWith('/admin/login?next=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('/admin', $q['next']);
    }

    public function test_login_page_is_reachable_when_signed_out(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/login');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_page_carries_jobs_return_url_and_context(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/login?next=%2Fjobs');
        $response = $app->handle($request);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        // The validated return URL is handed to the sign-in island (JSON-encoded "/jobs")...
        $this->assertStringContainsString('\/jobs', $body);
        // ...and the heading reflects the Jobs context.
        $this->assertStringContainsString('Jobs', $body);
    }

    public function test_login_page_ignores_malicious_return_url(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/login?next=https%3A%2F%2Fevil.com');
        $response = $app->handle($request);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('evil.com', $body);
    }

    public function test_legacy_php_session_does_not_unlock_admin(): void
    {
        // A leftover pre-Clerk session value must NOT grant access: the gate
        // is Clerk-only. This fails against the old session middleware and
        // guards against accidentally re-enabling session auth.
        $app = $this->app();
        $_SESSION['uid'] = 1;
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');
        $response = $app->handle($request);
        unset($_SESSION['uid']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/admin/login', $response->getHeaderLine('Location'));
    }
}
