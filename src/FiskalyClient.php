<?php

namespace ModPMS;

use JsonException;
use RuntimeException;

class FiskalyClient
{
    public const DEFAULT_API_BASE_URL = 'https://e-receipt.fiskaly.com';
    public const DEFAULT_AUTH_URL = 'https://app.fiskaly.com/api/v1/auth';

    private string $apiKey;
    private string $apiSecret;
    private string $cashRegisterId;
    private string $templateId;
    private ?string $organizationId;
    private string $apiBaseUrl;
    private string $authUrl;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    private int $retryAttempts;
    private int $retryDelaySeconds;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $cashRegisterId,
        string $templateId,
        ?string $organizationId = null,
        ?string $apiBaseUrl = null,
        ?string $authUrl = null,
        ?array $httpOptions = null
    ) {
        $this->apiKey = trim($apiKey);
        $this->apiSecret = trim($apiSecret);
        $this->cashRegisterId = trim($cashRegisterId);
        $this->templateId = trim($templateId);
        $this->organizationId = $organizationId !== null ? trim($organizationId) : null;
        $this->apiBaseUrl = $this->normaliseBaseUrl($apiBaseUrl ?? self::DEFAULT_API_BASE_URL);
        $this->authUrl = $this->normaliseAuthUrl($authUrl ?? self::DEFAULT_AUTH_URL);
        $this->timeoutSeconds = 30;
        $this->connectTimeoutSeconds = 10;
        $this->retryAttempts = 1;
        $this->retryDelaySeconds = 2;

        if ($this->apiKey === '') {
            throw new RuntimeException('Fiskaly API-Key fehlt.');
        }

        if ($this->apiSecret === '') {
            throw new RuntimeException('Fiskaly API-Secret fehlt.');
        }

        if ($this->cashRegisterId === '') {
            throw new RuntimeException('Fiskaly-Kassen-ID fehlt.');
        }

        if ($this->templateId === '') {
            throw new RuntimeException('Fiskaly-Vorlagen-ID fehlt.');
        }

        if ($httpOptions !== null) {
            $this->configureHttpOptions($httpOptions);
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $metadata
     * @return array{
     *     status:int,
     *     body:array<string,mixed>|null,
     *     raw:string|null,
     *     request:array<string,mixed>,
     *     error_class:?string,
     *     error_message:?string
     * }
     */
    public function createReceipt(array $document, array $items, array $customer = [], array $metadata = []): array
    {
        $payload = $this->buildReceiptPayload($document, $items, $customer, $metadata);

        return $this->request('POST', '/api/v1/receipts', $payload);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function buildReceiptPayload(array $document, array $items, array $customer, array $metadata): array
    {
        $currency = strtoupper((string) ($document['currency'] ?? 'EUR'));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $issueDate = null;
        if (isset($document['issue_date']) && $document['issue_date'] !== null && $document['issue_date'] !== '') {
            $issueDate = (string) $document['issue_date'];
        }

        $lineItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                $description = 'Position';
            }

            $quantity = $this->normaliseFloat($item['quantity'] ?? 1.0, 1.0);
            $unitPrice = $this->normaliseFloat($item['unit_price'] ?? 0.0);
            $totalNet = $this->normaliseFloat($item['total_net'] ?? ($quantity * $unitPrice));
            $totalVat = $this->normaliseFloat($item['total_vat'] ?? 0.0);
            $totalGross = $this->normaliseFloat($item['total_gross'] ?? ($totalNet + $totalVat));
            $taxRate = $this->normaliseFloat($item['tax_rate'] ?? 0.0);

            $lineItems[] = $this->filterEmpty([
                'description' => $description,
                'quantity' => $this->formatNumber($quantity),
                'unit_price' => $this->formatNumber($unitPrice),
                'total_price' => $this->formatNumber($totalGross),
                'total_net' => $this->formatNumber($totalNet),
                'total_vat' => $this->formatNumber($totalVat),
                'vat_rate' => $this->formatNumber($taxRate),
            ]);
        }

        $totals = $this->calculateTotals($items);

        $paymentDetails = [];
        if (isset($document['payment_method']) && $document['payment_method'] !== '') {
            $paymentDetails['method'] = (string) $document['payment_method'];
        }
        if (isset($document['payment_reference']) && $document['payment_reference'] !== '') {
            $paymentDetails['reference'] = (string) $document['payment_reference'];
        }

        $receipt = $this->filterEmpty([
            'document_number' => isset($document['document_number']) ? (string) $document['document_number'] : null,
            'external_id' => isset($document['id']) ? sprintf('document-%s', (string) $document['id']) : null,
            'currency_code' => $currency,
            'issued_at' => $issueDate,
            'total_price' => $this->formatNumber($totals['gross']),
            'total_net' => $this->formatNumber($totals['net']),
            'total_vat' => $this->formatNumber($totals['vat']),
            'line_items' => $lineItems,
            'payment' => $paymentDetails,
        ]);

        $customerPayload = $this->filterEmpty([
            'name' => isset($customer['name']) ? (string) $customer['name'] : null,
            'address' => isset($customer['address']) ? (string) $customer['address'] : null,
            'email' => isset($customer['email']) ? (string) $customer['email'] : null,
            'phone_number' => isset($customer['phone_number']) ? (string) $customer['phone_number'] : null,
        ]);

        $sellerPayload = $this->filterEmpty([
            'name' => isset($metadata['seller_name']) ? (string) $metadata['seller_name'] : null,
            'address' => isset($metadata['seller_address']) ? (string) $metadata['seller_address'] : null,
            'vat_id' => isset($metadata['seller_vat_id']) ? (string) $metadata['seller_vat_id'] : null,
        ]);

        $metadataPayload = $this->filterEmpty([
            'document_id' => isset($document['id']) ? (string) $document['id'] : null,
            'document_type' => isset($document['type']) ? (string) $document['type'] : null,
            'reservation_id' => isset($metadata['reservation_id']) ? (string) $metadata['reservation_id'] : null,
            'reservation_number' => isset($metadata['reservation_number']) ? (string) $metadata['reservation_number'] : null,
            'payment_method' => $paymentDetails['method'] ?? null,
            'payment_reference' => $paymentDetails['reference'] ?? null,
            'note' => isset($metadata['note']) ? (string) $metadata['note'] : null,
            'source' => $metadata['source'] ?? 'modpms',
        ]);

        if (isset($metadata['pdf_path']) && $metadata['pdf_path'] !== '') {
            $metadataPayload['pdf_path'] = (string) $metadata['pdf_path'];
        }

        $payload = $this->filterEmpty([
            'cash_register_id' => $this->cashRegisterId,
            'template_id' => $this->templateId,
            'receipt' => $receipt,
            'customer' => $customerPayload,
            'seller' => $sellerPayload,
            'metadata' => $metadataPayload,
        ]);

        if ($this->organizationId !== null && $this->organizationId !== '') {
            $payload['organization_id'] = $this->organizationId;
        }

        return $payload;
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
            if (!is_array($item)) {
                continue;
            }

            $net += $this->normaliseFloat($item['total_net'] ?? 0.0);
            $vat += $this->normaliseFloat($item['total_vat'] ?? 0.0);
            $gross += $this->normaliseFloat($item['total_gross'] ?? 0.0);
        }

        return [
            'net' => round($net, 2),
            'vat' => round($vat, 2),
            'gross' => round($gross, 2),
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string,mixed>|null, raw:string|null, request:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Für die Kommunikation mit Fiskaly wird die PHP-Extension "curl" benötigt.');
        }

        $token = $this->authenticate();

        $url = $this->buildUrl($path);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: modPMS/1.0 (+https://github.com/rinkelzz/modpms)',
        ];

        return $this->sendHttpRequest($method, $url, $headers, $payload, [
            'auth_method' => 'bearer',
            'cash_register_id' => $this->cashRegisterId,
            'template_id' => $this->templateId,
        ]);
    }

    private function authenticate(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt !== null && $this->tokenExpiresAt > time() + 30) {
            return $this->accessToken;
        }

        $payload = [
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
        ];

        if ($this->organizationId !== null && $this->organizationId !== '') {
            $payload['organization_id'] = $this->organizationId;
        }

        $response = $this->sendHttpRequest('POST', $this->authUrl, ['Accept: application/json', 'Content-Type: application/json'], $payload, [
            'auth' => 'credentials',
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = 'Fiskaly-Authentifizierung fehlgeschlagen.';
            if (isset($response['error_message']) && $response['error_message'] !== null && $response['error_message'] !== '') {
                $message .= ' ' . (string) $response['error_message'];
            }
            $body = $response['body'] ?? [];
            if (is_array($body)) {
                if (isset($body['message']) && $body['message'] !== '') {
                    $message .= ' ' . (string) $body['message'];
                } elseif (isset($body['error']) && $body['error'] !== '') {
                    $message .= ' ' . (string) $body['error'];
                }
            }

            $message .= sprintf(' (HTTP %d)', (int) $response['status']);
            throw new RuntimeException($message);
        }

        $body = $response['body'] ?? [];
        if (!is_array($body) || !isset($body['access_token'])) {
            throw new RuntimeException('Fiskaly-Authentifizierung lieferte kein Access Token.');
        }

        $token = (string) $body['access_token'];
        if ($token === '') {
            throw new RuntimeException('Fiskaly-Authentifizierung lieferte ein leeres Access Token.');
        }

        $this->accessToken = $token;
        $expiresIn = isset($body['expires_in']) ? (int) $body['expires_in'] : 0;
        if ($expiresIn > 0) {
            $this->tokenExpiresAt = time() + $expiresIn;
        } else {
            $this->tokenExpiresAt = time() + 300;
        }

        return $this->accessToken;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $meta
     * @return array{
     *     status:int,
     *     body:array<string,mixed>|null,
     *     raw:string|null,
     *     request:array<string,mixed>,
     *     error_class:?string,
     *     error_message:?string
     * }
     */
    private function sendHttpRequest(string $method, string $url, array $headers, ?array $payload = null, array $meta = []): array
    {
        $maxAttempts = max(1, $this->retryAttempts + 1);
        $attempt = 0;
        $lastResult = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('cURL konnte nicht initialisiert werden.');
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ];

            $connectTimeout = min($this->connectTimeoutSeconds, $this->timeoutSeconds);
            if ($connectTimeout > 0) {
                $options[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
            }

            if ($payload !== null) {
                try {
                    $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    curl_close($handle);
                    throw new RuntimeException('Fiskaly-Anfrage konnte nicht serialisiert werden: ' . $exception->getMessage(), 0, $exception);
                }

                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_POSTFIELDS] = $encodedPayload;
            }

            $options[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($handle, $options);

            $responseBody = curl_exec($handle);
            $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0;
            $curlError = $responseBody === false ? curl_error($handle) : '';
            curl_close($handle);

            $requestContext = array_merge($meta, [
                'url' => $url,
                'method' => strtoupper($method),
                'payload' => $payload,
                'attempts' => $attempt,
            ]);

            if ($responseBody === false) {
                $errorMessage = 'Fiskaly-Anfrage fehlgeschlagen: ' . ($curlError !== '' ? $curlError : 'Unbekannter Fehler');
                $result = [
                    'status' => 0,
                    'body' => null,
                    'raw' => null,
                    'error_class' => 'network',
                    'error_message' => $errorMessage,
                    'request' => $requestContext,
                ];

                if ($attempt >= $maxAttempts) {
                    return $result;
                }

                $lastResult = $result;
                if ($this->retryDelaySeconds > 0) {
                    usleep($this->retryDelaySeconds * 1_000_000);
                }

                continue;
            }

            $decoded = null;
            if ($responseBody !== '') {
                $decodedBody = json_decode($responseBody, true);
                if (is_array($decodedBody)) {
                    $decoded = $decodedBody;
                }
            }

            $errorClass = null;
            $errorMessage = null;

            if ($statusCode >= 500 || $statusCode === 429) {
                $errorClass = 'transient';
            } elseif ($statusCode >= 400) {
                $errorClass = 'client';
            }

            if ($errorClass !== null) {
                if (is_array($decoded)) {
                    foreach (['message', 'error', 'detail'] as $key) {
                        if (isset($decoded[$key]) && (string) $decoded[$key] !== '') {
                            $errorMessage = (string) $decoded[$key];
                            break;
                        }
                    }
                }

                if ($errorMessage === null) {
                    if ($statusCode === 429) {
                        $errorMessage = 'Fiskaly-Rate-Limit erreicht.';
                    } elseif ($statusCode >= 500) {
                        $errorMessage = 'Fiskaly-Server meldete einen Fehler.';
                    } else {
                        $errorMessage = 'Fiskaly-Anfrage wurde abgelehnt.';
                    }
                }
            }

            $result = [
                'status' => (int) $statusCode,
                'body' => $decoded,
                'raw' => $responseBody !== '' ? $responseBody : null,
                'error_class' => $errorClass,
                'error_message' => $errorMessage,
                'request' => $requestContext,
            ];

            if ($errorClass === 'transient' && $attempt < $maxAttempts) {
                $lastResult = $result;
                if ($this->retryDelaySeconds > 0) {
                    usleep($this->retryDelaySeconds * 1_000_000);
                }

                continue;
            }

            return $result;
        }

        if ($lastResult !== null) {
            return $lastResult;
        }

        return [
            'status' => 0,
            'body' => null,
            'raw' => null,
            'error_class' => 'network',
            'error_message' => 'Fiskaly-Anfrage konnte nicht ausgeführt werden.',
            'request' => array_merge($meta, [
                'url' => $url,
                'method' => strtoupper($method),
                'payload' => $payload,
                'attempts' => $maxAttempts,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $httpOptions
     */
    private function configureHttpOptions(array $httpOptions): void
    {
        if (isset($httpOptions['timeout'])) {
            $timeout = (int) $httpOptions['timeout'];
            if ($timeout > 0) {
                $this->timeoutSeconds = $timeout;
            }
        }

        if (isset($httpOptions['connect_timeout'])) {
            $connectTimeout = (int) $httpOptions['connect_timeout'];
            if ($connectTimeout > 0) {
                $this->connectTimeoutSeconds = $connectTimeout;
            }
        }

        if (isset($httpOptions['retry_attempts'])) {
            $retryAttempts = (int) $httpOptions['retry_attempts'];
            if ($retryAttempts >= 0) {
                $this->retryAttempts = $retryAttempts;
            }
        }

        if (isset($httpOptions['retry_delay'])) {
            $retryDelay = (int) $httpOptions['retry_delay'];
            if ($retryDelay >= 0) {
                $this->retryDelaySeconds = $retryDelay;
            }
        }

        if ($this->connectTimeoutSeconds > $this->timeoutSeconds) {
            $this->connectTimeoutSeconds = $this->timeoutSeconds;
        }
    }

    private function buildUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        return rtrim($this->apiBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function normaliseBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::DEFAULT_API_BASE_URL;
        }

        return preg_replace('/\/+$/', '', $url) ?? $url;
    }

    private function normaliseAuthUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::DEFAULT_AUTH_URL;
        }

        return $url;
    }

    private function normaliseFloat($value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $normalized = str_replace([',', ' '], ['.', ''], (string) $value);
        if (!is_numeric($normalized)) {
            return $default;
        }

        return (float) $normalized;
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterEmpty(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                if (trim($value) === '') {
                    continue;
                }

                $filtered[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $nested = $this->filterEmpty($value);
                if ($nested === []) {
                    continue;
                }

                $filtered[$key] = $nested;
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
