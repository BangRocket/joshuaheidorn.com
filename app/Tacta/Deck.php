<?php

declare(strict_types=1);

namespace App\Tacta;

final class Deck
{
    // 18 layouts. Each row: [N, E, S, W] where each edge is [Shape, dots], plus a Suit.
    // Balanced first cut; all six color decks share these layouts.
    // NOTE: every layout currently carries all three edge-shape types, so a legal connecting
    // move always exists — the "no legal move -> isolated drop" rule (Rules::isLegalIsolated,
    // exercised by tests) is intentionally unreachable in real play until these decks are tuned.
    private const T = Shape::Triangle;
    private const Q = Shape::Square;
    private const R = Shape::Rectangle;

    /** @return list<array{edges: array<int, array{0: Shape, 1: int}>, suit: Suit}> */
    private static function layouts(): array
    {
        $rows = [
            // N,        E,        S,        W,        suit
            [[self::T, 1], [self::Q, 0], [self::R, 1], [self::T, 0], Suit::Circle],
            [[self::Q, 2], [self::T, 1], [self::Q, 0], [self::R, 0], Suit::Square],
            [[self::R, 1], [self::R, 1], [self::T, 1], [self::Q, 0], Suit::Triangle],
            [[self::T, 0], [self::Q, 2], [self::R, 0], [self::T, 1], Suit::Circle],
            [[self::Q, 1], [self::T, 0], [self::T, 2], [self::R, 0], Suit::Square],
            [[self::R, 0], [self::R, 1], [self::Q, 1], [self::T, 1], Suit::Triangle],
            [[self::T, 2], [self::Q, 1], [self::R, 0], [self::Q, 0], Suit::Circle],
            [[self::Q, 0], [self::T, 1], [self::T, 1], [self::R, 1], Suit::Square],
            [[self::R, 1], [self::R, 0], [self::Q, 2], [self::T, 0], Suit::Triangle],
            [[self::T, 1], [self::Q, 1], [self::R, 1], [self::T, 0], Suit::Circle],
            [[self::Q, 0], [self::T, 2], [self::Q, 0], [self::R, 1], Suit::Square],
            [[self::R, 2], [self::R, 0], [self::T, 1], [self::Q, 0], Suit::Triangle],
            [[self::T, 0], [self::Q, 1], [self::R, 1], [self::T, 1], Suit::Circle],
            [[self::Q, 1], [self::T, 0], [self::T, 0], [self::R, 2], Suit::Square],
            [[self::R, 1], [self::R, 1], [self::Q, 0], [self::T, 1], Suit::Triangle],
            [[self::T, 1], [self::Q, 0], [self::R, 2], [self::Q, 0], Suit::Circle],
            [[self::Q, 0], [self::T, 1], [self::T, 1], [self::R, 0], Suit::Square],
            [[self::R, 0], [self::R, 2], [self::Q, 1], [self::T, 0], Suit::Triangle],
        ];

        $out = [];
        foreach ($rows as $row) {
            $suit = array_pop($row);
            $out[] = ['edges' => $row, 'suit' => $suit];
        }

        return $out;
    }

    /** @return list<Card> the 18 cards for a color */
    public static function forColor(string $color): array
    {
        $cards = [];
        foreach (self::layouts() as $i => $layout) {
            $edges = [];
            foreach ([Side::N, Side::E, Side::S, Side::W] as $idx => $side) {
                [$shape, $dots] = $layout['edges'][$idx];
                $edges[$side->value] = new EdgeShape($shape, $dots);
            }
            $n = $i + 1;
            $cards[] = new Card("{$color}-{$n}", $edges, $layout['suit']);
        }

        return $cards;
    }

    /** The neutral starting card: four edge shapes, no dots. */
    public static function startingCard(): Card
    {
        return new Card('start', [
            Side::N->value => new EdgeShape(Shape::Triangle, 0),
            Side::E->value => new EdgeShape(Shape::Square, 0),
            Side::S->value => new EdgeShape(Shape::Rectangle, 0),
            Side::W->value => new EdgeShape(Shape::Square, 0),
        ], Suit::Circle);
    }
}
