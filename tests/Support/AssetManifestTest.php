<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\AssetManifest;
use PHPUnit\Framework\TestCase;

final class AssetManifestTest extends TestCase
{
    public function test_resolves_entry_to_hashed_url(): void
    {
        $dir = sys_get_temp_dir() . '/jh_manifest_' . uniqid();
        mkdir($dir . '/.vite', 0775, true);
        file_put_contents(
            $dir . '/.vite/manifest.json',
            json_encode([
                'islands/main.js' => ['file' => 'main-abc123.js', 'css' => ['main-def456.css']],
            ])
        );

        $manifest = new AssetManifest($dir, '/assets/');

        $this->assertSame('/assets/main-abc123.js', $manifest->js('islands/main.js'));
        $this->assertSame(['/assets/main-def456.css'], $manifest->css('islands/main.js'));
    }

    public function test_missing_manifest_returns_empty(): void
    {
        $manifest = new AssetManifest(sys_get_temp_dir() . '/does-not-exist', '/assets/');

        $this->assertSame('', $manifest->js('islands/main.js'));
        $this->assertSame([], $manifest->css('islands/main.js'));
    }

    public function test_versioned_appends_stable_content_hash(): void
    {
        $public = sys_get_temp_dir() . '/jh_public_' . uniqid();
        mkdir($public . '/css', 0775, true);
        file_put_contents($public . '/css/theme.css', 'body{}');

        $manifest = new AssetManifest($public . '/assets', '/assets/', $public);

        $url = $manifest->versioned('/css/theme.css');
        $this->assertMatchesRegularExpression('#^/css/theme\.css\?v=[0-9a-f]{10}$#', $url);

        // Unchanged file → identical query string (so the cache stays warm).
        $this->assertSame($url, $manifest->versioned('/css/theme.css'));

        // Changed content → different query string (so the cache busts).
        file_put_contents($public . '/css/theme.css', 'body{color:red}');
        $reloaded = new AssetManifest($public . '/assets', '/assets/', $public);
        $this->assertNotSame($url, $reloaded->versioned('/css/theme.css'));
    }

    public function test_versioned_passes_through_when_file_missing(): void
    {
        $manifest = new AssetManifest(sys_get_temp_dir() . '/nope/assets', '/assets/', sys_get_temp_dir() . '/nope');

        $this->assertSame('/css/missing.css', $manifest->versioned('/css/missing.css'));
    }
}
