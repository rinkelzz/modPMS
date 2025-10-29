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

    private string $configuredReaderIdentifier;

    private string $authMethod;

    private ?string $resolvedReaderId = null;

    private ?string $readerResolutionSource = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $readerProbe = null;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $terminalProbe = null;

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
        $terminalSerial = $this->stripWhitespace($terminalSerial);
        $configuredIdentifier = $terminalSerial;
        $terminalSerial = $this->normaliseReaderIdentifier($terminalSerial);

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
        $this->configuredReaderIdentifier = $configuredIdentifier;
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
                'configured_reader_identifier' => $this->configuredReaderIdentifier,
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
        $resolution = $this->resolveReaderIdentifier();

        $endpoint = sprintf(
            '%s/merchants/%s/readers/%s/checkout',
            self::API_BASE_URL,
            rawurlencode($this->merchantCode),
            rawurlencode($resolution['reader_id'])
        );

        $response = $this->request('POST', $endpoint, $payload);

        $response['request']['reader_id'] = $resolution['reader_id'];
        $response['request']['reader_resolution'] = $resolution['source'];

        if ($resolution['reader_probe'] !== null) {
            $response['request']['reader_probe'] = $this->summarizeProbe($resolution['reader_probe']);
        }

        if ($resolution['terminal_probe'] !== null) {
            $response['request']['terminal_probe'] = $this->summarizeProbe($resolution['terminal_probe']);
        }

        return $response;
    }

    /**
     * @return array{reader_id:string, source:string, reader_probe:array<string,mixed>|null, terminal_probe:array<string,mixed>|null}
     */
    private function resolveReaderIdentifier(): array
    {
        if ($this->resolvedReaderId !== null) {
            return [
                'reader_id' => $this->resolvedReaderId,
                'source' => $this->readerResolutionSource ?? 'configured',
                'reader_probe' => $this->readerProbe,
                'terminal_probe' => $this->terminalProbe,
            ];
        }

        $readerCandidates = $this->buildReaderIdentifierCandidates();
        $readerProbe = null;

        foreach ($readerCandidates as $candidate) {
            $candidateValue = $candidate['value'];
            $candidateSource = $candidate['source'];

            $readerEndpoint = sprintf(
                '%s/merchants/%s/readers/%s',
                self::API_BASE_URL,
                rawurlencode($this->merchantCode),
                rawurlencode($candidateValue)
            );

            $probe = $this->request('GET', $readerEndpoint);
            $this->readerProbe = $probe;
            $readerProbe = $probe;

            if ($this->isSuccessfulStatus($probe['status'])) {
                $this->resolvedReaderId = $this->normaliseReaderIdentifier($candidateValue);
                $this->readerResolutionSource = $candidateSource;

                return [
                    'reader_id' => $this->resolvedReaderId,
                    'source' => $this->readerResolutionSource,
                    'reader_probe' => $this->readerProbe,
                    'terminal_probe' => $this->terminalProbe,
                ];
            }

            if (!in_array($probe['status'], [400, 404], true)) {
                throw new RuntimeException(
                    $this->formatReaderVerificationError($candidateValue, $probe, null)
                );
            }
        }

        $terminalProbe = null;
        $terminalCandidates = $this->buildTerminalIdentifierCandidates();

        foreach ($terminalCandidates as $terminalCandidate) {
            $terminalEndpoint = sprintf(
                '%s/merchants/%s/terminals/%s',
                self::API_BASE_URL,
                rawurlencode($this->merchantCode),
                rawurlencode($terminalCandidate)
            );

            $probe = $this->request('GET', $terminalEndpoint);
            $this->terminalProbe = $probe;
            $terminalProbe = $probe;

            if ($this->isSuccessfulStatus($probe['status'])) {
                $resolvedReaderId = $this->extractReaderIdFromTerminalResponse($probe['body'] ?? null);

                if ($resolvedReaderId !== null) {
                    $this->resolvedReaderId = $resolvedReaderId;
                    $this->readerResolutionSource = 'terminal_lookup';

                    return [
                        'reader_id' => $this->resolvedReaderId,
                        'source' => $this->readerResolutionSource,
                        'reader_probe' => $this->readerProbe,
                        'terminal_probe' => $this->terminalProbe,
                    ];
                }
            }

            if (!in_array($probe['status'], [400, 404], true)) {
                break;
            }
        }

        throw new RuntimeException(
            $this->formatReaderVerificationError(
                $this->configuredReaderIdentifier,
                $readerProbe,
                $terminalProbe
            )
        );
    }

    /**
     * @param mixed $response
     */
    private function extractReaderIdFromTerminalResponse($response): ?string
    {
        if (!is_array($response)) {
            return null;
        }

        $candidates = [];

        if (isset($response['reader_id'])) {
            $candidates[] = $response['reader_id'];
        }

        if (isset($response['readerId'])) {
            $candidates[] = $response['readerId'];
        }

        if (isset($response['reader']) && is_array($response['reader'])) {
            $readerData = $response['reader'];
            if (isset($readerData['id'])) {
                $candidates[] = $readerData['id'];
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if ($trimmed !== '') {
                    return $this->normaliseReaderIdentifier($trimmed);
                }
            }
        }

        return null;
    }

    private function normaliseReaderIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            return $identifier;
        }

        if (preg_match('/^rdr_[0-9a-z]{26}$/i', $identifier) === 1) {
            return 'rdr_' . strtolower(substr($identifier, 4));
        }

        return $identifier;
    }

    private function stripWhitespace(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $stripped = preg_replace('/\s+/', '', $value);

        return $stripped !== null ? $stripped : '';
    }

    /**
     * @return array<int,array{value:string,source:string}>
     */
    private function buildReaderIdentifierCandidates(): array
    {
        $candidates = [];
        $seen = [];

        $append = static function (string $value, string $source) use (&$candidates, &$seen): void {
            if ($value === '') {
                return;
            }

            if (isset($seen[$value])) {
                return;
            }

            $seen[$value] = true;
            $candidates[] = ['value' => $value, 'source' => $source];
        };

        $configured = $this->configuredReaderIdentifier;
        $normalized = $this->terminalSerial;

        $append($configured, 'configured');
        if ($normalized !== $configured) {
            $append($normalized, 'normalized');
        }

        $prefixNormalized = preg_replace('/^rdr_/i', 'rdr_', $configured, 1);
        if (is_string($prefixNormalized)) {
            $append($prefixNormalized, 'prefix_normalized');
        }

        $typeIdNormalized = $this->normaliseReaderIdentifier($configured);
        if ($typeIdNormalized !== '') {
            $append($typeIdNormalized, 'typeid_normalized');
        }

        $uppercaseConfigured = strtoupper($configured);
        if ($uppercaseConfigured !== $configured) {
            $append($uppercaseConfigured, 'uppercase');
        }

        $uppercaseNormalized = strtoupper($normalized);
        if ($uppercaseNormalized !== '' && $uppercaseNormalized !== $uppercaseConfigured) {
            $append($uppercaseNormalized, 'uppercase_normalized');
        }

        $lowercaseConfigured = strtolower($configured);
        if ($lowercaseConfigured !== $configured) {
            $append($lowercaseConfigured, 'lowercase');
        }

        return $candidates;
    }

    /**
     * @return string[]
     */
    private function buildTerminalIdentifierCandidates(): array
    {
        $candidates = [];
        $seen = [];

        $append = static function (string $value) use (&$candidates, &$seen): void {
            if ($value === '') {
                return;
            }

            if (isset($seen[$value])) {
                return;
            }

            $seen[$value] = true;
            $candidates[] = $value;
        };

        $configured = $this->configuredReaderIdentifier;
        $normalized = $this->terminalSerial;

        $append($configured);
        $append($normalized);
        $append($this->normaliseReaderIdentifier($configured));
        $append($this->normaliseReaderIdentifier($normalized));
        $append(strtoupper($configured));
        $append(strtoupper($normalized));
        $append(strtolower($configured));
        $append(strtolower($normalized));

        return $candidates;
    }

    /**
     * @param array<string,mixed> $probe
     * @return array<string,mixed>
     */
    private function summarizeProbe(array $probe): array
    {
        $summary = [
            'status' => (int) ($probe['status'] ?? 0),
        ];

        if (isset($probe['request']['url'])) {
            $summary['url'] = (string) $probe['request']['url'];
        }

        if (isset($probe['body']) && is_array($probe['body'])) {
            $summary['body'] = $probe['body'];
        } elseif (isset($probe['raw'])) {
            $summary['raw'] = (string) $probe['raw'];
        }

        return $summary;
    }

    private function isSuccessfulStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @param array<string,mixed>|null $readerProbe
     * @param array<string,mixed>|null $terminalProbe
     */
    private function formatReaderVerificationError(
        string $configuredIdentifier,
        ?array $readerProbe,
        ?array $terminalProbe
    ): string {
        $message = sprintf(
            'SumUp-Reader konnte nicht verifiziert werden. Bitte prüfen Sie Händlercode und Reader-ID "%s".',
            $configuredIdentifier
        );

        if ($readerProbe !== null) {
            $message .= sprintf(
                ' Reader-Endpunkt lieferte HTTP %d.',
                (int) ($readerProbe['status'] ?? 0)
            );

            $readerBody = $readerProbe['body'] ?? [];
            if (is_array($readerBody)) {
                $detail = $readerBody['message']
                    ?? $readerBody['error_message']
                    ?? $readerBody['error_description']
                    ?? null;

                if (is_string($detail) && $detail !== '') {
                    $message .= ' ' . $detail;
                }
            }
        }

        if ($terminalProbe !== null) {
            $message .= sprintf(
                ' Terminal-Abfrage lieferte HTTP %d.',
                (int) ($terminalProbe['status'] ?? 0)
            );

            $terminalBody = $terminalProbe['body'] ?? [];
            if (is_array($terminalBody)) {
                $detail = $terminalBody['message']
                    ?? $terminalBody['error_message']
                    ?? $terminalBody['error_description']
                    ?? null;

                if (is_string($detail) && $detail !== '') {
                    $message .= ' ' . $detail;
                }
            }
        }

        return $message;
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

