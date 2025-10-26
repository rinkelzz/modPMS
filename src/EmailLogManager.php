<?php

namespace ModPMS;

use JsonException;
use PDO;
use PDOException;

class EmailLogManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS email_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    recipient VARCHAR(191) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    body LONGTEXT NOT NULL,
                    headers LONGTEXT NULL,
                    status VARCHAR(32) NOT NULL,
                    error_message TEXT NULL,
                    context_json LONGTEXT NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email_logs_recipient (recipient),
                    INDEX idx_email_logs_status (status),
                    INDEX idx_email_logs_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException $exception) {
            error_log('EmailLogManager: unable to ensure schema: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        string $recipient,
        string $subject,
        string $body,
        string $headers,
        string $status,
        ?string $errorMessage = null,
        array $context = []
    ): void {
        $normalizedRecipient = trim($recipient);
        $normalizedSubject = trim($subject);
        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus === '') {
            $normalizedStatus = 'unknown';
        }

        $contextJson = null;
        if ($context !== []) {
            try {
                $contextJson = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                $contextJson = null;
                error_log('EmailLogManager: unable to encode context: ' . $exception->getMessage());
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_logs (recipient, subject, body, headers, status, error_message, context_json, created_at) ' .
            'VALUES (:recipient, :subject, :body, :headers, :status, :error_message, :context_json, NOW())'
        );

        $stmt->execute([
            'recipient' => $normalizedRecipient,
            'subject' => $normalizedSubject,
            'body' => $body,
            'headers' => $headers,
            'status' => $normalizedStatus,
            'error_message' => $errorMessage,
            'context_json' => $contextJson,
        ]);

        $this->prune();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->prepare('SELECT * FROM email_logs ORDER BY created_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }

            $entries[] = $this->mapRecord($row);
        }

        return $entries;
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM email_logs');
    }

    public function prune(int $days = 60): void
    {
        $days = max(1, min($days, 365));
        $stmt = $this->pdo->prepare('DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function mapRecord(array $record): array
    {
        $record['id'] = isset($record['id']) ? (int) $record['id'] : 0;
        $record['recipient'] = isset($record['recipient']) ? (string) $record['recipient'] : '';
        $record['subject'] = isset($record['subject']) ? (string) $record['subject'] : '';
        $record['body'] = isset($record['body']) ? (string) $record['body'] : '';
        $record['headers'] = isset($record['headers']) ? (string) $record['headers'] : '';
        $record['status'] = isset($record['status']) ? (string) $record['status'] : 'unknown';
        $record['error_message'] = isset($record['error_message']) && $record['error_message'] !== null
            ? (string) $record['error_message']
            : null;
        $record['created_at'] = isset($record['created_at']) ? (string) $record['created_at'] : null;

        if (isset($record['context_json']) && $record['context_json'] !== null) {
            try {
                $record['context'] = json_decode((string) $record['context_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $record['context'] = [];
                error_log('EmailLogManager: unable to decode context: ' . $exception->getMessage());
            }
        } else {
            $record['context'] = [];
        }

        unset($record['context_json']);

        return $record;
    }
}
