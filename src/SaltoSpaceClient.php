<?php

namespace ModPMS;

use DateTimeInterface;
use JsonException;
use RuntimeException;

class SaltoSpaceClient
{
    private string $baseUrl;

    private string $tenantId;

    private ?string $siteId;

    private string $apiToken;

    private int $timeoutSeconds;

    /**
     * @var callable|null
     */
    private $transport;

    public function __construct(
        string $baseUrl,
        string $tenantId,
        ?string $siteId,
        string $apiToken,
        ?callable $transport = null,
        int $timeoutSeconds = 15
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Salto Space API-URL fehlt.');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Die Salto Space API-URL ist ungültig.');
        }

        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new RuntimeException('Salto Space Mandanten-ID fehlt.');
        }

        $apiToken = trim($apiToken);
        if ($apiToken === '') {
            throw new RuntimeException('Salto Space API-Token fehlt.');
        }

        $siteId = $siteId !== null ? trim($siteId) : null;
        if ($siteId === '') {
            $siteId = null;
        }

        $timeoutSeconds = max(1, min($timeoutSeconds, 120));

        $this->baseUrl = $baseUrl;
        $this->tenantId = $tenantId;
        $this->siteId = $siteId;
        $this->apiToken = $apiToken;
        $this->transport = $transport;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * @param array<string,mixed>|null $metadata
     * @return array{success:bool,status:int,message:string,body:array<string,mixed>,request:array<string,mixed>,raw:string}
     */
    public function issueMobileKey(
        string $reservationNumber,
        string $guestFirstName,
        string $guestLastName,
        DateTimeInterface $validFrom,
        DateTimeInterface $validUntil,
        ?string $roomNumber = null,
        ?string $guestEmail = null,
        ?string $guestPhone = null,
        ?array $metadata = null
    ): array {
        $reservationNumber = trim($reservationNumber);
        if ($reservationNumber === '') {
            throw new RuntimeException('Reservierungsnummer für Salto Space fehlt.');
        }

        $guestFirstName = trim($guestFirstName);
        $guestLastName = trim($guestLastName);

        if ($guestFirstName === '' && $guestLastName === '') {
            throw new RuntimeException('Gastname für Salto Space fehlt.');
        }

        if ($validUntil <= $validFrom) {
            throw new RuntimeException('Abreisedatum muss nach dem Anreisedatum liegen.');
        }

        $payload = [
            'reservationNumber' => $reservationNumber,
            'guest' => [],
            'access' => [
                'validFrom' => $validFrom->format(DateTimeInterface::ATOM),
                'validUntil' => $validUntil->format(DateTimeInterface::ATOM),
            ],
        ];

        if ($guestFirstName !== '') {
            $payload['guest']['firstName'] = $guestFirstName;
        }

        if ($guestLastName !== '') {
            $payload['guest']['lastName'] = $guestLastName;
        }

        $guestEmail = $guestEmail !== null ? trim($guestEmail) : null;
        if ($guestEmail !== null && $guestEmail !== '') {
            $payload['guest']['email'] = $guestEmail;
        }

        $guestPhone = $guestPhone !== null ? trim($guestPhone) : null;
        if ($guestPhone !== null && $guestPhone !== '') {
            $payload['guest']['phone'] = $guestPhone;
        }

        if ($payload['guest'] === []) {
            unset($payload['guest']);
        }

        if ($roomNumber !== null) {
            $roomNumber = trim($roomNumber);
            if ($roomNumber !== '') {
                $payload['roomNumber'] = $roomNumber;
            }
        }

        if ($metadata !== null) {
            $normalisedMetadata = $this->normaliseMetadata($metadata);
            if ($normalisedMetadata !== []) {
                $payload['metadata'] = $normalisedMetadata;
            }
        }

        $response = $this->request('POST', $this->buildMobileKeyEndpoint(), $payload);

        $statusCode = $response['status'];
        $success = $statusCode >= 200 && $statusCode < 300;
        $message = $success
            ? 'Salto Space hat den Schlüsselauftrag angenommen.'
            : $this->extractErrorMessage($response['body']);

        return [
            'success' => $success,
            'status' => $statusCode,
            'message' => $message,
            'body' => $response['body'],
            'request' => $response['request'],
            'raw' => $response['raw'],
        ];
    }

    private function buildMobileKeyEndpoint(): string
    {
        if ($this->siteId !== null) {
            return sprintf(
                '/tenants/%s/sites/%s/mobile-keys',
                rawurlencode($this->tenantId),
                rawurlencode($this->siteId)
            );
        }

        return sprintf(
            '/tenants/%s/mobile-keys',
            rawurlencode($this->tenantId)
        );
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{status:int,body:array<string,mixed>,raw:string,request:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = $this->buildHeaders();
        $requestData = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
            'timeout' => $this->timeoutSeconds,
        ];

        if ($this->transport !== null) {
            $response = ($this->transport)($requestData);

            if (!is_array($response) || !isset($response['status'])) {
                throw new RuntimeException('Ungültige Antwort des Salto Space-Transports.');
            }

            $body = $response['body'] ?? [];
            $raw = '';

            if (is_string($body)) {
                $raw = $body;
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                } else {
                    $body = ['raw' => $body];
                }
            } elseif (is_array($body)) {
                $raw = $this->encodeJsonSafely($body);
            } else {
                $body = [];
            }

            return [
                'status' => (int) $response['status'],
                'body' => $body,
                'raw' => $raw,
                'request' => $requestData,
            ];
        }

        if (!extension_loaded('curl')) {
            throw new RuntimeException('Die PHP-Extension "curl" wird für die Kommunikation mit Salto Space benötigt.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Salto Space-Anfrage konnte nicht initialisiert werden.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];

        if ($payload !== null) {
            try {
                $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new RuntimeException(
                    'Salto Space-Anfrage konnte nicht vorbereitet werden: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            if (defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException('Zeitüberschreitung bei der Kommunikation mit Salto Space.');
            }

            throw new RuntimeException('Salto Space-Anfrage fehlgeschlagen: ' . $error);
        }

        $statusCode = (int) (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0);
        curl_close($handle);

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $responseBody];
        }

        return [
            'status' => $statusCode,
            'body' => $decoded,
            'raw' => $responseBody,
            'request' => $requestData,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiToken,
            'X-Tenant-Id: ' . $this->tenantId,
        ];

        if ($this->siteId !== null) {
            $headers[] = 'X-Site-Id: ' . $this->siteId;
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function normaliseMetadata(array $metadata): array
    {
        $normalised = [];

        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $normalised[(string) $key] = (string) $value;
                continue;
            }

            if (is_array($value)) {
                $sub = $this->normaliseMetadata($value);
                if ($sub !== []) {
                    $normalised[(string) $key] = $sub;
                }
            }
        }

        return $normalised;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function extractErrorMessage(array $body): string
    {
        $primaryMessage = null;

        foreach (['message', 'error', 'detail', 'description'] as $field) {
            if (isset($body[$field]) && is_string($body[$field]) && trim($body[$field]) !== '') {
                $primaryMessage = $this->sanitizeMessage((string) $body[$field]);
                break;
            }
        }

        $validationMessages = [];

        if (isset($body['errors']) && is_array($body['errors'])) {
            array_walk_recursive(
                $body['errors'],
                static function ($value) use (&$validationMessages): void {
                    if (is_string($value) && trim($value) !== '') {
                        $validationMessages[] = trim($value);
                    }
                }
            );
        }

        if ($validationMessages !== []) {
            $validationText = $this->sanitizeMessage('Validierungsfehler: ' . implode('; ', array_unique($validationMessages)));

            if ($primaryMessage !== null && $primaryMessage !== '') {
                if (stripos($validationText, $primaryMessage) !== false) {
                    return $validationText;
                }

                return $primaryMessage . ' – ' . $validationText;
            }

            return $validationText;
        }

        if ($primaryMessage !== null && $primaryMessage !== '') {
            return $primaryMessage;
        }

        return 'Unbekannte Antwort der Salto Space API.';
    }

    private function sanitizeMessage(string $message): string
    {
        $message = strip_tags($message);
        $message = preg_replace('/\s+/', ' ', $message);
        if ($message === null) {
            $message = '';
        }

        $message = trim($message);

        return $message !== '' ? $message : 'Unbekannte Antwort der Salto Space API.';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function encodeJsonSafely(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            return '{}';
        }
    }
}

