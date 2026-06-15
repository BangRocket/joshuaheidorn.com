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
