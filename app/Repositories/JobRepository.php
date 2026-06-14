<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ValidationException;
use PDO;

/**
 * CRUD for job applications. The server owns the `added`/`updated` dates:
 * `added` is set once on create; `updated` is refreshed only when the status
 * changes ("status change resets the clock").
 */
final class JobRepository
{
    public const STATUSES = [
        'saved', 'applied', 'submitted', 'interviewed', 'offer', 'rejected', 'ghosted',
    ];

    private const TEXT_FIELDS = ['company', 'role', 'link', 'salary', 'location', 'description', 'notes'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs ORDER BY updated DESC, id DESC');
        $stmt->execute();
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $fields = $this->sanitize($data);
        if ($fields['company'] === '') {
            throw new ValidationException('Company is required.');
        }
        $status = $this->validStatus($data['status'] ?? 'applied');
        $today = self::today();

        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (company, role, status, link, salary, location, description, notes, added, updated)
             VALUES (:company, :role, :status, :link, :salary, :location, :description, :notes, :added, :updated)'
        );
        $stmt->execute([
            ':company' => $fields['company'],
            ':role' => $fields['role'],
            ':status' => $status,
            ':link' => $fields['link'],
            ':salary' => $fields['salary'],
            ':location' => $fields['location'],
            ':description' => $fields['description'],
            ':notes' => $fields['notes'],
            ':added' => $today,
            ':updated' => $today,
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null  null when the id does not exist
     * @throws ValidationException
     */
    public function update(int $id, array $data): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $fields = $this->sanitize($data);
        if ($fields['company'] === '') {
            throw new ValidationException('Company is required.');
        }
        $status = $this->validStatus($data['status'] ?? $existing['status']);
        $updated = $status !== $existing['status'] ? self::today() : $existing['updated'];

        $stmt = $this->pdo->prepare(
            'UPDATE jobs SET company = :company, role = :role, status = :status, link = :link,
                salary = :salary, location = :location, description = :description, notes = :notes,
                updated = :updated
             WHERE id = :id'
        );
        $stmt->execute([
            ':company' => $fields['company'],
            ':role' => $fields['role'],
            ':status' => $status,
            ':link' => $fields['link'],
            ':salary' => $fields['salary'],
            ':location' => $fields['location'],
            ':description' => $fields['description'],
            ':notes' => $fields['notes'],
            ':updated' => $updated,
            ':id' => $id,
        ]);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function sanitize(array $data): array
    {
        $out = [];
        foreach (self::TEXT_FIELDS as $f) {
            $out[$f] = trim((string) ($data[$f] ?? ''));
        }
        return $out;
    }

    /** @throws ValidationException */
    private function validStatus(mixed $status): string
    {
        $status = is_string($status) ? $status : '';
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Invalid status: ' . $status);
        }
        return $status;
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** Normalize column types for JSON output (MySQL returns strings). */
    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        foreach (['description', 'notes'] as $f) {
            $row[$f] = (string) ($row[$f] ?? '');
        }
        return $row;
    }
}
