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
