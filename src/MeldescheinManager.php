<?php

namespace ModPMS;

use DateTimeImmutable;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class MeldescheinManager
{
    private PDO $pdo;
    private SettingManager $settingsManager;
    private string $signatureStorageDirectory;

    public function __construct(PDO $pdo, SettingManager $settingsManager)
    {
        $this->pdo = $pdo;
        $this->settingsManager = $settingsManager;
        $this->signatureStorageDirectory = __DIR__ . '/../storage/meldescheine/signatures';

        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS meldescheine (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    form_number VARCHAR(191) NOT NULL UNIQUE,
                    guest_id INT UNSIGNED NOT NULL,
                    reservation_id INT UNSIGNED NULL,
                    guest_name VARCHAR(191) NOT NULL,
                    company_name VARCHAR(191) NULL,
                    purpose_of_stay VARCHAR(50) NULL,
                    arrival_date DATE NULL,
                    departure_date DATE NULL,
                    issued_date DATE NOT NULL,
                    room_label VARCHAR(191) NULL,
                    pdf_path VARCHAR(255) NULL,
                    guest_signature_path VARCHAR(255) NULL,
                    guest_signed_at DATETIME NULL,
                    details_json LONGTEXT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_meldescheine_guest FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
                    CONSTRAINT fk_meldescheine_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
                    INDEX idx_meldescheine_guest (guest_id),
                    INDEX idx_meldescheine_reservation (reservation_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('MeldescheinManager: unable to ensure schema: ' . $exception->getMessage());
        }

        $this->ensureColumn('room_label', 'ALTER TABLE meldescheine ADD COLUMN room_label VARCHAR(191) NULL AFTER issued_date');
        $this->ensureColumn('details_json', 'ALTER TABLE meldescheine ADD COLUMN details_json LONGTEXT NULL AFTER pdf_path');
        $this->ensureColumn('guest_signature_path', 'ALTER TABLE meldescheine ADD COLUMN guest_signature_path VARCHAR(255) NULL AFTER pdf_path');
        $this->ensureColumn('guest_signed_at', 'ALTER TABLE meldescheine ADD COLUMN guest_signed_at DATETIME NULL AFTER guest_signature_path');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForms(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM meldescheine ORDER BY created_at DESC');
        if ($stmt === false) {
            return [];
        }

        $forms = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }

            $forms[] = $this->mapRecord($row);
        }

        return $forms;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM meldescheine WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($record) ? $this->mapRecord($record) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createForm(array $data, callable $pdfGenerator): array
    {
        $guestId = isset($data['guest_id']) ? (int) $data['guest_id'] : 0;
        if ($guestId <= 0) {
            throw new RuntimeException('Gast konnte nicht ermittelt werden.');
        }

        $guestName = trim((string) ($data['guest_name'] ?? ''));
        if ($guestName === '') {
            throw new RuntimeException('Bitte geben Sie einen Gastnamen an.');
        }

        $arrivalDate = $this->normalizeDate($data['arrival_date'] ?? null);
        $departureDate = $this->normalizeDate($data['departure_date'] ?? null);
        if ($arrivalDate === null && $departureDate === null) {
            throw new RuntimeException('Der Aufenthaltszeitraum ist unvollständig.');
        }

        $purpose = $this->normalizePurpose($data['purpose_of_stay'] ?? null);
        $issuedDate = $this->normalizeDate($data['issued_at'] ?? null);
        if ($issuedDate === null) {
            $issuedDate = (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        $reservationId = $this->normalizeId($data['reservation_id'] ?? null);
        $companyName = trim((string) ($data['company_name'] ?? ''));
        $roomLabel = trim((string) ($data['room_label'] ?? ''));
        $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];
        if (!isset($details['signature']) || !is_array($details['signature'])) {
            $details['signature'] = [
                'guest_signature_path' => null,
                'guest_signed_at' => null,
            ];
        }

        $formNumber = $this->generateFormNumber();

        $details['form_number'] = $formNumber;
        $details['issued_date'] = $issuedDate;
        $details['guest_name'] = $guestName;
        $details['arrival_date'] = $arrivalDate;
        $details['departure_date'] = $departureDate;
        $details['purpose_of_stay'] = $purpose;
        $details['company_name'] = $companyName;
        $details['room_label'] = $roomLabel;
        $details['guest_signature_path'] = null;
        $details['guest_signed_at'] = null;

        $pdfPath = $pdfGenerator($details);
        if (!is_string($pdfPath) || $pdfPath === '') {
            throw new RuntimeException('PDF konnte nicht erzeugt werden.');
        }

        $snapshot = $this->encodeSnapshot($details);

        $stmt = $this->pdo->prepare(
            'INSERT INTO meldescheine '
            . '(form_number, guest_id, reservation_id, guest_name, company_name, purpose_of_stay, arrival_date, departure_date, issued_date, room_label, pdf_path, guest_signature_path, guest_signed_at, details_json, created_at, updated_at) '
            . 'VALUES (:form_number, :guest_id, :reservation_id, :guest_name, :company_name, :purpose_of_stay, :arrival_date, :departure_date, :issued_date, :room_label, :pdf_path, :guest_signature_path, :guest_signed_at, :details_json, NOW(), NOW())'
        );
        $stmt->execute([
            'form_number' => $formNumber,
            'guest_id' => $guestId,
            'reservation_id' => $reservationId,
            'guest_name' => $guestName,
            'company_name' => $companyName !== '' ? $companyName : null,
            'purpose_of_stay' => $purpose,
            'arrival_date' => $arrivalDate,
            'departure_date' => $departureDate,
            'issued_date' => $issuedDate,
            'room_label' => $roomLabel !== '' ? $roomLabel : null,
            'pdf_path' => $pdfPath,
            'guest_signature_path' => null,
            'guest_signed_at' => null,
            'details_json' => $snapshot,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->find($id);
    }

    public function saveGuestSignature(int $formId, string $signatureData, callable $pdfGenerator): array
    {
        if ($formId <= 0) {
            throw new RuntimeException('Der ausgewählte Meldeschein ist ungültig.');
        }

        $form = $this->find($formId);
        if ($form === null) {
            throw new RuntimeException('Der Meldeschein wurde nicht gefunden.');
        }

        $signaturePayload = trim($signatureData);
        if ($signaturePayload === '') {
            throw new RuntimeException('Es wurden keine Signaturdaten übermittelt.');
        }

        $formNumber = isset($form['form_number']) ? (string) $form['form_number'] : ('MS-' . $formId);

        $signaturePath = $this->storeSignatureImage($formNumber, $signaturePayload);

        $signedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $details = isset($form['details']) && is_array($form['details']) ? $form['details'] : [];
        if (!isset($details['signature']) || !is_array($details['signature'])) {
            $details['signature'] = [];
        }

        $details['signature']['guest_signature_path'] = $signaturePath;
        $details['signature']['guest_signed_at'] = $signedAt;
        $details['guest_signature_path'] = $signaturePath;
        $details['guest_signed_at'] = $signedAt;
        $details['form_number'] = $formNumber;
        $details['issued_date'] = $form['issued_date'] ?? null;
        $details['guest_name'] = $form['guest_name'] ?? null;
        $details['arrival_date'] = $form['arrival_date'] ?? null;
        $details['departure_date'] = $form['departure_date'] ?? null;
        $details['purpose_of_stay'] = $form['purpose_of_stay'] ?? null;
        $details['company_name'] = $form['company_name'] ?? null;
        $details['room_label'] = $form['room_label'] ?? null;

        try {
            $pdfPath = $pdfGenerator($details);
            if (!is_string($pdfPath) || $pdfPath === '') {
                throw new RuntimeException('Das PDF mit der Signatur konnte nicht erzeugt werden.');
            }

            $snapshot = $this->encodeSnapshot($details);

            $stmt = $this->pdo->prepare(
                'UPDATE meldescheine '
                . 'SET guest_signature_path = :guest_signature_path, '
                . 'guest_signed_at = :guest_signed_at, '
                . 'pdf_path = :pdf_path, '
                . 'details_json = :details_json, '
                . 'updated_at = NOW() '
                . 'WHERE id = :id'
            );

            $stmt->execute([
                'guest_signature_path' => $signaturePath,
                'guest_signed_at' => $signedAt,
                'pdf_path' => $pdfPath,
                'details_json' => $snapshot,
                'id' => $formId,
            ]);
        } catch (Throwable $exception) {
            $this->removeFile($signaturePath);
            throw $exception;
        }

        $previousSignature = isset($form['guest_signature_path']) ? (string) $form['guest_signature_path'] : '';
        if ($previousSignature !== '' && $previousSignature !== $signaturePath) {
            $this->removeFile($previousSignature);
        }

        return $this->find($formId);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM meldescheine WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function mapRecord(array $record): array
    {
        $record['id'] = isset($record['id']) ? (int) $record['id'] : 0;
        $record['guest_id'] = isset($record['guest_id']) ? (int) $record['guest_id'] : 0;
        $record['reservation_id'] = isset($record['reservation_id']) && $record['reservation_id'] !== null
            ? (int) $record['reservation_id']
            : null;
        $record['arrival_date'] = isset($record['arrival_date']) && $record['arrival_date'] !== null
            ? (string) $record['arrival_date']
            : null;
        $record['departure_date'] = isset($record['departure_date']) && $record['departure_date'] !== null
            ? (string) $record['departure_date']
            : null;
        $record['issued_date'] = isset($record['issued_date']) ? (string) $record['issued_date'] : null;
        $record['room_label'] = isset($record['room_label']) && $record['room_label'] !== null
            ? (string) $record['room_label']
            : null;
        $record['purpose_of_stay'] = isset($record['purpose_of_stay']) && $record['purpose_of_stay'] !== null
            ? (string) $record['purpose_of_stay']
            : null;
        $record['pdf_path'] = isset($record['pdf_path']) && $record['pdf_path'] !== null
            ? (string) $record['pdf_path']
            : null;
        $record['guest_signature_path'] = isset($record['guest_signature_path']) && $record['guest_signature_path'] !== null
            ? (string) $record['guest_signature_path']
            : null;
        $record['guest_signed_at'] = isset($record['guest_signed_at']) && $record['guest_signed_at'] !== null
            ? (string) $record['guest_signed_at']
            : null;
        $record['details'] = [];

        if (isset($record['details_json']) && $record['details_json'] !== null) {
            try {
                $decoded = json_decode((string) $record['details_json'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $record['details'] = $decoded;
                }
            } catch (JsonException $exception) {
                $record['details'] = [];
            }
        }

        unset($record['details_json']);

        return $record;
    }

    private function encodeSnapshot(array $details): string
    {
        try {
            return json_encode($details, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Meldeschein konnte nicht gespeichert werden.', 0, $exception);
        }
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stringValue = (string) $value;

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $stringValue)
            ?: DateTimeImmutable::createFromFormat('d.m.Y', $stringValue);

        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function normalizePurpose($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower((string) $value);
        if ($normalized === 'geschäftlich' || $normalized === 'geschaeftlich') {
            return 'geschäftlich';
        }

        return 'privat';
    }

    private function normalizeId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function generateFormNumber(): string
    {
        $currentValue = (int) $this->settingsManager->get('meldeschein_sequence', '0');
        $nextValue = $currentValue + 1;
        $this->settingsManager->set('meldeschein_sequence', (string) $nextValue);

        $year = (new DateTimeImmutable())->format('Y');

        return sprintf('MS%s%05d', $year, $nextValue);
    }

    private function ensureColumn(string $column, string $alterStatement): void
    {
        try {
            $check = $this->pdo->prepare('SHOW COLUMNS FROM meldescheine LIKE :column');
            $check->execute(['column' => $column]);
            $exists = $check->fetch();

            if ($exists === false) {
                $this->pdo->exec($alterStatement);
            }
        } catch (PDOException $exception) {
            error_log(sprintf('MeldescheinManager: unable to ensure column %s: %s', $column, $exception->getMessage()));
        }
    }

    private function storeSignatureImage(string $formNumber, string $payload): string
    {
        $parts = explode(',', $payload, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Die Signaturdaten sind beschädigt.');
        }

        $meta = $parts[0];
        $data = $parts[1];

        if (!preg_match('#^data:image/(png|jpeg);base64$#i', $meta, $matches)) {
            throw new RuntimeException('Die Signatur muss als PNG oder JPEG übertragen werden.');
        }

        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Die Signatur konnte nicht verarbeitet werden.');
        }

        if (strlen($binary) > 2 * 1024 * 1024) {
            throw new RuntimeException('Die Signaturdatei ist zu groß.');
        }

        if (!is_dir($this->signatureStorageDirectory) && !mkdir($concurrentDirectory = $this->signatureStorageDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Der Signaturspeicher konnte nicht erstellt werden.');
        }

        $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : 'png';
        $filename = $this->sanitizeFilename($formNumber) . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = rtrim($this->signatureStorageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('Die Signatur konnte nicht gespeichert werden.');
        }

        return 'storage/meldescheine/signatures/' . $filename;
    }

    private function removeFile(string $relativePath): void
    {
        $relative = ltrim($relativePath, '/\\');
        if ($relative === '') {
            return;
        }

        $base = realpath(__DIR__ . '/..');
        if ($base === false) {
            return;
        }

        $absolute = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        return $normalized !== '' ? $normalized : 'meldeschein';
    }
}
