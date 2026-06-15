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

    /**
     * Add a player to a lobby. The first joiner takes seat 0 and is the host.
     *
     * @return array<string,mixed> the new player (including guest_token)
     * @throws ValidationException
     */
    public function join(string $code, string $name, string $color): array
    {
        $game = $this->requireGame($code);
        if ($game['status'] !== 'lobby') {
            throw new ValidationException('Game has already started.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new ValidationException('Name is required.');
        }
        $name = mb_substr($name, 0, 64);
        if (!in_array($color, self::COLORS, true)) {
            throw new ValidationException('Invalid color.');
        }

        $players = $this->players($game['id']);
        if (count($players) >= self::MAX_PLAYERS) {
            throw new ValidationException('Game is full.');
        }
        foreach ($players as $existing) {
            if ($existing['color'] === $color) {
                throw new ValidationException('Color already taken.');
            }
        }

        $seat = count($players);
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            'INSERT INTO tacta_players
                (game_id, seat, color, display_name, guest_token, deck, is_host, joined_at)
             VALUES (:game_id, :seat, :color, :name, :token, NULL, :is_host, :joined_at)'
        );
        $stmt->execute([
            ':game_id' => $game['id'],
            ':seat' => $seat,
            ':color' => $color,
            ':name' => $name,
            ':token' => $token,
            ':is_host' => $seat === 0 ? 1 : 0,
            ':joined_at' => self::now(),
        ]);

        $this->bumpSeq($game['id']);

        return $this->playerByToken($game['id'], $token);
    }

    /** @return list<array<string,mixed>> players ordered by seat */
    public function players(int $gameId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tacta_players WHERE game_id = :id ORDER BY seat ASC');
        $stmt->execute([':id' => $gameId]);

        return array_map([$this, 'castPlayer'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function playerByToken(int $gameId, string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tacta_players WHERE game_id = :id AND guest_token = :token'
        );
        $stmt->execute([':id' => $gameId, ':token' => $token]);
        $row = $stmt->fetch();

        return $row ? $this->castPlayer($row) : null;
    }

    /** @throws ValidationException */
    private function requireGame(string $code): array
    {
        $game = $this->findByCode($code);
        if ($game === null) {
            throw new ValidationException('Game not found.');
        }

        return $game;
    }

    private function bumpSeq(int $gameId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tacta_games SET seq = seq + 1, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => self::now(), ':id' => $gameId]);
    }

    /** @param array<string,mixed> $row */
    private function castPlayer(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'seat' => (int) $row['seat'],
            'color' => $row['color'],
            'display_name' => $row['display_name'],
            'guest_token' => $row['guest_token'],
            'deck' => $row['deck'] === null ? null : json_decode($row['deck'], true),
            'is_host' => (bool) $row['is_host'],
            'joined_at' => $row['joined_at'],
        ];
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
