# PHP + Svelte Rewrite — Plan 2: Public Site Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the full public site from PHP (Slim 4 + Twig) against the MySQL content created in Plan 1, reproducing the current appearance and URLs, with two Svelte islands (typewriter, live search) and on-demand PDF resume export.

**Architecture:** `public/index.php` runs a Slim app built in `app/bootstrap.php`. Controllers pull from PDO repositories and render Twig templates ported from the Astro pages. Body Markdown is rendered with the Plan 1 `Markdown` helper. The visual look is reproduced by porting the existing CSS verbatim into static stylesheets. Svelte islands are compiled by Vite to `public/assets/`; a manifest helper emits hashed tags. PDF export renders a print template through Dompdf.

**Tech Stack:** PHP 8.x, Slim 4, slim/twig-view (Twig 3), league/commonmark (from Plan 1), dompdf/dompdf, Vite + Svelte 5. MySQL via the Plan 1 schema/importer.

**Spec:** `docs/superpowers/specs/2026-06-04-php-svelte-rewrite-design.md`
**Depends on:** Plan 1 (Foundation) — schema, importer, `App\Support\{Database,Slug,Markdown}` all present; dev DB `joshuaheidorn` populated.

---

## Prerequisites

- Node + Yarn available locally (the repo already used them for Astro). Verify: `node -v && yarn -v`.
- Docker MySQL running (`docker compose up -d`), dev DB populated (`php bin/seed.php`).
- All PHP commands run on the host (Homebrew PHP 8.5). MySQL CLI via `docker compose exec -T db mysql -uroot ...`.
- Note for this harness: prefix `php`/`composer`/`yarn`/`vendor/bin/*` Bash calls with `dangerouslyDisableSandbox: true`.

## Conventions for porting tasks

Several tasks "port" an existing Astro file. That means:
1. Read the source `src/...astro`.
2. Recreate its **markup** as a Twig template under `app/views/`, replacing Astro expressions with Twig (`{{ }}` / `{% %}`) and the data the controller passes.
3. Move its scoped `<style>` block **verbatim** into the matching CSS file under `public/assets/css/` (class names are already unique; do not rename).
4. Replace EmDash-only bits per the spec: drop `bylines`, `Comments`, `WidgetArea`; `<Image>` becomes a plain `<img src=... alt=...>` using the media row's `path`; `getEntryTerms`/`getMenu`/`getSiteSettings` become repository calls.

## File Structure (created by this plan)

```
package.json                      # REPLACED: Vite + Svelte (build-time only)
vite.config.js
islands/
  main.js                         # hydrates [data-island] elements
  Typewriter.svelte
  Search.svelte
public/
  index.php                       # Slim front controller
  .htaccess                       # Apache rewrite to index.php
  assets/                         # Vite output (gitignored) + css/ (tracked)
    css/{theme,base,layout,pages,article}.css
app/
  bootstrap.php                   # builds the Slim app (container, Twig, routes)
  Support/
    AssetManifest.php             # reads Vite manifest -> hashed asset URLs
    ReadingTime.php               # minutes from Markdown
    SiteIdentity.php              # site title/tagline from settings
    Seo.php                       # builds <head> meta arrays
  Repositories/
    PostRepository.php
    ProjectRepository.php
    PageRepository.php
    TermRepository.php
    ResumeRepository.php
    SettingsRepository.php
    SearchRepository.php
  Controllers/
    HomeController.php  ResumeController.php  PostController.php
    ProjectController.php  PageController.php  TaxonomyController.php
    SearchController.php  FeedController.php  SitemapController.php
    PdfController.php  ErrorController.php
  views/
    layout.twig  partials/head.twig
    home.twig  resume.twig  resume_pdf.twig
    posts/index.twig  posts/show.twig
    projects/index.twig  projects/show.twig
    pages/show.twig  taxonomy.twig  search.twig  404.twig
tests/
  Support/{ReadingTimeTest,SeoTest}.php
  Repositories/PostRepositoryTest.php
  Http/RouteSmokeTest.php
```

---

## Task 1: JavaScript toolchain (Vite + Svelte)

**Files:**
- Replace: `package.json`
- Create: `vite.config.js`

- [ ] **Step 1: Replace `package.json`**

```json
{
    "name": "joshuaheidorn",
    "version": "0.1.0",
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "vite build"
    },
    "devDependencies": {
        "@sveltejs/vite-plugin-svelte": "^5.0.0",
        "svelte": "^5.0.0",
        "vite": "^6.0.0"
    }
}
```

- [ ] **Step 2: Create `vite.config.js`**

```js
import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
    plugins: [svelte({ emitCss: false })],
    base: "/assets/",
    build: {
        manifest: true,
        outDir: "public/assets",
        emptyOutDir: true,
        rollupOptions: {
            input: "islands/main.js",
        },
    },
});
```

- [ ] **Step 3: Install JS deps**

Run: `yarn install`
Expected: `node_modules/` populated, no errors.

- [ ] **Step 4: Commit**

```bash
git add package.json yarn.lock vite.config.js
git commit -m "Replace Astro toolchain with Vite + Svelte"
```

---

## Task 2: Svelte islands

The mount script finds `[data-island="<name>"]` elements and hydrates the matching component, reading props from `data-props` (JSON).

**Files:**
- Create: `islands/main.js`, `islands/Typewriter.svelte`, `islands/Search.svelte`

- [ ] **Step 1: Create `islands/Typewriter.svelte`** (Svelte 5 runes; ports `TypewriterHeadline.tsx`)

```svelte
<script>
    let { phrases = [], typeMs = 60, deleteMs = 35, holdMs = 1800, gapMs = 400 } = $props();

    let text = $state(phrases[0] ?? "");
    let phraseIdx = $state(0);
    let phase = $state("hold"); // hold | deleting | typing

    $effect(() => {
        if (!phrases.length) return;
        const current = phrases[phraseIdx];
        let timer;

        if (phase === "hold") {
            timer = setTimeout(() => (phase = "deleting"), holdMs);
        } else if (phase === "deleting") {
            if (text.length === 0) {
                timer = setTimeout(() => {
                    phraseIdx = (phraseIdx + 1) % phrases.length;
                    phase = "typing";
                }, gapMs);
            } else {
                timer = setTimeout(() => (text = text.slice(0, -1)), deleteMs);
            }
        } else if (phase === "typing") {
            if (text.length === current.length) {
                phase = "hold";
            } else {
                timer = setTimeout(() => (text = current.slice(0, text.length + 1)), typeMs);
            }
        }

        return () => clearTimeout(timer);
    });
</script>

{#if phrases.length}
    <span class="typewriter" aria-live="polite" aria-label={phrases[phraseIdx]}>
        <span>{text}</span>
        <span class="typewriter-caret" aria-hidden="true">|</span>
    </span>
{/if}
```

- [ ] **Step 2: Create `islands/Search.svelte`** (replaces EmDash LiveSearch; same class names from `Base.astro`)

```svelte
<script>
    let { placeholder = "Search...", collections = ["posts", "projects", "pages"] } = $props();

    let query = $state("");
    let results = $state([]);
    let open = $state(false);
    let loading = $state(false);
    let timer;

    function onInput() {
        clearTimeout(timer);
        const q = query.trim();
        if (q.length < 2) {
            results = [];
            open = false;
            return;
        }
        loading = true;
        open = true;
        timer = setTimeout(async () => {
            const params = new URLSearchParams({ q });
            collections.forEach((c) => params.append("in[]", c));
            const res = await fetch(`/api/search?${params}`);
            results = res.ok ? await res.json() : [];
            loading = false;
        }, 180);
    }
</script>

<div class="site-search">
    <input
        class="site-search-input"
        type="search"
        {placeholder}
        bind:value={query}
        oninput={onInput}
        onfocus={() => { if (results.length) open = true; }}
    />
    {#if open}
        <div class="site-search-results">
            {#if loading}
                <div class="emdash-live-search-loading">Searching…</div>
            {:else if results.length === 0}
                <div class="emdash-live-search-no-results">No results</div>
            {:else}
                {#each results as r}
                    <a class="site-search-result" href={r.url}>
                        <span class="emdash-live-search-result-title">{r.title}</span>
                        <span class="emdash-live-search-result-collection">{r.collection}</span>
                        {#if r.snippet}
                            <span class="emdash-live-search-result-snippet">{r.snippet}</span>
                        {/if}
                    </a>
                {/each}
            {/if}
        </div>
    {/if}
</div>
```

