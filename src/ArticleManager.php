<?php

namespace ModPMS;

use PDO;
use PDOException;

class ArticleManager
{
    public const PRICING_PER_DAY = 'per_day';
    public const PRICING_PER_PERSON_PER_DAY = 'per_person_per_day';
    public const PRICING_ONE_TIME = 'one_time';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public static function pricingTypes(): array
    {
        return [
            self::PRICING_PER_DAY => 'Pro Tag',
            self::PRICING_PER_PERSON_PER_DAY => 'Pro Person und Tag',
            self::PRICING_ONE_TIME => 'Einmalig',
        ];
    }

    private function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS articles (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(191) NOT NULL,
                    description TEXT NULL,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0,
                    pricing_type VARCHAR(32) NOT NULL DEFAULT "per_day",
                    tax_category_id INT UNSIGNED NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_articles_tax_category FOREIGN KEY (tax_category_id) REFERENCES tax_categories(id) ON DELETE SET NULL,
                    INDEX idx_articles_tax_category (tax_category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('ArticleManager schema error: ' . $exception->getMessage());
        }

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS reservation_item_articles (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reservation_item_id INT UNSIGNED NOT NULL,
                    article_id INT UNSIGNED NULL,
                    article_name VARCHAR(191) NOT NULL,
                    pricing_type VARCHAR(32) NOT NULL DEFAULT "per_day",
                    tax_category_id INT UNSIGNED NULL,
                    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                    quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                    total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_reservation_item_articles_item FOREIGN KEY (reservation_item_id) REFERENCES reservation_items(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reservation_item_articles_article FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservation_item_articles_tax_category FOREIGN KEY (tax_category_id) REFERENCES tax_categories(id) ON DELETE SET NULL,
                    INDEX idx_reservation_item_articles_item (reservation_item_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('Reservation item article schema error: ' . $exception->getMessage());
        }

        $this->migrateVatColumnsToTax();
    }

    private function migrateVatColumnsToTax(): void
    {
        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservation_item_articles LIKE 'vat_category_id'");
            if ($column !== false && $column->fetch(PDO::FETCH_ASSOC) !== false) {
                $this->pdo->exec('ALTER TABLE reservation_item_articles CHANGE COLUMN vat_category_id tax_category_id INT UNSIGNED NULL');
            }
        } catch (PDOException $exception) {
            // Column rename may fail on limited permissions – ignore to keep application working.
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservation_item_articles LIKE 'vat_rate'");
            if ($column !== false && $column->fetch(PDO::FETCH_ASSOC) !== false) {
                $this->pdo->exec('ALTER TABLE reservation_item_articles CHANGE COLUMN vat_rate tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0');
            }
        } catch (PDOException $exception) {
            // Ignore rename failures – schema updates can be rerun once permissions allow it.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT a.id, a.name, a.description, a.price, a.pricing_type, a.tax_category_id, tc.name AS tax_category_name, tc.rate AS tax_category_rate
             FROM articles a
             LEFT JOIN tax_categories tc ON tc.id = a.tax_category_id
             ORDER BY a.name ASC'
        );

        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.name, a.description, a.price, a.pricing_type, a.tax_category_id, tc.rate AS tax_category_rate
             FROM articles a
             LEFT JOIN tax_categories tc ON tc.id = a.tax_category_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO articles (name, description, price, pricing_type, tax_category_id, created_at, updated_at)
             VALUES (:name, :description, :price, :pricing_type, :tax_category_id, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'price' => isset($data['price']) ? number_format((float) $data['price'], 2, '.', '') : '0.00',
            'pricing_type' => $this->normalizePricingType($data['pricing_type'] ?? null),
            'tax_category_id' => $this->normalizeTaxCategoryId($data['tax_category_id'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE articles SET name = :name, description = :description, price = :price, pricing_type = :pricing_type, tax_category_id = :tax_category_id, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'price' => isset($data['price']) ? number_format((float) $data['price'], 2, '.', '') : '0.00',
            'pricing_type' => $this->normalizePricingType($data['pricing_type'] ?? null),
            'tax_category_id' => $this->normalizeTaxCategoryId($data['tax_category_id'] ?? null),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM articles WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function normalizePricingType(?string $value): string
    {
        $value = (string) $value;
        $types = array_keys(self::pricingTypes());
        if (!in_array($value, $types, true)) {
            return self::PRICING_PER_DAY;
        }

        return $value;
    }

    private function normalizeTaxCategoryId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
