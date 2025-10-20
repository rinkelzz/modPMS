<?php

namespace ModPMS;

use PDO;
use PDOException;

class ReservationManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'room_id'");
            if ($column !== false) {
                $definition = $column->fetch();
                if (is_array($definition) && isset($definition['Null']) && strtoupper((string) $definition['Null']) === 'NO') {
                    $this->pdo->exec('ALTER TABLE reservations MODIFY room_id INT UNSIGNED NULL');
                }
            }
        } catch (PDOException $exception) {
            // ignore schema adjustment failures
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'category_id'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN category_id INT UNSIGNED NULL AFTER room_id');
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN room_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER category_id');
                $this->pdo->exec('ALTER TABLE reservations ADD INDEX idx_reservations_category (category_id)');
                $this->pdo->exec('UPDATE reservations r LEFT JOIN rooms rm ON rm.id = r.room_id SET r.category_id = rm.category_id WHERE r.room_id IS NOT NULL');
                $this->pdo->exec('ALTER TABLE reservations ADD CONSTRAINT fk_reservations_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE SET NULL');
            } else {
                $quantityColumn = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'room_quantity'");
                if ($quantityColumn === false || $quantityColumn->fetch() === false) {
                    $this->pdo->exec('ALTER TABLE reservations ADD COLUMN room_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER category_id');
                }
            }
        } catch (PDOException $exception) {
            // ignore schema adjustment failures
        }

        try {
            $this->pdo->exec('ALTER TABLE reservations DROP FOREIGN KEY fk_reservations_room');
        } catch (PDOException $exception) {
            // foreign key might not exist yet
        }

        try {
            $this->pdo->exec('ALTER TABLE reservations ADD CONSTRAINT fk_reservations_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            // foreign key already updated
        }

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS reservation_items (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reservation_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NULL,
                    room_id INT UNSIGNED NULL,
                    room_quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    CONSTRAINT fk_reservation_items_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reservation_items_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservation_items_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
                    INDEX idx_reservation_items_reservation (reservation_id),
                    INDEX idx_reservation_items_category (category_id),
                    INDEX idx_reservation_items_room (room_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // ignore table creation issues
        }

        try {
            $countStatement = $this->pdo->query('SELECT COUNT(*) FROM reservation_items');
            $hasItems = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;

            if ($hasItems === 0) {
                $source = $this->pdo->query('SELECT id, category_id, room_id, room_quantity FROM reservations');
                if ($source !== false) {
                    $insert = $this->pdo->prepare(
                        'INSERT INTO reservation_items (reservation_id, category_id, room_id, room_quantity, created_at, updated_at)
                         VALUES (:reservation_id, :category_id, :room_id, :room_quantity, NOW(), NOW())'
                    );

                    while ($row = $source->fetch()) {
                        if ($row === false) {
                            continue;
                        }

                        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
                        if ($reservationId <= 0) {
                            continue;
                        }

                        $categoryId = null;
                        if (isset($row['category_id']) && $row['category_id'] !== null) {
                            $categoryId = (int) $row['category_id'];
                            if ($categoryId <= 0) {
                                $categoryId = null;
                            }
                        }

                        $roomId = null;
                        if (isset($row['room_id']) && $row['room_id'] !== null) {
                            $roomId = (int) $row['room_id'];
                            if ($roomId <= 0) {
                                $roomId = null;
                            }
                        }

                        if ($categoryId === null && $roomId === null) {
                            continue;
                        }

                        $quantity = 1;
                        if (isset($row['room_quantity'])) {
                            $quantity = (int) $row['room_quantity'];
                            if ($quantity <= 0) {
                                $quantity = 1;
                            }
                        }

                        $insert->execute([
                            'reservation_id' => $reservationId,
                            'category_id' => $categoryId,
                            'room_id' => $roomId,
                            'room_quantity' => $quantity,
                        ]);
                    }
                }
            }
        } catch (PDOException $exception) {
            // ignore data migration issues
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $search = null): array
    {
        $sql = 'SELECT r.id, r.guest_id, r.room_id, r.category_id, r.room_quantity, r.company_id, r.arrival_date, r.departure_date, r.status, r.notes, '
            . 'r.created_at, r.updated_at, r.created_by, r.updated_by, '
            . 'g.first_name AS guest_first_name, g.last_name AS guest_last_name, g.salutation AS guest_salutation, '
            . 'g.email AS guest_email, g.phone AS guest_phone, '
            . 'c.name AS company_name, '
            . 'rm.room_number, rm.status AS room_status, rm.category_id AS room_category_id, '
            . 'rc.name AS reservation_category_name, '
            . 'created_user.name AS created_by_name, updated_user.name AS updated_by_name '
            . 'FROM reservations r '
            . 'LEFT JOIN guests g ON g.id = r.guest_id '
            . 'LEFT JOIN companies c ON c.id = r.company_id '
            . 'LEFT JOIN rooms rm ON rm.id = r.room_id '
            . 'LEFT JOIN room_categories rc ON rc.id = r.category_id '
            . 'LEFT JOIN users created_user ON created_user.id = r.created_by '
            . 'LEFT JOIN users updated_user ON updated_user.id = r.updated_by';

        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE (g.last_name LIKE :search '
                . 'OR g.first_name LIKE :search '
                . 'OR CONCAT_WS(" ", g.first_name, g.last_name) LIKE :search '
                . 'OR c.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY r.arrival_date ASC, r.departure_date ASC, r.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $reservations = $stmt->fetchAll();
        if (!is_array($reservations) || $reservations === []) {
            return [];
        }

        $idList = [];
        foreach ($reservations as $reservation) {
            if (isset($reservation['id'])) {
                $idList[] = (int) $reservation['id'];
            }
        }

        $itemsMap = $this->loadItemsForReservations($idList);

        foreach ($reservations as $index => $reservation) {
            $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
            $reservations[$index]['items'] = $itemsMap[$reservationId] ?? [];
        }

        return $reservations;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.guest_id, r.room_id, r.category_id, r.room_quantity, r.company_id, r.arrival_date, r.departure_date, r.status, r.notes, '
            . 'r.created_at, r.updated_at, r.created_by, r.updated_by, '
            . 'g.first_name AS guest_first_name, g.last_name AS guest_last_name, '
            . 'c.name AS company_name '
            . 'FROM reservations r '
            . 'LEFT JOIN guests g ON g.id = r.guest_id '
            . 'LEFT JOIN companies c ON c.id = r.company_id '
            . 'WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $reservation = $stmt->fetch();

        if ($reservation === false || !is_array($reservation)) {
            return null;
        }

        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        if ($reservationId > 0) {
            $items = $this->loadItemsForReservations([$reservationId]);
            $reservation['items'] = $items[$reservationId] ?? [];
        } else {
            $reservation['items'] = [];
        }

        return $reservation;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reservations '
            . '(guest_id, room_id, category_id, room_quantity, company_id, arrival_date, departure_date, status, notes, created_by, updated_by, created_at, updated_at) '
            . 'VALUES (:guest_id, :room_id, :category_id, :room_quantity, :company_id, :arrival_date, :departure_date, :status, :notes, :created_by, :updated_by, NOW(), NOW())'
        );
        $stmt->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $columns = [];
        $params = ['id' => $id];

        foreach ($payload as $field => $value) {
            $columns[] = sprintf('%s = :%s', $field, $field);
            $params[$field] = $value;
        }

        $columns[] = 'updated_at = NOW()';

        $sql = sprintf('UPDATE reservations SET %s WHERE id = :id', implode(', ', $columns));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM reservations WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function replaceItems(int $reservationId, array $items): void
    {
        $delete = $this->pdo->prepare('DELETE FROM reservation_items WHERE reservation_id = :reservation_id');
        $delete->execute(['reservation_id' => $reservationId]);

        if ($items === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO reservation_items (reservation_id, category_id, room_id, room_quantity, created_at, updated_at)
             VALUES (:reservation_id, :category_id, :room_id, :room_quantity, NOW(), NOW())'
        );

        foreach ($items as $item) {
            $categoryId = isset($item['category_id']) ? (int) $item['category_id'] : null;
            if ($categoryId !== null && $categoryId <= 0) {
                $categoryId = null;
            }

            $roomId = isset($item['room_id']) ? (int) $item['room_id'] : null;
            if ($roomId !== null && $roomId <= 0) {
                $roomId = null;
            }

            $quantity = isset($item['room_quantity']) ? (int) $item['room_quantity'] : 1;
            if ($quantity <= 0) {
                $quantity = 1;
            }

            $insert->execute([
                'reservation_id' => $reservationId,
                'category_id' => $categoryId,
                'room_id' => $roomId,
                'room_quantity' => $quantity,
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForGuest(int $guestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_id, room_id, category_id, room_quantity, company_id, arrival_date, departure_date, status '
            . 'FROM reservations WHERE guest_id = :guest_id '
            . 'ORDER BY arrival_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['guest_id' => $guestId]);
        $reservation = $stmt->fetch();

        return $reservation !== false ? $reservation : null;
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function loadItemsForReservations(array $reservationIds): array
    {
        $reservationIds = array_values(array_filter($reservationIds, static fn ($value) => (int) $value > 0));
        if ($reservationIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($reservationIds), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT ri.id, ri.reservation_id, ri.category_id, ri.room_id, ri.room_quantity, ri.created_at, ri.updated_at, '
            . 'rc.name AS category_name, rm.room_number '
            . 'FROM reservation_items ri '
            . 'LEFT JOIN room_categories rc ON rc.id = ri.category_id '
            . 'LEFT JOIN rooms rm ON rm.id = ri.room_id '
            . 'WHERE ri.reservation_id IN (' . $placeholders . ') '
            . 'ORDER BY ri.id ASC'
        );
        $stmt->execute($reservationIds);

        $items = $stmt->fetchAll();
        if (!is_array($items) || $items === []) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!isset($item['reservation_id'])) {
                continue;
            }

            $reservationId = (int) $item['reservation_id'];
            if (!isset($result[$reservationId])) {
                $result[$reservationId] = [];
            }

            $result[$reservationId][] = $item;
        }

        return $result;
    }
}
