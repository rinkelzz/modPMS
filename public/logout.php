<?php

session_start();

$userName = $_SESSION['user_name'] ?? null;

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

session_start();

if ($userName) {
    $_SESSION['alert'] = [
        'type' => 'info',
        'message' => sprintf('Sie haben sich erfolgreich abgemeldet, %s.', htmlspecialchars($userName, ENT_QUOTES, 'UTF-8')),
    ];
} else {
    $_SESSION['alert'] = [
        'type' => 'info',
        'message' => 'Sie haben sich erfolgreich abgemeldet.',
    ];
}

header('Location: login.php');
exit;
