<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Slug;
use PDO;

final class TaxonomyWriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    private function termId(string $taxonomy, string $label): int
    {
        $slug = Slug::make($label);
        $stmt = $this->pdo->prepare('SELECT id FROM terms WHERE taxonomy = :t AND slug = :s LIMIT 1');
        $stmt->execute(['t' => $taxonomy, 's' => $slug]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $ins = $this->pdo->prepare('INSERT INTO terms (taxonomy, slug, label) VALUES (:t, :s, :l)');
        $ins->execute(['t' => $taxonomy, 's' => $slug, 'l' => $label]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Replace all relationships of one taxonomy for a content item.
     * @param array<int,string> $labels
     */
    public function sync(string $taxonomy, string $contentType, int $contentId, array $labels): void
    {
        $del = $this->pdo->prepare(
            'DELETE tr FROM term_relationships tr
             JOIN terms t ON t.id = tr.term_id
             WHERE t.taxonomy = :tax AND tr.content_type = :type AND tr.content_id = :cid'
        );
        $del->execute(['tax' => $taxonomy, 'type' => $contentType, 'cid' => $contentId]);

        $ins = $this->pdo->prepare(
            'INSERT INTO term_relationships (term_id, content_type, content_id) VALUES (:term, :type, :cid)'
        );
        foreach (array_unique(array_filter(array_map('trim', $labels))) as $label) {
            $ins->execute(['term' => $this->termId($taxonomy, $label), 'type' => $contentType, 'cid' => $contentId]);
        }
    }

    /** @return array<int,string> labels for a content item's taxonomy */
    public function labels(string $taxonomy, string $contentType, int $contentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.label FROM term_relationships tr
             JOIN terms t ON t.id = tr.term_id
             WHERE t.taxonomy = :tax AND tr.content_type = :type AND tr.content_id = :cid
             ORDER BY t.label'
        );
        $stmt->execute(['tax' => $taxonomy, 'type' => $contentType, 'cid' => $contentId]);
        return array_column($stmt->fetchAll(), 'label');
    }
}
