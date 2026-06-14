<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Single-row Job Tracker settings. Only `stale_days` survives the integration
 * (accent/theme were dropped — the site's theme switcher owns dark mode). The
 * row is created lazily so no migration seed is needed.
 */
final class JobSettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{stale_days:int} */
    public function get(): array
    {
        $this->pdo->exec('INSERT IGNORE INTO job_settings (id, stale_days) VALUES (1, 14)');
        $row = $this->pdo->query('SELECT stale_days FROM job_settings WHERE id = 1')->fetch();
        return ['stale_days' => (int) $row['stale_days']];
    }

    /**
     * @param array<string,mixed> $data partial update; only `stale_days` is honored.
     * @return array{stale_days:int}
     */
    public function update(array $data): array
    {
        $current = $this->get();
        $stale = $current['stale_days'];
        if (array_key_exists('stale_days', $data)) {
            $stale = max(3, min(90, (int) $data['stale_days'])); // clamp 3–90
        }
        $stmt = $this->pdo->prepare('UPDATE job_settings SET stale_days = :s WHERE id = 1');
        $stmt->execute([':s' => $stale]);
        return $this->get();
    }
}
