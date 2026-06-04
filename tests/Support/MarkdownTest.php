<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function test_renders_headings_and_emphasis(): void
    {
        $html = (new Markdown())->toHtml("## Title\n\nSome **bold** and _italic_ text.");

        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function test_strips_raw_html(): void
    {
        $html = (new Markdown())->toHtml('Hello <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
    }
}
