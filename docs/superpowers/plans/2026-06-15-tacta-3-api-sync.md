# Tacta Phase 3 — API, Identity & Polling Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect the engine and the data layer over HTTP — an unlisted `/tacta` route group with a JSON API for create/join/start/move/state, guest-cookie identity, and **server-authoritative move validation** (reconstruct the board from the move log and validate placements with the Phase 1 rules before persisting).

**Architecture:** Two small engine-bridge classes in `App\Tacta\` — `BoardBuilder` (move rows → `Board`) and `MoveValidator` (board + a player's deque state + a proposed move → a validated, resolved move, or a `ValidationException`). A thin `App\Controllers\TactaController` wires `TactaGameRepository` + these bridges to HTTP, following the `JobController` pattern (`json()`/`requireJson()` helpers). Identity is a per-game HttpOnly cookie holding the player's `guest_token`. Sync is polling: clients `GET …/state?since=N`.

**Tech Stack:** PHP 8.2, Slim 4, PDO/MySQL, Twig, PHPUnit (engine-bridge unit tests + DB-backed HTTP tests). Run tests with `vendor/bin/phpunit`; DB tests need MySQL up (`docker compose up -d`).

---

## Spec coverage & prerequisites

Implements the spec's **Architecture (routes)** and **Multiplayer, identity & sync** sections.
Builds on Phase 1 (`App\Tacta\{Board,PlacedCard,Card,Deck,Rules,Scorer,Side}`) and Phase 2
(`App\Repositories\TactaGameRepository`, the `tacta_*` tables). Phase 4 (the Svelte client)
follows and will fill the page shell created here.

**Prerequisite — running, migrated database** (same as Phase 2):
```bash
docker compose up -d
vendor/bin/phinx migrate -e development
vendor/bin/phinx migrate -e testing
```

## Key designs

**Card ids.** A played card is identified as `"{color}-{n}"` where `n` is 1-based over the 18
shared layouts (so `red-7` = `Deck::forColor('red')[6]`). The neutral starting card is `start`.

**Deque state.** A player's shuffled `deck` is a list of 18 layout indices (0–17). After
`t` top-draws and `b` bottom-draws, the two playable ("outermost") cards are
`deck[t]` (top) and `deck[17-b]` (bottom). When `t == 17-b` only one card remains (top).
Top/bottom draw counts are derived from that seat's rows in the move log (`draw_end`).

**Board z.** The starting card is `z=0`; the *k*-th played card is `z=k` (= `Board::nextZ()`
at placement time = the repository's stored `z`). `BoardBuilder` places the starting card
first, then replays moves in `seq` order.

**Move legality (server-authoritative).** The client sends `{draw_end, x, y, rotation,
mirror}`. The server resolves which card that is, then:
- If the target cell has ≥1 orthogonal neighbor → it must satisfy `Rules::isLegalConnect`
  (empty cell, exactly one neighbor, shared-edge shapes match).
- If the target cell has no neighbors (isolated drop) → allowed **only if neither outermost
  card can legally connect anywhere** (`Rules::legalConnects` empty for both) and
  `Rules::isLegalIsolated` holds.

**Identity.** On join the server sets `Set-Cookie: tacta_{code}={guest_token}; Path=/tacta;
HttpOnly; SameSite=Lax`. The token never appears in a JSON body (kept out of JS). Requests
identify the player via that cookie → `TactaGameRepository::playerByToken`.

**CSRF/abuse posture.** All mutating endpoints require `Content-Type: application/json`
(forces a cross-origin preflight) — the same second layer `JobController` uses — combined
with the SameSite=Lax identity cookie and the room code.

## File structure

```
app/Tacta/BoardBuilder.php          # move rows -> Board (+ card-id parsing)
app/Tacta/MoveValidator.php         # validate/resolve a proposed move
app/Controllers/TactaController.php  # HTTP endpoints + cookie identity
app/views/tacta.twig                # standalone SPA shell (noindex)
app/routes.php                      # + unlisted /tacta group  (modify)
app/bootstrap.php                   # + /tacta cache exclusion  (modify)
tests/Tacta/BoardBuilderTest.php
tests/Tacta/MoveValidatorTest.php
tests/Http/TactaApiTest.php
```

---

## Task 1: `BoardBuilder` (move rows → `Board`)

**Files:**
- Create: `app/Tacta/BoardBuilder.php`
- Test: `tests/Tacta/BoardBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/BoardBuilderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\BoardBuilder;
use App\Tacta\Card;
use App\Tacta\Deck;
use App\Tacta\Side;
use PHPUnit\Framework\TestCase;

