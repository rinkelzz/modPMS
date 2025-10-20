<?php

use ModPMS\Calendar;
use ModPMS\Database;
use ModPMS\RoomCategoryManager;
use ModPMS\RoomManager;
use ModPMS\SystemUpdater;
use ModPMS\UserManager;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/RoomCategoryManager.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/RoomManager.php';
require_once __DIR__ . '/../src/SystemUpdater.php';
require_once __DIR__ . '/../src/UserManager.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $updateToken = bin2hex(random_bytes(32));
} catch (Throwable $exception) {
    $updateToken = bin2hex(hash('sha256', uniqid('', true), true));
}

$_SESSION['update_token'] = $updateToken;

$alert = null;
if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

$updateOutput = null;
if (isset($_SESSION['update_output']) && is_array($_SESSION['update_output'])) {
    $updateOutput = $_SESSION['update_output'];
    unset($_SESSION['update_output']);
}

$categoryFormData = [
    'id' => null,
    'name' => '',
    'description' => '',
    'capacity' => 1,
    'status' => 'aktiv',
];

$roomFormData = [
    'id' => null,
    'room_number' => '',
    'category_id' => '',
    'status' => 'frei',
    'floor' => '',
    'notes' => '',
];

$userFormData = [
    'id' => null,
    'name' => '',
    'email' => '',
    'role' => 'mitarbeiter',
];

$config = require __DIR__ . '/../config/app.php';
$dbError = null;
$categories = [];
$rooms = [];
$users = [];
$pdo = null;
$categoryManager = null;
$roomManager = null;
$userManager = null;

