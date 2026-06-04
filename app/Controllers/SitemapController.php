<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SitemapController
{
    public function __construct(
        private PostRepository $posts,
        private ProjectRepository $projects,
        private PageRepository $pages,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        $urls = ['/', '/resume', '/posts', '/projects'];
        foreach ($this->posts->published() as $p) {
            $urls[] = '/posts/' . $p['slug'];
        }
        foreach ($this->projects->published() as $p) {
            $urls[] = '/projects/' . $p['slug'];
        }
        foreach ($this->pages->published() as $p) {
            $urls[] = '/pages/' . $p['slug'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $xml .= '<url><loc>' . htmlspecialchars($origin . $u) . '</loc></url>';
        }
        $xml .= '</urlset>';

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
