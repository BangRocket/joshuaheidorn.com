<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ResumeRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HomeController
{
    public function __construct(
        private Twig $twig,
        private ResumeRepository $resume,
        private PostRepository $posts,
        private ProjectRepository $projects,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $meta = $this->resume->meta();
        $email = $meta['contact']['email'] ?? '';

        $tiles = [
            ['href' => '/resume', 'label' => 'Resume', 'hint' => 'Experience & skills'],
            ['href' => '/projects', 'label' => 'Projects', 'hint' => 'Selected work'],
            ['href' => '/posts', 'label' => 'Blog', 'hint' => 'Notes & writing'],
            ['href' => $email ? 'mailto:' . $email : '#', 'label' => 'Contact', 'hint' => $email ?: 'Get in touch'],
        ];

        return $this->twig->render($response, 'home.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build([
                'title' => $meta['name'], 'siteTitle' => $site['siteTitle'],
                'description' => $meta['summary'] ?? $site['siteTagline'],
                'url' => (string) $request->getUri(),
            ]),
            'resume' => $meta,
            'tiles' => $tiles,
            'featuredProjects' => $this->projects->featured(3),
            'recentPosts' => array_slice($this->posts->published(), 0, 3),
        ]);
    }
}
