<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function upsert(string $email, string $name, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $this->findByEmail($email);
        if ($existing) {
            $stmt = $this->pdo->prepare('UPDATE users SET name = :n, password_hash = :h WHERE id = :id');
            $stmt->execute(['n' => $name, 'h' => $hash, 'id' => $existing['id']]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash) VALUES (:e, :n, :h)'
        );
        $stmt->execute(['e' => $email, 'n' => $name, 'h' => $hash]);
    }
}
