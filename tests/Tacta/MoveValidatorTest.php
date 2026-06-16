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
        // An empty board (no cards) has nothing to connect to, so the isolated-drop branch
        // (anyConnectPossible == false, then isLegalIsolated) must accept the placement.
        // This exercises the acceptance path directly, independent of deck composition.
        $board = new Board();
        $deck = range(0, 17);

        $result = MoveValidator::validate($board, 'red', $deck, 0, 0, [
            'draw_end' => 'top', 'x' => 0, 'y' => 0, 'rotation' => 0, 'mirror' => false,
        ]);

        $this->assertSame('red-' . ($deck[0] + 1), $result['card_id']);
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