- [ ] **Step 3: Create `islands/main.js`**

```js
import { mount } from "svelte";
import Typewriter from "./Typewriter.svelte";
import Search from "./Search.svelte";

const REGISTRY = { Typewriter, Search };

for (const el of document.querySelectorAll("[data-island]")) {
    const Component = REGISTRY[el.dataset.island];
    if (!Component) continue;
    const props = el.dataset.props ? JSON.parse(el.dataset.props) : {};
    mount(Component, { target: el, props });
}
```

- [ ] **Step 4: Build and verify the manifest**

Run: `yarn build && cat public/assets/.vite/manifest.json | head -20`
Expected: build succeeds; manifest JSON maps `islands/main.js` to a hashed file under `assets/`.

- [ ] **Step 5: Commit**

```bash
git add islands package.json
git commit -m "Add Typewriter + Search Svelte islands"
```

---

## Task 3: PHP web dependencies

**Files:**
- Modify: `composer.json` (via composer require)

- [ ] **Step 1: Require Slim, Twig view, Dompdf**

Run:
```bash
composer require slim/slim:"^4.14" slim/psr7:"^1.7" slim/twig-view:"^3.4" dompdf/dompdf:"^3.0"
```
Expected: installs without error; `composer.json` updated.

- [ ] **Step 2: Commit**

```bash
git add composer.json composer.lock
git commit -m "Add Slim, Twig view, and Dompdf"
```

---

## Task 4: Slim bootstrap + asset manifest

**Files:**
- Create: `public/index.php`, `public/.htaccess`, `app/bootstrap.php`, `app/Support/AssetManifest.php`, `tests/Support/AssetManifestTest.php`

- [ ] **Step 1: Write the failing test — `tests/Support/AssetManifestTest.php`**

```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter AssetManifestTest`
Expected: FAIL — `Class "App\Support\AssetManifest" not found`.

- [ ] **Step 3: Write `app/Support/AssetManifest.php`**

```php
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
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter AssetManifestTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Write `app/bootstrap.php`**

```php
<?php

declare(strict_types=1);

use App\Support\AssetManifest;
use App\Support\Database;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/config.php';

$pdo = Database::connect($config['db']);
$twig = Twig::create(__DIR__ . '/views', ['cache' => false, 'autoescape' => 'html']);
$assets = new AssetManifest($config['paths']['base'] . '/public/assets');

// Expose shared values to all templates.
$twig->getEnvironment()->addGlobal('assets', $assets);

$app = AppFactory::create();
$app->add(TwigMiddleware::create($app, $twig));

(require __DIR__ . '/routes.php')($app, $pdo, $twig);

return $app;
```

- [ ] **Step 6: Write a minimal `app/routes.php`** (expanded in later tasks)

```php
<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

return function ($app, \PDO $pdo, Twig $twig): void {
    $app->get('/health', function (Request $request, Response $response) {
        $response->getBody()->write('ok');
        return $response;
    });
};
```

- [ ] **Step 7: Write `public/index.php`**

```php
<?php

declare(strict_types=1);

/** @var Slim\App $app */
$app = require __DIR__ . '/../app/bootstrap.php';
$app->run();
```

- [ ] **Step 8: Write `public/.htaccess`**

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.php [QSA,L]
```

- [ ] **Step 9: Verify the app boots via PHP's built-in server**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
curl -s http://127.0.0.1:8088/health
kill %1
```
Expected: prints `ok`.

- [ ] **Step 10: Commit**

```bash
git add app/bootstrap.php app/routes.php app/Support/AssetManifest.php public/index.php public/.htaccess tests/Support/AssetManifestTest.php
git commit -m "Add Slim bootstrap, routing skeleton, and asset manifest"
```

---

## Task 5: Support ports — ReadingTime, SiteIdentity, Seo

**Files:**
- Create: `app/Support/ReadingTime.php`, `app/Support/SiteIdentity.php`, `app/Support/Seo.php`, `tests/Support/ReadingTimeTest.php`, `tests/Support/SeoTest.php`

- [ ] **Step 1: Write the failing test — `tests/Support/ReadingTimeTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ReadingTime;
use PHPUnit\Framework\TestCase;

final class ReadingTimeTest extends TestCase
{
    public function test_minimum_one_minute(): void
    {
        $this->assertSame(1, ReadingTime::minutes('Short text.'));
    }

