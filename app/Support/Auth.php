<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PHP session bootstrap. Identity is now owned by Clerk (see ClerkAuth);
 * the PHP session survives only to back CSRF tokens (see Csrf).
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Refuse attacker-supplied session IDs (fixation defense).
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
            ]);
            session_start();
        }
    }
}
