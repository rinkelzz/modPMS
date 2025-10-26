<?php

declare(strict_types=1);

use ModPMS\Database;
use ModPMS\MeldescheinManager;
use ModPMS\MeldescheinPdfRenderer;
use ModPMS\SettingManager;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SettingManager.php';
require_once __DIR__ . '/../src/MeldescheinManager.php';
require_once __DIR__ . '/../src/MeldescheinPdfRenderer.php';

session_start();

$config = require __DIR__ . '/../config/app.php';
$appName = isset($config['name']) ? (string) $config['name'] : 'modPMS';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
} else {
    $token = trim((string) ($_GET['token'] ?? ''));
}

$pdo = null;
$settingsManager = null;
$meldescheinManager = null;
$dbError = null;

try {
    $pdo = Database::getConnection();
    $settingsManager = new SettingManager($pdo);
    $meldescheinManager = new MeldescheinManager($pdo, $settingsManager);
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$form = null;
$errorMessage = null;
$successMessage = null;
$signatureExpired = false;
$signatureAllowed = false;
$signatureExistingPath = '';

if ($dbError === null && $meldescheinManager instanceof MeldescheinManager) {
    if ($token === '') {
        $errorMessage = 'Der Signaturlink ist ungültig. Bitte kontaktieren Sie die Unterkunft für einen neuen Link.';
    } else {
        $form = $meldescheinManager->findBySignatureToken($token, true);
        if ($form === null) {
            $errorMessage = 'Der Signaturlink ist ungültig oder wurde bereits verwendet. Bitte kontaktieren Sie die Unterkunft.';
        } else {
            $storedToken = isset($form['signature_token']) && $form['signature_token'] !== null
                ? (string) $form['signature_token']
                : '';

            if ($storedToken === '' || !hash_equals($storedToken, $token)) {
                $errorMessage = 'Der Signaturlink ist ungültig oder wurde bereits verwendet. Bitte kontaktieren Sie die Unterkunft.';
                $form = null;
            } else {
                $signatureExistingPath = isset($form['guest_signature_path']) && $form['guest_signature_path'] !== null
                    ? (string) $form['guest_signature_path']
                    : '';

                $expiresRaw = isset($form['signature_token_expires_at']) && $form['signature_token_expires_at'] !== null
                    ? (string) $form['signature_token_expires_at']
                    : '';

                if ($expiresRaw !== '') {
                    try {
                        $expiresAt = new DateTimeImmutable($expiresRaw);
                        if ($expiresAt < new DateTimeImmutable('now')) {
                            $signatureExpired = true;
                        }
                    } catch (Throwable $exception) {
                        $signatureExpired = true;
                    }
                }

                $signatureAllowed = !$signatureExpired && $signatureExistingPath === '';

                if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('signature_data', $_POST)) {
                    if (!$signatureAllowed) {
                        if ($signatureExpired) {
                            $errorMessage = 'Der Signaturlink ist abgelaufen. Bitte fordern Sie bei der Unterkunft einen neuen Link an.';
                        } else {
                            $errorMessage = 'Für diesen Meldeschein wurde bereits eine digitale Unterschrift gespeichert.';
                        }
                    } else {
                        $signaturePayload = trim((string) ($_POST['signature_data'] ?? ''));
                        if ($signaturePayload === '') {
                            $errorMessage = 'Bitte zeichnen Sie Ihre Unterschrift im Feld.';
                        } else {
                            try {
                                $renderer = new MeldescheinPdfRenderer();
                                $form = $meldescheinManager->saveGuestSignature(
                                    (int) $form['id'],
                                    $signaturePayload,
                                    static function (array $payload) use ($renderer): string {
                                        return $renderer->render($payload);
                                    }
                                );
                                $successMessage = 'Vielen Dank! Ihre digitale Unterschrift wurde gespeichert.';
                                $signatureAllowed = false;
                                $signatureExistingPath = isset($form['guest_signature_path']) && $form['guest_signature_path'] !== null
                                    ? (string) $form['guest_signature_path']
                                    : '';
                            } catch (Throwable $exception) {
                                $errorMessage = $exception->getMessage();
                            }
                        }
                    }
                }

                if ($signatureExpired && $successMessage === null && $errorMessage === null && !$signatureAllowed) {
                    $errorMessage = 'Der Signaturlink ist abgelaufen. Bitte wenden Sie sich an die Unterkunft.';
                }
            }
        }
    }
} elseif ($dbError !== null) {
    $errorMessage = $dbError;
}

