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
