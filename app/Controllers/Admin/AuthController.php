<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(private Twig $twig)
    {
    }

    /**
     * Renders the page that hosts the Clerk sign-in island. Sign-in, sign-out,
     * and "already signed in" redirects are handled client-side by Clerk JS.
     */
    public function loginForm(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/login.twig', []);
    }
}
