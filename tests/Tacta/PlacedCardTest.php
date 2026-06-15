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
