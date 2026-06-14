<?php

declare(strict_types=1);

namespace App\Support;

final class AssetManifest
{
    private array $manifest;
    private string $publicDir;

    public function __construct(
        private string $assetsDir,
        private string $base = '/assets/',
        ?string $publicDir = null,
    ) {
        // The public web root is the parent of the Vite assets dir (public/assets).
        $this->publicDir = $publicDir ?? dirname($assetsDir);

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

    /**
     * Version a static public asset (e.g. "/css/theme.css") with a short
     * content hash. The hash is stable while the file is unchanged (cache-
     * friendly) and changes the moment the file does (cache-busting), so the
     * stable-URL plain CSS files invalidate the Cloudflare/browser cache on
     * every deploy without a manual purge. Returns the path unchanged if the
     * file is missing.
     */
    public function versioned(string $path): string
    {
        $file = $this->publicDir . $path;
        if (!is_file($file)) {
            return $path;
        }
        return $path . '?v=' . substr(md5_file($file) ?: '', 0, 10);
    }
}
