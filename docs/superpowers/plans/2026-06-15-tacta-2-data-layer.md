# Tacta Phase 2 — Data Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist Tacta games in MySQL — a migration for `tacta_games`/`tacta_players`/`tacta_moves` and a `TactaGameRepository` that owns all SQL for the room lifecycle (create → join → start → record moves) and state reads.

**Architecture:** One Phinx migration plus one repository (`App\Repositories\TactaGameRepository`) following the existing `JobRepository` pattern: injected `PDO`, prepared statements only, column whitelists, `ValidationException` for bad input, and `cast*()` helpers that normalize MySQL's string columns. The repository handles persistence and turn bookkeeping; it deliberately does **not** validate card-placement legality (that's the Phase 3 controller, using the Phase 1 engine). It uses the Phase 1 `App\Tacta\Deck` only to shuffle decks and compute the first player.

**Tech Stack:** PHP 8.2, PDO/MySQL, Phinx migrations, PHPUnit (DB-backed tests against the `joshuaheidorn_test` database). Run tests with `vendor/bin/phpunit`.

---

## Spec coverage & prerequisites

Implements the **"Data model"** and **room lifecycle** parts of
`docs/superpowers/specs/2026-06-15-tacta-game-design.md`. Builds on Phase 1
(`App\Tacta\Deck`, already merged on this branch). Phase 3 (API) and Phase 4 (client) follow.

**Prerequisite — running database.** These tasks need MySQL up and the schema migrated:
```bash
docker compose up -d                              # starts MySQL on 127.0.0.1:3306
vendor/bin/phinx migrate -e development           # (after Task 1 adds the migration)
vendor/bin/phinx migrate -e testing               # the joshuaheidorn_test DB used by tests
```
If `docker compose` or MySQL is unavailable, STOP and report BLOCKED — the DB tests cannot run without it.

## Data model

**`tacta_games`** — one row per room.

| column | type | notes |
| --- | --- | --- |
| `id` | integer pk | |
| `code` | string(12) | unique room code (6 chars from an unambiguous alphabet) |
| `status` | string(16) | `lobby` → `active` → `done` |
| `current_seat` | integer null | whose turn (seat number); null in lobby and when done |
| `turn_order` | text null | JSON array of seats in play order; null until start |
| `seq` | integer | monotonic change counter (polling cursor); bumped on join/start/move |
| `created_at` / `updated_at` | datetime | server-owned |

**`tacta_players`** — one row per seat.

| column | type | notes |
| --- | --- | --- |
| `id` | integer pk | |
| `game_id` | integer fk → tacta_games (cascade delete) | |
| `seat` | integer | 0-based; seat 0 is the host |
| `color` | string(16) | one of the six player colors |
| `display_name` | string(64) | |
| `guest_token` | string(64) | random; stored in the player's cookie to identify the seat |
| `deck` | text null | JSON array of 18 layout indices (0–17), shuffled at start |
| `is_host` | boolean | true for seat 0 |
| `joined_at` | datetime | |

Unique `(game_id, seat)` and `(game_id, color)`; index on `guest_token`.

**`tacta_moves`** — append-only log; the board is reconstructed from it (Phase 3).

| column | type | notes |
| --- | --- | --- |
| `id` | integer pk | |
| `game_id` | integer fk → tacta_games (cascade delete) | |
| `seq` | integer | the game `seq` assigned to this move (polling filter/order) |
| `seat` | integer | who played |
| `card_id` | string(32) | e.g. `red-7` |
| `draw_end` | string(8) | `top` or `bottom` (which end of the deque) |
| `x` / `y` | integer | cell coordinates |
| `rotation` | integer | 0–3 |
| `mirror` | boolean | back face? |
| `z` | integer | board stacking ordinal (1-based; the neutral starting card is z=0) |
| `created_at` | datetime | |

Index `(game_id, seq)`.

## File structure