if ($form !== null && isset($form['details']) && !is_array($form['details'])) {
    $form['details'] = [];
}

$formatDate = static function (?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('d.m.Y');
    } catch (Throwable $exception) {
        return null;
    }
};

$formNumber = $form['form_number'] ?? '';
$guestName = $form['guest_name'] ?? '';
$companyName = $form['company_name'] ?? '';
$arrivalLabel = $formatDate($form['arrival_date'] ?? null);
$departureLabel = $formatDate($form['departure_date'] ?? null);
if ($arrivalLabel === null && isset($form['arrival_date']) && $form['arrival_date'] !== '') {
    $arrivalLabel = (string) $form['arrival_date'];
}
if ($departureLabel === null && isset($form['departure_date']) && $form['departure_date'] !== '') {
    $departureLabel = (string) $form['departure_date'];
}
$purpose = isset($form['purpose_of_stay']) && (string) $form['purpose_of_stay'] === 'geschäftlich' ? 'Geschäftlich' : 'Privat';
$roomLabel = isset($form['room_label']) && $form['room_label'] !== '' ? (string) $form['room_label'] : '—';

$details = isset($form['details']) && is_array($form['details']) ? $form['details'] : [];
$stayDetails = isset($details['stay']) && is_array($details['stay']) ? $details['stay'] : [];
$nights = isset($stayDetails['nights']) ? (int) $stayDetails['nights'] : null;
$reservationNumber = isset($stayDetails['reservation_number']) ? (string) $stayDetails['reservation_number'] : '';
$guestDetails = isset($details['guest']) && is_array($details['guest']) ? $details['guest'] : [];
$guestAddressLines = isset($guestDetails['address_lines']) && is_array($guestDetails['address_lines'])
    ? array_map(static fn ($line): string => (string) $line, $guestDetails['address_lines'])
    : [];

$expiresLabel = null;
if ($form !== null && isset($form['signature_token_expires_at']) && $form['signature_token_expires_at'] !== null) {
    try {
        $expiresLabel = (new DateTimeImmutable((string) $form['signature_token_expires_at']))->format('d.m.Y H:i');
    } catch (Throwable $exception) {
        $expiresLabel = null;
    }
}

