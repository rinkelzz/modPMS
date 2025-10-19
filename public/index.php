<?php

use ModPMS\Calendar;
use ModPMS\RoomCategoryManager;
use ModPMS\RoomManager;
use ModPMS\SystemUpdater;

require_once __DIR__ . '/../src/RoomCategoryManager.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/RoomManager.php';
require_once __DIR__ . '/../src/SystemUpdater.php';

session_start();

$config = require __DIR__ . '/../config/app.php';
$categoryManager = new RoomCategoryManager(__DIR__ . '/../storage/room_categories.json');
$categories = $categoryManager->all();
$roomManager = new RoomManager(__DIR__ . '/../storage/rooms.json');
$rooms = $roomManager->all();

$calendar = new Calendar();
$days = $calendar->daysOfMonth();

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form']) && $_POST['form'] === 'category') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $capacity = (int) ($_POST['capacity'] ?? 0);
    $status = trim($_POST['status'] ?? 'aktiv');

    if ($name === '' || $capacity <= 0) {
        $alert = [
            'type' => 'danger',
            'message' => 'Bitte geben Sie einen Namen und eine gültige Kapazität an.',
        ];
    } else {
        $categoryManager->add([
            'name' => $name,
            'description' => $description,
            'capacity' => $capacity,
            'status' => $status,
        ]);

        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => sprintf('Kategorie "%s" erfolgreich angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
        ];

        header('Location: index.php');
        exit;
    }
}

if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

$updater = new SystemUpdater(dirname(__DIR__), $config['repository']['branch']);

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
      <div class="container">
        <a class="navbar-brand" href="#">🏨 <?= htmlspecialchars($config['name']) ?></a>
        <div class="d-flex align-items-center gap-3">
          <span class="badge rounded-pill text-bg-primary">Version <?= htmlspecialchars($config['version']) ?></span>
        </div>
      </div>
    </nav>

    <main class="container py-5">
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
        <div class="col-lg-6">
          <div class="card module-card h-100">
            <div class="card-header bg-transparent border-0">
              <h2 class="h5 mb-1">Zimmerkategorien verwalten</h2>
              <p class="text-muted mb-0">Neue Kategorien für die Belegung anlegen.</p>
            </div>
            <div class="card-body">
              <form method="post" class="row g-3">
                <input type="hidden" name="form" value="category">
                <div class="col-12">
                  <label for="name" class="form-label">Bezeichnung *</label>
                  <input type="text" class="form-control" id="name" name="name" required>
                </div>
                <div class="col-12">
                  <label for="description" class="form-label">Beschreibung</label>
                  <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                  <label for="capacity" class="form-label">Kapazität *</label>
                  <input type="number" min="1" class="form-control" id="capacity" name="capacity" required>
                </div>
                <div class="col-md-6">
                  <label for="status" class="form-label">Status</label>
                  <select class="form-select" id="status" name="status">
                    <option value="aktiv">Aktiv</option>
                    <option value="inaktiv">Inaktiv</option>
                  </select>
                </div>
                <div class="col-12 text-end">
                  <button type="submit" class="btn btn-primary">Kategorie speichern</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card module-card h-100">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <div>
                <h2 class="h5 mb-1">Systemupdates</h2>
                <p class="text-muted mb-0">Aktuelle Version prüfen und GitHub-Updates abrufen.</p>
              </div>
              <span class="badge text-bg-secondary">Update</span>
            </div>
            <div class="card-body">
              <p class="mb-2">Repository: <code><?= htmlspecialchars($config['repository']['url']) ?></code></p>
              <p>Branch: <code><?= htmlspecialchars($config['repository']['branch']) ?></code></p>
              <form action="update.php" method="post" class="d-flex flex-column gap-3">
                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['update_token'] = bin2hex(random_bytes(16))) ?>">
                <button type="submit" class="btn btn-outline-primary">Update jetzt starten</button>
              </form>
              <div class="mt-4">
                <h3 class="h6 text-muted">Letzte Update-Ausgabe</h3>
                <?php if (isset($_SESSION['update_output'])): ?>
                  <div class="update-output">
                    <?php foreach ($_SESSION['update_output'] as $line): ?>
                      <div><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                  </div>
                  <?php unset($_SESSION['update_output']); ?>
                <?php else: ?>
                  <p class="text-muted mb-0">Noch keine Updates ausgeführt.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

    <footer class="py-4 bg-white border-top mt-5">
      <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center text-muted">
        <small>&copy; <?= date('Y') ?> <?= htmlspecialchars($config['name']) ?>. Alle Rechte vorbehalten.</small>
        <small>Basis-Version <?= htmlspecialchars($config['version']) ?> · Modulstatus: Aktiv</small>
      </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
  </body>
</html>