    public function test_scales_with_word_count(): void
    {
        $words = str_repeat('word ', 400); // 400 words ~ 2 min @ 200 wpm
        $this->assertSame(2, ReadingTime::minutes($words));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter ReadingTimeTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `app/Support/ReadingTime.php`** (Markdown-based; ports the 200 wpm + CJK logic from `src/utils/reading-time.ts`)

```php
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
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit --filter ReadingTimeTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Write `app/Support/SiteIdentity.php`** (ports `site-identity.ts`)

```php
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
```

- [ ] **Step 6: Write the failing test — `tests/Support/SeoTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Seo;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    public function test_appends_site_title_when_missing(): void
    {
        $meta = Seo::build([
            'title' => 'About',
            'siteTitle' => 'Joshua Heidorn',
            'description' => 'A page',
            'url' => 'https://example.com/pages/about',
        ]);

        $this->assertSame('About — Joshua Heidorn', $meta['title']);
        $this->assertSame('A page', $meta['description']);
        $this->assertSame('https://example.com/pages/about', $meta['canonical']);
        $this->assertSame('website', $meta['type']);
    }

    public function test_keeps_title_that_already_has_site_title(): void
    {
        $meta = Seo::build(['title' => 'Joshua Heidorn', 'siteTitle' => 'Joshua Heidorn']);
        $this->assertSame('Joshua Heidorn', $meta['title']);
    }
}
```

- [ ] **Step 7: Run to verify it fails**

Run: `vendor/bin/phpunit --filter SeoTest`
Expected: FAIL — class not found.

- [ ] **Step 8: Write `app/Support/Seo.php`**

```php
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
```

- [ ] **Step 9: Run to verify it passes**

Run: `vendor/bin/phpunit --filter SeoTest`
Expected: PASS (2 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Support/ReadingTime.php app/Support/SiteIdentity.php app/Support/Seo.php tests/Support/ReadingTimeTest.php tests/Support/SeoTest.php
git commit -m "Port reading-time, site-identity, and add SEO meta helper"
```

---

## Task 6: Repositories

All repositories take a `PDO` and return associative arrays. "Published" means `status = 'published'`. Slugs are unique. Each adds a JSON-decoding step where the schema stores JSON (`resume_meta.contact`, `experience.bullets/tags`, `resume_meta.headlines`).

**Files:**
- Create: `app/Repositories/{Post,Project,Page,Term,Resume,Settings,Search}Repository.php`
- Create: `tests/Repositories/PostRepositoryTest.php`

- [ ] **Step 1: Write `app/Repositories/PostRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PostRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function published(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, m.path AS image_path, m.alt AS image_alt
             FROM posts p
             LEFT JOIN media m ON m.id = p.featured_image_id
             WHERE p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, m.path AS image_path, m.alt AS image_alt
             FROM posts p
             LEFT JOIN media m ON m.id = p.featured_image_id
             WHERE p.slug = :slug AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
```

- [ ] **Step 2: Write `app/Repositories/ProjectRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProjectRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function published(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, m.path AS image_path, m.alt AS image_alt
             FROM projects p
             LEFT JOIN media m ON m.id = p.featured_image_id
             WHERE p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function featured(int $limit = 3): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, m.path AS image_path, m.alt AS image_alt
             FROM projects p
             LEFT JOIN media m ON m.id = p.featured_image_id
             WHERE p.status = 'published' AND p.featured = 1
             ORDER BY p.published_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, m.path AS image_path, m.alt AS image_alt
             FROM projects p
             LEFT JOIN media m ON m.id = p.featured_image_id
             WHERE p.slug = :slug AND p.status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
```

- [ ] **Step 3: Write `app/Repositories/PageRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PageRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function published(): array
    {
        return $this->pdo
            ->query("SELECT * FROM pages WHERE status = 'published' ORDER BY title ASC")
            ->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
```

- [ ] **Step 4: Write `app/Repositories/TermRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TermRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Terms attached to one content item. @return array<int,array<string,mixed>> */
    public function forContent(string $taxonomy, string $type, int $contentId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.slug, t.label
             FROM term_relationships tr
             JOIN terms t ON t.id = tr.term_id
             WHERE t.taxonomy = :tax AND tr.content_type = :type AND tr.content_id = :cid
             ORDER BY t.label"
        );
        $stmt->execute(['tax' => $taxonomy, 'type' => $type, 'cid' => $contentId]);
        return $stmt->fetchAll();
    }

    public function findTerm(string $taxonomy, string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, label FROM terms WHERE taxonomy = :tax AND slug = :slug LIMIT 1'
        );
        $stmt->execute(['tax' => $taxonomy, 'slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Published content of a type tagged with a term. @return array<int,array<string,mixed>> */
    public function contentForTerm(int $termId, string $type, string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, m.path AS image_path, m.alt AS image_alt
             FROM term_relationships tr
             JOIN `{$table}` c ON c.id = tr.content_id
             LEFT JOIN media m ON m.id = c.featured_image_id
             WHERE tr.term_id = :tid AND tr.content_type = :type AND c.status = 'published'
             ORDER BY c.published_at DESC"
        );
        $stmt->execute(['tid' => $termId, 'type' => $type]);
        return $stmt->fetchAll();
    }
}
```

> Note: `$table` is never user input — controllers pass the literal `'posts'` or `'projects'`. The interpolation is safe.

- [ ] **Step 5: Write `app/Repositories/ResumeRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ResumeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function meta(): array
    {
        $row = $this->pdo->query('SELECT * FROM resume_meta LIMIT 1')->fetch();
        if (!$row) {
            return ['name' => '', 'headline' => '', 'headlines' => [], 'summary' => '', 'contact' => []];
        }
        $row['headlines'] = json_decode((string) ($row['headlines'] ?? '[]'), true) ?: [];
        $row['contact'] = json_decode((string) ($row['contact'] ?? '{}'), true) ?: [];
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function experience(): array
    {
        $rows = $this->pdo->query('SELECT * FROM experience ORDER BY sort ASC')->fetchAll();
        foreach ($rows as &$r) {
            $r['bullets'] = json_decode((string) ($r['bullets'] ?? '[]'), true) ?: [];
            $r['tags'] = json_decode((string) ($r['tags'] ?? '[]'), true) ?: [];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function education(): array
    {
        return $this->pdo->query('SELECT * FROM education ORDER BY sort ASC')->fetchAll();
    }

    /** @return array<int,array<string,mixed>> categories each with an `items` array */
    public function skills(): array
    {
        $cats = $this->pdo->query('SELECT * FROM skill_categories ORDER BY sort ASC')->fetchAll();
        $stmt = $this->pdo->prepare('SELECT * FROM skills WHERE category_id = :cid ORDER BY sort ASC');
        foreach ($cats as &$cat) {
            $stmt->execute(['cid' => $cat['id']]);
            $cat['items'] = $stmt->fetchAll();
        }
        return $cats;
    }
}
```

- [ ] **Step 6: Write `app/Repositories/SettingsRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,string|null> setting_key => setting_value */
    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function menu(): array
    {
        return $this->pdo->query('SELECT label, url, target FROM menu_items ORDER BY sort ASC')->fetchAll();
    }
}
```

- [ ] **Step 7: Write `app/Repositories/SearchRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SearchRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<int,string> $collections subset of posts|projects|pages
     * @return array<int,array{title:string,collection:string,url:string,snippet:string}>
     */
    public function search(string $query, array $collections): array
    {
        $like = '%' . $query . '%';
        $out = [];

        $sources = [
            'posts' => ['table' => 'posts', 'urlPrefix' => '/posts/', 'body' => 'body_md'],
            'projects' => ['table' => 'projects', 'urlPrefix' => '/projects/', 'body' => 'body_md'],
            'pages' => ['table' => 'pages', 'urlPrefix' => '/pages/', 'body' => 'body_md'],
        ];

        foreach ($collections as $collection) {
            if (!isset($sources[$collection])) {
                continue;
            }
            $s = $sources[$collection];
            $stmt = $this->pdo->prepare(
                "SELECT title, slug, {$s['body']} AS body
                 FROM `{$s['table']}`
                 WHERE status = 'published' AND (title LIKE :q1 OR {$s['body']} LIKE :q2)
                 ORDER BY published_at DESC
                 LIMIT 5"
            );
            $stmt->execute(['q1' => $like, 'q2' => $like]);
            foreach ($stmt->fetchAll() as $row) {
                $out[] = [
                    'title' => $row['title'],
                    'collection' => $collection,
                    'url' => $s['urlPrefix'] . $row['slug'],
                    'snippet' => mb_substr(trim(strip_tags((string) $row['body'])), 0, 120),
                ];
            }
        }

        return $out;
    }
}
```

- [ ] **Step 8: Write the test — `tests/Repositories/PostRepositoryTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\PostRepository;
use App\Support\Importer;
use Tests\DatabaseTestCase;

final class PostRepositoryTest extends DatabaseTestCase
{
    private function seed(): void
    {
        $root = dirname(__DIR__, 2);
        (new Importer($this->pdo, [
            'base' => $root,
            'uploads_src' => $root . '/uploads',
            'uploads_dest' => sys_get_temp_dir() . '/jh_uploads_test',
        ]))->run();
    }

    public function test_published_returns_posts_newest_first(): void
    {
        $this->seed();
        $repo = new PostRepository($this->pdo);

        $posts = $repo->published();

        $this->assertCount(8, $posts);
        $this->assertGreaterThanOrEqual(
            strtotime((string) $posts[1]['published_at']),
            strtotime((string) $posts[0]['published_at'])
        );
    }

    public function test_find_by_slug_returns_one_post(): void
    {
        $this->seed();
        $repo = new PostRepository($this->pdo);

        $post = $repo->findBySlug('building-for-the-long-term');

        $this->assertNotNull($post);
        $this->assertSame('Building for the Long Term', $post['title']);
    }
}
```

- [ ] **Step 9: Run the repository test**

Run: `vendor/bin/phpunit --filter PostRepositoryTest`
Expected: PASS (2 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Repositories tests/Repositories/PostRepositoryTest.php
git commit -m "Add PDO repositories for content, terms, resume, settings, search"
```

---

## Task 7: Layout + CSS port

**Files:**
- Create: `app/views/layout.twig`, `app/views/partials/head.twig`
- Create: `public/assets/css/theme.css`, `public/assets/css/base.css`, `public/assets/css/layout.css`
- Modify: `.gitignore` (track `public/assets/css` even though `public/assets` build output is ignored)

- [ ] **Step 1: Allow CSS to be tracked under the ignored assets dir**

Append to `.gitignore`:
```gitignore
!/public/assets/css
```
And ensure the rule order keeps `/public/assets` ignored but re-includes `css/`. If the negation does not take effect (because the parent dir is ignored), instead place CSS at `public/css/` and reference `/css/...` in templates. Use `public/css/` to avoid gitignore edge cases.

> **Decision:** put hand-written CSS at `public/css/` (tracked), keep Vite output at `public/assets/` (ignored). Update paths below accordingly.

- [ ] **Step 2: Create `public/css/theme.css`**

Copy `src/styles/theme.css` **verbatim** (the palette + dark-mode custom properties). It is plain CSS and needs no changes.

- [ ] **Step 3: Create `public/css/base.css`**

Copy the contents of the `@layer base { ... }` block from `src/layouts/Base.astro`'s `<style is:global>` (the reset, `:root` type scale/spacing tokens, base typography, `body`, links, headings, `::selection`). Omit the `@layer base;` wrapper — paste the rules directly. Do **not** include the `--color-*` palette already provided by `theme.css`; keep the type scale, spacing, radius, transitions, nav-height, etc.

- [ ] **Step 4: Create `public/css/layout.css`**

Copy the two non-global `<style>` blocks from `src/layouts/Base.astro` (the `.site-header`/`.nav`/`.site-search*`/`.site-footer`/`.theme-switcher` rules and the responsive `@media` blocks) verbatim.

- [ ] **Step 5: Create `app/views/partials/head.twig`**

```twig
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>{{ seo.title }}</title>
{% if seo.description %}<meta name="description" content="{{ seo.description }}" />{% endif %}
{% if seo.canonical %}<link rel="canonical" href="{{ seo.canonical }}" />{% endif %}
{% if seo.robots %}<meta name="robots" content="{{ seo.robots }}" />{% endif %}
<meta property="og:title" content="{{ seo.title }}" />
{% if seo.description %}<meta property="og:description" content="{{ seo.description }}" />{% endif %}
<meta property="og:type" content="{{ seo.type }}" />
{% if seo.canonical %}<meta property="og:url" content="{{ seo.canonical }}" />{% endif %}
{% if seo.image %}<meta property="og:image" content="{{ seo.image }}" />{% endif %}
<meta name="twitter:card" content="summary_large_image" />
{% if seo.type == 'article' and seo.publishedTime %}<meta property="article:published_time" content="{{ seo.publishedTime }}" />{% endif %}
{% if seo.type == 'article' and seo.modifiedTime %}<meta property="article:modified_time" content="{{ seo.modifiedTime }}" />{% endif %}
<link rel="stylesheet" href="/css/theme.css" />
<link rel="stylesheet" href="/css/base.css" />
<link rel="stylesheet" href="/css/layout.css" />
<link rel="stylesheet" href="/css/pages.css" />
<link rel="stylesheet" href="/css/article.css" />
{% for href in assets.css('islands/main.js') %}<link rel="stylesheet" href="{{ href }}" />{% endfor %}
```

- [ ] **Step 6: Create `app/views/layout.twig`** (ports `Base.astro` chrome; theme + ⌘K scripts ported verbatim as inline `<script>`; Search island mounted via `data-island`)

```twig
<!doctype html>
<html lang="en">
<head>
    {% include 'partials/head.twig' %}
    <script>
        (function () {
            var c = document.cookie, i = c.indexOf("theme=");
            var theme = i >= 0 ? c.slice(i + 6).split(";")[0] : null;
            if (theme === "dark" || theme === "light") document.documentElement.classList.add(theme);
            else if (window.matchMedia("(prefers-color-scheme: dark)").matches) document.documentElement.classList.add("dark");
        })();
    </script>
</head>
<body>
    <header class="site-header">
        <nav class="nav">
            <a href="/" class="site-title">{{ site.siteTitle }}</a>
            <div class="nav-right">
                <div data-island="Search" data-props='{{ {"placeholder":"Search...","collections":["posts","projects","pages"]}|json_encode }}'></div>
                <div class="nav-links">
                    {% for item in menu %}<a href="{{ item.url }}"{% if item.target %} target="{{ item.target }}"{% endif %}>{{ item.label }}</a>{% endfor %}
                </div>
            </div>
        </nav>
    </header>

    <main>{% block content %}{% endblock %}</main>

    <footer class="site-footer">
        <div class="footer-inner">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="/" class="footer-logo">{{ site.siteTitle }}</a>
                    <p class="footer-tagline">{{ site.siteTagline }}</p>
                </div>
                <div class="footer-nav">
                    <h4 class="footer-heading">Navigate</h4>
                    <ul class="footer-links">
                        <li><a href="/">Home</a></li>
                        <li><a href="/posts">All Posts</a></li>
                        {% for page in pages|slice(0, 3) %}<li><a href="/pages/{{ page.slug }}">{{ page.title }}</a></li>{% endfor %}
                    </ul>
                </div>
                <div class="footer-nav">
                    <h4 class="footer-heading">Connect</h4>
                    <ul class="footer-links">
                        {% for item in menu %}<li><a href="{{ item.url }}"{% if item.target %} target="{{ item.target }}" rel="noopener noreferrer"{% endif %}>{{ item.label }}</a></li>{% endfor %}
                        <li><a href="/posts/rss.xml">Posts RSS</a></li>
                        <li><a href="/projects/rss.xml">Projects RSS</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p class="footer-copyright">© {{ "now"|date("Y") }} {{ site.siteTitle }}</p>
                <div class="theme-switcher">
                    <button type="button" class="theme-btn" data-theme="light" aria-label="Light mode">☀</button>
                    <button type="button" class="theme-btn" data-theme="dark" aria-label="Dark mode">☾</button>
                    <button type="button" class="theme-btn" data-theme="system" aria-label="System theme">◐</button>
                </div>
            </div>
        </div>
    </footer>

    <script>
        {# Theme switcher — ported verbatim from Base.astro #}
        (function () {
            var THEME_REGEX = /theme=([^;]+)/;
            var btns = document.querySelectorAll(".theme-btn"), root = document.documentElement;
            function setCookie(n, v, age) { age = age || 31536000; var s = location.protocol === "https:" ? "; Secure" : ""; document.cookie = v === "" ? n + "=; path=/; max-age=0; SameSite=Lax" + s : n + "=" + v + "; path=/; max-age=" + age + "; SameSite=Lax" + s; }
            function active(t) { btns.forEach(function (b) { b.classList.toggle("active", b.dataset.theme === t); }); }
            function setTheme(t) { if (t === "system") { setCookie("theme", ""); root.classList.remove("light", "dark"); if (window.matchMedia("(prefers-color-scheme: dark)").matches) root.classList.add("dark"); } else { setCookie("theme", t); root.classList.remove("light", "dark"); root.classList.add(t); } active(t); }
            function stored() { var m = document.cookie.match(THEME_REGEX); return m ? m[1] : "system"; }
            setTheme(stored());
            btns.forEach(function (b) { b.addEventListener("click", function () { setTheme(b.dataset.theme || "system"); }); });
            window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function (e) { if (stored() === "system") root.classList.toggle("dark", e.matches); });
        })();
        {# Cmd/Ctrl+K focuses search #}
        document.addEventListener("keydown", function (e) { if ((e.metaKey || e.ctrlKey) && e.key === "k") { e.preventDefault(); var i = document.querySelector(".site-search-input"); if (i) i.focus(); } });
    </script>
    {% if assets.js('islands/main.js') %}<script type="module" src="{{ assets.js('islands/main.js') }}"></script>{% endif %}
    {% block scripts %}{% endblock %}
</body>
</html>
```

- [ ] **Step 7: Create empty CSS placeholders** so the `<link>`s resolve before later tasks fill them:

```bash
mkdir -p public/css
touch public/css/pages.css public/css/article.css
```

- [ ] **Step 8: Commit**

```bash
git add app/views/layout.twig app/views/partials/head.twig public/css .gitignore
git commit -m "Add layout + head templates and port site CSS"
```

---

## Task 8: Home + Resume pages

The Twig environment needs `site`, `menu`, `pages`, and `seo` on most renders. Add a tiny helper so controllers don't repeat themselves.

**Files:**
- Create: `app/Controllers/HomeController.php`, `app/Controllers/ResumeController.php`
- Create: `app/views/home.twig`, `app/views/resume.twig`
- Modify: `app/routes.php`, `public/css/pages.css`

- [ ] **Step 1: Write `app/Controllers/HomeController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ResumeRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HomeController
{
    public function __construct(
        private Twig $twig,
        private ResumeRepository $resume,
        private PostRepository $posts,
        private ProjectRepository $projects,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $settings = $this->settings->all();
        $site = SiteIdentity::resolve($settings);
        $meta = $this->resume->meta();

        $tiles = [
            ['href' => '/resume', 'label' => 'Resume', 'hint' => 'Experience & skills'],
            ['href' => '/projects', 'label' => 'Projects', 'hint' => 'Selected work'],
            ['href' => '/posts', 'label' => 'Blog', 'hint' => 'Notes & writing'],
            ['href' => ($meta['contact']['email'] ?? '') ? 'mailto:' . $meta['contact']['email'] : '#',
             'label' => 'Contact', 'hint' => $meta['contact']['email'] ?? 'Get in touch'],
        ];

        return $this->twig->render($response, 'home.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build([
                'title' => $meta['name'], 'siteTitle' => $site['siteTitle'],
                'description' => $meta['summary'] ?? $site['siteTagline'],
                'url' => (string) $request->getUri(),
            ]),
            'resume' => $meta,
            'tiles' => $tiles,
            'featuredProjects' => $this->projects->featured(3),
            'recentPosts' => array_slice($this->posts->published(), 0, 3),
        ]);
    }
}
```

- [ ] **Step 2: Create `app/views/home.twig`**

Port `src/pages/index.astro`'s markup into `{% extends 'layout.twig' %}` + `{% block content %}`. Map: `resume.name`, the typewriter via `<p class="headline"><span data-island="Typewriter" data-props='{{ {"phrases": resume.headlines}|json_encode }}'></span></p>` (fallback to `resume.headline` when `resume.headlines` is empty), `resume.summary`, the `tiles` loop, `featuredProjects` loop (`/projects/{{ p.slug }}`, `p.title`, `p.summary`), and `recentPosts` loop (`/posts/{{ post.slug }}`, `post.title`, `post.published_at|date('M j, Y')`, `post.excerpt`). Move `index.astro`'s `<style>` block verbatim into `public/css/pages.css`.

- [ ] **Step 3: Write `app/Controllers/ResumeController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\ResumeRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ResumeController
{
    public function __construct(
        private Twig $twig,
        private ResumeRepository $resume,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $meta = $this->resume->meta();

        return $this->twig->render($response, 'resume.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build([
                'title' => 'Resume · ' . $meta['name'],
                'siteTitle' => $site['siteTitle'],
                'description' => $meta['headline'] ?? null,
                'url' => (string) $request->getUri(),
            ]),
            'resume' => $meta,
            'experience' => $this->resume->experience(),
            'education' => $this->resume->education(),
            'skills' => $this->resume->skills(),
        ]);
    }
}
```

- [ ] **Step 4: Create `app/views/resume.twig`**

Port `src/pages/resume.astro` markup into Twig. Map `resume.name`, `resume.headline`, `resume.summary`, `resume.contact.email`/`.location`/`.links`, `experience` (with `formatRange(start,end)` rendered inline as `{{ job.start }}{% if job.end %} – {{ job.end }}{% endif %}`), `job.bullets`, `job.tags`, `education`, and `skills` (categories → `cat.items` → `item.name`). Drop the `_placeholder` banner. Move `resume.astro`'s `<style>` block verbatim into `public/css/pages.css`.

- [ ] **Step 5: Expand `app/routes.php`** — replace the file with the wiring for all controllers (full version; later tasks reference the same closures):

```php
<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\ResumeController;
use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ResumeRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Repositories\SearchRepository;
use Slim\Views\Twig;

