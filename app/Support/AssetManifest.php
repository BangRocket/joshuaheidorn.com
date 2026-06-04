<?php

declare(strict_types=1);

namespace App\Support;

final class AssetManifest
{
    private array $manifest;

    public function __construct(private string $assetsDir, private string $base = '/assets/')
    {
        $file = $this->assetsDir . '/.vite/manifest.json';
        $this->manifest = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?? [])
            : [];
    }

    public function js(string $entry): string
    {
        $file = $this->manifest[$entry]['file'] ?? null;
        return $file ? $this->base . $file : '';
    }

    /** @return array<int,string> */
    public function css(string $entry): array
    {
        $files = $this->manifest[$entry]['css'] ?? [];
        return array_map(fn ($f) => $this->base . $f, $files);
    }
}
