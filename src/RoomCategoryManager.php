<?php

namespace ModPMS;

use PDO;
use PDOException;

class RoomCategoryManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $needsResequence = false;

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM room_categories LIKE 'sort_order'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE room_categories ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER status');
                $needsResequence = true;
            }
        } catch (PDOException $exception) {
            // ignore schema adjustment failures
            return;
        }

        try {
            $stats = $this->pdo->query('SELECT COUNT(*) AS total, COUNT(DISTINCT sort_order) AS distinct_total FROM room_categories');
            if ($stats !== false) {
                $row = $stats->fetch();
                if (is_array($row)) {
                    $total = (int) ($row['total'] ?? 0);
                    $distinct = (int) ($row['distinct_total'] ?? 0);
                    if ($total > 0 && $distinct < $total) {
                        $needsResequence = true;
                    }
                }
            }
        } catch (PDOException $exception) {
            // ignore stats retrieval issues
        }

        if ($needsResequence) {
            $this->resequence();
        }
    }

    public function refreshSchema(): void
    {
        $this->ensureSchema();
    }

    private function resequence(): void
    {
        try {
            $statement = $this->pdo->query('SELECT id FROM room_categories ORDER BY sort_order ASC, id ASC');
            if ($statement === false) {
                return;
            }

            $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($ids) || $ids === []) {
                return;
            }

            $shouldCommit = false;
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $shouldCommit = true;
            }

            $update = $this->pdo->prepare('UPDATE room_categories SET sort_order = :sort_order WHERE id = :id');
            $position = 1;
            foreach ($ids as $categoryId) {
                $update->execute([
                    'sort_order' => $position++,
                    'id' => (int) $categoryId,
                ]);
            }

            if ($shouldCommit) {
                $this->pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    private function nextSortOrder(): int
    {
        try {
            $statement = $this->pdo->query('SELECT MAX(sort_order) FROM room_categories');
            $max = $statement !== false ? $statement->fetchColumn() : 0;
        } catch (PDOException $exception) {
            $max = 0;
        }

        return ((int) $max) + 1;
    }

    private function currentSortOrder(int $id): int
    {
        try {
            $statement = $this->pdo->prepare('SELECT sort_order FROM room_categories WHERE id = :id');
            $statement->execute(['id' => $id]);
            $value = $statement->fetchColumn();
            if ($value !== false) {
                return (int) $value;
            }
        } catch (PDOException $exception) {
            // ignore lookup issues
        }

        return $this->nextSortOrder();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, description, capacity, status, sort_order FROM room_categories ORDER BY sort_order ASC, name ASC');

        $categories = $statement ? $statement->fetchAll() : [];

        return is_array($categories) ? $categories : [];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, description, capacity, status, sort_order FROM room_categories WHERE id = :id');
        $statement->execute(['id' => $id]);

        $category = $statement->fetch();

        return $category !== false ? $category : null;
    }

    /**
     * @param array<string, mixed> $category
     */
    public function add(array $category): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO room_categories (name, description, capacity, status, sort_order)
             VALUES (:name, :description, :capacity, :status, :sort_order)'
        );

        $statement->execute([
            'name' => $category['name'],
            'description' => $category['description'] !== '' ? $category['description'] : null,
            'capacity' => (int) $category['capacity'],
            'status' => $category['status'],
            'sort_order' => isset($category['sort_order'])
                ? max(0, (int) $category['sort_order'])
                : $this->nextSortOrder(),
        ]);
    }

    /**
     * @param array<string, mixed> $category
     */
    public function update(int $id, array $category): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE room_categories SET name = :name, description = :description, capacity = :capacity, status = :status, sort_order = :sort_order WHERE id = :id'
        );

        $statement->execute([
            'name' => $category['name'],
            'description' => $category['description'] !== '' ? $category['description'] : null,
            'capacity' => (int) $category['capacity'],
            'status' => $category['status'],
            'sort_order' => isset($category['sort_order'])
                ? max(0, (int) $category['sort_order'])
                : $this->currentSortOrder($id),
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM room_categories WHERE id = :id');
        $statement->execute(['id' => $id]);

        $this->resequence();
    }

    public function move(int $id, string $direction): bool
    {
        try {
            $statement = $this->pdo->query('SELECT id FROM room_categories ORDER BY sort_order ASC, id ASC');
            if ($statement === false) {
                return false;
            }

            $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($ids) || $ids === []) {
                return false;
            }

            $ids = array_map(static fn ($value): int => (int) $value, $ids);
            $index = array_search($id, $ids, true);
            if ($index === false) {
                return false;
            }

            if ($direction === 'up') {
                if ($index === 0) {
                    return false;
                }

                $swapIndex = $index - 1;
            } elseif ($direction === 'down') {
                if ($index === count($ids) - 1) {
                    return false;
                }

                $swapIndex = $index + 1;
            } else {
                return false;
            }

            [$ids[$index], $ids[$swapIndex]] = [$ids[$swapIndex], $ids[$index]];

            $shouldCommit = false;
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $shouldCommit = true;
            }

            $update = $this->pdo->prepare('UPDATE room_categories SET sort_order = :sort_order WHERE id = :id');
            $position = 1;
            foreach ($ids as $categoryId) {
                $update->execute([
                    'sort_order' => $position++,
                    'id' => $categoryId,
                ]);
            }

            if ($shouldCommit) {
                $this->pdo->commit();
            }

            return true;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return false;
        }
    }
}
