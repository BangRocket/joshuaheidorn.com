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

    /** Create + 2 joins; returns [code, hostToken, guestToken]. */
    private function twoPlayerLobby($app): array
    {
        $code = $this->body($this->post($app, '/tacta/api/games', []))['code'];
        $j1 = $this->post($app, "/tacta/api/games/{$code}/join", ['name' => 'Josh', 'color' => 'red']);
        $j2 = $this->post($app, "/tacta/api/games/{$code}/join", ['name' => 'Pat', 'color' => 'blue']);

        return [$code, $this->cookieToken($j1, $code), $this->cookieToken($j2, $code)];
    }

    public function test_only_host_can_start(): void
    {
        $app = $this->app();
        [$code, , $guestToken] = $this->twoPlayerLobby($app);

        $byGuest = $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $guestToken]);
        $this->assertSame(403, $byGuest->getStatusCode());
    }

    public function test_host_start_activates_game(): void
    {
        $app = $this->app();
        [$code, $hostToken] = $this->twoPlayerLobby($app);

        $start = $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);
        $this->assertSame(200, $start->getStatusCode());

        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $hostToken]));
        $this->assertSame('active', $state['status']);
        $this->assertNotNull($state['you']['hand']['top']);
    }

    public function test_move_out_of_turn_is_rejected(): void
    {
        $app = $this->app();
        [$code, $hostToken, $guestToken] = $this->twoPlayerLobby($app);
        $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);

        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $hostToken]));
        $current = $state['current_seat'];
        $notCurrentToken = $current === 0 ? $guestToken : $hostToken;

        $res = $this->post($app, "/tacta/api/games/{$code}/moves",
            ['draw_end' => 'top', 'x' => 0, 'y' => -1, 'rotation' => 0, 'mirror' => false],
            ['tacta_' . $code => $notCurrentToken]);
        $this->assertSame(409, $res->getStatusCode());
    }

    public function test_legal_first_move_is_accepted_and_advances_turn(): void
    {
        $app = $this->app();
        [$code, $hostToken, $guestToken] = $this->twoPlayerLobby($app);
        $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);

        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $hostToken]));
        $current = $state['current_seat'];
        $currentToken = $current === 0 ? $hostToken : $guestToken;

        // Read the current player's top card and compute a legal placement via the engine.
        $me = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $currentToken]))['you'];
        [$color, $n] = explode('-', $me['hand']['top']);
        $card = \App\Tacta\Deck::forColor($color)[(int) $n - 1];
        $board = \App\Tacta\BoardBuilder::build([]);
        $legal = \App\Tacta\Rules::legalConnects($board, $card, $color, $board->nextZ());
        $this->assertNotEmpty($legal);
        $place = $legal[0];

        $res = $this->post($app, "/tacta/api/games/{$code}/moves", [
            'draw_end' => 'top',
            'x' => $place->x, 'y' => $place->y,
            'rotation' => $place->rotation, 'mirror' => $place->mirror,
        ], ['tacta_' . $code => $currentToken]);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $after = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $currentToken]));
        $this->assertNotSame($current, $after['current_seat']); // turn advanced
        $this->assertCount(1, $after['moves']);                  // one card on the board
        $this->assertSame($place->x, $after['moves'][0]['x']);
    }

    public function test_illegal_move_is_rejected_with_400(): void
    {
        $app = $this->app();
        [$code, $hostToken, $guestToken] = $this->twoPlayerLobby($app);
        $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);
        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $hostToken]));
        $currentToken = $state['current_seat'] === 0 ? $hostToken : $guestToken;

        // Far-away isolated drop while a legal connect to the starting card exists.
        $res = $this->post($app, "/tacta/api/games/{$code}/moves",
            ['draw_end' => 'top', 'x' => 20, 'y' => 20, 'rotation' => 0, 'mirror' => false],
            ['tacta_' . $code => $currentToken]);
        $this->assertSame(400, $res->getStatusCode());
    }

    public function test_move_without_identity_cookie_is_forbidden(): void
    {
        $app = $this->app();
        [$code, $hostToken] = $this->twoPlayerLobby($app);
        $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);

        // No cookie at all -> not a recognised player.
        $res = $this->post($app, "/tacta/api/games/{$code}/moves",
            ['draw_end' => 'top', 'x' => 0, 'y' => -1, 'rotation' => 0, 'mirror' => false]);
        $this->assertSame(403, $res->getStatusCode());
    }

    public function test_deck_endpoint_returns_18_layouts(): void
    {
        $app = $this->app();
        $res = $this->get($app, '/tacta/api/deck');
        $this->assertSame(200, $res->getStatusCode());
        $body = $this->body($res);
        $this->assertCount(18, $body['layouts']);
        $first = $body['layouts'][0];
        $this->assertArrayHasKey('edges', $first);
        $this->assertArrayHasKey('N', $first['edges']);
        $this->assertArrayHasKey('shape', $first['edges']['N']);
        $this->assertArrayHasKey('dots', $first['edges']['N']);
    }

    public function test_state_includes_legal_moves_for_the_current_player(): void
    {
        $app = $this->app();
        [$code, $hostToken, $guestToken] = $this->twoPlayerLobby($app);
        $this->post($app, "/tacta/api/games/{$code}/start", [], ['tacta_' . $code => $hostToken]);

        $state = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $hostToken]));
        $currentToken = $state['current_seat'] === 0 ? $hostToken : $guestToken;

        $me = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $currentToken]))['you'];
        $this->assertTrue($me['your_turn']);
        $this->assertNotEmpty($me['legal']); // a first move against the starting card always exists
        $first = $me['legal'][0];
        foreach (['card_id', 'draw_end', 'x', 'y', 'rotation', 'mirror'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        // The non-current player gets no legal list.
        $otherToken = $currentToken === $hostToken ? $guestToken : $hostToken;
        $other = $this->body($this->get($app, "/tacta/api/games/{$code}/state", ['tacta_' . $code => $otherToken]))['you'];
        $this->assertFalse($other['your_turn']);
        $this->assertArrayNotHasKey('legal', $other);
    }
}