?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName) ?> · Digitale Meldeschein-Unterschrift</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
      body {
        background: #f3f4f6;
      }
      .signature-wrapper {
        background: #ffffff;
        border-radius: 1rem;
        box-shadow: 0 1rem 2.5rem rgba(15, 23, 42, 0.08);
      }
    </style>
  </head>
  <body>
    <main class="container py-5">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-10 col-xl-8">
          <div class="signature-wrapper p-4 p-md-5">
            <header class="mb-4 text-center">
              <h1 class="h4 mb-2">Digitale Meldeschein-Unterschrift</h1>
              <p class="text-muted mb-0">für <?= htmlspecialchars($appName) ?></p>
            </header>
            <?php if ($successMessage !== null): ?>
              <div class="alert alert-success" role="status">
                <?= htmlspecialchars($successMessage) ?> Sie können dieses Fenster nun schließen.
              </div>
            <?php endif; ?>
            <?php if ($errorMessage !== null && $successMessage === null): ?>
              <div class="alert alert-warning" role="alert">
                <?= htmlspecialchars($errorMessage) ?>
              </div>
            <?php endif; ?>

            <?php if ($form !== null): ?>
              <section class="mb-4">
                <h2 class="h5 mb-3">Meldeschein <?= htmlspecialchars($formNumber !== '' ? $formNumber : 'Ohne Nummer') ?></h2>
                <dl class="row small mb-0">
                  <dt class="col-sm-4">Gast</dt>
                  <dd class="col-sm-8 mb-2">
                    <div class="fw-semibold"><?= htmlspecialchars($guestName !== '' ? $guestName : 'Gast') ?></div>
                    <?php foreach ($guestAddressLines as $line): ?>
                      <div><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                  </dd>
                  <dt class="col-sm-4">Aufenthalt</dt>
                  <dd class="col-sm-8 mb-2">
                    <?= htmlspecialchars($arrivalLabel ?? '—') ?> – <?= htmlspecialchars($departureLabel ?? '—') ?>
                    <?php if ($nights !== null && $nights > 0): ?>
                      <div class="text-muted"><?= (int) $nights ?> Übernachtung<?= $nights === 1 ? '' : 'en' ?></div>
                    <?php endif; ?>
                  </dd>
                  <dt class="col-sm-4">Zimmer</dt>
                  <dd class="col-sm-8 mb-2"><?= htmlspecialchars($roomLabel) ?></dd>
                  <dt class="col-sm-4">Reisezweck</dt>
                  <dd class="col-sm-8 mb-2"><?= htmlspecialchars($purpose) ?></dd>
                  <?php if ($companyName !== ''): ?>
                    <dt class="col-sm-4">Firma</dt>
                    <dd class="col-sm-8 mb-2"><?= htmlspecialchars($companyName) ?></dd>
                  <?php endif; ?>
                  <?php if ($reservationNumber !== ''): ?>
                    <dt class="col-sm-4">Reservierung</dt>
                    <dd class="col-sm-8 mb-2"><?= htmlspecialchars($reservationNumber) ?></dd>
                  <?php endif; ?>
                  <?php if ($expiresLabel !== null): ?>
                    <dt class="col-sm-4">Link gültig bis</dt>
                    <dd class="col-sm-8 mb-0"><?= htmlspecialchars($expiresLabel) ?></dd>
                  <?php endif; ?>
                </dl>
              </section>

              <?php if ($signatureAllowed): ?>
                <section>
                  <h2 class="h5 mb-3">Unterschrift erfassen</h2>
                  <form method="post" id="guest-signature-form">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" name="signature_data" id="guest-signature-data">
                    <div class="mb-3">
                      <canvas id="guest-signature-pad" width="900" height="260" class="w-100 border border-secondary-subtle rounded" aria-label="Unterschriftsfeld"></canvas>
                      <div class="form-text">Bitte unterschreiben Sie direkt im Feld. Nutzen Sie „Zurücksetzen“, um die Signatur zu löschen.</div>
                      <div class="text-danger small mt-2 d-none" id="guest-signature-error"></div>
                      <noscript><div class="text-danger small mt-2">Bitte aktivieren Sie JavaScript, um die digitale Unterschrift zu erfassen.</div></noscript>
                    </div>
                    <div class="d-flex flex-column flex-md-row gap-2">
                      <button type="button" class="btn btn-outline-secondary" id="guest-signature-clear">Zurücksetzen</button>
                      <button type="submit" class="btn btn-primary">Unterschrift senden</button>
                    </div>
                  </form>
                </section>
              <?php elseif ($signatureExistingPath !== '' && $successMessage === null): ?>
                <div class="alert alert-info" role="status">
                  Für diesen Meldeschein wurde bereits eine digitale Unterschrift gespeichert. Vielen Dank!
                </div>
              <?php elseif ($signatureExpired && $successMessage === null): ?>
                <div class="alert alert-warning" role="status">
                  Dieser Signaturlink ist abgelaufen. Bitte kontaktieren Sie die Unterkunft, um einen neuen Link zu erhalten.
                </div>
              <?php endif; ?>
            <?php else: ?>
              <p class="text-muted mb-0">Der Meldeschein konnte nicht geladen werden. Bitte wenden Sie sich direkt an Ihre Unterkunft.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>

    <script>
      (function () {
        var canvas = document.getElementById('guest-signature-pad');
        var form = document.getElementById('guest-signature-form');
        var dataInput = document.getElementById('guest-signature-data');
        var clearButton = document.getElementById('guest-signature-clear');
        var errorHint = document.getElementById('guest-signature-error');

        if (!canvas || !form || !dataInput) {
          return;
        }

        var context = canvas.getContext('2d');
        if (!context) {
          if (errorHint) {
            errorHint.textContent = 'Ihr Browser unterstützt keine digitale Unterschrift.';
            errorHint.classList.remove('d-none');
          }
          canvas.classList.add('d-none');
          return;
        }

        canvas.style.touchAction = 'none';

        var scaleRatio = 1;
        var drawing = false;
        var hasSignature = false;

        function clearError() {
          if (errorHint) {
            errorHint.textContent = '';
            errorHint.classList.add('d-none');
          }
        }

        function applyStyle() {
          context.strokeStyle = '#111827';
          context.lineWidth = 2;
          context.lineCap = 'round';
          context.lineJoin = 'round';
        }

        function resetCanvasBackground() {
          context.setTransform(1, 0, 0, 1, 0, 0);
          context.clearRect(0, 0, canvas.width, canvas.height);
          context.setTransform(scaleRatio, 0, 0, scaleRatio, 0, 0);
          var width = canvas.width / scaleRatio;
          var height = canvas.height / scaleRatio;
          context.fillStyle = '#ffffff';
          context.fillRect(0, 0, width, height);
          applyStyle();
        }

        function resizeCanvas() {
          var rect = canvas.getBoundingClientRect();
          scaleRatio = Math.max(window.devicePixelRatio || 1, 1);
          canvas.width = Math.max(rect.width, 1) * scaleRatio;
          canvas.height = Math.max(rect.height, 1) * scaleRatio;
          resetCanvasBackground();
          hasSignature = false;
          dataInput.value = '';
        }

        function getPoint(event) {
          var rect = canvas.getBoundingClientRect();
          return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
          };
        }

        function stopDrawing(event) {
          if (!drawing) {
            return;
          }

          drawing = false;

          if (event && typeof canvas.releasePointerCapture === 'function') {
            try {
              canvas.releasePointerCapture(event.pointerId);
            } catch (error) {
              // ignore
            }
          }
        }

        resizeCanvas();

        canvas.addEventListener('pointerdown', function (event) {
          event.preventDefault();
          clearError();
          drawing = true;

          if (typeof canvas.setPointerCapture === 'function') {
            try {
              canvas.setPointerCapture(event.pointerId);
            } catch (error) {
              // ignore
            }
          }

          var point = getPoint(event);
          context.beginPath();
          context.moveTo(point.x, point.y);
        });

        canvas.addEventListener('pointermove', function (event) {
          if (!drawing) {
            return;
          }

          event.preventDefault();
          var point = getPoint(event);
          context.lineTo(point.x, point.y);
          context.stroke();
          hasSignature = true;
        });

        canvas.addEventListener('pointerup', stopDrawing);
        canvas.addEventListener('pointerleave', stopDrawing);
        canvas.addEventListener('pointercancel', stopDrawing);

        if (clearButton) {
          clearButton.addEventListener('click', function (event) {
            event.preventDefault();
            resizeCanvas();
            clearError();
          });
        }

        form.addEventListener('submit', function (event) {
          clearError();

          if (!hasSignature) {
            event.preventDefault();
            if (errorHint) {
              errorHint.textContent = 'Bitte unterschreiben Sie im Feld, bevor Sie fortfahren.';
              errorHint.classList.remove('d-none');
            }
            return;
          }

          try {
            dataInput.value = canvas.toDataURL('image/png');
          } catch (error) {
            event.preventDefault();
            if (errorHint) {
              errorHint.textContent = 'Die Unterschrift konnte nicht verarbeitet werden. Bitte erneut versuchen.';
              errorHint.classList.remove('d-none');
            }
          }
        });

        window.addEventListener('resize', function () {
          var wasSigned = hasSignature;
          resizeCanvas();
          if (wasSigned && errorHint) {
            errorHint.textContent = 'Nach der Größenänderung muss die Unterschrift erneut erfasst werden.';
            errorHint.classList.remove('d-none');
          }
        });
      })();
    </script>
  </body>
</html>
