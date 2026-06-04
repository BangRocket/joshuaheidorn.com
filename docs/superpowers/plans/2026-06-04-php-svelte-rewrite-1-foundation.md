# PHP + Svelte Rewrite — Plan 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the PHP toolchain, MySQL schema, core content-conversion utilities, and a one-time importer that loads all existing content (posts, pages, projects, resume, skills, taxonomies, menu, settings, media) into MySQL.

**Architecture:** Pure-PHP foundation, no web layer yet. PSR-4 `App\` namespace under `app/`. Phinx owns the schema. Three pure-logic utilities (`Slug`, `Markdown`, `PortableText`) are TDD'd in isolation; the `Importer` orchestrates them to migrate the repo's existing JSON/seed content into the DB. The web layer (Slim/Twig/Svelte) and admin come in Plans 2 and 3.

**Tech Stack:** PHP 8.2+, Composer, PDO (MySQL), `league/commonmark` (Markdown→HTML), `robmorgan/phinx` (migrations), `vlucas/phpdotenv` (config), PHPUnit (tests).

**Spec:** `docs/superpowers/specs/2026-06-04-php-svelte-rewrite-design.md`

---

## Prerequisites (one-time, before Task 1)

The executing engineer needs a local MySQL/MariaDB 8+ server running. Create the two databases:

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS joshuaheidorn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS joshuaheidorn_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

PHP must have the `pdo_mysql` and `iconv` extensions enabled (both standard). Verify:

```bash
php -m | grep -E "pdo_mysql|iconv"
```
Expected: both `iconv` and `pdo_mysql` printed.

## File Structure (created by this plan)

```
composer.json                 # PHP deps + PSR-4 autoload (App\ -> app/, Tests\ -> tests/)
phpunit.xml                   # PHPUnit config
phinx.php                     # Phinx environments (development + testing), reads .env
.env.example                  # config template (copied to .env, which is gitignored)
app/
  config.php                  # loads .env, returns config array (db, db_test, paths)
  Support/
    Database.php              # PDO factory
    Slug.php                  # slugify
    Markdown.php              # CommonMark wrapper (Markdown -> HTML)
    PortableText.php          # EmDash Portable Text -> Markdown
    Importer.php              # one-time content migration into MySQL
db/migrations/
  20260604000001_create_initial_schema.php
bin/
  seed.php                    # CLI entry: runs Importer against the dev DB
tests/
  bootstrap.php
  DatabaseTestCase.php        # base class for DB-backed tests (connect + truncate)
  Support/
    SlugTest.php
    MarkdownTest.php
    PortableTextTest.php
    DatabaseTest.php
  ImporterTest.php
```

Migration sources stay in place and are read by the importer: `seed/seed.json`, `src/data/projects.json`, `src/data/resume.json`, `src/data/skills.json`, `uploads/*`. The old Astro app under `src/` (pages, layouts, components, styles) remains untouched as a porting reference for Plan 2 and is deleted in a later plan.

---

## Task 1: PHP project scaffold

**Files:**
- Create: `composer.json`, `phpunit.xml`, `phinx.php`, `.env.example`, `app/config.php`, `tests/bootstrap.php`
- Modify: `.gitignore`

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "joshuaheidorn/site",
    "description": "joshuaheidorn.com — PHP + Svelte",
    "type": "project",
    "require": {
        "php": ">=8.2",
        "league/commonmark": "^2.4",
        "vlucas/phpdotenv": "^5.6"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "robmorgan/phinx": "^0.16"
    },
    "autoload": {
        "psr-4": { "App\\": "app/" }
    },
    "autoload-dev": {
        "psr-4": { "Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Create `.env.example`**

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=joshuaheidorn
DB_TEST_NAME=joshuaheidorn_test
DB_USER=root
DB_PASS=
```

- [ ] **Step 3: Append to `.gitignore`**

Add these lines to the existing `.gitignore`:

```gitignore
/vendor
.env
/public/uploads
```

- [ ] **Step 4: Create `app/config.php`**

```php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (file_exists($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? 'joshuaheidorn',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'db_test' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_TEST_NAME'] ?? 'joshuaheidorn_test',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'paths' => [
        'base' => $root,
        'uploads_src' => $root . '/uploads',
        'uploads_dest' => $root . '/public/uploads',
    ],
];
```

- [ ] **Step 5: Create `phinx.php`**

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => $_ENV['DB_NAME'] ?? 'joshuaheidorn',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASS'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => $_ENV['DB_TEST_NAME'] ?? 'joshuaheidorn_test',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASS'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
```

- [ ] **Step 6: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 7: Create `tests/bootstrap.php`**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 8: Install dependencies and copy env**

Run:
```bash
composer install
cp .env.example .env
```
Expected: `vendor/` created; no errors. Edit `.env` if your MySQL user/pass differ from root/empty.

- [ ] **Step 9: Verify the toolchain**

Run:
```bash
vendor/bin/phpunit --version && vendor/bin/phinx --version
```
Expected: PHPUnit 11.x and Phinx version strings print.

- [ ] **Step 10: Commit**

```bash
git add composer.json composer.lock phpunit.xml phinx.php .env.example .gitignore app/config.php tests/bootstrap.php
git commit -m "Scaffold PHP toolchain (Composer, PHPUnit, Phinx, dotenv)"
```

---

## Task 2: PDO database factory

**Files:**
- Create: `app/Support/Database.php`, `tests/DatabaseTestCase.php`, `tests/Support/DatabaseTest.php`

- [ ] **Step 1: Write the failing test — `tests/Support/DatabaseTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function test_connect_returns_a_working_pdo(): void
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
        $this->assertSame(
            PDO::ERRMODE_EXCEPTION,
            $pdo->getAttribute(PDO::ATTR_ERRMODE)
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_connect_returns_a_working_pdo`
Expected: FAIL — `Class "App\Support\Database" not found`.

- [ ] **Step 3: Write `app/Support/Database.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    /**
     * @param array{host:string,port:string,name:string,user:string,pass:string} $config
     */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['name']
        );

        return new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
