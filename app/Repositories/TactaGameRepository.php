<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ValidationException;
use App\Tacta\Deck;
use PDO;

/**
 * All SQL for the Tacta room lifecycle: create → join → start → record moves,
 * plus state reads. Persistence + turn bookkeeping only; card-placement legality
 * is enforced by the Phase 3 controller using the App\Tacta engine.
 */
final class TactaGameRepository
{
    public const COLORS = ['blue', 'green', 'orange', 'pink', 'purple', 'red'];
    public const MAX_PLAYERS = 6;
    public const CARDS_PER_PLAYER = 18;

    // No ambiguous characters (I/O/0/1/L excluded).
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 6;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> the new lobby game */
    public function createGame(): array
    {
        $code = $this->generateUniqueCode();
        $now = self::now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO tacta_games (code, status, current_seat, turn_order, seq, created_at, updated_at)
             VALUES (:code, :status, NULL, NULL, 0, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':code' => $code,
            ':status' => 'lobby',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->findByCode($code);
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tacta_games WHERE code = :code');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return $row ? $this->castGame($row) : null;
    }

    private function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $stmt = $this->pdo->prepare('SELECT 1 FROM tacta_games WHERE code = :code');
            $stmt->execute([':code' => $code]);
            if ($stmt->fetchColumn() === false) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique room code.');
    }

    /** @param array<string,mixed> $row */
    private function castGame(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'status' => $row['status'],
            'current_seat' => $row['current_seat'] === null ? null : (int) $row['current_seat'],
            'turn_order' => $row['turn_order'] === null ? null : json_decode($row['turn_order'], true),
            'seq' => (int) $row['seq'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }
}
