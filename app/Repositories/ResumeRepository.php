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
