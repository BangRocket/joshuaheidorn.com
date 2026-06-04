<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ReadingTime;
use PHPUnit\Framework\TestCase;

final class ReadingTimeTest extends TestCase
{
    public function test_minimum_one_minute(): void
    {
        $this->assertSame(1, ReadingTime::minutes('Short text.'));
    }

    public function test_scales_with_word_count(): void
    {
        $words = str_repeat('word ', 400); // 400 words ~ 2 min @ 200 wpm
        $this->assertSame(2, ReadingTime::minutes($words));
    }
}
