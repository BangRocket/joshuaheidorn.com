<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TactaApiTest extends TestCase
{
    private function app()
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DELETE FROM tacta_moves');
        $pdo->exec('DELETE FROM tacta_players');
        $pdo->exec('DELETE FROM tacta_games');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $_ENV['DB_NAME'] = $config['db_test']['name'];
        putenv('DB_NAME=' . $config['db_test']['name']);
        $_ENV['CLERK_SECRET_KEY'] = 'sk_test_dummy';
        $_ENV['ADMIN_CLERK_USER_ID'] = 'user_dummy';

        return require $root . '/app/bootstrap.php';
    }

    /** @param array<string,mixed> $body */
    private function post($app, string $path, array $body, array $cookies = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withCookieParams($cookies);
        $req->getBody()->write(json_encode($body));
        $req->getBody()->rewind();

        return $app->handle($req);
    }

    private function get($app, string $path, array $cookies = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams($cookies);

        return $app->handle($req);
    }

    private function body(ResponseInterface $res): array
    {
        return json_decode((string) $res->getBody(), true) ?? [];
    }

    private function cookieToken(ResponseInterface $res, string $code): string
    {
        foreach ($res->getHeader('Set-Cookie') as $line) {
            if (preg_match('/tacta_' . preg_quote($code, '/') . '=([^;]+)/', $line, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    public function test_page_renders_with_mount_point(): void
    {
        $app = $this->app();
        $res = $this->get($app, '/tacta');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('data-island="Tacta"', (string) $res->getBody());
    }

    public function test_create_returns_a_code(): void
    {
        $app = $this->app();
        $res = $this->post($app, '/tacta/api/games', []);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $this->body($res)['code']);
    }

    public function test_create_requires_json(): void
    {
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/tacta/api/games');
        $res = $app->handle($req); // no application/json header
        $this->assertSame(415, $res->getStatusCode());
    }

    public function test_join_sets_cookie_and_state_shows_player(): void
    {
        $app = $this->app();
        $code = $this->body($this->post($app, '/tacta/api/games', []))['code'];

        $join = $this->post($app, "/tacta/api/games/{$code}/join", ['name' => 'Josh', 'color' => 'red']);
        $this->assertSame(201, $join->getStatusCode());
        $this->assertSame(0, $this->body($join)['seat']);
        $this->assertTrue($this->body($join)['is_host']);
        $token = $this->cookieToken($join, $code);
        $this->assertNotSame('', $token);

        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state"));
        $this->assertSame('lobby', $state['status']);
        $this->assertCount(1, $state['players']);
        $this->assertSame('red', $state['players'][0]['color']);
    }

    public function test_join_rejects_duplicate_color(): void
    {
        $app = $this->app();
        $code = $this->body($this->post($app, '/tacta/api/games', []))['code'];
        $this->post($app, "/tacta/api/games/{$code}/join", ['name' => 'Josh', 'color' => 'red']);
        $dup = $this->post($app, "/tacta/api/games/{$code}/join", ['name' => 'Pat', 'color' => 'red']);
        $this->assertSame(400, $dup->getStatusCode());
    }

    public function test_state_includes_private_hand_only_for_the_cookie_holder(): void
    {
        $app = $this->app();
        $code = $this->body($this->post($app, '/tacta/api/games', []))['code'];
        $j1 = $this->post($app, "/tacta/api/games/{$code}/join", ['name' => 'Josh', 'color' => 'red']);
        $t1 = $this->cookieToken($j1, $code);

        // Anonymous state has no "you".
        $anon = $this->body($this->get($app, "/tacta/api/games/{$code}/state"));
        $this->assertNull($anon['you']);

        // Cookie holder sees their seat.
        $mine = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $t1]));
        $this->assertSame(0, $mine['you']['seat']);
    }

    public function test_state_unknown_code_is_404(): void
    {
        $app = $this->app();
        $res = $this->get($app, '/tacta/api/games/ZZZZZZ/state');
        $this->assertSame(404, $res->getStatusCode());
    }
}
