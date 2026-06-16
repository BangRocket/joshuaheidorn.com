<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\TactaGameRepository;
use App\Support\ValidationException;
use App\Tacta\BoardBuilder;
use App\Tacta\Deck;
use App\Tacta\MoveValidator;
use App\Tacta\Rules;
use App\Tacta\Scorer;
use App\Tacta\Side;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class TactaController
{
    public function __construct(
        private Twig $twig,
        private TactaGameRepository $repo,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'tacta.twig', []);
    }

    public function deck(Request $request, Response $response): Response
    {
        $layouts = [];
        foreach (Deck::forColor('blue') as $i => $card) {
            $edges = [];
            foreach ([Side::N, Side::E, Side::S, Side::W] as $side) {
                $edges[$side->name] = [
                    'shape' => $card->edge($side)->shape->value,
                    'dots' => $card->edge($side)->dots,
                ];
            }
            $layouts[] = [
                'index' => $i,
                'edges' => $edges,
                'value' => $card->value(),
                'suit' => $card->suit->value,
            ];
        }

        return $this->json($response, ['layouts' => $layouts]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }

        return $this->json($response, ['code' => $this->repo->createGame()['code']], 201);
    }

    public function join(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        $body = (array) $request->getParsedBody();
        try {
            $player = $this->repo->join(
                $args['code'],
                (string) ($body['name'] ?? ''),
                (string) ($body['color'] ?? ''),
            );
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        $response = $this->setTokenCookie($request, $response, $args['code'], $player['guest_token']);

        return $this->json($response, [
            'seat' => $player['seat'],
            'color' => $player['color'],
            'is_host' => $player['is_host'],
        ], 201);
    }

    public function state(Request $request, Response $response, array $args): Response
    {
        $game = $this->repo->findByCode($args['code']);
        if ($game === null) {
            return $this->json($response, ['error' => 'Game not found'], 404);
        }

        $since = (int) ($request->getQueryParams()['since'] ?? -1);
        $players = $this->repo->players($game['id']);
        $allMoves = $this->repo->movesSince($game['id'], 0);
        $counts = $this->drawCountsBySeat($allMoves);

        $publicPlayers = array_map(function (array $p) use ($counts): array {
            $played = ($counts[$p['seat']]['top'] ?? 0) + ($counts[$p['seat']]['bottom'] ?? 0);

            return [
                'seat' => $p['seat'],
                'color' => $p['color'],
                'name' => $p['display_name'],
                'is_host' => $p['is_host'],
                'remaining' => TactaGameRepository::CARDS_PER_PLAYER - $played,
            ];
        }, $players);

        $board = BoardBuilder::build($allMoves);
        $newMoves = array_map(
            [$this, 'publicMove'],
            array_values(array_filter($allMoves, static fn (array $m): bool => $m['seq'] > $since)),
        );

        $you = null;
        $me = $this->identify($request, $game, $args['code']);
        if ($me !== null) {
            $you = [
                'seat' => $me['seat'],
                'color' => $me['color'],
                'your_turn' => $game['current_seat'] === $me['seat'],
                'hand' => null,
            ];
            if ($game['status'] === 'active' && is_array($me['deck'])) {
                $seatCounts = $counts[$me['seat']] ?? ['top' => 0, 'bottom' => 0];
                $you['hand'] = $this->hand($me, $seatCounts);
                if ($you['your_turn']) {
                    $you['legal'] = $this->legalMoves($board, $me, $seatCounts);
                }
            }
        }

        return $this->json($response, [
            'code' => $game['code'],
            'status' => $game['status'],
            'seq' => $game['seq'],
            'current_seat' => $game['current_seat'],
            'players' => $publicPlayers,
            'scores' => Scorer::scores($board),
            'moves' => $newMoves,
            'you' => $you,
        ]);
    }

    public function start(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        $game = $this->repo->findByCode($args['code']);
        if ($game === null) {
            return $this->json($response, ['error' => 'Game not found'], 404);
        }
        $me = $this->identify($request, $game, $args['code']);
        if ($me === null || !$me['is_host']) {
            return $this->json($response, ['error' => 'Only the host can start the game'], 403);
        }
        try {
            $game = $this->repo->start($args['code']);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->json($response, ['ok' => true, 'seq' => $game['seq']]);
    }

    public function move(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        $game = $this->repo->findByCode($args['code']);
        if ($game === null) {
            return $this->json($response, ['error' => 'Game not found'], 404);
        }
        $me = $this->identify($request, $game, $args['code']);
        if ($me === null) {
            return $this->json($response, ['error' => 'Join the game first'], 403);
        }
        if ($game['status'] !== 'active') {
            return $this->json($response, ['error' => 'Game is not active'], 400);
        }
        if ($game['current_seat'] !== $me['seat']) {
            return $this->json($response, ['error' => 'It is not your turn'], 409);
        }

        $body = (array) $request->getParsedBody();
        $allMoves = $this->repo->movesSince($game['id'], 0);
        $board = BoardBuilder::build($allMoves);
        $counts = $this->drawCountsBySeat($allMoves)[$me['seat']] ?? ['top' => 0, 'bottom' => 0];

        try {
            $resolved = MoveValidator::validate(
                $board,
                $me['color'],
                $me['deck'],
                $counts['top'],
                $counts['bottom'],
                [
                    'draw_end' => (string) ($body['draw_end'] ?? 'top'),
                    'x' => (int) ($body['x'] ?? 0),
                    'y' => (int) ($body['y'] ?? 0),
                    'rotation' => (int) ($body['rotation'] ?? 0),
                    'mirror' => !empty($body['mirror']),
                ],
            );
            $game = $this->repo->recordMove($args['code'], $me['seat'], $resolved);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->json($response, ['ok' => true, 'seq' => $game['seq']]);
    }

    // ----- helpers -----

    private function identify(Request $request, array $game, string $code): ?array
    {
        $token = $request->getCookieParams()['tacta_' . $code] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $this->repo->playerByToken($game['id'], $token);
    }

    /**
     * @param list<array<string,mixed>> $moves
     * @return array<int,array{top:int,bottom:int}>
     */
    private function drawCountsBySeat(array $moves): array
    {
        $out = [];
        foreach ($moves as $move) {
            $seat = (int) $move['seat'];
            $out[$seat] ??= ['top' => 0, 'bottom' => 0];
            $out[$seat][$move['draw_end'] === 'bottom' ? 'bottom' : 'top']++;
        }

        return $out;
    }

    /**
     * Legal connecting placements for the current player's two outermost cards.
     *
     * @param array<string,mixed> $me
     * @param array{top:int,bottom:int} $counts
     * @return list<array{card_id:string,draw_end:string,x:int,y:int,rotation:int,mirror:bool}>
     */
    private function legalMoves(\App\Tacta\Board $board, array $me, array $counts): array
    {
        $deck = $me['deck'];
        $head = $counts['top'];
        $tail = count($deck) - 1 - $counts['bottom'];
        if ($head > $tail) {
            return [];
        }
        $ends = $head === $tail
            ? [['top', $deck[$head]]]
            : [['top', $deck[$head]], ['bottom', $deck[$tail]]];

        $cards = Deck::forColor($me['color']);
        $out = [];
        foreach ($ends as [$drawEnd, $index]) {
            foreach (Rules::legalConnects($board, $cards[$index], $me['color'], $board->nextZ()) as $placement) {
                $out[] = [
                    'card_id' => $me['color'] . '-' . ($index + 1),
                    'draw_end' => $drawEnd,
                    'x' => $placement->x,
                    'y' => $placement->y,
                    'rotation' => $placement->rotation,
                    'mirror' => $placement->mirror,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $player
     * @param array{top:int,bottom:int} $counts
     * @return array{top:?string,bottom:?string}
     */
    private function hand(array $player, array $counts): array
    {
        $deck = $player['deck'];
        $head = $counts['top'];
        $tail = count($deck) - 1 - $counts['bottom'];

        return [
            'top' => $head <= $tail ? $player['color'] . '-' . ($deck[$head] + 1) : null,
            'bottom' => $head < $tail ? $player['color'] . '-' . ($deck[$tail] + 1) : null,
        ];
    }

    /** @param array<string,mixed> $m */
    private function publicMove(array $m): array
    {
        return [
            'seq' => $m['seq'],
            'seat' => $m['seat'],
            'card_id' => $m['card_id'],
            'x' => $m['x'],
            'y' => $m['y'],
            'rotation' => $m['rotation'],
            'mirror' => $m['mirror'],
            'z' => $m['z'],
        ];
    }

    private function setTokenCookie(Request $request, Response $response, string $code, string $token): Response
    {
        // Add `; Secure` when the request reached us over HTTPS — directly or via the
        // Cloudflare `X-Forwarded-Proto` header — but not on plain-HTTP local dev.
        $https = $request->getHeaderLine('X-Forwarded-Proto') === 'https'
            || $request->getUri()->getScheme() === 'https';
        $cookie = sprintf(
            'tacta_%s=%s; Path=/tacta; HttpOnly; SameSite=Lax%s',
            $code,
            $token,
            $https ? '; Secure' : '',
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * State-changing endpoints must carry an `application/json` body. Requiring JSON
     * (a non-"simple" content type) forces a cross-origin preflight which, together with
     * the SameSite=Lax identity cookie, keeps these mutations CSRF-resistant.
     */
    private function requireJson(Request $request, Response $response): ?Response
    {
        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return $this->json($response, ['error' => 'Expected application/json'], 415);
        }

        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
