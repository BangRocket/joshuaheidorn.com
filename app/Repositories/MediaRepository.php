<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MediaRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM media ORDER BY created_at DESC')->fetchAll();
    }

    public function create(string $filename, string $path, ?int $w, ?int $h, ?string $mime, string $alt = ''): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO media (filename, path, alt, width, height, mime) VALUES (:f, :p, :a, :w, :h, :m)'
        );
        $stmt->execute(['f' => $filename, 'p' => $path, 'a' => $alt, 'w' => $w, 'h' => $h, 'm' => $mime]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
