<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (file_exists($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_NAME'] ?? 'joshuaheidorn',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'db_test' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'name' => $_ENV['DB_TEST_NAME'] ?? 'joshuaheidorn_test',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'paths' => [
        'base' => $root,
        'uploads_src' => $root . '/uploads',
        'uploads_dest' => $root . '/public/uploads',
    ],
];
