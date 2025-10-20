<?php

namespace ModPMS;

use PDO;

class RoomCategoryManager
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
        $statement = $this->pdo->query('SELECT id, name, description, capacity, status FROM room_categories ORDER BY name');

        $categories = $statement ? $statement->fetchAll() : [];

        return is_array($categories) ? $categories : [];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, description, capacity, status FROM room_categories WHERE id = :id');
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
            'INSERT INTO room_categories (name, description, capacity, status) VALUES (:name, :description, :capacity, :status)'
        );

        $statement->execute([
            'name' => $category['name'],
            'description' => $category['description'] !== '' ? $category['description'] : null,
            'capacity' => (int) $category['capacity'],
            'status' => $category['status'],
        ]);
    }

    /**
     * @param array<string, mixed> $category
     */
    public function update(int $id, array $category): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE room_categories SET name = :name, description = :description, capacity = :capacity, status = :status WHERE id = :id'
        );

        $statement->execute([
            'name' => $category['name'],
            'description' => $category['description'] !== '' ? $category['description'] : null,
            'capacity' => (int) $category['capacity'],
            'status' => $category['status'],
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM room_categories WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
