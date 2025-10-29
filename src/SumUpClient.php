<?php

namespace ModPMS;

use JsonException;
use RuntimeException;
use stdClass;

class SumUpClient
{
    private const API_BASE_URL = 'https://api.sumup.com/v0.1';

    private string $credential;

    private string $merchantCode;

    private string $terminalSerial;

    private string $authMethod;

    public function __construct(
        string $credential,
        string $merchantCode,
        string $terminalSerial,
        string $authMethod = 'api_key'
    )
    {
        $authMethod = strtolower(trim($authMethod));

        if (!in_array($authMethod, ['api_key', 'oauth'], true)) {
            throw new RuntimeException('Unsupported SumUp authentication method.');
        }

        $credential = trim($credential);
        $merchantCode = trim($merchantCode);
        $terminalSerial = trim($terminalSerial);

        if ($credential === '') {
            throw new RuntimeException('Missing SumUp credentials.');
        }

        if ($merchantCode === '') {
            throw new RuntimeException('Missing SumUp merchant code.');
        }

        if ($terminalSerial === '') {
            throw new RuntimeException('Missing SumUp terminal serial.');
        }

        $this->credential = $credential;
        $this->merchantCode = $merchantCode;
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
        ?string $affiliateAppId = null,
        ?string $affiliateKey = null
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        $currency = strtoupper($currency);
        $minorUnit = $this->resolveMinorUnit($currency);
        $payload = [
            'total_amount' => [
                'value' => $this->toMinorUnits($amount, $minorUnit),
                'minor_unit' => $minorUnit,
                'currency' => $currency,
            ],
            'payment_type' => 'CARD_PRESENT',
        ];

        if ($externalId !== null && $externalId !== '') {
            $payload['checkout_reference'] = $externalId;
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

        return $this->sendCheckoutRequest($payload);
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
                'merchant_code' => $this->merchantCode,
                'terminal_serial' => $this->terminalSerial,
            ],
        ];
    }

    private function buildAuthorizationHeader(): string
    {
        return 'Bearer ' . $this->credential;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int, body:array<string,mixed>, raw:string, request:array<string,mixed>}
     */
    private function sendCheckoutRequest(array $payload): array
    {
        $endpoints = [
            sprintf(
                '%s/merchants/%s/readers/%s/checkout',
                self::API_BASE_URL,
                rawurlencode($this->merchantCode),
                rawurlencode($this->terminalSerial)
            ),
            sprintf(
                '%s/merchants/%s/terminals/%s/checkout',
                self::API_BASE_URL,
                rawurlencode($this->merchantCode),
                rawurlencode($this->terminalSerial)
            ),
        ];

        $response = null;

        foreach ($endpoints as $endpoint) {
            $response = $this->request('POST', $endpoint, $payload);

            if ($response['status'] !== 404) {
                return $response;
            }
        }

        return $response ?? $this->request('POST', $endpoints[0], $payload);
    }

    private function resolveMinorUnit(string $currency): int
    {
        $map = [
            'BHD' => 3,
            'CLF' => 4,
            'CLP' => 0,
            'CVE' => 0,
            'DJF' => 0,
            'GNF' => 0,
            'IDR' => 0,
            'IQD' => 3,
            'IRR' => 0,
            'ISK' => 0,
            'JOD' => 3,
            'JPY' => 0,
            'KMF' => 0,
            'KRW' => 0,
            'KWD' => 3,
            'LYD' => 3,
            'OMR' => 3,
            'PYG' => 0,
            'RWF' => 0,
            'TND' => 3,
            'UGX' => 0,
            'UYI' => 0,
            'VND' => 0,
            'VUV' => 0,
            'XAF' => 0,
            'XOF' => 0,
            'XPF' => 0,
        ];

        return $map[$currency] ?? 2;
    }

    private function toMinorUnits(float $amount, int $minorUnit): int
    {
        $factor = 10 ** $minorUnit;

        return (int) round($amount * $factor);
    }
}

