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
