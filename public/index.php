<?php

use ModPMS\Calendar;
use ModPMS\CompanyManager;
use ModPMS\Database;
use ModPMS\GuestManager;
use ModPMS\RoomCategoryManager;
use ModPMS\RoomManager;
use ModPMS\SystemUpdater;
use ModPMS\UserManager;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/CompanyManager.php';
require_once __DIR__ . '/../src/RoomCategoryManager.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/RoomManager.php';
require_once __DIR__ . '/../src/SystemUpdater.php';
require_once __DIR__ . '/../src/UserManager.php';
require_once __DIR__ . '/../src/GuestManager.php';

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

$guestFormData = [
    'id' => null,
    'salutation' => '',
    'first_name' => '',
    'last_name' => '',
    'date_of_birth' => '',
    'nationality' => '',
    'document_type' => '',
    'document_number' => '',
    'address_street' => '',
    'address_postal_code' => '',
    'address_city' => '',
    'address_country' => '',
    'email' => '',
    'phone' => '',
    'purpose_of_stay' => 'privat',
    'notes' => '',
    'company_id' => '',
    'room_id' => '',
];

$companyFormData = [
    'id' => null,
    'name' => '',
    'address_street' => '',
    'address_postal_code' => '',
    'address_city' => '',
    'address_country' => '',
    'email' => '',
    'phone' => '',
    'tax_id' => '',
    'notes' => '',
];

$userFormData = [
    'id' => null,
    'name' => '',
    'email' => '',
    'role' => 'mitarbeiter',
];

$navItems = [
    'dashboard' => 'Dashboard',
    'categories' => 'Kategorien',
    'rooms' => 'Zimmer',
    'guests' => 'Gäste',
    'users' => 'Benutzer',
    'updates' => 'Updates',
];

$activeSection = $_GET['section'] ?? 'dashboard';
if (!array_key_exists($activeSection, $navItems)) {
    $activeSection = 'dashboard';
}

if (isset($_GET['editCategory'])) {
    $activeSection = 'categories';
} elseif (isset($_GET['editRoom'])) {
    $activeSection = 'rooms';
} elseif (isset($_GET['editGuest'])) {
    $activeSection = 'guests';
} elseif (isset($_GET['editUser'])) {
    $activeSection = 'users';
} elseif (isset($_GET['editCompany'])) {
    $activeSection = 'guests';
}

$calendarPastDays = 2;
$calendarFutureDays = 5;
$requestedCalendarDate = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$calendarReferenceDate = null;

if ($requestedCalendarDate !== '') {
    $candidate = DateTimeImmutable::createFromFormat('Y-m-d', $requestedCalendarDate);
    if ($candidate instanceof DateTimeImmutable) {
        $calendarReferenceDate = $candidate;
    }
}

$calendar = new Calendar($calendarReferenceDate);
$days = $calendar->daysAround($calendarPastDays, $calendarFutureDays);
$calendarRangeLabel = $calendar->rangeLabel($calendarPastDays, $calendarFutureDays);
$calendarViewLength = $calendar->viewLength($calendarPastDays, $calendarFutureDays);
$calendarPrevDate = $calendar->currentDate()->modify(sprintf('-%d days', $calendarViewLength));
$calendarNextDate = $calendar->currentDate()->modify(sprintf('+%d days', $calendarViewLength));
$calendarCurrentDateValue = $calendar->currentDate()->format('Y-m-d');
$todayDateValue = (new DateTimeImmutable('today'))->format('Y-m-d');

$config = require __DIR__ . '/../config/app.php';
$dbError = null;
$categories = [];
$rooms = [];
$calendarCategoryGroups = [];
$guests = [];
$companies = [];
$companyGuestCounts = [];
$users = [];
$roomLookup = [];
$roomOccupancies = [];
$pdo = null;
$categoryManager = null;
$roomManager = null;
$guestManager = null;
$companyManager = null;
$userManager = null;

try {
    $pdo = Database::getConnection();
    $categoryManager = new RoomCategoryManager($pdo);
    $roomManager = new RoomManager($pdo);
    $guestManager = new GuestManager($pdo);
    $companyManager = new CompanyManager($pdo);
    $userManager = new UserManager($pdo);
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$categoryStatuses = ['aktiv', 'inaktiv'];
$roomStatuses = ['frei', 'belegt', 'wartung'];
$guestSalutations = ['Herr', 'Frau', 'Divers'];
$guestPurposeOptions = ['privat', 'geschäftlich'];
$userRoles = ['admin', 'mitarbeiter'];

$normalizeDateInput = static function (string $value): ?string {
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false) {
        return null;
    }

    return $date->format('Y-m-d');
};

$createDateImmutable = static function (?string $value): ?DateTimeImmutable {
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable $exception) {
        return null;
    }
};

$buildGuestCalendarLabel = static function (array $guest): string {
    $companyName = isset($guest['company_name']) ? trim((string) $guest['company_name']) : '';
    if ($companyName !== '') {
        return $companyName;
    }

    $lastName = isset($guest['last_name']) ? trim((string) $guest['last_name']) : '';
    $firstName = isset($guest['first_name']) ? trim((string) $guest['first_name']) : '';

    if ($lastName !== '' && $firstName !== '') {
        $initial = function_exists('mb_substr') ? mb_substr($firstName, 0, 1) : substr($firstName, 0, 1);

        return sprintf('%s %s.', $lastName, strtoupper((string) $initial));
    }

    if ($lastName !== '') {
        return $lastName;
    }

    return $firstName !== '' ? $firstName : 'Gast';
};

