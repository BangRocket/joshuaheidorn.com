<?php

declare(strict_types=1);

namespace App\Tacta;

final class Board
{
    /** @var array<string, PlacedCard> keyed by "x,y" */
    private array $cells = [];

    public static function key(int $x, int $y): string
    {
        return $x . ',' . $y;
    }

    public function isEmpty(): bool
    {
        return $this->cells === [];
    }

    public function count(): int
    {
        return count($this->cells);
    }

    /** Next play-order value = number of cards already placed. */
    public function nextZ(): int
    {
        return count($this->cells);
    }

    public function cardAt(int $x, int $y): ?PlacedCard
    {
        return $this->cells[self::key($x, $y)] ?? null;
    }

    public function neighbor(int $x, int $y, Side $side): ?PlacedCard
    {
        [$dx, $dy] = $side->offset();

        return $this->cardAt($x + $dx, $y + $dy);
    }

    /** @return list<PlacedCard> */
    public function cards(): array
    {
        return array_values($this->cells);
    }

    public function place(PlacedCard $card): void
    {
        $key = self::key($card->x, $card->y);
        if (isset($this->cells[$key])) {
            throw new \InvalidArgumentException("Cell {$key} is already occupied");
        }
        $this->cells[$key] = $card;
    }
}
