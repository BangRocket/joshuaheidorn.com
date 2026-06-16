<?php

declare(strict_types=1);

namespace App\Tacta;

/** Reconstructs a Board from the persisted move log (the source of truth). */
final class BoardBuilder
{
    /**
     * @param list<array{card_id:string,x:int,y:int,rotation:int,mirror:bool,z:int}> $moves
     *        cast move rows in play order
     */
    public static function build(array $moves): Board
    {
        $board = new Board();
        $board->place(new PlacedCard(Deck::startingCard(), 'neutral', 0, 0, 0, false, 0));

        foreach ($moves as $move) {
            $board->place(new PlacedCard(
                self::cardFromId($move['card_id']),
                self::colorFromId($move['card_id']),
                (int) $move['x'],
                (int) $move['y'],
                (int) $move['rotation'],
                (bool) $move['mirror'],
                (int) $move['z'],
            ));
        }

        return $board;
    }

    /** "red-7" -> the 7th red layout card. */
    public static function cardFromId(string $cardId): Card
    {
        [$color, $n] = self::split($cardId);

        return Deck::forColor($color)[$n - 1];
    }

    public static function colorFromId(string $cardId): string
    {
        return self::split($cardId)[0];
    }

    /** @return array{0:string,1:int} */
    private static function split(string $cardId): array
    {
        $parts = explode('-', $cardId);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            throw new \InvalidArgumentException("Bad card id: {$cardId}");
        }

        return [$parts[0], (int) $parts[1]];
    }
}
