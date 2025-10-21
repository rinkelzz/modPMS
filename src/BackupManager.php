<?php

namespace ModPMS;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

class BackupManager
{
    private PDO $pdo;

    /**
     * @var array<string, array<int, string>>
     */
    private const TABLE_COLUMNS = [
        'room_categories' => ['id', 'name', 'description', 'capacity', 'status', 'sort_order'],
        'rooms' => ['id', 'room_number', 'category_id', 'status', 'floor', 'notes'],
        'companies' => ['id', 'name', 'address_street', 'address_postal_code', 'address_city', 'address_country', 'email', 'phone', 'tax_id', 'notes', 'created_at', 'updated_at'],
        'guests' => ['id', 'salutation', 'first_name', 'last_name', 'date_of_birth', 'nationality', 'document_type', 'document_number', 'address_street', 'address_postal_code', 'address_city', 'address_country', 'email', 'phone', 'arrival_date', 'departure_date', 'purpose_of_stay', 'notes', 'company_id', 'room_id', 'created_at', 'updated_at'],
        'rates' => ['id', 'name', 'category_id', 'base_price', 'description', 'created_by', 'updated_by', 'created_at', 'updated_at'],
        'rate_category_prices' => ['id', 'rate_id', 'category_id', 'base_price', 'created_at', 'updated_at'],
        'rate_periods' => ['id', 'rate_id', 'start_date', 'end_date', 'price', 'days_of_week', 'created_by', 'updated_by', 'created_at', 'updated_at'],
        'rate_period_prices' => ['id', 'period_id', 'category_id', 'price', 'created_at', 'updated_at'],
        'rate_events' => ['id', 'rate_id', 'name', 'start_date', 'end_date', 'default_price', 'color', 'description', 'created_by', 'updated_by', 'created_at', 'updated_at'],
        'rate_event_prices' => ['id', 'event_id', 'category_id', 'price', 'created_at', 'updated_at'],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $tables = [];

        foreach (self::TABLE_COLUMNS as $table => $columns) {
            $columnList = implode(', ', array_map(static fn (string $column): string => sprintf('`%s`', $column), $columns));
            $sql = sprintf('SELECT %s FROM `%s` ORDER BY id ASC', $columnList, $table);

            $statement = $this->pdo->query($sql);
            if ($statement === false) {
                $tables[$table] = [];
                continue;
            }

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $tables[$table] = is_array($rows) ? $rows : [];
        }

        return [
            'meta' => [
                'generated_at' => gmdate('c'),
                'tables' => array_keys(self::TABLE_COLUMNS),
            ],
            'tables' => $tables,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    public function restore(array $payload): array
    {
        $tables = $payload['tables'] ?? $payload;
        if (!is_array($tables)) {
            throw new InvalidArgumentException('Ungültiges Backup-Format: "tables" Abschnitt fehlt.');
        }

        $importCounts = [];

        $disableForeignKeyChecks = static function (PDO $pdo): void {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            } catch (PDOException $exception) {
                throw new InvalidArgumentException('Fremdschlüssel konnten nicht deaktiviert werden: ' . $exception->getMessage(), 0, $exception);
            }
        };

        $enableForeignKeyChecks = static function (PDO $pdo): void {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (PDOException $exception) {
                // Re-enable errors are ignored to avoid masking original issues
            }
        };

        $this->pdo->beginTransaction();
        $foreignKeysDisabled = false;

        try {
            $disableForeignKeyChecks($this->pdo);
            $foreignKeysDisabled = true;

            foreach (self::TABLE_COLUMNS as $table => $columns) {
                $rows = $tables[$table] ?? [];
                if (!is_array($rows)) {
                    $rows = [];
                }

                $this->pdo->exec(sprintf('DELETE FROM `%s`', $table));

                if ($rows !== []) {
                    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
                    $sql = sprintf(
                        'INSERT INTO `%s` (%s) VALUES (%s)',
                        $table,
                        implode(', ', array_map(static fn (string $column): string => sprintf('`%s`', $column), $columns)),
                        implode(', ', $placeholders)
                    );

                    $statement = $this->pdo->prepare($sql);
                    if ($statement === false) {
                        throw new InvalidArgumentException('Vorbereiten des Insert-Statements für ' . $table . ' fehlgeschlagen.');
                    }

                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $params = [];
                        foreach ($columns as $column) {
                            $params[$column] = $row[$column] ?? null;
                        }

                        $statement->execute($params);
                    }
                }

                $importCounts[$table] = is_countable($rows) ? count($rows) : 0;
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        } finally {
            if ($foreignKeysDisabled) {
                $enableForeignKeyChecks($this->pdo);
            }
        }

        return $importCounts;
    }
}
