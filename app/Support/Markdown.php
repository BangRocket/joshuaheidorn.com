<?php

declare(strict_types=1);

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

final class Markdown
{
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function toHtml(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }
}
