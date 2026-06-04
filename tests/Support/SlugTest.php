<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function test_lowercases_and_hyphenates(): void
    {
        $this->assertSame('hello-world', Slug::make('Hello World'));
    }

    public function test_strips_punctuation_and_collapses_separators(): void
    {
        $this->assertSame('react-typescript', Slug::make('React + TypeScript!!'));
    }

    public function test_transliterates_accents(): void
    {
        $this->assertSame('cafe-creme', Slug::make('Café Crème'));
    }

    public function test_trims_leading_and_trailing_separators(): void
    {
        $this->assertSame('tools', Slug::make('  --Tools--  '));
    }
}
