<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    /** Tables truncated before each test, child-first to respect FKs. */
    private const TABLES = [
        'term_relationships', 'skills', 'skill_categories', 'experience',
        'education', 'resume_meta', 'menu_items', 'settings',
        'posts', 'projects', 'pages', 'terms', 'media', 'users',
    ];

    protected function setUp(): void
    {
        $config = require dirname(__DIR__) . '/app/config.php';
        $this->pdo = Database::connect($config['db_test']);
        $this->truncateAll();
    }

    protected function truncateAll(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $table) {
            $this->pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
