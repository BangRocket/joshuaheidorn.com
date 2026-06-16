<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Auth;
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
     *
     * A `?next=` return URL (set by AuthMiddleware) is validated and handed to the
     * sign-in island as the post-login destination, so guarded deep links — e.g.
     * `/jobs` — send you back where you started instead of always to the dashboard.
     */
    public function loginForm(Request $request, Response $response): Response
    {
        $next = $request->getQueryParams()['next'] ?? null;
        $redirectUrl = Auth::safeNext(is_string($next) ? $next : null);

        return $this->twig->render($response, 'admin/login.twig', [
            'redirect_url' => $redirectUrl,
            'login_context' => str_starts_with($redirectUrl, '/jobs') ? 'Jobs' : 'Admin',
        ]);
    }
}
