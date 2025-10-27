<?php

namespace ModPMS;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

class DocumentManager
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_OFFER = 'offer';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_CORRECTION = 'correction';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_CORRECTED = 'corrected';

    /**
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_INVOICE => 'Rechnung',
        self::TYPE_OFFER => 'Angebot',
        self::TYPE_REMINDER => 'Mahnung',
        self::TYPE_CORRECTION => 'Rechnungskorrektur',
    ];

    private PDO $pdo;
    private SettingManager $settingsManager;

    public function __construct(PDO $pdo, SettingManager $settingsManager)
    {
        $this->pdo = $pdo;
        $this->settingsManager = $settingsManager;

        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS document_templates (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    type VARCHAR(32) NOT NULL,
                    name VARCHAR(191) NOT NULL,
                    subject VARCHAR(191) NULL,
                    body_html MEDIUMTEXT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_document_templates_type (type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('DocumentManager: unable to ensure templates schema: ' . $exception->getMessage());
        }

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS documents (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    type VARCHAR(32) NOT NULL,
                    document_number VARCHAR(191) NOT NULL UNIQUE,
                    status VARCHAR(32) NOT NULL DEFAULT "draft",
                    recipient_name VARCHAR(191) NOT NULL,
                    recipient_address TEXT NULL,
                    subject VARCHAR(191) NULL,
                    body_html MEDIUMTEXT NULL,
                    items_json LONGTEXT NULL,
                    reservation_id INT UNSIGNED NULL,
                    total_net DECIMAL(12,2) NULL,
                    total_vat DECIMAL(12,2) NULL,
                    total_gross DECIMAL(12,2) NULL,
                    currency VARCHAR(8) NOT NULL DEFAULT "EUR",
                    issue_date DATE NULL,
                    due_date DATE NULL,
                    template_id INT UNSIGNED NULL,
                    correction_of_id INT UNSIGNED NULL,
                    correction_number INT UNSIGNED NULL,
                    pdf_path VARCHAR(255) NULL,
                    finalized_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_documents_template FOREIGN KEY (template_id) REFERENCES document_templates(id) ON DELETE SET NULL,
                    CONSTRAINT fk_documents_correction FOREIGN KEY (correction_of_id) REFERENCES documents(id) ON DELETE SET NULL,
                    CONSTRAINT fk_documents_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
                    INDEX idx_documents_type (type),
                    INDEX idx_documents_status (status),
                    INDEX idx_documents_reservation (reservation_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('DocumentManager: unable to ensure documents schema: ' . $exception->getMessage());
        }

        $this->ensureColumn('documents', 'reservation_id', 'ALTER TABLE documents ADD COLUMN reservation_id INT UNSIGNED NULL AFTER items_json');
        $this->ensureColumn('documents', 'pdf_path', 'ALTER TABLE documents ADD COLUMN pdf_path VARCHAR(255) NULL AFTER correction_number');
        $this->ensureColumn('documents', 'finalized_at', 'ALTER TABLE documents ADD COLUMN finalized_at TIMESTAMP NULL DEFAULT NULL AFTER pdf_path');
        $this->ensureColumn('documents', 'correction_number', 'ALTER TABLE documents ADD COLUMN correction_number INT UNSIGNED NULL AFTER correction_of_id');
        $this->ensureColumn('documents', 'payment_method', 'ALTER TABLE documents ADD COLUMN payment_method VARCHAR(64) NULL AFTER finalized_at');
        $this->ensureColumn('documents', 'payment_reference', 'ALTER TABLE documents ADD COLUMN payment_reference VARCHAR(191) NULL AFTER payment_method');
        $this->ensureColumn('documents', 'payment_details_json', 'ALTER TABLE documents ADD COLUMN payment_details_json LONGTEXT NULL AFTER payment_reference');

        try {
            $this->pdo->exec('ALTER TABLE documents ADD INDEX idx_documents_reservation (reservation_id)');
        } catch (PDOException $exception) {
            // index might already exist
        }

        try {
            $this->pdo->exec('ALTER TABLE documents ADD CONSTRAINT fk_documents_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            // constraint might already exist
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDocuments(): array
    {
        $stmt = $this->pdo->query(
            'SELECT d.*, t.name AS template_name, r.reservation_number
             FROM documents d
             LEFT JOIN document_templates t ON t.id = d.template_id
             LEFT JOIN reservations r ON r.id = d.reservation_id
             ORDER BY d.created_at DESC'
        );

        if ($stmt === false) {
            return [];
        }

        $documents = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }

            $documents[] = $this->mapDocumentRecord($row);
        }

        return $documents;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM document_templates ORDER BY name ASC');
        if ($stmt === false) {
            return [];
        }

        return array_map(function ($row) {
            return is_array($row) ? $row : [];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findTemplate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_templates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($record) ? $record : null;
    }

    public function createTemplate(array $data): int
    {
        $type = $this->normalizeType($data['type'] ?? '');
        if ($type === self::TYPE_CORRECTION) {
            throw new RuntimeException('Vorlagen für Rechnungskorrekturen werden automatisch erzeugt.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen für die Vorlage angeben.');
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        $body = (string) ($data['body_html'] ?? '');

        $stmt = $this->pdo->prepare('INSERT INTO document_templates (type, name, subject, body_html, created_at, updated_at) VALUES (:type, :name, :subject, :body_html, NOW(), NOW())');
        $stmt->execute([
            'type' => $type,
            'name' => $name,
            'subject' => $subject,
            'body_html' => $body,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateTemplate(int $id, array $data): void
    {
        $template = $this->findTemplate($id);
        if ($template === null) {
            throw new RuntimeException('Vorlage wurde nicht gefunden.');
        }

        $type = $this->normalizeType($data['type'] ?? $template['type']);
        if ($type === self::TYPE_CORRECTION) {
            throw new RuntimeException('Vorlagen für Rechnungskorrekturen werden automatisch erzeugt.');
        }

        $name = trim((string) ($data['name'] ?? $template['name']));
        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen für die Vorlage angeben.');
        }

        $subject = trim((string) ($data['subject'] ?? ($template['subject'] ?? '')));
        $body = (string) ($data['body_html'] ?? ($template['body_html'] ?? ''));

        $stmt = $this->pdo->prepare('UPDATE document_templates SET type = :type, name = :name, subject = :subject, body_html = :body_html, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'subject' => $subject,
            'body_html' => $body,
        ]);
    }

    public function deleteTemplate(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM document_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, t.name AS template_name, r.reservation_number
             FROM documents d
             LEFT JOIN document_templates t ON t.id = d.template_id
             LEFT JOIN reservations r ON r.id = d.reservation_id
             WHERE d.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($record)) {
            return null;
        }

        return $this->mapDocumentRecord($record);
    }

    public function createDocument(array $data): array
    {
        $type = $this->normalizeType($data['type'] ?? '');
        if ($type === self::TYPE_CORRECTION) {
            throw new RuntimeException('Rechnungskorrekturen werden automatisch erzeugt.');
        }

        $items = $this->normalizeItems(isset($data['items']) && is_array($data['items']) ? $data['items'] : []);
        if ($items === []) {
            throw new RuntimeException('Bitte mindestens eine Position erfassen.');
        }

        $documentNumber = $this->generateDocumentNumber($type);

        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        if ($recipientName === '') {
            throw new RuntimeException('Bitte einen Empfänger angeben.');
        }

        return $this->persistDocument([
            'type' => $type,
            'document_number' => $documentNumber,
            'status' => self::STATUS_DRAFT,
            'recipient_name' => $recipientName,
            'recipient_address' => (string) ($data['recipient_address'] ?? ''),
            'subject' => (string) ($data['subject'] ?? ''),
            'body_html' => (string) ($data['body_html'] ?? ''),
            'currency' => strtoupper((string) ($data['currency'] ?? 'EUR')),
            'issue_date' => $this->normalizeDate($data['issue_date'] ?? null),
            'due_date' => $this->normalizeDate($data['due_date'] ?? null),
            'template_id' => isset($data['template_id']) ? $this->normalizeId($data['template_id']) : null,
            'correction_of_id' => null,
            'correction_number' => null,
            'reservation_id' => isset($data['reservation_id']) ? $this->normalizeId($data['reservation_id']) : null,
        ], $items);
    }

    public function updateDocument(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Dokument wurde nicht gefunden.');
        }

        if ($existing['status'] !== self::STATUS_DRAFT) {
            throw new RuntimeException('Nur Entwürfe können bearbeitet werden.');
        }

        $items = $this->normalizeItems(isset($data['items']) && is_array($data['items']) ? $data['items'] : $existing['items']);
        if ($items === []) {
            throw new RuntimeException('Bitte mindestens eine Position erfassen.');
        }

        $recipientName = trim((string) ($data['recipient_name'] ?? $existing['recipient_name']));
        if ($recipientName === '') {
            throw new RuntimeException('Bitte einen Empfänger angeben.');
        }

        return $this->persistDocument([
            'id' => $id,
            'type' => $existing['type'],
            'document_number' => $existing['document_number'],
            'status' => $existing['status'],
            'recipient_name' => $recipientName,
            'recipient_address' => (string) ($data['recipient_address'] ?? $existing['recipient_address']),
            'subject' => (string) ($data['subject'] ?? $existing['subject']),
            'body_html' => (string) ($data['body_html'] ?? $existing['body_html']),
            'currency' => strtoupper((string) ($data['currency'] ?? $existing['currency'] ?? 'EUR')),
            'issue_date' => $this->normalizeDate($data['issue_date'] ?? $existing['issue_date']),
            'due_date' => $this->normalizeDate($data['due_date'] ?? $existing['due_date']),
            'template_id' => isset($data['template_id']) ? $this->normalizeId($data['template_id']) : ($existing['template_id'] ?? null),
            'correction_of_id' => $existing['correction_of_id'] ?? null,
            'correction_number' => $existing['correction_number'] ?? null,
            'reservation_id' => array_key_exists('reservation_id', $data)
                ? $this->normalizeId($data['reservation_id'])
                : ($existing['reservation_id'] ?? null),
        ], $items);
    }

    public function deleteDraft(int $id): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Dokument wurde nicht gefunden.');
        }

        if ($existing['status'] !== self::STATUS_DRAFT) {
            throw new RuntimeException('Nur Entwürfe können gelöscht werden.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function finalizeDocument(
        int $id,
        callable $pdfGenerator,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
        ?array $paymentDetails = null
    ): array
    {
        $document = $this->find($id);
        if ($document === null) {
            throw new RuntimeException('Dokument wurde nicht gefunden.');
        }

        if ($document['status'] !== self::STATUS_DRAFT) {
            throw new RuntimeException('Nur Entwürfe können finalisiert werden.');
        }

        $items = $this->normalizeItems($document['items']);
        if ($items === []) {
            throw new RuntimeException('Dokument enthält keine Positionen.');
        }

        $totals = $this->calculateTotals($items);

        $documentForPdf = $document;
        $documentForPdf['items'] = $items;
        $documentForPdf['total_net'] = $totals['net'];
        $documentForPdf['total_vat'] = $totals['vat'];
        $documentForPdf['total_gross'] = $totals['gross'];
        $documentForPdf['type_label'] = self::TYPE_LABELS[$documentForPdf['type']] ?? 'Dokument';

        $companySettings = $this->settingsManager->getMany([
            'document_company_name',
            'document_company_address',
            'document_company_vat_id',
            'document_company_bank_details',
        ]);
        $documentForPdf['company_name'] = isset($companySettings['document_company_name'])
            ? trim((string) $companySettings['document_company_name'])
            : '';
        $documentForPdf['company_address'] = isset($companySettings['document_company_address'])
            ? trim((string) $companySettings['document_company_address'])
            : '';
        $documentForPdf['company_vat_id'] = isset($companySettings['document_company_vat_id'])
            ? trim((string) $companySettings['document_company_vat_id'])
            : '';
        $documentForPdf['company_bank_details'] = isset($companySettings['document_company_bank_details'])
            ? trim((string) $companySettings['document_company_bank_details'])
            : '';

        $pdfPath = $pdfGenerator($documentForPdf);
        if (!is_string($pdfPath) || $pdfPath === '') {
            throw new RuntimeException('PDF konnte nicht erzeugt werden.');
        }

        $normalizedPaymentMethod = $paymentMethod !== null ? trim($paymentMethod) : null;
        if ($normalizedPaymentMethod === '') {
            $normalizedPaymentMethod = null;
        }

        $normalizedPaymentReference = $paymentReference !== null ? trim($paymentReference) : null;
        if ($normalizedPaymentReference === '') {
            $normalizedPaymentReference = null;
        }

        $paymentDetailsJson = null;
        if ($paymentDetails !== null) {
            try {
                $paymentDetailsJson = $this->encodePaymentDetails($paymentDetails);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Zahlungsdetails konnten nicht verarbeitet werden: ' . $exception->getMessage(), 0, $exception);
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE documents SET status = :status, finalized_at = NOW(), pdf_path = :pdf_path, total_net = :total_net, total_vat = :total_vat, total_gross = :total_gross, items_json = :items_json, payment_method = :payment_method, payment_reference = :payment_reference, payment_details_json = :payment_details_json, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => self::STATUS_FINALIZED,
            'pdf_path' => $pdfPath,
            'total_net' => $totals['net'],
            'total_vat' => $totals['vat'],
            'total_gross' => $totals['gross'],
            'items_json' => $this->encodeItems($items),
            'payment_method' => $normalizedPaymentMethod,
            'payment_reference' => $normalizedPaymentReference,
            'payment_details_json' => $paymentDetailsJson,
        ]);

        return $this->find($id);
    }

    public function createCorrection(int $documentId, callable $pdfGenerator): array
    {
        $original = $this->find($documentId);
        if ($original === null) {
            throw new RuntimeException('Ausgangsrechnung wurde nicht gefunden.');
        }

        if ($original['type'] !== self::TYPE_INVOICE) {
            throw new RuntimeException('Nur Rechnungen können korrigiert werden.');
        }

        if ($original['status'] !== self::STATUS_FINALIZED) {
            throw new RuntimeException('Nur finalisierte Rechnungen können korrigiert werden.');
        }

        $items = $this->normalizeItems($original['items']);
        if ($items === []) {
            throw new RuntimeException('Die Rechnung enthält keine Positionen.');
        }

        foreach ($items as &$item) {
            $item['quantity'] = -1 * (float) ($item['quantity'] ?? 0.0);
            $item['unit_price'] = (float) ($item['unit_price'] ?? 0.0);
            $item['total_net'] = -1 * abs((float) ($item['total_net'] ?? ($item['unit_price'] * $item['quantity'])));
            if ($item['total_net'] === 0.0) {
                $item['total_net'] = $item['quantity'] * $item['unit_price'];
            }
            $item['total_vat'] = -1 * abs((float) ($item['total_vat'] ?? 0.0));
            $item['total_gross'] = -1 * abs((float) ($item['total_gross'] ?? 0.0));
        }
        unset($item);

        $totals = $this->calculateTotals($items);
        if ($totals['gross'] >= 0) {
            $totals['net'] = -1 * abs($totals['net']);
            $totals['vat'] = -1 * abs($totals['vat']);
            $totals['gross'] = -1 * abs($totals['gross']);
        }

        $correctionNumber = $this->nextCorrectionIndex($documentId);
        $documentNumber = $this->generateDocumentNumber(self::TYPE_CORRECTION);

        $subject = sprintf('Rechnungskorrektur zu %s', $original['document_number']);
        $body = "Gutschrift zur Rechnung " . $original['document_number'];
        if (!empty($original['body_html'])) {
            $body .= "\n\n" . $original['body_html'];
        }

        $inserted = $this->persistDocument([
            'type' => self::TYPE_CORRECTION,
            'document_number' => $documentNumber,
            'status' => self::STATUS_DRAFT,
            'recipient_name' => (string) ($original['recipient_name'] ?? ''),
            'recipient_address' => (string) ($original['recipient_address'] ?? ''),
            'subject' => $subject,
            'body_html' => $body,
            'currency' => $original['currency'] ?? 'EUR',
            'issue_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'due_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'template_id' => null,
            'correction_of_id' => $documentId,
            'correction_number' => $correctionNumber,
            'reservation_id' => $original['reservation_id'] ?? null,
        ], $items, $totals);

        $finalizedCorrection = $this->finalizeDocument($inserted['id'], $pdfGenerator);

        $stmt = $this->pdo->prepare('UPDATE documents SET status = :status WHERE id = :id');
        $stmt->execute([
            'id' => $documentId,
            'status' => self::STATUS_CORRECTED,
        ]);

        return $finalizedCorrection;
    }

    private function persistDocument(array $documentData, array $items, ?array $precalculatedTotals = null): array
    {
        $id = isset($documentData['id']) ? (int) $documentData['id'] : null;
        $itemsJson = $this->encodeItems($items);
        $totals = $precalculatedTotals ?? $this->calculateTotals($items);

        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO documents (type, document_number, status, recipient_name, recipient_address, subject, body_html, items_json, reservation_id, total_net, total_vat, total_gross, currency, issue_date, due_date, template_id, correction_of_id, correction_number, pdf_path, created_at, updated_at)
                 VALUES (:type, :document_number, :status, :recipient_name, :recipient_address, :subject, :body_html, :items_json, :reservation_id, :total_net, :total_vat, :total_gross, :currency, :issue_date, :due_date, :template_id, :correction_of_id, :correction_number, NULL, NOW(), NOW())'
            );

            $parameters = [
                'type' => $documentData['type'],
                'document_number' => $documentData['document_number'],
                'status' => $documentData['status'],
                'recipient_name' => $documentData['recipient_name'],
                'recipient_address' => $documentData['recipient_address'],
                'subject' => $documentData['subject'],
                'body_html' => $documentData['body_html'],
                'items_json' => $itemsJson,
                'reservation_id' => $documentData['reservation_id'],
                'total_net' => $totals['net'],
                'total_vat' => $totals['vat'],
                'total_gross' => $totals['gross'],
                'currency' => $documentData['currency'],
                'issue_date' => $documentData['issue_date'],
                'due_date' => $documentData['due_date'],
                'template_id' => $documentData['template_id'],
                'correction_of_id' => $documentData['correction_of_id'],
                'correction_number' => $documentData['correction_number'],
            ];
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE documents SET recipient_name = :recipient_name, recipient_address = :recipient_address, subject = :subject, body_html = :body_html, items_json = :items_json, reservation_id = :reservation_id, total_net = :total_net, total_vat = :total_vat, total_gross = :total_gross, currency = :currency, issue_date = :issue_date, due_date = :due_date, template_id = :template_id, correction_of_id = :correction_of_id, correction_number = :correction_number, updated_at = NOW() WHERE id = :id'
            );

            $parameters = [
                'recipient_name' => $documentData['recipient_name'],
                'recipient_address' => $documentData['recipient_address'],
                'subject' => $documentData['subject'],
                'body_html' => $documentData['body_html'],
                'items_json' => $itemsJson,
                'reservation_id' => $documentData['reservation_id'],
                'total_net' => $totals['net'],
                'total_vat' => $totals['vat'],
                'total_gross' => $totals['gross'],
                'currency' => $documentData['currency'],
                'issue_date' => $documentData['issue_date'],
                'due_date' => $documentData['due_date'],
                'template_id' => $documentData['template_id'],
                'correction_of_id' => $documentData['correction_of_id'],
                'correction_number' => $documentData['correction_number'],
                'id' => $id,
            ];
        }

        $stmt->execute($parameters);

        $documentId = $id ?? (int) $this->pdo->lastInsertId();

        return $this->find($documentId);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{net: float, vat: float, gross: float}
     */
    private function calculateTotals(array $items): array
    {
        $net = 0.0;
        $vat = 0.0;
        $gross = 0.0;

        foreach ($items as $item) {
            $net += (float) ($item['total_net'] ?? 0.0);
            $vat += (float) ($item['total_vat'] ?? 0.0);
            $gross += (float) ($item['total_gross'] ?? 0.0);
        }

        return [
            'net' => round($net, 2),
            'vat' => round($vat, 2),
            'gross' => round($gross, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function encodeItems(array $items): string
    {
        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function encodePaymentDetails(array $details): string
    {
        return json_encode($details, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));
            $quantity = $this->normalizeFloat($item['quantity'] ?? 0);
            $unitPrice = $this->normalizeFloat($item['unit_price'] ?? 0);
            $taxRate = $this->normalizeFloat($item['tax_rate'] ?? 0);

            if ($description === '' && $quantity === 0.0 && $unitPrice === 0.0) {
                continue;
            }

            if ($description === '') {
                $description = 'Position';
            }

            if ($quantity === 0.0) {
                $quantity = 1.0;
            }

            if ($taxRate < 0) {
                $taxRate = 0.0;
            }

            $totalNet = round($quantity * $unitPrice, 2);
            $totalVat = round($totalNet * ($taxRate / 100), 2);
            $totalGross = round($totalNet + $totalVat, 2);

            $normalized[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'tax_rate' => round($taxRate, 2),
                'total_net' => $totalNet,
                'total_vat' => $totalVat,
                'total_gross' => $totalGross,
            ];
        }

        return $normalized;
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, [self::TYPE_INVOICE, self::TYPE_OFFER, self::TYPE_REMINDER, self::TYPE_CORRECTION], true)) {
            throw new RuntimeException('Unbekannter Dokumenttyp.');
        }

        return $type;
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }

        $stringValue = (string) $value;
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $stringValue);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }

        return null;
    }

    private function normalizeId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    private function normalizeFloat($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $normalized = str_replace([',', ' '], ['.', ''], (string) $value);
        if (!is_numeric($normalized)) {
            return 0.0;
        }

        return (float) $normalized;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function mapDocumentRecord(array $record): array
    {
        $record['id'] = isset($record['id']) ? (int) $record['id'] : null;
        $record['template_id'] = isset($record['template_id']) && $record['template_id'] !== null ? (int) $record['template_id'] : null;
        $record['correction_of_id'] = isset($record['correction_of_id']) && $record['correction_of_id'] !== null ? (int) $record['correction_of_id'] : null;
        $record['correction_number'] = isset($record['correction_number']) && $record['correction_number'] !== null ? (int) $record['correction_number'] : null;
        $record['reservation_id'] = isset($record['reservation_id']) && $record['reservation_id'] !== null ? (int) $record['reservation_id'] : null;
        $record['total_net'] = isset($record['total_net']) ? (float) $record['total_net'] : 0.0;
        $record['total_vat'] = isset($record['total_vat']) ? (float) $record['total_vat'] : 0.0;
        $record['total_gross'] = isset($record['total_gross']) ? (float) $record['total_gross'] : 0.0;
        $record['items'] = [];

        if (isset($record['items_json']) && $record['items_json'] !== null) {
            try {
                $decoded = json_decode((string) $record['items_json'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $record['items'] = $this->normalizeItems($decoded);
                }
            } catch (\JsonException $exception) {
                $record['items'] = [];
            }
        }

        $record['type_label'] = self::TYPE_LABELS[$record['type']] ?? 'Dokument';
        $record['reservation_number'] = isset($record['reservation_number']) && $record['reservation_number'] !== null
            ? (string) $record['reservation_number']
            : null;

        $record['payment_method'] = isset($record['payment_method']) && $record['payment_method'] !== ''
            ? (string) $record['payment_method']
            : null;
        $record['payment_reference'] = isset($record['payment_reference']) && $record['payment_reference'] !== ''
            ? (string) $record['payment_reference']
            : null;
        $record['payment_details'] = [];

        if (isset($record['payment_details_json']) && $record['payment_details_json'] !== null && $record['payment_details_json'] !== '') {
            try {
                $decodedDetails = json_decode((string) $record['payment_details_json'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedDetails)) {
                    $record['payment_details'] = $decodedDetails;
                }
            } catch (\JsonException $exception) {
                $record['payment_details'] = [];
            }
        }

        unset($record['payment_details_json']);

        return $record;
    }

    private function generateDocumentNumber(string $type): string
    {
        $sequenceKey = 'document_sequence_' . $type;
        $current = $this->settingsManager->get($sequenceKey, '0');
        $currentValue = (int) $current;
        $nextValue = $currentValue + 1;
        $this->settingsManager->set($sequenceKey, (string) $nextValue);

        $prefixMap = [
            self::TYPE_INVOICE => 'RE',
            self::TYPE_OFFER => 'AN',
            self::TYPE_REMINDER => 'MA',
            self::TYPE_CORRECTION => 'RK',
        ];

        $prefix = $prefixMap[$type] ?? 'DOC';
        $year = (new DateTimeImmutable())->format('Y');

        return sprintf('%s%s%05d', $prefix, $year, $nextValue);
    }

    private function nextCorrectionIndex(int $documentId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(correction_number) FROM documents WHERE correction_of_id = :id');
        $stmt->execute(['id' => $documentId]);
        $value = $stmt->fetchColumn();
        $current = $value !== false ? (int) $value : 0;

        return $current + 1;
    }

    private function ensureColumn(string $table, string $column, string $alterStatement): void
    {
        try {
            $tableName = str_replace('`', '', $table);
            $columnName = str_replace('`', '', $column);
            $sql = sprintf('SHOW COLUMNS FROM `%s` LIKE %s', $tableName, $this->pdo->quote($columnName));
            $exists = $this->pdo->query($sql)->fetch();

            if ($exists === false) {
                $this->pdo->exec($alterStatement);
            }
        } catch (PDOException $exception) {
            error_log(sprintf('DocumentManager: unable to ensure column %s.%s: %s', $table, $column, $exception->getMessage()));
        }
    }
}
