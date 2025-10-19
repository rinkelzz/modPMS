<?php

declare(strict_types=1);

use ModPMS\SystemUpdater;

require_once __DIR__ . '/../src/SystemUpdater.php';

session_start();

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

$config = require __DIR__ . '/../config/app.php';
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
