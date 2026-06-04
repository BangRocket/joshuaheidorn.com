<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\ResumeController;
use App\Repositories\PageRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ResumeRepository;
use App\Repositories\SearchRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TermRepository;
use Slim\Views\Twig;

return function ($app, \PDO $pdo, Twig $twig): void {
    $posts = new PostRepository($pdo);
    $projects = new ProjectRepository($pdo);
    $pages = new PageRepository($pdo);
    $terms = new TermRepository($pdo);
    $resume = new ResumeRepository($pdo);
    $settings = new SettingsRepository($pdo);
    $search = new SearchRepository($pdo);

    $home = new HomeController($twig, $resume, $posts, $projects, $settings, $pages);
    $resumeCtrl = new ResumeController($twig, $resume, $settings, $pages);

    $app->get('/', [$home, 'index']);
    $app->get('/resume', [$resumeCtrl, 'show']);
};
