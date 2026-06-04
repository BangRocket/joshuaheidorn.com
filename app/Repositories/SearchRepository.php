<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SearchRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<int,string> $collections subset of posts|projects|pages
     * @return array<int,array{title:string,collection:string,url:string,snippet:string}>
     */
    public function search(string $query, array $collections): array
    {
        $like = '%' . $query . '%';
        $out = [];

        $sources = [
            'posts' => ['table' => 'posts', 'urlPrefix' => '/posts/'],
            'projects' => ['table' => 'projects', 'urlPrefix' => '/projects/'],
            'pages' => ['table' => 'pages', 'urlPrefix' => '/pages/'],
        ];

        foreach ($collections as $collection) {
            if (!isset($sources[$collection])) {
                continue;
            }
            $s = $sources[$collection];
            $stmt = $this->pdo->prepare(
                "SELECT title, slug, body_md AS body
                 FROM `{$s['table']}`
                 WHERE status = 'published' AND (title LIKE :q1 OR body_md LIKE :q2)
                 ORDER BY published_at DESC
                 LIMIT 5"
            );
            $stmt->execute(['q1' => $like, 'q2' => $like]);
            foreach ($stmt->fetchAll() as $row) {
                $out[] = [
                    'title' => $row['title'],
                    'collection' => $collection,
                    'url' => $s['urlPrefix'] . $row['slug'],
                    'snippet' => mb_substr(trim(strip_tags((string) $row['body'])), 0, 120),
                ];
            }
        }

        return $out;
    }
}
