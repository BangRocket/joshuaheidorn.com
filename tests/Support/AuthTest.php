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

    /**
     * @return array<string, array{?string, string}>
     */
    public static function nextCases(): array
    {
        return [
            'jobs root' => ['/jobs', '/jobs'],
            'jobs api path' => ['/jobs/api/jobs', '/jobs/api/jobs'],
            'jobs with query' => ['/jobs?foo=1', '/jobs?foo=1'],
            'admin root' => ['/admin', '/admin'],
            'admin deep path' => ['/admin/posts/5/edit', '/admin/posts/5/edit'],
            'null defaults to admin' => [null, '/admin'],
            'empty defaults to admin' => ['', '/admin'],
            'protocol-relative blocked' => ['//evil.com', '/admin'],
            'absolute url blocked' => ['https://evil.com/jobs', '/admin'],
            'unrelated path blocked' => ['/etc/passwd', '/admin'],
            'prefix-boundary blocked' => ['/jobsX', '/admin'],
            'admin-prefix-boundary blocked' => ['/administrators', '/admin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nextCases')]
    public function test_safe_next_whitelists_local_guarded_paths(?string $next, string $expected): void
    {
        $this->assertSame($expected, Auth::safeNext($next));
    }
}
