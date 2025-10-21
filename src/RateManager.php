<?php

namespace ModPMS;

use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

class RateManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function refreshSchema(): void
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->createRatesTable();
        $this->adjustLegacyRateColumns();
        $this->createRateCategoryPricesTable();
        $this->createRatePeriodsTable();
        $this->createRatePeriodPricesTable();
        $this->migrateLegacyRateData();
    }

    private function createRatesTable(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rates (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    category_id INT UNSIGNED NULL,
                    base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    description TEXT NULL,
                    created_by INT UNSIGNED NULL,
                    updated_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rates_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE SET NULL,
                    CONSTRAINT fk_rates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    CONSTRAINT fk_rates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_rates_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // Ignore schema creation failure to keep the application usable
        }
    }

    private function adjustLegacyRateColumns(): void
    {
        if ($this->tableHasColumn('rates', 'category_id')) {
            try {
                $this->pdo->exec('ALTER TABLE rates MODIFY category_id INT UNSIGNED NULL');
            } catch (PDOException $exception) {
                // Column might already be nullable or the constraint cannot be changed – safe to ignore
            }
        }
    }

    private function createRateCategoryPricesTable(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rate_category_prices (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    rate_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rate_category_price_rate FOREIGN KEY (rate_id) REFERENCES rates(id) ON DELETE CASCADE,
                    CONSTRAINT fk_rate_category_price_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE CASCADE,
                    UNIQUE KEY uniq_rate_category (rate_id, category_id),
                    INDEX idx_rate_category_prices_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // Ignore creation failure to avoid blocking the application
        }
    }

    private function createRatePeriodsTable(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rate_periods (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    rate_id INT UNSIGNED NOT NULL,
                    start_date DATE NOT NULL,
                    end_date DATE NOT NULL,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    days_of_week VARCHAR(32) NULL,
                    created_by INT UNSIGNED NULL,
                    updated_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rate_periods_rate FOREIGN KEY (rate_id) REFERENCES rates(id) ON DELETE CASCADE,
                    CONSTRAINT fk_rate_periods_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    CONSTRAINT fk_rate_periods_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_rate_periods_rate (rate_id),
                    INDEX idx_rate_periods_start_end (start_date, end_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // Ignore schema creation failure
        }
    }

    private function createRatePeriodPricesTable(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rate_period_prices (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    period_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rate_period_price_period FOREIGN KEY (period_id) REFERENCES rate_periods(id) ON DELETE CASCADE,
                    CONSTRAINT fk_rate_period_price_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE CASCADE,
                    UNIQUE KEY uniq_period_category (period_id, category_id),
                    INDEX idx_rate_period_prices_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // Ignore creation failure
        }
    }

    private function migrateLegacyRateData(): void
    {
        $legacyAssignments = [];

        if ($this->tableHasColumn('rates', 'category_id')) {
            try {
                $statement = $this->pdo->query('SELECT id, category_id, base_price FROM rates WHERE category_id IS NOT NULL');
            } catch (PDOException $exception) {
                $statement = false;
            }

            if ($statement !== false) {
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $rateId = isset($row['id']) ? (int) $row['id'] : 0;
                        $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
                        if ($rateId <= 0 || $categoryId <= 0) {
                            continue;
                        }

                        $price = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
                        $legacyAssignments[$rateId] = [
                            'category_id' => $categoryId,
                            'price' => $price,
                        ];

                        $this->upsertCategoryPrice($rateId, $categoryId, $price);
                    }
                }
            }

            if ($legacyAssignments !== []) {
                try {
                    $this->pdo->exec('UPDATE rates SET category_id = NULL WHERE category_id IS NOT NULL');
                } catch (PDOException $exception) {
                    // Ignore – if the column cannot be updated the migration still provided category prices
                }
            }
        }

        if ($legacyAssignments !== []) {
            try {
                $statement = $this->pdo->query('SELECT id, rate_id, price FROM rate_periods');
            } catch (PDOException $exception) {
                $statement = false;
            }

            if ($statement !== false) {
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $periodId = isset($row['id']) ? (int) $row['id'] : 0;
                        $rateId = isset($row['rate_id']) ? (int) $row['rate_id'] : 0;
                        if ($periodId <= 0 || $rateId <= 0) {
                            continue;
                        }

                        if (!isset($legacyAssignments[$rateId])) {
                            continue;
                        }

                        $categoryId = $legacyAssignments[$rateId]['category_id'];
                        $price = isset($row['price']) ? (float) $row['price'] : $legacyAssignments[$rateId]['price'];
                        $this->upsertPeriodCategoryPrice($periodId, $categoryId, $price);
                    }
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        try {
            $statement = $this->pdo->query('SELECT * FROM rates ORDER BY name ASC, id ASC');
        } catch (PDOException $exception) {
            return [];
        }

        if ($statement === false) {
            return [];
        }

        $rates = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rates) || $rates === []) {
            return [];
        }

        $rateIds = [];
        foreach ($rates as $rate) {
            if (isset($rate['id'])) {
                $rateIds[] = (int) $rate['id'];
            }
        }

        $categoryPrices = $this->getCategoryPricesForRates($rateIds);

        foreach ($rates as $index => $rate) {
            $rateId = isset($rate['id']) ? (int) $rate['id'] : 0;
            $rates[$index]['category_prices'] = $categoryPrices[$rateId] ?? [];
        }

        return $rates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM rates WHERE id = :id');
        if ($statement === false) {
            return null;
        }

        $statement->execute(['id' => $id]);
        $rate = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($rate)) {
            return null;
        }

        $rate['category_prices'] = $this->getCategoryPrices($id);

        return $rate;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rates (name, category_id, base_price, description, created_by, updated_by)
             VALUES (:name, :category_id, :base_price, :description, :created_by, :updated_by)'
        );

        if ($statement === false) {
            return 0;
        }

        $statement->execute([
            'name' => $payload['name'],
            'category_id' => $payload['category_id'] ?? null,
            'base_price' => $payload['base_price'] ?? '0.00',
            'description' => $payload['description'] ?? null,
            'created_by' => $payload['created_by'] ?? null,
            'updated_by' => $payload['updated_by'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE rates SET name = :name, category_id = :category_id, base_price = :base_price, description = :description,
                updated_by = :updated_by
             WHERE id = :id'
        );

        if ($statement === false) {
            return false;
        }

        $statement->execute([
            'id' => $id,
            'name' => $payload['name'],
            'category_id' => $payload['category_id'] ?? null,
            'base_price' => $payload['base_price'] ?? '0.00',
            'description' => $payload['description'] ?? null,
            'updated_by' => $payload['updated_by'] ?? null,
        ]);

        return $statement->rowCount() > 0;
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM rates WHERE id = :id');
        if ($statement === false) {
            return;
        }

        $statement->execute(['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function periodsForRate(int $rateId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM rate_periods WHERE rate_id = :rate_id ORDER BY start_date ASC, end_date ASC, id ASC'
        );

        if ($statement === false) {
            return [];
        }

        $statement->execute(['rate_id' => $rateId]);
        $periods = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($periods)) {
            return [];
        }

        $periodIds = [];
        foreach ($periods as $period) {
            if (isset($period['id'])) {
                $periodIds[] = (int) $period['id'];
            }
        }

        $periodCategoryPrices = $this->getPeriodCategoryPricesForPeriods($periodIds);

        foreach ($periods as $index => $period) {
            $periodId = isset($period['id']) ? (int) $period['id'] : 0;
            $periods[$index]['days_of_week_list'] = $this->parseDaysOfWeek($period['days_of_week'] ?? null);
            $periods[$index]['category_prices'] = $periodCategoryPrices[$periodId] ?? [];
        }

        return $periods;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPeriod(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM rate_periods WHERE id = :id');
        if ($statement === false) {
            return null;
        }

        $statement->execute(['id' => $id]);
        $period = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($period)) {
            return null;
        }

        $period['days_of_week_list'] = $this->parseDaysOfWeek($period['days_of_week'] ?? null);
        $period['category_prices'] = $this->getPeriodCategoryPrices($id);

        return $period;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPeriod(array $payload): int
    {
        $price = $payload['price'] ?? $this->determineDefaultPrice($payload['category_prices'] ?? []);

        $statement = $this->pdo->prepare(
            'INSERT INTO rate_periods (rate_id, start_date, end_date, price, days_of_week, created_by, updated_by)
             VALUES (:rate_id, :start_date, :end_date, :price, :days_of_week, :created_by, :updated_by)'
        );

        if ($statement === false) {
            return 0;
        }

        $statement->execute([
            'rate_id' => $payload['rate_id'],
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'price' => $price,
            'days_of_week' => $payload['days_of_week'],
            'created_by' => $payload['created_by'] ?? null,
            'updated_by' => $payload['updated_by'] ?? null,
        ]);

        $periodId = (int) $this->pdo->lastInsertId();

        if ($periodId > 0 && isset($payload['category_prices']) && is_array($payload['category_prices'])) {
            $removals = $payload['category_price_removals'] ?? [];
            $this->syncPeriodCategoryPrices($periodId, $payload['category_prices'], $removals);
        }

        return $periodId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePeriod(int $id, array $payload): bool
    {
        $price = $payload['price'] ?? $this->determineDefaultPrice($payload['category_prices'] ?? []);

        $statement = $this->pdo->prepare(
            'UPDATE rate_periods SET start_date = :start_date, end_date = :end_date, price = :price,
                days_of_week = :days_of_week, updated_by = :updated_by WHERE id = :id'
        );

        if ($statement === false) {
            return false;
        }

        $statement->execute([
            'id' => $id,
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'price' => $price,
            'days_of_week' => $payload['days_of_week'],
            'updated_by' => $payload['updated_by'] ?? null,
        ]);

        if (isset($payload['category_prices']) && is_array($payload['category_prices'])) {
            $removals = $payload['category_price_removals'] ?? [];
            $this->syncPeriodCategoryPrices($id, $payload['category_prices'], $removals);
        }

        return $statement->rowCount() > 0;
    }

    public function deletePeriod(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM rate_periods WHERE id = :id');
        if ($statement === false) {
            return;
        }

        $statement->execute(['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildYearlyCalendar(int $rateId, int $categoryId, int $year): array
    {
        $rate = $this->find($rateId);
        if ($rate === null) {
            return [
                'rate' => null,
                'category' => null,
                'year' => $year,
                'months' => [],
            ];
        }

        $categoryPrices = $this->getCategoryPrices($rateId);
        $basePrice = $categoryPrices[$categoryId] ?? null;

        $calendar = [];
        $periods = $this->periodsForRate($rateId);

        for ($month = 1; $month <= 12; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $calendar[$monthKey] = [];

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateString = sprintf('%s-%02d', $monthKey, $day);
                $price = $basePrice !== null ? (float) $basePrice : 0.0;
                $source = 'base';
                $sourcePeriod = null;

                try {
                    $currentDate = new DateTimeImmutable($dateString);
                    $weekday = (int) $currentDate->format('N');
                } catch (Throwable $exception) {
                    $weekday = (int) date('N', strtotime($dateString));
                }

                foreach ($periods as $period) {
                    $startDate = (string) ($period['start_date'] ?? '');
                    $endDate = (string) ($period['end_date'] ?? '');

                    if ($dateString < $startDate || $dateString > $endDate) {
                        continue;
                    }

                    $daysOfWeek = $period['days_of_week_list'] ?? [];
                    if ($daysOfWeek !== [] && !in_array($weekday, $daysOfWeek, true)) {
                        continue;
                    }

                    $periodCategoryPrices = $period['category_prices'] ?? [];
                    if (array_key_exists($categoryId, $periodCategoryPrices)) {
                        $price = (float) $periodCategoryPrices[$categoryId];
                        $source = 'period';
                        $sourcePeriod = $period;
                        continue;
                    }

                    if ($periodCategoryPrices === [] && isset($period['price'])) {
                        $price = (float) $period['price'];
                        $source = 'period';
                        $sourcePeriod = $period;
                    }
                }

                $calendar[$monthKey][] = [
                    'date' => $dateString,
                    'price' => $price,
                    'source' => $source,
                    'period_id' => $sourcePeriod['id'] ?? null,
                    'period_label' => $sourcePeriod !== null ? $this->formatPeriodLabel($sourcePeriod) : null,
                ];
            }
        }

        return [
            'rate' => $rate,
            'category' => [
                'id' => $categoryId,
                'base_price' => $basePrice,
            ],
            'year' => $year,
            'months' => $calendar,
        ];
    }

    /**
     * @return array<int, float>
     */
    public function getCategoryPrices(int $rateId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT category_id, base_price FROM rate_category_prices WHERE rate_id = :rate_id ORDER BY category_id ASC'
        );

        if ($statement === false) {
            return [];
        }

        $statement->execute(['rate_id' => $rateId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            if (!isset($row['category_id'])) {
                continue;
            }

            $categoryId = (int) $row['category_id'];
            $prices[$categoryId] = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
        }

        return $prices;
    }

    /**
     * @param array<int, string|float|null> $prices
     */
    public function syncCategoryPrices(int $rateId, array $prices, ?array $expectedCategoryIds = null): void
    {
        $existing = $this->getCategoryPrices($rateId);
        $seenCategoryIds = [];

        foreach ($prices as $categoryId => $priceValue) {
            $categoryId = (int) $categoryId;
            if ($categoryId <= 0) {
                continue;
            }

            $normalized = $this->normalisePriceInput($priceValue);
            if ($normalized === null) {
                continue;
            }

            $seenCategoryIds[$categoryId] = true;

            if (array_key_exists($categoryId, $existing)) {
                $update = $this->pdo->prepare(
                    'UPDATE rate_category_prices SET base_price = :price, updated_at = CURRENT_TIMESTAMP
                     WHERE rate_id = :rate_id AND category_id = :category_id'
                );

                if ($update !== false) {
                    $update->execute([
                        'price' => $normalized,
                        'rate_id' => $rateId,
                        'category_id' => $categoryId,
                    ]);
                }
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rate_category_prices (rate_id, category_id, base_price, created_at, updated_at)
                     VALUES (:rate_id, :category_id, :price, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );

                if ($insert !== false) {
                    $insert->execute([
                        'rate_id' => $rateId,
                        'category_id' => $categoryId,
                        'price' => $normalized,
                    ]);
                }
            }
        }

        $expected = $expectedCategoryIds ?? array_keys($prices);
        $expected = array_map('intval', $expected);

        foreach ($existing as $categoryId => $_value) {
            if (!in_array($categoryId, $expected, true) || !isset($seenCategoryIds[$categoryId])) {
                $delete = $this->pdo->prepare(
                    'DELETE FROM rate_category_prices WHERE rate_id = :rate_id AND category_id = :category_id'
                );

                if ($delete !== false) {
                    $delete->execute([
                        'rate_id' => $rateId,
                        'category_id' => $categoryId,
                    ]);
                }
            }
        }
    }

    /**
     * @param array<int, string|float|null> $prices
     * @param array<int, int> $removals
     */
    public function syncPeriodCategoryPrices(int $periodId, array $prices, array $removals = []): void
    {
        $existing = $this->getPeriodCategoryPrices($periodId);
        $processed = [];

        foreach ($prices as $categoryId => $priceValue) {
            $categoryId = (int) $categoryId;
            if ($categoryId <= 0) {
                continue;
            }

            $normalized = $this->normalisePriceInput($priceValue);
            if ($normalized === null) {
                continue;
            }

            $processed[$categoryId] = true;

            if (array_key_exists($categoryId, $existing)) {
                $update = $this->pdo->prepare(
                    'UPDATE rate_period_prices SET price = :price, updated_at = CURRENT_TIMESTAMP
                     WHERE period_id = :period_id AND category_id = :category_id'
                );

                if ($update !== false) {
                    $update->execute([
                        'price' => $normalized,
                        'period_id' => $periodId,
                        'category_id' => $categoryId,
                    ]);
                }
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rate_period_prices (period_id, category_id, price, created_at, updated_at)
                     VALUES (:period_id, :category_id, :price, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                );

                if ($insert !== false) {
                    $insert->execute([
                        'period_id' => $periodId,
                        'category_id' => $categoryId,
                        'price' => $normalized,
                    ]);
                }
            }
        }

        $removalIds = array_map('intval', $removals);
        foreach ($existing as $categoryId => $_value) {
            $categoryId = (int) $categoryId;
            if (!isset($processed[$categoryId]) && ($removalIds === [] || in_array($categoryId, $removalIds, true))) {
                $delete = $this->pdo->prepare(
                    'DELETE FROM rate_period_prices WHERE period_id = :period_id AND category_id = :category_id'
                );

                if ($delete !== false) {
                    $delete->execute([
                        'period_id' => $periodId,
                        'category_id' => $categoryId,
                    ]);
                }
            }
        }
    }

    /**
     * @return array<int, float>
     */
    public function getPeriodCategoryPrices(int $periodId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT category_id, price FROM rate_period_prices WHERE period_id = :period_id ORDER BY category_id ASC'
        );

        if ($statement === false) {
            return [];
        }

        $statement->execute(['period_id' => $periodId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            if (!isset($row['category_id'])) {
                continue;
            }

            $categoryId = (int) $row['category_id'];
            $prices[$categoryId] = isset($row['price']) ? (float) $row['price'] : 0.0;
        }

        return $prices;
    }

    /**
     * @param array<int, int> $rateIds
     * @return array<int, array<int, float>>
     */
    private function getCategoryPricesForRates(array $rateIds): array
    {
        if ($rateIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($rateIds), '?'));
        $sql = sprintf(
            'SELECT rate_id, category_id, base_price FROM rate_category_prices WHERE rate_id IN (%s) ORDER BY rate_id ASC, category_id ASC',
            $placeholders
        );

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            return [];
        }

        foreach ($rateIds as $index => $rateId) {
            $statement->bindValue($index + 1, $rateId, PDO::PARAM_INT);
        }

        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            $rateId = isset($row['rate_id']) ? (int) $row['rate_id'] : 0;
            $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            if ($rateId <= 0 || $categoryId <= 0) {
                continue;
            }

            if (!isset($prices[$rateId])) {
                $prices[$rateId] = [];
            }

            $prices[$rateId][$categoryId] = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
        }

        return $prices;
    }

    /**
     * @param array<int, int> $periodIds
     * @return array<int, array<int, float>>
     */
    private function getPeriodCategoryPricesForPeriods(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($periodIds), '?'));
        $sql = sprintf(
            'SELECT period_id, category_id, price FROM rate_period_prices WHERE period_id IN (%s) ORDER BY period_id ASC, category_id ASC',
            $placeholders
        );

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            return [];
        }

        foreach ($periodIds as $index => $periodId) {
            $statement->bindValue($index + 1, $periodId, PDO::PARAM_INT);
        }

        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            $periodId = isset($row['period_id']) ? (int) $row['period_id'] : 0;
            $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            if ($periodId <= 0 || $categoryId <= 0) {
                continue;
            }

            if (!isset($prices[$periodId])) {
                $prices[$periodId] = [];
            }

            $prices[$periodId][$categoryId] = isset($row['price']) ? (float) $row['price'] : 0.0;
        }

        return $prices;
    }

    /**
     * @param string|float|int|null $value
     */
    private function normalisePriceInput($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    /**
     * @param array<int, string|float|null> $categoryPrices
     */
    private function determineDefaultPrice(array $categoryPrices): string
    {
        foreach ($categoryPrices as $priceValue) {
            $normalized = $this->normalisePriceInput($priceValue);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return '0.00';
    }

    private function upsertCategoryPrice(int $rateId, int $categoryId, float $price): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_category_prices (rate_id, category_id, base_price, created_at, updated_at)
             VALUES (:rate_id, :category_id, :price, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE base_price = VALUES(base_price), updated_at = CURRENT_TIMESTAMP'
        );

        if ($statement === false) {
            return;
        }

        $statement->execute([
            'rate_id' => $rateId,
            'category_id' => $categoryId,
            'price' => number_format($price, 2, '.', ''),
        ]);
    }

    private function upsertPeriodCategoryPrice(int $periodId, int $categoryId, float $price): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_period_prices (period_id, category_id, price, created_at, updated_at)
             VALUES (:period_id, :category_id, :price, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = CURRENT_TIMESTAMP'
        );

        if ($statement === false) {
            return;
        }

        $statement->execute([
            'period_id' => $periodId,
            'category_id' => $categoryId,
            'price' => number_format($price, 2, '.', ''),
        ]);
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            $statement = $this->pdo->prepare(sprintf('SHOW COLUMNS FROM `%s` LIKE :column', $table));
        } catch (PDOException $exception) {
            return false;
        }

        if ($statement === false) {
            return false;
        }

        $statement->execute(['column' => $column]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($result);
    }

    /**
     * @param array<string, mixed> $period
     */
    private function formatPeriodLabel(array $period): string
    {
        $start = $period['start_date'] ?? '';
        $end = $period['end_date'] ?? '';
        $startLabel = $start;
        $endLabel = $end;

        try {
            if ($start !== '') {
                $startLabel = (new DateTimeImmutable($start))->format('d.m.Y');
            }
        } catch (Throwable $exception) {
            $startLabel = $start;
        }

        try {
            if ($end !== '') {
                $endLabel = (new DateTimeImmutable($end))->format('d.m.Y');
            }
        } catch (Throwable $exception) {
            $endLabel = $end;
        }

        $days = $period['days_of_week_list'] ?? [];
        if ($days === []) {
            return sprintf('%s – %s (alle Tage)', $startLabel, $endLabel);
        }

        $dayNames = array_map([$this, 'mapWeekdayLabel'], $days);

        return sprintf('%s – %s (%s)', $startLabel, $endLabel, implode(', ', $dayNames));
    }

    private function mapWeekdayLabel(int $weekday): string
    {
        $labels = [
            1 => 'Mo',
            2 => 'Di',
            3 => 'Mi',
            4 => 'Do',
            5 => 'Fr',
            6 => 'Sa',
            7 => 'So',
        ];

        return $labels[$weekday] ?? (string) $weekday;
    }

    /**
     * @param array<int|string> $days
     */
    public function normaliseDaysOfWeek(array $days): ?string
    {
        $uniqueDays = [];
        foreach ($days as $day) {
            $dayNumber = (int) $day;
            if ($dayNumber < 1 || $dayNumber > 7) {
                continue;
            }

            $uniqueDays[$dayNumber] = true;
        }

        if ($uniqueDays === []) {
            return null;
        }

        $sorted = array_keys($uniqueDays);
        sort($sorted);

        return implode(',', $sorted);
    }

    /**
     * @return array<int, int>
     */
    private function parseDaysOfWeek(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = explode(',', $value);
        $days = [];

        foreach ($parts as $part) {
            $dayNumber = (int) trim($part);
            if ($dayNumber >= 1 && $dayNumber <= 7) {
                $days[] = $dayNumber;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }
}
