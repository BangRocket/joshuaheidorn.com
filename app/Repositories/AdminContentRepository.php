<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminContentRepository
{
    /** Column whitelist per type — only these are ever written. */
    private const COLUMNS = [
        'posts' => ['slug', 'title', 'excerpt', 'body_md', 'featured_image_id', 'status', 'published_at'],
        'projects' => ['slug', 'title', 'summary', 'body_md', 'source_url', 'external_url', 'featured', 'featured_image_id', 'status', 'published_at'],
        'pages' => ['slug', 'title', 'body_md', 'status', 'published_at'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function all(string $type): array
    {
        $this->assertType($type);
        return $this->pdo->query("SELECT id, title, slug, status, published_at FROM `{$type}` ORDER BY COALESCE(published_at, created_at) DESC")->fetchAll();
    }

    public function find(string $type, int $id): ?array
    {
        $this->assertType($type);
        $stmt = $this->pdo->prepare("SELECT * FROM `{$type}` WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $data @return int inserted id */
    public function create(string $type, array $data): int
    {
        $this->assertType($type);
        $cols = array_values(array_filter(self::COLUMNS[$type], fn ($c) => array_key_exists($c, $data)));
        $place = array_map(fn ($c) => ':' . $c, $cols);
        $sql = "INSERT INTO `{$type}` (" . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bind($cols, $data));
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(string $type, int $id, array $data): void
    {
        $this->assertType($type);
        $cols = array_values(array_filter(self::COLUMNS[$type], fn ($c) => array_key_exists($c, $data)));
        $set = implode(',', array_map(fn ($c) => "`{$c}` = :{$c}", $cols));
        $stmt = $this->pdo->prepare("UPDATE `{$type}` SET {$set} WHERE id = :id");
        $stmt->execute($this->bind($cols, $data) + ['id' => $id]);
    }

    public function delete(string $type, int $id): void
    {
        $this->assertType($type);
        $stmt = $this->pdo->prepare("DELETE FROM `{$type}` WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    private function bind(array $cols, array $data): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $data[$c];
        }
        return $out;
    }

    private function assertType(string $type): void
    {
        if (!isset(self::COLUMNS[$type])) {
            throw new \InvalidArgumentException("Unknown content type: {$type}");
        }
    }
}
