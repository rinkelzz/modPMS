<?php

session_start();

$config = require __DIR__ . '/../config/app.php';
$databaseConfigPath = __DIR__ . '/../config/database.php';
$existingDatabaseConfig = is_readable($databaseConfigPath) ? include $databaseConfigPath : null;
if ($existingDatabaseConfig !== null && !is_array($existingDatabaseConfig)) {
    $existingDatabaseConfig = null;
}
$configDirectory = dirname($databaseConfigPath);

$step = isset($_GET['step']) ? max(1, min(3, (int) $_GET['step'])) : 1;
$errors = [];
$successData = $_SESSION['install_success'] ?? null;
if ($successData) {
    unset($_SESSION['install_success']);
}

$requirements = [
    'phpVersion' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'pdoExtension' => extension_loaded('pdo_mysql'),
    'configWritable' => $existingDatabaseConfig ? is_writable($databaseConfigPath) : is_writable($configDirectory),
    'configExists' => (bool) $existingDatabaseConfig,
];

$configWritableLabel = $requirements['configExists']
    ? 'Schreibrechte für <code>config/database.php</code>'
    : 'Schreibrechte für <code>config/</code>';
$configWritableDescription = $requirements['configExists']
    ? 'Erforderlich, um die bestehende Konfiguration zu aktualisieren.'
    : 'Erforderlich, um <code>database.php</code> anzulegen.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentStep = (int) ($_POST['step'] ?? 1);

    if ($currentStep === 1 && $requirements['phpVersion'] && $requirements['pdoExtension'] && $requirements['configWritable']) {
        header('Location: install.php?step=2');
        exit;
    }

    if ($currentStep === 2) {
        $dbHost = trim($_POST['db_host'] ?? '');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPassword = $_POST['db_password'] ?? '';
        $createDatabase = isset($_POST['create_database']);
        $adminName = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            $errors[] = 'Bitte füllen Sie alle Pflichtfelder aus.';
        }

        if ($dbName !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            $errors[] = 'Der Datenbankname darf nur Buchstaben, Zahlen und Unterstriche enthalten.';
        }

        if ($dbPort !== '' && !preg_match('/^[0-9]+$/', $dbPort)) {
            $errors[] = 'Der Port darf nur Ziffern enthalten.';
        }

        if ($adminName === '' || $adminEmail === '' || $adminPassword === '') {
            $errors[] = 'Bitte hinterlegen Sie Name, E-Mail-Adresse und Passwort für den Administrationsbenutzer.';
        }

        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse für den Administrationsbenutzer ein.';
        }

        if ($adminPassword !== '' && strlen($adminPassword) < 8) {
            $errors[] = 'Das Administrationspasswort muss mindestens 8 Zeichen lang sein.';
        }

        if ($adminPassword !== $adminPasswordConfirm) {
            $errors[] = 'Die angegebenen Administrationspasswörter stimmen nicht überein.';
        }

        if (!$errors) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbHost, $dbPort !== '' ? $dbPort : '3306');
                $pdo = new PDO($dsn, $dbUser, $dbPassword, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                if ($createDatabase) {
                    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', str_replace('`', '', $dbName)));
                }

                $pdo->exec(sprintf('USE `%s`', str_replace('`', '', $dbName)));

                $pdo->exec('CREATE TABLE IF NOT EXISTS room_categories (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(150) NOT NULL,
                    description TEXT NULL,
                    capacity INT UNSIGNED NOT NULL DEFAULT 1,
                    status ENUM("aktiv", "inaktiv") NOT NULL DEFAULT "aktiv",
                    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS rooms (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    room_number VARCHAR(20) NOT NULL,
                    category_id INT UNSIGNED NULL,
                    status ENUM("frei", "belegt", "wartung") NOT NULL DEFAULT "frei",
                    floor VARCHAR(50) NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_rooms_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS users (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(150) NOT NULL,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    role VARCHAR(50) NOT NULL DEFAULT "admin",
                    password_hash VARCHAR(255) NOT NULL,
                    last_login_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS companies (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(190) NOT NULL,
                    address_street VARCHAR(190) NULL,
                    address_postal_code VARCHAR(20) NULL,
                    address_city VARCHAR(150) NULL,
                    address_country VARCHAR(150) NULL,
                    email VARCHAR(190) NULL,
                    phone VARCHAR(80) NULL,
                    tax_id VARCHAR(120) NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_companies_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS guests (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    salutation VARCHAR(20) NULL,
                    first_name VARCHAR(120) NOT NULL,
                    last_name VARCHAR(150) NOT NULL,
                    date_of_birth DATE NULL,
                    nationality VARCHAR(100) NULL,
                    document_type VARCHAR(100) NULL,
                    document_number VARCHAR(100) NULL,
                    address_street VARCHAR(190) NULL,
                    address_postal_code VARCHAR(20) NULL,
                    address_city VARCHAR(150) NULL,
                    address_country VARCHAR(150) NULL,
                    email VARCHAR(190) NULL,
                    phone VARCHAR(80) NULL,
                    arrival_date DATE NULL,
                    departure_date DATE NULL,
                    purpose_of_stay ENUM("privat", "geschäftlich") NOT NULL DEFAULT "privat",
                    notes TEXT NULL,
                    company_id INT UNSIGNED NULL,
                    room_id INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_guests_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
                    CONSTRAINT fk_guests_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
                    INDEX idx_guests_name (last_name, first_name),
                    INDEX idx_guests_arrival (arrival_date),
                    INDEX idx_guests_company (company_id),
                    INDEX idx_guests_room (room_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS reservations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    guest_id INT UNSIGNED NOT NULL,
                    room_id INT UNSIGNED NULL,
                    category_id INT UNSIGNED NOT NULL,
                    room_quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    company_id INT UNSIGNED NULL,
                    arrival_date DATE NOT NULL,
                    departure_date DATE NOT NULL,
                    status ENUM("geplant", "eingecheckt", "abgereist", "bezahlt", "noshow", "storniert") NOT NULL DEFAULT "geplant",
                    notes TEXT NULL,
                    created_by INT UNSIGNED NULL,
                    updated_by INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_reservations_guest FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reservations_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservations_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE RESTRICT,
                    CONSTRAINT fk_reservations_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    CONSTRAINT fk_reservations_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_reservations_arrival (arrival_date),
                    INDEX idx_reservations_guest (guest_id),
                    INDEX idx_reservations_room (room_id),
                    INDEX idx_reservations_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS reservation_items (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reservation_id INT UNSIGNED NOT NULL,
                    category_id INT UNSIGNED NULL,
                    room_id INT UNSIGNED NULL,
                    room_quantity INT UNSIGNED NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL,
                    CONSTRAINT fk_install_reservation_items_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
                    CONSTRAINT fk_install_reservation_items_category FOREIGN KEY (category_id) REFERENCES room_categories(id) ON DELETE SET NULL,
                    CONSTRAINT fk_install_reservation_items_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
                    INDEX idx_install_reservation_items_reservation (reservation_id),
                    INDEX idx_install_reservation_items_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(191) NOT NULL UNIQUE,
                    setting_value TEXT NULL,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                $statusColorDefaults = [
                    'geplant' => '#2563EB',
                    'eingecheckt' => '#16A34A',
                    'abgereist' => '#6B7280',
                    'bezahlt' => '#0EA5E9',
                    'noshow' => '#F59E0B',
                    'storniert' => '#DC2626',
                ];

                $settingsStmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value');
                foreach ($statusColorDefaults as $statusKey => $colorValue) {
                    $settingsStmt->execute([
                        'key' => 'reservation_status_color_' . $statusKey,
                        'value' => $colorValue,
                    ]);
                }

                $seedCategory = $pdo->query('SELECT COUNT(*) AS total FROM room_categories')->fetchColumn();
                if ((int) $seedCategory === 0) {
                    $stmt = $pdo->prepare('INSERT INTO room_categories (name, description, capacity, status, sort_order) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute(['Standardzimmer', 'Komfortable Zimmer mit Queen-Size-Bett', 2, 'aktiv', 1]);
                    $stmt->execute(['Deluxe Suite', 'Großzügige Suite mit Wohnbereich', 4, 'aktiv', 2]);
                }

                $seedRooms = $pdo->query('SELECT COUNT(*) AS total FROM rooms')->fetchColumn();
                if ((int) $seedRooms === 0) {
                    $stmt = $pdo->prepare('INSERT INTO rooms (room_number, category_id, status, floor) VALUES (?, ?, ?, ?)');
                    $stmt->execute(['101', 1, 'frei', '1']);
                    $stmt->execute(['102', 1, 'belegt', '1']);
                    $stmt->execute(['201', 2, 'frei', '2']);
                }

                $sampleRoomId = null;
                $sampleCategoryId = null;
                $existingOccupiedRoom = $pdo->query('SELECT id, category_id FROM rooms WHERE status = "belegt" ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                if (is_array($existingOccupiedRoom) && isset($existingOccupiedRoom['id'])) {
                    $sampleRoomId = (int) $existingOccupiedRoom['id'];
                    if (isset($existingOccupiedRoom['category_id'])) {
                        $sampleCategoryId = (int) $existingOccupiedRoom['category_id'];
                    }
                }

                if ($sampleCategoryId === null) {
                    $existingCategoryId = $pdo->query('SELECT id FROM room_categories ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
                    if ($existingCategoryId !== false) {
                        $sampleCategoryId = (int) $existingCategoryId;
                    }
                }

                $sampleCompanyId = null;
                $companyExists = $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
                if ((int) $companyExists === 0) {
                    $stmt = $pdo->prepare('INSERT INTO companies (name, address_street, address_postal_code, address_city, address_country, email, phone, tax_id, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                    $stmt->execute([
                        'Musterfirma GmbH',
                        'Wirtschaftsweg 5',
                        '80331',
                        'München',
                        'Deutschland',
                        'kontakt@musterfirma.example',
                        '+49 89 987654',
                        'DE123456789',
                        'Beispielfirma für Geschäftsreisende.',
                    ]);
                    $sampleCompanyId = (int) $pdo->lastInsertId();
                } else {
                    $existingCompanyId = $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetchColumn();
                    if ($existingCompanyId !== false) {
                $sampleCompanyId = (int) $existingCompanyId;
            }
        }

        $sampleAdminId = null;
        $adminExists = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ((int) $adminExists === 0) {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, role, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                $adminName,
                $adminEmail,
                'admin',
                password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);
            $sampleAdminId = (int) $pdo->lastInsertId();
        } else {
            $existingAdminId = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($existingAdminId !== false) {
                $sampleAdminId = (int) $existingAdminId;
            }
        }

        $sampleGuestId = null;
        $guestExists = $pdo->query('SELECT COUNT(*) FROM guests')->fetchColumn();
        if ((int) $guestExists === 0) {
            $stmt = $pdo->prepare('INSERT INTO guests (salutation, first_name, last_name, date_of_birth, nationality, document_type, document_number, address_street, address_postal_code, address_city, address_country, email, phone, arrival_date, departure_date, purpose_of_stay, notes, company_id, room_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                'Herr',
                'Max',
                'Mustermann',
                '1985-04-12',
                'Deutschland',
                'Personalausweis',
                'D1234567',
                'Musterstraße 1',
                '10115',
                'Berlin',
                'Deutschland',
                'max.mustermann@example.com',
                '+49 30 1234567',
                null,
                null,
                'geschäftlich',
                'Beispielgast für den Einstieg.',
                $sampleCompanyId !== null ? $sampleCompanyId : null,
                $sampleRoomId !== null ? $sampleRoomId : null,
            ]);
            $sampleGuestId = (int) $pdo->lastInsertId();
        } else {
            $existingGuestId = $pdo->query('SELECT id FROM guests ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($existingGuestId !== false) {
                $sampleGuestId = (int) $existingGuestId;
            }
        }

        $reservationExists = $pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
        if (
            (int) $reservationExists === 0
            && $sampleGuestId !== null
            && $sampleCategoryId !== null
        ) {
            $arrival = (new DateTimeImmutable('today'))->format('Y-m-d');
            $departure = (new DateTimeImmutable('+3 days'))->format('Y-m-d');

            $stmt = $pdo->prepare('INSERT INTO reservations (guest_id, room_id, category_id, room_quantity, company_id, arrival_date, departure_date, status, notes, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                $sampleGuestId,
                $sampleRoomId,
                $sampleCategoryId,
                1,
                $sampleCompanyId,
                $arrival,
                $departure,
                'geplant',
                'Beispielreservierung für den Kalender.',
                $sampleAdminId,
                $sampleAdminId,
            ]);

            $reservationId = (int) $pdo->lastInsertId();
            if ($reservationId > 0) {
                $itemInsert = $pdo->prepare('INSERT INTO reservation_items (reservation_id, category_id, room_id, room_quantity, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
                $itemInsert->execute([
                    $reservationId,
                    $sampleCategoryId,
                    $sampleRoomId,
                    1,
                ]);
            }

            $guestUpdate = $pdo->prepare('UPDATE guests SET arrival_date = ?, departure_date = ?, room_id = ?, updated_at = NOW() WHERE id = ?');
            $guestUpdate->execute([
                $arrival,
                $departure,
                $sampleRoomId,
                $sampleGuestId,
            ]);

            $overbookingArrival = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
            $overbookingDeparture = (new DateTimeImmutable('+8 days'))->format('Y-m-d');

            $overbookingInsert = $pdo->prepare('INSERT INTO reservations (guest_id, room_id, category_id, room_quantity, company_id, arrival_date, departure_date, status, notes, created_by, updated_by, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $overbookingInsert->execute([
                $sampleGuestId,
                $sampleCategoryId,
                2,
                $sampleCompanyId,
                $overbookingArrival,
                $overbookingDeparture,
                'geplant',
                'Reservierung ohne Zimmerzuweisung für Demonstrationszwecke.',
                $sampleAdminId,
                $sampleAdminId,
            ]);

            $overbookingReservationId = (int) $pdo->lastInsertId();
            if ($overbookingReservationId > 0) {
                $itemInsert = $pdo->prepare('INSERT INTO reservation_items (reservation_id, category_id, room_id, room_quantity, created_at, updated_at) VALUES (?, ?, NULL, ?, NOW(), NOW())');
                $itemInsert->execute([
                    $overbookingReservationId,
                    $sampleCategoryId,
                    2,
                ]);
            }
        }

                $databaseConfig = [
                    'driver' => 'mysql',
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'database' => $dbName,
                    'username' => $dbUser,
                    'password' => $dbPassword,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ];

                $configContent = "<?php\n\nreturn " . var_export($databaseConfig, true) . ";\n";

                if (false === file_put_contents($databaseConfigPath, $configContent)) {
                    throw new RuntimeException('Die Konfigurationsdatei konnte nicht geschrieben werden.');
                }

                $_SESSION['install_success'] = [
                    'database' => $dbName,
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'admin_email' => $adminEmail,
                ];

                header('Location: install.php?step=3');
                exit;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        $step = 2;
    }
}

if ($step === 3 && !$successData) {
    $step = 1;
}

?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation · <?= htmlspecialchars($config['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
      body {
        min-height: 100vh;
        background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
      }
      .install-card {
        border: none;
        border-radius: 1rem;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.15);
      }
      .step-badge {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
      }
      .requirement-item {
        border-radius: 0.75rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        padding: 1rem;
        background: #fff;
      }
    </style>
  </head>
  <body class="d-flex align-items-center py-5">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">
          <div class="card install-card">
            <div class="card-body p-5">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <h1 class="h3 mb-1">Installation · <?= htmlspecialchars($config['name']) ?></h1>
                  <p class="text-muted mb-0">Grafischer Assistent zur Einrichtung der Datenbank.</p>
                </div>
                <span class="badge text-bg-primary">Version <?= htmlspecialchars($config['version']) ?></span>
              </div>

              <?php if ($errors): ?>
                <div class="alert alert-danger">
                  <h2 class="h6 mb-2">Es sind Fehler aufgetreten:</h2>
                  <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                      <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <?php if ($step === 1): ?>
                <form method="post" class="d-flex flex-column gap-4">
                  <input type="hidden" name="step" value="1">
                  <div class="d-flex align-items-center gap-3">
                    <div class="step-badge bg-primary text-white">1</div>
                    <div>
                      <h2 class="h5 mb-1">Systemprüfung</h2>
                      <p class="text-muted mb-0">Wir prüfen, ob Ihr Server die Mindestanforderungen erfüllt.</p>
                    </div>
                  </div>

                  <div class="d-grid gap-3">
                    <div class="requirement-item d-flex justify-content-between align-items-center">
                      <div>
                        <strong>PHP-Version ≥ 8.1</strong>
                        <p class="text-muted mb-0">Aktuelle Version: <?= PHP_VERSION ?></p>
                      </div>
                      <span class="badge <?= $requirements['phpVersion'] ? 'text-bg-success' : 'text-bg-danger' ?>">
                        <?= $requirements['phpVersion'] ? 'OK' : 'Nicht erfüllt' ?>
                      </span>
                    </div>
                    <div class="requirement-item d-flex justify-content-between align-items-center">
                      <div>
                        <strong>PDO MySQL Erweiterung</strong>
                        <p class="text-muted mb-0">Benötigt für die Datenbankverbindung.</p>
                      </div>
                      <span class="badge <?= $requirements['pdoExtension'] ? 'text-bg-success' : 'text-bg-danger' ?>">
                        <?= $requirements['pdoExtension'] ? 'Aktiv' : 'Fehlt' ?>
                      </span>
                    </div>
                    <div class="requirement-item d-flex justify-content-between align-items-center">
                      <div>
                        <strong><?= $configWritableLabel ?></strong>
                        <p class="text-muted mb-0"><?= $configWritableDescription ?></p>
                      </div>
                      <span class="badge <?= $requirements['configWritable'] ? 'text-bg-success' : 'text-bg-danger' ?>">
                        <?= $requirements['configWritable'] ? 'Beschreibbar' : 'Keine Rechte' ?>
                      </span>
                    </div>
                  </div>

                  <?php if ($requirements['configExists']): ?>
                    <div class="alert alert-warning mb-0">
                      <strong>Hinweis:</strong> Eine bestehende <code>config/database.php</code> wurde gefunden. Bei der erneuten Installation wird diese Datei überschrieben.
                    </div>
                  <?php endif; ?>

                  <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" <?= ($requirements['phpVersion'] && $requirements['pdoExtension'] && $requirements['configWritable']) ? '' : 'disabled' ?>>Weiter zu Schritt 2</button>
                  </div>
                </form>
              <?php elseif ($step === 2): ?>
                <?php
                  $prefill = [
                      'host' => $_POST['db_host'] ?? ($existingDatabaseConfig['host'] ?? 'localhost'),
                      'port' => $_POST['db_port'] ?? ($existingDatabaseConfig['port'] ?? '3306'),
                      'name' => $_POST['db_name'] ?? ($existingDatabaseConfig['database'] ?? $config['name']),
                      'user' => $_POST['db_user'] ?? ($existingDatabaseConfig['username'] ?? ''),
                  ];
                ?>
                <form method="post" class="d-flex flex-column gap-4">
                  <input type="hidden" name="step" value="2">

                  <div class="d-flex align-items-center gap-3">
                    <div class="step-badge bg-primary text-white">2</div>
                    <div>
                      <h2 class="h5 mb-1">Datenbank &amp; Admin konfigurieren</h2>
                      <p class="text-muted mb-0">Hinterlegen Sie Zugangsdaten für MySQL und Ihren ersten Administrationsbenutzer.</p>
                    </div>
                  </div>

                  <?php if ($requirements['configExists']): ?>
                    <div class="alert alert-info mb-0">
                      Die bestehenden Verbindungsdaten wurden vorausgefüllt. Änderungen werden in <code>config/database.php</code> gespeichert.
                    </div>
                  <?php endif; ?>

                  <div class="row g-3">
                    <div class="col-md-6">
                      <label for="db_host" class="form-label">Host *</label>
                      <input type="text" class="form-control" id="db_host" name="db_host" value="<?= htmlspecialchars($prefill['host']) ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label for="db_port" class="form-label">Port</label>
                      <input type="text" class="form-control" id="db_port" name="db_port" value="<?= htmlspecialchars($prefill['port']) ?>">
                    </div>
                    <div class="col-md-6">
                      <label for="db_name" class="form-label">Datenbankname *</label>
                      <input type="text" class="form-control" id="db_name" name="db_name" value="<?= htmlspecialchars($prefill['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label for="db_user" class="form-label">Benutzer *</label>
                      <input type="text" class="form-control" id="db_user" name="db_user" value="<?= htmlspecialchars($prefill['user']) ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label for="db_password" class="form-label">Passwort</label>
                      <input type="password" class="form-control" id="db_password" name="db_password" value="<?= htmlspecialchars($_POST['db_password'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="create_database" name="create_database" <?= isset($_POST['create_database']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="create_database">
                          Datenbank automatisch anlegen (falls nicht vorhanden)
                        </label>
                      </div>
                    </div>
                  </div>

                  <hr class="my-4">

                  <h3 class="h6 text-uppercase text-muted">Administrationszugang</h3>
                  <p class="text-muted">Mit diesen Zugangsdaten melden Sie sich später im Dashboard an.</p>

                  <div class="row g-3">
                    <div class="col-md-6">
                      <label for="admin_name" class="form-label">Name *</label>
                      <input type="text" class="form-control" id="admin_name" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label for="admin_email" class="form-label">E-Mail *</label>
                      <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                      <label for="admin_password" class="form-label">Passwort *</label>
                      <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                      <div class="form-text">Mindestens 8 Zeichen.</div>
                    </div>
                    <div class="col-md-6">
                      <label for="admin_password_confirm" class="form-label">Passwort wiederholen *</label>
                      <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm" required>
                    </div>
                  </div>

                  <div class="d-flex justify-content-between">
                    <a class="btn btn-link" href="install.php?step=1">Zurück</a>
                    <button type="submit" class="btn btn-primary">Installation starten</button>
                  </div>
                </form>
              <?php elseif ($step === 3 && $successData): ?>
                <div class="d-flex align-items-center gap-3 mb-4">
                  <div class="step-badge bg-success text-white">3</div>
                  <div>
                    <h2 class="h5 mb-1">Installation abgeschlossen</h2>
                    <p class="text-muted mb-0">Ihre Datenbank wurde erfolgreich eingerichtet.</p>
                  </div>
                </div>

                <div class="alert alert-success">
                  <h2 class="h6 mb-2">Verbindung hergestellt</h2>
                  <p class="mb-0">Die Datenbank <strong><?= htmlspecialchars($successData['database']) ?></strong> auf <strong><?= htmlspecialchars($successData['host']) ?><?= $successData['port'] ? ':' . htmlspecialchars($successData['port']) : '' ?></strong> wurde konfiguriert. Sie können sich jetzt mit <strong><?= htmlspecialchars($successData['admin_email']) ?></strong> am Dashboard anmelden.</p>
                </div>

                <div class="card border-0 shadow-sm">
                  <div class="card-body">
                    <h3 class="h6 text-uppercase text-muted mb-3">Nächste Schritte</h3>
                    <ul class="mb-4">
                      <li>Überprüfen Sie die generierte Datei <code>config/database.php</code> und passen Sie sie bei Bedarf an.</li>
                      <li>Entfernen oder sichern Sie <code>public/install.php</code>, um unbefugte Zugriffe zu verhindern.</li>
                      <li>Melden Sie sich am <a href="index.php">Dashboard</a> an und beginnen Sie mit der Konfiguration.</li>
                    </ul>
                    <a href="index.php" class="btn btn-success">Zum Dashboard</a>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>
