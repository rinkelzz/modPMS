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
        $stmt = $this->pdo->query('SELECT g.id, g.salutation, g.first_name, g.last_name, g.date_of_birth, g.nationality, g.document_type, g.document_number, g.address_street, g.address_postal_code, g.address_city, g.address_country, g.email, g.phone, g.arrival_date, g.departure_date, g.purpose_of_stay, g.notes, g.company_id, g.created_at, g.updated_at, c.name AS company_name FROM guests g LEFT JOIN companies c ON g.company_id = c.id ORDER BY c.name IS NULL ASC, c.name ASC, g.last_name ASC, g.first_name ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, company_id FROM guests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $guest = $stmt->fetch();

        return $guest !== false ? $guest : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO guests (salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, company_id, created_at, updated_at) VALUES (:salutation, :first_name, :last_name, :date_of_birth, :nationality, :document_type, :document_number, :address_street, :address_postal_code, :address_city, :address_country, :email, :phone, :arrival_date, :departure_date, :purpose_of_stay, :notes, :company_id, NOW(), NOW())');
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