```
db/migrations/<timestamp>_create_tacta_tables.php   # the three tables
app/Repositories/TactaGameRepository.php            # all Tacta SQL + lifecycle
tests/Repositories/TactaGameRepositoryTest.php      # DB-backed tests
```

---

## Task 1: Migration — `tacta_games` / `tacta_players` / `tacta_moves`

**Files:**
- Create: `db/migrations/<timestamp>_create_tacta_tables.php`

Phinx requires a creation-ordered timestamp filename and a matching `Camel` class name.

- [ ] **Step 1: Generate the migration skeleton**

Run:
```bash
vendor/bin/phinx create CreateTactaTables
```
Expected: prints the path of a new file `db/migrations/<timestamp>_create_tacta_tables.php`.

- [ ] **Step 2: Replace the file body with the schema**

Open the generated file and replace its entire contents with (keep the generated class name if it differs — it must match the filename Phinx produced; the generated name for `CreateTactaTables` is `CreateTactaTables`):
```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTactaTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tacta_games')
            ->addColumn('code', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'lobby'])
            ->addColumn('current_seat', 'integer', ['null' => true])
            ->addColumn('turn_order', 'text', ['null' => true])
            ->addColumn('seq', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['code'], ['unique' => true])
            ->addIndex(['updated_at'])
            ->create();

        $this->table('tacta_players')
            ->addColumn('game_id', 'integer', ['null' => false])
            ->addColumn('seat', 'integer', ['null' => false])
            ->addColumn('color', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('guest_token', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('deck', 'text', ['null' => true])
            ->addColumn('is_host', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('joined_at', 'datetime', ['null' => false])
            ->addIndex(['game_id', 'seat'], ['unique' => true])
            ->addIndex(['game_id', 'color'], ['unique' => true])
            ->addIndex(['guest_token'])
            ->addForeignKey('game_id', 'tacta_games', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('tacta_moves')
            ->addColumn('game_id', 'integer', ['null' => false])
            ->addColumn('seq', 'integer', ['null' => false])
            ->addColumn('seat', 'integer', ['null' => false])
            ->addColumn('card_id', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('draw_end', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('x', 'integer', ['null' => false])
            ->addColumn('y', 'integer', ['null' => false])
            ->addColumn('rotation', 'integer', ['null' => false])
            ->addColumn('mirror', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('z', 'integer', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['game_id', 'seq'])
            ->addForeignKey('game_id', 'tacta_games', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
```

- [ ] **Step 3: Apply the migration to both databases**

Run:
```bash
docker compose up -d
vendor/bin/phinx migrate -e development
vendor/bin/phinx migrate -e testing
```
Expected: both runs end with `All Done.` and report `== CreateTactaTables: migrated`.

- [ ] **Step 4: Verify the tables exist**

Run:
```bash
docker compose exec -T db mysql -uroot joshuaheidorn_test -e "SHOW TABLES LIKE 'tacta_%';"
```
Expected: lists `tacta_games`, `tacta_moves`, `tacta_players`.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/*_create_tacta_tables.php
git commit -m "feat(tacta): migration for games/players/moves tables

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `TactaGameRepository::createGame` + `findByCode`

**Files:**
- Create: `app/Repositories/TactaGameRepository.php`
- Test: `tests/Repositories/TactaGameRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Repositories/TactaGameRepositoryTest.php`:
```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: FAIL — `Class "App\Repositories\TactaGameRepository" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Repositories/TactaGameRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ValidationException;
use App\Tacta\Deck;
use PDO;

/**
 * All SQL for the Tacta room lifecycle: create → join → start → record moves,
 * plus state reads. Persistence + turn bookkeeping only; card-placement legality
 * is enforced by the Phase 3 controller using the App\Tacta engine.
 */
final class TactaGameRepository
{
    public const COLORS = ['blue', 'green', 'orange', 'pink', 'purple', 'red'];
    public const MAX_PLAYERS = 6;
    public const CARDS_PER_PLAYER = 18;

