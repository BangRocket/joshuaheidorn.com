<?php

declare(strict_types=1);

namespace App\Support;

final class ReadingTime
{
    private const WORDS_PER_MINUTE = 200;
    private const CJK_PER_MINUTE = 500;

    public static function minutes(string $markdown): int
    {
        // Strip the most common Markdown syntax to plain-ish text.
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/[#>*_`\[\]()!-]+/', ' ', $text) ?? $text;

        $cjkCount = preg_match_all('/\p{Han}|\p{Hangul}|\p{Hiragana}|\p{Katakana}/u', $text);
        $withoutCjk = preg_replace('/\p{Han}|\p{Hangul}|\p{Hiragana}|\p{Katakana}/u', ' ', $text) ?? $text;
        $wordCount = count(array_filter(preg_split('/\s+/', trim($withoutCjk)) ?: []));

        $minutes = (int) ceil($wordCount / self::WORDS_PER_MINUTE + $cjkCount / self::CJK_PER_MINUTE);

        return max(1, $minutes);
    }
}
