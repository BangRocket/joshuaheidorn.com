<?php

declare(strict_types=1);

namespace Tests\Tacta;

use App\Tacta\Side;
use PHPUnit\Framework\TestCase;

final class SideTest extends TestCase
{
    public function test_offsets_use_screen_coordinates_y_down(): void
    {
        $this->assertSame([0, -1], Side::N->offset());
        $this->assertSame([1, 0], Side::E->offset());
        $this->assertSame([0, 1], Side::S->offset());
        $this->assertSame([-1, 0], Side::W->offset());
    }

    public function test_opposite(): void
    {
        $this->assertSame(Side::S, Side::N->opposite());
        $this->assertSame(Side::W, Side::E->opposite());
        $this->assertSame(Side::N, Side::S->opposite());
        $this->assertSame(Side::E, Side::W->opposite());
    }
}
