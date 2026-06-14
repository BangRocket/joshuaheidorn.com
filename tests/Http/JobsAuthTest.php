<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Support\Database;
use App\Support\Importer;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('mutatingRoutes')]
    public function test_mutating_routes_are_gated_when_signed_out(string $method, string $path): void
    {
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest($method, $path);
        $res = $app->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/login', $res->getHeaderLine('Location'));
    }

    /** @return array<string, array{string, string}> */
    public static function mutatingRoutes(): array
    {
        return [
            'create job' => ['POST', '/jobs/api/jobs'],
            'update job' => ['PUT', '/jobs/api/jobs/1'],
            'delete job' => ['DELETE', '/jobs/api/jobs/1'],
            'save settings' => ['PUT', '/jobs/api/settings'],
        ];
    }
}