return function ($app, \PDO $pdo, Twig $twig): void {
    $posts = new PostRepository($pdo);
    $projects = new ProjectRepository($pdo);
    $pages = new PageRepository($pdo);
    $terms = new TermRepository($pdo);
    $resume = new ResumeRepository($pdo);
    $settings = new SettingsRepository($pdo);
    $search = new SearchRepository($pdo);

    $home = new HomeController($twig, $resume, $posts, $projects, $settings, $pages);
    $resumeCtrl = new ResumeController($twig, $resume, $settings, $pages);

    $app->get('/', [$home, 'index']);
    $app->get('/resume', [$resumeCtrl, 'show']);

    // Routes for posts/projects/pages/taxonomy/search/feeds/sitemap/pdf/404 are
    // added in Tasks 9-14 — wire each controller here as it is built.
};
```

- [ ] **Step 6: Build islands then verify home + resume render**

Run:
```bash
yarn build
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
echo "HOME:"; curl -s http://127.0.0.1:8088/ | grep -o '<h1 class="name">[^<]*' | head -1
echo "RESUME:"; curl -s http://127.0.0.1:8088/resume | grep -o 'class="resume-name"' | head -1
kill %1
```
Expected: HOME shows the name heading; RESUME shows `class="resume-name"`.

- [ ] **Step 7: Commit**

```bash
git add app/Controllers/HomeController.php app/Controllers/ResumeController.php app/views/home.twig app/views/resume.twig app/routes.php public/css/pages.css
git commit -m "Add home + resume pages"
```

---

## Task 9: Posts index, post detail, posts RSS

**Files:**
- Create: `app/Controllers/PostController.php`, `app/Controllers/FeedController.php`
- Create: `app/views/posts/index.twig`, `app/views/posts/show.twig`
- Modify: `app/routes.php`, `public/css/pages.css`, `public/css/article.css`

- [ ] **Step 1: Write `app/Controllers/PostController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Support\Markdown;
use App\Support\ReadingTime;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PostController
{
    public function __construct(
        private Twig $twig,
        private PostRepository $posts,
        private TermRepository $terms,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    private function chrome(): array
    {
        return [
            'site' => SiteIdentity::resolve($this->settings->all()),
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
        ];
    }

    public function index(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        return $this->twig->render($response, 'posts/index.twig', [
            ...$this->chrome(),
            'seo' => Seo::build(['title' => 'Posts', 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'posts' => $this->posts->published(),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->findBySlug($args['slug']);
        if (!$post) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'posts/show.twig', [
            ...$this->chrome(),
            'seo' => Seo::build([
                'title' => $post['title'], 'siteTitle' => $site['siteTitle'],
                'description' => $post['excerpt'] ?? null, 'type' => 'article',
                'image' => $post['image_path'] ?? null, 'url' => (string) $request->getUri(),
                'publishedTime' => $post['published_at'] ?? null, 'modifiedTime' => $post['updated_at'] ?? null,
            ]),
            'post' => $post,
            'bodyHtml' => (new Markdown())->toHtml((string) $post['body_md']),
            'readingTime' => ReadingTime::minutes((string) $post['body_md']),
            'tags' => $this->terms->forContent('tag', 'post', (int) $post['id']),
        ]);
    }
}
```

- [ ] **Step 2: Create `app/views/posts/index.twig`**

Port `src/pages/posts/index.astro` markup. Loop `posts`, rendering each as the card markup from `src/components/PostCard.astro` (inline the card markup or make a `partials/post_card.twig`): `/posts/{{ post.slug }}`, `post.title`, `post.excerpt`, `post.image_path` (plain `<img>`), `post.published_at|date('M j, Y')`. Drop bylines. Move both `posts/index.astro` and `PostCard.astro` `<style>` blocks into `public/css/pages.css`.

- [ ] **Step 3: Create `app/views/posts/show.twig`**

Port `src/pages/posts/[slug].astro` markup. Render hero `<img>` from `post.image_path`, the three-column grid, meta (published date `{{ post.published_at|date('F j, Y') }}`, `{{ readingTime }} min`, `tags` → `/tag/{{ tag.slug }}`), `<h1>{{ post.title }}</h1>`, `{{ post.excerpt }}`, and the body via `<div class="article-content">{{ bodyHtml|raw }}</div>`. **Drop** the bylines block, the `<Comments>`/`<CommentForm>` block, and the sidebar `<WidgetArea>`; keep the TOC `<nav>` and its building `<script>` (port verbatim into a `{% block scripts %}`). Move `[slug].astro`'s `<style>` block verbatim into `public/css/article.css`.

- [ ] **Step 4: Write `app/Controllers/FeedController.php`** (posts + projects RSS)

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingsRepository;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FeedController
{
    public function __construct(
        private PostRepository $posts,
        private ProjectRepository $projects,
        private SettingsRepository $settings,
    ) {
    }

    public function posts(Request $request, Response $response): Response
    {
        return $this->render($request, $response, 'Posts', '/posts/', $this->posts->published(), fn ($p) => $p['excerpt'] ?? '');
    }

    public function projects(Request $request, Response $response): Response
    {
        return $this->render($request, $response, 'Projects', '/projects/', $this->projects->published(), fn ($p) => $p['summary'] ?? '');
    }

    private function render(Request $request, Response $response, string $section, string $prefix, array $items, callable $desc): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0"><channel>';
        $xml .= '<title>' . htmlspecialchars($site['siteTitle'] . ' — ' . $section) . '</title>';
        $xml .= '<link>' . htmlspecialchars($origin . $prefix) . '</link>';
        $xml .= '<description>' . htmlspecialchars($site['siteTagline']) . '</description>';
        foreach ($items as $item) {
            $url = $origin . $prefix . $item['slug'];
            $xml .= '<item>';
            $xml .= '<title>' . htmlspecialchars($item['title']) . '</title>';
            $xml .= '<link>' . htmlspecialchars($url) . '</link>';
            $xml .= '<guid>' . htmlspecialchars($url) . '</guid>';
            if (!empty($item['published_at'])) {
                $xml .= '<pubDate>' . date(DATE_RSS, strtotime((string) $item['published_at'])) . '</pubDate>';
            }
            $xml .= '<description>' . htmlspecialchars((string) $desc($item)) . '</description>';
            $xml .= '</item>';
        }
        $xml .= '</channel></rss>';

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
    }
}
```

- [ ] **Step 5: Wire routes** in `app/routes.php` (add after the resume route):

```php
    $postCtrl = new \App\Controllers\PostController($twig, $posts, $terms, $settings, $pages);
    $feedCtrl = new \App\Controllers\FeedController($posts, $projects, $settings);

    $app->get('/posts', [$postCtrl, 'index']);
    $app->get('/posts/rss.xml', [$feedCtrl, 'posts']);
    $app->get('/posts/{slug}', [$postCtrl, 'show']);
    $app->get('/projects/rss.xml', [$feedCtrl, 'projects']);
```

> Order matters: register `/posts/rss.xml` before `/posts/{slug}` so the literal route wins.

- [ ] **Step 6: Verify**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
echo "INDEX:"; curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088/posts
echo "SHOW:"; curl -s http://127.0.0.1:8088/posts/building-for-the-long-term | grep -o '<h1 class="article-title">[^<]*' | head -1
echo "RSS:"; curl -s http://127.0.0.1:8088/posts/rss.xml | head -1
kill %1
```
Expected: INDEX `200`; SHOW shows the post title; RSS prints the XML declaration.

- [ ] **Step 7: Commit**

```bash
git add app/Controllers/PostController.php app/Controllers/FeedController.php app/views/posts app/routes.php public/css/pages.css public/css/article.css
git commit -m "Add posts index, detail, and RSS feeds"
```

---

## Task 10: Projects index, detail, project tags

**Files:**
- Create: `app/Controllers/ProjectController.php`, `app/views/projects/index.twig`, `app/views/projects/show.twig`
- Modify: `app/routes.php`, `public/css/pages.css`, `public/css/article.css`

- [ ] **Step 1: Write `app/Controllers/ProjectController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Support\Markdown;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ProjectController
{
    public function __construct(
        private Twig $twig,
        private ProjectRepository $projects,
        private TermRepository $terms,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    private function chrome(): array
    {
        return [
            'site' => SiteIdentity::resolve($this->settings->all()),
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
        ];
    }

    public function index(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        return $this->twig->render($response, 'projects/index.twig', [
            ...$this->chrome(),
            'seo' => Seo::build(['title' => 'Projects', 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'projects' => $this->projects->published(),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $project = $this->projects->findBySlug($args['slug']);
        if (!$project) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'projects/show.twig', [
            ...$this->chrome(),
            'seo' => Seo::build([
                'title' => $project['title'], 'siteTitle' => $site['siteTitle'],
                'description' => $project['summary'] ?? null, 'type' => 'article',
                'image' => $project['image_path'] ?? null, 'url' => (string) $request->getUri(),
            ]),
            'project' => $project,
            'bodyHtml' => (new Markdown())->toHtml((string) $project['body_md']),
            'tags' => $this->terms->forContent('tag', 'project', (int) $project['id']),
        ]);
    }
}
```

- [ ] **Step 2: Create `app/views/projects/index.twig`**

Port `src/pages/projects/index.astro` markup (read it first). Loop `projects` using the `src/components/ProjectCard.astro` markup (read it; inline or make `partials/project_card.twig`): `/projects/{{ p.slug }}`, `p.title`, `p.summary`, `p.image_path`, `p.source_url`/`p.external_url`. Move both `<style>` blocks into `public/css/pages.css`.

- [ ] **Step 3: Create `app/views/projects/show.twig`**

Port `src/pages/projects/[slug].astro` markup (read it first). Render `project.title`, `project.summary`, optional hero `<img>` from `project.image_path`, `source_url`/`external_url` links, body via `{{ bodyHtml|raw }}`, and `tags` → `/projects/tags/{{ tag.slug }}`. Move its `<style>` block into `public/css/article.css` (or `pages.css` if it does not reuse article styles).

- [ ] **Step 4: Wire routes** in `app/routes.php`:

```php
    $projectCtrl = new \App\Controllers\ProjectController($twig, $projects, $terms, $settings, $pages);

    $app->get('/projects', [$projectCtrl, 'index']);
    $app->get('/projects/tags/{slug}', [$taxonomyCtrl, 'projectTag']); // $taxonomyCtrl from Task 11
    $app->get('/projects/{slug}', [$projectCtrl, 'show']);
```

> If Task 11 is not yet done, temporarily omit the `/projects/tags/{slug}` line and add it in Task 11. Keep `/projects/{slug}` last.

- [ ] **Step 5: Verify**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
echo "INDEX:"; curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088/projects
echo "SHOW:"; curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088/projects/mypalclara
kill %1
```
Expected: both `200`.

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/ProjectController.php app/views/projects app/routes.php public/css/pages.css public/css/article.css
git commit -m "Add projects index and detail"
```

---

## Task 11: Pages + taxonomy (tag / category / project tags)

**Files:**
- Create: `app/Controllers/PageController.php`, `app/Controllers/TaxonomyController.php`
- Create: `app/views/pages/show.twig`, `app/views/taxonomy.twig`
- Modify: `app/routes.php`, `public/css/pages.css`

- [ ] **Step 1: Write `app/Controllers/PageController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Support\Markdown;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PageController
{
    public function __construct(
        private Twig $twig,
        private PageRepository $pages,
        private SettingsRepository $settings,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $page = $this->pages->findBySlug($args['slug']);
        if (!$page) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'pages/show.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => $page['title'], 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'page' => $page,
            'bodyHtml' => (new Markdown())->toHtml((string) $page['body_md']),
        ]);
    }
}
```

- [ ] **Step 2: Write `app/Controllers/TaxonomyController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class TaxonomyController
{
    public function __construct(
        private Twig $twig,
        private TermRepository $terms,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function tag(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'tag', $args['slug'], 'post', 'posts', '/posts/');
    }

    public function category(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'category', $args['slug'], 'post', 'posts', '/posts/');
    }

    public function projectTag(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'tag', $args['slug'], 'project', 'projects', '/projects/');
    }

    private function render(Request $request, Response $response, string $taxonomy, string $slug, string $type, string $table, string $prefix): Response
    {
        $term = $this->terms->findTerm($taxonomy, $slug);
        if (!$term) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'taxonomy.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => $term['label'], 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'term' => $term,
            'taxonomy' => $taxonomy,
            'items' => $this->terms->contentForTerm((int) $term['id'], $type, $table),
            'urlPrefix' => $prefix,
        ]);
    }
}
```

- [ ] **Step 3: Create `app/views/pages/show.twig`**

Port `src/pages/pages/[slug].astro`. Render `page.title` and `{{ bodyHtml|raw }}` in the same article container the page used. Move any `<style>` into `public/css/pages.css`.

- [ ] **Step 4: Create `app/views/taxonomy.twig`**

A single template for tag/category/project-tag listings. Header: `{{ term.label }}`. Loop `items` rendering each as a link card: `{{ urlPrefix }}{{ item.slug }}`, `item.title`, `item.excerpt|default(item.summary)`. Base its styles on the existing `src/pages/tag/[slug].astro` (read it) — move that `<style>` into `public/css/pages.css`.

- [ ] **Step 5: Wire routes** in `app/routes.php`:

```php
    $pageCtrl = new \App\Controllers\PageController($twig, $pages, $settings);
    $taxonomyCtrl = new \App\Controllers\TaxonomyController($twig, $terms, $settings, $pages);

    $app->get('/pages/{slug}', [$pageCtrl, 'show']);
    $app->get('/tag/{slug}', [$taxonomyCtrl, 'tag']);
    $app->get('/category/{slug}', [$taxonomyCtrl, 'category']);
    // /projects/tags/{slug} -> [$taxonomyCtrl, 'projectTag'] (added in Task 10 route block)
```

Ensure `$taxonomyCtrl` is defined before the Task 10 `/projects/tags/{slug}` line; move controller construction above the route registrations if needed.

- [ ] **Step 6: Verify**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
for u in /pages/about /tag/opinion /category/development /projects/tags/ai; do
  echo "$u:"; curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8088$u"
done
kill %1
```
Expected: all `200`.

- [ ] **Step 7: Commit**

```bash
git add app/Controllers/PageController.php app/Controllers/TaxonomyController.php app/views/pages app/views/taxonomy.twig app/routes.php public/css/pages.css
git commit -m "Add pages and taxonomy listings"
```

---

## Task 12: Search (page + JSON API)

**Files:**
- Create: `app/Controllers/SearchController.php`, `app/views/search.twig`
- Modify: `app/routes.php`

- [ ] **Step 1: Write `app/Controllers/SearchController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SearchRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SearchController
{
    public function __construct(
        private Twig $twig,
        private SearchRepository $search,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $site = SiteIdentity::resolve($this->settings->all());
        $results = $q !== '' ? $this->search->search($q, ['posts', 'projects', 'pages']) : [];

        return $this->twig->render($response, 'search.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => 'Search', 'siteTitle' => $site['siteTitle'], 'robots' => 'noindex', 'url' => (string) $request->getUri()]),
            'query' => $q,
            'results' => $results,
        ]);
    }

    public function api(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $collections = $params['in'] ?? ['posts', 'projects', 'pages'];
        if (!is_array($collections)) {
            $collections = [$collections];
        }
        $results = $q !== '' ? $this->search->search($q, $collections) : [];

        $response->getBody()->write((string) json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 2: Create `app/views/search.twig`**

Port `src/pages/search.astro` for the static results page. Show the `query`, and loop `results` (`r.url`, `r.title`, `r.collection`, `r.snippet`). Reuse `pages.css`.

- [ ] **Step 3: Wire routes** in `app/routes.php`:

```php
    $searchCtrl = new \App\Controllers\SearchController($twig, $search, $settings, $pages);

    $app->get('/search', [$searchCtrl, 'page']);
    $app->get('/api/search', [$searchCtrl, 'api']);
```

- [ ] **Step 4: Verify the API + live search**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
echo "API:"; curl -s "http://127.0.0.1:8088/api/search?q=framework&in[]=posts" | head -c 200
echo ""; echo "PAGE:"; curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8088/search?q=framework"
kill %1
```
Expected: API returns a JSON array containing at least one result; PAGE `200`.

- [ ] **Step 5: Commit**

```bash
git add app/Controllers/SearchController.php app/views/search.twig app/routes.php
git commit -m "Add search page and JSON API"
```

---

## Task 13: Sitemap, 404, cache headers

**Files:**
- Create: `app/Controllers/SitemapController.php`, `app/Controllers/ErrorController.php`, `app/views/404.twig`
- Modify: `app/routes.php`, `app/bootstrap.php`

- [ ] **Step 1: Write `app/Controllers/SitemapController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SitemapController
{
    public function __construct(
        private PostRepository $posts,
        private ProjectRepository $projects,
        private PageRepository $pages,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        $urls = ['/', '/resume', '/posts', '/projects'];
        foreach ($this->posts->published() as $p) {
            $urls[] = '/posts/' . $p['slug'];
        }
        foreach ($this->projects->published() as $p) {
            $urls[] = '/projects/' . $p['slug'];
        }
        foreach ($this->pages->published() as $p) {
            $urls[] = '/pages/' . $p['slug'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $xml .= '<url><loc>' . htmlspecialchars($origin . $u) . '</loc></url>';
        }
        $xml .= '</urlset>';

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
```

- [ ] **Step 2: Write `app/Controllers/ErrorController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ErrorController
{
    public function __construct(
        private Twig $twig,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function notFound(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $response = $this->twig->render($response, '404.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => 'Not Found', 'siteTitle' => $site['siteTitle'], 'robots' => 'noindex']),
        ]);
        return $response->withStatus(404);
    }
}
```

- [ ] **Step 3: Create `app/views/404.twig`**

Port `src/pages/404.astro` markup into `{% extends 'layout.twig' %}`. Move its `<style>` into `public/css/pages.css`.

- [ ] **Step 4: Wire routes + a Slim Not Found handler + cache headers** in `app/routes.php` and `app/bootstrap.php`.

In `app/routes.php` add:
```php
    $sitemapCtrl = new \App\Controllers\SitemapController($posts, $projects, $pages);
    $errorCtrl = new \App\Controllers\ErrorController($twig, $settings, $pages);

    $app->get('/sitemap.xml', [$sitemapCtrl, 'index']);
    $app->get('/404', [$errorCtrl, 'notFound']);
```

In `app/bootstrap.php`, after `$app->add(TwigMiddleware...)`, register the error middleware so unknown routes render the 404 page. Add:
```php
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setErrorHandler(
    Slim\Exception\HttpNotFoundException::class,
    function ($request, $throwable, $displayErrorDetails) use ($app, $twig, $pdo) {
        $ctrl = new App\Controllers\ErrorController(
            $twig,
            new App\Repositories\SettingsRepository($pdo),
            new App\Repositories\PageRepository($pdo)
        );
        $response = $app->getResponseFactory()->createResponse();
        return $ctrl->notFound($request, $response);
    }
);
```
> `$pdo` and `$twig` are already in scope in `bootstrap.php`. Place this block after the routes are registered (after the `require routes.php` call).

Add a simple cache-control middleware for GET responses (anonymous public caching). In `app/bootstrap.php` before `return $app;`:
```php
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    if ($request->getMethod() === 'GET' && !str_starts_with($request->getUri()->getPath(), '/api')) {
        return $response->withHeader('Cache-Control', 'public, max-age=300');
    }
    return $response;
});
```

- [ ] **Step 5: Verify 404 + sitemap**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
echo "404:"; curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088/no-such-page
echo "SITEMAP:"; curl -s http://127.0.0.1:8088/sitemap.xml | head -1
kill %1
```
Expected: 404 returns `404`; SITEMAP prints the XML declaration.

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/SitemapController.php app/Controllers/ErrorController.php app/views/404.twig app/routes.php app/bootstrap.php public/css/pages.css
git commit -m "Add sitemap, 404 handler, and cache headers"
```

---

## Task 14: PDF resume export

**Files:**
- Create: `app/Controllers/PdfController.php`, `app/views/resume_pdf.twig`
- Modify: `app/routes.php`

- [ ] **Step 1: Write `app/Controllers/PdfController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ResumeRepository;
use Dompdf\Dompdf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PdfController
{
    public function __construct(private Twig $twig, private ResumeRepository $resume)
    {
    }

    public function resume(Request $request, Response $response): Response
    {
        $html = $this->twig->fetch('resume_pdf.twig', [
            'resume' => $this->resume->meta(),
            'experience' => $this->resume->experience(),
            'education' => $this->resume->education(),
            'skills' => $this->resume->skills(),
        ]);

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        $name = preg_replace('/[^a-z0-9]+/i', '-', $this->resume->meta()['name'] ?? 'resume');
        $response->getBody()->write($dompdf->output());

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . strtolower(trim($name, '-')) . '.pdf"');
    }
}
```

- [ ] **Step 2: Create `app/views/resume_pdf.twig`** (self-contained, print-optimized; inline `<style>`, no external CSS — Dompdf supports a CSS subset)

```twig
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; font-size: 11px; line-height: 1.45; margin: 0; }
    h1 { font-size: 22px; margin: 0 0 2px; }
    .headline { color: #444; margin: 0 0 8px; font-size: 12px; }
    .summary { margin: 0 0 12px; }
    .contact { margin: 0 0 16px; font-size: 10px; color: #444; }
    h2 { font-size: 13px; border-bottom: 1px solid #000; padding-bottom: 2px; margin: 16px 0 8px; text-transform: uppercase; letter-spacing: 0.04em; }
    .entry { margin-bottom: 10px; }
    .entry-head { font-weight: bold; }
    .entry-meta { color: #555; font-size: 10px; margin-bottom: 3px; }
    ul { margin: 4px 0 0 16px; padding: 0; }
    li { margin-bottom: 2px; }
    .skills-cat { margin-bottom: 6px; }
    .skills-cat b { display: inline-block; min-width: 110px; }
</style>
</head>
<body>
    <h1>{{ resume.name }}</h1>
    {% if resume.headline %}<p class="headline">{{ resume.headline }}</p>{% endif %}
    {% if resume.summary %}<p class="summary">{{ resume.summary }}</p>{% endif %}
    <p class="contact">
        {% if resume.contact.email %}{{ resume.contact.email }}{% endif %}
        {% if resume.contact.location %} · {{ resume.contact.location }}{% endif %}
        {% for link in resume.contact.links %} · {{ link.label }}: {{ link.url }}{% endfor %}
    </p>

    <h2>Experience</h2>
    {% for job in experience %}
        <div class="entry">
            <div class="entry-head">{{ job.role }} · {{ job.company }}</div>
            <div class="entry-meta">{{ job.start }}{% if job.end %} – {{ job.end }}{% endif %}{% if job.location %} · {{ job.location }}{% endif %}</div>
            {% if job.bullets %}<ul>{% for b in job.bullets %}<li>{{ b }}</li>{% endfor %}</ul>{% endif %}
        </div>
    {% endfor %}

    <h2>Education</h2>
    {% for ed in education %}
        <div class="entry">
            <div class="entry-head">{{ ed.degree }} · {{ ed.school }}</div>
            <div class="entry-meta">{{ ed.start }}{% if ed.end %} – {{ ed.end }}{% endif %}{% if ed.location %} · {{ ed.location }}{% endif %}</div>
        </div>
    {% endfor %}

    <h2>Skills</h2>
    {% for cat in skills %}
        <div class="skills-cat"><b>{{ cat.name }}</b> {{ cat.items|map(i => i.name)|join(', ') }}</div>
    {% endfor %}
</body>
</html>
```

- [ ] **Step 3: Wire route** in `app/routes.php`:

```php
    $pdfCtrl = new \App\Controllers\PdfController($twig, $resume);
    $app->get('/resume.pdf', [$pdfCtrl, 'resume']);
```

> Register `/resume.pdf` — it will not collide with `/resume` (exact paths).

- [ ] **Step 4: Add a download link** to `app/views/resume.twig` near the contact links: `<a href="/resume.pdf" class="resume-pdf-link">Download PDF</a>`.

- [ ] **Step 5: Verify the PDF**

Run:
```bash
php -S 127.0.0.1:8088 -t public public/index.php &
sleep 1
curl -s http://127.0.0.1:8088/resume.pdf -o /tmp/resume.pdf
file /tmp/resume.pdf
head -c 5 /tmp/resume.pdf
kill %1
```
Expected: `file` reports `PDF document`; first bytes are `%PDF-`.

- [ ] **Step 6: Add a smoke test — `tests/Http/RouteSmokeTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Support\Importer;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RouteSmokeTest extends TestCase
{
    private function app()
    {
        // Build the app against the TEST database by overriding env for this process.
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        (new Importer($pdo, [
            'base' => dirname(__DIR__, 2),
            'uploads_src' => dirname(__DIR__, 2) . '/uploads',
            'uploads_dest' => sys_get_temp_dir() . '/jh_uploads_test',
        ]))->run();

        // bootstrap.php connects via config['db']; for the smoke test we point the
        // default connection at the test DB by setting env before requiring it.
        $_ENV['DB_NAME'] = $config['db_test']['name'];
        putenv('DB_NAME=' . $config['db_test']['name']);

        return require dirname(__DIR__, 2) . '/app/bootstrap.php';
    }

    public function test_home_renders(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<main', (string) $response->getBody());
    }

    public function test_api_search_returns_json(): void
    {
        $app = $this->app();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search')
            ->withQueryParams(['q' => 'framework', 'in' => ['posts']]);
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJson((string) $response->getBody());
    }
}
```

> Because `app/config.php` uses `createImmutable`, set `DB_NAME` via `putenv`/`$_ENV` **before** `config.php` runs in the same process. Since PHPUnit loads config inside `app()` each call and `bootstrap.php` re-requires config, the immutable loader keeps the already-set `DB_NAME`. If this proves flaky, instead add an optional `bootstrap.php` parameter to inject the PDO; keep the smoke test minimal.

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass (Plan 1 tests + AssetManifest, ReadingTime, Seo, PostRepository, RouteSmoke).

- [ ] **Step 8: Commit**

```bash
git add app/Controllers/PdfController.php app/views/resume_pdf.twig app/routes.php app/views/resume.twig tests/Http/RouteSmokeTest.php
git commit -m "Add PDF resume export and route smoke tests"
```

---

## Task 15: Manual visual QA + cleanup

**Files:**
- Modify/Delete: old Astro source (deferred deletion)

- [ ] **Step 1: Visual QA against the dev DB**

Run `php -S 127.0.0.1:8088 -t public public/index.php` and open each route in a browser; compare against the current site's look (header, footer, hero typewriter, NeoBrutalism tiles/cards, dark mode toggle, post article layout, live search dropdown). Note any CSS gaps and fix by reconciling against the source `<style>` blocks.

- [ ] **Step 2: Decide on old Astro source**

The old Astro app (`astro.config.mjs`, `wrangler.jsonc`, `src/pages`, `src/layouts`, `src/components`, `src/live.config.ts`, `src/worker.ts`, `emdash-env.d.ts`, `worker-configuration.d.ts`) is now fully superseded. Removal is handled at the end of Plan 3 (Admin) so it remains available as a porting reference for Plan 3's admin styling. **Do not delete in this plan.** `src/data/*.json`, `seed/`, and `uploads/` stay (importer sources).

- [ ] **Step 3: Final commit (if QA fixes were made)**

```bash
git add public/css app/views
git commit -m "Visual QA fixes for public site"
```

---

## Self-Review

**Spec coverage (public-site slice):**
- Slim 4 + Twig + PDO web layer → Tasks 4, 6, 8–13. ✓
- Appearance preserved via CSS port → Tasks 7–13 (porting tasks move `<style>` verbatim). ✓
- Routes/URLs preserved (`/`, `/resume`, `/resume.pdf`, `/posts`, `/posts/{slug}`, `/posts/rss.xml`, `/projects`, `/projects/{slug}`, `/projects/tags/{slug}`, `/projects/rss.xml`, `/pages/{slug}`, `/tag/{slug}`, `/category/{slug}`, `/search`, `/api/search`, `/sitemap.xml`, `/404`) → Tasks 8–14. ✓
- Svelte islands (Typewriter, Search) → Tasks 1–2, mounted in Tasks 7–8. ✓
- Markdown rendering + reading time → `Markdown` (Plan 1) used in controllers; `ReadingTime` Task 5. ✓
- SEO/head + RSS + sitemap → Tasks 5, 7, 9, 13. ✓
- PDF export via Dompdf → Task 14. ✓
- Cache headers → Task 13. ✓
- Dropped EmDash features (bylines, comments, widgets) → noted in porting conventions + Tasks 9–11. ✓

**Placeholder scan:** New code is complete. Porting tasks intentionally reference existing `src/...astro` files as the source of truth for markup/CSS (the files exist in-repo); each porting task lists the exact data bindings and transformations, so they are not open-ended.

**Type/name consistency:** Repository method names (`published`, `findBySlug`, `featured`, `forContent`, `findTerm`, `contentForTerm`, `meta`, `experience`, `education`, `skills`, `all`, `menu`, `search`) are used consistently across controllers; `Seo::build`, `SiteIdentity::resolve`, `ReadingTime::minutes`, `Markdown::toHtml`, `AssetManifest::{js,css}` match their definitions; template variable names (`site`, `menu`, `pages`, `seo`, `resume`, `post`, `project`, `bodyHtml`, `readingTime`, `tags`, `items`, `urlPrefix`) are consistent between controllers and the templates they render.

**Known risks for the executor:**
- `RouteSmokeTest` relies on `createImmutable` keeping a pre-set `DB_NAME`; if flaky, inject the PDO into `bootstrap.php` instead (noted in Task 14 Step 6).
- The home `index.astro` referenced `--color-shadow` (defined in `theme.css`) — confirm the tile/card shadows render after the CSS port.
- Slim route ordering: literal routes (`/posts/rss.xml`, `/projects/rss.xml`, `/projects/tags/{slug}`, `/resume.pdf`) must be registered before the `{slug}` catch-alls.