    // No ambiguous characters (I/O/0/1/L excluded).
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 6;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> the new lobby game */
    public function createGame(): array
    {
        $code = $this->generateUniqueCode();
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO tacta_games (code, status, current_seat, turn_order, seq, created_at, updated_at)
             VALUES (:code, :status, NULL, NULL, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':code' => $code,
            ':status' => 'lobby',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->findByCode($code);
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tacta_games WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return $row ? $this->castGame($row) : null;
    }

    private function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $stmt = $this->pdo->prepare('SELECT 1 FROM tacta_games WHERE code = :code');
            $stmt->execute([':code' => $code]);
            if ($stmt->fetchColumn() === false) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique room code.');
    }

    /** @param array<string,mixed> $row */
    private function castGame(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'status' => $row['status'],
            'current_seat' => $row['current_seat'] === null ? null : (int) $row['current_seat'],
            'turn_order' => $row['turn_order'] === null ? null : json_decode($row['turn_order'], true),
            'seq' => (int) $row['seq'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }
}
```

Note: the CODE_ALPHABET contains only A–Z and 2–9, so the test's `/^[A-Z0-9]{6}$/` holds.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/TactaGameRepository.php tests/Repositories/TactaGameRepositoryTest.php
git commit -m "feat(tacta): TactaGameRepository createGame + findByCode

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `join` + `players` + `playerByToken`

**Files:**
- Modify: `app/Repositories/TactaGameRepository.php`
- Modify: `tests/Repositories/TactaGameRepositoryTest.php`

- [ ] **Step 1: Add the failing tests**

Add these methods to `TactaGameRepositoryTest`:
```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: FAIL — `Call to undefined method ...::join()`.

- [ ] **Step 3: Add the implementation**

Add these methods to `TactaGameRepository` (before the `castGame` helper):
```php
    /**
     * Add a player to a lobby. The first joiner takes seat 0 and is the host.
     *
     * @return array<string,mixed> the new player (including guest_token)
     * @throws ValidationException
     */
    public function join(string $code, string $name, string $color): array
    {
        $game = $this->requireGame($code);
        if ($game['status'] !== 'lobby') {
            throw new ValidationException('Game has already started.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new ValidationException('Name is required.');
        }
        $name = mb_substr($name, 0, 64);
        if (!in_array($color, self::COLORS, true)) {
            throw new ValidationException('Invalid color.');
        }

        $players = $this->players($game['id']);
        if (count($players) >= self::MAX_PLAYERS) {
            throw new ValidationException('Game is full.');
        }
        foreach ($players as $existing) {
            if ($existing['color'] === $color) {
                throw new ValidationException('Color already taken.');
            }
        }

        $seat = count($players);
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            'INSERT INTO tacta_players
                (game_id, seat, color, display_name, guest_token, deck, is_host, joined_at)
             VALUES (:game_id, :seat, :color, :name, :token, NULL, :is_host, :joined_at)'
        );
        $stmt->execute([
            ':game_id' => $game['id'],
            ':seat' => $seat,
            ':color' => $color,
            ':name' => $name,
            ':token' => $token,
            ':is_host' => $seat === 0 ? 1 : 0,
            ':joined_at' => self::now(),
        ]);

        $this->bumpSeq($game['id']);

        return $this->playerByToken($game['id'], $token);
    }

    /** @return list<array<string,mixed>> players ordered by seat */
    public function players(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tacta_players WHERE game_id = :id ORDER BY seat ASC');
        $stmt->execute([':id' => $gameId]);

        return array_map([$this, 'castPlayer'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function playerByToken(int $gameId, string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tacta_players WHERE game_id = :id AND guest_token = :token'
        );
        $stmt->execute([':id' => $gameId, ':token' => $token]);
        $row = $stmt->fetch();

        return $row ? $this->castPlayer($row) : null;
    }

    /** @throws ValidationException */
    private function requireGame(string $code): array
    {
        $game = $this->findByCode($code);
        if ($game === null) {
            throw new ValidationException('Game not found.');
        }

        return $game;
    }

    private function bumpSeq(int $gameId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tacta_games SET seq = seq + 1, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => self::now(), ':id' => $gameId]);
    }

    /** @param array<string,mixed> $row */
    private function castPlayer(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'seat' => (int) $row['seat'],
            'color' => $row['color'],
            'display_name' => $row['display_name'],
            'guest_token' => $row['guest_token'],
            'deck' => $row['deck'] === null ? null : json_decode($row['deck'], true),
            'is_host' => (bool) $row['is_host'],
            'joined_at' => $row['joined_at'],
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: PASS (12 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/TactaGameRepository.php tests/Repositories/TactaGameRepositoryTest.php
git commit -m "feat(tacta): join + players + playerByToken

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `start` (shuffle decks, first player, turn order)

**Files:**
- Modify: `app/Repositories/TactaGameRepository.php`
- Modify: `tests/Repositories/TactaGameRepositoryTest.php`

- [ ] **Step 1: Add the failing tests**

Add to `TactaGameRepositoryTest`:
```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: FAIL — `Call to undefined method ...::start()`.

- [ ] **Step 3: Add the implementation**

Add to `TactaGameRepository`:
```php
    /**
     * Shuffle each player's deck once, choose the first player, fix turn order,
     * and activate the game.
     *
     * @return array<string,mixed> the activated game
     * @throws ValidationException
     */
    public function start(string $code): array
    {
        $game = $this->requireGame($code);
        if ($game['status'] !== 'lobby') {
            throw new ValidationException('Game has already started.');
        }
        $players = $this->players($game['id']);
        if (count($players) < 2) {
            throw new ValidationException('Need at least 2 players to start.');
        }

        $decks = [];
        foreach ($players as $player) {
            $order = range(0, self::CARDS_PER_PLAYER - 1);
            shuffle($order);
            $decks[$player['seat']] = $order;
            $stmt = $this->pdo->prepare('UPDATE tacta_players SET deck = :deck WHERE id = :id');
            $stmt->execute([':deck' => json_encode($order), ':id' => $player['id']]);
        }

        $firstSeat = $this->firstSeat($players, $decks);
        $turnOrder = $this->clockwiseFrom($players, $firstSeat);

        $stmt = $this->pdo->prepare(
            'UPDATE tacta_games
                SET status = :status, current_seat = :seat, turn_order = :order,
                    seq = seq + 1, updated_at = :now
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'active',
            ':seat' => $firstSeat,
            ':order' => json_encode($turnOrder),
            ':now' => self::now(),
            ':id' => $game['id'],
        ]);

        return $this->findByCode($code);
    }

    /**
     * First player = lowest value on either outermost card; tie → lowest combined
     * value of the two outermost cards; further tie → lowest seat.
     *
     * @param list<array<string,mixed>> $players
     * @param array<int, list<int>> $decks seat => shuffled layout indices
     */
    private function firstSeat(array $players, array $decks): int
    {
        // Layout values are color-independent (all colors share the 18 layouts).
        $values = array_map(static fn ($card) => $card->value(), Deck::forColor('blue'));

        $bestKey = null;
        $bestSeat = $players[0]['seat'];
        foreach ($players as $player) {
            $order = $decks[$player['seat']];
            $top = $values[$order[0]];
            $bottom = $values[$order[count($order) - 1]];
            $key = [min($top, $bottom), $top + $bottom, $player['seat']];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $bestSeat = $player['seat'];
            }
        }

        return $bestSeat;
    }

    /**
     * Seats in clockwise (ascending-seat) order, rotated so $firstSeat leads.
     *
     * @param list<array<string,mixed>> $players
     * @return list<int>
     */
    private function clockwiseFrom(array $players, int $firstSeat): array
    {
        $seats = array_map(static fn ($p) => $p['seat'], $players);
        sort($seats);
        $index = array_search($firstSeat, $seats, true);

        return array_values(array_merge(array_slice($seats, $index), array_slice($seats, 0, $index)));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: PASS (15 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/TactaGameRepository.php tests/Repositories/TactaGameRepositoryTest.php
git commit -m "feat(tacta): start — shuffle decks, first player, turn order

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `recordMove` + `movesSince` (turn advance + end detection)

**Files:**
- Modify: `app/Repositories/TactaGameRepository.php`
- Modify: `tests/Repositories/TactaGameRepositoryTest.php`

`recordMove` appends to the log, bumps `seq`, advances the turn, and flips the game to
`done` once every card has been played (total moves == 18 × player count). It enforces turn
ownership but **not** card-placement legality (Phase 3 does that).

- [ ] **Step 1: Add the failing tests**

Add to `TactaGameRepositoryTest`:
```php
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

