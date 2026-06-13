<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\ClerkAuth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private ClerkAuth $clerk)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        Auth::start(); // PHP session is kept only for CSRF tokens.
        $path = $request->getUri()->getPath();

        // The login page hosts the Clerk sign-in island; it must be reachable
        // while signed out.
        if ($path === '/admin/login') {
            return $handler->handle($request);
        }

        if (!$this->clerk->isAdmin($request)) {
            return (new SlimResponse())
                ->withHeader('Location', '/admin/login')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
