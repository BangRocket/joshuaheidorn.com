<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Seo;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    public function test_appends_site_title_when_missing(): void
    {
        $meta = Seo::build([
            'title' => 'About',
            'siteTitle' => 'Joshua Heidorn',
            'description' => 'A page',
            'url' => 'https://example.com/pages/about',
        ]);

        $this->assertSame('About — Joshua Heidorn', $meta['title']);
        $this->assertSame('A page', $meta['description']);
        $this->assertSame('https://example.com/pages/about', $meta['canonical']);
        $this->assertSame('website', $meta['type']);
    }

    public function test_keeps_title_that_already_has_site_title(): void
    {
        $meta = Seo::build(['title' => 'Joshua Heidorn', 'siteTitle' => 'Joshua Heidorn']);
        $this->assertSame('Joshua Heidorn', $meta['title']);
    }
}
