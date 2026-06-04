<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingsRepository;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FeedController
{
    public function __construct(
        private PostRepository $posts,
        private ProjectRepository $projects,
        private SettingsRepository $settings,
    ) {
    }

    public function posts(Request $request, Response $response): Response
    {
        return $this->render($request, $response, 'Posts', '/posts/', $this->posts->published(), fn ($p) => $p['excerpt'] ?? '');
    }

    public function projects(Request $request, Response $response): Response
    {
        return $this->render($request, $response, 'Projects', '/projects/', $this->projects->published(), fn ($p) => $p['summary'] ?? '');
    }

    private function render(Request $request, Response $response, string $section, string $prefix, array $items, callable $desc): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0"><channel>';
        $xml .= '<title>' . htmlspecialchars($site['siteTitle'] . ' — ' . $section) . '</title>';
        $xml .= '<link>' . htmlspecialchars($origin . $prefix) . '</link>';
        $xml .= '<description>' . htmlspecialchars($site['siteTagline']) . '</description>';
        foreach ($items as $item) {
            $url = $origin . $prefix . $item['slug'];
            $xml .= '<item>';
            $xml .= '<title>' . htmlspecialchars($item['title']) . '</title>';
            $xml .= '<link>' . htmlspecialchars($url) . '</link>';
            $xml .= '<guid>' . htmlspecialchars($url) . '</guid>';
            if (!empty($item['published_at'])) {
                $xml .= '<pubDate>' . date(DATE_RSS, strtotime((string) $item['published_at'])) . '</pubDate>';
            }
            $xml .= '<description>' . htmlspecialchars((string) $desc($item)) . '</description>';
            $xml .= '</item>';
        }
        $xml .= '</channel></rss>';

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
    }
}
