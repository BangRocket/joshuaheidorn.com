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

    /**
     * Shuffle each player's deck once, choose the first player, fix turn order,
     * and activate the game.
     *
     * @return array<string,mixed> the activated game
     * @throws ValidationException
     */
    public function start(string $code): array
    {
        $game = $this->requireGame($code);
        if ($game['status'] !== 'lobby') {
            throw new ValidationException('Game has already started.');
        }
        $players = $this->players($game['id']);
        if (count($players) < 2) {
            throw new ValidationException('Need at least 2 players to start.');
        }

        $decks = [];
        foreach ($players as $player) {
            $order = range(0, self::CARDS_PER_PLAYER - 1);
            shuffle($order);
            $decks[$player['seat']] = $order;
            $stmt = $this->pdo->prepare('UPDATE tacta_players SET deck = :deck WHERE id = :id');
            $stmt->execute([':deck' => json_encode($order), ':id' => $player['id']]);
        }

        $firstSeat = $this->firstSeat($players, $decks);
        $turnOrder = $this->clockwiseFrom($players, $firstSeat);

        $stmt = $this->pdo->prepare(
            'UPDATE tacta_games
                SET status = :status, current_seat = :seat, turn_order = :order,
                    seq = seq + 1, updated_at = :now
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => 'active',
            ':seat' => $firstSeat,
            ':order' => json_encode($turnOrder),
            ':now' => self::now(),
            ':id' => $game['id'],
        ]);

        return $this->findByCode($code);
    }

    /**
     * First player = lowest value on either outermost card; tie → lowest combined
     * value of the two outermost cards; further tie → lowest seat.
     *
     * @param list<array<string,mixed>> $players
     * @param array<int, list<int>> $decks seat => shuffled layout indices
     */
    private function firstSeat(array $players, array $decks): int
    {
        // Layout values are color-independent (all colors share the 18 layouts).
        $values = array_map(static fn ($card) => $card->value(), Deck::forColor('blue'));

        $bestKey = null;
        $bestSeat = $players[0]['seat'];
        foreach ($players as $player) {
            $order = $decks[$player['seat']];
            $top = $values[$order[0]];
            $bottom = $values[$order[count($order) - 1]];
            $key = [min($top, $bottom), $top + $bottom, $player['seat']];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $bestSeat = $player['seat'];
            }
        }

        return $bestSeat;
    }

    /**
     * Seats in clockwise (ascending-seat) order, rotated so $firstSeat leads.
     *
     * @param list<array<string,mixed>> $players
     * @return list<int>
     */
    private function clockwiseFrom(array $players, int $firstSeat): array
    {
        $seats = array_map(static fn ($p) => $p['seat'], $players);
        sort($seats);
        $index = array_search($firstSeat, $seats, true);

        return array_values(array_merge(array_slice($seats, $index), array_slice($seats, 0, $index)));
    }

    /**
     * Append a move, advance the turn, and end the game when every card is played.
     * Enforces turn ownership only; placement legality is the controller's job.
     *
     * @param array{card_id:string,draw_end:string,x:int,y:int,rotation:int,mirror:bool} $move
     * @return array<string,mixed> the updated game
     * @throws ValidationException
     */
    public function recordMove(string $code, int $seat, array $move): array
    {
        $game = $this->requireGame($code);
        if ($game['status'] !== 'active') {
            throw new ValidationException('Game is not active.');
        }
        if ($game['current_seat'] !== $seat) {
            throw new ValidationException('It is not your turn.');
        }

        $z = $this->moveCount($game['id']) + 1; // 1-based; starting card is z=0
        $newSeq = $game['seq'] + 1;

        $stmt = $this->pdo->prepare(
            'INSERT INTO tacta_moves
                (game_id, seq, seat, card_id, draw_end, x, y, rotation, mirror, z, created_at)
             VALUES (:game_id, :seq, :seat, :card_id, :draw_end, :x, :y, :rotation, :mirror, :z, :now)'
        );
        $stmt->execute([
            ':game_id' => $game['id'],
            ':seq' => $newSeq,
            ':seat' => $seat,
            ':card_id' => (string) $move['card_id'],
            ':draw_end' => $move['draw_end'] === 'bottom' ? 'bottom' : 'top',
            ':x' => (int) $move['x'],
            ':y' => (int) $move['y'],
            ':rotation' => (int) $move['rotation'],
            ':mirror' => !empty($move['mirror']) ? 1 : 0,
            ':z' => $z,
            ':now' => self::now(),
        ]);

        $totalCards = self::CARDS_PER_PLAYER * count($this->players($game['id']));
        if ($z >= $totalCards) {
            $stmt = $this->pdo->prepare(
                'UPDATE tacta_games SET status = :status, current_seat = NULL, seq = :seq, updated_at = :now WHERE id = :id'
            );
            $stmt->execute([':status' => 'done', ':seq' => $newSeq, ':now' => self::now(), ':id' => $game['id']]);
        } else {
            $next = $this->nextSeat($game, $seat);
            $stmt = $this->pdo->prepare(
                'UPDATE tacta_games SET current_seat = :seat, seq = :seq, updated_at = :now WHERE id = :id'
            );
            $stmt->execute([':seat' => $next, ':seq' => $newSeq, ':now' => self::now(), ':id' => $game['id']]);
        }

        return $this->findByCode($code);
    }

    /** @return list<array<string,mixed>> moves with seq greater than $sinceSeq, in order */
    public function movesSince(int $gameId, int $sinceSeq): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tacta_moves WHERE game_id = :id AND seq > :seq ORDER BY seq ASC'
        );
        $stmt->execute([':id' => $gameId, ':seq' => $sinceSeq]);

        return array_map([$this, 'castMove'], $stmt->fetchAll());
    }

    private function moveCount(int $gameId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tacta_moves WHERE game_id = :id');
        $stmt->execute([':id' => $gameId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $game a cast game (turn_order is an array) */
    private function nextSeat(array $game, int $seat): int
    {
        $order = $game['turn_order'];
        $index = array_search($seat, $order, true);

        return $order[($index + 1) % count($order)];
    }

    /** @param array<string,mixed> $row */
    private function castMove(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'game_id' => (int) $row['game_id'],
            'seq' => (int) $row['seq'],
            'seat' => (int) $row['seat'],
            'card_id' => $row['card_id'],
            'draw_end' => $row['draw_end'],
            'x' => (int) $row['x'],
            'y' => (int) $row['y'],
            'rotation' => (int) $row['rotation'],
            'mirror' => (bool) $row['mirror'],
            'z' => (int) $row['z'],
            'created_at' => $row['created_at'],
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
