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
