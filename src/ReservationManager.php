<?php

namespace ModPMS;

use DateTimeImmutable;
use PDO;
use PDOException;

use ModPMS\ArticleManager;

class ReservationManager
{
    /**
     * @var array<int, string>
     */
    private const ARCHIVE_STATUSES = ['bezahlt', 'storniert'];

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
                    rate_id INT UNSIGNED NULL,
                    room_quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    occupancy INT UNSIGNED NOT NULL DEFAULT 1,
                    primary_guest_id INT UNSIGNED NULL,
                    arrival_date DATE NULL,
                    departure_date DATE NULL,
                    price_per_night DECIMAL(10,2) NULL,
                    total_price DECIMAL(10,2) NULL,
                    created_at TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    CONSTRAINT fk_reservation_items_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reservation_items_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservation_items_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservation_items_primary_guest FOREIGN KEY (primary_guest_id) REFERENCES guests(id) ON DELETE SET NULL,
                    INDEX idx_reservation_items_reservation (reservation_id),
                    INDEX idx_reservation_items_category (category_id),
                    INDEX idx_reservation_items_room (room_id),
                    INDEX idx_reservation_items_primary_guest (primary_guest_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            // ignore table creation issues
        }

        foreach ([
            'rate_id INT UNSIGNED NULL AFTER room_id',
            'arrival_date DATE NULL AFTER room_quantity',
            'departure_date DATE NULL AFTER arrival_date',
            'price_per_night DECIMAL(10,2) NULL AFTER departure_date',
            'total_price DECIMAL(10,2) NULL AFTER price_per_night',
            'occupancy INT UNSIGNED NOT NULL DEFAULT 1 AFTER room_quantity',
            'primary_guest_id INT UNSIGNED NULL AFTER occupancy',
        ] as $definition) {
            try {
                if (preg_match('/^([a-z_]+)\s/i', $definition, $matches) === 1) {
                    $columnName = $matches[1];
                    $columnCheck = $this->pdo->query("SHOW COLUMNS FROM reservation_items LIKE '" . $columnName . "'");
                    if ($columnCheck === false || $columnCheck->fetch() === false) {
                        $this->pdo->exec('ALTER TABLE reservation_items ADD COLUMN ' . $definition);
                    }
                }
            } catch (PDOException $exception) {
                // ignore column adjustments
            }
        }

        try {
            $indexCheck = $this->pdo->query("SHOW INDEX FROM reservation_items WHERE Key_name = 'idx_reservation_items_primary_guest'");
            if ($indexCheck === false || $indexCheck->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservation_items ADD INDEX idx_reservation_items_primary_guest (primary_guest_id)');
            }
        } catch (PDOException $exception) {
            // ignore index adjustments
        }

