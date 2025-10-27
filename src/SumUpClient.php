<?php

namespace ModPMS;

use JsonException;
use RuntimeException;
use stdClass;

class SumUpClient
{
    private const API_BASE_URL = 'https://api.sumup.com/v0.1';

    private string $credential;

    private string $terminalSerial;

    private string $authMethod;

    public function __construct(string $credential, string $terminalSerial, string $authMethod = 'api_key')
    {
        $authMethod = strtolower(trim($authMethod));

        if (!in_array($authMethod, ['api_key', 'oauth'], true)) {
            throw new RuntimeException('Unsupported SumUp authentication method.');
        }

        $credential = trim($credential);
        $terminalSerial = strtoupper(trim($terminalSerial));

        if ($credential === '') {
            throw new RuntimeException('Missing SumUp credentials.');
        }

        if ($terminalSerial === '') {
            throw new RuntimeException('Missing SumUp terminal serial.');
        }

        $this->credential = $credential;
        $this->terminalSerial = $terminalSerial;
        $this->authMethod = $authMethod;
    }

    /**
     * @return array{status:int, body:array<string,mixed>, raw:string, request:array<string,mixed>}
     */
    public function sendPayment(
        float $amount,
        string $currency = 'EUR',
        ?string $externalId = null,
        ?string $description = null,
        ?float $tipAmount = null,
        ?string $affiliateAppId = null,
        ?string $affiliateKey = null
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        $payload = [
            'amount' => round($amount, 2),
            'currency' => strtoupper($currency),
            'transaction_type' => 'SALE',
        ];

        if ($tipAmount !== null && $tipAmount > 0) {
            $payload['tip_amount'] = round($tipAmount, 2);
        }

        if ($externalId !== null && $externalId !== '') {
            $payload['external_id'] = $externalId;
        }

        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        $affiliateAppId = $affiliateAppId !== null ? trim($affiliateAppId) : null;
        $affiliateKey = $affiliateKey !== null ? trim($affiliateKey) : null;

        if ($affiliateAppId !== null && $affiliateAppId !== '' && $affiliateKey !== null && $affiliateKey !== '') {
            $affiliatePayload = [
                'app_id' => $affiliateAppId,
                'key' => $affiliateKey,
                'tags' => new stdClass(),
            ];

            if ($externalId !== null && $externalId !== '') {
                $affiliatePayload['foreign_transaction_id'] = $externalId;
            }

            $payload['affiliate'] = $affiliatePayload;
        }

        $endpoint = sprintf(
            '%s/me/terminals/%s/transactions',
            self::API_BASE_URL,
            rawurlencode($this->terminalSerial)
        );

        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{status:int, body:array<string,mixed>, raw:string, request:array<string,mixed>}
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Die PHP-Extension "curl" wird für die Kommunikation mit SumUp benötigt.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        $headers = [
            'Accept: application/json',
            sprintf('Authorization: %s', $this->buildAuthorizationHeader()),
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        if ($payload !== null) {
            try {
                $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new RuntimeException(
                    'SumUp-Request konnte nicht vorbereitet werden: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('SumUp API request failed: ' . $message);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0;
        curl_close($handle);

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $responseBody];
        }

        return [
            'status' => (int) $statusCode,
            'body' => $decoded,
            'raw' => $responseBody,
            'request' => [
                'url' => $url,
                'method' => strtoupper($method),
                'payload' => $payload,
                'auth_method' => $this->authMethod,
                'terminal_serial' => $this->terminalSerial,
            ],
        ];
    }

    private function buildAuthorizationHeader(): string
    {
        return 'Bearer ' . $this->credential;
    }
}

