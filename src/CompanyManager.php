<?php

namespace ModPMS;

use PDO;

class CompanyManager
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
        $stmt = $this->pdo->query('SELECT id, name, address_street, address_postal_code, address_city, address_country, email, phone, tax_id, notes, created_at, updated_at FROM companies ORDER BY name ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, address_street, address_postal_code, address_city, address_country, email, phone, tax_id, notes FROM companies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $company = $stmt->fetch();

        return $company !== false ? $company : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO companies (name, address_street, address_postal_code, address_city, address_country, email, phone, tax_id, notes, created_at, updated_at) VALUES (:name, :address_street, :address_postal_code, :address_city, :address_country, :email, :phone, :tax_id, :notes, NOW(), NOW())');
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

        $sql = sprintf('UPDATE companies SET %s WHERE id = :id', implode(', ', $columns));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM companies WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function hasGuests(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM guests WHERE company_id = :company_id');
        $stmt->execute(['company_id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