try {
    $pdo = Database::getConnection();
    $categoryManager = new RoomCategoryManager($pdo);
    $roomManager = new RoomManager($pdo);
    $userManager = new UserManager($pdo);
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$categoryStatuses = ['aktiv', 'inaktiv'];
$roomStatuses = ['frei', 'belegt', 'wartung'];
$userRoles = ['admin', 'mitarbeiter'];

if ($pdo !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form'])) {
    $form = $_POST['form'];

    switch ($form) {
        case 'category_create':
        case 'category_update':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $capacityInput = trim((string) ($_POST['capacity'] ?? ''));
            $capacityValue = (int) $capacityInput;
            $status = $_POST['status'] ?? 'aktiv';
            if (!in_array($status, $categoryStatuses, true)) {
                $status = 'aktiv';
            }

            $categoryFormData = [
                'id' => $form === 'category_update' ? (int) ($_POST['id'] ?? 0) : null,
                'name' => $name,
                'description' => $description,
                'capacity' => $capacityInput !== '' ? $capacityInput : '',
                'status' => $status,
            ];

            if ($name === '' || $capacityValue <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Namen und eine gültige Kapazität an.',
                ];
                break;
            }

            $payload = [
                'name' => $name,
                'description' => $description,
                'capacity' => $capacityValue,
                'status' => $status,
            ];

            if ($form === 'category_create') {
                $categoryManager->add($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Kategorie "%s" erfolgreich angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php#category-management');
                exit;
            }

            $categoryId = (int) ($_POST['id'] ?? 0);
            if ($categoryId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Kategorie konnte nicht aktualisiert werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            if ($categoryManager->find($categoryId) === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Kategorie wurde nicht gefunden.',
                ];
                break;
            }

            $categoryManager->update($categoryId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Kategorie "%s" wurde aktualisiert.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php#category-management');
            exit;

        case 'category_delete':
            $categoryId = (int) ($_POST['id'] ?? 0);

            if ($categoryId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Kategorie konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $category = $categoryManager->find($categoryId);
            if ($category === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Kategorie wurde nicht gefunden.',
                ];
                break;
            }

            $categoryManager->delete($categoryId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Kategorie "%s" wurde gelöscht.', htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php#category-management');
            exit;

        case 'room_create':
        case 'room_update':
            $roomNumber = trim($_POST['room_number'] ?? '');
            $roomStatus = $_POST['status'] ?? 'frei';
            if (!in_array($roomStatus, $roomStatuses, true)) {
                $roomStatus = 'frei';
            }
            $categoryIdInput = trim((string) ($_POST['category_id'] ?? ''));
            $categoryId = $categoryIdInput === '' ? null : (int) $categoryIdInput;
            $floor = trim($_POST['floor'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $roomFormData = [
                'id' => $form === 'room_update' ? (int) ($_POST['id'] ?? 0) : null,
                'room_number' => $roomNumber,
                'category_id' => $categoryIdInput,
                'status' => $roomStatus,
                'floor' => $floor,
                'notes' => $notes,
            ];

            if ($roomNumber === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie eine Zimmernummer an.',
                ];
                break;
            }

            $payload = [
                'room_number' => $roomNumber,
                'category_id' => $categoryId,
                'status' => $roomStatus,
                'floor' => $floor,
                'notes' => $notes,
            ];

            if ($form === 'room_create') {
                $roomManager->create($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Zimmer "%s" erfolgreich angelegt.', htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php#room-management');
                exit;
            }

            $roomId = (int) ($_POST['id'] ?? 0);
            if ($roomId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das Zimmer konnte nicht aktualisiert werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            if ($roomManager->find($roomId) === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das ausgewählte Zimmer wurde nicht gefunden.',
                ];
                break;
            }

            $roomManager->update($roomId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Zimmer "%s" wurde aktualisiert.', htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php#room-management');
            exit;

        case 'room_delete':
            $roomId = (int) ($_POST['id'] ?? 0);

            if ($roomId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das Zimmer konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $room = $roomManager->find($roomId);
            if ($room === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das ausgewählte Zimmer wurde nicht gefunden.',
                ];
                break;
            }

            $roomManager->delete($roomId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Zimmer "%s" wurde gelöscht.', htmlspecialchars($room['room_number'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php#room-management');
            exit;

        case 'user_create':
        case 'user_update':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Sie verfügen nicht über die erforderlichen Berechtigungen, um Benutzer zu verwalten.',
                ];
                break;
            }

            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $roleInput = $_POST['role'] ?? 'mitarbeiter';
            $role = in_array($roleInput, $userRoles, true) ? $roleInput : 'mitarbeiter';
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

            $userFormData = [
                'id' => $form === 'user_update' ? (int) ($_POST['id'] ?? 0) : null,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ];

            if ($name === '' || $email === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Namen und eine E-Mail-Adresse an.',
                ];
                break;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
                ];
                break;
            }

            if ($form === 'user_create' && $password === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte vergeben Sie ein Passwort für den neuen Benutzer.',
                ];
                break;
            }

            if ($password !== '' && strlen($password) < 8) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das Passwort muss mindestens 8 Zeichen lang sein.',
                ];
                break;
            }

            if ($password !== $passwordConfirm) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die eingegebenen Passwörter stimmen nicht überein.',
                ];
                break;
            }

            $existingUser = $userManager->findByEmail($email);
            $targetUserId = $form === 'user_update' ? (int) ($_POST['id'] ?? 0) : null;

            if ($existingUser !== null && ($targetUserId === null || (int) $existingUser['id'] !== $targetUserId)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die angegebene E-Mail-Adresse wird bereits verwendet.',
                ];
                break;
            }

            if ($form === 'user_create') {
                $userManager->create([
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Benutzer "%s" erfolgreich angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php#user-management');
                exit;
            }

            $userId = $targetUserId;
            if ($userId === null || $userId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Benutzer konnte nicht aktualisiert werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $user = $userManager->find($userId);
            if ($user === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Benutzer wurde nicht gefunden.',
                ];
                break;
            }

            $payload = [
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ];

            if ($password !== '') {
                $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $userManager->update($userId, $payload);

            if ((int) $_SESSION['user_id'] === $userId) {
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = $role;
            }

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Benutzer "%s" wurde aktualisiert.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php#user-management');
            exit;

        case 'user_delete':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Sie verfügen nicht über die erforderlichen Berechtigungen, um Benutzer zu verwalten.',
                ];
                break;
            }

            $userId = (int) ($_POST['id'] ?? 0);

            if ($userId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Benutzer konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            if ((int) $_SESSION['user_id'] === $userId) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Sie können Ihr eigenes Konto nicht löschen.',
                ];
                break;
            }

            $user = $userManager->find($userId);
            if ($user === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Benutzer wurde nicht gefunden.',
                ];
                break;
            }

            $userManager->delete($userId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Benutzer "%s" wurde gelöscht.', htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php#user-management');
            exit;
    }
} elseif ($pdo === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $alert = [
        'type' => 'danger',
        'message' => 'Aktionen sind ohne aktive Datenbankverbindung nicht verfügbar.',
    ];
}

