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
