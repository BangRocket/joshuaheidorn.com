<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,string|null> setting_key => setting_value */
    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function menu(): array
    {
        return $this->pdo->query('SELECT label, url, target FROM menu_items ORDER BY sort ASC')->fetchAll();
    }
}
