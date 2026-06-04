<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ErrorController
{
    public function __construct(
        private Twig $twig,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function notFound(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $response = $this->twig->render($response, '404.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => 'Page not found', 'siteTitle' => $site['siteTitle'], 'robots' => 'noindex']),
        ]);
        return $response->withStatus(404);
    }
}
