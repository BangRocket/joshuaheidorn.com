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