if ($pdo !== null) {
    $categories = $categoryManager->all();
    $rooms = $roomManager->all();
    $users = $userManager->all();
}

if ($pdo !== null && isset($_GET['editCategory']) && $categoryFormData['id'] === null) {
    $categoryToEdit = $categoryManager->find((int) $_GET['editCategory']);

    if ($categoryToEdit) {
        $categoryFormData = [
            'id' => (int) $categoryToEdit['id'],
            'name' => $categoryToEdit['name'],
            'description' => $categoryToEdit['description'] ?? '',
            'capacity' => (int) $categoryToEdit['capacity'],
            'status' => $categoryToEdit['status'],
        ];
    } elseif ($alert === null) {
        $alert = [
            'type' => 'warning',
            'message' => 'Die ausgewählte Kategorie wurde nicht gefunden.',
        ];
    }
}

if ($pdo !== null && isset($_GET['editRoom']) && $roomFormData['id'] === null) {
    $roomToEdit = $roomManager->find((int) $_GET['editRoom']);

    if ($roomToEdit) {
        $roomFormData = [
            'id' => (int) $roomToEdit['id'],
            'room_number' => $roomToEdit['room_number'],
            'category_id' => $roomToEdit['category_id'] !== null ? (string) $roomToEdit['category_id'] : '',
            'status' => $roomToEdit['status'],
            'floor' => $roomToEdit['floor'] ?? '',
            'notes' => $roomToEdit['notes'] ?? '',
        ];
    } elseif ($alert === null) {
        $alert = [
            'type' => 'warning',
            'message' => 'Das ausgewählte Zimmer wurde nicht gefunden.',
        ];
    }
}

if ($pdo !== null && isset($_GET['editUser']) && $userFormData['id'] === null) {
    $userToEdit = $userManager->find((int) $_GET['editUser']);

    if ($userToEdit) {
        $userFormData = [
            'id' => (int) $userToEdit['id'],
            'name' => $userToEdit['name'],
            'email' => $userToEdit['email'],
            'role' => $userToEdit['role'],
        ];
    } elseif ($alert === null) {
        $alert = [
            'type' => 'warning',
            'message' => 'Der ausgewählte Benutzer wurde nicht gefunden.',
        ];
    }
}

$calendar = new Calendar();
$days = $calendar->daysOfMonth();

$updater = new SystemUpdater(dirname(__DIR__), $config['repository']['branch'], $config['repository']['url']);

