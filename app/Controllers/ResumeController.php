<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PageRepository;
use App\Repositories\ResumeRepository;
use App\Repositories\SettingsRepository;
use App\Support\Seo;
use App\Support\SiteIdentity;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ResumeController
{
    public function __construct(
        private Twig $twig,
        private ResumeRepository $resume,
        private SettingsRepository $settings,
        private PageRepository $pages,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $site = SiteIdentity::resolve($this->settings->all());
        $meta = $this->resume->meta();

        return $this->twig->render($response, 'resume.twig', [
            'site' => $site,
            'menu' => $this->settings->menu(),
            'pages' => $this->pages->published(),
            'seo' => Seo::build([
                'title' => 'Resume · ' . $meta['name'],
                'siteTitle' => $site['siteTitle'],
                'description' => $meta['headline'] ?? null,
                'url' => (string) $request->getUri(),
            ]),
            'resume' => $meta,
            'experience' => $this->resume->experience(),
            'education' => $this->resume->education(),
            'skills' => $this->resume->skills(),
        ]);
    }
}