        $after = $repo->recordMove($code, $first, $this->dummyMove(1));
        $this->assertSame($game['turn_order'][1], $after['current_seat']); // advanced
        $this->assertSame($seqBefore + 1, $after['seq']);

        $moves = $repo->movesSince($after['id'], $seqBefore);
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: FAIL — `Call to undefined method ...::recordMove()`.

- [ ] **Step 3: Add the implementation**

Add to `TactaGameRepository`:
```php
    /**
     * Append a move, advance the turn, and end the game when every card is played.
     * Enforces turn ownership only; placement legality is the controller's job.
     *
     * @param array{card_id:string,draw_end:string,x:int,y:int,rotation:int,mirror:bool} $move
     * @return array<string,mixed> the updated game
     * @throws ValidationException
     */
    public function recordMove(string $code, int $seat, array $move): array
    {
        $game = $this->requireGame($code);
        if ($game['status'] !== 'active') {
            throw new ValidationException('Game is not active.');
        }
        if ($game['current_seat'] !== $seat) {
            throw new ValidationException('It is not your turn.');
        }

        $z = $this->moveCount($game['id']) + 1; // 1-based; starting card is z=0
        $newSeq = $game['seq'] + 1;

        $stmt = $this->pdo->prepare(
            'INSERT INTO tacta_moves
                (game_id, seq, seat, card_id, draw_end, x, y, rotation, mirror, z, created_at)
             VALUES (:game_id, :seq, :seat, :card_id, :draw_end, :x, :y, :rotation, :mirror, :z, :now)'
        );
        $stmt->execute([
            ':game_id' => $game['id'],
            ':seq' => $newSeq,
            ':seat' => $seat,
            ':card_id' => (string) $move['card_id'],
            ':draw_end' => $move['draw_end'] === 'bottom' ? 'bottom' : 'top',
            ':x' => (int) $move['x'],
            ':y' => (int) $move['y'],
            ':rotation' => (int) $move['rotation'],
            ':mirror' => !empty($move['mirror']) ? 1 : 0,
            ':z' => $z,
            ':now' => self::now(),
        ]);

        $totalCards = self::CARDS_PER_PLAYER * count($this->players($game['id']));
        if ($z >= $totalCards) {
            $stmt = $this->pdo->prepare(
                'UPDATE tacta_games SET status = :status, current_seat = NULL, seq = :seq, updated_at = :now WHERE id = :id'
            );
            $stmt->execute([':status' => 'done', ':seq' => $newSeq, ':now' => self::now(), ':id' => $game['id']]);
        } else {
            $next = $this->nextSeat($game, $seat);
            $stmt = $this->pdo->prepare(
                'UPDATE tacta_games SET current_seat = :seat, seq = :seq, updated_at = :now WHERE id = :id'
            );
            $stmt->execute([':seat' => $next, ':seq' => $newSeq, ':now' => self::now(), ':id' => $game['id']]);
        }

        return $this->findByCode($code);
    }

    /** @return list<array<string,mixed>> moves with seq greater than $sinceSeq, in order */
    public function movesSince(int $gameId, int $sinceSeq): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tacta_moves WHERE game_id = :id AND seq > :seq ORDER BY seq ASC'
        );
        $stmt->execute([':id' => $gameId, ':seq' => $sinceSeq]);

        return array_map([$this, 'castMove'], $stmt->fetchAll());
    }

    private function moveCount(int $gameId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tacta_moves WHERE game_id = :id');
        $stmt->execute([':id' => $gameId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $game a cast game (turn_order is an array) */
    private function nextSeat(array $game, int $seat): int
    {
        $order = $game['turn_order'];
        $index = array_search($seat, $order, true);

        return $order[($index + 1) % count($order)];
    }

    /** @param array<string,mixed> $row */
    private function castMove(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'seq' => (int) $row['seq'],
            'seat' => (int) $row['seat'],
            'card_id' => $row['card_id'],
            'draw_end' => $row['draw_end'],
            'x' => (int) $row['x'],
            'y' => (int) $row['y'],
            'rotation' => (int) $row['rotation'],
            'mirror' => (bool) $row['mirror'],
            'z' => (int) $row['z'],
            'created_at' => $row['created_at'],
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: PASS (19 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/TactaGameRepository.php tests/Repositories/TactaGameRepositoryTest.php
git commit -m "feat(tacta): recordMove + movesSince with turn advance and end detection

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `purgeStale` (room expiry)

**Files:**
- Modify: `app/Repositories/TactaGameRepository.php`
- Modify: `tests/Repositories/TactaGameRepositoryTest.php`

- [ ] **Step 1: Add the failing test**

Add to `TactaGameRepositoryTest`:
```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: FAIL — `Call to undefined method ...::purgeStale()`.

- [ ] **Step 3: Add the implementation**

Add to `TactaGameRepository`:
```php
    /**
     * Delete games not touched in the last $olderThanMinutes (default 12h).
     * Cascades to players and moves via the foreign keys.
     *
     * @return int number of games removed
     */
    public function purgeStale(int $olderThanMinutes = 720): int
    {
        $cutoff = (new \DateTimeImmutable("-{$olderThanMinutes} minutes"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('DELETE FROM tacta_games WHERE updated_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaGameRepositoryTest`
Expected: PASS (21 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Repositories/TactaGameRepository.php tests/Repositories/TactaGameRepositoryTest.php
git commit -m "feat(tacta): purgeStale for idle-room cleanup

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Full suite green

- [ ] **Step 1: Run the entire suite (DB up)**

Run:
```bash
docker compose up -d
vendor/bin/phpunit
```
Expected: all tests pass, including the 21 `TactaGameRepositoryTest` tests, the Phase 1
`Tests\Tacta\*` tests, and the pre-existing repository/HTTP tests (which also need MySQL).

- [ ] **Step 2: If green, Phase 2 is complete**

Do not proceed to Phase 3 with a red suite. If a pre-existing test fails for an unrelated
reason (e.g., environment), note it but ensure no `TactaGameRepositoryTest` test is failing.

---

## Self-review notes (already applied)

- **Spec coverage:** the three tables and their columns match the spec's Data model exactly
  (Task 1); create/join/start/record/state-read cover the room lifecycle (Tasks 2–6);
  `guest_token` provides the identity hook Phase 3 needs; `seq` is the polling cursor;
  `movesSince` is the polling read.
- **Deferred to Phase 3 (intentionally):** placement legality (engine-backed), board
  reconstruction from `tacta_moves`, score computation, choosing which card (top/bottom) the
  move plays, the `draw_end`/deque enforcement, and HTTP/cookie identity. `recordMove` only
  guards turn ownership and end-of-game.
- **Type consistency:** `castGame` decodes `turn_order` to an array, so `nextSeat` consumes it
  as an array (not JSON). `z` is 1-based here and the Phase 1 engine/Phase 3 reconstruction
  treats the neutral starting card as `z=0`, so there is no collision. Colors come from
  `TactaGameRepository::COLORS`, matching the spec's six.
- **Note on shuffle:** `shuffle()`/`random_int()` are fine for game-deal randomness here (not
  security-sensitive); `guest_token` uses `random_bytes` (security-sensitive).