if ($pdo !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form'])) {
    $form = $_POST['form'];

    switch ($form) {
        case 'category_create':
        case 'category_update':
            $activeSection = 'categories';
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

                header('Location: index.php?section=categories');
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

            header('Location: index.php?section=categories');
            exit;

        case 'category_delete':
            $activeSection = 'categories';
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

            header('Location: index.php?section=categories');
            exit;

        case 'room_create':
        case 'room_update':
            $activeSection = 'rooms';
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

                header('Location: index.php?section=rooms');
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

            header('Location: index.php?section=rooms');
            exit;

        case 'room_delete':
            $activeSection = 'rooms';
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

            header('Location: index.php?section=rooms');
            exit;

        case 'guest_create':
        case 'guest_update':
            $activeSection = 'guests';
            $salutationInput = trim($_POST['salutation'] ?? '');
            if ($salutationInput !== '' && !in_array($salutationInput, $guestSalutations, true)) {
                $salutationInput = '';
            }

            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $dateOfBirthInput = trim((string) ($_POST['date_of_birth'] ?? ''));
            $nationality = trim($_POST['nationality'] ?? '');
            $documentType = trim($_POST['document_type'] ?? '');
            $documentNumber = trim($_POST['document_number'] ?? '');
            $addressStreet = trim($_POST['address_street'] ?? '');
            $addressPostalCode = trim($_POST['address_postal_code'] ?? '');
            $addressCity = trim($_POST['address_city'] ?? '');
            $addressCountry = trim($_POST['address_country'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $purposeInput = $_POST['purpose_of_stay'] ?? 'privat';
            $notes = trim($_POST['notes'] ?? '');
            $companyIdInput = trim((string) ($_POST['company_id'] ?? ''));
            $roomIdInput = trim((string) ($_POST['room_id'] ?? ''));

            if (!in_array($purposeInput, $guestPurposeOptions, true)) {
                $purposeInput = 'privat';
            }

            $guestFormData = [
                'id' => $form === 'guest_update' ? (int) ($_POST['id'] ?? 0) : null,
                'salutation' => $salutationInput,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $dateOfBirthInput,
                'nationality' => $nationality,
                'document_type' => $documentType,
                'document_number' => $documentNumber,
                'address_street' => $addressStreet,
                'address_postal_code' => $addressPostalCode,
                'address_city' => $addressCity,
                'address_country' => $addressCountry,
                'email' => $email,
                'phone' => $phone,
                'purpose_of_stay' => $purposeInput,
                'notes' => $notes,
                'company_id' => $companyIdInput,
                'room_id' => $roomIdInput,
            ];

            if ($firstName === '' || $lastName === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie mindestens Vor- und Nachnamen des Gastes an.',
                ];
                break;
            }

            $companyId = null;
            if ($companyIdInput !== '') {
                $companyIdValue = (int) $companyIdInput;

                if ($companyIdValue <= 0) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Die ausgewählte Firma ist ungültig.',
                    ];
                    break;
                }

                if ($companyManager === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Firmenzuordnungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
                    ];
                    break;
                }

                $company = $companyManager->find($companyIdValue);
                if ($company === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Die ausgewählte Firma wurde nicht gefunden.',
                    ];
                    break;
                }

                $companyId = $companyIdValue;
            }

            $roomId = null;
            if ($roomIdInput !== '') {
                $roomIdValue = (int) $roomIdInput;

                if ($roomIdValue <= 0) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Das ausgewählte Zimmer ist ungültig.',
                    ];
                    break;
                }

                if ($roomManager === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Zimmer konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
                    ];
                    break;
                }

                $room = $roomManager->find($roomIdValue);
                if ($room === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Das ausgewählte Zimmer wurde nicht gefunden.',
                    ];
                    break;
                }

                $roomId = $roomIdValue;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse für den Gast an.',
                ];
                break;
            }

            if (($documentType === '' && $documentNumber !== '') || ($documentType !== '' && $documentNumber === '')) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte hinterlegen Sie Ausweisart und -nummer gemeinsam.',
                ];
                break;
            }

            $normalizedBirthDate = $normalizeDateInput($dateOfBirthInput);
            if ($dateOfBirthInput !== '' && $normalizedBirthDate === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das angegebene Geburtsdatum ist ungültig. Bitte verwenden Sie das Format JJJJ-MM-TT.',
                ];
                break;
            }

            $payload = [
                'salutation' => $salutationInput !== '' ? $salutationInput : null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $normalizedBirthDate,
                'nationality' => $nationality !== '' ? $nationality : null,
                'document_type' => $documentType !== '' ? $documentType : null,
                'document_number' => $documentNumber !== '' ? $documentNumber : null,
                'address_street' => $addressStreet !== '' ? $addressStreet : null,
                'address_postal_code' => $addressPostalCode !== '' ? $addressPostalCode : null,
                'address_city' => $addressCity !== '' ? $addressCity : null,
                'address_country' => $addressCountry !== '' ? $addressCountry : null,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'purpose_of_stay' => $purposeInput,
                'notes' => $notes !== '' ? $notes : null,
                'company_id' => $companyId,
                'room_id' => $roomId,
            ];

            if ($form === 'guest_create') {
                $guestManager->create($payload + [
                    'arrival_date' => null,
                    'departure_date' => null,
                ]);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Gast "%s %s" erfolgreich angelegt.', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php?section=guests');
                exit;
            }

            $guestId = (int) ($_POST['id'] ?? 0);
            if ($guestId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Gast konnte nicht aktualisiert werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $guest = $guestManager->find($guestId);
            if ($guest === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Gast wurde nicht gefunden.',
                ];
                break;
            }

            $guestManager->update($guestId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Gast "%s %s" wurde aktualisiert.', htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'), htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=guests');
            exit;

        case 'guest_delete':
            $activeSection = 'guests';
            $guestId = (int) ($_POST['id'] ?? 0);

            if ($guestId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Gast konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $guest = $guestManager->find($guestId);
            if ($guest === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Gast wurde nicht gefunden.',
                ];
                break;
            }

            $guestManager->delete($guestId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Gast "%s %s" wurde gelöscht.', htmlspecialchars($guest['first_name'], ENT_QUOTES, 'UTF-8'), htmlspecialchars($guest['last_name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=guests');
            exit;

        case 'company_create':
        case 'company_update':
            $activeSection = 'guests';

            if ($companyManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Firmenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $name = trim($_POST['name'] ?? '');
            $addressStreet = trim($_POST['address_street'] ?? '');
            $addressPostalCode = trim($_POST['address_postal_code'] ?? '');
            $addressCity = trim($_POST['address_city'] ?? '');
            $addressCountry = trim($_POST['address_country'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $taxId = trim($_POST['tax_id'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $companyFormData = [
                'id' => $form === 'company_update' ? (int) ($_POST['id'] ?? 0) : null,
                'name' => $name,
                'address_street' => $addressStreet,
                'address_postal_code' => $addressPostalCode,
                'address_city' => $addressCity,
                'address_country' => $addressCountry,
                'email' => $email,
                'phone' => $phone,
                'tax_id' => $taxId,
                'notes' => $notes,
            ];

            if ($name === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Firmennamen an.',
                ];
                break;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse für die Firma an.',
                ];
                break;
            }

            $payload = [
                'name' => $name,
                'address_street' => $addressStreet !== '' ? $addressStreet : null,
                'address_postal_code' => $addressPostalCode !== '' ? $addressPostalCode : null,
                'address_city' => $addressCity !== '' ? $addressCity : null,
                'address_country' => $addressCountry !== '' ? $addressCountry : null,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'tax_id' => $taxId !== '' ? $taxId : null,
                'notes' => $notes !== '' ? $notes : null,
            ];

            if ($form === 'company_create') {
                $companyManager->create($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Firma "%s" wurde angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php?section=guests');
                exit;
            }

            $companyId = (int) ($_POST['id'] ?? 0);
            if ($companyId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Firma konnte nicht aktualisiert werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $company = $companyManager->find($companyId);
            if ($company === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Firma wurde nicht gefunden.',
                ];
                break;
            }

            $companyManager->update($companyId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Firma "%s" wurde aktualisiert.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=guests');
            exit;

        case 'company_delete':
            $activeSection = 'guests';

            if ($companyManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Firmenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $companyId = (int) ($_POST['id'] ?? 0);

            if ($companyId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Firma konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $company = $companyManager->find($companyId);
            if ($company === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Firma wurde nicht gefunden.',
                ];
                break;
            }

            if ($companyManager->hasGuests($companyId)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Firma kann nicht gelöscht werden, solange Gäste zugeordnet sind.',
                ];
                break;
            }

            $companyManager->delete($companyId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Firma "%s" wurde gelöscht.', htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=guests');
            exit;

        case 'user_create':
        case 'user_update':
            $activeSection = 'users';
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

                header('Location: index.php?section=users');
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

            header('Location: index.php?section=users');
            exit;

        case 'user_delete':
            $activeSection = 'users';
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

            header('Location: index.php?section=users');
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
    $guests = $guestManager->all();
    if ($companyManager !== null) {
        $companies = $companyManager->all();
    }
    $users = $userManager->all();

    foreach ($rooms as $room) {
        if (!isset($room['id'])) {
            continue;
        }

        $roomLookup[(int) $room['id']] = $room;
    }

    foreach ($guests as $guest) {
        if (isset($guest['company_id']) && $guest['company_id'] !== null) {
            $companyId = (int) $guest['company_id'];
            if (!isset($companyGuestCounts[$companyId])) {
                $companyGuestCounts[$companyId] = 0;
            }
            $companyGuestCounts[$companyId]++;
        }
    }

    $roomStays = [];

    foreach ($guests as $guest) {
        if (!isset($guest['room_id']) || $guest['room_id'] === null) {
            continue;
        }

        $roomId = (int) $guest['room_id'];
        if ($roomId <= 0) {
            continue;
        }

        $roomStays[$roomId][] = [
            'label' => $buildGuestCalendarLabel($guest),
            'arrival' => $createDateImmutable($guest['arrival_date'] ?? null),
            'departure' => $createDateImmutable($guest['departure_date'] ?? null),
        ];
    }

    if ($days !== []) {
        $viewStart = new DateTimeImmutable($days[0]['date']);
        $viewEnd = new DateTimeImmutable($days[count($days) - 1]['date']);
        $viewEndExclusive = $viewEnd->modify('+1 day');

        foreach ($roomStays as $roomId => $stays) {
            foreach ($stays as $stay) {
                $arrival = $stay['arrival'];
                $departure = $stay['departure'];

                if ($arrival === null && $departure === null) {
                    continue;
                }

                $stayStart = $arrival ?? $viewStart;
                if ($arrival === null && $departure !== null && $departure <= $viewStart) {
                    continue;
                }

                $exclusiveEnd = $viewEndExclusive;
                if ($departure !== null) {
                    $exclusiveEnd = $departure;
                    if ($arrival !== null && $departure <= $arrival) {
                        $exclusiveEnd = $arrival->modify('+1 day');
                    }
                }

                $effectiveStart = $stayStart > $viewStart ? $stayStart : $viewStart;
                $effectiveEnd = $exclusiveEnd < $viewEndExclusive ? $exclusiveEnd : $viewEndExclusive;

                if ($arrival === null && $departure !== null && $effectiveEnd <= $effectiveStart) {
                    $effectiveEnd = $departure;
                }

                if ($effectiveStart >= $effectiveEnd) {
                    continue;
                }

                for ($cursor = $effectiveStart; $cursor < $effectiveEnd; $cursor = $cursor->modify('+1 day')) {
                    $dateKey = $cursor->format('Y-m-d');
                    $roomOccupancies[$roomId][$dateKey][] = $stay['label'];
                }
            }
        }
    }

    $categoryPositions = [];

    foreach ($categories as $category) {
        $categoryWithIntId = $category;
        $categoryWithIntId['id'] = (int) $category['id'];

        $categoryPositions[$categoryWithIntId['id']] = count($calendarCategoryGroups);
        $calendarCategoryGroups[] = [
            'category' => $categoryWithIntId,
            'rooms' => [],
            'totalRooms' => 0,
            'freeRooms' => 0,
            'is_uncategorized' => false,
        ];
    }

    $uncategorizedIndex = null;

    foreach ($rooms as $room) {
        $status = $room['status'] ?? '';
        $isFree = $status === 'frei';
        $categoryId = isset($room['category_id']) && $room['category_id'] !== null
            ? (int) $room['category_id']
            : null;

        if ($categoryId !== null && isset($categoryPositions[$categoryId])) {
            $targetIndex = $categoryPositions[$categoryId];
        } else {
            if ($uncategorizedIndex === null) {
                $uncategorizedIndex = count($calendarCategoryGroups);
                $calendarCategoryGroups[] = [
                    'category' => [
                        'id' => null,
                        'name' => 'Ohne Kategorie',
                        'status' => null,
                    ],
                    'rooms' => [],
                    'totalRooms' => 0,
                    'freeRooms' => 0,
                    'is_uncategorized' => true,
                ];
            }

            $targetIndex = $uncategorizedIndex;
        }

        $calendarCategoryGroups[$targetIndex]['rooms'][] = $room;
        $calendarCategoryGroups[$targetIndex]['totalRooms']++;

        if ($isFree) {
            $calendarCategoryGroups[$targetIndex]['freeRooms']++;
        }
    }
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

if ($pdo !== null && isset($_GET['editGuest']) && $guestFormData['id'] === null) {
    $guestToEdit = $guestManager->find((int) $_GET['editGuest']);

    if ($guestToEdit) {
        $guestFormData = [
            'id' => (int) $guestToEdit['id'],
            'salutation' => $guestToEdit['salutation'] ?? '',
            'first_name' => $guestToEdit['first_name'],
            'last_name' => $guestToEdit['last_name'],
            'date_of_birth' => $guestToEdit['date_of_birth'] ?? '',
            'nationality' => $guestToEdit['nationality'] ?? '',
            'document_type' => $guestToEdit['document_type'] ?? '',
            'document_number' => $guestToEdit['document_number'] ?? '',
            'address_street' => $guestToEdit['address_street'] ?? '',
            'address_postal_code' => $guestToEdit['address_postal_code'] ?? '',
            'address_city' => $guestToEdit['address_city'] ?? '',
            'address_country' => $guestToEdit['address_country'] ?? '',
            'email' => $guestToEdit['email'] ?? '',
            'phone' => $guestToEdit['phone'] ?? '',
            'purpose_of_stay' => $guestToEdit['purpose_of_stay'] ?? 'privat',
            'notes' => $guestToEdit['notes'] ?? '',
            'company_id' => isset($guestToEdit['company_id']) && $guestToEdit['company_id'] !== null ? (string) $guestToEdit['company_id'] : '',
            'room_id' => isset($guestToEdit['room_id']) && $guestToEdit['room_id'] !== null ? (string) $guestToEdit['room_id'] : '',
        ];
    } elseif ($alert === null) {
        $alert = [
            'type' => 'warning',
            'message' => 'Der ausgewählte Gast wurde nicht gefunden.',
        ];
    }
}

if ($pdo !== null && isset($_GET['editCompany']) && $companyFormData['id'] === null) {
    if ($companyManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Die Firmenverwaltung ist derzeit nicht verfügbar.',
            ];
        }
    } else {
        $companyToEdit = $companyManager->find((int) $_GET['editCompany']);

        if ($companyToEdit) {
            $companyFormData = [
                'id' => (int) $companyToEdit['id'],
                'name' => $companyToEdit['name'],
                'address_street' => $companyToEdit['address_street'] ?? '',
                'address_postal_code' => $companyToEdit['address_postal_code'] ?? '',
                'address_city' => $companyToEdit['address_city'] ?? '',
                'address_country' => $companyToEdit['address_country'] ?? '',
                'email' => $companyToEdit['email'] ?? '',
                'phone' => $companyToEdit['phone'] ?? '',
                'tax_id' => $companyToEdit['tax_id'] ?? '',
                'notes' => $companyToEdit['notes'] ?? '',
            ];
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Die ausgewählte Firma wurde nicht gefunden.',
            ];
        }
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
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
      <div class="container-fluid">
        <a class="navbar-brand" href="index.php?section=dashboard">🏨 <?= htmlspecialchars($config['name']) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#primaryNav" aria-controls="primaryNav" aria-expanded="false" aria-label="Navigation umschalten">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="primaryNav">
          <ul class="navbar-nav me-auto mb-2 mb-lg-0">
            <?php foreach ($navItems as $sectionKey => $label): ?>
              <li class="nav-item">
                <?php
                  $navUrl = 'index.php?section=' . rawurlencode($sectionKey);
                  if ($sectionKey === 'dashboard' && $calendarReferenceDate instanceof DateTimeImmutable) {
                      $navUrl .= '&date=' . rawurlencode($calendarCurrentDateValue);
                  }
                ?>
                <a class="nav-link <?= $activeSection === $sectionKey ? 'active' : '' ?>" href="<?= htmlspecialchars($navUrl) ?>"><?= htmlspecialchars($label) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="d-flex align-items-center gap-3 flex-wrap justify-content-end">
            <div class="dropdown quick-action-dropdown">
              <button class="btn btn-primary btn-sm quick-action-toggle" type="button" id="quickActionMenu" data-bs-toggle="dropdown" aria-expanded="false" title="Schnell erstellen">
                <span class="quick-action-icon" aria-hidden="true">+</span>
                <span class="visually-hidden">Schnellauswahl öffnen</span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end quick-action-menu" aria-labelledby="quickActionMenu">
                <li><a class="dropdown-item" href="index.php?section=dashboard#reservation-create">Neue Reservierung</a></li>
                <li><a class="dropdown-item" href="index.php?section=guests#guest-meldeschein">Meldeschein vorbereiten</a></li>
                <li><a class="dropdown-item" href="index.php?section=guests#guest-form">Neuen Gast anlegen</a></li>
              </ul>
            </div>
            <span class="badge rounded-pill text-bg-primary">Version <?= htmlspecialchars($config['version']) ?></span>
            <span class="text-muted small">Angemeldet als <?= htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Unbekannt') ?></span>
            <a href="logout.php" class="btn btn-outline-secondary btn-sm">Abmelden</a>
          </div>
        </div>
      </div>
    </nav>

    <main class="app-main py-4">
      <div class="container-xxl">
      <?php if ($dbError): ?>
        <div class="alert alert-danger" role="alert">
          <?= htmlspecialchars($dbError) ?><br>
          <small>Bitte führen Sie die <a href="install.php">Installation</a> durch oder prüfen Sie die Verbindungseinstellungen.</small>
        </div>
      <?php endif; ?>

      <?php if ($alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> alert-dismissible fade show mb-4" role="alert">
          <?= $alert['message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if ($activeSection === 'dashboard'): ?>
      <section id="dashboard" class="app-section active">
        <div class="row g-4">
        <div class="col-lg-8">
          <div class="card module-card h-100" id="reservation-create">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <div>
                <h2 class="h5 mb-1">Dashboard</h2>
                <p class="text-muted mb-0">Kalender und aktuelle Auslastung im Blick.</p>
              </div>
              <span class="badge text-bg-info">Basis-Modul</span>
            </div>
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3 calendar-toolbar">
                <div>
                  <h3 class="h4 mb-1"><?= htmlspecialchars($calendarRangeLabel) ?></h3>
                  <div class="text-muted small">Referenzdatum: <?= (new DateTimeImmutable($calendarCurrentDateValue))->format('d.m.Y') ?></div>
                  <div class="text-muted small">Heute: <?= (new DateTimeImmutable($todayDateValue))->format('d.m.Y') ?></div>
                </div>
                <div class="calendar-controls d-flex flex-wrap align-items-center gap-2">
                  <a class="btn btn-outline-secondary btn-sm" href="index.php?section=dashboard&amp;date=<?= $calendarPrevDate->format('Y-m-d') ?>" title="Vorherige <?= $calendarViewLength ?> Tage" aria-label="Vorherige <?= $calendarViewLength ?> Tage">&laquo;</a>
                  <a class="btn btn-outline-secondary btn-sm" href="index.php?section=dashboard" title="Zurück zu heute">Heute</a>
                  <a class="btn btn-outline-secondary btn-sm" href="index.php?section=dashboard&amp;date=<?= $calendarNextDate->format('Y-m-d') ?>" title="Nächste <?= $calendarViewLength ?> Tage" aria-label="Nächste <?= $calendarViewLength ?> Tage">&raquo;</a>
                  <form method="get" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="section" value="dashboard">
                    <label for="calendar-date" class="visually-hidden">Datum auswählen</label>
                    <input type="date" id="calendar-date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($calendarCurrentDateValue) ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Springen</button>
                  </form>
                </div>
              </div>
              <div class="calendar-grid-wrapper">
                <table class="table table-bordered align-middle room-calendar">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" class="room-column">Kategorie / Zimmer</th>
                      <?php foreach ($days as $day): ?>
                        <th scope="col" class="text-center">
                          <div class="day-number"><?= $day['day'] ?></div>
                          <small class="text-muted text-uppercase"><?= $day['weekday'] ?></small>
                        </th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!empty($calendarCategoryGroups)): ?>
                      <?php foreach ($calendarCategoryGroups as $group): ?>
                        <tr class="category-row<?= $group['is_uncategorized'] ? ' category-row-uncategorized' : '' ?>">
                          <th scope="row" class="room-label category-label">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                              <span><?= htmlspecialchars($group['category']['name']) ?></span>
                              <?php if (!$group['is_uncategorized'] && isset($group['category']['status'])): ?>
                                <?php
                                  $categoryStatusClass = $group['category']['status'] === 'aktiv'
                                    ? 'text-bg-success'
                                    : 'text-bg-secondary';
                                ?>
                                <span class="badge <?= $categoryStatusClass ?>">Status: <?= htmlspecialchars(ucfirst($group['category']['status'])) ?></span>
                              <?php elseif ($group['is_uncategorized']): ?>
                                <span class="badge text-bg-warning">Ohne Zuordnung</span>
                              <?php endif; ?>
                            </div>
                            <div class="category-summary text-muted small">
                              Gesamt: <?= $group['totalRooms'] ?> · Frei: <?= $group['freeRooms'] ?>
                            </div>
                          </th>
                          <?php foreach ($days as $day): ?>
                            <td class="category-cell">&nbsp;</td>
                          <?php endforeach; ?>
                        </tr>
                        <?php if (!empty($group['rooms'])): ?>
                          <?php foreach ($group['rooms'] as $room): ?>
                            <?php
                              $roomId = isset($room['id']) ? (int) $room['id'] : 0;
                              $roomStatus = isset($room['status']) ? (string) $room['status'] : '';
                            ?>
                            <tr>
                              <th scope="row" class="room-label room-label-room">
                                <div class="fw-semibold">Zimmer <?= htmlspecialchars($room['number']) ?></div>
                                <small class="text-muted">Status: <?= htmlspecialchars(ucfirst($room['status'])) ?></small>
                              </th>
                              <?php foreach ($days as $day): ?>
                                <?php
                                  $cellOccupants = [];
                                  if ($roomId > 0 && isset($roomOccupancies[$roomId][$day['date']])) {
                                      $cellOccupants = $roomOccupancies[$roomId][$day['date']];
                                  }

                                  $cellClasses = ['room-calendar-cell'];
                                  if ($day['isToday']) {
                                      $cellClasses[] = 'today';
                                  }
                                  if ($cellOccupants !== []) {
                                      $cellClasses[] = 'occupied';
                                  } else {
                                      $cellClasses[] = $roomStatus === 'wartung' ? 'maintenance' : 'free';
                                  }
                                ?>
                                <td class="<?= htmlspecialchars(implode(' ', $cellClasses)) ?>" data-date="<?= htmlspecialchars($day['date']) ?>" data-room="<?= htmlspecialchars($room['number']) ?>"<?= $roomId > 0 ? ' data-room-id="' . $roomId . '"' : '' ?>>
                                  <span class="visually-hidden">Zimmer <?= htmlspecialchars($room['number']) ?> am <?= htmlspecialchars($day['date']) ?></span>
                                  <?php if ($cellOccupants !== []): ?>
                                    <?php foreach ($cellOccupants as $occupantLabel): ?>
                                      <div class="occupancy-entry"><?= htmlspecialchars($occupantLabel) ?></div>
                                    <?php endforeach; ?>
                                  <?php else: ?>
                                    <?php if ($roomStatus === 'wartung'): ?>
                                      <span class="badge text-bg-warning">Wartung</span>
                                    <?php elseif ($roomStatus === 'belegt'): ?>
                                      <span class="text-muted small">Belegt</span>
                                    <?php else: ?>
                                      <span class="text-muted small">Frei</span>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                </td>
                              <?php endforeach; ?>
                            </tr>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <tr class="room-empty-row">
                            <td colspan="<?= count($days) + 1 ?>" class="text-center text-muted py-3">Noch keine Zimmer in dieser Kategorie.</td>
                          </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    <?php else: ?>
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
      </section>
      <?php endif; ?>

      <?php if ($activeSection === 'categories'): ?>
      <section id="categories" class="app-section active">
        <?php $isEditingCategory = $categoryFormData['id'] !== null; ?>
        <div class="row g-4">
          <div class="col-12 col-xxl-8">
            <div class="card module-card" id="category-management">
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
                      <a href="index.php?section=categories" class="btn btn-outline-secondary">Abbrechen</a>
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
                                <a class="btn btn-outline-secondary btn-sm" href="index.php?section=categories&editCategory=<?= (int) $category['id'] ?>">Bearbeiten</a>
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
        </div>
      </section>
      <?php endif; ?>

      <?php if ($activeSection === 'updates'): ?>
      <section id="updates" class="app-section active">
        <div class="row g-4">
          <div class="col-12 col-lg-8 col-xl-6">
            <div class="card module-card" id="system-updates">
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
                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
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
      </section>
      <?php endif; ?>

      <?php if ($activeSection === 'guests'): ?>
      <section id="guests" class="app-section active">
        <div class="row g-4">
          <div class="col-12 col-xxl-8">
          <div class="card module-card" id="guest-meldeschein" data-section="guest-management">
            <?php $isEditingGuest = $guestFormData['id'] !== null; ?>
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h2 class="h5 mb-1">Gäste &amp; Meldescheine</h2>
                <p class="text-muted mb-0"><?= $isEditingGuest ? 'Gastdaten prüfen und für den Meldeschein vervollständigen.' : 'Neue Gäste aufnehmen und Meldeschein-relevante Informationen sammeln.' ?></p>
              </div>
              <?php if ($isEditingGuest): ?>
                <span class="badge text-bg-primary">Bearbeitung</span>
              <?php else: ?>
                <span class="badge text-bg-success">Meldeschein-Ready</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" class="row g-3" id="guest-form">
                <input type="hidden" name="form" value="<?= $isEditingGuest ? 'guest_update' : 'guest_create' ?>">
                <?php if ($isEditingGuest): ?>
                  <input type="hidden" name="id" value="<?= (int) $guestFormData['id'] ?>">
                <?php endif; ?>
                <div class="col-md-2">
                  <label for="guest-salutation" class="form-label">Anrede</label>
                  <select class="form-select" id="guest-salutation" name="salutation" <?= $pdo === null ? 'disabled' : '' ?>>
                    <option value="">Keine Angabe</option>
                    <?php foreach ($guestSalutations as $salutation): ?>
                      <option value="<?= htmlspecialchars($salutation) ?>" <?= $guestFormData['salutation'] === $salutation ? 'selected' : '' ?>><?= htmlspecialchars($salutation) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-5">
                  <label for="guest-first-name" class="form-label">Vorname *</label>
                  <input type="text" class="form-control" id="guest-first-name" name="first_name" value="<?= htmlspecialchars((string) $guestFormData['first_name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-5">
                  <label for="guest-last-name" class="form-label">Nachname *</label>
                  <input type="text" class="form-control" id="guest-last-name" name="last_name" value="<?= htmlspecialchars((string) $guestFormData['last_name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                  <label for="guest-dob" class="form-label">Geburtsdatum</label>
                  <input type="date" class="form-control" id="guest-dob" name="date_of_birth" value="<?= htmlspecialchars((string) $guestFormData['date_of_birth']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                  <label for="guest-nationality" class="form-label">Staatsangehörigkeit</label>
                  <input type="text" class="form-control" id="guest-nationality" name="nationality" value="<?= htmlspecialchars((string) $guestFormData['nationality']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                  <label for="guest-purpose" class="form-label">Reisezweck</label>
                  <select class="form-select" id="guest-purpose" name="purpose_of_stay" <?= $pdo === null ? 'disabled' : '' ?>>
                    <?php foreach ($guestPurposeOptions as $purpose): ?>
                      <option value="<?= htmlspecialchars($purpose) ?>" <?= $guestFormData['purpose_of_stay'] === $purpose ? 'selected' : '' ?>><?= $purpose === 'geschäftlich' ? 'Geschäftlich' : 'Privat' ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-8">
                  <label for="guest-company" class="form-label">Firma</label>
                  <div class="input-group">
                    <select class="form-select" id="guest-company" name="company_id" <?= $pdo === null ? 'disabled' : '' ?>>
                      <option value="">Keine Zuordnung</option>
                      <?php foreach ($companies as $company): ?>
                        <option value="<?= (int) $company['id'] ?>" <?= $guestFormData['company_id'] !== '' && (int) $guestFormData['company_id'] === (int) $company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <a class="btn btn-outline-secondary" href="index.php?section=guests#company-management">Neue Firma</a>
                  </div>
                </div>
                <div class="col-md-4">
                  <label for="guest-room" class="form-label">Zimmerzuordnung</label>
                  <select class="form-select" id="guest-room" name="room_id" <?= $pdo === null ? 'disabled' : '' ?>>
                    <option value="">Keine Zuordnung</option>
                    <?php foreach ($rooms as $roomOption): ?>
                      <option value="<?= isset($roomOption['id']) ? (int) $roomOption['id'] : 0 ?>" <?= $guestFormData['room_id'] !== '' && isset($roomOption['id']) && (int) $guestFormData['room_id'] === (int) $roomOption['id'] ? 'selected' : '' ?>>Zimmer <?= htmlspecialchars($roomOption['number']) ?><?= isset($roomOption['category_name']) && $roomOption['category_name'] !== null ? ' · ' . htmlspecialchars($roomOption['category_name']) : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">An- und Abreisedaten werden im Reservierungsmodul erfasst.</div>
                </div>
                <div class="col-md-6">
                  <label for="guest-document-type" class="form-label">Ausweisart</label>
                  <input type="text" class="form-control" id="guest-document-type" name="document_type" value="<?= htmlspecialchars((string) $guestFormData['document_type']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="guest-document-number" class="form-label">Ausweisnummer</label>
                  <input type="text" class="form-control" id="guest-document-number" name="document_number" value="<?= htmlspecialchars((string) $guestFormData['document_number']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-8">
                  <label for="guest-address-street" class="form-label">Straße &amp; Hausnummer</label>
                  <input type="text" class="form-control" id="guest-address-street" name="address_street" value="<?= htmlspecialchars((string) $guestFormData['address_street']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-4">
                  <label for="guest-address-postal" class="form-label">PLZ</label>
                  <input type="text" class="form-control" id="guest-address-postal" name="address_postal_code" value="<?= htmlspecialchars((string) $guestFormData['address_postal_code']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="guest-address-city" class="form-label">Ort</label>
                  <input type="text" class="form-control" id="guest-address-city" name="address_city" value="<?= htmlspecialchars((string) $guestFormData['address_city']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="guest-address-country" class="form-label">Land</label>
                  <input type="text" class="form-control" id="guest-address-country" name="address_country" value="<?= htmlspecialchars((string) $guestFormData['address_country']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="guest-email" class="form-label">E-Mail</label>
                  <input type="email" class="form-control" id="guest-email" name="email" value="<?= htmlspecialchars((string) $guestFormData['email']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="guest-phone" class="form-label">Telefon</label>
                  <input type="text" class="form-control" id="guest-phone" name="phone" value="<?= htmlspecialchars((string) $guestFormData['phone']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="guest-notes" class="form-label">Notizen</label>
                  <textarea class="form-control" id="guest-notes" name="notes" rows="2" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $guestFormData['notes']) ?></textarea>
                  <div class="form-text">Freitext für Meldeschein-Hinweise, z. B. Begleitpersonen oder Besonderheiten.</div>
                </div>
                <div class="col-12 d-flex justify-content-end align-items-center flex-wrap gap-2">
                  <?php if ($isEditingGuest): ?>
                    <a href="index.php?section=guests" class="btn btn-outline-secondary">Abbrechen</a>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingGuest ? 'Gast aktualisieren' : 'Gast speichern' ?></button>
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
                        <th scope="col">Gast</th>
                        <th scope="col">Aufenthalt</th>
                        <th scope="col">Zimmer</th>
                        <th scope="col">Kontakt &amp; Adresse</th>
                        <th scope="col">Firma</th>
                        <th scope="col">Ausweisdaten</th>
                        <th scope="col">Meldeschein</th>
                        <th scope="col" class="text-end">Aktionen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($guests as $guest): ?>
                        <?php
                          $guestNameParts = [];
                          if (!empty($guest['salutation'])) {
                              $guestNameParts[] = $guest['salutation'];
                          }
                          $guestNameParts[] = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
                          $guestName = trim(implode(' ', array_filter($guestNameParts)));

                          $birthLabel = '<span class="text-muted">unbekannt</span>';
                          if (!empty($guest['date_of_birth'])) {
                              try {
                                  $birthLabel = htmlspecialchars((new DateTimeImmutable($guest['date_of_birth']))->format('d.m.Y'));
                              } catch (Throwable $exception) {
                                  $birthLabel = htmlspecialchars($guest['date_of_birth']);
                              }
                          }

                          $nationalityLabel = !empty($guest['nationality']) ? htmlspecialchars($guest['nationality']) : '—';

                          $arrivalLabel = null;
                          if (!empty($guest['arrival_date'])) {
                              try {
                                  $arrivalLabel = (new DateTimeImmutable($guest['arrival_date']))->format('d.m.Y');
                              } catch (Throwable $exception) {
                                  $arrivalLabel = $guest['arrival_date'];
                              }
                          }

                          $departureLabel = null;
                          if (!empty($guest['departure_date'])) {
                              try {
                                  $departureLabel = (new DateTimeImmutable($guest['departure_date']))->format('d.m.Y');
                              } catch (Throwable $exception) {
                                  $departureLabel = $guest['departure_date'];
                              }
                          }

                          $stayDetails = [];
                          $hasStayWindow = $arrivalLabel !== null || $departureLabel !== null;
                          if ($hasStayWindow) {
                              $stayDetails[] = sprintf('%s – %s', $arrivalLabel !== null ? $arrivalLabel : 'offen', $departureLabel !== null ? $departureLabel : 'offen');
                          } else {
                              $stayDetails[] = 'Reservierungsdaten folgen';
                          }
                          $stayDetails[] = $guest['purpose_of_stay'] === 'geschäftlich' ? 'Geschäftlich' : 'Privat';

                          $roomAssignment = null;
                          $roomAssignmentStatus = null;
                          if (isset($guest['room_id']) && $guest['room_id'] !== null) {
                              $guestRoomId = (int) $guest['room_id'];
                              if (isset($roomLookup[$guestRoomId])) {
                                  $guestRoom = $roomLookup[$guestRoomId];
                                  $roomAssignment = 'Zimmer ' . ($guestRoom['number'] ?? $guestRoomId);
                                  if (!empty($guestRoom['category_name'])) {
                                      $roomAssignment .= ' · ' . $guestRoom['category_name'];
                                  }
                                  $roomAssignmentStatus = $guestRoom['status'] ?? null;
                              } else {
                                  $roomAssignment = 'Zimmer #' . $guestRoomId;
                              }
                          }

                          $contactParts = [];
                          if (!empty($guest['email'])) {
                              $contactParts[] = htmlspecialchars($guest['email']);
                          }
                          if (!empty($guest['phone'])) {
                              $contactParts[] = htmlspecialchars($guest['phone']);
                          }

                          $companyName = isset($guest['company_name']) && $guest['company_name'] !== null ? (string) $guest['company_name'] : '';

                          $addressParts = [];
                          if (!empty($guest['address_street'])) {
                              $addressParts[] = htmlspecialchars($guest['address_street']);
                          }
                          $cityLineParts = array_filter([
                              $guest['address_postal_code'] !== null ? trim((string) $guest['address_postal_code']) : '',
                              $guest['address_city'] !== null ? trim((string) $guest['address_city']) : '',
                          ]);
                          if ($cityLineParts !== []) {
                              $addressParts[] = htmlspecialchars(implode(' ', $cityLineParts));
                          }
                          if (!empty($guest['address_country'])) {
                              $addressParts[] = htmlspecialchars($guest['address_country']);
                          }

                          $documentParts = [];
                          if (!empty($guest['document_type'])) {
                              $documentParts[] = htmlspecialchars($guest['document_type']);
                          }
                          if (!empty($guest['document_number'])) {
                              $documentParts[] = htmlspecialchars($guest['document_number']);
                          }

                          $hasMeldescheinCore = !empty($guest['date_of_birth'])
                              && !empty($guest['nationality'])
                              && !empty($guest['address_street'])
                              && !empty($guest['address_city'])
                              && !empty($guest['address_country'])
                              && !empty($guest['document_type'])
                              && !empty($guest['document_number']);

                          $hasMeldeschein = $hasStayWindow && $hasMeldescheinCore;

                          if (!$hasStayWindow) {
                              $meldescheinBadgeClass = 'text-bg-warning';
                              $meldescheinBadgeText = 'wartet auf Reservierung';
                              $meldescheinStatusMessage = 'Reservierungsdaten werden für den Meldeschein benötigt.';
                          } else {
                              $meldescheinBadgeClass = $hasMeldeschein ? 'text-bg-success' : 'text-bg-warning';
                              $meldescheinBadgeText = $hasMeldeschein ? 'bereit' : 'unvollständig';
                              $meldescheinStatusMessage = $hasMeldeschein
                                  ? 'Alle Pflichtfelder befüllt.'
                                  : 'Bitte fehlende Angaben ergänzen.';
                          }
                        ?>
                        <tr>
                          <td>
                            <div class="fw-semibold"><?= htmlspecialchars($guestName) ?></div>
                            <div class="small text-muted">Geburtsdatum: <?= $birthLabel ?><?= $nationalityLabel !== '—' ? ' · ' . $nationalityLabel : '' ?></div>
                          </td>
                          <td>
                            <?php foreach ($stayDetails as $stayDetail): ?>
                              <div><?= htmlspecialchars($stayDetail) ?></div>
                            <?php endforeach; ?>
                            <?php if (empty($stayDetails)): ?>
                              <span class="text-muted">Keine Angaben</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($roomAssignment !== null): ?>
                              <div class="fw-semibold"><?= htmlspecialchars($roomAssignment) ?></div>
                              <?php if ($roomAssignmentStatus !== null): ?>
                                <div class="small text-muted">Status: <?= htmlspecialchars(ucfirst($roomAssignmentStatus)) ?></div>
                              <?php endif; ?>
                            <?php else: ?>
                              <span class="text-muted">Keine Zuordnung</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($contactParts !== []): ?>
                              <?php foreach ($contactParts as $contactPart): ?>
                                <div><?= $contactPart ?></div>
                              <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($addressParts !== []): ?>
                              <div class="mt-1">
                                <?php foreach ($addressParts as $addressPart): ?>
                                  <div><?= $addressPart ?></div>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                          <?php if ($contactParts === [] && $addressParts === []): ?>
                            <span class="text-muted">Keine Kontaktdaten</span>
                          <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($companyName !== ''): ?>
                              <div class="fw-semibold"><?= htmlspecialchars($companyName) ?></div>
                              <div class="small text-muted">Zuordnung für Meldeschein</div>
                            <?php else: ?>
                              <span class="text-muted">Keine Zuordnung</span>
                              <?php if ($guest['purpose_of_stay'] === 'geschäftlich'): ?>
                                <div class="small text-warning">Geschäftsreise ohne Firma</div>
                              <?php endif; ?>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($documentParts !== []): ?>
                              <?php foreach ($documentParts as $documentPart): ?>
                                <div><?= $documentPart ?></div>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <span class="text-muted">Keine Angaben</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <span class="badge <?= $meldescheinBadgeClass ?> text-uppercase"><?= $meldescheinBadgeText ?></span>
                            <div class="small text-muted"><?= htmlspecialchars($meldescheinStatusMessage) ?></div>
                          </td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2 flex-wrap">
                              <a class="btn btn-outline-secondary btn-sm" href="index.php?section=guests&editGuest=<?= (int) $guest['id'] ?>">Bearbeiten</a>
                              <form method="post" onsubmit="return confirm('Gast wirklich löschen?');">
                                <input type="hidden" name="form" value="guest_delete">
                                <input type="hidden" name="id" value="<?= (int) $guest['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Löschen</button>
                              </form>
                              <button type="button" class="btn btn-outline-primary btn-sm" title="Export folgt in einem späteren Release" disabled>Meldeschein</button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (empty($guests)): ?>
                        <tr>
                          <td colspan="8" class="text-center text-muted py-3">Noch keine Gäste erfasst.</td>
                        </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <p class="small text-muted mt-3 mb-0">Hinweis: Sobald alle Pflichtfelder gepflegt sind, kann ein Meldeschein aus den gespeicherten Daten generiert werden.</p>
              <?php endif; ?>
            </div>
          </div>
          </div>
          <div class="col-12 col-xxl-4">
          <div class="card module-card h-100" id="company-management">
            <?php $isEditingCompany = $companyFormData['id'] !== null; ?>
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h2 class="h5 mb-1">Firmen</h2>
                <p class="text-muted mb-0"><?= $isEditingCompany ? 'Firmendaten anpassen und Zuordnungen prüfen.' : 'Stammdaten für Firmenkunden verwalten.' ?></p>
              </div>
              <?php if ($isEditingCompany): ?>
                <span class="badge text-bg-primary">Bearbeitung</span>
              <?php else: ?>
                <span class="badge text-bg-info">Optional</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" class="row g-3" id="company-form">
                <input type="hidden" name="form" value="<?= $isEditingCompany ? 'company_update' : 'company_create' ?>">
                <?php if ($isEditingCompany): ?>
                  <input type="hidden" name="id" value="<?= (int) $companyFormData['id'] ?>">
                <?php endif; ?>
                <div class="col-12">
                  <label for="company-name" class="form-label">Firmenname *</label>
                  <input type="text" class="form-control" id="company-name" name="name" value="<?= htmlspecialchars((string) $companyFormData['name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="company-address-street" class="form-label">Straße &amp; Hausnummer</label>
                  <input type="text" class="form-control" id="company-address-street" name="address_street" value="<?= htmlspecialchars((string) $companyFormData['address_street']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="company-address-postal" class="form-label">PLZ</label>
                  <input type="text" class="form-control" id="company-address-postal" name="address_postal_code" value="<?= htmlspecialchars((string) $companyFormData['address_postal_code']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="company-address-city" class="form-label">Ort</label>
                  <input type="text" class="form-control" id="company-address-city" name="address_city" value="<?= htmlspecialchars((string) $companyFormData['address_city']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="company-address-country" class="form-label">Land</label>
                  <input type="text" class="form-control" id="company-address-country" name="address_country" value="<?= htmlspecialchars((string) $companyFormData['address_country']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="company-email" class="form-label">E-Mail</label>
                  <input type="email" class="form-control" id="company-email" name="email" value="<?= htmlspecialchars((string) $companyFormData['email']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label for="company-phone" class="form-label">Telefon</label>
                  <input type="text" class="form-control" id="company-phone" name="phone" value="<?= htmlspecialchars((string) $companyFormData['phone']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="company-tax-id" class="form-label">Steuernummer / USt-IdNr.</label>
                  <input type="text" class="form-control" id="company-tax-id" name="tax_id" value="<?= htmlspecialchars((string) $companyFormData['tax_id']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                </div>
                <div class="col-12">
                  <label for="company-notes" class="form-label">Notizen</label>
                  <textarea class="form-control" id="company-notes" name="notes" rows="2" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $companyFormData['notes']) ?></textarea>
                </div>
                <div class="col-12 d-flex justify-content-end align-items-center flex-wrap gap-2">
                  <?php if ($isEditingCompany): ?>
                    <a href="index.php?section=guests" class="btn btn-outline-secondary">Abbrechen</a>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingCompany ? 'Firma aktualisieren' : 'Firma speichern' ?></button>
                </div>
              </form>

              <?php if ($pdo === null): ?>
                <p class="text-muted mt-3 mb-0">Erstellen und Bearbeiten von Firmen ist ohne Datenbankverbindung nicht möglich.</p>
              <?php endif; ?>

              <?php if ($pdo !== null): ?>
                <div class="table-responsive mt-4">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Firma</th>
                        <th scope="col">Kontakt</th>
                        <th scope="col" class="text-end">Aktionen</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($companies as $company): ?>
                        <?php
                          $companyId = (int) $company['id'];
                          $assignedGuests = $companyGuestCounts[$companyId] ?? 0;
                          $contactLines = array_filter([
                              !empty($company['email']) ? htmlspecialchars($company['email']) : null,
                              !empty($company['phone']) ? htmlspecialchars($company['phone']) : null,
                          ]);
                          $addressLines = array_filter([
                              !empty($company['address_street']) ? htmlspecialchars($company['address_street']) : null,
                              trim((($company['address_postal_code'] ?? '') !== '' ? $company['address_postal_code'] . ' ' : '') . ($company['address_city'] ?? '')) !== ''
                                  ? htmlspecialchars(trim((($company['address_postal_code'] ?? '') !== '' ? $company['address_postal_code'] . ' ' : '') . ($company['address_city'] ?? '')))
                                  : null,
                              !empty($company['address_country']) ? htmlspecialchars($company['address_country']) : null,
                          ]);
                        ?>
                        <tr>
                          <td>
                            <div class="fw-semibold d-flex align-items-center gap-2">
                              <?= htmlspecialchars($company['name']) ?>
                              <?php if ($assignedGuests > 0): ?>
                                <span class="badge text-bg-secondary">Gäste: <?= $assignedGuests ?></span>
                              <?php endif; ?>
                            </div>
                            <?php if (!empty($company['tax_id'])): ?>
                              <div class="small text-muted">Steuer-ID: <?= htmlspecialchars($company['tax_id']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($company['notes'])): ?>
                              <div class="small text-muted mt-1"><?= nl2br(htmlspecialchars($company['notes'])) ?></div>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($contactLines !== []): ?>
                              <?php foreach ($contactLines as $contactLine): ?>
                                <div><?= $contactLine ?></div>
                              <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($addressLines !== []): ?>
                              <div class="mt-1">
                                <?php foreach ($addressLines as $addressLine): ?>
                                  <div><?= $addressLine ?></div>
                                <?php endforeach; ?>
                              </div>
                            <?php endif; ?>
                            <?php if ($contactLines === [] && $addressLines === []): ?>
                              <span class="text-muted">Keine Kontaktdaten</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                              <a class="btn btn-outline-secondary btn-sm" href="index.php?section=guests&editCompany=<?= (int) $company['id'] ?>">Bearbeiten</a>
                              <form method="post" onsubmit="return confirm('Firma wirklich löschen?');">
                                <input type="hidden" name="form" value="company_delete">
                                <input type="hidden" name="id" value="<?= (int) $company['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" <?= ($companyGuestCounts[$companyId] ?? 0) > 0 ? 'disabled title="Zuerst Gästezuordnungen entfernen"' : '' ?>>Löschen</button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (empty($companies)): ?>
                        <tr>
                          <td colspan="3" class="text-center text-muted py-3">Noch keine Firmen erfasst.</td>
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
      </section>
      <?php endif; ?>

      <?php if ($activeSection === 'rooms'): ?>
      <section id="rooms" class="app-section active">
        <?php $isEditingRoom = $roomFormData['id'] !== null; ?>
        <div class="row g-4">
          <div class="col-12 col-xxl-10">
            <div class="card module-card" id="room-management">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <h2 class="h5 mb-1">Zimmerverwaltung</h2>
                  <p class="text-muted mb-0"><?= $isEditingRoom ? 'Zimmerdetails bearbeiten und Status anpassen.' : 'Neue Zimmer mit Kategorie, Etage und Status hinterlegen.' ?></p>
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
                      <a href="index.php?section=rooms" class="btn btn-outline-secondary">Abbrechen</a>
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
                                <a class="btn btn-outline-secondary btn-sm" href="index.php?section=rooms&editRoom=<?= (int) $room['id'] ?>">Bearbeiten</a>
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
      </section>
      <?php endif; ?>

      <?php if ($activeSection === 'users'): ?>
      <section id="users" class="app-section active">
        <div class="row g-4">
          <div class="col-12 col-xxl-8">
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
                    <a href="index.php?section=users" class="btn btn-outline-secondary">Abbrechen</a>
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
                              <a class="btn btn-outline-secondary btn-sm" href="index.php?section=users&editUser=<?= (int) $user['id'] ?>">Bearbeiten</a>
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
      </section>
      <?php endif; ?>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
      document.querySelectorAll('#primaryNav .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
          var navbarCollapse = document.getElementById('primaryNav');
          if (!navbarCollapse || !navbarCollapse.classList.contains('show')) {
            return;
          }

          if (window.bootstrap && window.bootstrap.Collapse) {
            var collapse = window.bootstrap.Collapse.getInstance(navbarCollapse);
            if (collapse) {
              collapse.hide();
            }
          }
        });
      });

      document.querySelectorAll('.quick-action-menu a').forEach(function (link) {
        link.addEventListener('click', function () {
          var navbarCollapse = document.getElementById('primaryNav');
          if (!navbarCollapse || !navbarCollapse.classList.contains('show')) {
            return;
          }

          if (window.bootstrap && window.bootstrap.Collapse) {
            var collapse = window.bootstrap.Collapse.getInstance(navbarCollapse);
            if (collapse) {
              collapse.hide();
            }
          }
        });
      });
    </script>
  </body>
</html>
