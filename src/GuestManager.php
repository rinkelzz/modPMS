<?php

namespace ModPMS;

use PDO;
use PDOException;

class GuestManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureRoomAssignmentColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT g.id, g.salutation, g.first_name, g.last_name, g.date_of_birth, g.nationality, g.document_type, g.document_number, g.address_street, g.address_postal_code, g.address_city, g.address_country, g.email, g.phone, g.arrival_date, g.departure_date, g.purpose_of_stay, g.notes, g.company_id, g.room_id, g.created_at, g.updated_at, c.name AS company_name FROM guests g LEFT JOIN companies c ON g.company_id = c.id ORDER BY c.name IS NULL ASC, c.name ASC, g.last_name ASC, g.first_name ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $limit = max(1, min($limit, 50));

        $sql = <<<SQL
SELECT
    g.id,
    g.first_name,
    g.last_name,
    g.address_street,
    g.address_postal_code,
    g.address_city,
    g.address_country,
    c.id AS company_id,
    c.name AS company_name,
    c.address_street AS company_address_street,
    c.address_postal_code AS company_address_postal_code,
    c.address_city AS company_address_city,
    c.address_country AS company_address_country
FROM guests g
LEFT JOIN companies c ON g.company_id = c.id
WHERE
    g.last_name LIKE :term_last
    OR g.first_name LIKE :term_first
    OR CONCAT_WS(' ', g.first_name, g.last_name) LIKE :term_full
    OR CONCAT_WS(' ', g.last_name, g.first_name) LIKE :term_full_reverse
    OR c.name LIKE :term_company
ORDER BY c.name IS NULL ASC, c.name ASC, g.last_name ASC, g.first_name ASC
LIMIT :limit
SQL;

        $stmt = $this->pdo->prepare($sql);
        $likeTerm = sprintf('%%%s%%', $term);
        $stmt->bindValue(':term_last', $likeTerm);
        $stmt->bindValue(':term_first', $likeTerm);
        $stmt->bindValue(':term_full', $likeTerm);
        $stmt->bindValue(':term_full_reverse', $likeTerm);
        $stmt->bindValue(':term_company', $likeTerm);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, company_id, room_id FROM guests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $guest = $stmt->fetch();

        return $guest !== false ? $guest : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO guests (salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, company_id, room_id, created_at, updated_at) VALUES (:salutation, :first_name, :last_name, :date_of_birth, :nationality, :document_type, :document_number, :address_street, :address_postal_code, :address_city, :address_country, :email, :phone, :arrival_date, :departure_date, :purpose_of_stay, :notes, :company_id, :room_id, NOW(), NOW())');
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

    private function ensureRoomAssignmentColumn(): void
    {
        try {
            $columnStatement = $this->pdo->query("SHOW COLUMNS FROM guests LIKE 'room_id'");

            if ($columnStatement === false || $columnStatement->fetch() !== false) {
                return;
            }

            $this->pdo->exec('ALTER TABLE guests ADD COLUMN room_id INT UNSIGNED NULL AFTER company_id');
            $this->pdo->exec('ALTER TABLE guests ADD INDEX idx_guests_room (room_id)');
            $this->pdo->exec('ALTER TABLE guests ADD CONSTRAINT fk_guests_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            error_log('GuestManager: unable to ensure room assignment column: ' . $exception->getMessage());
        }
    }
}
