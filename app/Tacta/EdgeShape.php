<?php

declare(strict_types=1);

namespace App\Tacta;

final class EdgeShape
{
    public function __construct(
        public readonly Shape $shape,
        public readonly int $dots, // 0 = hollow; 1..3 = filled
    ) {
        if ($dots < 0 || $dots > 3) {
            throw new \InvalidArgumentException("Edge dots out of range: {$dots}");
        }
    }

    public function isHollow(): bool
    {
        return $this->dots === 0;
    }
}
