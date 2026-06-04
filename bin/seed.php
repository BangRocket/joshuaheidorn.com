<?php

declare(strict_types=1);

$config = require __DIR__ . '/../app/config.php';

$pdo = App\Support\Database::connect($config['db']);
$importer = new App\Support\Importer($pdo, $config['paths']);
$importer->run();

echo "Import complete.\n";
