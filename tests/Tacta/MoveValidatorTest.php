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
        if ($deckOrder === null) {
            $this->markTestSkipped('no layout exists without a Square edge in this deck');
        }
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
