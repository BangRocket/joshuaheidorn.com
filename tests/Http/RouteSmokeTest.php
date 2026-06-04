<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Support\Database;
use App\Support\Importer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RouteSmokeTest extends TestCase
{
    private function app()
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/app/config.php';

        // Seed the test database, then point the default connection at it so
        // bootstrap.php (which reads config['db']) builds the app against it.
        $pdo = Database::connect($config['db_test']);
        (new Importer($pdo, [
            'base' => $root,
            'uploads_src' => $root . '/uploads',
            'uploads_dest' => sys_get_temp_dir() . '/jh_uploads_test',
        ]))->run();

        $_ENV['DB_NAME'] = $config['db_test']['name'];
        putenv('DB_NAME=' . $config['db_test']['name']);

        return require $root . '/app/bootstrap.php';
    }

    public function test_home_renders(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<main', (string) $response->getBody());
    }

    public function test_post_detail_renders(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/posts/building-for-the-long-term');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Building for the Long Term', (string) $response->getBody());
    }

    public function test_api_search_returns_json(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search')
            ->withQueryParams(['q' => 'framework', 'in' => ['posts']]);
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJson((string) $response->getBody());
    }
}
