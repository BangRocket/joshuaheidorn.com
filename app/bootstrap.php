<?php

declare(strict_types=1);

use App\Support\AssetManifest;
use App\Support\ClerkAuth;
use App\Support\Database;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/config.php';

$pdo = Database::connect($config['db']);
$twig = Twig::create(__DIR__ . '/views', ['cache' => false, 'autoescape' => 'html']);
$assets = new AssetManifest($config['paths']['base'] . '/public/assets');

$twig->getEnvironment()->addGlobal('assets', $assets);
$twig->getEnvironment()->addGlobal('clerk_pk', $config['clerk']['publishable_key']);

$clerkAuth = new ClerkAuth(
    $config['clerk']['secret_key'],
    [$config['clerk']['app_url']],
    $config['clerk']['admin_user_id'],
);

$app = AppFactory::create();
$app->add(TwigMiddleware::create($app, $twig));

(require __DIR__ . '/routes.php')($app, $pdo, $twig, $clerkAuth);

// Render the 404 template for unmatched routes.
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setErrorHandler(
    Slim\Exception\HttpNotFoundException::class,
    function ($request) use ($app, $twig, $pdo) {
        $ctrl = new App\Controllers\ErrorController(
            $twig,
            new App\Repositories\SettingsRepository($pdo),
            new App\Repositories\PageRepository($pdo)
        );
        return $ctrl->notFound($request, $app->getResponseFactory()->createResponse());
    }
);

// Light public caching for anonymous GET responses (no edge cache anymore).
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    if ($request->getMethod() === 'GET'
        && !str_starts_with($request->getUri()->getPath(), '/api')
        && !str_starts_with($request->getUri()->getPath(), '/admin')) {
        return $response->withHeader('Cache-Control', 'public, max-age=300');
    }
    return $response;
});

return $app;