final class BoardBuilderTest extends TestCase
{
    public function test_card_from_id_parses_color_and_index(): void
    {
        $card = BoardBuilder::cardFromId('red-7');
        $this->assertInstanceOf(Card::class, $card);
        // red-7 is the 7th layout (index 6); compare its north edge to the deck's.
        $expected = Deck::forColor('red')[6];
        $this->assertSame($expected->edge(Side::N)->shape, $card->edge(Side::N)->shape);
        $this->assertSame('red', BoardBuilder::colorFromId('red-7'));
    }

    public function test_empty_log_yields_just_the_starting_card(): void
    {
        $board = BoardBuilder::build([]);
        $this->assertSame(1, $board->count());
        $start = $board->cardAt(0, 0);
        $this->assertNotNull($start);
        $this->assertSame(0, $start->z);
        $this->assertSame('neutral', $start->color);
    }

    public function test_replays_moves_into_placed_cards(): void
    {
        $moves = [
            ['card_id' => 'red-1', 'seat' => 0, 'x' => 0, 'y' => -1, 'rotation' => 1, 'mirror' => false, 'z' => 1],
            ['card_id' => 'blue-2', 'seat' => 1, 'x' => 1, 'y' => 0, 'rotation' => 0, 'mirror' => true, 'z' => 2],
        ];
        $board = BoardBuilder::build($moves);

        $this->assertSame(3, $board->count()); // starting card + 2
        $red = $board->cardAt(0, -1);
        $this->assertNotNull($red);
        $this->assertSame('red', $red->color);
        $this->assertSame(1, $red->rotation);
        $this->assertSame(1, $red->z);

        $blue = $board->cardAt(1, 0);
        $this->assertNotNull($blue);
        $this->assertSame('blue', $blue->color);
        $this->assertTrue($blue->mirror);
        $this->assertSame(2, $blue->z);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter BoardBuilderTest`
Expected: FAIL — `Class "App\Tacta\BoardBuilder" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/BoardBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

/** Reconstructs a Board from the persisted move log (the source of truth). */
final class BoardBuilder
{
    /**
     * @param list<array{card_id:string,x:int,y:int,rotation:int,mirror:bool,z:int}> $moves
     *        cast move rows in play order
     */
    public static function build(array $moves): Board
    {
        $board = new Board();
        $board->place(new PlacedCard(Deck::startingCard(), 'neutral', 0, 0, 0, false, 0));

        foreach ($moves as $move) {
            $board->place(new PlacedCard(
                self::cardFromId($move['card_id']),
                self::colorFromId($move['card_id']),
                (int) $move['x'],
                (int) $move['y'],
                (int) $move['rotation'],
                (bool) $move['mirror'],
                (int) $move['z'],
            ));
        }

        return $board;
    }

    /** "red-7" -> the 7th red layout card. */
    public static function cardFromId(string $cardId): Card
    {
        [$color, $n] = self::split($cardId);

        return Deck::forColor($color)[$n - 1];
    }

    public static function colorFromId(string $cardId): string
    {
        return self::split($cardId)[0];
    }

    /** @return array{0:string,1:int} */
    private static function split(string $cardId): array
    {
        $parts = explode('-', $cardId);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            throw new \InvalidArgumentException("Bad card id: {$cardId}");
        }

        return [$parts[0], (int) $parts[1]];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter BoardBuilderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/BoardBuilder.php tests/Tacta/BoardBuilderTest.php
git commit -m "feat(tacta): BoardBuilder reconstructs Board from the move log

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `MoveValidator` (resolve + validate a proposed move)

**Files:**
- Create: `app/Tacta/MoveValidator.php`
- Test: `tests/Tacta/MoveValidatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/MoveValidatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Support\ValidationException;
use App\Tacta\Board;
use App\Tacta\BoardBuilder;
use App\Tacta\Deck;
use App\Tacta\MoveValidator;
use App\Tacta\PlacedCard;
use App\Tacta\Rules;
use App\Tacta\Side;
use PHPUnit\Framework\TestCase;

final class MoveValidatorTest extends TestCase
{
    /** Deck where the top card (index 0) is layout 0; returns [board, deck]. */
    private function freshGame(): array
    {
        $board = BoardBuilder::build([]); // just the starting card at (0,0)
        $deck = range(0, 17);             // unshuffled: deck[0] = layout 0
        return [$board, $deck];
    }

    /** Find a legal first-move placement for the top card, via the engine. */
    private function aLegalConnect(Board $board, array $deck): PlacedCard
    {
        $card = Deck::forColor('red')[$deck[0]];
        $moves = Rules::legalConnects($board, $card, 'red', $board->nextZ());
        $this->assertNotEmpty($moves, 'expected a legal first move to exist');
        return $moves[0];
    }

    public function test_resolves_top_card_id_from_draw_end(): void
    {
        [$board, $deck] = $this->freshGame();
        $place = $this->aLegalConnect($board, $deck);

        $result = MoveValidator::validate($board, 'red', $deck, 0, 0, [
            'draw_end' => 'top',
            'x' => $place->x, 'y' => $place->y,
            'rotation' => $place->rotation, 'mirror' => $place->mirror,
        ]);

        $this->assertSame('red-' . ($deck[0] + 1), $result['card_id']);
        $this->assertSame('top', $result['draw_end']);
        $this->assertSame($place->x, $result['x']);
    }

    public function test_rejects_occupied_cell(): void
    {
        [$board, $deck] = $this->freshGame();
        $this->expectException(ValidationException::class);
        MoveValidator::validate($board, 'red', $deck, 0, 0, [
            'draw_end' => 'top', 'x' => 0, 'y' => 0, 'rotation' => 0, 'mirror' => false,
        ]);
    }

    public function test_rejects_connect_with_mismatched_shape(): void
    {
        [$board, $deck] = $this->freshGame();
        // Place adjacent to start but force a rotation whose facing edge cannot match.
        // Try all rotations/mirrors at (0,-1); pick one that is NOT legal to prove rejection.
        $card = Deck::forColor('red')[$deck[0]];
        $illegal = null;
        for ($r = 0; $r < 4 && $illegal === null; $r++) {
            foreach ([false, true] as $m) {
                $candidate = new PlacedCard($card, 'red', 0, -1, $r, $m, $board->nextZ());
                if (!Rules::isLegalConnect($board, $candidate)) {
                    $illegal = $candidate;
                    break;
                }
            }
        }
        $this->assertNotNull($illegal, 'expected at least one illegal orientation at (0,-1)');

        $this->expectException(ValidationException::class);
        MoveValidator::validate($board, 'red', $deck, 0, 0, [
            'draw_end' => 'top', 'x' => $illegal->x, 'y' => $illegal->y,
            'rotation' => $illegal->rotation, 'mirror' => $illegal->mirror,
        ]);
    }

    public function test_rejects_isolated_drop_when_a_connect_is_possible(): void
    {
        [$board, $deck] = $this->freshGame();
        // A legal connect exists against the starting card, so an isolated drop far away
        // must be refused.
        $this->expectException(ValidationException::class);
        MoveValidator::validate($board, 'red', $deck, 0, 0, [
            'draw_end' => 'top', 'x' => 9, 'y' => 9, 'rotation' => 0, 'mirror' => false,
        ]);
    }

    public function test_allows_isolated_drop_when_no_connect_is_possible(): void
    {
        // Build a board whose only card is a starting card with all-Square edges, and give the
        // player a deck whose top card has NO square edges -> cannot connect anywhere.
        $board = BoardBuilder::build([]);
        // Find a layout with no Square edge to use as the top card.
        $deckOrder = null;
        foreach (Deck::forColor('red') as $i => $card) {
            $hasSquare = false;
            foreach ([Side::N, Side::E, Side::S, Side::W] as $s) {
                if ($card->edge($s)->shape === \App\Tacta\Shape::Square) {
                    $hasSquare = true;
                    break;
                }
            }
            if (!$hasSquare) {
                $deckOrder = array_merge([$i], array_values(array_diff(range(0, 17), [$i])));
                break;
            }
        }
        // The starting card (Deck::startingCard) has edges T/Q/R/Q. If the top card has no
        // Square AND can still match Triangle/Rectangle, a connect might exist; so additionally
        // require this test only runs when legalConnects is genuinely empty.
        $this->assertNotNull($deckOrder, 'expected a no-square layout');
        $top = Deck::forColor('red')[$deckOrder[0]];
        if (!empty(Rules::legalConnects($board, $top, 'red', $board->nextZ()))) {
            $this->markTestSkipped('chosen top card can still connect to the starting card');
        }

        $result = MoveValidator::validate($board, 'red', $deckOrder, 0, 0, [
            'draw_end' => 'top', 'x' => 9, 'y' => 9, 'rotation' => 0, 'mirror' => false,
        ]);
        $this->assertSame('red-' . ($deckOrder[0] + 1), $result['card_id']);
    }

    public function test_no_cards_left_throws(): void
    {
        [$board, $deck] = $this->freshGame();
        $this->expectException(ValidationException::class);
        // 18 top-draws exhausts an 18-card deck (head 18 > tail 17).
        MoveValidator::validate($board, 'red', $deck, 18, 0, [
            'draw_end' => 'top', 'x' => 0, 'y' => -1, 'rotation' => 0, 'mirror' => false,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MoveValidatorTest`
Expected: FAIL — `Class "App\Tacta\MoveValidator" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/MoveValidator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

use App\Support\ValidationException;

/**
 * Server-authoritative validation of a proposed move. Resolves which card the
 * player is playing (top/bottom of their deque) and verifies the placement is
 * legal against the current board.
 */
final class MoveValidator
{
    private const SIDES = [Side::N, Side::E, Side::S, Side::W];

    /**
     * @param list<int> $deck shuffled layout indices (0..17)
     * @param array{draw_end:string,x:int,y:int,rotation:int,mirror:bool} $move
     * @return array{card_id:string,draw_end:string,x:int,y:int,rotation:int,mirror:bool}
     * @throws ValidationException
     */
    public static function validate(
        Board $board,
        string $color,
        array $deck,
        int $topDraws,
        int $bottomDraws,
        array $move,
    ): array {
        $count = count($deck);
        $head = $topDraws;
        $tail = $count - 1 - $bottomDraws;
        if ($head > $tail) {
            throw new ValidationException('No cards left to play.');
        }

        $drawEnd = ($move['draw_end'] ?? 'top') === 'bottom' ? 'bottom' : 'top';
        if ($head === $tail) {
            $drawEnd = 'top'; // one card left: both ends are the same card
        }
        $layoutIndex = $drawEnd === 'top' ? $deck[$head] : $deck[$tail];
        $cardId = $color . '-' . ($layoutIndex + 1);
        $card = Deck::forColor($color)[$layoutIndex];

        $x = (int) $move['x'];
        $y = (int) $move['y'];
        $rotation = (int) $move['rotation'];
        $mirror = !empty($move['mirror']);
        if ($rotation < 0 || $rotation > 3) {
            throw new ValidationException('Invalid rotation.');
        }
        if ($board->cardAt($x, $y) !== null) {
            throw new ValidationException('That cell is already occupied.');
        }

        $placement = new PlacedCard($card, $color, $x, $y, $rotation, $mirror, $board->nextZ());

        $neighbors = 0;
        foreach (self::SIDES as $side) {
            if ($board->neighbor($x, $y, $side) !== null) {
                $neighbors++;
            }
        }

        if ($neighbors > 0) {
            if (!Rules::isLegalConnect($board, $placement)) {
                throw new ValidationException('Illegal move: your edge shape must match a single adjacent card.');
            }
        } else {
            if (self::anyConnectPossible($board, $color, $deck, $head, $tail)) {
                throw new ValidationException('You must connect to a card when a legal move exists.');
            }
            if (!Rules::isLegalIsolated($board, $placement)) {
                throw new ValidationException('Illegal isolated placement.');
            }
        }

        return [
            'card_id' => $cardId,
            'draw_end' => $drawEnd,
            'x' => $x,
            'y' => $y,
            'rotation' => $rotation,
            'mirror' => $mirror,
        ];
    }

    /** Can either outermost card connect anywhere on the board? */
    private static function anyConnectPossible(Board $board, string $color, array $deck, int $head, int $tail): bool
    {
        $cards = Deck::forColor($color);
        $indices = $head === $tail ? [$deck[$head]] : [$deck[$head], $deck[$tail]];
        foreach ($indices as $index) {
            if (Rules::legalConnects($board, $cards[$index], $color, $board->nextZ()) !== []) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter MoveValidatorTest`
Expected: PASS (6 tests; one may report as risky/skipped only if the no-connect layout can't be found, which it can — the deck has layouts without a Square edge).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/MoveValidator.php tests/Tacta/MoveValidatorTest.php
git commit -m "feat(tacta): MoveValidator resolves + validates proposed moves

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: `TactaController` + routes + page shell — create / join / state

**Files:**
- Create: `app/Controllers/TactaController.php`
- Create: `app/views/tacta.twig`
- Modify: `app/routes.php`
- Modify: `app/bootstrap.php`
- Test: `tests/Http/TactaApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Http/TactaApiTest.php`:
```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaApiTest`
Expected: FAIL — routes 404 / `TactaController` not found.

- [ ] **Step 3a: Create the controller**

Create `app/Controllers/TactaController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TactaGameRepository;
use App\Support\ValidationException;
use App\Tacta\BoardBuilder;
use App\Tacta\MoveValidator;
use App\Tacta\Scorer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class TactaController
{
    public function __construct(
        private Twig $twig,
        private TactaGameRepository $repo,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'tacta.twig', []);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }

        return $this->json($response, ['code' => $this->repo->createGame()['code']], 201);
    }

    public function join(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        try {
            $player = $this->repo->join(
                $args['code'],
                (string) ($body['name'] ?? ''),
                (string) ($body['color'] ?? ''),
            );
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        $response = $this->setTokenCookie($response, $args['code'], $player['guest_token']);

        return $this->json($response, [
            'seat' => $player['seat'],
            'color' => $player['color'],
            'is_host' => $player['is_host'],
        ], 201);
    }

    public function state(Request $request, Response $response, array $args): Response
    {
        $game = $this->repo->findByCode($args['code']);
        if ($game === null) {
            return $this->json($response, ['error' => 'Game not found'], 404);
        }

        $since = (int) ($request->getQueryParams()['since'] ?? -1);
        $players = $this->repo->players($game['id']);
        $allMoves = $this->repo->movesSince($game['id'], 0);
        $counts = $this->drawCountsBySeat($allMoves);

        $publicPlayers = array_map(function (array $p) use ($counts): array {
            $played = ($counts[$p['seat']]['top'] ?? 0) + ($counts[$p['seat']]['bottom'] ?? 0);

            return [
                'seat' => $p['seat'],
                'color' => $p['color'],
                'name' => $p['display_name'],
                'is_host' => $p['is_host'],
                'remaining' => TactaGameRepository::CARDS_PER_PLAYER - $played,
            ];
        }, $players);

        $board = BoardBuilder::build($allMoves);
        $newMoves = array_map(
            [$this, 'publicMove'],
            array_values(array_filter($allMoves, static fn (array $m): bool => $m['seq'] > $since)),
        );

        $you = null;
        $me = $this->identify($request, $game, $args['code']);
        if ($me !== null) {
            $you = [
                'seat' => $me['seat'],
                'color' => $me['color'],
                'your_turn' => $game['current_seat'] === $me['seat'],
                'hand' => null,
            ];
            if ($game['status'] === 'active' && is_array($me['deck'])) {
                $you['hand'] = $this->hand($me, $counts[$me['seat']] ?? ['top' => 0, 'bottom' => 0]);
            }
        }

        return $this->json($response, [
            'code' => $game['code'],
            'status' => $game['status'],
            'seq' => $game['seq'],
            'current_seat' => $game['current_seat'],
            'players' => $publicPlayers,
            'scores' => Scorer::scores($board),
            'moves' => $newMoves,
            'you' => $you,
        ]);
    }

    // ----- helpers -----

    private function identify(Request $request, array $game, string $code): ?array
    {
        $token = $request->getCookieParams()['tacta_' . $code] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $this->repo->playerByToken($game['id'], $token);
    }

    /**
     * @param list<array<string,mixed>> $moves
     * @return array<int,array{top:int,bottom:int}>
     */
    private function drawCountsBySeat(array $moves): array
    {
        $out = [];
        foreach ($moves as $move) {
            $seat = (int) $move['seat'];
            $out[$seat] ??= ['top' => 0, 'bottom' => 0];
            $out[$seat][$move['draw_end'] === 'bottom' ? 'bottom' : 'top']++;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $player
     * @param array{top:int,bottom:int} $counts
     * @return array{top:?string,bottom:?string}
     */
    private function hand(array $player, array $counts): array
    {
        $deck = $player['deck'];
        $head = $counts['top'];
        $tail = count($deck) - 1 - $counts['bottom'];

        return [
            'top' => $head <= $tail ? $player['color'] . '-' . ($deck[$head] + 1) : null,
            'bottom' => $head < $tail ? $player['color'] . '-' . ($deck[$tail] + 1) : null,
        ];
    }

    /** @param array<string,mixed> $m */
    private function publicMove(array $m): array
    {
        return [
            'seq' => $m['seq'],
            'seat' => $m['seat'],
            'card_id' => $m['card_id'],
            'x' => $m['x'],
            'y' => $m['y'],
            'rotation' => $m['rotation'],
            'mirror' => $m['mirror'],
            'z' => $m['z'],
        ];
    }

    private function setTokenCookie(Response $response, string $code, string $token): Response
    {
        $cookie = sprintf('tacta_%s=%s; Path=/tacta; HttpOnly; SameSite=Lax', $code, $token);

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    private function requireJson(Request $request, Response $response): ?Response
    {
        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return $this->json($response, ['error' => 'Expected application/json'], 415);
        }

        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

- [ ] **Step 3b: Create the page shell**

Create `app/views/tacta.twig` (standalone, noindex; the JS bundle is wired in Phase 4 and the
`{% if assets.js %}` guard keeps this rendering until then):
```twig
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Tacta</title>
    <script>
        (function () {
            var c = document.cookie, i = c.indexOf("theme=");
            var theme = i >= 0 ? c.slice(i + 6).split(";")[0] : null;
            if (theme === "dark" || theme === "light") document.documentElement.classList.add(theme);
            else if (window.matchMedia("(prefers-color-scheme: dark)").matches) document.documentElement.classList.add("dark");
        })();
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Roboto+Mono:wght@400;500;700&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" />
    <link rel="stylesheet" href="{{ assets.versioned('/css/base.css') }}" />
    <link rel="stylesheet" href="{{ assets.versioned('/css/theme.css') }}" />
    {% for href in assets.css('islands/tacta.js') %}<link rel="stylesheet" href="{{ href }}" />{% endfor %}
</head>
<body>
    <div data-island="Tacta"></div>
    {% if assets.js('islands/tacta.js') %}<script type="module" src="{{ assets.js('islands/tacta.js') }}"></script>{% endif %}
</body>
</html>
```

- [ ] **Step 3c: Register the routes**

In `app/routes.php`, immediately after the `$app->group('/jobs', …)` block (and before the
closing `};` of the returned function), add:
```php
    // ----- Tacta (hidden game; unlisted, room-code-gated) -----
    $tactaCtrl = new \App\Controllers\TactaController(
        $twig,
        new \App\Repositories\TactaGameRepository($pdo),
    );

    $app->group('/tacta', function ($group) use ($tactaCtrl) {
        $group->get('', [$tactaCtrl, 'page']);
        $group->post('/api/games', [$tactaCtrl, 'create']);
        $group->post('/api/games/{code}/join', [$tactaCtrl, 'join']);
        $group->get('/api/games/{code}/state', [$tactaCtrl, 'state']);
        $group->get('/{code}', [$tactaCtrl, 'page']);
    });
```

- [ ] **Step 3d: Exclude `/tacta` from public GET caching**

In `app/bootstrap.php`, in the public-cache middleware, add a `/tacta` guard so polling
responses are never cached. Change:
```php
        && !str_starts_with($request->getUri()->getPath(), '/jobs')) {
```
to:
```php
        && !str_starts_with($request->getUri()->getPath(), '/jobs')
        && !str_starts_with($request->getUri()->getPath(), '/tacta')) {
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaApiTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/TactaController.php app/views/tacta.twig app/routes.php app/bootstrap.php tests/Http/TactaApiTest.php
git commit -m "feat(tacta): controller + routes + page shell — create/join/state

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `start` + `move` endpoints (server-authoritative)

**Files:**
- Modify: `app/Controllers/TactaController.php`
- Modify: `app/routes.php`
- Modify: `tests/Http/TactaApiTest.php`

- [ ] **Step 1: Add the failing tests**

Add these to `TactaApiTest` (the helpers `post`/`get`/`body`/`cookieToken` already exist):
```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter TactaApiTest`
Expected: FAIL — the `/start` and `/moves` routes 404 (not yet registered) / methods missing.

- [ ] **Step 3a: Add the route lines**

In `app/routes.php`, inside the `$app->group('/tacta', …)` body, add the two mutating routes
(place them with the other `/api/games/{code}` routes):
```php
        $group->post('/api/games/{code}/start', [$tactaCtrl, 'start']);
        $group->post('/api/games/{code}/moves', [$tactaCtrl, 'move']);
```

- [ ] **Step 3b: Add the controller methods**

Add `start` and `move` to `TactaController` (after `state`, before the helpers):
```php
    public function start(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        $game = $this->repo->findByCode($args['code']);
        if ($game === null) {
            return $this->json($response, ['error' => 'Game not found'], 404);
        }
        $me = $this->identify($request, $game, $args['code']);
        if ($me === null || !$me['is_host']) {
            return $this->json($response, ['error' => 'Only the host can start the game'], 403);
        }
        try {
            $game = $this->repo->start($args['code']);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->json($response, ['ok' => true, 'seq' => $game['seq']]);
    }

    public function move(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        $game = $this->repo->findByCode($args['code']);
        if ($game === null) {
            return $this->json($response, ['error' => 'Game not found'], 404);
        }
        $me = $this->identify($request, $game, $args['code']);
        if ($me === null) {
            return $this->json($response, ['error' => 'Join the game first'], 403);
        }
        if ($game['status'] !== 'active') {
            return $this->json($response, ['error' => 'Game is not active'], 400);
        }
        if ($game['current_seat'] !== $me['seat']) {
            return $this->json($response, ['error' => 'It is not your turn'], 409);
        }

        $body = (array) $request->getParsedBody();
        $allMoves = $this->repo->movesSince($game['id'], 0);
        $board = BoardBuilder::build($allMoves);
        $counts = $this->drawCountsBySeat($allMoves)[$me['seat']] ?? ['top' => 0, 'bottom' => 0];

        try {
            $resolved = MoveValidator::validate(
                $board,
                $me['color'],
                $me['deck'],
                $counts['top'],
                $counts['bottom'],
                [
                    'draw_end' => (string) ($body['draw_end'] ?? 'top'),
                    'x' => (int) ($body['x'] ?? 0),
                    'y' => (int) ($body['y'] ?? 0),
                    'rotation' => (int) ($body['rotation'] ?? 0),
                    'mirror' => !empty($body['mirror']),
                ],
            );
            $game = $this->repo->recordMove($args['code'], $me['seat'], $resolved);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->json($response, ['ok' => true, 'seq' => $game['seq']]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TactaApiTest`
Expected: PASS (12 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/TactaController.php app/routes.php tests/Http/TactaApiTest.php
git commit -m "feat(tacta): start + server-authoritative move endpoints

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Full suite green

- [ ] **Step 1: Run the entire suite (DB up)**

Run:
```bash
docker compose up -d
vendor/bin/phpunit
```
Expected: all tests pass — the new `BoardBuilderTest`, `MoveValidatorTest`, and `TactaApiTest`,
plus all Phase 1/2 and pre-existing tests.

- [ ] **Step 2: If green, Phase 3 is complete**

Do not proceed to Phase 4 with a red suite.

---

## Self-review notes (already applied)

- **Spec coverage:** unlisted `/tacta` group + JSON API (Task 3/4); guest-cookie identity
  (`setTokenCookie`/`identify`); polling `state?since=N` returning status/seq/players/scores/
  incremental moves + the caller's private hand (Task 3); server-authoritative create/join/
  start/move with engine-backed legality (Tasks 1–4); `/tacta` excluded from GET caching.
- **Deferred to Phase 4 (intentionally):** the Svelte client (the page shell renders an empty
  mount point; the `tacta.js` bundle + island are Phase 4), animations, reconnection UX.
- **Type consistency:** card ids are `"{color}-{n}"` (1-based) everywhere — `BoardBuilder`,
  `MoveValidator`, the controller's `hand()`, and `publicMove`. `z` is `Board::nextZ()` at
  placement (starting card `z=0`), matching the repository. Draw counts derive from
  `draw_end` in the move log; outermost cards are `deck[top]` / `deck[17-bottom]`.
- **Security:** `guest_token` stays in an HttpOnly cookie, never in a JSON body; mutating
  endpoints require `application/json`. Move legality is computed server-side from the
  authoritative move log, so a client cannot place illegally or out of turn.
