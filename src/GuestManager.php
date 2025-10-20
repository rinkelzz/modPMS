<?php

namespace ModPMS;

use PDO;

class GuestManager
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
        $stmt = $this->pdo->query('SELECT id, salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, created_at, updated_at FROM guests ORDER BY last_name ASC, first_name ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes FROM guests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $guest = $stmt->fetch();

        return $guest !== false ? $guest : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO guests (salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, created_at, updated_at) VALUES (:salutation, :first_name, :last_name, :date_of_birth, :nationality, :document_type, :document_number, :address_street, :address_postal_code, :address_city, :address_country, :email, :phone, :arrival_date, :departure_date, :purpose_of_stay, :notes, NOW(), NOW())');
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

        $sql = sprintf('UPDATE guests SET %s WHERE id = :id', implode(', ', $columns));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM guests WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