```

- [ ] **Step 4: Write `tests/DatabaseTestCase.php`** (base class used by later DB-backed tests)

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    /** Tables truncated before each test, child-first to respect FKs. */
    private const TABLES = [
        'term_relationships', 'skills', 'skill_categories', 'experience',
        'education', 'resume_meta', 'menu_items', 'settings',
        'posts', 'projects', 'pages', 'terms', 'media', 'users',
    ];

    protected function setUp(): void
    {
        $config = require dirname(__DIR__) . '/app/config.php';
        $this->pdo = Database::connect($config['db_test']);
        $this->truncateAll();
    }

    protected function truncateAll(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $table) {
            $this->pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter test_connect_returns_a_working_pdo`
Expected: PASS. (Requires the `joshuaheidorn_test` database to exist — created in Prerequisites.)

- [ ] **Step 6: Commit**

```bash
git add app/Support/Database.php tests/DatabaseTestCase.php tests/Support/DatabaseTest.php
git commit -m "Add PDO database factory"
```

---

## Task 3: Slug utility

**Files:**
- Create: `app/Support/Slug.php`, `tests/Support/SlugTest.php`

- [ ] **Step 1: Write the failing test — `tests/Support/SlugTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function test_lowercases_and_hyphenates(): void
    {
        $this->assertSame('hello-world', Slug::make('Hello World'));
    }

    public function test_strips_punctuation_and_collapses_separators(): void
    {
        $this->assertSame('react-typescript', Slug::make('React + TypeScript!!'));
    }

    public function test_transliterates_accents(): void
    {
        $this->assertSame('cafe-creme', Slug::make('Café Crème'));
    }

    public function test_trims_leading_and_trailing_separators(): void
    {
        $this->assertSame('tools', Slug::make('  --Tools--  '));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SlugTest`
Expected: FAIL — `Class "App\Support\Slug" not found`.

- [ ] **Step 3: Write `app/Support/Slug.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class Slug
{
    public static function make(string $text): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = strtolower($ascii !== false ? $ascii : $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SlugTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Slug.php tests/Support/SlugTest.php
git commit -m "Add Slug utility"
```

---

## Task 4: Markdown renderer

**Files:**
- Create: `app/Support/Markdown.php`, `tests/Support/MarkdownTest.php`

- [ ] **Step 1: Write the failing test — `tests/Support/MarkdownTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function test_renders_headings_and_emphasis(): void
    {
        $html = (new Markdown())->toHtml("## Title\n\nSome **bold** and _italic_ text.");

        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function test_strips_raw_html(): void
    {
        $html = (new Markdown())->toHtml('Hello <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MarkdownTest`
Expected: FAIL — `Class "App\Support\Markdown" not found`.

