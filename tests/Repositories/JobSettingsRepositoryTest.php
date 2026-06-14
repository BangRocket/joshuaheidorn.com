<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\JobSettingsRepository;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class JobSettingsRepositoryTest extends TestCase
{
    private function repo(): JobSettingsRepository
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('DELETE FROM job_settings');
        return new JobSettingsRepository($pdo);
    }

    public function test_get_creates_default_row(): void
    {
        $this->assertSame(['stale_days' => 14], $this->repo()->get());
    }

    public function test_update_clamps_stale_days(): void
    {
        $repo = $this->repo();
        $this->assertSame(['stale_days' => 90], $repo->update(['stale_days' => 9999]));
        $this->assertSame(['stale_days' => 3], $repo->update(['stale_days' => 1]));
        $this->assertSame(['stale_days' => 30], $repo->update(['stale_days' => 30]));
    }

    public function test_update_ignores_unknown_keys(): void
    {
        $repo = $this->repo();
        $this->assertSame(['stale_days' => 14], $repo->update(['accent' => 'x', 'theme' => 'dark']));
    }
}
