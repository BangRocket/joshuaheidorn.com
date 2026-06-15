# Tacta Phase 1 — Rules Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the pure-PHP Tacta rules engine — cards, deck, board, placement legality, and dot scoring — fully unit-tested, with no database or HTTP dependencies.

**Architecture:** A small set of immutable value objects (`Shape`, `Side`, `Suit`, `EdgeShape`, `Card`, `PlacedCard`) plus three stateless/lightweight services (`Board`, `Rules`, `Scorer`) under the `App\Tacta\` namespace. The engine implements the approved **edge-adjacency + tab-cover** model: cards occupy single grid cells, a move connects to exactly one neighbor whose shared-edge shape matches, and a later-placed card covers (hides the dots of) the neighbor's tab on that edge.

**Tech Stack:** PHP 8.2 (enums, readonly properties, `match`), PHPUnit 11/12. PSR-4 `App\` → `app/`, `Tests\` → `tests/`. Run tests with `vendor/bin/phpunit`.

---

## Spec coverage & phase roadmap

This plan implements the **"The rules engine (concrete model)"** section of
`docs/superpowers/specs/2026-06-15-tacta-game-design.md`. The remaining spec sections are
covered by later phase plans (written after this one so they reference real types):

| Phase | Plan file | Spec sections |
| --- | --- | --- |
| **1 (this)** | `2026-06-15-tacta-1-rules-engine.md` | The rules engine |
| 2 | `2026-06-15-tacta-2-data-layer.md` | Data model |
| 3 | `2026-06-15-tacta-3-api-sync.md` | Architecture (routes), Multiplayer/identity/sync |
| 4 | `2026-06-15-tacta-4-client.md` | Client (Svelte island) |

## File structure (created by this plan)

```
app/Tacta/
  Shape.php        # enum: edge-shape types (triangle, square, rectangle)
  Suit.php         # enum: center suit (circle, square, triangle) — variants only
  Side.php         # enum N/E/S/W with grid offset() and opposite()
  EdgeShape.php    # value object: a shape on one edge + its dot count (0 = hollow)
  Card.php         # immutable card definition: 4 edges + suit; value() = sum of dots
  PlacedCard.php   # a card placed at (x,y) with rotation+mirror+z; edgeAt(worldSide)
  Board.php        # cell map of placed cards; place(), cardAt(), neighbor(), nextZ()
  Rules.php        # placement legality + legal-move enumeration
  Scorer.php       # visible-dot totals by color
  Deck.php         # the 18-card layout set + forColor()
tests/Tacta/
  SideTest.php
  CardTest.php
  PlacedCardTest.php
  BoardTest.php
  RulesTest.php
  ScorerTest.php
  DeckTest.php
```

Each card carries one **edge-shape per side** (N/E/S/W). `value()` (the printed center number)
always equals the **sum of the card's edge dots**. The orientation model: a card may be
**rotated** 0–3 clockwise quarter-turns and **mirrored** (back face = horizontal mirror,
swapping the E and W edges). `PlacedCard::edgeAt(Side $world)` resolves which edge shows on a
given world-facing side after mirror + rotation.

---

## Task 0: Create the feature branch

- [ ] **Step 1: Branch off main**

Run:
```bash
git checkout -b tacta-game
```
Expected: `Switched to a new branch 'tacta-game'`

---

## Task 1: `Side` enum (grid geometry)

**Files:**
- Create: `app/Tacta/Side.php`
- Test: `tests/Tacta/SideTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/SideTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Side;
use PHPUnit\Framework\TestCase;

final class SideTest extends TestCase
{
    public function test_offsets_use_screen_coordinates_y_down(): void
    {
        $this->assertSame([0, -1], Side::N->offset());
        $this->assertSame([1, 0], Side::E->offset());
        $this->assertSame([0, 1], Side::S->offset());
        $this->assertSame([-1, 0], Side::W->offset());
    }

