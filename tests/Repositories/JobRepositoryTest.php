<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\JobRepository;
use App\Support\Database;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class JobRepositoryTest extends TestCase
{
    /** @return array{0: JobRepository, 1: PDO} */
    private function repo(): array
    {
        $config = require dirname(__DIR__, 2) . '/app/config.php';
        $pdo = Database::connect($config['db_test']);
        $pdo->exec('DELETE FROM jobs');
        return [new JobRepository($pdo), $pdo];
    }

    public function test_create_sets_dates_and_returns_record(): void
    {
        [$repo] = $this->repo();
        $job = $repo->create(['company' => 'Acme', 'status' => 'applied', 'role' => 'Dev']);

        $this->assertSame('Acme', $job['company']);
        $this->assertSame('applied', $job['status']);
        $this->assertIsInt($job['id']);
        $this->assertSame($job['added'], $job['updated']); // same day on create
    }

    public function test_create_requires_company(): void
    {
        [$repo] = $this->repo();
        $this->expectException(ValidationException::class);
        $repo->create(['company' => '   ', 'status' => 'applied']);
    }

    public function test_create_rejects_bad_status(): void
    {
        [$repo] = $this->repo();
        $this->expectException(ValidationException::class);
        $repo->create(['company' => 'Acme', 'status' => 'nonsense']);
    }

    public function test_status_change_resets_updated_clock(): void
    {
        [$repo, $pdo] = $this->repo();
        $job = $repo->create(['company' => 'Acme', 'status' => 'applied']);
        // Back-date `updated` so a status change is observable.
        $stmt = $pdo->prepare("UPDATE jobs SET updated = '2020-01-01' WHERE id = :id");
        $stmt->execute([':id' => $job['id']]);

        $sameStatus = $repo->update($job['id'], ['company' => 'Acme', 'status' => 'applied']);
        $this->assertSame('2020-01-01', $sameStatus['updated']); // unchanged

        $changed = $repo->update($job['id'], ['company' => 'Acme', 'status' => 'interviewed']);
        $this->assertNotSame('2020-01-01', $changed['updated']); // reset to today
    }

    public function test_update_unknown_id_returns_null(): void
    {
        [$repo] = $this->repo();
        $this->assertNull($repo->update(999999, ['company' => 'X', 'status' => 'applied']));
    }

    public function test_delete_removes_row(): void
    {
        [$repo] = $this->repo();
        $job = $repo->create(['company' => 'Acme', 'status' => 'applied']);
        $this->assertTrue($repo->delete($job['id']));
        $this->assertNull($repo->find($job['id']));
    }
}