- [ ] **Step 3: Write `app/Support/Markdown.php`**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter MarkdownTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Markdown.php tests/Support/MarkdownTest.php
git commit -m "Add Markdown renderer (CommonMark)"
```

---

## Task 5: Portable Text → Markdown converter

EmDash stores bodies as Portable Text: an array of blocks. Each `block` has a `style` (`normal`, `h1`–`h4`, `blockquote`), `children` (an array of `span`s with `text` and `marks`), optional `markDefs` (link annotations keyed by `_key`), and optional `listItem` (`bullet`/`number`) + `level`. Decorator marks seen in content: `strong`, `em`, `code`. We also handle `image` blocks defensively.

**Files:**
- Create: `app/Support/PortableText.php`, `tests/Support/PortableTextTest.php`

- [ ] **Step 1: Write the failing test — `tests/Support/PortableTextTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\PortableText;
use PHPUnit\Framework\TestCase;

final class PortableTextTest extends TestCase
{
    private function block(string $style, array $children, array $extra = []): array
    {
        return array_merge(['_type' => 'block', 'style' => $style, 'children' => $children], $extra);
    }

    private function span(string $text, array $marks = []): array
    {
        return ['_type' => 'span', 'text' => $text, 'marks' => $marks];
    }

    public function test_paragraph_and_heading(): void
    {
        $blocks = [
            $this->block('normal', [$this->span('Hello world.')]),
            $this->block('h2', [$this->span('What survives')]),
        ];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("Hello world.\n\n## What survives\n", $md);
    }

    public function test_decorator_marks(): void
    {
        $blocks = [$this->block('normal', [
            $this->span('A '),
            $this->span('bold', ['strong']),
            $this->span(' and '),
            $this->span('italic', ['em']),
            $this->span(' and '),
            $this->span('code', ['code']),
            $this->span('.'),
        ])];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("A **bold** and _italic_ and `code`.\n", $md);
    }

    public function test_link_annotation(): void
    {
        $blocks = [$this->block('normal', [
            $this->span('See '),
            $this->span('the docs', ['link-1']),
        ], ['markDefs' => [['_key' => 'link-1', '_type' => 'link', 'href' => 'https://example.com']]])];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("See [the docs](https://example.com)\n", $md);
    }

