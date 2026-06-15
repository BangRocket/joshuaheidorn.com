<?php

declare(strict_types=1);

namespace App\Tacta;

enum Side: int
{
    case N = 0;
    case E = 1;
    case S = 2;
    case W = 3;

    /**
     * Grid offset [dx, dy] for this side, in screen coordinates (y grows downward).
     *
     * @return array{int, int}
     */
    public function offset(): array
    {
        return match ($this) {
            self::N => [0, -1],
            self::E => [1, 0],
            self::S => [0, 1],
            self::W => [-1, 0],
        };
    }

    public function opposite(): self
    {
        return self::from(($this->value + 2) % 4);
    }
}