?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['name']) ?> · Basis Modul</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css">
  </head>
  <body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
      <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="#">🏨 <?= htmlspecialchars($config['name']) ?></a>
        <div class="d-flex align-items-center gap-3 flex-wrap justify-content-end">
          <span class="badge rounded-pill text-bg-primary">Version <?= htmlspecialchars($config['version']) ?></span>
          <span class="text-muted small">Angemeldet als <?= htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Unbekannt') ?></span>
          <a href="logout.php" class="btn btn-outline-secondary btn-sm">Abmelden</a>
        </div>
      </div>
    </nav>

    <main class="container py-5">
      <?php if ($dbError): ?>
        <div class="alert alert-danger" role="alert">
          <?= htmlspecialchars($dbError) ?><br>
          <small>Bitte führen Sie die <a href="install.php">Installation</a> durch oder prüfen Sie die Verbindungseinstellungen.</small>
        </div>
      <?php endif; ?>

      <?php if ($alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> alert-dismissible fade show" role="alert">
          <?= $alert['message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="card module-card h-100">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <div>
                <h2 class="h5 mb-1">Dashboard</h2>
                <p class="text-muted mb-0">Kalender und aktuelle Auslastung im Blick.</p>
              </div>
              <span class="badge text-bg-info">Basis-Modul</span>
            </div>
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h4 mb-0"><?= htmlspecialchars($calendar->monthLabel()) ?></h3>
                <span class="text-muted">Heute: <?= (new DateTime())->format('d.m.Y') ?></span>
              </div>
              <div class="calendar-grid-wrapper">
                <table class="table table-bordered align-middle room-calendar">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" class="room-column">Zimmer</th>
                      <?php foreach ($days as $day): ?>
                        <th scope="col" class="text-center">
                          <div class="day-number"><?= $day['day'] ?></div>
                          <small class="text-muted text-uppercase"><?= $day['weekday'] ?></small>
                        </th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rooms as $room): ?>
                      <tr>
                        <th scope="row" class="room-label">
                          <div class="fw-semibold">Zimmer <?= htmlspecialchars($room['number']) ?></div>
                          <small class="text-muted">Status: <?= htmlspecialchars(ucfirst($room['status'])) ?></small>
                        </th>
                        <?php foreach ($days as $day): ?>
                          <td class="room-calendar-cell <?= $day['isToday'] ? 'today' : '' ?>" data-date="<?= $day['date'] ?>" data-room="<?= htmlspecialchars($room['number']) ?>">
                            <span class="visually-hidden">Zimmer <?= htmlspecialchars($room['number']) ?> am <?= $day['date'] ?></span>
                          </td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rooms)): ?>
                      <tr>
                        <td colspan="<?= count($days) + 1 ?>" class="text-muted text-center py-4">Noch keine Zimmer angelegt.</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card module-card h-100">
            <div class="card-header bg-transparent border-0">
              <h2 class="h5 mb-1">Schnellstatistik</h2>
              <p class="text-muted mb-0">Überblick über Zimmerkategorien.</p>
            </div>
            <div class="card-body">
              <ul class="list-group list-group-flush">
                <?php foreach ($categories as $category): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($category['name']) ?></div>
                      <small class="text-muted">Kapazität: <?= (int) $category['capacity'] ?> Gäste</small>
                    </div>
                    <span class="badge <?= $category['status'] === 'aktiv' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                      <?= htmlspecialchars(ucfirst($category['status'])) ?>
                    </span>
                  </li>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                  <li class="list-group-item text-muted">Noch keine Kategorien erfasst.</li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-lg-8">
          <div class="card module-card h-100" id="category-management">
            <?php $isEditingCategory = $categoryFormData['id'] !== null; ?>
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h2 class="h5 mb-1">Zimmerkategorien verwalten</h2>
                <p class="text-muted mb-0"><?= $isEditingCategory ? 'Bestehende Kategorie bearbeiten oder aktualisieren.' : 'Neue Kategorien für die Belegung anlegen.' ?></p>
              </div>
              <?php if ($isEditingCategory): ?>
                <span class="badge text-bg-primary">Bearbeitung</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" class="row g-3" id="category-form">
                <input type="hidden" name="form" value="<?= $isEditingCategory ? 'category_update' : 'category_create' ?>">
                <?php if ($isEditingCategory): ?>
                  <input type="hidden" name="id" value="<?= (int) $categoryFormData['id'] ?>">
                <?php endif; ?>
                <div class="col-12">
                  <label for="category-name" class="form-label">Bezeichnung *</label>
                  <input type="text" class="form-control" id="category-name" name="name" value="<?= htmlspecialchars((string) $categoryFormData['name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="category-description" class="form-label">Beschreibung</label>
                  <textarea class="form-control" id="category-description" name="description" rows="2" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $categoryFormData['description']) ?></textarea>
                </div>
                <div class="col-md-6">
                  <label for="category-capacity" class="form-label">Kapazität *</label>
                  <input type="number" min="1" class="form-control" id="category-capacity" name="capacity" value="<?= htmlspecialchars((string) $categoryFormData['capacity']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="category-status" class="form-label">Status</label>
                  <select class="form-select" id="category-status" name="status" <?= $pdo === null ? 'disabled' : '' ?>>
                    <?php foreach ($categoryStatuses as $status): ?>
                      <option value="<?= htmlspecialchars($status) ?>" <?= $categoryFormData['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 d-flex justify-content-end align-items-center flex-wrap gap-2">
                  <?php if ($isEditingCategory): ?>
                    <a href="index.php#category-management" class="btn btn-outline-secondary">Abbrechen</a>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingCategory ? 'Kategorie aktualisieren' : 'Kategorie speichern' ?></button>
                </div>
              </form>
              <?php if ($pdo === null): ?>
                <p class="text-muted mt-3 mb-0">Die Formularfelder sind deaktiviert, bis eine gültige Datenbankverbindung besteht.</p>
              <?php endif; ?>

              <?php if ($pdo !== null): ?>
                <div class="table-responsive mt-4">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Bezeichnung</th>
                        <th scope="col">Kapazität</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Aktionen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($categories as $category): ?>
                        <tr>
                          <td>
                            <div class="fw-semibold"><?= htmlspecialchars($category['name']) ?></div>
                            <?php if (!empty($category['description'])): ?>
                              <div class="small text-muted"><?= htmlspecialchars($category['description']) ?></div>
                            <?php endif; ?>
                          </td>
                          <td><?= (int) $category['capacity'] ?> Gäste</td>
                          <td>
                            <span class="badge <?= $category['status'] === 'aktiv' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= htmlspecialchars(ucfirst($category['status'])) ?></span>
                          </td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                              <a class="btn btn-outline-secondary btn-sm" href="index.php?editCategory=<?= (int) $category['id'] ?>#category-management">Bearbeiten</a>
                              <form method="post" onsubmit="return confirm('Kategorie wirklich löschen?');">
                                <input type="hidden" name="form" value="category_delete">
                                <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Löschen</button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (empty($categories)): ?>
                        <tr>
                          <td colspan="4" class="text-center text-muted py-3">Noch keine Kategorien erfasst.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card module-card h-100">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <div>
                <h2 class="h5 mb-1">Systemupdates</h2>
                <p class="text-muted mb-0">Version prüfen und GitHub Updates abrufen.</p>
              </div>
              <span class="badge text-bg-warning">Dev Tools</span>
            </div>
            <div class="card-body">
              <form method="post" action="update.php" class="d-flex flex-column gap-3">
                <input type="hidden" name="token" value="<?= htmlspecialchars($updateToken, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                  <label class="form-label">Repository</label>
                  <p class="form-control-plaintext mb-0">
                    <?php $repositoryLabel = rtrim(str_replace(['https://', 'http://'], '', $config['repository']['url']), '/'); ?>
                    <a href="<?= htmlspecialchars($config['repository']['url']) ?>" target="_blank" rel="noopener">
                      <?= htmlspecialchars($repositoryLabel) ?>
                    </a>
                  </p>
                </div>
                <div>
                  <label for="branch" class="form-label">Branch</label>
                  <input type="text" class="form-control" id="branch" name="branch" value="<?= htmlspecialchars($config['repository']['branch']) ?>">
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <span class="text-muted d-block">Aktuelle Version</span>
                    <span class="fs-5 fw-semibold"><?= htmlspecialchars($config['version']) ?></span>
                  </div>
                  <button type="submit" class="btn btn-outline-primary">Update ausführen</button>
                </div>
              </form>
              <?php if ($updateOutput): ?>
                <div class="alert alert-secondary mt-4 mb-0" role="status">
                  <h3 class="h6 text-uppercase text-muted">Letzte Aktualisierung</h3>
                  <pre class="small mb-0 bg-transparent border-0 p-0"><?= htmlspecialchars(implode("\n", $updateOutput)) ?></pre>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12">
          <div class="card module-card" id="room-management">
            <?php $isEditingRoom = $roomFormData['id'] !== null; ?>
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h2 class="h5 mb-1">Zimmerstamm verwalten</h2>
                <p class="text-muted mb-0"><?= $isEditingRoom ? 'Zimmerdaten bearbeiten und Änderungen speichern.' : 'Neue Zimmer erfassen und bestehenden Bestand pflegen.' ?></p>
              </div>
              <?php if ($isEditingRoom): ?>
                <span class="badge text-bg-primary">Bearbeitung</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" class="row g-3" id="room-form">
                <input type="hidden" name="form" value="<?= $isEditingRoom ? 'room_update' : 'room_create' ?>">
                <?php if ($isEditingRoom): ?>
                  <input type="hidden" name="id" value="<?= (int) $roomFormData['id'] ?>">
                <?php endif; ?>
                <div class="col-md-3">
                  <label for="room-number" class="form-label">Zimmernummer *</label>
                  <input type="text" class="form-control" id="room-number" name="room_number" value="<?= htmlspecialchars((string) $roomFormData['room_number']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-3">
                  <label for="room-category" class="form-label">Kategorie</label>
                  <select class="form-select" id="room-category" name="category_id" <?= $pdo === null ? 'disabled' : '' ?>>
                    <option value="">Keine Zuordnung</option>
                    <?php foreach ($categories as $category): ?>
                      <option value="<?= (int) $category['id'] ?>" <?= $roomFormData['category_id'] !== '' && (int) $roomFormData['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label for="room-status" class="form-label">Status</label>
                  <select class="form-select" id="room-status" name="status" <?= $pdo === null ? 'disabled' : '' ?>>
                    <?php foreach ($roomStatuses as $status): ?>
                      <option value="<?= htmlspecialchars($status) ?>" <?= $roomFormData['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label for="room-floor" class="form-label">Etage</label>
                  <input type="text" class="form-control" id="room-floor" name="floor" value="<?= htmlspecialchars((string) $roomFormData['floor']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="room-notes" class="form-label">Notizen</label>
                  <textarea class="form-control" id="room-notes" name="notes" rows="2" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $roomFormData['notes']) ?></textarea>
                </div>
                <div class="col-12 d-flex justify-content-end align-items-center flex-wrap gap-2">
                  <?php if ($isEditingRoom): ?>
                    <a href="index.php#room-management" class="btn btn-outline-secondary">Abbrechen</a>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingRoom ? 'Zimmer aktualisieren' : 'Zimmer speichern' ?></button>
                </div>
              </form>
              <?php if ($pdo === null): ?>
                <p class="text-muted mt-3 mb-0">Die Formularfelder sind deaktiviert, bis eine gültige Datenbankverbindung besteht.</p>
              <?php endif; ?>

              <?php if ($pdo !== null): ?>
                <div class="table-responsive mt-4">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Zimmer</th>
                        <th scope="col">Kategorie</th>
                        <th scope="col">Status</th>
                        <th scope="col">Etage</th>
                        <th scope="col">Notizen</th>
                        <th scope="col" class="text-end">Aktionen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rooms as $room): ?>
                        <?php
                          $roomStatusClass = 'text-bg-light border';
                          if ($room['status'] === 'frei') {
                              $roomStatusClass = 'text-bg-success';
                          } elseif ($room['status'] === 'belegt') {
                              $roomStatusClass = 'text-bg-danger';
                          } elseif ($room['status'] === 'wartung') {
                              $roomStatusClass = 'text-bg-warning';
                          }
                        ?>
                        <tr>
                          <td class="fw-semibold"><?= htmlspecialchars($room['number']) ?></td>
                          <td>
                            <?php if ($room['category_name']): ?>
                              <?= htmlspecialchars($room['category_name']) ?>
                            <?php else: ?>
                              <span class="text-muted">Keine</span>
                            <?php endif; ?>
                          </td>
                          <td><span class="badge <?= $roomStatusClass ?>"><?= htmlspecialchars(ucfirst($room['status'])) ?></span></td>
                          <td><?= $room['floor'] !== null ? htmlspecialchars($room['floor']) : '—' ?></td>
                          <td><?= $room['notes'] !== null && $room['notes'] !== '' ? htmlspecialchars($room['notes']) : '—' ?></td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                              <a class="btn btn-outline-secondary btn-sm" href="index.php?editRoom=<?= (int) $room['id'] ?>#room-management">Bearbeiten</a>
                              <form method="post" onsubmit="return confirm('Zimmer wirklich löschen?');">
                                <input type="hidden" name="form" value="room_delete">
                                <input type="hidden" name="id" value="<?= (int) $room['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Löschen</button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (empty($rooms)): ?>
                        <tr>
                          <td colspan="6" class="text-center text-muted py-3">Noch keine Zimmer angelegt.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12">
          <div class="card module-card" id="user-management">
            <?php $isEditingUser = $userFormData['id'] !== null; ?>
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h2 class="h5 mb-1">Benutzerverwaltung</h2>
                <p class="text-muted mb-0"><?= $isEditingUser ? 'Bestehenden Benutzer anpassen oder Passwort zurücksetzen.' : 'Neue Benutzer für das Team anlegen und Rollen vergeben.' ?></p>
              </div>
              <?php if ($isEditingUser): ?>
                <span class="badge text-bg-primary">Bearbeitung</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" class="row g-3" id="user-form">
                <input type="hidden" name="form" value="<?= $isEditingUser ? 'user_update' : 'user_create' ?>">
                <?php if ($isEditingUser): ?>
                  <input type="hidden" name="id" value="<?= (int) $userFormData['id'] ?>">
                <?php endif; ?>
                <div class="col-md-4">
                  <label for="user-name" class="form-label">Name *</label>
                  <input type="text" class="form-control" id="user-name" name="name" value="<?= htmlspecialchars((string) $userFormData['name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                  <label for="user-email" class="form-label">E-Mail *</label>
                  <input type="email" class="form-control" id="user-email" name="email" value="<?= htmlspecialchars((string) $userFormData['email']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                  <label for="user-role" class="form-label">Rolle *</label>
                  <select class="form-select" id="user-role" name="role" <?= $pdo === null ? 'disabled' : '' ?>>
                    <?php foreach ($userRoles as $role): ?>
                      <option value="<?= htmlspecialchars($role) ?>" <?= $userFormData['role'] === $role ? 'selected' : '' ?>><?= $role === 'admin' ? 'Administrator' : 'Mitarbeiter' ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label for="user-password" class="form-label">Passwort <?= $isEditingUser ? '(optional)' : '*' ?></label>
                  <input type="password" class="form-control" id="user-password" name="password" <?= $pdo === null ? 'disabled' : '' ?> <?= $isEditingUser ? '' : 'required' ?>>
                  <div class="form-text">Mindestens 8 Zeichen.</div>
                </div>
                <div class="col-md-6">
                  <label for="user-password-confirm" class="form-label">Passwort wiederholen <?= $isEditingUser ? '(optional)' : '*' ?></label>
                  <input type="password" class="form-control" id="user-password-confirm" name="password_confirm" <?= $pdo === null ? 'disabled' : '' ?> <?= $isEditingUser ? '' : 'required' ?>>
                </div>
                <div class="col-12 d-flex justify-content-end align-items-center flex-wrap gap-2">
                  <?php if ($isEditingUser): ?>
                    <a href="index.php#user-management" class="btn btn-outline-secondary">Abbrechen</a>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingUser ? 'Benutzer aktualisieren' : 'Benutzer speichern' ?></button>
                </div>
              </form>

              <?php if ($pdo === null): ?>
                <p class="text-muted mt-3 mb-0">Die Formularfelder sind deaktiviert, bis eine gültige Datenbankverbindung besteht.</p>
              <?php endif; ?>

              <?php if ($pdo !== null): ?>
                <div class="table-responsive mt-4">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Name</th>
                        <th scope="col">E-Mail</th>
                        <th scope="col">Rolle</th>
                        <th scope="col">Letzter Login</th>
                        <th scope="col" class="text-end">Aktionen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($users as $user): ?>
                        <?php
                          $lastLoginLabel = '<span class="text-muted">Noch nie</span>';
                          if (!empty($user['last_login_at'])) {
                              try {
                                  $lastLoginLabel = htmlspecialchars((new DateTime($user['last_login_at']))->format('d.m.Y H:i'));
                              } catch (Throwable $exception) {
                                  $lastLoginLabel = htmlspecialchars($user['last_login_at']);
                              }
                          }
                        ?>
                        <tr>
                          <td class="fw-semibold"><?= htmlspecialchars($user['name']) ?></td>
                          <td><?= htmlspecialchars($user['email']) ?></td>
                          <td><span class="badge <?= $user['role'] === 'admin' ? 'text-bg-dark' : 'text-bg-secondary' ?>"><?= $user['role'] === 'admin' ? 'Administrator' : 'Mitarbeiter' ?></span></td>
                          <td><?= $lastLoginLabel ?></td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                              <a class="btn btn-outline-secondary btn-sm" href="index.php?editUser=<?= (int) $user['id'] ?>#user-management">Bearbeiten</a>
                              <?php if ((int) $_SESSION['user_id'] !== (int) $user['id']): ?>
                                <form method="post" onsubmit="return confirm('Benutzer wirklich löschen?');">
                                  <input type="hidden" name="form" value="user_delete">
                                  <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm">Löschen</button>
                                </form>
                              <?php else: ?>
                                <span class="badge text-bg-light">Eigenes Konto</span>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (empty($users)): ?>
                        <tr>
                          <td colspan="5" class="text-center text-muted py-3">Noch keine Benutzer vorhanden.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
  </body>
</html>
