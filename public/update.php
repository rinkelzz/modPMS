<?php

declare(strict_types=1);

use ModPMS\SystemUpdater;

require_once __DIR__ . '/../src/SystemUpdater.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Nicht autorisiert';
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Nicht autorisiert';
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

$config = require __DIR__ . '/../config/app.php';
$branch = $config['repository']['branch'];

if (isset($_POST['branch']) && is_string($_POST['branch'])) {
    $candidate = trim($_POST['branch']);
    if ($candidate !== '') {
        $branch = $candidate;
    }
}

$updater = new SystemUpdater(dirname(__DIR__), $branch, $config['repository']['url']);

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
