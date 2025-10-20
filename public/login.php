<?php

use ModPMS\Database;
use ModPMS\UserManager;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/UserManager.php';

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$config = require __DIR__ . '/../config/app.php';

$alert = null;
if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

$email = trim($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$loginError = null;
$dbError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($email === '' || $password === '') {
        $loginError = 'Bitte geben Sie Ihre E-Mail-Adresse und Ihr Passwort ein.';
    } else {
        try {
            $pdo = Database::getConnection();
            $userManager = new UserManager($pdo);
            $user = $userManager->findByEmail($email);

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];

                try {
                    $userManager->recordLogin((int) $user['id']);
                } catch (Throwable $exception) {
                    // Recording the login is optional; failures should not block access.
                }

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Willkommen zurück, %s!', htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php');
                exit;
            }

            $loginError = 'Die Zugangsdaten sind ungültig.';
        } catch (Throwable $exception) {
            $dbError = $exception->getMessage();
        }
    }
}

?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmeldung · <?= htmlspecialchars($config['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
      body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
      }
      .login-card {
        border-radius: 1rem;
        border: none;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.15);
      }
    </style>
  </head>
  <body>
    <div class="container py-5">
      <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
          <div class="card login-card">
            <div class="card-body p-5">
              <div class="text-center mb-4">
                <h1 class="h3 mb-1">Willkommen bei <?= htmlspecialchars($config['name']) ?></h1>
                <p class="text-muted mb-0">Bitte melden Sie sich mit Ihren Zugangsdaten an.</p>
              </div>

              <?php if ($alert): ?>
                <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>" role="alert">
                  <?= $alert['message'] ?>
                </div>
              <?php endif; ?>

              <?php if ($dbError): ?>
                <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($dbError) ?><br>
                  <small>Bitte stellen Sie sicher, dass die <code>config/database.php</code> existiert und korrekt konfiguriert ist.</small>
                </div>
              <?php endif; ?>

              <?php if ($loginError && !$dbError): ?>
                <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($loginError) ?>
                </div>
              <?php endif; ?>

              <form method="post" class="d-flex flex-column gap-3">
                <div>
                  <label for="email" class="form-label">E-Mail-Adresse</label>
                  <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus>
                </div>
                <div>
                  <label for="password" class="form-label">Passwort</label>
                  <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Anmelden</button>
              </form>

              <p class="text-center text-muted mt-4 mb-0">
                Noch keine Datenbank eingerichtet? Führen Sie zuerst den <a href="install.php">Installer</a> aus.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
  </body>
</html>
