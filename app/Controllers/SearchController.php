<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SearchRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SearchController
{
    public function __construct(
        private Twig $twig,
        private SearchRepository $search,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $site = SiteIdentity::resolve($this->settings->all());
        $results = $q !== '' ? $this->search->search($q, ['posts', 'projects', 'pages']) : [];

        return $this->twig->render($response, 'search.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build([
                'title' => $q !== '' ? 'Search: ' . $q : 'Search',
                'siteTitle' => $site['siteTitle'], 'robots' => 'noindex',
                'url' => (string) $request->getUri(),
            ]),
            'query' => $q,
            'results' => $results,
        ]);
    }

    public function api(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $collections = $params['in'] ?? ['posts', 'projects', 'pages'];
        if (!is_array($collections)) {
            $collections = [$collections];
        }
        $results = $q !== '' ? $this->search->search($q, $collections) : [];

        $response->getBody()->write((string) json_encode($results));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
