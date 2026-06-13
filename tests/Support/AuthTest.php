<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function test_start_enables_strict_session_mode(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        ini_set('session.use_strict_mode', '0');

        Auth::start();

        $this->assertSame('1', ini_get('session.use_strict_mode'));
        session_destroy();
    }
}
