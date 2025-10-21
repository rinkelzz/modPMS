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
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rates (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    description TEXT NULL,
                    created_by INT UNSIGNED NULL,
                    updated_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rates_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE CASCADE,
                    CONSTRAINT fk_rates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    CONSTRAINT fk_rates_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_rates_category (category_id),
                    INDEX idx_rates_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // Ignore schema creation failure
        }

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $sql = 'SELECT r.*, rc.name AS category_name, rc.sort_order AS category_sort_order '
            . 'FROM rates r '
            . 'LEFT JOIN room_categories rc ON rc.id = r.category_id '
            . 'ORDER BY rc.sort_order ASC, rc.name ASC, r.name ASC';

        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return [];
        }

        $records = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($records) ? $records : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $sql = 'SELECT r.*, rc.name AS category_name '
            . 'FROM rates r '
            . 'LEFT JOIN room_categories rc ON rc.id = r.category_id '
            . 'WHERE r.id = :id';

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            return null;
        }

        $statement->execute(['id' => $id]);
        $rate = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($rate) ? $rate : null;
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
            'category_id' => $payload['category_id'],
            'base_price' => $payload['base_price'],
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
            'category_id' => $payload['category_id'],
            'base_price' => $payload['base_price'],
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

        foreach ($periods as $index => $period) {
            $periods[$index]['days_of_week_list'] = $this->parseDaysOfWeek($period['days_of_week'] ?? null);
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

        return $period;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPeriod(array $payload): int
    {
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
            'price' => $payload['price'],
            'days_of_week' => $payload['days_of_week'],
            'created_by' => $payload['created_by'] ?? null,
            'updated_by' => $payload['updated_by'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePeriod(int $id, array $payload): bool
    {
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
            'price' => $payload['price'],
            'days_of_week' => $payload['days_of_week'],
            'updated_by' => $payload['updated_by'] ?? null,
        ]);

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
    public function buildYearlyCalendar(int $rateId, int $year): array
    {
        $rate = $this->find($rateId);
        if ($rate === null) {
            return [
                'rate' => null,
                'year' => $year,
                'months' => [],
            ];
        }

        $periods = $this->periodsForRate($rateId);
        $calendar = [];
        $basePrice = isset($rate['base_price']) ? (float) $rate['base_price'] : 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $calendar[$monthKey] = [];

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateString = sprintf('%s-%02d', $monthKey, $day);
                $price = $basePrice;
                $source = 'base';
                $sourcePeriod = null;

                try {
                    $currentDate = new DateTimeImmutable($dateString);
                    $weekday = (int) $currentDate->format('N');
                } catch (Throwable $exception) {
                    $weekday = (int) date('N', strtotime($dateString));
                }

                foreach ($periods as $period) {
                    $startDate = (string) $period['start_date'];
                    $endDate = (string) $period['end_date'];

                    if ($dateString < $startDate || $dateString > $endDate) {
                        continue;
                    }

                    $daysOfWeek = $period['days_of_week_list'] ?? [];
                    if ($daysOfWeek !== [] && !in_array($weekday, $daysOfWeek, true)) {
                        continue;
                    }

                    $price = isset($period['price']) ? (float) $period['price'] : $price;
                    $source = 'period';
                    $sourcePeriod = $period;
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
            'year' => $year,
            'months' => $calendar,
        ];
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
