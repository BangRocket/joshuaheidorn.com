<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_token_is_stable_within_session(): void
    {
        $this->assertSame(Csrf::token(), Csrf::token());
    }

    public function test_validate_accepts_current_token_rejects_others(): void
    {
        $token = Csrf::token();
        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('nope'));
        $this->assertFalse(Csrf::validate(null));
    }
}
