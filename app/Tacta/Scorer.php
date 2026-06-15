<?php

declare(strict_types=1);

namespace App\Tacta;

final class Scorer
{
    private const SIDES = [Side::N, Side::E, Side::S, Side::W];

    /**
     * Visible-dot totals keyed by color. A card's tab on a side is hidden when a
     * later-placed (higher-z) neighbor sits across that edge.
     *
     * @return array<string, int>
     */
    public static function scores(Board $board): array
    {
        $totals = [];
        foreach ($board->cards() as $card) {
            foreach (self::SIDES as $side) {
                $dots = $card->edgeAt($side)->dots;
                if ($dots === 0) {
                    continue;
                }
                $neighbor = $board->neighbor($card->x, $card->y, $side);
                $covered = $neighbor !== null && $neighbor->z > $card->z;
                if (!$covered) {
                    $totals[$card->color] = ($totals[$card->color] ?? 0) + $dots;
                }
            }
        }

        return $totals;
    }
}
