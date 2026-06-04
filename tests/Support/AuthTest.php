<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repositories\UserRepository;
use App\Support\Auth;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function test_attempt_succeeds_with_correct_password(): void
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('DELETE FROM users');

        $repo = new UserRepository($pdo);
        $repo->upsert('admin@test.local', 'Admin', 's3cret-pass');

        $_SESSION = [];
        $this->assertTrue(Auth::attempt($pdo, 'admin@test.local', 's3cret-pass'));
        $this->assertTrue(Auth::check());
        $this->assertFalse(Auth::attempt($pdo, 'admin@test.local', 'wrong'));
    }
}