    public function test_tight_bullet_list(): void
    {
        $blocks = [
            $this->block('normal', [$this->span('First item')], ['listItem' => 'bullet']),
            $this->block('normal', [$this->span('Second item')], ['listItem' => 'bullet']),
        ];

        $md = (new PortableText())->toMarkdown($blocks);

        $this->assertSame("- First item\n- Second item\n", $md);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PortableTextTest`
Expected: FAIL — `Class "App\Support\PortableText" not found`.

- [ ] **Step 3: Write `app/Support/PortableText.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class PortableText
{
    /**
     * @param array<int,array<string,mixed>> $blocks
     */
    public function toMarkdown(array $blocks): string
    {
        $pieces = [];

        foreach ($blocks as $block) {
            $type = $block['_type'] ?? null;

            if ($type === 'block') {
                $pieces[] = [
                    'md' => $this->renderBlock($block),
                    'list' => isset($block['listItem']),
                ];
            } elseif ($type === 'image') {
                $pieces[] = ['md' => $this->renderImage($block), 'list' => false];
            }
            // Unknown block types are skipped.
        }

        $result = '';
        foreach ($pieces as $i => $piece) {
            if ($piece['md'] === '') {
                continue;
            }
            if ($i > 0) {
                $bothLists = $piece['list'] && ($pieces[$i - 1]['list'] ?? false);
                $result .= $bothLists ? "\n" : "\n\n";
            }
            $result .= $piece['md'];
        }

        return trim($result) . "\n";
    }

    private function renderBlock(array $block): string
    {
        $text = $this->renderChildren($block['children'] ?? [], $block['markDefs'] ?? []);

        if (isset($block['listItem'])) {
            $level = max(1, (int) ($block['level'] ?? 1));
            $indent = str_repeat('  ', $level - 1);
            $marker = $block['listItem'] === 'number' ? '1.' : '-';

            return $indent . $marker . ' ' . $text;
        }

        return match ($block['style'] ?? 'normal') {
            'h1' => '# ' . $text,
            'h2' => '## ' . $text,
            'h3' => '### ' . $text,
            'h4' => '#### ' . $text,
            'blockquote' => '> ' . $text,
            default => $text,
        };
    }

    private function renderChildren(array $children, array $markDefs): string
    {
        $defs = [];
        foreach ($markDefs as $def) {
            if (isset($def['_key'])) {
                $defs[$def['_key']] = $def;
            }
        }

        $out = '';
        foreach ($children as $child) {
            if (($child['_type'] ?? 'span') !== 'span') {
                continue;
            }

            $text = $child['text'] ?? '';
            $marks = $child['marks'] ?? [];

            $href = null;
            foreach ($marks as $mark) {
                if (isset($defs[$mark]) && ($defs[$mark]['_type'] ?? '') === 'link') {
                    $href = $defs[$mark]['href'] ?? null;
                }
            }

            if (in_array('code', $marks, true)) {
                $text = '`' . $text . '`';
            }
            if (in_array('strong', $marks, true)) {
                $text = '**' . $text . '**';
            }
            if (in_array('em', $marks, true)) {
                $text = '_' . $text . '_';
            }
            if ($href !== null) {
                $text = '[' . $text . '](' . $href . ')';
            }

            $out .= $text;
        }

        return $out;
    }

    private function renderImage(array $block): string
    {
        $alt = $block['alt'] ?? '';
        $ref = $block['asset']['_ref'] ?? ($block['asset']['id'] ?? '');

        return '![' . $alt . '](ASSET:' . $ref . ')';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PortableTextTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/PortableText.php tests/Support/PortableTextTest.php
git commit -m "Add Portable Text to Markdown converter"
```

---

## Task 6: Database schema migration

**Files:**
- Create: `db/migrations/20260604000001_create_initial_schema.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInitialSchema extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true])
            ->create();

        $this->table('media')
            ->addColumn('filename', 'string', ['limit' => 255])
            ->addColumn('path', 'string', ['limit' => 512])
            ->addColumn('alt', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('width', 'integer', ['null' => true])
            ->addColumn('height', 'integer', ['null' => true])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('terms')
            ->addColumn('taxonomy', 'string', ['limit' => 32])
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('label', 'string', ['limit' => 191])
            ->addIndex(['taxonomy', 'slug'], ['unique' => true])
            ->create();

        $this->table('term_relationships')
            ->addColumn('term_id', 'integer')
            ->addColumn('content_type', 'string', ['limit' => 32])
            ->addColumn('content_id', 'integer')
            ->addIndex(['content_type', 'content_id'])
            ->addIndex(['term_id'])
            ->addForeignKey('term_id', 'terms', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('posts')
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('excerpt', 'text', ['null' => true])
            ->addColumn('body_md', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('featured_image_id', 'integer', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->addForeignKey('featured_image_id', 'media', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('projects')
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('summary', 'text', ['null' => true])
            ->addColumn('body_md', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('source_url', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('external_url', 'string', ['limit' => 512, 'null' => true])
            ->addColumn('featured', 'boolean', ['default' => false])
            ->addColumn('featured_image_id', 'integer', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->addForeignKey('featured_image_id', 'media', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('pages')
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('body_md', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $this->table('resume_meta')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('headline', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('headlines', 'text', ['null' => true])
            ->addColumn('summary', 'text', ['null' => true])
            ->addColumn('contact', 'text', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();

        $this->table('experience')
            ->addColumn('company', 'string', ['limit' => 255])
            ->addColumn('role', 'string', ['limit' => 255])
            ->addColumn('start', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('end', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('bullets', 'text', ['null' => true])
            ->addColumn('tags', 'text', ['null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();

        $this->table('education')
            ->addColumn('school', 'string', ['limit' => 255])
            ->addColumn('degree', 'string', ['limit' => 255])
            ->addColumn('start', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('end', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();

        $this->table('skill_categories')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();

        $this->table('skills')
            ->addColumn('category_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('proficiency', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addForeignKey('category_id', 'skill_categories', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('settings')
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();

        $this->table('menu_items')
            ->addColumn('label', 'string', ['limit' => 191])
            ->addColumn('url', 'string', ['limit' => 512])
            ->addColumn('target', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->create();
    }
}
```

- [ ] **Step 2: Apply the migration to both databases**

Run:
```bash
vendor/bin/phinx migrate -e development
vendor/bin/phinx migrate -e testing
```
Expected: both report `== CreateInitialSchema: migrated`.

- [ ] **Step 3: Verify the tables exist**

Run:
```bash
mysql -uroot joshuaheidorn -e "SHOW TABLES;"
```
Expected: lists `users, media, terms, term_relationships, posts, projects, pages, resume_meta, experience, education, skill_categories, skills, settings, menu_items, phinxlog`.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/20260604000001_create_initial_schema.php
git commit -m "Add initial MySQL schema migration"
```

---

## Task 7: Content importer

The importer reads the existing repo content and loads it into MySQL. It is destructive/idempotent: it truncates all content tables, then re-inserts. Bylines are intentionally dropped (single-user site). Published dates are synthesized in source order from a fixed base date (posts/pages have no stored dates); projects use their stored `publishedAt`.

**Files:**
- Create: `app/Support/Importer.php`, `bin/seed.php`, `tests/ImporterTest.php`

- [ ] **Step 1: Write the failing test — `tests/ImporterTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Importer;

final class ImporterTest extends DatabaseTestCase
{
    private function runImport(): void
    {
        $root = dirname(__DIR__);
        $importer = new Importer($this->pdo, [
            'base' => $root,
            'uploads_src' => $root . '/uploads',
            'uploads_dest' => sys_get_temp_dir() . '/jh_uploads_test',
        ]);
        $importer->run();
    }

    private function count(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    public function test_imports_expected_row_counts(): void
    {
        $this->runImport();

        $this->assertSame(8, $this->count('posts'));
        $this->assertSame(1, $this->count('pages'));
        $this->assertSame(6, $this->count('projects'));
        $this->assertSame(8, $this->count('skill_categories'));
        $this->assertSame(3, $this->count('experience'));
        $this->assertSame(2, $this->count('education'));
        $this->assertSame(1, $this->count('resume_meta'));
    }

    public function test_converts_post_body_to_markdown(): void
    {
        $this->runImport();

        $body = $this->pdo->query(
            "SELECT body_md FROM posts WHERE slug = 'building-for-the-long-term'"
        )->fetchColumn();

        $this->assertStringContainsString('## What survives', (string) $body);
    }

    public function test_links_taxonomies_to_posts(): void
    {
        $this->runImport();

        $rows = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM term_relationships tr
             JOIN terms t ON t.id = tr.term_id
             WHERE tr.content_type = 'post' AND t.taxonomy = 'category' AND t.slug = 'development'"
        )->fetchColumn();

        $this->assertGreaterThanOrEqual(1, $rows);
    }

    public function test_imports_resume_name(): void
    {
        $this->runImport();

        $name = $this->pdo->query('SELECT name FROM resume_meta LIMIT 1')->fetchColumn();
        $expected = json_decode(file_get_contents(dirname(__DIR__) . '/src/data/resume.json'), true)['name'];

        $this->assertSame($expected, $name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ImporterTest`
Expected: FAIL — `Class "App\Support\Importer" not found`.

- [ ] **Step 3: Write `app/Support/Importer.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

final class Importer
{
    private const BASE_DATE = '2026-04-01 12:00:00';

    /** @var array<string,int> path => media id */
    private array $mediaByPath = [];
    /** @var array<string,int> ulid => media id */
    private array $mediaByUlid = [];
    /** @var array<string,int> "taxonomy|slug" => term id */
    private array $terms = [];

    /** @param array{base:string,uploads_src:string,uploads_dest:string} $paths */
    public function __construct(private PDO $pdo, private array $paths)
    {
    }

    public function run(): void
    {
        $this->truncate();
        $seed = $this->json($this->paths['base'] . '/seed/seed.json');

        $this->importSettings($seed['settings'] ?? []);
        $this->importMenu($seed['menus'] ?? []);
        $this->importTerms($seed['taxonomies'] ?? []);
        $this->importMedia();
        $this->importPosts($seed['content']['posts'] ?? []);
        $this->importPages($seed['content']['pages'] ?? []);
        $this->importProjects($this->json($this->paths['base'] . '/src/data/projects.json')['projects'] ?? []);
        $this->importResume($this->json($this->paths['base'] . '/src/data/resume.json'));
        $this->importSkills($this->json($this->paths['base'] . '/src/data/skills.json')['categories'] ?? []);
    }

    private function json(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$file}");
        }
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function truncate(): void
    {
        $tables = [
            'term_relationships', 'skills', 'skill_categories', 'experience',
            'education', 'resume_meta', 'menu_items', 'settings',
            'posts', 'projects', 'pages', 'terms', 'media',
        ];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function importSettings(array $settings): void
    {
        $map = [
            'site_title' => $settings['title'] ?? 'joshuaheidorn.com',
            'site_tagline' => $settings['tagline'] ?? '',
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)'
        );
        foreach ($map as $k => $v) {
            $stmt->execute(['k' => $k, 'v' => $v]);
        }
    }

    private function importMenu(array $menus): void
    {
        $primary = null;
        foreach ($menus as $menu) {
            if (($menu['name'] ?? '') === 'primary') {
                $primary = $menu;
            }
        }
        if ($primary === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (label, url, target, sort) VALUES (:label, :url, :target, :sort)'
        );
        foreach (($primary['items'] ?? []) as $i => $item) {
            $stmt->execute([
                'label' => $item['label'] ?? '',
                'url' => $item['url'] ?? '#',
                'target' => $item['target'] ?? null,
                'sort' => $i,
            ]);
        }
    }

    private function importTerms(array $taxonomies): void
    {
        foreach ($taxonomies as $tax) {
            $name = $tax['name'] ?? '';
            foreach (($tax['terms'] ?? []) as $term) {
                $this->termId($name, $term['slug'], $term['label'] ?? $term['slug']);
            }
        }
    }

    private function termId(string $taxonomy, string $slug, ?string $label = null): int
    {
        $key = $taxonomy . '|' . $slug;
        if (isset($this->terms[$key])) {
            return $this->terms[$key];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO terms (taxonomy, slug, label) VALUES (:t, :s, :l)'
        );
        $stmt->execute(['t' => $taxonomy, 's' => $slug, 'l' => $label ?? ucfirst($slug)]);

        return $this->terms[$key] = (int) $this->pdo->lastInsertId();
    }

    private function importMedia(): void
    {
        $src = $this->paths['uploads_src'];
        $dest = $this->paths['uploads_dest'];
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        foreach (glob($src . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $name = basename($file);
            copy($file, $dest . '/' . $name);

            $width = $height = null;
            $mime = null;
            $info = @getimagesize($file);
            if ($info !== false) {
                [$width, $height] = $info;
                $mime = $info['mime'] ?? null;
            }

            $id = $this->insertMedia('/uploads/' . $name, $name, '', $width, $height, $mime);
            $this->mediaByUlid[pathinfo($name, PATHINFO_FILENAME)] = $id;
        }
    }

    private function insertMedia(string $path, string $filename, string $alt, ?int $w, ?int $h, ?string $mime): int
    {
        if (isset($this->mediaByPath[$path])) {
            return $this->mediaByPath[$path];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO media (filename, path, alt, width, height, mime)
             VALUES (:f, :p, :a, :w, :h, :m)'
        );
        $stmt->execute(['f' => $filename, 'p' => $path, 'a' => $alt, 'w' => $w, 'h' => $h, 'm' => $mime]);

        return $this->mediaByPath[$path] = (int) $this->pdo->lastInsertId();
    }

    private function mediaFromSeedImage(?array $featured): ?int
    {
        if ($featured === null || !isset($featured['$media'])) {
            return null;
        }
        $m = $featured['$media'];
        $url = $m['url'] ?? '';
        if ($url === '') {
            return null;
        }
        return $this->insertMedia($url, $m['filename'] ?? basename($url), $m['alt'] ?? '', null, null, null);
    }

    private function mediaFromProjectImage(?array $featured): ?int
    {
        if ($featured === null || empty($featured['src'])) {
            return null;
        }
        $src = $featured['src'];
        if (preg_match('#/media/file/([^/?]+)#', $src, $match)) {
            $name = $match[1];
            $ulid = pathinfo($name, PATHINFO_FILENAME);
            if (isset($this->mediaByUlid[$ulid])) {
                return $this->mediaByUlid[$ulid];
            }
            return $this->insertMedia('/uploads/' . $name, $name, $featured['alt'] ?? '', $featured['width'] ?? null, $featured['height'] ?? null, null);
        }
        return $this->insertMedia($src, basename($src), $featured['alt'] ?? '', $featured['width'] ?? null, $featured['height'] ?? null, null);
    }

    private function publishedAt(int $index): string
    {
        return date('Y-m-d H:i:s', strtotime(self::BASE_DATE . " -{$index} days"));
    }

    private function importPosts(array $posts): void
    {
        $pt = new PortableText();
        $stmt = $this->pdo->prepare(
            'INSERT INTO posts (slug, title, excerpt, body_md, featured_image_id, status, published_at)
             VALUES (:slug, :title, :excerpt, :body, :img, :status, :published)'
        );
        foreach ($posts as $i => $post) {
            $data = $post['data'] ?? [];
            $status = $post['status'] ?? 'published';
            $stmt->execute([
                'slug' => $post['slug'] ?? Slug::make($data['title'] ?? 'post'),
                'title' => $data['title'] ?? 'Untitled',
                'excerpt' => $data['excerpt'] ?? null,
                'body' => $pt->toMarkdown($data['content'] ?? []),
                'img' => $this->mediaFromSeedImage($data['featured_image'] ?? null),
                'status' => $status,
                'published' => $status === 'published' ? $this->publishedAt($i) : null,
            ]);
            $postId = (int) $this->pdo->lastInsertId();
            $this->linkTaxonomies('post', $postId, $post['taxonomies'] ?? []);
        }
    }

    private function importPages(array $pages): void
    {
        $pt = new PortableText();
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (slug, title, body_md, status, published_at)
             VALUES (:slug, :title, :body, :status, :published)'
        );
        foreach ($pages as $i => $page) {
            $data = $page['data'] ?? [];
            $status = $page['status'] ?? 'published';
            $stmt->execute([
                'slug' => $page['slug'] ?? Slug::make($data['title'] ?? 'page'),
                'title' => $data['title'] ?? 'Untitled',
                'body' => $pt->toMarkdown($data['content'] ?? []),
                'status' => $status,
                'published' => $status === 'published' ? $this->publishedAt($i) : null,
            ]);
        }
    }

    private function importProjects(array $projects): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (slug, title, summary, body_md, source_url, external_url, featured, featured_image_id, status, published_at)
             VALUES (:slug, :title, :summary, :body, :source, :external, :featured, :img, :status, :published)'
        );
        foreach ($projects as $i => $project) {
            $body = '';
            foreach (($project['sections'] ?? []) as $section) {
                if (!empty($section['heading'])) {
                    $body .= '## ' . $section['heading'] . "\n\n";
                }
                $body .= ($section['body'] ?? '') . "\n\n";
            }
            $stmt->execute([
                'slug' => $project['slug'] ?? Slug::make($project['title'] ?? 'project'),
                'title' => $project['title'] ?? 'Untitled',
                'summary' => $project['summary'] ?? null,
                'body' => trim($body),
                'source' => $project['source_url'] ?? null,
                'external' => $project['external_url'] ?? null,
                'featured' => !empty($project['featured']) ? 1 : 0,
                'img' => $this->mediaFromProjectImage($project['featured_image'] ?? null),
                'status' => 'published',
                'published' => $project['publishedAt'] ?? $this->publishedAt($i),
            ]);
            $projectId = (int) $this->pdo->lastInsertId();
            foreach (($project['tags'] ?? []) as $label) {
                $termId = $this->termId('tag', Slug::make($label), $label);
                $this->linkTerm('project', $projectId, $termId);
            }
        }
    }

    /** @param array<string,array<int,string>> $taxonomies */
    private function linkTaxonomies(string $type, int $contentId, array $taxonomies): void
    {
        foreach ($taxonomies as $taxonomy => $slugs) {
            foreach ($slugs as $slug) {
                $this->linkTerm($type, $contentId, $this->termId($taxonomy, $slug));
            }
        }
    }

    private function linkTerm(string $type, int $contentId, int $termId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO term_relationships (term_id, content_type, content_id)
             VALUES (:term, :type, :cid)'
        );
        $stmt->execute(['term' => $termId, 'type' => $type, 'cid' => $contentId]);
    }

    private function importResume(array $resume): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO resume_meta (name, headline, headlines, summary, contact)
             VALUES (:name, :headline, :headlines, :summary, :contact)'
        );
        $stmt->execute([
            'name' => $resume['name'] ?? '',
            'headline' => $resume['headline'] ?? null,
            'headlines' => json_encode($resume['headlines'] ?? [], JSON_UNESCAPED_SLASHES),
            'summary' => $resume['summary'] ?? null,
            'contact' => json_encode($resume['contact'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
        ]);

        $exp = $this->pdo->prepare(
            'INSERT INTO experience (company, role, start, end, location, bullets, tags, sort)
             VALUES (:company, :role, :start, :end, :location, :bullets, :tags, :sort)'
        );
        foreach (($resume['experience'] ?? []) as $i => $job) {
            $exp->execute([
                'company' => $job['company'] ?? '',
                'role' => $job['role'] ?? '',
                'start' => $job['start'] ?? null,
                'end' => $job['end'] ?? null,
                'location' => $job['location'] ?? null,
                'bullets' => json_encode($job['bullets'] ?? [], JSON_UNESCAPED_SLASHES),
                'tags' => json_encode($job['tags'] ?? [], JSON_UNESCAPED_SLASHES),
                'sort' => $i,
            ]);
        }

        $edu = $this->pdo->prepare(
            'INSERT INTO education (school, degree, start, end, location, sort)
             VALUES (:school, :degree, :start, :end, :location, :sort)'
        );
        foreach (($resume['education'] ?? []) as $i => $ed) {
            $edu->execute([
                'school' => $ed['school'] ?? '',
                'degree' => $ed['degree'] ?? '',
                'start' => $ed['start'] ?? null,
                'end' => $ed['end'] ?? null,
                'location' => $ed['location'] ?? null,
                'sort' => $i,
            ]);
        }
    }

    private function importSkills(array $categories): void
    {
        $cat = $this->pdo->prepare('INSERT INTO skill_categories (name, sort) VALUES (:name, :sort)');
        $skill = $this->pdo->prepare(
            'INSERT INTO skills (category_id, name, proficiency, sort) VALUES (:cat, :name, :prof, :sort)'
        );
        foreach ($categories as $ci => $category) {
            $cat->execute(['name' => $category['name'] ?? '', 'sort' => $ci]);
            $categoryId = (int) $this->pdo->lastInsertId();
            foreach (($category['items'] ?? []) as $si => $item) {
                $skill->execute([
                    'cat' => $categoryId,
                    'name' => $item['name'] ?? '',
                    'prof' => $item['proficiency'] ?? null,
                    'sort' => $si,
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Write `bin/seed.php`**

```php
<?php

declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';

$pdo = App\Support\Database::connect($config['db']);
$importer = new App\Support\Importer($pdo, $config['paths']);
$importer->run();

echo "Import complete.\n";
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ImporterTest`
Expected: PASS (4 tests). Requires the schema applied to the testing DB (Task 6, Step 2).

- [ ] **Step 6: Run the importer against the dev DB and spot-check**

Run:
```bash
php bin/seed.php
mysql -uroot joshuaheidorn -e "SELECT (SELECT COUNT(*) FROM posts) posts, (SELECT COUNT(*) FROM projects) projects, (SELECT COUNT(*) FROM skill_categories) skill_cats, (SELECT COUNT(*) FROM media) media;"
```
Expected: `Import complete.` then `posts=8, projects=6, skill_cats=8, media>=14`.

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass (Slug, Markdown, PortableText, Database, Importer).

- [ ] **Step 8: Commit**

```bash
git add app/Support/Importer.php bin/seed.php tests/ImporterTest.php
git commit -m "Add content importer (JSON/seed -> MySQL)"
```

---

## Self-Review

**Spec coverage (Foundation slice):**
- MySQL data model → Task 6 (all tables incl. resume/skills normalized, terms/relationships, media, settings, menu). ✓ (Added `proficiency` to `skills`; dropped the redundant `migrations` table in favor of Phinx's `phinxlog`; dropped a `bylines` table since bylines are not imported.)
- Markdown editor storage + rendering → Task 4 (`Markdown`), Task 5 (`PortableText` for migration). ✓
- Content migration (Portable Text→MD, projects sections→MD, resume/skills→tables, media incl. external URLs + local uploads, taxonomies/menu/settings) → Task 7. ✓
- Config/env, Composer, Phinx, PHPUnit → Task 1. ✓
- Slug generation → Task 3. ✓

**Deferred to later plans (correct):** Slim/Twig web layer, Svelte/Vite, RSS/sitemap/SEO, PDF export, admin/auth, the default admin `users` row (created in Plan 3), deletion of the old Astro `src/`.

**Placeholder scan:** none — every step has full code or an exact command + expected output.

**Type/name consistency:** `App\Support\{Database,Slug,Markdown,PortableText,Importer}` used consistently; `Database::connect()`, `Slug::make()`, `Markdown::toHtml()`, `PortableText::toMarkdown()`, `Importer::run()` match across tasks; config keys `db`/`db_test`/`paths.{base,uploads_src,uploads_dest}` consistent between `config.php`, tests, and `bin/seed.php`; table/column names match between the migration and the importer's SQL.

**Known migration caveats (verify when reviewing imported data):**
- Post featured images are external Unsplash URLs — they render via the stored URL; no local copy.
- One project (`mypalclara`) references an image ULID that may not exist in `uploads/`; if missing, its `media.path` will point at a non-existent `/uploads/...png` (fix by re-uploading via the admin in Plan 3).
- Bylines are dropped by design.
