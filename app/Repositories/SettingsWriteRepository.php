<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsWriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    /** @param array<int,array{label:string,url:string,target?:string}> $items */
    public function saveMenu(array $items): void
    {
        $this->pdo->exec('DELETE FROM menu_items');
        $stmt = $this->pdo->prepare('INSERT INTO menu_items (label, url, target, sort) VALUES (:l,:u,:t,:s)');
        foreach (array_values($items) as $i => $item) {
            $stmt->execute([
                'l' => $item['label'] ?? '', 'u' => $item['url'] ?? '#',
                't' => $item['target'] ?? null, 's' => $i,
            ]);
        }
    }
}
