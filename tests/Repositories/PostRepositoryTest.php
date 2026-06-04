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

        // 8 imported posts, but one is a draft; published() returns published only.
        $this->assertCount(7, $posts);
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
