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
}
