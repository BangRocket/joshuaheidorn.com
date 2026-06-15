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
        $colors = array_slice(TactaGameRepository::COLORS, 0, TactaGameRepository::MAX_PLAYERS);
        foreach ($colors as $color) {
            $repo->join($code, 'P-' . $color, $color);
        }
        $this->expectException(\App\Support\ValidationException::class);
        $repo->join($code, 'Overflow', TactaGameRepository::COLORS[0]); // every seat taken, game full
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

    public function test_start_requires_two_players(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Solo', 'red');
        $this->expectException(\App\Support\ValidationException::class);
        $repo->start($code);
    }

    public function test_start_activates_game_and_deals_decks(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');
        $game = $repo->start($code);

        $this->assertSame('active', $game['status']);
        $this->assertIsArray($game['turn_order']);
        $this->assertEqualsCanonicalizing([0, 1], $game['turn_order']);
        $this->assertContains($game['current_seat'], [0, 1]);
        $this->assertSame($game['turn_order'][0], $game['current_seat']);

        foreach ($repo->players($game['id']) as $player) {
            $this->assertIsArray($player['deck']);
            $this->assertCount(18, $player['deck']);
            $this->assertEqualsCanonicalizing(range(0, 17), $player['deck']);
        }
    }

    public function test_start_rejects_already_started_game(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');
        $repo->start($code);
        $this->expectException(\App\Support\ValidationException::class);
        $repo->start($code);
    }

    /** @return array<string,mixed> a dummy placement (legality is not checked here) */
    private function dummyMove(int $x): array
    {
        return [
            'card_id' => 'red-1',
            'draw_end' => 'top',
            'x' => $x,
            'y' => 0,
            'rotation' => 0,
            'mirror' => false,
        ];
    }

    public function test_record_move_rejects_when_not_active(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');
        $this->expectException(\App\Support\ValidationException::class);
        $repo->recordMove($code, 0, $this->dummyMove(0)); // still in lobby
    }

    public function test_record_move_rejects_out_of_turn(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');
        $game = $repo->start($code);
        $notCurrent = $game['current_seat'] === 0 ? 1 : 0;

        $this->expectException(\App\Support\ValidationException::class);
        $repo->recordMove($code, $notCurrent, $this->dummyMove(0));
    }

    public function test_record_move_appends_advances_turn_and_is_visible(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');
        $game = $repo->start($code);
        $first = $game['current_seat'];
        $seqBefore = $game['seq'];

        $afterMove = $repo->recordMove($code, $first, $this->dummyMove(1));
        $this->assertSame($game['turn_order'][1], $afterMove['current_seat']); // advanced
        $this->assertSame($seqBefore + 1, $afterMove['seq']);

        $moves = $repo->movesSince($afterMove['id'], $seqBefore);
        $this->assertCount(1, $moves);
        $this->assertSame($first, $moves[0]['seat']);
        $this->assertSame(1, $moves[0]['z']); // first placed card (starting card is z=0)
    }

    public function test_game_ends_after_all_cards_are_played(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $repo->join($code, 'Pat', 'blue');
        $repo->start($code);

        $total = TactaGameRepository::CARDS_PER_PLAYER * 2; // 36
        for ($i = 0; $i < $total; $i++) {
            $game = $repo->findByCode($code);
            $repo->recordMove($code, $game['current_seat'], $this->dummyMove($i));
        }

        $game = $repo->findByCode($code);
        $this->assertSame('done', $game['status']);
        $this->assertNull($game['current_seat']);
        $this->assertCount($total, $repo->movesSince($game['id'], 0));
    }

    public function test_three_player_turn_rotation_wraps(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'A', 'red');
        $repo->join($code, 'B', 'blue');
        $repo->join($code, 'C', 'green');
        $game = $repo->start($code);
        $order = $game['turn_order']; // some rotation of [0,1,2]
        $this->assertEqualsCanonicalizing([0, 1, 2], $order);

        // current_seat must cycle through turn_order and wrap around.
        $expected = [$order[1], $order[2], $order[0], $order[1]];
        foreach ($expected as $i => $expectSeat) {
            $current = $repo->findByCode($code)['current_seat'];
            $after = $repo->recordMove($code, $current, $this->dummyMove($i));
            $this->assertSame($expectSeat, $after['current_seat']);
        }
    }

    public function test_purge_stale_deletes_idle_games_and_cascades(): void
    {
        [$repo, $pdo] = $this->repo();
        $code = $repo->createGame()['code'];
        $repo->join($code, 'Josh', 'red');
        $game = $repo->findByCode($code);

        // Back-date so it counts as stale.
        $stmt = $pdo->prepare("UPDATE tacta_games SET updated_at = '2020-01-01 00:00:00' WHERE id = :id");
        $stmt->execute([':id' => $game['id']]);

        $deleted = $repo->purgeStale(60);
        $this->assertSame(1, $deleted);
        $this->assertNull($repo->findByCode($code));
        // FK cascade removed the player too.
        $this->assertCount(0, $repo->players($game['id']));
    }

    public function test_purge_stale_keeps_fresh_games(): void
    {
        [$repo] = $this->repo();
        $code = $repo->createGame()['code'];
        $this->assertSame(0, $repo->purgeStale(60));
        $this->assertNotNull($repo->findByCode($code));
    }
}
