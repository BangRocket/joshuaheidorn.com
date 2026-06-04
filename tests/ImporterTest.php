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

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    public function test_imports_expected_row_counts(): void
    {
        $this->runImport();

        $this->assertSame(8, $this->rowCount('posts'));
        $this->assertSame(1, $this->rowCount('pages'));
        $this->assertSame(6, $this->rowCount('projects'));
        $this->assertSame(8, $this->rowCount('skill_categories'));
        $this->assertSame(3, $this->rowCount('experience'));
        $this->assertSame(2, $this->rowCount('education'));
        $this->assertSame(1, $this->rowCount('resume_meta'));
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
