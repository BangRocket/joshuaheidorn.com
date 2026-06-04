<?php

declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';

$email = $_ENV['ADMIN_EMAIL'] ?? null;
$password = $_ENV['ADMIN_PASSWORD'] ?? null;
$name = $_ENV['ADMIN_NAME'] ?? 'Admin';

if (!$email || !$password) {
    fwrite(STDERR, "Set ADMIN_EMAIL and ADMIN_PASSWORD in .env first.\n");
    exit(1);
}

$pdo = App\Support\Database::connect($config['db']);
(new App\Repositories\UserRepository($pdo))->upsert($email, $name, $password);

echo "Admin user {$email} created/updated.\n";
