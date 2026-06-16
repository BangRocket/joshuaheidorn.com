<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PHP session bootstrap. Identity is now owned by Clerk (see ClerkAuth);
 * the PHP session survives only to back CSRF tokens (see Csrf).
 */
final class Auth
{
    /**
     * Validate a post-login return path. Returns $next only when it is a same-site
     * relative path under a guarded route (/jobs or /admin); otherwise '/admin'.
     * Blocks open redirects (//host, https://…) and unrelated paths.
     */
    public static function safeNext(?string $next): string
    {
        $default = '/admin';
        if (!is_string($next) || $next === '') {
            return $default;
        }

        // First path segment must be exactly "jobs" or "admin".
        return preg_match('#^/(jobs|admin)($|[/?])#', $next) === 1 ? $next : $default;
    }

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
