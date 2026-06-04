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
}
