<?php

namespace ModPMS;

use PDO;

class RoomManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT r.id, r.room_number AS number, r.category_id, r.status, r.floor, r.notes, rc.name AS category_name
             FROM rooms r
             LEFT JOIN room_categories rc ON rc.id = r.category_id
             ORDER BY CAST(r.room_number AS UNSIGNED), r.room_number'
        );

        $rooms = $statement ? $statement->fetchAll() : [];

        return is_array($rooms) ? $rooms : [];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, room_number, category_id, status, floor, notes FROM rooms WHERE id = :id');
        $statement->execute(['id' => $id]);

        $room = $statement->fetch();

        return $room !== false ? $room : null;
    }

    /**
     * @param array<string, mixed> $room
     */
    public function create(array $room): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rooms (room_number, category_id, status, floor, notes) VALUES (:room_number, :category_id, :status, :floor, :notes)'
        );

        $statement->execute([
            'room_number' => $room['room_number'],
            'category_id' => $room['category_id'],
            'status' => $room['status'],
            'floor' => $room['floor'] !== '' ? $room['floor'] : null,
            'notes' => $room['notes'] !== '' ? $room['notes'] : null,
        ]);
    }

    /**
     * @param array<string, mixed> $room
     */
    public function update(int $id, array $room): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE rooms SET room_number = :room_number, category_id = :category_id, status = :status, floor = :floor, notes = :notes WHERE id = :id'
        );

        $statement->execute([
            'room_number' => $room['room_number'],
            'category_id' => $room['category_id'],
            'status' => $room['status'],
            'floor' => $room['floor'] !== '' ? $room['floor'] : null,
            'notes' => $room['notes'] !== '' ? $room['notes'] : null,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM rooms WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
