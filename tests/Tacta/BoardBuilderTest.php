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