        try {
            $this->pdo->exec('ALTER TABLE reservation_items ADD CONSTRAINT fk_reservation_items_primary_guest FOREIGN KEY (primary_guest_id) REFERENCES guests(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            // ignore constraint adjustments
        }

        try {
            $this->pdo->exec('UPDATE reservation_items SET occupancy = CASE WHEN occupancy IS NULL OR occupancy <= 0 THEN GREATEST(COALESCE(room_quantity, 1), 1) ELSE occupancy END');
        } catch (PDOException $exception) {
            // ignore data backfill issues
        }

        try {
            $countStatement = $this->pdo->query('SELECT COUNT(*) FROM reservation_items');
            $hasItems = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;

            if ($hasItems === 0) {
                $source = $this->pdo->query('SELECT id, category_id, room_id, room_quantity, guest_id FROM reservations');
                if ($source !== false) {
                    $insert = $this->pdo->prepare(
                        'INSERT INTO reservation_items (reservation_id, category_id, room_id, room_quantity, occupancy, primary_guest_id, created_at, updated_at)
                         VALUES (:reservation_id, :category_id, :room_id, :room_quantity, :occupancy, :primary_guest_id, NOW(), NOW())'
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

                        $occupancy = $quantity > 0 ? $quantity : 1;

                        $primaryGuestId = null;
                        if (isset($row['guest_id']) && $row['guest_id'] !== null) {
                            $candidateGuestId = (int) $row['guest_id'];
                            if ($candidateGuestId > 0) {
                                $primaryGuestId = $candidateGuestId;
                            }
                        }

                        $insert->execute([
                            'reservation_id' => $reservationId,
                            'category_id' => $categoryId,
                            'room_id' => $roomId,
                            'room_quantity' => $quantity,
                            'occupancy' => $occupancy,
                            'primary_guest_id' => $primaryGuestId,
                        ]);
                    }
                }
            }
        } catch (PDOException $exception) {
            // ignore data migration issues
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'reservation_number'");
            $columnInfo = $column !== false ? $column->fetch() : false;
            $needsPopulation = false;

            if ($columnInfo === false) {
                $this->pdo->exec("ALTER TABLE reservations ADD COLUMN reservation_number VARCHAR(32) NOT NULL AFTER id");
                $needsPopulation = true;
            } else {
                if (!isset($columnInfo['Null']) || strtoupper((string) $columnInfo['Null']) !== 'NO') {
                    $this->pdo->exec('ALTER TABLE reservations MODIFY reservation_number VARCHAR(32) NOT NULL');
                }
            }

            $index = $this->pdo->query("SHOW INDEX FROM reservations WHERE Key_name = 'uniq_reservations_number'");
            if ($index === false || $index->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD UNIQUE INDEX uniq_reservations_number (reservation_number)');
            }

            if (!$needsPopulation) {
                $missing = $this->pdo->query("SELECT COUNT(*) FROM reservations WHERE reservation_number IS NULL OR reservation_number = ''");
                $needsPopulation = $missing !== false && (int) $missing->fetchColumn() > 0;
            }

            if ($needsPopulation) {
                $this->populateMissingReservationNumbers();
            }
        } catch (PDOException $exception) {
            // ignore reservation number adjustments
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'rate_id'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN rate_id INT UNSIGNED NULL AFTER reservation_number');
            }
        } catch (PDOException $exception) {
            // ignore missing rate column adjustments
        }

        try {
            $this->pdo->exec('ALTER TABLE reservations ADD INDEX idx_reservations_rate (rate_id)');
        } catch (PDOException $exception) {
            // index may already exist
        }

