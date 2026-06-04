<?php

declare(strict_types=1);

namespace App\Support;

final class Seo
{
    /**
     * @param array<string,mixed> $in keys: title, siteTitle, description?, url?, image?, type?, robots?
     * @return array<string,mixed>
     */
    public static function build(array $in): array
    {
        $title = (string) ($in['title'] ?? '');
        $siteTitle = (string) ($in['siteTitle'] ?? '');
        $fullTitle = ($siteTitle !== '' && !str_contains($title, $siteTitle))
            ? "{$title} — {$siteTitle}"
            : $title;

        return [
            'title' => $fullTitle,
            'description' => $in['description'] ?? null,
            'canonical' => $in['url'] ?? null,
            'image' => $in['image'] ?? null,
            'type' => $in['type'] ?? 'website',
            'robots' => $in['robots'] ?? null,
            'siteName' => $siteTitle,
            'publishedTime' => $in['publishedTime'] ?? null,
            'modifiedTime' => $in['modifiedTime'] ?? null,
        ];
    }
}
