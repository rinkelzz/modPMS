<?php

declare(strict_types=1);

use ModPMS\SystemUpdater;

require_once __DIR__ . '/../src/SystemUpdater.php';

session_start();

$config = require __DIR__ . '/../config/app.php';
$updateGuard = $config['update_guard'] ?? [];
$updateGuardEnabled = isset($updateGuard['enabled']) ? (bool) $updateGuard['enabled'] : false;
$updateGuardUsername = isset($updateGuard['username']) && is_string($updateGuard['username']) ? $updateGuard['username'] : '';
$updateGuardPasswordHash = isset($updateGuard['password_hash']) && is_string($updateGuard['password_hash']) ? $updateGuard['password_hash'] : '';
$updateEndpointActive = $updateGuardEnabled && $updateGuardUsername !== '' && $updateGuardPasswordHash !== '';

if (!$updateEndpointActive) {
    http_response_code(403);
    echo 'Update-Endpunkt ist deaktiviert.';
    exit;
}

if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="modPMS Update", charset="UTF-8"');
    http_response_code(401);
    echo 'Authentifizierung erforderlich.';
    exit;
}

$providedUsername = (string) $_SERVER['PHP_AUTH_USER'];
$providedPassword = (string) $_SERVER['PHP_AUTH_PW'];

if (!hash_equals($updateGuardUsername, $providedUsername) || !password_verify($providedPassword, $updateGuardPasswordHash)) {
    header('WWW-Authenticate: Basic realm="modPMS Update", charset="UTF-8"');
    http_response_code(401);
    echo 'Ungültige Zugangsdaten.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Methode nicht erlaubt';
    exit;
}

if (!isset($_POST['token'], $_SESSION['update_token']) || !hash_equals($_SESSION['update_token'], $_POST['token'])) {
    http_response_code(400);
    echo 'Ungültiger Token';
    exit;
}

unset($_SESSION['update_token']);

$updater = new SystemUpdater(dirname(__DIR__), $config['repository']['branch']);

$result = $updater->performUpdate();

$log = [$result['message']];
if (isset($result['details'])) {
    foreach ($result['details'] as $detail) {
        $log[] = sprintf('> %s (Status: %d)', $detail['command'], $detail['status']);
        foreach ($detail['output'] as $line) {
            $log[] = '  ' . $line;
        }
    }
}

$_SESSION['update_output'] = $log;
$_SESSION['alert'] = [
    'type' => $result['success'] ? 'success' : 'danger',
    'message' => $result['message'],
];

header('Location: index.php');
exit;
