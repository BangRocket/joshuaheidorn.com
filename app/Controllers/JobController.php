<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\JobRepository;
use App\Repositories\JobSettingsRepository;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class JobController
{
    public function __construct(
        private Twig $twig,
        private JobRepository $jobs,
        private JobSettingsRepository $settings,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'jobs.twig', []);
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, $this->jobs->all());
    }

    public function store(Request $request, Response $response): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        try {
            $job = $this->jobs->create((array) $request->getParsedBody());
            return $this->json($response, $job, 201);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        try {
            $job = $this->jobs->update((int) $args['id'], (array) $request->getParsedBody());
            return $job === null
                ? $this->json($response, ['error' => 'Not found'], 404)
                : $this->json($response, $job);
        } catch (ValidationException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $ok = $this->jobs->delete((int) $args['id']);
        return $ok
            ? $this->json($response, ['ok' => true])
            : $this->json($response, ['error' => 'Not found'], 404);
    }

    public function getSettings(Request $request, Response $response): Response
    {
        return $this->json($response, $this->settings->get());
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (($guard = $this->requireJson($request, $response)) !== null) {
            return $guard;
        }
        return $this->json($response, $this->settings->update((array) $request->getParsedBody()));
    }

    /**
     * State-changing endpoints must carry an `application/json` body. With Slim's
     * body parser also accepting form-urlencoded (a CORS-"simple" content type),
     * requiring JSON keeps the cross-origin preflight as a second CSRF layer on
     * top of the SameSite=Lax Clerk cookie.
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
