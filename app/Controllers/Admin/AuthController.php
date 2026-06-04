<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Auth;
use App\Support\Csrf;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(private Twig $twig, private PDO $pdo)
    {
    }

    public function loginForm(Request $request, Response $response): Response
    {
        if (Auth::check()) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return $this->twig->render($response, 'admin/login.twig', [
            'csrf' => Csrf::token(),
            'error' => null,
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::validate($data['csrf'] ?? null)) {
            return $this->twig->render($response->withStatus(400), 'admin/login.twig', [
                'csrf' => Csrf::token(), 'error' => 'Invalid session token. Try again.',
            ]);
        }
        if (Auth::attempt($this->pdo, (string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''))) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return $this->twig->render($response->withStatus(401), 'admin/login.twig', [
            'csrf' => Csrf::token(), 'error' => 'Invalid email or password.',
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        Auth::logout();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }
}
