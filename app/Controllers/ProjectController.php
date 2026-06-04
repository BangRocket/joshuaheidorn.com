<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use App\Support\Markdown;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ProjectController
{
    public function __construct(
        private Twig $twig,
        private ProjectRepository $projects,
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
        $projects = $this->projects->published();
        foreach ($projects as &$project) {
            $project['tags'] = $this->terms->forContent('tag', 'project', (int) $project['id']);
        }
        unset($project);

        return $this->twig->render($response, 'projects/index.twig', [
            ...$this->chrome(),
            'seo' => Seo::build(['title' => 'Projects', 'siteTitle' => $site['siteTitle'], 'url' => (string) $request->getUri()]),
            'projects' => $projects,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $project = $this->projects->findBySlug($args['slug']);
        if (!$project) {
            return $response->withHeader('Location', '/404')->withStatus(302);
        }
        $site = SiteIdentity::resolve($this->settings->all());

        return $this->twig->render($response, 'projects/show.twig', [
            ...$this->chrome(),
            'seo' => Seo::build([
                'title' => $project['title'], 'siteTitle' => $site['siteTitle'],
                'description' => $project['summary'] ?? null, 'type' => 'article',
                'image' => $project['image_path'] ?? null, 'url' => (string) $request->getUri(),
                'publishedTime' => $project['published_at'] ?? null,
            ]),
            'project' => $project,
            'bodyHtml' => (new Markdown())->toHtml((string) $project['body_md']),
            'tags' => $this->terms->forContent('tag', 'project', (int) $project['id']),
        ]);
    }
}
