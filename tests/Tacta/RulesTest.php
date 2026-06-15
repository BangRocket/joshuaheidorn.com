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
