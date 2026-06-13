<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ClerkAuth;
use PHPUnit\Framework\TestCase;

final class ClerkAuthTest extends TestCase
{
    public function test_authenticated_admin_is_allowed(): void
    {
        $this->assertTrue(ClerkAuth::decide(true, 'user_abc', 'user_abc'));
    }

    public function test_authenticated_non_admin_is_denied(): void
    {
        $this->assertFalse(ClerkAuth::decide(true, 'user_other', 'user_abc'));
    }

    public function test_signed_out_is_denied(): void
    {
        $this->assertFalse(ClerkAuth::decide(false, null, 'user_abc'));
        // Even if a payload survives an unauthenticated state, the flag wins.
        $this->assertFalse(ClerkAuth::decide(false, 'user_abc', 'user_abc'));
    }

    public function test_empty_admin_id_denies_everyone(): void
    {
        // Misconfiguration must fail closed, not open.
        $this->assertFalse(ClerkAuth::decide(true, '', ''));
        $this->assertFalse(ClerkAuth::decide(true, 'user_abc', ''));
    }

    public function test_empty_subject_never_matches_a_real_admin(): void
    {
        $this->assertFalse(ClerkAuth::decide(true, '', 'user_abc'));
    }

    public function test_parse_authorized_parties_single_origin(): void
    {
        $this->assertSame(
            ['http://127.0.0.1:8088'],
            ClerkAuth::parseAuthorizedParties('http://127.0.0.1:8088')
        );
    }

    public function test_parse_authorized_parties_splits_and_trims(): void
    {
        $this->assertSame(
            ['http://127.0.0.1:8088', 'http://localhost:8088'],
            ClerkAuth::parseAuthorizedParties('http://127.0.0.1:8088, http://localhost:8088')
        );
    }

    public function test_parse_authorized_parties_drops_empty_entries(): void
    {
        $this->assertSame(
            ['https://example.com'],
            ClerkAuth::parseAuthorizedParties('https://example.com,, ')
        );
    }
}
