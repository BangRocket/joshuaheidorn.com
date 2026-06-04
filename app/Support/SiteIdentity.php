<?php

declare(strict_types=1);

namespace App\Support;

final class SiteIdentity
{
    /** @param array<string,string|null> $settings setting_key => setting_value */
    public static function resolve(array $settings): array
    {
        return [
            'siteTitle' => $settings['site_title'] ?? 'joshuaheidorn.com',
            'siteTagline' => $settings['site_tagline'] ?? '',
        ];
    }
}
