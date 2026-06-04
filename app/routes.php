<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

return function ($app, \PDO $pdo, Twig $twig): void {
    $app->get('/health', function (Request $request, Response $response) {
        $response->getBody()->write('ok');
        return $response;
    });
};
