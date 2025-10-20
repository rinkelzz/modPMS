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

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.guest_id, r.room_id, r.category_id, r.room_quantity, r.company_id, r.arrival_date, r.departure_date, r.status, r.notes, '
            . 'r.created_at, r.updated_at, r.created_by, r.updated_by '
            . 'FROM reservations r WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $reservation = $stmt->fetch();

        return $reservation !== false ? $reservation : null;
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
}
