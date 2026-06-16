<?php

declare(strict_types=1);

namespace App\Tacta;

use App\Support\ValidationException;

/**
 * Server-authoritative validation of a proposed move. Resolves which card the
 * player is playing (top/bottom of their deque) and verifies the placement is
 * legal against the current board.
 */
final class MoveValidator
{
    private const SIDES = [Side::N, Side::E, Side::S, Side::W];

    /**
     * @param list<int> $deck shuffled layout indices (0..17)
     * @param array{draw_end:string,x:int,y:int,rotation:int,mirror:bool} $move
     * @return array{card_id:string,draw_end:string,x:int,y:int,rotation:int,mirror:bool}
     * @throws ValidationException
     */
    public static function validate(
        Board $board,
        string $color,
        array $deck,
        int $topDraws,
        int $bottomDraws,
        array $move,
    ): array {
        $count = count($deck);
        $head = $topDraws;
        $tail = $count - 1 - $bottomDraws;
        if ($head > $tail) {
            throw new ValidationException('No cards left to play.');
        }

        $drawEnd = ($move['draw_end'] ?? 'top') === 'bottom' ? 'bottom' : 'top';
        if ($head === $tail) {
            $drawEnd = 'top'; // one card left: both ends are the same card
        }
        $layoutIndex = $drawEnd === 'top' ? $deck[$head] : $deck[$tail];
        $cardId = $color . '-' . ($layoutIndex + 1);
        $card = Deck::forColor($color)[$layoutIndex];

        $x = (int) $move['x'];
        $y = (int) $move['y'];
        $rotation = (int) $move['rotation'];
        $mirror = !empty($move['mirror']);
        if ($rotation < 0 || $rotation > 3) {
            throw new ValidationException('Invalid rotation.');
        }
        if ($board->cardAt($x, $y) !== null) {
            throw new ValidationException('That cell is already occupied.');
        }

        $placement = new PlacedCard($card, $color, $x, $y, $rotation, $mirror, $board->nextZ());

        $neighbors = 0;
        foreach (self::SIDES as $side) {
            if ($board->neighbor($x, $y, $side) !== null) {
                $neighbors++;
            }
        }

        if ($neighbors > 0) {
            if (!Rules::isLegalConnect($board, $placement)) {
                throw new ValidationException('Illegal move: your edge shape must match a single adjacent card.');
            }
        } else {
            if (self::anyConnectPossible($board, $color, $deck, $head, $tail)) {
                throw new ValidationException('You must connect to a card when a legal move exists.');
            }
            if (!Rules::isLegalIsolated($board, $placement)) {
                throw new ValidationException('Illegal isolated placement.');
            }
        }

        return [
            'card_id' => $cardId,
            'draw_end' => $drawEnd,
            'x' => $x,
            'y' => $y,
            'rotation' => $rotation,
            'mirror' => $mirror,
        ];
    }

    /** Can either outermost card connect anywhere on the board? */
    private static function anyConnectPossible(Board $board, string $color, array $deck, int $head, int $tail): bool
    {
        $cards = Deck::forColor($color);
        $indices = $head === $tail ? [$deck[$head]] : [$deck[$head], $deck[$tail]];
        foreach ($indices as $index) {
            if (Rules::legalConnects($board, $cards[$index], $color, $board->nextZ()) !== []) {
                return true;
            }
        }

        return false;
    }
}
