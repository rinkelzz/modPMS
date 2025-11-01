<?php

use ModPMS\SaltoSpaceClient;

require_once __DIR__ . '/../src/SaltoSpaceClient.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($message . sprintf(' (erwartet: %s, erhalten: %s)', var_export($expected, true), var_export($actual, true)));
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new \RuntimeException($message . sprintf(' ("%s" nicht in "%s")', $needle, $haystack));
    }
}

function testIssueMobileKeySuccess(): void
{
    $capturedRequest = null;
    $transport = function (array $request) use (&$capturedRequest): array {
        $capturedRequest = $request;

        return [
            'status' => 201,
            'body' => ['id' => 'abc123', 'status' => 'created'],
        ];
    };

    $client = new SaltoSpaceClient(
        'https://space.example.com',
        'tenant-123',
        'site-1',
        'token-xyz',
        $transport,
        10
    );

    $validFrom = new DateTimeImmutable('2024-01-10T15:00:00+00:00');
    $validUntil = new DateTimeImmutable('2024-01-12T10:00:00+00:00');

    $result = $client->issueMobileKey(
        'Res2024-0001',
        'Max',
        'Mustermann',
        $validFrom,
        $validUntil,
        '101',
        'max@example.com',
        '+4912345678',
        ['source' => 'test']
    );

    assertTrue($result['success'] === true, 'Die API sollte einen erfolgreichen Schlüsselauftrag melden.');
    assertSame(201, $result['status'], 'Der Statuscode sollte 201 sein.');
    assertSame('Salto Space hat den Schlüsselauftrag angenommen.', $result['message'], 'Die Erfolgsmeldung ist unerwartet.');

    assertTrue(is_array($capturedRequest), 'Der Transport sollte aufgerufen werden.');
    assertSame('POST', $capturedRequest['method'] ?? null, 'Die Methode sollte POST sein.');
    assertSame(
        'https://space.example.com/tenants/tenant-123/sites/site-1/mobile-keys',
        $capturedRequest['url'] ?? null,
        'Die Ziel-URL stimmt nicht.'
    );
    assertSame('101', $capturedRequest['payload']['roomNumber'] ?? null, 'Die Zimmernummer wurde nicht übernommen.');
    assertSame('test', $capturedRequest['payload']['metadata']['source'] ?? null, 'Metadaten wurden nicht übertragen.');
}

function testIssueMobileKeyValidationError(): void
{
    $transport = static function (array $request): array {
        return [
            'status' => 422,
            'body' => [
                'message' => 'Validation failed',
                'errors' => [
                    'room' => ['Room not found'],
                ],
            ],
        ];
    };

    $client = new SaltoSpaceClient(
        'https://space.example.com',
        'tenant-123',
        null,
        'token-xyz',
        $transport,
        12
    );

    $validFrom = new DateTimeImmutable('2024-05-01T15:00:00+00:00');
    $validUntil = new DateTimeImmutable('2024-05-03T10:00:00+00:00');

    $result = $client->issueMobileKey(
        'Res2024-0002',
        '',
        'Tester',
        $validFrom,
        $validUntil,
        null,
        null,
        null,
        null
    );

    assertTrue($result['success'] === false, 'Die API sollte einen Fehler melden.');
    assertSame(422, $result['status'], 'Der Statuscode sollte 422 sein.');
    assertContains('Validierungsfehler', $result['message'], 'Die Fehlermeldung sollte einen Validierungshinweis enthalten.');
    assertContains('Room not found', $result['message'], 'Die Fehlermeldung sollte das Feldproblem benennen.');
}

$tests = [
    'issueMobileKeySuccess' => 'testIssueMobileKeySuccess',
    'issueMobileKeyValidationError' => 'testIssueMobileKeyValidationError',
];

$passed = 0;

foreach ($tests as $name => $callable) {
    try {
        $callable();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Throwable $throwable) {
        echo "[FAIL] {$name}: " . $throwable->getMessage() . "\n";
        exit(1);
    }
}

echo sprintf("%d/%d Tests bestanden\n", $passed, count($tests));
