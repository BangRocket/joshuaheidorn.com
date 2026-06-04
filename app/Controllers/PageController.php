<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Support\Markdown;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PageController
{
    public function __construct(
        private Twig $twig,
        private PageRepository $pages,
        private SettingsRepository $settings,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $page = $this->pages->findBySlug($args['slug']);
        if (!$page) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'pages/show.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => $page['title'], 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'page' => $page,
            'bodyHtml' => (new Markdown())->toHtml((string) $page['body_md']),
        ]);
    }
}
