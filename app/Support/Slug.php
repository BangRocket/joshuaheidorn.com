<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

final class Slug
{
    public static function make(string $text): string
    {
        // Strip accents deterministically across platforms. macOS libiconv
        // renders "é" as "'e" under //TRANSLIT, so prefer NFD normalization +
        // removing combining marks; fall back to iconv where intl is absent.
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_D);
            if ($normalized !== false) {
                $text = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $text;
            }
        } else {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($ascii !== false) {
                $text = $ascii;
            }
        }

        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }
}
