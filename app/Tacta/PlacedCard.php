<?php

declare(strict_types=1);

namespace App\Tacta;

final class PlacedCard
{
    public function __construct(
        public readonly Card $card,
        public readonly string $color,
        public readonly int $x,
        public readonly int $y,
        public readonly int $rotation, // 0..3 clockwise quarter-turns
        public readonly bool $mirror,  // true = back face (horizontal mirror: swap E/W)
        public readonly int $z,        // play order; higher z = placed later = on top
    ) {
        if ($rotation < 0 || $rotation > 3) {
            throw new \InvalidArgumentException("rotation must be 0..3, got {$rotation}");
        }
    }

    /** Edge shown on the given world-facing side, after mirror + rotation. */
    public function edgeAt(Side $world): EdgeShape
    {
        // Undo clockwise rotation: the world side came from canonical side (world - rotation).
        $side = ($world->value - $this->rotation + 4) % 4;

        // Undo horizontal mirror (swap E<->W) applied before rotation.
        if ($this->mirror) {
            $side = match ($side) {
                Side::E->value => Side::W->value,
                Side::W->value => Side::E->value,
                default => $side,
            };
        }

        return $this->card->edge(Side::from($side));
    }
}
