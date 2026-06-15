<?php

declare(strict_types=1);

namespace App\Tacta;

final class Rules
{
    private const SIDES = [Side::N, Side::E, Side::S, Side::W];

    /**
     * Legal "connect/cover" move: empty cell, exactly one orthogonal neighbor,
     * and the shared edge's shapes match.
     */
    public static function isLegalConnect(Board $board, PlacedCard $card): bool
    {
        if ($board->cardAt($card->x, $card->y) !== null) {
            return false;
        }

        $touching = [];
        foreach (self::SIDES as $side) {
            $neighbor = $board->neighbor($card->x, $card->y, $side);
            if ($neighbor !== null) {
                $touching[] = [$side, $neighbor];
            }
        }

        if (count($touching) !== 1) {
            return false;
        }

        [$side, $neighbor] = $touching[0];

        return $card->edgeAt($side)->shape === $neighbor->edgeAt($side->opposite())->shape;
    }

    /** Legal isolated drop (no-legal-move fallback): empty cell with no neighbors. */
    public static function isLegalIsolated(Board $board, PlacedCard $card): bool
    {
        if ($board->cardAt($card->x, $card->y) !== null) {
            return false;
        }
        foreach (self::SIDES as $side) {
            if ($board->neighbor($card->x, $card->y, $side) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every legal connecting placement of $card: all empty cells adjacent to the
     * tableau, across all 4 rotations and both mirror faces.
     *
     * @return list<PlacedCard>
     */
    public static function legalConnects(Board $board, Card $card, string $color, int $z): array
    {
        $candidates = [];
        foreach ($board->cards() as $placed) {
            foreach (self::SIDES as $side) {
                [$dx, $dy] = $side->offset();
                $cx = $placed->x + $dx;
                $cy = $placed->y + $dy;
                if ($board->cardAt($cx, $cy) === null) {
                    $candidates[Board::key($cx, $cy)] = [$cx, $cy];
                }
            }
        }

        $moves = [];
        foreach ($candidates as [$cx, $cy]) {
            for ($rotation = 0; $rotation < 4; $rotation++) {
                foreach ([false, true] as $mirror) {
                    $placement = new PlacedCard($card, $color, $cx, $cy, $rotation, $mirror, $z);
                    if (self::isLegalConnect($board, $placement)) {
                        $moves[] = $placement;
                    }
                }
            }
        }

        return $moves;
    }
}
