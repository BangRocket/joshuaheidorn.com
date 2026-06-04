<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\UserRepository;
use PDO;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
            ]);
            session_start();
        }
    }

    public static function attempt(PDO $pdo, string $email, string $password): bool
    {
        $user = (new UserRepository($pdo))->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['uname'] = $user['name'];
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
