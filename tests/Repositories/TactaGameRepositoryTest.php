<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\TactaGameRepository;
use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class TactaGameRepositoryTest extends TestCase
{
    /** @return array{0: TactaGameRepository, 1: PDO} */
    private function repo(): array
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DELETE FROM tacta_moves');
        $pdo->exec('DELETE FROM tacta_players');
        $pdo->exec('DELETE FROM tacta_games');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return [new TactaGameRepository($pdo), $pdo];
    }

    public function test_create_game_makes_a_lobby_with_a_code(): void
    {
        [$repo] = $this->repo();
        $game = $repo->createGame();

        $this->assertSame('lobby', $game['status']);
        $this->assertSame(0, $game['seq']);
        $this->assertNull($game['current_seat']);
        $this->assertNull($game['turn_order']);
        $this->assertSame(6, strlen($game['code']));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $game['code']);
    }

    public function test_find_by_code_round_trips(): void
    {
        [$repo] = $this->repo();
        $game = $repo->createGame();
        $found = $repo->findByCode($game['code']);

        $this->assertNotNull($found);
        $this->assertSame($game['id'], $found['id']);
    }

    public function test_find_by_unknown_code_returns_null(): void
    {
        [$repo] = $this->repo();
        $this->assertNull($repo->findByCode('ZZZZZZ'));
    }

    public function test_codes_are_unique_across_games(): void
    {
        [$repo] = $this->repo();
        $codes = [];
        for ($i = 0; $i < 25; $i++) {
            $codes[] = $repo->createGame()['code'];
        }
        $this->assertCount(25, array_unique($codes));
    }

    public function test_first_join_is_seat_zero_host(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $player = $repo->join($code, 'Josh', 'red');

        $this->assertSame(0, $player['seat']);
        $this->assertTrue($player['is_host']);
        $this->assertSame('red', $player['color']);
        $this->assertNotSame('', $player['guest_token']);
    }

    public function test_second_join_is_seat_one_not_host(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $second = $repo->join($code, 'Pat', 'blue');

        $this->assertSame(1, $second['seat']);
        $this->assertFalse($second['is_host']);
    }

    public function test_join_rejects_duplicate_color(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $this->expectException(\App\Support\ValidationException::class);
        $repo->join($code, 'Pat', 'red');
    }

    public function test_join_rejects_invalid_color(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $this->expectException(\App\Support\ValidationException::class);
        $repo->join($code, 'Josh', 'chartreuse');
    }

    public function test_join_rejects_empty_name(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $this->expectException(\App\Support\ValidationException::class);
        $repo->join($code, '   ', 'red');
    }

    public function test_join_rejects_when_full(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        foreach (TactaGameRepository::COLORS as $color) {
            $repo->join($code, 'P-' . $color, $color);
        }
        $this->expectException(\App\Support\ValidationException::class);
        $repo->join($code, 'Overflow', 'red'); // all 6 colors used, game full
    }

    public function test_join_bumps_seq_and_lists_players(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');

        $game = $repo->findByCode($code);
        $this->assertSame(2, $game['seq']); // one bump per join
        $players = $repo->players($game['id']);
        $this->assertCount(2, $players);
        $this->assertSame(['red', 'blue'], array_map(static fn ($p) => $p['color'], $players));
    }

    public function test_player_by_token_finds_the_seat(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $player = $repo->join($code, 'Josh', 'red');
        $game = $repo->findByCode($code);

        $found = $repo->playerByToken($game['id'], $player['guest_token']);
        $this->assertNotNull($found);
        $this->assertSame(0, $found['seat']);
        $this->assertNull($repo->playerByToken($game['id'], 'nope'));
    }
}