        try {
            $this->pdo->exec('ALTER TABLE reservations ADD CONSTRAINT fk_reservations_rate FOREIGN KEY (rate_id) REFERENCES rates(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            // foreign key may already exist
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'price_per_night'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN price_per_night DECIMAL(10,2) NULL AFTER status');
            }
        } catch (PDOException $exception) {
            // ignore
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'total_price'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN total_price DECIMAL(10,2) NULL AFTER price_per_night');
            }
        } catch (PDOException $exception) {
            // ignore
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'vat_rate'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN vat_rate DECIMAL(5,2) NULL AFTER total_price');
            }
        } catch (PDOException $exception) {
            // ignore
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'archived_at'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN archived_at DATETIME NULL AFTER updated_at');
            }
        } catch (PDOException $exception) {
            // ignore
        }

        try {
            $column = $this->pdo->query("SHOW COLUMNS FROM reservations LIKE 'archived_by'");
            if ($column === false || $column->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at');
            }
        } catch (PDOException $exception) {
            // ignore
        }

        try {
            $index = $this->pdo->query("SHOW INDEX FROM reservations WHERE Key_name = 'idx_reservations_archived'");
            if ($index === false || $index->fetch() === false) {
                $this->pdo->exec('ALTER TABLE reservations ADD INDEX idx_reservations_archived (archived_at)');
            }
        } catch (PDOException $exception) {
            // ignore missing index additions
        }

        try {
            $this->pdo->exec('ALTER TABLE reservations ADD CONSTRAINT fk_reservations_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            // foreign key might already exist
        }

        try {
            $this->pdo->exec(
                "UPDATE reservations SET archived_at = COALESCE(updated_at, NOW()), archived_by = COALESCE(updated_by, created_by) "
                . "WHERE status IN ('bezahlt', 'storniert') AND archived_at IS NULL"
            );
        } catch (PDOException $exception) {
            // ignore archive backfills
        }
    }

    public function refreshSchema(): void
    {
        $this->ensureSchema();
    }

    private function populateMissingReservationNumbers(): void
    {
        try {
            $existingNumbers = $this->pdo->query("SELECT reservation_number FROM reservations WHERE reservation_number IS NOT NULL AND reservation_number <> ''");
            $yearCounters = [];

            if ($existingNumbers !== false) {
                while (($value = $existingNumbers->fetchColumn()) !== false) {
                    if (!is_string($value)) {
                        continue;
                    }

                    if (preg_match('/^Res(\d{4})(\d{6})$/', $value, $matches) === 1) {
                        $year = (int) $matches[1];
                        $sequence = (int) $matches[2];
                        if (!isset($yearCounters[$year]) || $sequence > $yearCounters[$year]) {
                            $yearCounters[$year] = $sequence;
                        }
                    }
                }
            }

            $pending = $this->pdo->query("SELECT id, arrival_date, created_at FROM reservations WHERE reservation_number IS NULL OR reservation_number = '' ORDER BY id ASC");
            if ($pending === false) {
                return;
            }

            $update = $this->pdo->prepare('UPDATE reservations SET reservation_number = :reservation_number WHERE id = :id');
            if ($update === false) {
                return;
            }

            while (($row = $pending->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                $year = $this->determineReservationYear($row['arrival_date'] ?? null, $row['created_at'] ?? null);
                if (!isset($yearCounters[$year])) {
                    $yearCounters[$year] = 0;
                }

                $yearCounters[$year]++;
                $number = sprintf('Res%d%06d', $year, $yearCounters[$year]);

                $update->execute([
                    'reservation_number' => $number,
                    'id' => (int) $row['id'],
                ]);
            }
        } catch (PDOException $exception) {
            // ignore repopulation failures
        }
    }

    private function determineReservationYear(?string $primaryDate, ?string $fallbackDate): int
    {
        foreach ([$primaryDate, $fallbackDate] as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            try {
                $date = new DateTimeImmutable($value);
                return (int) $date->format('Y');
            } catch (\Throwable $exception) {
                // try next value
            }
        }

        return (int) (new DateTimeImmutable('today'))->format('Y');
    }

    private function generateReservationNumber(?int $year = null): string
    {
        $year = $year ?? (int) (new DateTimeImmutable('today'))->format('Y');
        $prefix = sprintf('Res%d', $year);

        try {
            $stmt = $this->pdo->prepare('SELECT reservation_number FROM reservations WHERE reservation_number LIKE :prefix ORDER BY reservation_number DESC LIMIT 1');
            if ($stmt !== false) {
                $stmt->execute(['prefix' => $prefix . '%']);
                $lastNumber = $stmt->fetchColumn();

                if (is_string($lastNumber) && preg_match('/^' . preg_quote($prefix, '/') . '(\d{6})$/', $lastNumber, $matches) === 1) {
                    $sequence = (int) $matches[1] + 1;
                    return sprintf('%s%06d', $prefix, $sequence);
                }
            }
        } catch (PDOException $exception) {
            // ignore lookup failures and fall back to default sequence
        }

        return sprintf('%s%06d', $prefix, 1);
    }

    /**
     * @param string $sort
     * @return array<int, array<string, mixed>>
     */
    public function all(
        ?string $search = null,
        bool $includeArchived = false,
        bool $archivedOnly = false,
        string $sort = 'created_desc'
    ): array
    {
        $sql = 'SELECT r.id, r.reservation_number, r.rate_id, r.guest_id, r.room_id, r.category_id, r.room_quantity, r.company_id, r.arrival_date, r.departure_date, r.status, '
            . 'r.price_per_night, r.total_price, r.vat_rate, r.notes, '
            . 'r.created_at, r.updated_at, r.created_by, r.updated_by, r.archived_at, r.archived_by, '
            . 'g.first_name AS guest_first_name, g.last_name AS guest_last_name, g.salutation AS guest_salutation, '
            . 'g.email AS guest_email, g.phone AS guest_phone, '
            . 'c.name AS company_name, '
            . 'rm.room_number, rm.status AS room_status, rm.category_id AS room_category_id, '
            . 'rc.name AS reservation_category_name, '
            . 'created_user.name AS created_by_name, updated_user.name AS updated_by_name, '
            . 'rate.name AS rate_name '
            . 'FROM reservations r '
            . 'LEFT JOIN guests g ON g.id = r.guest_id '
            . 'LEFT JOIN companies c ON c.id = r.company_id '
            . 'LEFT JOIN rooms rm ON rm.id = r.room_id '
            . 'LEFT JOIN room_categories rc ON rc.id = r.category_id '
            . 'LEFT JOIN rates rate ON rate.id = r.rate_id '
            . 'LEFT JOIN users created_user ON created_user.id = r.created_by '
            . 'LEFT JOIN users updated_user ON updated_user.id = r.updated_by';

        $params = [];
        $conditions = [];

        if ($archivedOnly) {
            $conditions[] = 'r.archived_at IS NOT NULL';
        } elseif (!$includeArchived) {
            $conditions[] = 'r.archived_at IS NULL';
        }

        if ($search !== null && $search !== '') {
            $searchTerm = '%' . $search . '%';
            $searchColumns = [
                'g.last_name',
                'g.first_name',
                "CONCAT_WS(' ', g.first_name, g.last_name)",
                'c.name',
                'r.reservation_number',
            ];

            $searchConditions = [];
            foreach ($searchColumns as $index => $column) {
                $placeholder = ':reservation_search_' . $index;
                $searchConditions[] = $column . ' LIKE ' . $placeholder;
                $params['reservation_search_' . $index] = $searchTerm;
            }

            if ($searchConditions !== []) {
                $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $orderings = [
            'created_desc' => 'r.created_at DESC, r.id DESC',
            'created_asc' => 'r.created_at ASC, r.id ASC',
            'arrival_asc' => 'r.arrival_date ASC, r.departure_date ASC, r.id DESC',
            'arrival_desc' => 'r.arrival_date DESC, r.departure_date DESC, r.id DESC',
            'departure_asc' => 'r.departure_date ASC, r.arrival_date ASC, r.id DESC',
            'departure_desc' => 'r.departure_date DESC, r.arrival_date DESC, r.id DESC',
            'number_desc' => 'r.reservation_number DESC',
            'number_asc' => 'r.reservation_number ASC',
        ];

        $orderBy = $orderings[$sort] ?? $orderings['created_desc'];
        $sql .= ' ORDER BY ' . $orderBy;

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
            'SELECT r.id, r.reservation_number, r.rate_id, r.guest_id, r.room_id, r.category_id, r.room_quantity, r.company_id, r.arrival_date, r.departure_date, r.status, '
            . 'r.price_per_night, r.total_price, r.vat_rate, r.notes, r.archived_at, r.archived_by, '
            . 'r.created_at, r.updated_at, r.created_by, r.updated_by, '
            . 'g.first_name AS guest_first_name, g.last_name AS guest_last_name, '
            . 'c.name AS company_name, '
            . 'rate.name AS rate_name '
            . 'FROM reservations r '
            . 'LEFT JOIN guests g ON g.id = r.guest_id '
            . 'LEFT JOIN companies c ON c.id = r.company_id '
            . 'LEFT JOIN rates rate ON rate.id = r.rate_id '
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
            . '(reservation_number, rate_id, guest_id, room_id, category_id, room_quantity, company_id, arrival_date, departure_date, status, price_per_night, total_price, vat_rate, notes, created_by, updated_by, created_at, updated_at, archived_at, archived_by) '
            . 'VALUES (:reservation_number, :rate_id, :guest_id, :room_id, :category_id, :room_quantity, :company_id, :arrival_date, :departure_date, :status, :price_per_night, :total_price, :vat_rate, :notes, :created_by, :updated_by, NOW(), NOW(), :archived_at, :archived_by)'
        );

        if ($stmt === false) {
            throw new PDOException('Reservierung konnte nicht vorbereitet werden.');
        }

        $attempts = 0;

        $statusValue = isset($payload['status']) ? (string) $payload['status'] : 'geplant';
        $shouldArchive = in_array($statusValue, self::ARCHIVE_STATUSES, true);
        $archivedAtValue = $shouldArchive ? date('Y-m-d H:i:s') : null;
        $archivedByValue = null;
        if ($shouldArchive) {
            foreach ([$payload['updated_by'] ?? null, $payload['created_by'] ?? null] as $candidate) {
                if ($candidate === null) {
                    continue;
                }

                $candidateValue = (int) $candidate;
                if ($candidateValue > 0) {
                    $archivedByValue = $candidateValue;
                    break;
                }
            }
        }

        while (true) {
            $attempts++;
            $payloadWithNumber = $payload;
            foreach (['rate_id', 'price_per_night', 'total_price', 'vat_rate'] as $field) {
                if (!array_key_exists($field, $payloadWithNumber)) {
                    $payloadWithNumber[$field] = null;
                }
            }
            $payloadWithNumber['archived_at'] = $archivedAtValue;
            $payloadWithNumber['archived_by'] = $archivedByValue;
            $payloadWithNumber['reservation_number'] = $this->generateReservationNumber();

            try {
                $stmt->execute($payloadWithNumber);
                return (int) $this->pdo->lastInsertId();
            } catch (PDOException $exception) {
                $errorCode = $exception->errorInfo[1] ?? null;
                if ($errorCode !== 1062 || $attempts >= 5) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): void
    {
        if (isset($payload['reservation_number'])) {
            unset($payload['reservation_number']);
        }

        if ($payload === []) {
            return;
        }

        if (array_key_exists('status', $payload)) {
            $statusValue = (string) $payload['status'];
            $shouldArchive = in_array($statusValue, self::ARCHIVE_STATUSES, true);
            $payload['archived_at'] = $shouldArchive ? date('Y-m-d H:i:s') : null;

            $archiveUserId = null;
            if ($shouldArchive && isset($payload['updated_by'])) {
                $candidate = (int) $payload['updated_by'];
                if ($candidate > 0) {
                    $archiveUserId = $candidate;
                }
            }

            $payload['archived_by'] = $archiveUserId;
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
            'INSERT INTO reservation_items (reservation_id, category_id, room_id, rate_id, room_quantity, occupancy, primary_guest_id, arrival_date, departure_date, price_per_night, total_price, created_at, updated_at)'
            . ' VALUES (:reservation_id, :category_id, :room_id, :rate_id, :room_quantity, :occupancy, :primary_guest_id, :arrival_date, :departure_date, :price_per_night, :total_price, NOW(), NOW())'
        );

        $articleInsert = $this->pdo->prepare(
            'INSERT INTO reservation_item_articles (reservation_item_id, article_id, article_name, pricing_type, tax_category_id, tax_rate, quantity, unit_price, total_price, created_at, updated_at) '
            . 'VALUES (:reservation_item_id, :article_id, :article_name, :pricing_type, :tax_category_id, :tax_rate, :quantity, :unit_price, :total_price, NOW(), NOW())'
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

            $occupancy = isset($item['occupancy']) ? (int) $item['occupancy'] : $quantity;
            if ($occupancy <= 0) {
                $occupancy = $quantity > 0 ? $quantity : 1;
            }

            $primaryGuestId = null;
            if (isset($item['primary_guest_id']) && $item['primary_guest_id'] !== null && $item['primary_guest_id'] !== '') {
                $primaryGuestId = (int) $item['primary_guest_id'];
                if ($primaryGuestId <= 0) {
                    $primaryGuestId = null;
                }
            }

            $rateId = null;
            if (isset($item['rate_id']) && $item['rate_id'] !== null && $item['rate_id'] !== '') {
                $rateId = (int) $item['rate_id'];
                if ($rateId <= 0) {
                    $rateId = null;
                }
            }

            $arrivalDate = null;
            if (isset($item['arrival_date']) && $item['arrival_date'] !== null && $item['arrival_date'] !== '') {
                $arrivalDate = (string) $item['arrival_date'];
            }

            $departureDate = null;
            if (isset($item['departure_date']) && $item['departure_date'] !== null && $item['departure_date'] !== '') {
                $departureDate = (string) $item['departure_date'];
            }

            $pricePerNight = null;
            if (isset($item['price_per_night']) && $item['price_per_night'] !== null && $item['price_per_night'] !== '') {
                $pricePerNight = number_format((float) $item['price_per_night'], 2, '.', '');
            }

            $totalPrice = null;
            if (isset($item['total_price']) && $item['total_price'] !== null && $item['total_price'] !== '') {
                $totalPrice = number_format((float) $item['total_price'], 2, '.', '');
            }

            $insert->execute([
                'reservation_id' => $reservationId,
                'category_id' => $categoryId,
                'room_id' => $roomId,
                'rate_id' => $rateId,
                'room_quantity' => $quantity,
                'occupancy' => $occupancy,
                'primary_guest_id' => $primaryGuestId,
                'arrival_date' => $arrivalDate,
                'departure_date' => $departureDate,
                'price_per_night' => $pricePerNight,
                'total_price' => $totalPrice,
            ]);

            $itemId = (int) $this->pdo->lastInsertId();
            if ($itemId <= 0 || $articleInsert === false) {
                continue;
            }

            if (isset($item['articles']) && is_array($item['articles'])) {
                foreach ($item['articles'] as $articleEntry) {
                    if (!is_array($articleEntry)) {
                        continue;
                    }

                    $articleId = isset($articleEntry['article_id']) ? (int) $articleEntry['article_id'] : 0;
                    $articleName = isset($articleEntry['article_name']) ? (string) $articleEntry['article_name'] : null;
                    $pricingType = isset($articleEntry['pricing_type']) ? (string) $articleEntry['pricing_type'] : ArticleManager::PRICING_PER_DAY;
                    $taxCategoryId = isset($articleEntry['tax_category_id']) ? (int) $articleEntry['tax_category_id'] : null;
                    if ($taxCategoryId !== null && $taxCategoryId <= 0) {
                        $taxCategoryId = null;
                    }

                    $taxRate = isset($articleEntry['tax_rate']) ? number_format((float) $articleEntry['tax_rate'], 2, '.', '') : '0.00';
                    $quantityValue = isset($articleEntry['quantity']) ? (int) $articleEntry['quantity'] : 1;
                    if ($quantityValue <= 0) {
                        $quantityValue = 1;
                    }
                    if ($pricingType === ArticleManager::PRICING_PER_PERSON_PER_DAY) {
                        $quantityValue = 1;
                    }

                    $unitPrice = isset($articleEntry['unit_price']) ? number_format((float) $articleEntry['unit_price'], 2, '.', '') : '0.00';
                    $totalPriceValue = isset($articleEntry['total_price']) ? number_format((float) $articleEntry['total_price'], 2, '.', '') : '0.00';

                    $articleInsert->execute([
                        'reservation_item_id' => $itemId,
                        'article_id' => $articleId > 0 ? $articleId : null,
                        'article_name' => $articleName,
                        'pricing_type' => $pricingType,
                        'tax_category_id' => $taxCategoryId,
                        'tax_rate' => $taxRate,
                        'quantity' => $quantityValue,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPriceValue,
                    ]);
                }
            }
        }
    }

    public function isRoomAvailable(int $roomId, DateTimeImmutable $arrival, DateTimeImmutable $departure, ?int $ignoreReservationId = null): bool
    {
        if ($roomId <= 0) {
            return true;
        }

        $sql = 'SELECT COUNT(*) FROM reservation_items ri '
            . 'INNER JOIN reservations r ON r.id = ri.reservation_id '
            . 'WHERE ri.room_id = :room_id '
            . 'AND r.status NOT IN (\'storniert\', \'noshow\') '
            . 'AND r.archived_at IS NULL '
            . 'AND COALESCE(ri.arrival_date, r.arrival_date) < :departure '
            . 'AND COALESCE(ri.departure_date, r.departure_date) > :arrival';

        $params = [
            'room_id' => $roomId,
            'arrival' => $arrival->format('Y-m-d'),
            'departure' => $departure->format('Y-m-d'),
        ];

        if ($ignoreReservationId !== null && $ignoreReservationId > 0) {
            $sql .= ' AND r.id <> :ignore_id';
            $params['ignore_id'] = $ignoreReservationId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $conflicts = (int) $stmt->fetchColumn();

        return $conflicts === 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAvailableRooms(int $categoryId, DateTimeImmutable $arrival, DateTimeImmutable $departure, ?int $ignoreReservationId = null): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $params = [
            'category_id' => $categoryId,
            'arrival' => $arrival->format('Y-m-d'),
            'departure' => $departure->format('Y-m-d'),
        ];

        $ignoreClause = '';
        if ($ignoreReservationId !== null && $ignoreReservationId > 0) {
            $ignoreClause = ' AND r.id <> :ignore_id';
            $params['ignore_id'] = $ignoreReservationId;
        }

        $sql = 'SELECT rm.id, rm.room_number, rm.category_id, rm.status '
            . 'FROM rooms rm '
            . 'WHERE rm.category_id = :category_id '
            . 'AND rm.id NOT IN (
                SELECT DISTINCT ri.room_id
                FROM reservation_items ri
                INNER JOIN reservations r ON r.id = ri.reservation_id
                WHERE ri.room_id IS NOT NULL
                  AND r.status NOT IN (\'storniert\', \'noshow\')
                  AND r.archived_at IS NULL'
                  . $ignoreClause
                  . ' AND COALESCE(ri.arrival_date, r.arrival_date) < :departure
                  AND COALESCE(ri.departure_date, r.departure_date) > :arrival
            )
            ORDER BY CAST(rm.room_number AS UNSIGNED), rm.room_number';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rooms = $stmt->fetchAll();

        return is_array($rooms) ? $rooms : [];
    }

    public function updateStatus(int $id, string $status, ?int $userId = null): void
    {
        $shouldArchive = in_array($status, self::ARCHIVE_STATUSES, true);
        $archivedAt = $shouldArchive ? date('Y-m-d H:i:s') : null;
        $archivedBy = null;
        if ($shouldArchive && $userId !== null && $userId > 0) {
            $archivedBy = $userId;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE reservations SET status = :status, updated_by = :updated_by, updated_at = NOW(), archived_at = :archived_at, archived_by = :archived_by WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'updated_by' => $userId !== null && $userId > 0 ? $userId : null,
            'archived_at' => $archivedAt,
            'archived_by' => $archivedBy,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForGuest(int $guestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reservation_number, guest_id, room_id, category_id, room_quantity, company_id, arrival_date, departure_date, status '
            . 'FROM reservations WHERE guest_id = :guest_id AND archived_at IS NULL '
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
            'SELECT ri.id, ri.reservation_id, ri.category_id, ri.room_id, ri.rate_id, ri.room_quantity, '
            . 'ri.occupancy, ri.primary_guest_id, '
            . 'ri.arrival_date, ri.departure_date, ri.price_per_night, ri.total_price, '
            . 'ri.created_at, ri.updated_at, rc.name AS category_name, rm.room_number, rt.name AS rate_name, '
            . 'pg.first_name AS primary_guest_first_name, pg.last_name AS primary_guest_last_name, pg.company_id AS primary_guest_company_id, '
            . 'pg_company.name AS primary_guest_company_name '
            . 'FROM reservation_items ri '
            . 'LEFT JOIN room_categories rc ON rc.id = ri.category_id '
            . 'LEFT JOIN rooms rm ON rm.id = ri.room_id '
            . 'LEFT JOIN rates rt ON rt.id = ri.rate_id '
            . 'LEFT JOIN guests pg ON pg.id = ri.primary_guest_id '
            . 'LEFT JOIN companies pg_company ON pg_company.id = pg.company_id '
            . 'WHERE ri.reservation_id IN (' . $placeholders . ') '
            . 'ORDER BY ri.id ASC'
        );
        $stmt->execute($reservationIds);

        $items = $stmt->fetchAll();
        if (!is_array($items) || $items === []) {
            return [];
        }

        $itemIds = [];
        foreach ($items as $item) {
            if (isset($item['id'])) {
                $itemIds[] = (int) $item['id'];
            }
        }

        $articleMap = [];
        if ($itemIds !== []) {
            $articlePlaceholders = implode(', ', array_fill(0, count($itemIds), '?'));
            $articleStmt = $this->pdo->prepare(
                'SELECT ria.id, ria.reservation_item_id, ria.article_id, ria.article_name, ria.pricing_type, '
                . 'ria.tax_category_id, ria.tax_rate, ria.quantity, ria.unit_price, ria.total_price, ria.created_at, ria.updated_at '
                . 'FROM reservation_item_articles ria '
                . 'WHERE ria.reservation_item_id IN (' . $articlePlaceholders . ') '
                . 'ORDER BY ria.id ASC'
            );
            $articleStmt->execute($itemIds);
            $articleRows = $articleStmt->fetchAll();
            if (is_array($articleRows)) {
                foreach ($articleRows as $articleRow) {
                    if (!isset($articleRow['reservation_item_id'])) {
                        continue;
                    }

                    $reservationItemId = (int) $articleRow['reservation_item_id'];
                    if (!isset($articleMap[$reservationItemId])) {
                        $articleMap[$reservationItemId] = [];
                    }

                    $articleMap[$reservationItemId][] = $articleRow;
                }
            }
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

            $itemId = isset($item['id']) ? (int) $item['id'] : 0;
            $item['articles'] = $articleMap[$itemId] ?? [];

            $result[$reservationId][] = $item;
        }

        return $result;
    }
}
