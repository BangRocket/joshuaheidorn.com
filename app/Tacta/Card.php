<?php

declare(strict_types=1);

namespace App\Tacta;

final class Card
{
    /** @param array<int, EdgeShape> $edges keyed by Side->value (0=N,1=E,2=S,3=W) */
    public function __construct(
        public readonly string $id,
        public readonly array $edges,
        public readonly Suit $suit,
    ) {
        foreach ([Side::N, Side::E, Side::S, Side::W] as $side) {
            $edge = $edges[$side->value] ?? null;
            if (!$edge instanceof EdgeShape) {
                throw new \InvalidArgumentException("Card {$id} missing edge {$side->name}");
            }
        }
    }

    public function edge(Side $side): EdgeShape
    {
        return $this->edges[$side->value];
    }

    /** Center value = total dots across all four edges. */
    public function value(): int
    {
        return array_sum(array_map(static fn (EdgeShape $e): int => $e->dots, $this->edges));
    }
}
