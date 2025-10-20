<?php

namespace ModPMS;

use PDO;

class ReservationManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $search = null): array
    {
        $sql = 'SELECT r.id, r.guest_id, r.room_id, r.company_id, r.arrival_date, r.departure_date, r.status, r.notes, '
            . 'r.created_at, r.updated_at, r.created_by, r.updated_by, '
            . 'g.first_name AS guest_first_name, g.last_name AS guest_last_name, g.salutation AS guest_salutation, '
            . 'g.email AS guest_email, g.phone AS guest_phone, '
            . 'c.name AS company_name, '
            . 'rm.room_number, rm.status AS room_status, rm.category_id AS room_category_id, '
            . 'created_user.name AS created_by_name, updated_user.name AS updated_by_name '
            . 'FROM reservations r '
            . 'LEFT JOIN guests g ON g.id = r.guest_id '
            . 'LEFT JOIN companies c ON c.id = r.company_id '
            . 'LEFT JOIN rooms rm ON rm.id = r.room_id '
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
            'SELECT r.id, r.guest_id, r.room_id, r.company_id, r.arrival_date, r.departure_date, r.status, r.notes, '
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
            . '(guest_id, room_id, company_id, arrival_date, departure_date, status, notes, created_by, updated_by, created_at, updated_at) '
            . 'VALUES (:guest_id, :room_id, :company_id, :arrival_date, :departure_date, :status, :notes, :created_by, :updated_by, NOW(), NOW())'
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
            'SELECT id, guest_id, room_id, company_id, arrival_date, departure_date, status '
            . 'FROM reservations WHERE guest_id = :guest_id '
            . 'ORDER BY arrival_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['guest_id' => $guestId]);
        $reservation = $stmt->fetch();

        return $reservation !== false ? $reservation : null;
    }
}
