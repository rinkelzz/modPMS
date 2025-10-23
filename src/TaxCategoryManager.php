<?php

namespace ModPMS;

use PDO;
use PDOException;

class TaxCategoryManager
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
                'CREATE TABLE IF NOT EXISTS tax_categories (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(191) NOT NULL,
                    rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_tax_categories_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('TaxCategoryManager schema error: ' . $exception->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, rate, created_at, updated_at FROM tax_categories ORDER BY name ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, rate FROM tax_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tax_categories (name, rate, created_at, updated_at) VALUES (:name, :rate, NOW(), NOW())');
        $stmt->execute([
            'name' => $data['name'] ?? '',
            'rate' => isset($data['rate']) ? number_format((float) $data['rate'], 2, '.', '') : '0.00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare('UPDATE tax_categories SET name = :name, rate = :rate, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'] ?? '',
            'rate' => isset($data['rate']) ? number_format((float) $data['rate'], 2, '.', '') : '0.00',
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tax_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
