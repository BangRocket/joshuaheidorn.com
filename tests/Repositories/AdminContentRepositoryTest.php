<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\AdminContentRepository;
use Tests\DatabaseTestCase;

final class AdminContentRepositoryTest extends DatabaseTestCase
{
    public function test_create_update_delete_round_trip(): void
    {
        $repo = new AdminContentRepository($this->pdo);

        $id = $repo->create('posts', [
            'slug' => 'hello', 'title' => 'Hello', 'excerpt' => 'Hi',
            'body_md' => '# Hello', 'status' => 'published', 'published_at' => '2026-06-04 12:00:00',
        ]);
        $this->assertGreaterThan(0, $id);

        $repo->update('posts', $id, ['title' => 'Updated', 'slug' => 'hello', 'body_md' => 'x', 'status' => 'draft', 'published_at' => null]);
        $this->assertSame('Updated', $repo->find('posts', $id)['title']);

        $repo->delete('posts', $id);
        $this->assertNull($repo->find('posts', $id));
    }
}
