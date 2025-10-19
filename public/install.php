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

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            $errors[] = 'Bitte füllen Sie alle Pflichtfelder aus.';
        }

        if ($dbName !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            $errors[] = 'Der Datenbankname darf nur Buchstaben, Zahlen und Unterstriche enthalten.';
        }

        if ($dbPort !== '' && !preg_match('/^[0-9]+$/', $dbPort)) {
            $errors[] = 'Der Port darf nur Ziffern enthalten.';
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

                $seedCategory = $pdo->query('SELECT COUNT(*) AS total FROM room_categories')->fetchColumn();
                if ((int) $seedCategory === 0) {
                    $stmt = $pdo->prepare('INSERT INTO room_categories (name, description, capacity, status) VALUES (?, ?, ?, ?)');
                    $stmt->execute(['Standardzimmer', 'Komfortable Zimmer mit Queen-Size-Bett', 2, 'aktiv']);
                    $stmt->execute(['Deluxe Suite', 'Großzügige Suite mit Wohnbereich', 4, 'aktiv']);
                }

                $seedRooms = $pdo->query('SELECT COUNT(*) AS total FROM rooms')->fetchColumn();
                if ((int) $seedRooms === 0) {
                    $stmt = $pdo->prepare('INSERT INTO rooms (room_number, category_id, status, floor) VALUES (?, ?, ?, ?)');
                    $stmt->execute(['101', 1, 'frei', '1']);
                    $stmt->execute(['102', 1, 'belegt', '1']);
                    $stmt->execute(['201', 2, 'frei', '2']);
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
                      <h2 class="h5 mb-1">Datenbank konfigurieren</h2>
                      <p class="text-muted mb-0">Bitte geben Sie Ihre MySQL-Zugangsdaten ein.</p>
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
                    <div class="col-12">
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
                  <p class="mb-0">Die Datenbank <strong><?= htmlspecialchars($successData['database']) ?></strong> auf <strong><?= htmlspecialchars($successData['host']) ?><?= $successData['port'] ? ':' . htmlspecialchars($successData['port']) : '' ?></strong> wurde konfiguriert. Sie können sich jetzt im Dashboard anmelden.</p>
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
