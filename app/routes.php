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

return function ($app, \PDO $pdo, Twig $twig, \App\Support\ClerkAuth $clerkAuth): void {
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

    $pdfCtrl = new \App\Controllers\PdfController($twig, $resume);

    $app->get('/', [$home, 'index']);
    $app->get('/resume', [$resumeCtrl, 'show']);
    $app->get('/resume.pdf', [$pdfCtrl, 'resume']);

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

    // ----- Job Tracker -----
    $jobCtrl = new \App\Controllers\JobController(
        $twig,
        new \App\Repositories\JobRepository($pdo),
        new \App\Repositories\JobSettingsRepository($pdo),
    );

    // ----- Admin -----
    $authCtrl = new \App\Controllers\Admin\AuthController($twig);
    $dashCtrl = new \App\Controllers\Admin\DashboardController($twig, $pdo);
    $contentCtrl = new \App\Controllers\Admin\ContentController($twig, $pdo);
    $mediaCtrl = new \App\Controllers\Admin\MediaController($twig, $pdo);
    $adminResumeCtrl = new \App\Controllers\Admin\ResumeController($twig, $pdo);
    $adminSettingsCtrl = new \App\Controllers\Admin\SettingsController($twig, $pdo);

    $app->group('/admin', function ($group) use ($authCtrl, $dashCtrl, $contentCtrl, $mediaCtrl, $adminResumeCtrl, $adminSettingsCtrl) {
        $group->get('/login', [$authCtrl, 'loginForm']);
        $group->get('', [$dashCtrl, 'index']);

        foreach (['posts', 'projects', 'pages'] as $type) {
            $group->get("/{$type}", [$contentCtrl, 'index']);
            $group->get("/{$type}/new", [$contentCtrl, 'create']);
            $group->post("/{$type}", [$contentCtrl, 'store']);
            $group->get("/{$type}/{id}/edit", [$contentCtrl, 'edit']);
            $group->post("/{$type}/{id}", [$contentCtrl, 'update']);
            $group->post("/{$type}/{id}/delete", [$contentCtrl, 'destroy']);
        }

        $group->get('/media', [$mediaCtrl, 'index']);
        $group->post('/media', [$mediaCtrl, 'upload']);
        $group->post('/media/{id}/delete', [$mediaCtrl, 'destroy']);

        $group->get('/resume', [$adminResumeCtrl, 'edit']);
        $group->post('/resume', [$adminResumeCtrl, 'save']);

        $group->get('/settings', [$adminSettingsCtrl, 'edit']);
        $group->post('/settings', [$adminSettingsCtrl, 'save']);
    })->add(new \App\Middleware\AuthMiddleware($clerkAuth));

    $app->group('/jobs', function ($group) use ($jobCtrl) {
        $group->get('', [$jobCtrl, 'page']);
        $group->get('/api/jobs', [$jobCtrl, 'list']);
        $group->post('/api/jobs', [$jobCtrl, 'store']);
        $group->put('/api/jobs/{id}', [$jobCtrl, 'update']);
        $group->delete('/api/jobs/{id}', [$jobCtrl, 'destroy']);
        $group->get('/api/settings', [$jobCtrl, 'getSettings']);
        $group->put('/api/settings', [$jobCtrl, 'saveSettings']);
    })->add(new \App\Middleware\AuthMiddleware($clerkAuth));
};
