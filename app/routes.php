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
    $postCtrl = new \App\Controllers\PostController($twig, $posts, $terms, $settings, $pages);
    $projectCtrl = new \App\Controllers\ProjectController($twig, $projects, $terms, $settings, $pages);
    $pageCtrl = new \App\Controllers\PageController($twig, $pages, $settings);
    $taxonomyCtrl = new \App\Controllers\TaxonomyController($twig, $terms, $settings, $pages);
    $searchCtrl = new \App\Controllers\SearchController($twig, $search, $settings, $pages);
    $feedCtrl = new \App\Controllers\FeedController($posts, $projects, $settings);

    $app->get('/', [$home, 'index']);
    $app->get('/resume', [$resumeCtrl, 'show']);

    $app->get('/posts', [$postCtrl, 'index']);
    $app->get('/posts/rss.xml', [$feedCtrl, 'posts']);
    $app->get('/posts/{slug}', [$postCtrl, 'show']);

    $app->get('/projects', [$projectCtrl, 'index']);
    $app->get('/projects/rss.xml', [$feedCtrl, 'projects']);
    $app->get('/projects/tags/{slug}', [$taxonomyCtrl, 'projectTag']);
    $app->get('/projects/{slug}', [$projectCtrl, 'show']);

    $app->get('/pages/{slug}', [$pageCtrl, 'show']);
    $app->get('/tag/{slug}', [$taxonomyCtrl, 'tag']);
    $app->get('/category/{slug}', [$taxonomyCtrl, 'category']);

    $app->get('/search', [$searchCtrl, 'page']);
    $app->get('/api/search', [$searchCtrl, 'api']);

    $sitemapCtrl = new \App\Controllers\SitemapController($posts, $projects, $pages);
    $errorCtrl = new \App\Controllers\ErrorController($twig, $settings, $pages);

    $app->get('/sitemap.xml', [$sitemapCtrl, 'index']);
    $app->get('/404', [$errorCtrl, 'notFound']);
};
