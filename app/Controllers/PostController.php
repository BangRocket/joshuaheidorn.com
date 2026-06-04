<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Support\Markdown;
use App\Support\ReadingTime;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PostController
{
    public function __construct(
        private Twig $twig,
        private PostRepository $posts,
        private TermRepository $terms,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    private function chrome(): array
    {
        return [
            'site' => SiteIdentity::resolve($this->settings->all()),
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
        ];
    }

    public function index(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $posts = $this->posts->published();
        foreach ($posts as &$post) {
            $post['reading_time'] = ReadingTime::minutes((string) $post['body_md']);
            $post['tags'] = $this->terms->forContent('tag', 'post', (int) $post['id']);
        }
        unset($post);

        return $this->twig->render($response, 'posts/index.twig', [
            ...$this->chrome(),
            'seo' => Seo::build(['title' => 'All Posts', 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'posts' => $posts,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->findBySlug($args['slug']);
        if (!$post) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'posts/show.twig', [
            ...$this->chrome(),
            'seo' => Seo::build([
                'title' => $post['title'], 'siteTitle' => $site['siteTitle'],
                'description' => $post['excerpt'] ?? null, 'type' => 'article',
                'image' => $post['image_path'] ?? null, 'url' => (string) $request->getUri(),
                'publishedTime' => $post['published_at'] ?? null, 'modifiedTime' => $post['updated_at'] ?? null,
            ]),
            'post' => $post,
            'bodyHtml' => (new Markdown())->toHtml((string) $post['body_md']),
            'readingTime' => ReadingTime::minutes((string) $post['body_md']),
            'tags' => $this->terms->forContent('tag', 'post', (int) $post['id']),
        ]);
    }
}
