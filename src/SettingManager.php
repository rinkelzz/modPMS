<?php

namespace ModPMS;

use PDO;
use PDOException;

class SettingManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(191) NOT NULL UNIQUE,
                    setting_value TEXT NULL,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('SettingManager: unable to ensure schema: ' . $exception->getMessage());
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return $value !== null ? (string) $value : $default;
    }

    /**
     * @param array<string> $keys
     * @return array<string, string>
     */
    public function getMany(array $keys): array
    {
        $keys = array_values(array_filter(array_unique($keys), static fn ($value) => $value !== ''));
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare(sprintf('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (%s)', $placeholders));
        $stmt->execute($keys);
        $results = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($row['setting_key'])) {
                continue;
            }

            $key = (string) $row['setting_key'];
            $value = isset($row['setting_value']) ? (string) $row['setting_value'] : '';
            $results[$key] = $value;
        }

        return $results;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
        $stmt->execute([
            'key' => $key,
            'value' => $value,
        ]);
    }
}