    public function test_opposite(): void
    {
        $this->assertSame(Side::S, Side::N->opposite());
        $this->assertSame(Side::W, Side::E->opposite());
        $this->assertSame(Side::N, Side::S->opposite());
        $this->assertSame(Side::E, Side::W->opposite());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SideTest`
Expected: FAIL — `Error: Class "App\Tacta\Side" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/Side.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

enum Side: int
{
    case N = 0;
    case E = 1;
    case S = 2;
    case W = 3;

    /**
     * Grid offset [dx, dy] for this side, in screen coordinates (y grows downward).
     *
     * @return array{int, int}
     */
    public function offset(): array
    {
        return match ($this) {
            self::N => [0, -1],
            self::E => [1, 0],
            self::S => [0, 1],
            self::W => [-1, 0],
        };
    }

    public function opposite(): self
    {
        return self::from(($this->value + 2) % 4);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SideTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/Side.php tests/Tacta/SideTest.php
git commit -m "feat(tacta): Side enum with grid offsets and opposites"
```

---

## Task 2: `Shape`, `Suit`, `EdgeShape`, `Card`

**Files:**
- Create: `app/Tacta/Shape.php`, `app/Tacta/Suit.php`, `app/Tacta/EdgeShape.php`, `app/Tacta/Card.php`
- Test: `tests/Tacta/CardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/CardTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Card;
use App\Tacta\EdgeShape;
use App\Tacta\Shape;
use App\Tacta\Side;
use App\Tacta\Suit;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    private function card(): Card
    {
        return new Card('test-1', [
            Side::N->value => new EdgeShape(Shape::Triangle, 2),
            Side::E->value => new EdgeShape(Shape::Square, 0),
            Side::S->value => new EdgeShape(Shape::Rectangle, 1),
            Side::W->value => new EdgeShape(Shape::Triangle, 0),
        ], Suit::Circle);
    }

    public function test_edge_returns_shape_for_side(): void
    {
        $card = $this->card();
        $this->assertSame(Shape::Triangle, $card->edge(Side::N)->shape);
        $this->assertSame(0, $card->edge(Side::E)->dots);
    }

    public function test_value_is_sum_of_edge_dots(): void
    {
        $this->assertSame(3, $this->card()->value());
    }

    public function test_hollow_edge_has_zero_dots(): void
    {
        $this->assertTrue((new EdgeShape(Shape::Square, 0))->isHollow());
        $this->assertFalse((new EdgeShape(Shape::Square, 1))->isHollow());
    }

    public function test_edge_dots_out_of_range_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EdgeShape(Shape::Square, 4);
    }

    public function test_card_missing_edge_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Card('bad', [
            Side::N->value => new EdgeShape(Shape::Triangle, 1),
            Side::E->value => new EdgeShape(Shape::Square, 0),
            // S and W missing
        ], Suit::Square);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CardTest`
Expected: FAIL — `Class "App\Tacta\Card" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/Shape.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

enum Shape: string
{
    case Triangle = 'triangle';
    case Square = 'square';
    case Rectangle = 'rectangle';
}
```

Create `app/Tacta/Suit.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

// Center suit. Used only by alternative play modes (deferred); stored for completeness.
enum Suit: string
{
    case Circle = 'circle';
    case Square = 'square';
    case Triangle = 'triangle';
}
```

Create `app/Tacta/EdgeShape.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class EdgeShape
{
    public function __construct(
        public readonly Shape $shape,
        public readonly int $dots, // 0 = hollow; 1..3 = filled
    ) {
        if ($dots < 0 || $dots > 3) {
            throw new \InvalidArgumentException("Edge dots out of range: {$dots}");
        }
    }

    public function isHollow(): bool
    {
        return $this->dots === 0;
    }
}
```

Create `app/Tacta/Card.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class Card
{
    /** @param array<int, EdgeShape> $edges keyed by Side->value (0=N,1=E,2=S,3=W) */
    public function __construct(
        public readonly string $id,
        public readonly array $edges,
        public readonly Suit $suit,
    ) {
        foreach ([Side::N, Side::E, Side::S, Side::W] as $side) {
            $edge = $edges[$side->value] ?? null;
            if (!$edge instanceof EdgeShape) {
                throw new \InvalidArgumentException("Card {$id} missing edge {$side->name}");
            }
        }
    }

    public function edge(Side $side): EdgeShape
    {
        return $this->edges[$side->value];
    }

    /** Center value = total dots across all four edges. */
    public function value(): int
    {
        return array_sum(array_map(static fn (EdgeShape $e): int => $e->dots, $this->edges));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CardTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/Shape.php app/Tacta/Suit.php app/Tacta/EdgeShape.php app/Tacta/Card.php tests/Tacta/CardTest.php
git commit -m "feat(tacta): Shape/Suit/EdgeShape/Card value objects"
```

---

## Task 3: `PlacedCard` (rotation + mirror resolution)

**Files:**
- Create: `app/Tacta/PlacedCard.php`
- Test: `tests/Tacta/PlacedCardTest.php`

The key behavior: `edgeAt(Side $world)` returns the edge that shows on a world-facing side
after applying the card's mirror (back face = swap E/W) and clockwise rotation. Clockwise
rotation moves the canonical N edge onto world E at rotation 1.

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/PlacedCardTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Card;
use App\Tacta\EdgeShape;
use App\Tacta\PlacedCard;
use App\Tacta\Shape;
use App\Tacta\Side;
use App\Tacta\Suit;
use PHPUnit\Framework\TestCase;

final class PlacedCardTest extends TestCase
{
    private function card(): Card
    {
        // N=Triangle, E=Square, S=Rectangle, W=Triangle(blank) — distinct enough to track.
        return new Card('c', [
            Side::N->value => new EdgeShape(Shape::Triangle, 1),
            Side::E->value => new EdgeShape(Shape::Square, 1),
            Side::S->value => new EdgeShape(Shape::Rectangle, 1),
            Side::W->value => new EdgeShape(Shape::Triangle, 0),
        ], Suit::Circle);
    }

    public function test_no_rotation_no_mirror_is_identity(): void
    {
        $p = new PlacedCard($this->card(), 'red', 0, 0, 0, false, 0);
        $this->assertSame(Shape::Triangle, $p->edgeAt(Side::N)->shape);
        $this->assertSame(Shape::Square, $p->edgeAt(Side::E)->shape);
        $this->assertSame(Shape::Rectangle, $p->edgeAt(Side::S)->shape);
    }

    public function test_clockwise_rotation_moves_north_edge_to_east(): void
    {
        $p = new PlacedCard($this->card(), 'red', 0, 0, 1, false, 0);
        // Canonical N (Triangle) should now face world E.
        $this->assertSame(Shape::Triangle, $p->edgeAt(Side::E)->shape);
        // Canonical E (Square) should now face world S.
        $this->assertSame(Shape::Square, $p->edgeAt(Side::S)->shape);
    }

    public function test_mirror_swaps_east_and_west(): void
    {
        $p = new PlacedCard($this->card(), 'red', 0, 0, 0, true, 0);
        // World E now shows the canonical W edge (Triangle, 0 dots).
        $this->assertSame(Shape::Triangle, $p->edgeAt(Side::E)->shape);
        $this->assertSame(0, $p->edgeAt(Side::E)->dots);
        // World W now shows the canonical E edge (Square, 1 dot).
        $this->assertSame(Shape::Square, $p->edgeAt(Side::W)->shape);
        // N and S unaffected by horizontal mirror.
        $this->assertSame(Shape::Triangle, $p->edgeAt(Side::N)->shape);
        $this->assertSame(Shape::Rectangle, $p->edgeAt(Side::S)->shape);
    }

    public function test_rotation_out_of_range_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlacedCard($this->card(), 'red', 0, 0, 4, false, 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PlacedCardTest`
Expected: FAIL — `Class "App\Tacta\PlacedCard" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/PlacedCard.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class PlacedCard
{
    public function __construct(
        public readonly Card $card,
        public readonly string $color,
        public readonly int $x,
        public readonly int $y,
        public readonly int $rotation, // 0..3 clockwise quarter-turns
        public readonly bool $mirror,  // true = back face (horizontal mirror: swap E/W)
        public readonly int $z,        // play order; higher z = placed later = on top
    ) {
        if ($rotation < 0 || $rotation > 3) {
            throw new \InvalidArgumentException("rotation must be 0..3, got {$rotation}");
        }
    }

    /** Edge shown on the given world-facing side, after mirror + rotation. */
    public function edgeAt(Side $world): EdgeShape
    {
        // Undo clockwise rotation: the world side came from canonical side (world - rotation).
        $side = ($world->value - $this->rotation + 4) % 4;

        // Undo horizontal mirror (swap E<->W) applied before rotation.
        if ($this->mirror) {
            $side = match ($side) {
                Side::E->value => Side::W->value,
                Side::W->value => Side::E->value,
                default => $side,
            };
        }

        return $this->card->edge(Side::from($side));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PlacedCardTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/PlacedCard.php tests/Tacta/PlacedCardTest.php
git commit -m "feat(tacta): PlacedCard with rotation/mirror edge resolution"
```

---

## Task 4: `Board` (cell map)

**Files:**
- Create: `app/Tacta/Board.php`
- Test: `tests/Tacta/BoardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/BoardTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Board;
use App\Tacta\Card;
use App\Tacta\EdgeShape;
use App\Tacta\PlacedCard;
use App\Tacta\Shape;
use App\Tacta\Side;
use App\Tacta\Suit;
use PHPUnit\Framework\TestCase;

final class BoardTest extends TestCase
{
    private function placed(int $x, int $y, int $z): PlacedCard
    {
        $card = new Card("c{$x}_{$y}", [
            Side::N->value => new EdgeShape(Shape::Triangle, 1),
            Side::E->value => new EdgeShape(Shape::Square, 1),
            Side::S->value => new EdgeShape(Shape::Rectangle, 1),
            Side::W->value => new EdgeShape(Shape::Triangle, 0),
        ], Suit::Circle);

        return new PlacedCard($card, 'red', $x, $y, 0, false, $z);
    }

    public function test_empty_board(): void
    {
        $board = new Board();
        $this->assertTrue($board->isEmpty());
        $this->assertSame(0, $board->count());
        $this->assertSame(0, $board->nextZ());
        $this->assertNull($board->cardAt(0, 0));
    }

    public function test_place_and_lookup(): void
    {
        $board = new Board();
        $card = $this->placed(2, 3, 0);
        $board->place($card);

        $this->assertFalse($board->isEmpty());
        $this->assertSame(1, $board->count());
        $this->assertSame(1, $board->nextZ());
        $this->assertSame($card, $board->cardAt(2, 3));
    }

    public function test_neighbor_lookup(): void
    {
        $board = new Board();
        $center = $this->placed(0, 0, 0);
        $north = $this->placed(0, -1, 1);
        $board->place($center);
        $board->place($north);

        $this->assertSame($north, $board->neighbor(0, 0, Side::N));
        $this->assertNull($board->neighbor(0, 0, Side::S));
    }

    public function test_placing_on_occupied_cell_throws(): void
    {
        $board = new Board();
        $board->place($this->placed(1, 1, 0));
        $this->expectException(\InvalidArgumentException::class);
        $board->place($this->placed(1, 1, 1));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter BoardTest`
Expected: FAIL — `Class "App\Tacta\Board" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/Board.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class Board
{
    /** @var array<string, PlacedCard> keyed by "x,y" */
    private array $cells = [];

    public static function key(int $x, int $y): string
    {
        return $x . ',' . $y;
    }

    public function isEmpty(): bool
    {
        return $this->cells === [];
    }

    public function count(): int
    {
        return count($this->cells);
    }

    /** Next play-order value = number of cards already placed. */
    public function nextZ(): int
    {
        return count($this->cells);
    }

    public function cardAt(int $x, int $y): ?PlacedCard
    {
        return $this->cells[self::key($x, $y)] ?? null;
    }

    public function neighbor(int $x, int $y, Side $side): ?PlacedCard
    {
        [$dx, $dy] = $side->offset();

        return $this->cardAt($x + $dx, $y + $dy);
    }

    /** @return list<PlacedCard> */
    public function cards(): array
    {
        return array_values($this->cells);
    }

    public function place(PlacedCard $card): void
    {
        $key = self::key($card->x, $card->y);
        if (isset($this->cells[$key])) {
            throw new \InvalidArgumentException("Cell {$key} is already occupied");
        }
        $this->cells[$key] = $card;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter BoardTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/Board.php tests/Tacta/BoardTest.php
git commit -m "feat(tacta): Board cell map with neighbor lookup"
```

---

## Task 5: `Rules` (placement legality + legal-move enumeration)

**Files:**
- Create: `app/Tacta/Rules.php`
- Test: `tests/Tacta/RulesTest.php`

Rules implemented:
- **`isLegalConnect`** — target cell empty; **exactly one** orthogonal neighbor; the shared
  edge's shapes match. (The first move connects to the lone Starting Card.)
- **`isLegalIsolated`** — cell empty and **no** orthogonal neighbors (the no-legal-move drop).
- **`legalConnects`** — enumerate every legal connecting placement of a card across all empty
  cells adjacent to the tableau and all 4 rotations × 2 mirror faces. (Used later by the turn
  layer to decide whether the isolated drop is permitted, and by the client to highlight
  legal targets.)

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/RulesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Board;
use App\Tacta\Card;
use App\Tacta\EdgeShape;
use App\Tacta\PlacedCard;
use App\Tacta\Rules;
use App\Tacta\Shape;
use App\Tacta\Side;
use App\Tacta\Suit;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    /** A card whose four edges are the given shapes (1 dot each). */
    private function card(string $id, Shape $n, Shape $e, Shape $s, Shape $w): Card
    {
        return new Card($id, [
            Side::N->value => new EdgeShape($n, 1),
            Side::E->value => new EdgeShape($e, 1),
            Side::S->value => new EdgeShape($s, 1),
            Side::W->value => new EdgeShape($w, 1),
        ], Suit::Circle);
    }

    private function boardWithStart(): Board
    {
        // Starting card at origin, all four edges Square, z=0.
        $start = new PlacedCard(
            $this->card('start', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'neutral', 0, 0, 0, false, 0
        );
        $board = new Board();
        $board->place($start);

        return $board;
    }

    public function test_connect_matches_shared_edge(): void
    {
        $board = $this->boardWithStart();
        // Place to the EAST of start: my W edge meets start's E edge (Square). Make my W = Square.
        $mine = new PlacedCard(
            $this->card('m', Shape::Triangle, Shape::Triangle, Shape::Triangle, Shape::Square),
            'red', 1, 0, 0, false, $board->nextZ()
        );
        $this->assertTrue(Rules::isLegalConnect($board, $mine));
    }

    public function test_connect_rejected_when_shapes_mismatch(): void
    {
        $board = $this->boardWithStart();
        // My W edge is Triangle but start's E edge is Square -> mismatch.
        $mine = new PlacedCard(
            $this->card('m', Shape::Triangle, Shape::Triangle, Shape::Triangle, Shape::Triangle),
            'red', 1, 0, 0, false, $board->nextZ()
        );
        $this->assertFalse(Rules::isLegalConnect($board, $mine));
    }

    public function test_connect_rejected_on_occupied_cell(): void
    {
        $board = $this->boardWithStart();
        $mine = new PlacedCard(
            $this->card('m', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'red', 0, 0, 0, false, $board->nextZ()
        );
        $this->assertFalse(Rules::isLegalConnect($board, $mine));
    }

    public function test_connect_rejected_when_touching_two_cards(): void
    {
        $board = $this->boardWithStart();
        // Seed an L-shape around the origin (placed directly, bypassing legality for setup):
        // start at (0,0), card 'a' at (1,0), card 'b' at (0,1).
        $board->place(new PlacedCard(
            $this->card('a', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'red', 1, 0, 0, false, $board->nextZ()
        ));
        $board->place(new PlacedCard(
            $this->card('b', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'red', 0, 1, 0, false, $board->nextZ()
        ));
        // Cell (1,1) is orthogonally adjacent to both 'a' (1,0) and 'b' (0,1):
        // two neighbors -> connecting to it is illegal.
        $mine = new PlacedCard(
            $this->card('m', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'red', 1, 1, 0, false, $board->nextZ()
        );
        $this->assertFalse(Rules::isLegalConnect($board, $mine));
    }

    public function test_isolated_requires_no_neighbors(): void
    {
        $board = $this->boardWithStart();
        $far = new PlacedCard(
            $this->card('f', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'red', 5, 5, 0, false, $board->nextZ()
        );
        $adjacent = new PlacedCard(
            $this->card('f', Shape::Square, Shape::Square, Shape::Square, Shape::Square),
            'red', 1, 0, 0, false, $board->nextZ()
        );
        $this->assertTrue(Rules::isLegalIsolated($board, $far));
        $this->assertFalse(Rules::isLegalIsolated($board, $adjacent));
    }

    public function test_legal_connects_enumerates_orientations(): void
    {
        $board = $this->boardWithStart();
        // A card with exactly one Square edge (N) and the rest Triangles. Around the lone
        // start (all Square edges), legal placements exist in all 4 directions, and for each
        // direction exactly the rotations/mirrors that turn the Square edge toward the start.
        $card = $this->card('m', Shape::Square, Shape::Triangle, Shape::Triangle, Shape::Triangle);
        $moves = Rules::legalConnects($board, $card, 'red', $board->nextZ());

        $this->assertNotEmpty($moves);
        foreach ($moves as $move) {
            $this->assertTrue(Rules::isLegalConnect($board, $move));
        }
        // Every legal placement must be in one of the four cells around the start.
        $cells = array_map(static fn (PlacedCard $m): string => Board::key($m->x, $m->y), $moves);
        foreach ($cells as $cell) {
            $this->assertContains($cell, ['0,-1', '1,0', '0,1', '-1,0']);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RulesTest`
Expected: FAIL — `Class "App\Tacta\Rules" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/Rules.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class Rules
{
    private const SIDES = [Side::N, Side::E, Side::S, Side::W];

    /**
     * Legal "connect/cover" move: empty cell, exactly one orthogonal neighbor,
     * and the shared edge's shapes match.
     */
    public static function isLegalConnect(Board $board, PlacedCard $card): bool
    {
        if ($board->cardAt($card->x, $card->y) !== null) {
            return false;
        }

        $touching = [];
        foreach (self::SIDES as $side) {
            $neighbor = $board->neighbor($card->x, $card->y, $side);
            if ($neighbor !== null) {
                $touching[] = [$side, $neighbor];
            }
        }

        if (count($touching) !== 1) {
            return false;
        }

        [$side, $neighbor] = $touching[0];

        return $card->edgeAt($side)->shape === $neighbor->edgeAt($side->opposite())->shape;
    }

    /** Legal isolated drop (no-legal-move fallback): empty cell with no neighbors. */
    public static function isLegalIsolated(Board $board, PlacedCard $card): bool
    {
        if ($board->cardAt($card->x, $card->y) !== null) {
            return false;
        }
        foreach (self::SIDES as $side) {
            if ($board->neighbor($card->x, $card->y, $side) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every legal connecting placement of $card: all empty cells adjacent to the
     * tableau, across all 4 rotations and both mirror faces.
     *
     * @return list<PlacedCard>
     */
    public static function legalConnects(Board $board, Card $card, string $color, int $z): array
    {
        $candidates = [];
        foreach ($board->cards() as $placed) {
            foreach (self::SIDES as $side) {
                [$dx, $dy] = $side->offset();
                $cx = $placed->x + $dx;
                $cy = $placed->y + $dy;
                if ($board->cardAt($cx, $cy) === null) {
                    $candidates[Board::key($cx, $cy)] = [$cx, $cy];
                }
            }
        }

        $moves = [];
        foreach ($candidates as [$cx, $cy]) {
            for ($rotation = 0; $rotation < 4; $rotation++) {
                foreach ([false, true] as $mirror) {
                    $placement = new PlacedCard($card, $color, $cx, $cy, $rotation, $mirror, $z);
                    if (self::isLegalConnect($board, $placement)) {
                        $moves[] = $placement;
                    }
                }
            }
        }

        return $moves;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RulesTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/Rules.php tests/Tacta/RulesTest.php
git commit -m "feat(tacta): placement legality and legal-move enumeration"
```

---

## Task 6: `Scorer` (visible-dot totals)

**Files:**
- Create: `app/Tacta/Scorer.php`
- Test: `tests/Tacta/ScorerTest.php`

A tab's dots count for its owner unless a **later-placed** neighbor (higher `z`) sits across
that edge. Because every connecting placement is newer than the card it attaches to, placing a
card always covers the neighbor's tab on the shared edge (neighbor's dots hidden, the new
card's tab on top).

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/ScorerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Board;
use App\Tacta\Card;
use App\Tacta\EdgeShape;
use App\Tacta\PlacedCard;
use App\Tacta\Scorer;
use App\Tacta\Shape;
use App\Tacta\Side;
use App\Tacta\Suit;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    private function card(string $id, int $n, int $e, int $s, int $w): Card
    {
        return new Card($id, [
            Side::N->value => new EdgeShape(Shape::Square, $n),
            Side::E->value => new EdgeShape(Shape::Square, $e),
            Side::S->value => new EdgeShape(Shape::Square, $s),
            Side::W->value => new EdgeShape(Shape::Square, $w),
        ], Suit::Square);
    }

    public function test_lone_card_counts_all_dots(): void
    {
        $board = new Board();
        $board->place(new PlacedCard($this->card('a', 1, 2, 0, 1), 'red', 0, 0, 0, false, 0));
        $this->assertSame(['red' => 4], Scorer::scores($board));
    }

    public function test_later_neighbor_hides_the_covered_tab(): void
    {
        $board = new Board();
        // Red at (0,0): N=0,E=2,S=0,W=0  -> east tab worth 2.
        $red = new PlacedCard($this->card('r', 0, 2, 0, 0), 'red', 0, 0, 0, false, 0);
        // Blue placed LATER at (1,0): its W tab covers red's E tab. Blue W = 3, N=E=S=0.
        $blue = new PlacedCard($this->card('b', 0, 0, 0, 3), 'blue', 1, 0, 0, false, 1);
        $board->place($red);
        $board->place($blue);

        $scores = Scorer::scores($board);
        // Red's east tab (2) is covered by the newer blue card -> red scores 0.
        $this->assertSame(0, $scores['red'] ?? 0);
        // Blue's west tab (3) sits on top -> visible.
        $this->assertSame(3, $scores['blue']);
    }

    public function test_neutral_starting_card_with_no_dots_scores_nothing(): void
    {
        $board = new Board();
        $board->place(new PlacedCard($this->card('start', 0, 0, 0, 0), 'neutral', 0, 0, 0, false, 0));
        $this->assertSame([], Scorer::scores($board));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ScorerTest`
Expected: FAIL — `Class "App\Tacta\Scorer" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/Scorer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class Scorer
{
    private const SIDES = [Side::N, Side::E, Side::S, Side::W];

    /**
     * Visible-dot totals keyed by color. A card's tab on a side is hidden when a
     * later-placed (higher-z) neighbor sits across that edge.
     *
     * @return array<string, int>
     */
    public static function scores(Board $board): array
    {
        $totals = [];
        foreach ($board->cards() as $card) {
            foreach (self::SIDES as $side) {
                $dots = $card->edgeAt($side)->dots;
                if ($dots === 0) {
                    continue;
                }
                $neighbor = $board->neighbor($card->x, $card->y, $side);
                $covered = $neighbor !== null && $neighbor->z > $card->z;
                if (!$covered) {
                    $totals[$card->color] = ($totals[$card->color] ?? 0) + $dots;
                }
            }
        }

        return $totals;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ScorerTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/Scorer.php tests/Tacta/ScorerTest.php
git commit -m "feat(tacta): visible-dot scoring by z-order tab coverage"
```

---

## Task 7: `Deck` (the 18-card layout set)

**Files:**
- Create: `app/Tacta/Deck.php`
- Test: `tests/Tacta/DeckTest.php`

All six colors share one set of **18 card layouts** (color is a tint). The Starting Card is a
separate neutral card with four edge shapes but **no dots**. `Deck::forColor()` instantiates
18 `Card`s with ids `"{color}-{n}"`. The exact dot/shape mix below is a balanced first cut; it
can be tuned later without changing the engine.

- [ ] **Step 1: Write the failing test**

Create `tests/Tacta/DeckTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Card;
use App\Tacta\Deck;
use App\Tacta\EdgeShape;
use App\Tacta\Side;
use PHPUnit\Framework\TestCase;

final class DeckTest extends TestCase
{
    public function test_color_deck_has_18_cards(): void
    {
        $this->assertCount(18, Deck::forColor('red'));
    }

    public function test_card_ids_are_prefixed_and_unique(): void
    {
        $ids = array_map(static fn (Card $c): string => $c->id, Deck::forColor('blue'));
        $this->assertCount(18, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertStringStartsWith('blue-', $id);
        }
    }

    public function test_every_card_value_equals_sum_of_edge_dots(): void
    {
        foreach (Deck::forColor('green') as $card) {
            $sum = 0;
            foreach ([Side::N, Side::E, Side::S, Side::W] as $side) {
                $edge = $card->edge($side);
                $this->assertInstanceOf(EdgeShape::class, $edge);
                $this->assertGreaterThanOrEqual(0, $edge->dots);
                $this->assertLessThanOrEqual(3, $edge->dots);
                $sum += $edge->dots;
            }
            $this->assertSame($sum, $card->value(), "value mismatch on {$card->id}");
        }
    }

    public function test_all_six_colors_share_the_same_layout_shape(): void
    {
        $red = Deck::forColor('red');
        $blue = Deck::forColor('blue');
        for ($i = 0; $i < 18; $i++) {
            foreach ([Side::N, Side::E, Side::S, Side::W] as $side) {
                $this->assertSame(
                    $red[$i]->edge($side)->shape,
                    $blue[$i]->edge($side)->shape,
                    "layout {$i} side {$side->name} differs between colors"
                );
                $this->assertSame($red[$i]->edge($side)->dots, $blue[$i]->edge($side)->dots);
            }
        }
    }

    public function test_starting_card_has_no_dots(): void
    {
        $start = Deck::startingCard();
        $this->assertSame(0, $start->value());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DeckTest`
Expected: FAIL — `Class "App\Tacta\Deck" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Tacta/Deck.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tacta;

final class Deck
{
    // 18 layouts. Each row: [N, E, S, W] where each edge is [Shape, dots], plus a Suit.
    // Balanced first cut; all six color decks share these layouts.
    private const T = Shape::Triangle;
    private const Q = Shape::Square;
    private const R = Shape::Rectangle;

    /** @return list<array{edges: array<int, array{0: Shape, 1: int}>, suit: Suit}> */
    private static function layouts(): array
    {
        $rows = [
            // N,        E,        S,        R,        suit
            [[self::T, 1], [self::Q, 0], [self::R, 1], [self::T, 0], Suit::Circle],
            [[self::Q, 2], [self::T, 1], [self::Q, 0], [self::R, 0], Suit::Square],
            [[self::R, 1], [self::R, 1], [self::T, 1], [self::Q, 0], Suit::Triangle],
            [[self::T, 0], [self::Q, 2], [self::R, 0], [self::T, 1], Suit::Circle],
            [[self::Q, 1], [self::T, 0], [self::T, 2], [self::R, 0], Suit::Square],
            [[self::R, 0], [self::R, 1], [self::Q, 1], [self::T, 1], Suit::Triangle],
            [[self::T, 2], [self::Q, 1], [self::R, 0], [self::Q, 0], Suit::Circle],
            [[self::Q, 0], [self::T, 1], [self::T, 1], [self::R, 1], Suit::Square],
            [[self::R, 1], [self::R, 0], [self::Q, 2], [self::T, 0], Suit::Triangle],
            [[self::T, 1], [self::Q, 1], [self::R, 1], [self::T, 0], Suit::Circle],
            [[self::Q, 0], [self::T, 2], [self::Q, 0], [self::R, 1], Suit::Square],
            [[self::R, 2], [self::R, 0], [self::T, 1], [self::Q, 0], Suit::Triangle],
            [[self::T, 0], [self::Q, 1], [self::R, 1], [self::T, 1], Suit::Circle],
            [[self::Q, 1], [self::T, 0], [self::T, 0], [self::R, 2], Suit::Square],
            [[self::R, 1], [self::R, 1], [self::Q, 0], [self::T, 1], Suit::Triangle],
            [[self::T, 1], [self::Q, 0], [self::R, 2], [self::Q, 0], Suit::Circle],
            [[self::Q, 0], [self::T, 1], [self::T, 1], [self::R, 0], Suit::Square],
            [[self::R, 0], [self::R, 2], [self::Q, 1], [self::T, 0], Suit::Triangle],
        ];

        $out = [];
        foreach ($rows as $row) {
            $suit = array_pop($row);
            $out[] = ['edges' => $row, 'suit' => $suit];
        }

        return $out;
    }

    /** @return list<Card> the 18 cards for a color */
    public static function forColor(string $color): array
    {
        $cards = [];
        foreach (self::layouts() as $i => $layout) {
            $edges = [];
            foreach ([Side::N, Side::E, Side::S, Side::W] as $idx => $side) {
                [$shape, $dots] = $layout['edges'][$idx];
                $edges[$side->value] = new EdgeShape($shape, $dots);
            }
            $n = $i + 1;
            $cards[] = new Card("{$color}-{$n}", $edges, $layout['suit']);
        }

        return $cards;
    }

    /** The neutral starting card: four edge shapes, no dots. */
    public static function startingCard(): Card
    {
        return new Card('start', [
            Side::N->value => new EdgeShape(Shape::Triangle, 0),
            Side::E->value => new EdgeShape(Shape::Square, 0),
            Side::S->value => new EdgeShape(Shape::Rectangle, 0),
            Side::W->value => new EdgeShape(Shape::Square, 0),
        ], Suit::Circle);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DeckTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Tacta/Deck.php tests/Tacta/DeckTest.php
git commit -m "feat(tacta): 18-card deck layouts and neutral starting card"
```

---

## Task 8: Full suite green

- [ ] **Step 1: Run the entire test suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all existing tests plus the new `Tests\Tacta\*` tests (Side, Card, PlacedCard, Board, Rules, Scorer, Deck) green.

- [ ] **Step 2: Commit nothing if clean**

If the suite is green and the tree is clean, Phase 1 is complete. Otherwise fix the failing
test before proceeding. Do not move to Phase 2 with a red suite.

---

## Self-review notes (already applied)

- **Spec coverage:** every "rules engine" requirement maps to a task — cards/edges/value
  (Task 2), double-sided + rotation (Task 3), single-cell board (Task 4), edge-adjacency +
  exactly-one-neighbor + shared-edge match + isolated fallback (Task 5), z-order tab-cover
  scoring (Task 6), 6×18 shared deck + neutral starting card (Task 7).
- **Deferred to later phases (intentionally not here):** the turn rule that an isolated drop
  is only allowed when no `legalConnects` exist for either outermost card (Phase 3 turn
  layer); shuffling/deque handling and first-player determination (Phase 2/3); persistence
  (Phase 2). The engine exposes the primitives those phases compose.
- **Type consistency:** `Side->value` (0=N,1=E,2=S,3=W) is the single source of edge indexing
  across `Card`, `PlacedCard`, `Board`, `Rules`, `Scorer`, and `Deck`. `z` semantics
  (higher = later = on top) are identical in `Board::nextZ()`, `Rules`, and `Scorer`.
