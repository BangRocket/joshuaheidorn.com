<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ResumeWriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $resume name/headline/headlines/summary/contact/experience/education */
    public function saveResume(array $resume): void
    {
        $this->pdo->exec('DELETE FROM resume_meta');
        $stmt = $this->pdo->prepare(
            'INSERT INTO resume_meta (name, headline, headlines, summary, contact) VALUES (:n, :h, :hs, :s, :c)'
        );
        $stmt->execute([
            'n' => $resume['name'] ?? '',
            'h' => $resume['headline'] ?? null,
            'hs' => json_encode($resume['headlines'] ?? [], JSON_UNESCAPED_SLASHES),
            's' => $resume['summary'] ?? null,
            'c' => json_encode($resume['contact'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
        ]);

        $this->pdo->exec('DELETE FROM experience');
        $exp = $this->pdo->prepare(
            'INSERT INTO experience (company, role, start, end, location, bullets, tags, sort) VALUES (:co,:ro,:st,:en,:lo,:bu,:ta,:so)'
        );
        foreach (array_values($resume['experience'] ?? []) as $i => $j) {
            $exp->execute([
                'co' => $j['company'] ?? '', 'ro' => $j['role'] ?? '', 'st' => $j['start'] ?? null,
                'en' => $j['end'] ?? null, 'lo' => $j['location'] ?? null,
                'bu' => json_encode($j['bullets'] ?? [], JSON_UNESCAPED_SLASHES),
                'ta' => json_encode($j['tags'] ?? [], JSON_UNESCAPED_SLASHES), 'so' => $i,
            ]);
        }

        $this->pdo->exec('DELETE FROM education');
        $edu = $this->pdo->prepare(
            'INSERT INTO education (school, degree, start, end, location, sort) VALUES (:sc,:de,:st,:en,:lo,:so)'
        );
        foreach (array_values($resume['education'] ?? []) as $i => $e) {
            $edu->execute([
                'sc' => $e['school'] ?? '', 'de' => $e['degree'] ?? '', 'st' => $e['start'] ?? null,
                'en' => $e['end'] ?? null, 'lo' => $e['location'] ?? null, 'so' => $i,
            ]);
        }
    }

    /** @param array<int,array{name:string,items:array}> $categories */
    public function saveSkills(array $categories): void
    {
        $this->pdo->exec('DELETE FROM skills');
        $this->pdo->exec('DELETE FROM skill_categories');
        $cat = $this->pdo->prepare('INSERT INTO skill_categories (name, sort) VALUES (:n, :s)');
        $skill = $this->pdo->prepare('INSERT INTO skills (category_id, name, proficiency, sort) VALUES (:c,:n,:p,:s)');
        foreach (array_values($categories) as $ci => $category) {
            $cat->execute(['n' => $category['name'] ?? '', 's' => $ci]);
            $cid = (int) $this->pdo->lastInsertId();
            foreach (array_values($category['items'] ?? []) as $si => $item) {
                $skill->execute(['c' => $cid, 'n' => $item['name'] ?? '', 'p' => $item['proficiency'] ?? null, 's' => $si]);
            }
        }
    }
}
