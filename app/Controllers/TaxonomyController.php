<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Support\ReadingTime;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class TaxonomyController
{
    public function __construct(
        private Twig $twig,
        private TermRepository $terms,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function tag(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'tag', 'Tag', $args['slug'], 'post', 'posts', '/posts/');
    }

    public function category(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'category', 'Category', $args['slug'], 'post', 'posts', '/posts/');
    }

    public function projectTag(Request $request, Response $response, array $args): Response
    {
        return $this->render($request, $response, 'tag', 'Tag', $args['slug'], 'project', 'projects', '/projects/');
    }

    private function render(Request $request, Response $response, string $taxonomy, string $label, string $slug, string $type, string $table, string $prefix): Response
    {
        $term = $this->terms->findTerm($taxonomy, $slug);
        if (!$term) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());
        $items = $this->terms->contentForTerm((int) $term['id'], $type, $table);

        if ($type === 'post') {
            foreach ($items as &$item) {
                $item['reading_time'] = ReadingTime::minutes((string) $item['body_md']);
            }
            unset($item);
        }

        return $this->twig->render($response, 'taxonomy.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build(['title' => $term['label'], 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'term' => $term,
            'taxonomyLabel' => $label,
            'kind' => $type,
            'items' => $items,
            'urlPrefix' => $prefix,
        ]);
    }
}
