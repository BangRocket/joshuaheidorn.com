<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\PortableText;
use PHPUnit\Framework\TestCase;

final class PortableTextTest extends TestCase
{
    private function block(string $style, array $children, array $extra = []): array
    {
        return array_merge(['_type' => 'block', 'style' => $style, 'children' => $children], $extra);
    }

    private function span(string $text, array $marks = []): array
    {
        return ['_type' => 'span', 'text' => $text, 'marks' => $marks];
    }

    public function test_paragraph_and_heading(): void
    {
        $blocks = [
            $this->block('normal', [$this->span('Hello world.')]),
            $this->block('h2', [$this->span('What survives')]),
        ];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("Hello world.\n\n## What survives\n", $md);
    }

    public function test_decorator_marks(): void
    {
        $blocks = [$this->block('normal', [
            $this->span('A '),
            $this->span('bold', ['strong']),
            $this->span(' and '),
            $this->span('italic', ['em']),
            $this->span(' and '),
            $this->span('code', ['code']),
            $this->span('.'),
        ])];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("A **bold** and _italic_ and `code`.\n", $md);
    }

    public function test_link_annotation(): void
    {
        $blocks = [$this->block('normal', [
            $this->span('See '),
            $this->span('the docs', ['link-1']),
        ], ['markDefs' => [['_key' => 'link-1', '_type' => 'link', 'href' => 'https://example.com']]])];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("See [the docs](https://example.com)\n", $md);
    }

    public function test_tight_bullet_list(): void
    {
        $blocks = [
            $this->block('normal', [$this->span('First item')], ['listItem' => 'bullet']),
            $this->block('normal', [$this->span('Second item')], ['listItem' => 'bullet']),
        ];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("- First item\n- Second item\n", $md);
    }
}
