<?php

use ModPMS\ArticleManager;
use ModPMS\BackupManager;
use ModPMS\Calendar;
use ModPMS\CompanyManager;
use ModPMS\Database;
use ModPMS\GuestManager;
use ModPMS\RateManager;
use ModPMS\ReservationManager;
use ModPMS\TaxCategoryManager;
use ModPMS\SettingManager;
use ModPMS\RoomCategoryManager;
use ModPMS\RoomManager;
use ModPMS\SystemUpdater;
use ModPMS\UserManager;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/CompanyManager.php';
require_once __DIR__ . '/../src/RoomCategoryManager.php';
require_once __DIR__ . '/../src/BackupManager.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/RoomManager.php';
require_once __DIR__ . '/../src/SystemUpdater.php';
require_once __DIR__ . '/../src/UserManager.php';
require_once __DIR__ . '/../src/GuestManager.php';
require_once __DIR__ . '/../src/ReservationManager.php';
require_once __DIR__ . '/../src/SettingManager.php';
require_once __DIR__ . '/../src/RateManager.php';
require_once __DIR__ . '/../src/TaxCategoryManager.php';
require_once __DIR__ . '/../src/ArticleManager.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserName = $_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? '');

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
    'sort_order' => '',
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

$reservationFormData = [
    'id' => null,
    'guest_id' => '',
    'guest_query' => '',
    'company_id' => '',
    'company_query' => '',
    'room_id' => '',
    'arrival_date' => '',
    'departure_date' => '',
    'night_count' => '',
    'status' => 'geplant',
    'notes' => '',
    'reservation_number' => '',
    'vat_rate' => '',
    'grand_total' => '',
    'category_items' => [
        [
            'category_id' => '',
            'room_quantity' => '1',
            'occupancy' => '1',
            'room_id' => '',
            'arrival_date' => '',
            'departure_date' => '',
            'rate_id' => '',
            'price_per_night' => '',
            'total_price' => '',
            'primary_guest_id' => '',
            'primary_guest_query' => '',
            'articles' => [],
        ],
    ],
];

$articleFormData = [
    'id' => null,
    'name' => '',
    'description' => '',
    'price' => '',
    'pricing_type' => ArticleManager::PRICING_PER_DAY,
    'tax_category_id' => '',
];

$taxCategoryFormData = [
    'id' => null,
    'name' => '',
    'rate' => '',
];
$reservationFormDefaults = $reservationFormData;
$reservationFormMode = 'create';
$isEditingReservation = false;

$rateFormData = [
    'id' => null,
    'name' => '',
    'description' => '',
    'category_prices' => [],
];
$rateFormMode = 'create';

$ratePeriodFormData = [
    'id' => null,
    'rate_id' => '',
    'start_date' => '',
    'end_date' => '',
    'days_of_week' => [],
    'category_prices' => [],
];
$ratePeriodFormMode = 'create';
$rateEventFormData = [
    'id' => null,
    'rate_id' => '',
    'name' => '',
    'start_date' => '',
    'end_date' => '',
    'default_price' => '',
    'color' => '#B91C1C',
    'description' => '',
    'category_prices' => [],
];
$rateEventFormMode = 'create';
$ratePeriodWeekdayOptions = [
    1 => 'Montag',
    2 => 'Dienstag',
    3 => 'Mittwoch',
    4 => 'Donnerstag',
    5 => 'Freitag',
    6 => 'Samstag',
    7 => 'Sonntag',
];

$requestedRateId = isset($_GET['rateId']) ? (int) $_GET['rateId'] : null;
if ($requestedRateId !== null && $requestedRateId <= 0) {
    $requestedRateId = null;
}

$requestedRateCategoryId = isset($_GET['rateCategoryId']) ? (int) $_GET['rateCategoryId'] : null;
if ($requestedRateCategoryId !== null && $requestedRateCategoryId <= 0) {
    $requestedRateCategoryId = null;
}

$currentYear = (int) date('Y');
$rateCalendarYear = $currentYear;
if (isset($_GET['rateYear'])) {
    $candidateYear = (int) $_GET['rateYear'];
    if ($candidateYear >= 2000 && $candidateYear <= 2100) {
        $rateCalendarYear = $candidateYear;
    }
}

$rateCalendarData = [
    'rate' => null,
    'category' => null,
    'year' => $rateCalendarYear,
    'months' => [],
];
$rateCalendarPrevUrl = 'index.php?section=rates';
$rateCalendarNextUrl = 'index.php?section=rates';
$rateCalendarResetUrl = 'index.php?section=rates';

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
    'reservations' => 'Reservierungen',
    'rates' => 'Raten',
    'articles' => 'Artikel',
    'categories' => 'Kategorien',
    'rooms' => 'Zimmer',
    'guests' => 'Gäste',
    'users' => 'Benutzer',
    'settings' => 'Einstellungen',
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
} elseif (isset($_GET['editReservation'])) {
    $activeSection = 'reservations';
} elseif (isset($_GET['editRate']) || isset($_GET['editRatePeriod']) || isset($_GET['editRateEvent'])) {
    $activeSection = 'rates';
} elseif (isset($_GET['editArticle'])) {
    $activeSection = 'articles';
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
$calendarDisplayToggleUrls = [];
$calendarPrevUrl = 'index.php?section=dashboard';
$calendarNextUrl = 'index.php?section=dashboard';
$calendarTodayUrl = 'index.php?section=dashboard';

$config = require __DIR__ . '/../config/app.php';
$dbError = null;
$categories = [];
$rooms = [];
$calendarCategoryGroups = [];
$guests = [];
$companies = [];
$companyGuestCounts = [];
$companyLookup = [];
$rates = [];
$ratePeriods = [];
$rateEvents = [];
$activeRate = null;
$activeRateId = null;
$activeRateCategoryId = null;
$articles = [];
$articlePricingTypes = ArticleManager::pricingTypes();
$taxCategories = [];
$taxCategoryLookup = [];
$articleLookup = [];
$users = [];
$reservations = [];
$roomLookup = [];
$guestLookup = [];
$roomOccupancies = [];
$categoryOverbookingOccupancies = [];
$categoryOverbookingStats = [];
$categoryLookup = [];
$pdo = null;
$categoryManager = null;
$roomManager = null;
$guestManager = null;
$companyManager = null;
$userManager = null;
$reservationManager = null;
$rateManager = null;
$backupManager = null;
$articleManager = null;
$taxCategoryManager = null;
$reservationCategoryOptionsHtml = '<option value="">Bitte auswählen</option>';
$articleTaxCategoryOptionsHtml = '<option value="">Bitte auswählen</option>';
$articleSelectOptionsHtml = '<option value="">Artikel wählen</option>';
$buildArticleSelectOptions = null;
$buildRoomSelectOptions = null;
$buildRateSelectOptions = null;
$reservationSearchTerm = isset($_GET['reservation_search']) ? trim((string) $_GET['reservation_search']) : '';
$showArchivedReservations = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';
$openReservationModalRequested = isset($_GET['openReservationModal']) && $_GET['openReservationModal'] === '1';
$settingsManager = null;
$reservationStatuses = ['geplant', 'eingecheckt', 'abgereist', 'bezahlt', 'noshow', 'storniert'];
$reservationStatusBaseMeta = [
    'geplant' => [
        'label' => 'Geplant',
        'badge' => 'text-bg-primary',
    ],
    'eingecheckt' => [
        'label' => 'Angereist',
        'badge' => 'text-bg-success',
    ],
    'abgereist' => [
        'label' => 'Abgereist',
        'badge' => 'text-bg-secondary',
    ],
    'bezahlt' => [
        'label' => 'Bezahlt',
        'badge' => 'text-bg-info',
    ],
    'noshow' => [
        'label' => 'No-Show',
        'badge' => 'text-bg-warning text-dark',
    ],
    'storniert' => [
        'label' => 'Storniert',
        'badge' => 'text-bg-danger',
    ],
];

$normalizeHexColor = static function (?string $value): ?string {
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if ($value[0] !== '#') {
        $value = '#' . $value;
    }

    if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $match) === 1) {
        $hex = strtoupper($match[1]);
        return sprintf('#%s%s%s', $hex[0] . $hex[0], $hex[1] . $hex[1], $hex[2] . $hex[2]);
    }

    if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $match) === 1) {
        return '#' . strtoupper($match[1]);
    }

    return null;
};

$calculateContrastColor = static function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = sprintf('%s%s%s', $hex[0] . $hex[0], $hex[1] . $hex[1], $hex[2] . $hex[2]);
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

    return $luminance > 0.6 ? '#111827' : '#ffffff';
};

$hexToRgba = static function (?string $hex, float $alpha) use ($normalizeHexColor): ?string {
    $normalized = $normalizeHexColor($hex);
    if ($normalized === null) {
        return null;
    }

    $alpha = max(0.0, min(1.0, $alpha));
    $hexValue = ltrim($normalized, '#');
    if (strlen($hexValue) !== 6) {
        return null;
    }

    $r = hexdec(substr($hexValue, 0, 2));
    $g = hexdec(substr($hexValue, 2, 2));
    $b = hexdec(substr($hexValue, 4, 2));

    return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);
};

$defaultReservationStatusColors = [
    'geplant' => '#2563EB',
    'eingecheckt' => '#16A34A',
    'abgereist' => '#6B7280',
    'bezahlt' => '#0EA5E9',
    'noshow' => '#F59E0B',
    'storniert' => '#DC2626',
];

$reservationStatusColors = $defaultReservationStatusColors;
$reservationStatusFormColors = $reservationStatusColors;
$reservationStatusMeta = [];
$reservationUserLookup = [];
$reservationGuestTooltip = '';
$reservationCompanyTooltip = '';

$calendarOccupancyDisplayOptions = [
    'company' => 'Firma',
    'guest' => 'Gastname',
];

$calendarOccupancyDisplay = $_SESSION['calendar_occupancy_display'] ?? 'company';
if (isset($_GET['occupancyDisplay'])) {
    $requestedDisplay = (string) $_GET['occupancyDisplay'];
    if (array_key_exists($requestedDisplay, $calendarOccupancyDisplayOptions)) {
        $calendarOccupancyDisplay = $requestedDisplay;
        $_SESSION['calendar_occupancy_display'] = $calendarOccupancyDisplay;
    }
}

if (!array_key_exists($calendarOccupancyDisplay, $calendarOccupancyDisplayOptions)) {
    $calendarOccupancyDisplay = 'company';
}

foreach ($calendarOccupancyDisplayOptions as $displayKey => $displayLabel) {
    $toggleParams = [
        'section' => 'dashboard',
        'occupancyDisplay' => $displayKey,
    ];

    if ($calendarCurrentDateValue !== '') {
        $toggleParams['date'] = $calendarCurrentDateValue;
    }

    $calendarDisplayToggleUrls[$displayKey] = 'index.php?' . http_build_query($toggleParams);
}

$calendarNavigationBaseParams = [
    'section' => 'dashboard',
];

if ($calendarOccupancyDisplay !== '') {
    $calendarNavigationBaseParams['occupancyDisplay'] = $calendarOccupancyDisplay;
}

$calendarPrevUrl = 'index.php?' . http_build_query(array_merge($calendarNavigationBaseParams, [
    'date' => $calendarPrevDate->format('Y-m-d'),
]));

$calendarNextUrl = 'index.php?' . http_build_query(array_merge($calendarNavigationBaseParams, [
    'date' => $calendarNextDate->format('Y-m-d'),
]));

$calendarTodayUrl = 'index.php?' . http_build_query($calendarNavigationBaseParams);

try {
    $pdo = Database::getConnection();
    $categoryManager = new RoomCategoryManager($pdo);
    $roomManager = new RoomManager($pdo);
    $guestManager = new GuestManager($pdo);
    $companyManager = new CompanyManager($pdo);
    $userManager = new UserManager($pdo);
    $reservationManager = new ReservationManager($pdo);
    $settingsManager = new SettingManager($pdo);
    $backupManager = new BackupManager($pdo);
    $rateManager = new RateManager($pdo);
    $taxCategoryManager = new TaxCategoryManager($pdo);
    $articleManager = new ArticleManager($pdo);
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$settingsAvailable = $settingsManager instanceof SettingManager && $pdo !== null;

if ($settingsManager instanceof SettingManager) {
    $keys = array_map(static fn (string $status): string => 'reservation_status_color_' . $status, $reservationStatuses);
    $storedColors = $settingsManager->getMany($keys);

    foreach ($reservationStatuses as $statusKey) {
        $colorKey = 'reservation_status_color_' . $statusKey;
        if (!isset($storedColors[$colorKey])) {
            continue;
        }

        $normalized = $normalizeHexColor($storedColors[$colorKey]);
        if ($normalized !== null) {
            $reservationStatusColors[$statusKey] = $normalized;
        }
    }
}

$reservationStatusFormColors = $reservationStatusColors;
$reservationStatusMeta = [];
foreach ($reservationStatuses as $statusKey) {
    $baseMeta = $reservationStatusBaseMeta[$statusKey] ?? [
        'label' => ucfirst($statusKey),
        'badge' => 'text-bg-secondary',
    ];

    $color = $reservationStatusColors[$statusKey] ?? ($defaultReservationStatusColors[$statusKey] ?? '#2563EB');

    $reservationStatusMeta[$statusKey] = $baseMeta;
    $reservationStatusMeta[$statusKey]['color'] = $color;
    $reservationStatusMeta[$statusKey]['textColor'] = $calculateContrastColor($color);
}

$rates = [];
$rateLookup = [];
$reservationRateOptionsHtml = '<option value="">Keine Rate verfügbar</option>';
if ($rateManager instanceof RateManager) {
    $rates = $rateManager->all();
    foreach ($rates as $rate) {
        if (!isset($rate['id'])) {
            continue;
        }

        $rateId = (int) $rate['id'];
        if ($rateId <= 0) {
            continue;
        }

        $rateLookup[$rateId] = $rate;
    }
}

$buildRateSelectOptions = static function (?int $selectedRateId) use ($rates): string {
    if ($rates === []) {
        return '<option value="">Keine Rate verfügbar</option>';
    }

    $options = ['<option value="">Bitte auswählen</option>'];
    $hasSelectable = false;

    foreach ($rates as $rate) {
        if (!isset($rate['id'])) {
            continue;
        }

        $rateId = (int) $rate['id'];
        if ($rateId <= 0) {
            continue;
        }

        $hasSelectable = true;
        $rateName = isset($rate['name']) ? (string) $rate['name'] : ('Rate #' . $rateId);
        $selectedAttr = $selectedRateId !== null && $rateId === $selectedRateId ? ' selected' : '';

        $options[] = sprintf(
            '<option value="%d"%s>%s</option>',
            $rateId,
            $selectedAttr,
            htmlspecialchars($rateName, ENT_QUOTES, 'UTF-8')
        );
    }

    if (!$hasSelectable) {
        return '<option value="">Keine Rate verfügbar</option>';
    }

    return implode('', $options);
};

$reservationRateOptionsHtml = $buildRateSelectOptions(null);

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

$normalizeMoneyInput = static function (?string $value): ?float {
    if ($value === null) {
        return null;
    }

    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return null;
    }

    $normalized = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', $trimmed));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    $floatValue = round((float) $normalized, 2);
    if ($floatValue < 0) {
        return null;
    }

    return $floatValue;
};

$formatCurrency = static function (?float $value): ?string {
    if ($value === null) {
        return null;
    }

    return number_format($value, 2, ',', '.') . ' €';
};

$formatPercent = static function (?float $value): ?string {
    if ($value === null) {
        return null;
    }

    return number_format($value, 2, ',', '.') . ' %';
};

$overnightVatRate = 7.0;
if ($settingsManager instanceof SettingManager) {
    $storedVat = $settingsManager->get('overnight_vat_rate');
    if ($storedVat !== null && $storedVat !== '') {
        $parsedVat = $normalizeMoneyInput($storedVat);
        if ($parsedVat !== null) {
            $overnightVatRate = $parsedVat;
        }
    }
}
$overnightVatRateValue = number_format($overnightVatRate, 2, '.', '');
$overnightVatRateLabel = $formatPercent($overnightVatRate);

$computeReservationPricing = static function (?RateManager $rateManager, array $categoryItems): array {
    $overallArrival = null;
    $overallDeparture = null;
    $overallTotal = 0.0;
    $itemResults = [];

    foreach ($categoryItems as $item) {
        $categoryId = isset($item['category_id']) ? (int) $item['category_id'] : 0;
        if ($categoryId <= 0) {
            continue;
        }

        $quantity = isset($item['room_quantity']) ? (int) $item['room_quantity'] : 1;
        if ($quantity <= 0) {
            $quantity = 1;
        }

        $itemArrival = null;
        if (isset($item['arrival_date']) && $item['arrival_date'] !== null && $item['arrival_date'] !== '') {
            try {
                $itemArrival = new DateTimeImmutable((string) $item['arrival_date']);
            } catch (Throwable $exception) {
                $itemArrival = null;
            }
        }

        $itemDeparture = null;
        if (isset($item['departure_date']) && $item['departure_date'] !== null && $item['departure_date'] !== '') {
            try {
                $itemDeparture = new DateTimeImmutable((string) $item['departure_date']);
            } catch (Throwable $exception) {
                $itemDeparture = null;
            }
        }

        if ($itemArrival !== null && $itemDeparture !== null && $itemDeparture <= $itemArrival) {
            $itemDeparture = $itemArrival->modify('+1 day');
        }

        if ($itemArrival !== null && ($overallArrival === null || $itemArrival < $overallArrival)) {
            $overallArrival = $itemArrival;
        }

        if ($itemDeparture !== null && ($overallDeparture === null || $itemDeparture > $overallDeparture)) {
            $overallDeparture = $itemDeparture;
        }

        $itemNights = null;
        if ($itemArrival !== null && $itemDeparture !== null) {
            $diff = $itemArrival->diff($itemDeparture);
            $itemNights = (int) $diff->days;
            if ($diff->invert === 1 || $itemNights <= 0) {
                $itemNights = 1;
            }
        }

        $rateId = isset($item['rate_id']) ? (int) $item['rate_id'] : 0;
        if ($rateId <= 0) {
            $rateId = 0;
        }

        $itemTotal = null;
        $itemPricePerNight = null;

        if ($rateManager instanceof RateManager && $rateId > 0 && $itemArrival instanceof DateTimeImmutable && $itemDeparture instanceof DateTimeImmutable) {
            $nightlyEntries = $rateManager->nightlyPrices($rateId, $categoryId, $itemArrival, $itemDeparture);
            if ($nightlyEntries !== []) {
                $calculatedTotal = 0.0;
                foreach ($nightlyEntries as $entry) {
                    $priceValue = isset($entry['price']) ? (float) $entry['price'] : 0.0;
                    $calculatedTotal += $priceValue * $quantity;
                }

                $itemTotal = round($calculatedTotal, 2);
                if ($itemNights !== null && $itemNights > 0) {
                    $itemPricePerNight = round($calculatedTotal / $itemNights, 2);
                }

                $overallTotal += $itemTotal;
            }
        }

        $itemResults[] = [
            'index' => isset($item['index']) ? (int) $item['index'] : null,
            'category_id' => $categoryId,
            'room_quantity' => $quantity,
            'arrival_date' => $itemArrival instanceof DateTimeImmutable ? $itemArrival->format('Y-m-d') : null,
            'departure_date' => $itemDeparture instanceof DateTimeImmutable ? $itemDeparture->format('Y-m-d') : null,
            'nights' => $itemNights,
            'rate_id' => $rateId > 0 ? $rateId : null,
            'price_per_night' => $itemPricePerNight,
            'total_price' => $itemTotal,
        ];
    }

    $nightCount = null;
    if ($overallArrival instanceof DateTimeImmutable && $overallDeparture instanceof DateTimeImmutable) {
        $diff = $overallArrival->diff($overallDeparture);
        $nightCount = (int) $diff->days;
        if ($diff->invert === 1 || $nightCount <= 0) {
            $nightCount = 1;
        }
    }

    $averagePerNight = null;
    if ($nightCount !== null && $nightCount > 0 && $overallTotal > 0) {
        $averagePerNight = round($overallTotal / $nightCount, 2);
    }

    return [
        'arrival_date' => $overallArrival instanceof DateTimeImmutable ? $overallArrival->format('Y-m-d') : null,
        'departure_date' => $overallDeparture instanceof DateTimeImmutable ? $overallDeparture->format('Y-m-d') : null,
        'nights' => $nightCount,
        'price_per_night' => $averagePerNight,
        'total_price' => $overallTotal > 0 ? round($overallTotal, 2) : null,
        'items' => $itemResults,
    ];
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

$buildGuestCalendarLabel = static function (array $guest) use ($calendarOccupancyDisplay): string {
    $companyName = isset($guest['company_name']) ? trim((string) $guest['company_name']) : '';
    $lastName = isset($guest['last_name']) ? trim((string) $guest['last_name']) : '';
    $firstName = isset($guest['first_name']) ? trim((string) $guest['first_name']) : '';

    $nameLabel = '';
    if ($lastName !== '' && $firstName !== '') {
        $initial = function_exists('mb_substr') ? mb_substr($firstName, 0, 1) : substr($firstName, 0, 1);
        $nameLabel = sprintf('%s %s.', $lastName, strtoupper((string) $initial));
    } elseif ($lastName !== '') {
        $nameLabel = $lastName;
    } elseif ($firstName !== '') {
        $nameLabel = $firstName;
    }

    if ($calendarOccupancyDisplay === 'company' && $companyName !== '') {
        return $companyName;
    }

    if ($nameLabel !== '') {
        return $nameLabel;
    }

    return $companyName !== '' ? $companyName : 'Gast';
};

$buildGuestReservationLabel = static function (array $guest): string {
    $companyName = isset($guest['company_name']) ? trim((string) $guest['company_name']) : '';
    $lastName = isset($guest['last_name']) ? trim((string) $guest['last_name']) : '';
    $firstName = isset($guest['first_name']) ? trim((string) $guest['first_name']) : '';

    $nameParts = array_filter([$firstName, $lastName], static fn ($value) => $value !== '');
    $fullName = implode(' ', $nameParts);

    if ($companyName !== '' && $fullName !== '') {
        return sprintf('%s – %s', $companyName, $fullName);
    }

    if ($companyName !== '') {
        return $companyName;
    }

    if ($fullName !== '') {
        return $fullName;
    }

    if (isset($guest['id'])) {
        return 'Gast #' . (int) $guest['id'];
    }

    return 'Gast';
};

$buildAddressLabel = static function (array $record): string {
    $street = isset($record['address_street']) ? trim((string) $record['address_street']) : '';
    $postal = isset($record['address_postal_code']) ? trim((string) $record['address_postal_code']) : '';
    $city = isset($record['address_city']) ? trim((string) $record['address_city']) : '';
    $country = isset($record['address_country']) ? trim((string) $record['address_country']) : '';

    $parts = [];
    if ($street !== '') {
        $parts[] = $street;
    }

    $cityLineParts = array_filter([$postal, $city], static fn ($value) => $value !== '');
    if ($cityLineParts !== []) {
        $parts[] = implode(' ', $cityLineParts);
    }

    if ($country !== '') {
        $parts[] = $country;
    }

    return $parts !== [] ? implode(', ', $parts) : '';
};

$buildCompanyReservationLabel = static function (array $company): string {
    $name = '';
    if (isset($company['name'])) {
        $name = trim((string) $company['name']);
    } elseif (isset($company['company_name'])) {
        $name = trim((string) $company['company_name']);
    }

    if ($name !== '') {
        return $name;
    }

    if (isset($company['id'])) {
        return 'Firma #' . (int) $company['id'];
    }

    return 'Firma';
};

if ($pdo !== null && isset($_GET['ajax'])) {
    $ajaxAction = (string) $_GET['ajax'];
    $term = isset($_GET['term']) ? trim((string) $_GET['term']) : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($ajaxAction === 'guest_search' && $guestManager instanceof GuestManager) {
            $results = [];
            foreach ($guestManager->search($term, $limit) as $guest) {
                $guest['company_name'] = $guest['company_name'] ?? '';
                $label = $buildGuestReservationLabel($guest);
                $address = $buildAddressLabel($guest);

                $company = null;
                if (!empty($guest['company_id'])) {
                    $company = [
                        'id' => (int) $guest['company_id'],
                        'label' => $buildCompanyReservationLabel([
                            'id' => $guest['company_id'],
                            'name' => $guest['company_name'] ?? '',
                        ]),
                        'address' => $buildAddressLabel([
                            'address_street' => $guest['company_address_street'] ?? '',
                            'address_postal_code' => $guest['company_address_postal_code'] ?? '',
                            'address_city' => $guest['company_address_city'] ?? '',
                            'address_country' => $guest['company_address_country'] ?? '',
                        ]),
                    ];
                }

                $results[] = [
                    'id' => (int) $guest['id'],
                    'label' => $label,
                    'address' => $address,
                    'company' => $company,
                ];
            }

            echo json_encode(['items' => $results], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($ajaxAction === 'company_search' && $companyManager instanceof CompanyManager) {
            $results = [];
            foreach ($companyManager->search($term, $limit) as $company) {
                $results[] = [
                    'id' => (int) $company['id'],
                    'label' => $buildCompanyReservationLabel($company),
                    'address' => $buildAddressLabel($company),
                ];
            }

            echo json_encode(['items' => $results], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($ajaxAction === 'available_rooms' && $reservationManager instanceof ReservationManager) {
            $categoryId = isset($_GET['categoryId']) ? (int) $_GET['categoryId'] : 0;
            $arrivalInput = isset($_GET['arrivalDate']) ? (string) $_GET['arrivalDate'] : '';
            $departureInput = isset($_GET['departureDate']) ? (string) $_GET['departureDate'] : '';
            $ignoreIdInput = isset($_GET['ignoreReservationId']) ? (string) $_GET['ignoreReservationId'] : '';

            $normalizedArrival = $normalizeDateInput($arrivalInput);
            $normalizedDeparture = $normalizeDateInput($departureInput);

            if ($categoryId <= 0 || $normalizedArrival === null || $normalizedDeparture === null) {
                echo json_encode(['rooms' => []], JSON_THROW_ON_ERROR);
                exit;
            }

            $arrivalDate = new DateTimeImmutable($normalizedArrival);
            $departureDate = new DateTimeImmutable($normalizedDeparture);

            $ignoreReservationId = null;
            if ($ignoreIdInput !== '') {
                $candidate = (int) $ignoreIdInput;
                if ($candidate > 0) {
                    $ignoreReservationId = $candidate;
                }
            }

            $rooms = $reservationManager->findAvailableRooms($categoryId, $arrivalDate, $departureDate, $ignoreReservationId);

            $response = [];
            foreach ($rooms as $room) {
                if (!isset($room['id'])) {
                    continue;
                }

                $roomId = (int) $room['id'];
                $roomNumber = isset($room['room_number']) ? trim((string) $room['room_number']) : '';
                if ($roomNumber === '' && isset($room['number'])) {
                    $roomNumber = trim((string) $room['number']);
                }

                if ($roomNumber === '') {
                    $roomNumber = 'Zimmer ' . $roomId;
                }

                $response[] = [
                    'id' => $roomId,
                    'label' => $roomNumber,
                ];
            }

            echo json_encode(['rooms' => $response], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($ajaxAction === 'rate_quote' && $rateManager instanceof RateManager) {
            $rawBody = file_get_contents('php://input');
            $payload = [];
            if ($rawBody !== false && $rawBody !== '') {
                $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            }

            $itemsInput = [];
            if (isset($payload['items']) && is_array($payload['items'])) {
                $itemsInput = $payload['items'];
            } elseif (isset($payload['categories']) && isset($payload['rateId'])) {
                // backwards compatibility for older requests
                $rateId = (int) $payload['rateId'];
                $arrivalInput = isset($payload['arrivalDate']) ? (string) $payload['arrivalDate'] : '';
                $departureInput = isset($payload['departureDate']) ? (string) $payload['departureDate'] : '';
                $normalizedArrival = $normalizeDateInput($arrivalInput);
                $normalizedDeparture = $normalizeDateInput($departureInput);
                if ($normalizedArrival !== null && $normalizedDeparture !== null && isset($payload['categories']) && is_array($payload['categories'])) {
                    foreach ($payload['categories'] as $categoryPayload) {
                        if (!is_array($categoryPayload)) {
                            continue;
                        }

                        $categoryId = isset($categoryPayload['category_id']) ? (int) $categoryPayload['category_id'] : (isset($categoryPayload['categoryId']) ? (int) $categoryPayload['categoryId'] : 0);
                        if ($categoryId <= 0) {
                            continue;
                        }

                        $quantity = isset($categoryPayload['room_quantity']) ? (int) $categoryPayload['room_quantity'] : (isset($categoryPayload['quantity']) ? (int) $categoryPayload['quantity'] : 1);
                        if ($quantity <= 0) {
                            $quantity = 1;
                        }

                        $itemsInput[] = [
                            'categoryId' => $categoryId,
                            'rateId' => $rateId,
                            'quantity' => $quantity,
                            'arrivalDate' => $normalizedArrival,
                            'departureDate' => $normalizedDeparture,
                        ];
                    }
                }
            }

            if ($itemsInput === []) {
                echo json_encode(['success' => false, 'error' => 'Keine gültigen Positionen übermittelt.'], JSON_THROW_ON_ERROR);
                exit;
            }

            $responseItems = [];
            foreach ($itemsInput as $index => $itemPayload) {
                if (!is_array($itemPayload)) {
                    continue;
                }

                $categoryId = isset($itemPayload['categoryId']) ? (int) $itemPayload['categoryId'] : (isset($itemPayload['category_id']) ? (int) $itemPayload['category_id'] : 0);
                $rateId = isset($itemPayload['rateId']) ? (int) $itemPayload['rateId'] : (isset($itemPayload['rate_id']) ? (int) $itemPayload['rate_id'] : 0);
                $quantity = isset($itemPayload['quantity']) ? (int) $itemPayload['quantity'] : (isset($itemPayload['room_quantity']) ? (int) $itemPayload['room_quantity'] : 1);
                $arrivalInput = isset($itemPayload['arrivalDate']) ? (string) $itemPayload['arrivalDate'] : (isset($itemPayload['arrival_date']) ? (string) $itemPayload['arrival_date'] : '');
                $departureInput = isset($itemPayload['departureDate']) ? (string) $itemPayload['departureDate'] : (isset($itemPayload['departure_date']) ? (string) $itemPayload['departure_date'] : '');

                $normalizedArrival = $normalizeDateInput($arrivalInput);
                $normalizedDeparture = $normalizeDateInput($departureInput);

                if ($categoryId <= 0 || $rateId <= 0 || !isset($rateLookup[$rateId]) || $normalizedArrival === null || $normalizedDeparture === null) {
                    continue;
                }

                if ($quantity <= 0) {
                    $quantity = 1;
                }

                try {
                    $pricing = $computeReservationPricing($rateManager, [[
                        'index' => 0,
                        'category_id' => $categoryId,
                        'room_quantity' => $quantity,
                        'arrival_date' => $normalizedArrival,
                        'departure_date' => $normalizedDeparture,
                        'rate_id' => $rateId,
                    ]]);

                    $itemResult = $pricing['items'][0] ?? null;
                    if ($itemResult !== null) {
                        $responseItems[] = [
                            'index' => $index,
                            'price_per_night' => $itemResult['price_per_night'],
                            'total_price' => $itemResult['total_price'],
                        ];
                    }
                } catch (Throwable $exception) {
                    continue;
                }
            }

            if ($responseItems === []) {
                echo json_encode(['success' => false, 'error' => 'Berechnung nicht möglich.'], JSON_THROW_ON_ERROR);
                exit;
            }

            echo json_encode([
                'success' => true,
                'items' => $responseItems,
            ], JSON_THROW_ON_ERROR);
            exit;
        }
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'items' => [],
            'error' => $exception->getMessage(),
        ]);
        exit;
    }

    echo json_encode(['items' => []]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form']) && $_POST['form'] === 'settings_clear_cache') {
    $activeSection = 'settings';

    header('Clear-Site-Data: "cache"');

    $_SESSION['alert'] = [
        'type' => 'success',
        'message' => 'Browser-Cache wurde geleert. Bitte laden Sie die Seite neu, falls Inhalte noch zwischengespeichert erscheinen.',
    ];

    header('Location: index.php?section=settings#cache-tools');
    exit;
}

if ($pdo !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form'])) {
    $form = $_POST['form'];

    switch ($form) {
        case 'settings_schema_refresh':
            $activeSection = 'settings';

            if ($pdo === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Keine Datenbankverbindung vorhanden.',
                ];
                break;
            }

            try {
                $updatedComponents = [];

                if ($settingsManager instanceof SettingManager) {
                    $settingsManager->ensureSchema();
                    $updatedComponents[] = 'Einstellungen';
                }

                if ($reservationManager instanceof ReservationManager) {
                    if (method_exists($reservationManager, 'refreshSchema')) {
                        $reservationManager->refreshSchema();
                    }
                    $updatedComponents[] = 'Reservierungen';
                }

                if ($rateManager instanceof RateManager) {
                    if (method_exists($rateManager, 'refreshSchema')) {
                        $rateManager->refreshSchema();
                    }
                    $updatedComponents[] = 'Raten';
                }

                if ($categoryManager instanceof RoomCategoryManager) {
                    if (method_exists($categoryManager, 'refreshSchema')) {
                        $categoryManager->refreshSchema();
                    }
                    $updatedComponents[] = 'Kategorien';
                }

                if ($guestManager instanceof GuestManager) {
                    if (method_exists($guestManager, 'refreshSchema')) {
                        $guestManager->refreshSchema();
                    }
                    $updatedComponents[] = 'Gäste';
                }

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Datenbanktabellen wurden aktualisiert' . ($updatedComponents !== [] ? ': ' . implode(', ', $updatedComponents) . '.' : '.'),
                ];

                header('Location: index.php?section=settings#database-maintenance');
                exit;
            } catch (Throwable $exception) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Aktualisierung fehlgeschlagen: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'),
                ];
            }

            break;

        case 'settings_vat':
            $activeSection = 'settings';

            if (!$settingsAvailable || !$settingsManager instanceof SettingManager) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Einstellungen können ohne Datenbankverbindung nicht gespeichert werden.',
                ];
                break;
            }

            $vatInput = trim((string) ($_POST['overnight_vat_rate'] ?? ''));
            $vatValue = $normalizeMoneyInput($vatInput);

            if ($vatValue === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte einen gültigen Prozentsatz für die Mehrwertsteuer angeben.',
                ];
                break;
            }

            if ($vatValue < 0 || $vatValue > 100) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Mehrwertsteuersatz muss zwischen 0 und 100 liegen.',
                ];
                break;
            }

            $settingsManager->set('overnight_vat_rate', number_format($vatValue, 2, '.', ''));

            $overnightVatRate = $vatValue;
            $overnightVatRateValue = number_format($overnightVatRate, 2, '.', '');
            $overnightVatRateLabel = $formatPercent($overnightVatRate);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Mehrwertsteuersatz für Übernachtungen wurde aktualisiert.',
            ];

            header('Location: index.php?section=settings#vat-settings');
            exit;

        case 'settings_status_colors':
            $activeSection = 'settings';

            if (!$settingsAvailable) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Datenbankverbindung für Einstellungen konnte nicht hergestellt werden.',
                ];
                break;
            }

            $colorInputs = isset($_POST['status_colors']) && is_array($_POST['status_colors']) ? $_POST['status_colors'] : [];
            foreach ($reservationStatuses as $statusKey) {
                if (isset($colorInputs[$statusKey])) {
                    $reservationStatusFormColors[$statusKey] = (string) $colorInputs[$statusKey];
                }
            }
            $invalidStatuses = [];
            $normalizedColors = [];

            foreach ($reservationStatuses as $statusKey) {
                $label = $reservationStatusMeta[$statusKey]['label'] ?? ucfirst($statusKey);
                $inputValue = $colorInputs[$statusKey] ?? ($reservationStatusColors[$statusKey] ?? '');
                $normalized = $normalizeHexColor($inputValue);

                if ($normalized === null) {
                    $invalidStatuses[] = $label;
                    continue;
                }

                $normalizedColors[$statusKey] = $normalized;
            }

            if ($invalidStatuses !== []) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie gültige Hex-Farbwerte (z. B. #2563EB) für folgende Status ein: ' . implode(', ', $invalidStatuses),
                ];
                break;
            }

            foreach ($normalizedColors as $statusKey => $colorValue) {
                $settingsManager->set('reservation_status_color_' . $statusKey, $colorValue);
                $reservationStatusColors[$statusKey] = $colorValue;
                $reservationStatusFormColors[$statusKey] = $colorValue;
                if (isset($reservationStatusMeta[$statusKey])) {
                    $reservationStatusMeta[$statusKey]['color'] = $colorValue;
                    $reservationStatusMeta[$statusKey]['textColor'] = $calculateContrastColor($colorValue);
                }
            }

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Statusfarben wurden gespeichert.',
            ];

            header('Location: index.php?section=settings');
            exit;

        case 'settings_backup_export':
            $activeSection = 'settings';

            if ($pdo === null || !$backupManager instanceof BackupManager) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Keine Datenbankverbindung vorhanden. Export nicht möglich.',
                ];
                break;
            }

            try {
                $exportPayload = $backupManager->export();
                $json = json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $filename = sprintf('modpms-backup-%s.json', date('Ymd-His'));

                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            } catch (Throwable $exception) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Export fehlgeschlagen: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'),
                ];
            }

            break;

        case 'settings_backup_import':
            $activeSection = 'settings';

            if ($pdo === null || !$backupManager instanceof BackupManager) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Keine Datenbankverbindung vorhanden. Import nicht möglich.',
                ];
                break;
            }

            if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Keine Sicherungsdatei hochgeladen.',
                ];
                break;
            }

            $fileInfo = $_FILES['backup_file'];

            if (($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Sicherungsdatei konnte nicht hochgeladen werden (Fehlercode ' . (int) ($fileInfo['error'] ?? 0) . ').',
                ];
                break;
            }

            $tmpPath = $fileInfo['tmp_name'] ?? '';

            if (!is_string($tmpPath) || $tmpPath === '' || !is_readable($tmpPath)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die hochgeladene Datei ist nicht lesbar.',
                ];
                break;
            }

            $fileSize = filesize($tmpPath);
            if ($fileSize !== false && $fileSize > 5 * 1024 * 1024) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Sicherungsdatei überschreitet die maximale Größe von 5 MB.',
                ];
                break;
            }

            try {
                $contents = file_get_contents($tmpPath);
                if ($contents === false) {
                    throw new RuntimeException('Die Sicherungsdatei konnte nicht gelesen werden.');
                }

                $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $importCounts = $backupManager->restore($payload);

                if ($categoryManager instanceof RoomCategoryManager && method_exists($categoryManager, 'refreshSchema')) {
                    $categoryManager->refreshSchema();
                }

                if ($guestManager instanceof GuestManager && method_exists($guestManager, 'refreshSchema')) {
                    $guestManager->refreshSchema();
                }

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Sicherung wurde eingespielt. Importierte Datensätze – Kategorien: ' . (int) ($importCounts['room_categories'] ?? 0)
                        . ', Zimmer: ' . (int) ($importCounts['rooms'] ?? 0)
                        . ', Firmen: ' . (int) ($importCounts['companies'] ?? 0)
                        . ', Gäste: ' . (int) ($importCounts['guests'] ?? 0) . '.',
                ];

                header('Location: index.php?section=settings#database-backups');
                exit;
            } catch (Throwable $exception) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Import fehlgeschlagen: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'),
                ];
            }

            break;

        case 'rate_create':
        case 'rate_update':
            $activeSection = 'rates';
            $rateFormMode = $form === 'rate_update' ? 'update' : 'create';

            if ($rateManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $categoryPricesInput = isset($_POST['category_prices']) && is_array($_POST['category_prices']) ? $_POST['category_prices'] : [];
            $activeRateCategoryIdInput = null;
            if (isset($_POST['active_rate_category_id'])) {
                $candidate = (int) $_POST['active_rate_category_id'];
                if ($candidate > 0) {
                    $activeRateCategoryIdInput = $candidate;
                }
            }

            $rateFormData = [
                'id' => $form === 'rate_update' ? (int) ($_POST['id'] ?? 0) : null,
                'name' => $name,
                'description' => $description,
                'category_prices' => [],
            ];

            $availableCategories = [];
            if ($categoryManager instanceof RoomCategoryManager) {
                $availableCategories = $categoryManager->all();
            }

            if ($availableCategories === []) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte legen Sie zunächst Zimmerkategorien an, bevor Sie Raten verwalten.',
                ];
                break;
            }

            if ($name === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Namen für die Rate ein.',
                ];
                break;
            }

            $normalizedCategoryPrices = [];
            $expectedCategoryIds = [];
            $priceValidationError = null;

            foreach ($availableCategories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $categoryId = (int) $category['id'];
                if ($categoryId <= 0) {
                    continue;
                }

                $expectedCategoryIds[] = $categoryId;

                $rawValue = isset($categoryPricesInput[$categoryId]) ? trim((string) $categoryPricesInput[$categoryId]) : '';
                $rateFormData['category_prices'][$categoryId] = $rawValue;

                if ($rawValue === '') {
                    $priceValidationError = sprintf(
                        'Bitte geben Sie einen Preis für die Kategorie &quot;%s&quot; an.',
                        htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                    );
                    break;
                }

                $normalizedInput = str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $rawValue));
                if ($normalizedInput === '' || !is_numeric($normalizedInput)) {
                    $priceValidationError = sprintf(
                        'Der Preis für die Kategorie &quot;%s&quot; ist ungültig.',
                        htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                    );
                    break;
                }

                $priceValue = round((float) $normalizedInput, 2);
                if ($priceValue < 0) {
                    $priceValidationError = sprintf(
                        'Der Preis für die Kategorie &quot;%s&quot; darf nicht negativ sein.',
                        htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                    );
                    break;
                }

                $normalizedCategoryPrices[$categoryId] = number_format($priceValue, 2, '.', '');
            }

            if ($priceValidationError !== null) {
                $alert = [
                    'type' => 'danger',
                    'message' => $priceValidationError,
                ];
                break;
            }

            if ($normalizedCategoryPrices === []) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Mindestens eine Kategorie muss einen gültigen Preis erhalten.',
                ];
                break;
            }

            $primaryCategoryId = null;
            if ($availableCategories !== []) {
                $primaryCategoryId = isset($availableCategories[0]['id']) ? (int) $availableCategories[0]['id'] : null;
            }

            $redirectCategoryId = null;
            if (
                $activeRateCategoryIdInput !== null
                && in_array($activeRateCategoryIdInput, $expectedCategoryIds, true)
            ) {
                $redirectCategoryId = $activeRateCategoryIdInput;
            } elseif ($primaryCategoryId !== null && in_array($primaryCategoryId, $expectedCategoryIds, true)) {
                $redirectCategoryId = $primaryCategoryId;
            } elseif ($expectedCategoryIds !== []) {
                $redirectCategoryId = $expectedCategoryIds[0];
            }

            $defaultBasePrice = null;
            if ($primaryCategoryId !== null && isset($normalizedCategoryPrices[$primaryCategoryId])) {
                $defaultBasePrice = $normalizedCategoryPrices[$primaryCategoryId];
            } else {
                $firstPrice = reset($normalizedCategoryPrices);
                if ($firstPrice !== false) {
                    $defaultBasePrice = $firstPrice;
                }
            }

            if ($defaultBasePrice === null) {
                $defaultBasePrice = '0.00';
            }

            $baseCategoryId = $redirectCategoryId;
            if ($baseCategoryId === null) {
                if ($primaryCategoryId !== null) {
                    $baseCategoryId = $primaryCategoryId;
                } elseif ($expectedCategoryIds !== []) {
                    $baseCategoryId = $expectedCategoryIds[0];
                }
            }

            if ($form === 'rate_create') {
                $payload = [
                    'name' => $name,
                    'category_id' => $baseCategoryId,
                    'base_price' => $defaultBasePrice,
                    'description' => $description !== '' ? $description : null,
                    'created_by' => $currentUserId > 0 ? $currentUserId : null,
                    'updated_by' => $currentUserId > 0 ? $currentUserId : null,
                ];

                $newRateId = $rateManager->create($payload);

                if ($newRateId > 0) {
                    $rateManager->syncCategoryPrices($newRateId, $normalizedCategoryPrices, $expectedCategoryIds);
                }

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Rate "%s" wurde angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                ];

                $redirectParams = ['section' => 'rates'];
                if ($newRateId > 0) {
                    $redirectParams['rateId'] = $newRateId;
                    if ($redirectCategoryId !== null) {
                        $redirectParams['rateCategoryId'] = $redirectCategoryId;
                    }
                }

                header('Location: index.php?' . http_build_query($redirectParams));
                exit;
            }

            $rateId = (int) ($_POST['id'] ?? 0);
            if ($rateId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Rate konnte nicht aktualisiert werden, da keine gültige ID angegeben wurde.',
                ];
                break;
            }

            $existingRate = $rateManager->find($rateId);
            if ($existingRate === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Rate wurde nicht gefunden.',
                ];
                break;
            }

            $payload = [
                'name' => $name,
                'category_id' => $baseCategoryId,
                'base_price' => $defaultBasePrice,
                'description' => $description !== '' ? $description : null,
                'updated_by' => $currentUserId > 0 ? $currentUserId : null,
            ];

            $rateManager->update($rateId, $payload);
            $rateManager->syncCategoryPrices($rateId, $normalizedCategoryPrices, $expectedCategoryIds);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Rate "%s" wurde aktualisiert.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
            ];

            $redirectParams = ['section' => 'rates', 'rateId' => $rateId];
            if ($redirectCategoryId !== null) {
                $redirectParams['rateCategoryId'] = $redirectCategoryId;
            }

            header('Location: index.php?' . http_build_query($redirectParams));
            exit;

        case 'rate_delete':
            $activeSection = 'rates';

            if ($rateManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $rateId = (int) ($_POST['id'] ?? 0);
            if ($rateId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Rate konnte nicht gelöscht werden, da keine gültige ID übermittelt wurde.',
                ];
                break;
            }

            $existingRate = $rateManager->find($rateId);
            if ($existingRate === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Rate wurde nicht gefunden.',
                ];
                break;
            }

            $rateManager->delete($rateId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Rate "%s" wurde gelöscht.', htmlspecialchars((string) $existingRate['name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=rates');
            exit;

        case 'rate_period_create':
        case 'rate_period_update':
            $activeSection = 'rates';
            $ratePeriodFormMode = $form === 'rate_period_update' ? 'update' : 'create';

            if ($rateManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $rateIdInput = trim((string) ($_POST['rate_id'] ?? ''));
            $startInput = trim((string) ($_POST['start_date'] ?? ''));
            $endInput = trim((string) ($_POST['end_date'] ?? ''));
            $daysInput = isset($_POST['days_of_week']) && is_array($_POST['days_of_week']) ? $_POST['days_of_week'] : [];
            $periodCategoryInputs = isset($_POST['period_category_prices']) && is_array($_POST['period_category_prices'])
                ? $_POST['period_category_prices']
                : [];
            $activeRateCategoryIdInput = null;
            if (isset($_POST['active_rate_category_id'])) {
                $candidate = (int) $_POST['active_rate_category_id'];
                if ($candidate > 0) {
                    $activeRateCategoryIdInput = $candidate;
                }
            }

            $ratePeriodFormData = [
                'id' => $form === 'rate_period_update' ? (int) ($_POST['id'] ?? 0) : null,
                'rate_id' => $rateIdInput,
                'start_date' => $startInput,
                'end_date' => $endInput,
                'days_of_week' => array_map('intval', $daysInput),
                'category_prices' => [],
            ];

            $rateId = (int) $rateIdInput;
            if ($rateId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte wählen Sie eine Rate aus.',
                ];
                break;
            }

            $rate = $rateManager->find($rateId);
            if ($rate === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Rate wurde nicht gefunden.',
                ];
                break;
            }

            $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $startInput) ?: DateTimeImmutable::createFromFormat('d.m.Y', $startInput);
            $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $endInput) ?: DateTimeImmutable::createFromFormat('d.m.Y', $endInput);

            if (!$startDate || !$endDate) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie gültige Start- und Enddaten ein (Format JJJJ-MM-TT).',
                ];
                break;
            }

            if ($endDate < $startDate) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
                ];
                break;
            }

            $daysOfWeek = $rateManager->normaliseDaysOfWeek($daysInput);

            $periodCategoryPrices = [];
            $categoryPriceRemovals = [];
            $periodPriceValidationError = null;

            $availableCategories = [];
            $availableCategoryIds = [];
            if ($categoryManager instanceof RoomCategoryManager) {
                $availableCategories = $categoryManager->all();
            }

            foreach ($availableCategories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $categoryId = (int) $category['id'];
                if ($categoryId <= 0) {
                    continue;
                }

                $availableCategoryIds[] = $categoryId;
                $rawValue = isset($periodCategoryInputs[$categoryId]) ? trim((string) $periodCategoryInputs[$categoryId]) : '';
                $ratePeriodFormData['category_prices'][$categoryId] = $rawValue;

                if ($rawValue === '') {
                    $categoryPriceRemovals[] = $categoryId;
                    continue;
                }

                $normalizedInput = str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $rawValue));
                if ($normalizedInput === '' || !is_numeric($normalizedInput)) {
                    $periodPriceValidationError = sprintf(
                        'Der Zeitraumpreis für die Kategorie &quot;%s&quot; ist ungültig.',
                        htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                    );
                    break;
                }

                $priceValue = round((float) $normalizedInput, 2);
                if ($priceValue < 0) {
                    $periodPriceValidationError = sprintf(
                        'Der Zeitraumpreis für die Kategorie &quot;%s&quot; darf nicht negativ sein.',
                        htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                    );
                    break;
                }

                $periodCategoryPrices[$categoryId] = number_format($priceValue, 2, '.', '');
            }

            if ($periodPriceValidationError !== null) {
                $alert = [
                    'type' => 'danger',
                    'message' => $periodPriceValidationError,
                ];
                break;
            }

            if ($periodCategoryPrices === []) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie mindestens für eine Kategorie einen Zeitraumpreis an.',
                ];
                break;
            }

            $periodCategoryKeys = array_keys($periodCategoryPrices);
            $redirectCategoryId = null;
            if (
                $activeRateCategoryIdInput !== null
                && in_array($activeRateCategoryIdInput, $availableCategoryIds, true)
            ) {
                $redirectCategoryId = $activeRateCategoryIdInput;
            } elseif ($periodCategoryKeys !== []) {
                $redirectCategoryId = (int) $periodCategoryKeys[0];
            } elseif ($availableCategoryIds !== []) {
                $redirectCategoryId = $availableCategoryIds[0];
            }

            if ($form === 'rate_period_create') {
                $payload = [
                    'rate_id' => $rateId,
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days_of_week' => $daysOfWeek,
                    'created_by' => $currentUserId > 0 ? $currentUserId : null,
                    'updated_by' => $currentUserId > 0 ? $currentUserId : null,
                    'category_prices' => $periodCategoryPrices,
                ];

                $periodId = $rateManager->createPeriod($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Preiszeitraum wurde hinzugefügt.',
                ];

                $redirectParams = [
                    'section' => 'rates',
                    'rateId' => $rateId,
                ];
                if ($redirectCategoryId !== null) {
                    $redirectParams['rateCategoryId'] = $redirectCategoryId;
                }

                header('Location: index.php?' . http_build_query($redirectParams) . '#rate-periods');
                exit;
            }

            $periodId = (int) ($_POST['id'] ?? 0);
            if ($periodId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Zeitraum konnte nicht aktualisiert werden, da keine gültige ID übermittelt wurde.',
                ];
                break;
            }

            $existingPeriod = $rateManager->findPeriod($periodId);
            if ($existingPeriod === null || (int) ($existingPeriod['rate_id'] ?? 0) !== $rateId) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Zeitraum gehört nicht zur aktuellen Rate.',
                ];
                break;
            }

            $payload = [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'days_of_week' => $daysOfWeek,
                'updated_by' => $currentUserId > 0 ? $currentUserId : null,
                'category_prices' => $periodCategoryPrices,
                'category_price_removals' => $categoryPriceRemovals,
            ];

            $rateManager->updatePeriod($periodId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Preiszeitraum wurde aktualisiert.',
            ];

            $redirectParams = [
                'section' => 'rates',
                'rateId' => $rateId,
            ];
            if ($redirectCategoryId !== null) {
                $redirectParams['rateCategoryId'] = $redirectCategoryId;
            }

            header('Location: index.php?' . http_build_query($redirectParams) . '#rate-periods');
            exit;

        case 'rate_period_delete':
            $activeSection = 'rates';

            if ($rateManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $periodId = (int) ($_POST['id'] ?? 0);
            $rateId = (int) ($_POST['rate_id'] ?? 0);
            $activeRateCategoryIdInput = null;
            if (isset($_POST['active_rate_category_id'])) {
                $candidate = (int) $_POST['active_rate_category_id'];
                if ($candidate > 0) {
                    $activeRateCategoryIdInput = $candidate;
                }
            }

            if ($periodId <= 0 || $rateId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Zeitraum konnte nicht gelöscht werden, da Angaben fehlen.',
                ];
                break;
            }

            $existingPeriod = $rateManager->findPeriod($periodId);
            if ($existingPeriod === null || (int) ($existingPeriod['rate_id'] ?? 0) !== $rateId) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Zeitraum wurde nicht gefunden.',
                ];
                break;
            }

            $rateManager->deletePeriod($periodId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Preiszeitraum wurde gelöscht.',
            ];

            $redirectParams = [
                'section' => 'rates',
                'rateId' => $rateId,
            ];
            if ($activeRateCategoryIdInput !== null) {
                $redirectParams['rateCategoryId'] = $activeRateCategoryIdInput;
            }

            header('Location: index.php?' . http_build_query($redirectParams) . '#rate-periods');
            exit;

        case 'rate_event_create':
        case 'rate_event_update':
            $activeSection = 'rates';
            $rateEventFormMode = $form === 'rate_event_update' ? 'update' : 'create';

            if ($rateManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $rateIdInput = trim((string) ($_POST['rate_id'] ?? ''));
            $rateId = (int) $rateIdInput;

            $activeRateCategoryIdInput = null;
            if (isset($_POST['active_rate_category_id'])) {
                $candidate = (int) $_POST['active_rate_category_id'];
                if ($candidate > 0) {
                    $activeRateCategoryIdInput = $candidate;
                }
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $startDateInput = trim((string) ($_POST['start_date'] ?? ''));
            $endDateInput = trim((string) ($_POST['end_date'] ?? ''));
            $defaultPriceInput = trim((string) ($_POST['default_price'] ?? ''));
            $colorInput = trim((string) ($_POST['color'] ?? ''));
            $descriptionInput = trim((string) ($_POST['description'] ?? ''));
            $categoryPricesInput = isset($_POST['category_prices']) && is_array($_POST['category_prices']) ? $_POST['category_prices'] : [];

            $rateEventFormData = [
                'id' => $form === 'rate_event_update' ? (int) ($_POST['id'] ?? 0) : null,
                'rate_id' => $rateIdInput,
                'name' => $name,
                'start_date' => $startDateInput,
                'end_date' => $endDateInput,
                'default_price' => $defaultPriceInput,
                'color' => $colorInput !== ''
                    ? (strpos($colorInput, '#') === 0 ? strtoupper($colorInput) : '#' . strtoupper($colorInput))
                    : '#B91C1C',
                'description' => $descriptionInput,
                'category_prices' => [],
            ];

            if ($rateId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte wählen Sie eine gültige Rate aus.',
                ];
                break;
            }

            $rateRecord = $rateManager->find($rateId);
            if ($rateRecord === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Rate wurde nicht gefunden.',
                ];
                break;
            }

            $availableCategories = [];
            if ($categoryManager instanceof RoomCategoryManager) {
                $availableCategories = $categoryManager->all();
            }

            $categoryMap = [];
            foreach ($availableCategories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $categoryId = (int) $category['id'];
                $categoryMap[$categoryId] = $category;
            }

            if ($name === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Namen für die Messe ein.',
                ];
                break;
            }

            $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $startDateInput) ?: DateTimeImmutable::createFromFormat('d.m.Y', $startDateInput);
            $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $endDateInput) ?: DateTimeImmutable::createFromFormat('d.m.Y', $endDateInput);

            if (!($startDate instanceof DateTimeImmutable) || !($endDate instanceof DateTimeImmutable)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie gültige Start- und Enddaten an (Format: JJJJ-MM-TT).',
                ];
                break;
            }

            if ($endDate < $startDate) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
                ];
                break;
            }

            $defaultPrice = null;
            if ($defaultPriceInput !== '') {
                $normalizedDefault = str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $defaultPriceInput));
                if ($normalizedDefault === '' || !is_numeric($normalizedDefault)) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Der Standardpreis ist ungültig.',
                    ];
                    break;
                }

                $defaultValue = round((float) $normalizedDefault, 2);
                if ($defaultValue < 0) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Der Standardpreis darf nicht negativ sein.',
                    ];
                    break;
                }

                $defaultPrice = number_format($defaultValue, 2, '.', '');
                $rateEventFormData['default_price'] = number_format($defaultValue, 2, ',', '');
            }

            $colorValue = '#B91C1C';
            if ($colorInput !== '') {
                $normalizedColor = strtoupper(ltrim($colorInput, '#'));
                if (!preg_match('/^([0-9A-F]{6}|[0-9A-F]{3})$/', $normalizedColor)) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Bitte geben Sie eine gültige Farbe im Hex-Format an.',
                    ];
                    break;
                }

                if (strlen($normalizedColor) === 3) {
                    $normalizedColor = sprintf('%1$s%1$s%2$s%2$s%3$s%3$s', $normalizedColor[0], $normalizedColor[1], $normalizedColor[2]);
                }

                $colorValue = '#' . $normalizedColor;
                $rateEventFormData['color'] = $colorValue;
            }

            $eventCategoryPrices = [];
            $categoryPriceRemovals = [];

            foreach ($categoryMap as $categoryId => $category) {
                $rawValue = isset($categoryPricesInput[$categoryId]) ? trim((string) $categoryPricesInput[$categoryId]) : '';
                $rateEventFormData['category_prices'][$categoryId] = $rawValue;

                if ($rawValue === '') {
                    $categoryPriceRemovals[] = $categoryId;
                    continue;
                }

                $normalizedInput = str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', $rawValue));
                if ($normalizedInput === '' || !is_numeric($normalizedInput)) {
                    $alert = [
                        'type' => 'danger',
                        'message' => sprintf(
                            'Der Preis für die Kategorie &quot;%s&quot; ist ungültig.',
                            htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                        ),
                    ];
                    break 2;
                }

                $priceValue = round((float) $normalizedInput, 2);
                if ($priceValue < 0) {
                    $alert = [
                        'type' => 'danger',
                        'message' => sprintf(
                            'Der Preis für die Kategorie &quot;%s&quot; darf nicht negativ sein.',
                            htmlspecialchars((string) ($category['name'] ?? 'Kategorie'), ENT_QUOTES, 'UTF-8')
                        ),
                    ];
                    break 2;
                }

                $eventCategoryPrices[$categoryId] = number_format($priceValue, 2, '.', '');
                $rateEventFormData['category_prices'][$categoryId] = number_format($priceValue, 2, ',', '');
            }

            if ($alert !== null) {
                break;
            }

            $payload = [
                'rate_id' => $rateId,
                'name' => $name,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'default_price' => $defaultPrice,
                'color' => $colorValue,
                'description' => $descriptionInput !== '' ? $descriptionInput : null,
                'created_by' => $currentUserId > 0 ? $currentUserId : null,
                'updated_by' => $currentUserId > 0 ? $currentUserId : null,
                'category_prices' => $eventCategoryPrices,
            ];

            if ($form === 'rate_event_create') {
                $eventId = $rateManager->createEvent($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Messe wurde angelegt.',
                ];

                $redirectParams = [
                    'section' => 'rates',
                    'rateId' => $rateId,
                ];
                if ($activeRateCategoryIdInput !== null) {
                    $redirectParams['rateCategoryId'] = $activeRateCategoryIdInput;
                }

                header('Location: index.php?' . http_build_query($redirectParams) . '#rate-events');
                exit;
            }

            $eventId = (int) ($_POST['id'] ?? 0);
            if ($eventId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Messe konnte nicht aktualisiert werden, da keine gültige ID übermittelt wurde.',
                ];
                break;
            }

            $existingEvent = $rateManager->findEvent($eventId);
            if ($existingEvent === null || (int) ($existingEvent['rate_id'] ?? 0) !== $rateId) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Messe gehört nicht zur aktuellen Rate.',
                ];
                break;
            }

            $payload['updated_by'] = $currentUserId > 0 ? $currentUserId : null;
            $payload['category_price_removals'] = $categoryPriceRemovals;

            $rateManager->updateEvent($eventId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Messe wurde aktualisiert.',
            ];

            $redirectParams = [
                'section' => 'rates',
                'rateId' => $rateId,
            ];
            if ($activeRateCategoryIdInput !== null) {
                $redirectParams['rateCategoryId'] = $activeRateCategoryIdInput;
            }

            header('Location: index.php?' . http_build_query($redirectParams) . '#rate-events');
            exit;

        case 'rate_event_delete':
            $activeSection = 'rates';

            if ($rateManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $eventId = (int) ($_POST['id'] ?? 0);
            $rateId = (int) ($_POST['rate_id'] ?? 0);
            $activeRateCategoryIdInput = null;
            if (isset($_POST['active_rate_category_id'])) {
                $candidate = (int) $_POST['active_rate_category_id'];
                if ($candidate > 0) {
                    $activeRateCategoryIdInput = $candidate;
                }
            }

            if ($eventId <= 0 || $rateId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Messe konnte nicht gelöscht werden, da Angaben fehlen.',
                ];
                break;
            }

            $existingEvent = $rateManager->findEvent($eventId);
            if ($existingEvent === null || (int) ($existingEvent['rate_id'] ?? 0) !== $rateId) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Messe wurde nicht gefunden.',
                ];
                break;
            }

            $rateManager->deleteEvent($eventId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Messe wurde gelöscht.',
            ];

            $redirectParams = [
                'section' => 'rates',
                'rateId' => $rateId,
            ];
            if ($activeRateCategoryIdInput !== null) {
                $redirectParams['rateCategoryId'] = $activeRateCategoryIdInput;
            }

            header('Location: index.php?' . http_build_query($redirectParams) . '#rate-events');
            exit;

        case 'tax_category_create':
        case 'tax_category_update':
            $activeSection = 'articles';

            if ($taxCategoryManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Mehrwertsteuer-Kategorien können aktuell nicht verwaltet werden.',
                ];
                break;
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $rateInput = trim((string) ($_POST['rate'] ?? ''));
            $rateValue = $normalizeMoneyInput($rateInput);

            $taxCategoryFormData = [
                'id' => $form === 'tax_category_update' ? (int) ($_POST['id'] ?? 0) : null,
                'name' => $name,
                'rate' => $rateInput,
            ];

            if ($name === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Namen für die Mehrwertsteuer-Kategorie an.',
                ];
                break;
            }

            if ($rateValue === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen gültigen Prozentsatz an.',
                ];
                break;
            }

            if ($rateValue > 100) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Mehrwertsteuersatz darf 100 % nicht überschreiten.',
                ];
                break;
            }

            $payload = [
                'name' => $name,
                'rate' => $rateValue,
            ];

            if ($form === 'tax_category_create') {
                $taxCategoryManager->create($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Mehrwertsteuer-Kategorie "%s" wurde angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php?section=articles#tax-category-management');
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

            $existingCategory = $taxCategoryManager->find($categoryId);
            if ($existingCategory === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Mehrwertsteuer-Kategorie wurde nicht gefunden.',
                ];
                break;
            }

            $taxCategoryManager->update($categoryId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Mehrwertsteuer-Kategorie "%s" wurde aktualisiert.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=articles#tax-category-management');
            exit;

        case 'tax_category_delete':
            $activeSection = 'articles';

            if ($taxCategoryManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Mehrwertsteuer-Kategorien können aktuell nicht verwaltet werden.',
                ];
                break;
            }

            $categoryId = (int) ($_POST['id'] ?? 0);
            if ($categoryId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Kategorie konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $existingCategory = $taxCategoryManager->find($categoryId);
            if ($existingCategory === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Mehrwertsteuer-Kategorie wurde nicht gefunden.',
                ];
                break;
            }

            $taxCategoryManager->delete($categoryId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Mehrwertsteuer-Kategorie "%s" wurde gelöscht.', htmlspecialchars((string) $existingCategory['name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=articles#tax-category-management');
            exit;

        case 'article_create':
        case 'article_update':
            $activeSection = 'articles';

            if ($articleManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Artikelverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $priceInput = trim((string) ($_POST['price'] ?? ''));
            $pricingType = (string) ($_POST['pricing_type'] ?? ArticleManager::PRICING_PER_DAY);
            $taxCategoryIdInput = trim((string) ($_POST['tax_category_id'] ?? ''));

            $priceValue = $normalizeMoneyInput($priceInput);

            $articleFormData = [
                'id' => $form === 'article_update' ? (int) ($_POST['id'] ?? 0) : null,
                'name' => $name,
                'description' => $description,
                'price' => $priceInput,
                'pricing_type' => $pricingType,
                'tax_category_id' => $taxCategoryIdInput,
            ];

            if ($name === '') {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen Artikelnamen an.',
                ];
                break;
            }

            if ($priceValue === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie einen gültigen Bruttopreis an.',
                ];
                break;
            }

            if (!array_key_exists($pricingType, $articlePricingTypes)) {
                $pricingType = ArticleManager::PRICING_PER_DAY;
            }

            $taxCategoryId = $taxCategoryIdInput !== '' ? (int) $taxCategoryIdInput : null;
            if ($taxCategoryId !== null && $taxCategoryId <= 0) {
                $taxCategoryId = null;
            }

            if ($taxCategoryId !== null && !isset($taxCategoryLookup[$taxCategoryId])) {
                $taxCategoryRecord = null;
                if ($taxCategoryManager instanceof TaxCategoryManager) {
                    $taxCategoryRecord = $taxCategoryManager->find($taxCategoryId);
                    if ($taxCategoryRecord !== null) {
                        $taxCategoryLookup[$taxCategoryId] = $taxCategoryRecord;
                    }
                }

                if ($taxCategoryRecord === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Die ausgewählte Mehrwertsteuer-Kategorie wurde nicht gefunden.',
                    ];
                    break;
                }
            }

            $payload = [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'price' => $priceValue,
                'pricing_type' => $pricingType,
                'tax_category_id' => $taxCategoryId,
            ];

            if ($form === 'article_create') {
                $articleManager->create($payload);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => sprintf('Artikel "%s" wurde angelegt.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
                ];

                header('Location: index.php?section=articles');
                exit;
            }

            $articleId = (int) ($_POST['id'] ?? 0);
            if ($articleId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Artikel konnte nicht aktualisiert werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $existingArticle = $articleManager->find($articleId);
            if ($existingArticle === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Artikel wurde nicht gefunden.',
                ];
                break;
            }

            $articleManager->update($articleId, $payload);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Artikel "%s" wurde aktualisiert.', htmlspecialchars($name, ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=articles');
            exit;

        case 'article_delete':
            $activeSection = 'articles';

            if ($articleManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Artikelverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $articleId = (int) ($_POST['id'] ?? 0);
            if ($articleId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der Artikel konnte nicht gelöscht werden, da keine gültige ID übergeben wurde.',
                ];
                break;
            }

            $existingArticle = $articleManager->find($articleId);
            if ($existingArticle === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Der ausgewählte Artikel wurde nicht gefunden.',
                ];
                break;
            }

            $articleManager->delete($articleId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => sprintf('Artikel "%s" wurde gelöscht.', htmlspecialchars((string) $existingArticle['name'], ENT_QUOTES, 'UTF-8')),
            ];

            header('Location: index.php?section=articles');
            exit;

        case 'category_move':
            $activeSection = 'categories';

            $categoryId = (int) ($_POST['id'] ?? 0);
            $direction = isset($_POST['direction']) ? (string) $_POST['direction'] : '';

            if ($categoryId <= 0 || !in_array($direction, ['up', 'down'], true)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Kategorie konnte nicht verschoben werden. Bitte versuchen Sie es erneut.',
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

            $moved = $categoryManager->move($categoryId, $direction);
            $_SESSION['alert'] = [
                'type' => $moved ? 'success' : 'info',
                'message' => $moved ? 'Kategorieposition wurde aktualisiert.' : 'Die Kategorie befindet sich bereits an dieser Position.',
            ];

            header('Location: index.php?section=categories#category-management');
            exit;

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

        case 'reservation_create':
        case 'reservation_update':
            $activeSection = 'reservations';

            if ($reservationManager === null || $guestManager === null || $roomManager === null || $categoryManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Reservierungsverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            if ($articleLookup === [] && $articleManager instanceof ArticleManager) {
                $articles = $articleManager->all();

                foreach ($articles as $article) {
                    if (!isset($article['id'])) {
                        continue;
                    }

                    $articleId = (int) $article['id'];
                    if ($articleId <= 0) {
                        continue;
                    }

                    $articleLookup[$articleId] = $article;
                }
            }

            if ($taxCategoryLookup === [] && $taxCategoryManager instanceof TaxCategoryManager) {
                $taxCategories = $taxCategoryManager->all();

                foreach ($taxCategories as $taxCategory) {
                    if (!isset($taxCategory['id'])) {
                        continue;
                    }

                    $taxCategoryId = (int) $taxCategory['id'];
                    if ($taxCategoryId <= 0) {
                        continue;
                    }

                    $taxCategoryLookup[$taxCategoryId] = $taxCategory;
                }
            }

            $guestIdInput = trim((string) ($_POST['guest_id'] ?? ''));
            $guestQueryInput = trim((string) ($_POST['guest_query'] ?? ''));
            $companyIdInput = trim((string) ($_POST['company_id'] ?? ''));
            $companyQueryInput = trim((string) ($_POST['company_query'] ?? ''));
            $statusInput = $_POST['status'] ?? 'geplant';
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $categoryItemsInput = $_POST['reservation_categories'] ?? [];

            $reservationIdForUpdate = $form === 'reservation_update' ? (int) ($_POST['id'] ?? 0) : null;
            $existingReservationForUpdate = null;
            if ($reservationIdForUpdate !== null && $reservationIdForUpdate > 0) {
                $existingReservationForUpdate = $reservationManager->find($reservationIdForUpdate);
            }

            $existingVatRate = null;
            if ($existingReservationForUpdate !== null && isset($existingReservationForUpdate['vat_rate']) && $existingReservationForUpdate['vat_rate'] !== null) {
                $existingVatRate = (float) $existingReservationForUpdate['vat_rate'];
            }

            $vatRateValue = $existingVatRate ?? $overnightVatRate;
            $formVatRateValue = number_format($vatRateValue, 2, '.', '');

            $reservationFormMode = $form === 'reservation_update' ? 'update' : 'create';
            $isEditingReservation = $form === 'reservation_update';

            $categoryItemsForForm = [];
            if (is_array($categoryItemsInput)) {
                foreach ($categoryItemsInput as $categoryIndex => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $itemArticlesForForm = [];
                    if (isset($item['articles']) && is_array($item['articles'])) {
                        foreach ($item['articles'] as $articleRow) {
                            if (!is_array($articleRow)) {
                                continue;
                            }

                            $itemArticlesForForm[] = [
                                'article_id' => trim((string) ($articleRow['article_id'] ?? '')),
                                'quantity' => trim((string) ($articleRow['quantity'] ?? '1')),
                                'total_price' => trim((string) ($articleRow['total_price'] ?? '')),
                            ];
                        }
                    }

                    if ($itemArticlesForForm === []) {
                        $itemArticlesForForm[] = [
                            'article_id' => '',
                            'quantity' => '1',
                            'total_price' => '',
                        ];
                    }

                    $categoryItemsForForm[$categoryIndex] = [
                        'category_id' => trim((string) ($item['category_id'] ?? '')),
                        'room_quantity' => trim((string) ($item['room_quantity'] ?? '1')),
                        'occupancy' => trim((string) ($item['occupancy'] ?? '1')),
                        'room_id' => trim((string) ($item['room_id'] ?? '')),
                        'arrival_date' => trim((string) ($item['arrival_date'] ?? '')),
                        'departure_date' => trim((string) ($item['departure_date'] ?? '')),
                        'rate_id' => trim((string) ($item['rate_id'] ?? '')),
                        'price_per_night' => trim((string) ($item['price_per_night'] ?? '')),
                        'total_price' => trim((string) ($item['total_price'] ?? '')),
                        'primary_guest_id' => trim((string) ($item['primary_guest_id'] ?? '')),
                        'primary_guest_query' => trim((string) ($item['primary_guest_query'] ?? '')),
                        'articles' => $itemArticlesForForm,
                    ];
                }
            }

            if ($categoryItemsForForm === []) {
                $categoryItemsForForm[] = [
                    'category_id' => '',
                    'room_quantity' => '1',
                    'occupancy' => '1',
                    'room_id' => '',
                    'arrival_date' => '',
                    'departure_date' => '',
                    'rate_id' => '',
                    'price_per_night' => '',
                    'total_price' => '',
                    'primary_guest_id' => '',
                    'primary_guest_query' => '',
                    'articles' => [],
                ];
            }

            ksort($categoryItemsForForm);
            $categoryItemsForForm = array_values($categoryItemsForForm);
            $reservationFormData['category_items'] = $categoryItemsForForm;

            $availableCategories = $categoryManager->all();
            $categoryLookupForValidation = [];
            foreach ($availableCategories as $availableCategory) {
                if (!isset($availableCategory['id'])) {
                    continue;
                }

                $categoryLookupForValidation[(int) $availableCategory['id']] = $availableCategory;
            }

            $guestId = (int) $guestIdInput;
            if ($guestId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte wählen Sie einen gültigen Gast aus.',
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

            $companyId = null;
            if ($companyIdInput !== '') {
                if ($companyManager === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Firmen können derzeit nicht geladen werden.',
                    ];
                    break;
                }

                $companyIdValue = (int) $companyIdInput;
                if ($companyIdValue <= 0) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Bitte wählen Sie eine gültige Firma aus.',
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

            $validCategoryItems = [];
            $categoryValidationErrors = false;
            $selectedRoomIds = [];
            $earliestArrival = null;
            $latestDeparture = null;

            foreach ($categoryItemsForForm as $categoryIndex => $item) {
                $categoryIdValue = isset($item['category_id']) ? (int) $item['category_id'] : 0;
                $quantityValue = isset($item['room_quantity']) ? (int) $item['room_quantity'] : 1;
                $roomIdValue = isset($item['room_id']) ? (int) $item['room_id'] : 0;
                $rateIdValue = isset($item['rate_id']) ? (int) $item['rate_id'] : 0;

                $arrivalValue = isset($item['arrival_date']) ? trim((string) $item['arrival_date']) : '';
                $departureValue = isset($item['departure_date']) ? trim((string) $item['departure_date']) : '';

                $pricePerNightInput = isset($item['price_per_night']) ? trim((string) $item['price_per_night']) : '';
                $totalPriceInput = isset($item['total_price']) ? trim((string) $item['total_price']) : '';
                $primaryGuestIdRaw = isset($item['primary_guest_id']) ? (int) $item['primary_guest_id'] : 0;
                $primaryGuestQueryInput = isset($item['primary_guest_query'])
                    ? trim((string) $item['primary_guest_query'])
                    : '';

                $hasArticleSelection = false;
                if (isset($categoryItemsForForm[$categoryIndex]['articles'])
                    && is_array($categoryItemsForForm[$categoryIndex]['articles'])
                ) {
                    foreach ($categoryItemsForForm[$categoryIndex]['articles'] as $articleCandidate) {
                        if (!is_array($articleCandidate)) {
                            continue;
                        }

                        $articleIdCandidate = trim((string) ($articleCandidate['article_id'] ?? ''));
                        $articleTotalCandidate = trim((string) ($articleCandidate['total_price'] ?? ''));

                        if ($articleIdCandidate !== '' || $articleTotalCandidate !== '') {
                            $hasArticleSelection = true;
                            break;
                        }
                    }
                }

                $normalizedArrival = $normalizeDateInput($arrivalValue);
                $normalizedDeparture = $normalizeDateInput($departureValue);

                $hasCoreSelection = $categoryIdValue > 0
                    || $roomIdValue > 0
                    || $rateIdValue > 0
                    || $arrivalValue !== ''
                    || $departureValue !== ''
                    || $pricePerNightInput !== ''
                    || $totalPriceInput !== ''
                    || $hasArticleSelection;

                if (!$hasCoreSelection) {
                    if (isset($categoryItemsForForm[$categoryIndex])) {
                        $categoryItemsForForm[$categoryIndex]['articles_total'] = '';
                        $categoryItemsForForm[$categoryIndex]['price_per_night'] = '';
                        $categoryItemsForForm[$categoryIndex]['total_price'] = '';
                        $categoryItemsForForm[$categoryIndex]['primary_guest_id'] = '';
                        $categoryItemsForForm[$categoryIndex]['primary_guest_query'] = '';
                    }

                    continue;
                }

                if ($categoryIdValue <= 0 || !isset($categoryLookupForValidation[$categoryIdValue])) {
                    $categoryValidationErrors = true;
                    continue;
                }

                if ($normalizedArrival === null || $normalizedDeparture === null) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $arrivalDateObj = new DateTimeImmutable($normalizedArrival);
                $departureDateObj = new DateTimeImmutable($normalizedDeparture);

                if ($departureDateObj <= $arrivalDateObj) {
                    $categoryValidationErrors = true;
                    continue;
                }

                if ($quantityValue <= 0) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $occupancyValue = isset($item['occupancy']) ? (int) $item['occupancy'] : 0;
                if ($occupancyValue <= 0) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $primaryGuestIdValue = $primaryGuestIdRaw;
                if ($primaryGuestIdValue <= 0 && $guestId > 0) {
                    $primaryGuestIdValue = $guestId;
                }

                $primaryGuestRecord = null;
                if ($primaryGuestIdValue > 0) {
                    if (isset($guestLookup[$primaryGuestIdValue])) {
                        $primaryGuestRecord = $guestLookup[$primaryGuestIdValue];
                    } else {
                        $primaryGuestRecord = $guestManager->find($primaryGuestIdValue);
                        if ($primaryGuestRecord !== null) {
                            $guestLookup[$primaryGuestIdValue] = $primaryGuestRecord;
                        }
                    }
                }

                if ($primaryGuestRecord === null) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $primaryGuestLabel = $buildGuestReservationLabel($primaryGuestRecord);

                if (isset($categoryItemsForForm[$categoryIndex])) {
                    $categoryItemsForForm[$categoryIndex]['occupancy'] = (string) $occupancyValue;
                    $categoryItemsForForm[$categoryIndex]['primary_guest_id'] = (string) $primaryGuestIdValue;
                    $categoryItemsForForm[$categoryIndex]['primary_guest_query'] = $primaryGuestLabel;

                    if (!isset($categoryItemsForForm[$categoryIndex]['articles']) || !is_array($categoryItemsForForm[$categoryIndex]['articles'])) {
                        $categoryItemsForForm[$categoryIndex]['articles'] = [
                            [
                                'article_id' => '',
                                'quantity' => '1',
                                'total_price' => '',
                            ],
                        ];
                    }
                }

                $roomIdNormalized = null;
                if ($roomIdValue > 0) {
                    if (in_array($roomIdValue, $selectedRoomIds, true)) {
                        $categoryValidationErrors = true;
                        continue;
                    }

                    if (!$reservationManager->isRoomAvailable($roomIdValue, $arrivalDateObj, $departureDateObj, $reservationIdForUpdate)) {
                        $categoryValidationErrors = true;
                        continue;
                    }

                    $roomIdNormalized = $roomIdValue;
                    $selectedRoomIds[] = $roomIdValue;
                    $quantityValue = 1;
                }

                if ($rateIdValue <= 0 || !isset($rateLookup[$rateIdValue])) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $pricePerNightValue = $pricePerNightInput !== '' ? $normalizeMoneyInput($pricePerNightInput) : null;
                $totalPriceValue = $totalPriceInput !== '' ? $normalizeMoneyInput($totalPriceInput) : null;

                if ($pricePerNightInput !== '' && $pricePerNightValue === null) {
                    $categoryValidationErrors = true;
                    continue;
                }

                if ($totalPriceInput !== '' && $totalPriceValue === null) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $nightCount = (int) $arrivalDateObj->diff($departureDateObj)->format('%a');
                if ($nightCount <= 0) {
                    $categoryValidationErrors = true;
                    continue;
                }

                $roomCountForItem = $roomIdNormalized !== null ? 1 : $quantityValue;
                if ($roomCountForItem <= 0) {
                    $roomCountForItem = 1;
                }

                $normalizedArticles = [];
                $articleTotalForItem = 0.0;
                $articleProcessingError = false;

                if (isset($categoryItemsForForm[$categoryIndex]['articles']) && is_array($categoryItemsForForm[$categoryIndex]['articles'])) {
                    foreach ($categoryItemsForForm[$categoryIndex]['articles'] as $articleRowIndex => &$articleRowData) {
                        $articleIdInput = trim((string) ($articleRowData['article_id'] ?? ''));
                        $articleQuantityInput = trim((string) ($articleRowData['quantity'] ?? '1'));
                        if ($articleQuantityInput === '') {
                            $articleQuantityInput = '1';
                            $articleRowData['quantity'] = '1';
                        }

                        if ($articleIdInput === '') {
                            $articleRowData['article_id'] = '';
                            $articleRowData['quantity'] = $articleQuantityInput !== '' ? $articleQuantityInput : '1';
                            $articleRowData['total_price'] = '';
                            continue;
                        }

                        $articleId = (int) $articleIdInput;
                        if ($articleId <= 0 || !isset($articleLookup[$articleId])) {
                            $articleProcessingError = true;
                            $articleRowData['article_id'] = '';
                            $articleRowData['total_price'] = '';
                            continue;
                        }

                        $articleQuantity = (int) $articleQuantityInput;
                        if ($articleQuantity <= 0) {
                            $articleQuantity = 1;
                            $articleRowData['quantity'] = '1';
                        }

                        $articleDefinition = $articleLookup[$articleId];
                        $unitPrice = isset($articleDefinition['price']) ? (float) $articleDefinition['price'] : 0.0;
                        $pricingType = (string) ($articleDefinition['pricing_type'] ?? ArticleManager::PRICING_PER_DAY);
                        if (!isset($articlePricingTypes[$pricingType])) {
                            $pricingType = ArticleManager::PRICING_PER_DAY;
                        }

                        $taxCategoryId = isset($articleDefinition['tax_category_id']) && $articleDefinition['tax_category_id'] !== null
                            ? (int) $articleDefinition['tax_category_id']
                            : null;

                        $taxRate = 0.0;
                        if (isset($articleDefinition['tax_category_rate'])) {
                            $taxRate = (float) $articleDefinition['tax_category_rate'];
                        } elseif ($taxCategoryId !== null && isset($taxCategoryLookup[$taxCategoryId]['rate'])) {
                            $taxRate = (float) $taxCategoryLookup[$taxCategoryId]['rate'];
                        }

                        $peopleFactor = max(1, $occupancyValue) * $roomCountForItem;
                        if ($pricingType === ArticleManager::PRICING_PER_PERSON_PER_DAY) {
                            $effectiveQuantity = $peopleFactor * $nightCount * $articleQuantity;
                        } elseif ($pricingType === ArticleManager::PRICING_ONE_TIME) {
                            $effectiveQuantity = $articleQuantity;
                        } else {
                            $effectiveQuantity = $roomCountForItem * $nightCount * $articleQuantity;
                        }
                        if ($effectiveQuantity <= 0) {
                            $articleProcessingError = true;
                            $articleRowData['total_price'] = '';
                            continue;
                        }

                        $articleTotal = round($unitPrice * $effectiveQuantity, 2);

                        $articleRowData['article_id'] = (string) $articleId;
                        $articleRowData['quantity'] = (string) $articleQuantity;
                        $articleRowData['total_price'] = number_format($articleTotal, 2, ',', '.');

                        $normalizedArticles[] = [
                            'article_id' => $articleId,
                            'article_name' => (string) ($articleDefinition['name'] ?? ('Artikel #' . $articleId)),
                            'pricing_type' => $pricingType,
                            'quantity' => $articleQuantity,
                            'effective_quantity' => $effectiveQuantity,
                            'unit_price' => $unitPrice,
                            'total_price' => $articleTotal,
                            'tax_rate' => $taxRate,
                            'tax_category_id' => $taxCategoryId,
                            'nights' => $nightCount,
                        ];

                        $articleTotalForItem += $articleTotal;
                    }
                    unset($articleRowData);
                } else {
                    $categoryItemsForForm[$categoryIndex]['articles'] = [
                        [
                            'article_id' => '',
                            'quantity' => '1',
                            'total_price' => '',
                        ],
                    ];
                }

                if ($articleProcessingError) {
                    $categoryValidationErrors = true;
                    continue;
                }

                if (isset($categoryItemsForForm[$categoryIndex])) {
                    $categoryItemsForForm[$categoryIndex]['articles_total'] = $articleTotalForItem > 0
                        ? number_format($articleTotalForItem, 2, ',', '.')
                        : '';
                }

                if ($earliestArrival === null || $arrivalDateObj < $earliestArrival) {
                    $earliestArrival = $arrivalDateObj;
                }

                if ($latestDeparture === null || $departureDateObj > $latestDeparture) {
                    $latestDeparture = $departureDateObj;
                }

                $validCategoryItems[] = [
                    'index' => $categoryIndex,
                    'category_id' => $categoryIdValue,
                    'room_quantity' => $quantityValue,
                    'occupancy' => $occupancyValue,
                    'primary_guest_id' => $primaryGuestIdValue,
                    'primary_guest_label' => $primaryGuestLabel,
                    'room_id' => $roomIdNormalized,
                    'arrival_date' => $normalizedArrival,
                    'departure_date' => $normalizedDeparture,
                    'rate_id' => $rateIdValue,
                    'price_per_night' => $pricePerNightValue,
                    'total_price' => $totalPriceValue,
                    'article_total' => $articleTotalForItem,
                    'articles' => $normalizedArticles,
                    'night_count' => $nightCount,
                ];
            }

            if ($validCategoryItems === []) {
                $alert = [
                    'type' => 'danger',
                    'message' => $categoryValidationErrors
                        ? 'Bitte prüfen Sie die ausgewählten Kategorien, Raten, Daten und Zimmerzuweisungen.'
                        : 'Bitte geben Sie mindestens eine gültige Kategorie mit Zimmer- und Ratenangaben an.',
                ];
                break;
            }

            if ($categoryValidationErrors) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte prüfen Sie die ausgewählten Kategorien, Raten, Daten und Zimmerzuweisungen.',
                ];
                break;
            }

            $pricingItems = [];
            foreach ($validCategoryItems as $item) {
                $pricingItems[] = [
                    'index' => $item['index'],
                    'category_id' => $item['category_id'],
                    'room_quantity' => $item['room_quantity'],
                    'arrival_date' => $item['arrival_date'],
                    'departure_date' => $item['departure_date'],
                    'rate_id' => $item['rate_id'],
                ];
            }

            $pricing = $computeReservationPricing($rateManager, $pricingItems);
            $pricingMap = [];
            if (isset($pricing['items']) && is_array($pricing['items'])) {
                foreach ($pricing['items'] as $pricingItem) {
                    if (!is_array($pricingItem) || !isset($pricingItem['index'])) {
                        continue;
                    }

                    $pricingMap[(int) $pricingItem['index']] = $pricingItem;
                }
            }

            $overallTotal = 0.0;
            foreach ($validCategoryItems as $index => $item) {
                $itemIndex = $item['index'];
                $calculated = $pricingMap[$itemIndex] ?? null;

                $pricePerNightValue = $item['price_per_night'];
                $totalPriceValue = $item['total_price'];
                $articleTotalValue = isset($item['article_total']) ? (float) $item['article_total'] : 0.0;

                if ($totalPriceValue === null && $calculated !== null && isset($calculated['total_price'])) {
                    $totalPriceValue = (float) $calculated['total_price'];
                }

                if ($pricePerNightValue === null && $calculated !== null && isset($calculated['price_per_night'])) {
                    $pricePerNightValue = (float) $calculated['price_per_night'];
                }

                if ($totalPriceValue === null && $pricePerNightValue !== null) {
                    $roomCountForItem = isset($item['room_quantity']) ? (int) $item['room_quantity'] : 1;
                    if ($roomCountForItem <= 0) {
                        $roomCountForItem = 1;
                    }

                    $totalPriceValue = round($pricePerNightValue * $item['night_count'] * $roomCountForItem, 2);
                }

                if ($totalPriceValue === null) {
                    $categoryValidationErrors = true;
                    break;
                }

                $overallTotal += $totalPriceValue + $articleTotalValue;
                $validCategoryItems[$index]['price_per_night'] = $pricePerNightValue;
                $validCategoryItems[$index]['room_total_price'] = $totalPriceValue;
                $validCategoryItems[$index]['article_total'] = $articleTotalValue;
                $validCategoryItems[$index]['total_price'] = $totalPriceValue + $articleTotalValue;

                if (isset($categoryItemsForForm[$itemIndex])) {
                    $categoryItemsForForm[$itemIndex]['price_per_night'] = $pricePerNightValue !== null
                        ? number_format($pricePerNightValue, 2, ',', '.')
                        : '';
                    $categoryItemsForForm[$itemIndex]['total_price'] = number_format($totalPriceValue + $articleTotalValue, 2, ',', '.');
                    $categoryItemsForForm[$itemIndex]['articles_total'] = $articleTotalValue > 0
                        ? number_format($articleTotalValue, 2, ',', '.')
                        : '';
                    $categoryItemsForForm[$itemIndex]['occupancy'] = (string) $item['occupancy'];
                    $categoryItemsForForm[$itemIndex]['primary_guest_id'] = (string) $item['primary_guest_id'];
                    $categoryItemsForForm[$itemIndex]['primary_guest_query'] = $item['primary_guest_label'];
                }
            }

            if ($categoryValidationErrors) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Für einige Kategorien konnte kein Preis ermittelt werden. Bitte Preise manuell angeben.',
                ];
                break;
            }

            $reservationFormData['category_items'] = $categoryItemsForForm;
            $reservationFormData['grand_total'] = number_format($overallTotal, 2, ',', '.');
            $reservationFormData['vat_rate'] = $formVatRateValue;

            $overallArrival = $earliestArrival;
            $overallDeparture = $latestDeparture;
            if ($overallArrival === null && isset($pricing['arrival_date']) && $pricing['arrival_date'] !== null) {
                $overallArrival = new DateTimeImmutable((string) $pricing['arrival_date']);
            }
            if ($overallDeparture === null && isset($pricing['departure_date']) && $pricing['departure_date'] !== null) {
                $overallDeparture = new DateTimeImmutable((string) $pricing['departure_date']);
            }

            if (!($overallArrival instanceof DateTimeImmutable) || !($overallDeparture instanceof DateTimeImmutable)) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Bitte geben Sie gültige An- und Abreisedaten für die Zimmer an.',
                ];
                break;
            }

            $reservationFormData['arrival_date'] = $overallArrival->format('Y-m-d');
            $reservationFormData['departure_date'] = $overallDeparture->format('Y-m-d');

            $reservationStatus = in_array($statusInput, $reservationStatuses, true) ? $statusInput : 'geplant';

            $totalRoomQuantity = 0;
            $assignedRoomIds = [];
            foreach ($validCategoryItems as $item) {
                $totalRoomQuantity += $item['room_quantity'];
                if (isset($item['room_id']) && $item['room_id'] !== null) {
                    $assignedRoomIds[] = (int) $item['room_id'];
                }
            }

            $primaryCategoryId = $validCategoryItems[0]['category_id'];
            $primaryRoomId = $assignedRoomIds !== [] ? $assignedRoomIds[0] : null;
            $rateIdForReservation = $validCategoryItems[0]['rate_id'] ?? null;

            $nightCount = isset($pricing['nights']) && $pricing['nights'] !== null ? (int) $pricing['nights'] : null;
            if ($nightCount === null || $nightCount <= 0) {
                $interval = $overallArrival->diff($overallDeparture);
                $nightCount = max(1, (int) $interval->days);
            }

            $averageNightPrice = $nightCount > 0 ? round($overallTotal / $nightCount, 2) : $overallTotal;

            $reservationFormData['id'] = $reservationIdForUpdate;
            $reservationFormData['guest_id'] = $guestIdInput;
            $reservationFormData['guest_query'] = $guestQueryInput;
            $reservationFormData['company_id'] = $companyIdInput;
            $reservationFormData['company_query'] = $companyQueryInput;
            $reservationFormData['status'] = $reservationStatus;
            $reservationFormData['notes'] = $notes;
            $reservationFormData['reservation_number'] = $existingReservationForUpdate['reservation_number'] ?? '';

            $createPayload = [
                'guest_id' => $guestId,
                'room_id' => $primaryRoomId,
                'category_id' => $primaryCategoryId,
                'room_quantity' => $totalRoomQuantity,
                'company_id' => $companyId,
                'rate_id' => $rateIdForReservation,
                'price_per_night' => number_format($averageNightPrice, 2, '.', ''),
                'total_price' => number_format($overallTotal, 2, '.', ''),
                'vat_rate' => number_format($vatRateValue, 2, '.', ''),
                'arrival_date' => $overallArrival->format('Y-m-d'),
                'departure_date' => $overallDeparture->format('Y-m-d'),
                'status' => $reservationStatus,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => $currentUserId > 0 ? $currentUserId : null,
                'updated_by' => $currentUserId > 0 ? $currentUserId : null,
            ];

            if ($form === 'reservation_update') {
                $reservationId = $reservationIdForUpdate ?? 0;
                if ($reservationId <= 0 || $existingReservationForUpdate === null) {
                    $alert = [
                        'type' => 'danger',
                        'message' => 'Die Reservierung konnte nicht aktualisiert werden.',
                    ];
                    break;
                }

                $updatePayload = $createPayload;
                $updatePayload['created_by'] = $existingReservationForUpdate['created_by'] ?? null;
                $updatePayload['updated_by'] = $currentUserId > 0 ? $currentUserId : null;

                $reservationManager->update($reservationId, $updatePayload);
                $reservationManager->replaceItems($reservationId, $validCategoryItems);

                $guestUpdate = [
                    'arrival_date' => $overallArrival->format('Y-m-d'),
                    'departure_date' => $overallDeparture->format('Y-m-d'),
                    'room_id' => $primaryRoomId,
                ];
                if ($companyId !== null) {
                    $guestUpdate['company_id'] = $companyId;
                }
                $guestManager->update($guestId, $guestUpdate);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Reservierung wurde aktualisiert.',
                ];
            } else {
                $reservationId = $reservationManager->create($createPayload);
                $reservationManager->replaceItems($reservationId, $validCategoryItems);

                $guestUpdate = [
                    'arrival_date' => $overallArrival->format('Y-m-d'),
                    'departure_date' => $overallDeparture->format('Y-m-d'),
                    'room_id' => $primaryRoomId,
                ];
                if ($companyId !== null) {
                    $guestUpdate['company_id'] = $companyId;
                }
                $guestManager->update($guestId, $guestUpdate);

                $_SESSION['alert'] = [
                    'type' => 'success',
                    'message' => 'Reservierung wurde angelegt.',
                ];
            }

            $_SESSION['redirect_reservation'] = $reservationId;
            header('Location: index.php?section=reservations');
            exit;

        case 'reservation_delete':
            $activeSection = 'reservations';

            if ($reservationManager === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Reservierungsverwaltung ist derzeit nicht verfügbar.',
                ];
                break;
            }

            $reservationId = (int) ($_POST['id'] ?? 0);

            if ($reservationId <= 0) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die Reservierung konnte nicht gelöscht werden, da keine gültige ID übermittelt wurde.',
                ];
                break;
            }

            $reservation = $reservationManager->find($reservationId);
            if ($reservation === null) {
                $alert = [
                    'type' => 'danger',
                    'message' => 'Die ausgewählte Reservierung wurde nicht gefunden.',
                ];
                break;
            }

            $reservationManager->delete($reservationId);

            $_SESSION['alert'] = [
                'type' => 'success',
                'message' => 'Reservierung wurde gelöscht.',
            ];

            header('Location: index.php?section=reservations');
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
    if ($categoryManager instanceof RoomCategoryManager) {
        $categories = $categoryManager->all();
    }

    if ($taxCategoryManager instanceof TaxCategoryManager) {
        $taxCategories = $taxCategoryManager->all();

        $taxOptions = ['<option value="">Bitte auswählen</option>'];
        foreach ($taxCategories as $taxCategory) {
            if (!isset($taxCategory['id'])) {
                continue;
            }

            $taxCategoryLookup[(int) $taxCategory['id']] = $taxCategory;

            $taxOptions[] = sprintf(
                '<option value="%d">%s (%s)</option>',
                (int) $taxCategory['id'],
                htmlspecialchars((string) ($taxCategory['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                isset($taxCategory['rate']) ? htmlspecialchars(number_format((float) $taxCategory['rate'], 2, ',', '.') . ' %', ENT_QUOTES, 'UTF-8') : '0,00 %'
            );
        }

        $articleTaxCategoryOptionsHtml = implode('', $taxOptions);
    }

    if ($articleManager instanceof ArticleManager) {
        $articles = $articleManager->all();
        $articleSelectRecords = [];

        foreach ($articles as $article) {
            if (!isset($article['id'])) {
                continue;
            }

            $articleId = (int) $article['id'];
            $articleLookup[$articleId] = $article;

            $articleName = isset($article['name']) ? (string) $article['name'] : ('Artikel #' . $articleId);
            $articlePrice = isset($article['price']) ? (float) $article['price'] : 0.0;
            $articlePricingType = isset($article['pricing_type']) ? (string) $article['pricing_type'] : ArticleManager::PRICING_PER_DAY;

            $articleSelectRecords[] = [
                'id' => $articleId,
                'name' => $articleName,
                'price' => $articlePrice,
                'pricing' => $articlePricingType,
            ];
        }

        $buildArticleSelectOptions = static function (?int $selectedId) use ($articleSelectRecords): string {
            $options = ['<option value="">Artikel wählen</option>'];

            foreach ($articleSelectRecords as $record) {
                $options[] = sprintf(
                    '<option value="%d" data-price="%s" data-pricing="%s"%s>%s (€ %s)</option>',
                    $record['id'],
                    htmlspecialchars(number_format($record['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($record['pricing'], ENT_QUOTES, 'UTF-8'),
                    $selectedId !== null && $selectedId === $record['id'] ? ' selected' : '',
                    htmlspecialchars($record['name'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars(number_format($record['price'], 2, ',', '.'), ENT_QUOTES, 'UTF-8')
                );
            }

            return implode('', $options);
        };

        $articleSelectOptionsHtml = $buildArticleSelectOptions(null);
    }

    if ($rateManager instanceof RateManager) {
        $rates = $rateManager->all();

        if ($requestedRateId !== null) {
            $activeRateId = $requestedRateId;
        }

        if ($activeRateId === null && $rates !== []) {
            $firstRateId = isset($rates[0]['id']) ? (int) $rates[0]['id'] : 0;
            $activeRateId = $firstRateId > 0 ? $firstRateId : null;
        }

        if ($activeRateId !== null) {
            $activeRate = $rateManager->find($activeRateId);

            if ($activeRate === null && $rates !== []) {
                foreach ($rates as $rateRecord) {
                    if (!isset($rateRecord['id'])) {
                        continue;
                    }

                    if ((int) $rateRecord['id'] === $activeRateId) {
                        $activeRate = $rateRecord;
                        break;
                    }
                }
            }

            if ($activeRate !== null) {
                $ratePeriods = $rateManager->periodsForRate($activeRateId);
                $rateEvents = $rateManager->eventsForRate($activeRateId);
            }

            $availableCategoryIds = [];
            foreach ($categories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $availableCategoryIds[] = (int) $category['id'];
            }

            $rateCategoryPrices = [];
            if (is_array($activeRate) && isset($activeRate['category_prices']) && is_array($activeRate['category_prices'])) {
                foreach ($activeRate['category_prices'] as $categoryId => $priceValue) {
                    $rateCategoryPrices[(int) $categoryId] = (float) $priceValue;
                }
            }

            $candidateCategoryId = null;
            if (
                $requestedRateCategoryId !== null
                && in_array($requestedRateCategoryId, $availableCategoryIds, true)
                && ($rateCategoryPrices === [] || isset($rateCategoryPrices[$requestedRateCategoryId]))
            ) {
                $candidateCategoryId = $requestedRateCategoryId;
            }

            if ($candidateCategoryId === null) {
                foreach ($categories as $category) {
                    if (!isset($category['id'])) {
                        continue;
                    }

                    $categoryId = (int) $category['id'];
                    if ($rateCategoryPrices === [] || isset($rateCategoryPrices[$categoryId])) {
                        $candidateCategoryId = $categoryId;
                        break;
                    }
                }

                if ($candidateCategoryId === null && $rateCategoryPrices !== []) {
                    $categoryPriceKeys = array_keys($rateCategoryPrices);
                    if ($categoryPriceKeys !== []) {
                        $candidateCategoryId = (int) $categoryPriceKeys[0];
                    }
                }
            }

            if ($candidateCategoryId === null && $categories !== []) {
                $candidateCategoryId = isset($categories[0]['id']) ? (int) $categories[0]['id'] : null;
            }

            $activeRateCategoryId = $candidateCategoryId;

            if ($activeRate !== null && $activeRateCategoryId !== null) {
                $rateCalendarData = $rateManager->buildYearlyCalendar($activeRateId, $activeRateCategoryId, $rateCalendarYear);
            }
        }

        $rateCalendarBaseParams = ['section' => 'rates'];
        if ($activeRateId !== null) {
            $rateCalendarBaseParams['rateId'] = $activeRateId;
        }
        if ($activeRateCategoryId !== null) {
            $rateCalendarBaseParams['rateCategoryId'] = $activeRateCategoryId;
        }

        $rateCalendarPrevUrl = 'index.php?' . http_build_query(array_merge($rateCalendarBaseParams, [
            'rateYear' => $rateCalendarYear - 1,
        ]));
        $rateCalendarNextUrl = 'index.php?' . http_build_query(array_merge($rateCalendarBaseParams, [
            'rateYear' => $rateCalendarYear + 1,
        ]));
        $rateCalendarResetUrl = 'index.php?' . http_build_query($rateCalendarBaseParams);
    }

    foreach ($categories as $category) {
        if (!isset($category['id'])) {
            continue;
        }

        $categoryId = (int) $category['id'];
        if (!isset($rateFormData['category_prices'][$categoryId])) {
            $rateFormData['category_prices'][$categoryId] = '';
        }
        if (!isset($ratePeriodFormData['category_prices'][$categoryId])) {
            $ratePeriodFormData['category_prices'][$categoryId] = '';
        }
    }
    $options = ['<option value="">Bitte auswählen</option>'];
    foreach ($categories as $category) {
        if (!isset($category['id'])) {
            continue;
        }

        $categoryId = (int) $category['id'];
        $categoryLookup[$categoryId] = $category;
        $options[] = sprintf(
            '<option value="%d">%s</option>',
            $categoryId,
            htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8')
        );
    }
    $reservationCategoryOptionsHtml = implode('', $options);
    $rooms = $roomManager->all();
    $guests = $guestManager->all();
    if ($companyManager !== null) {
        $companies = $companyManager->all();
    }
    $users = $userManager->all();

    foreach ($users as $user) {
        if (!isset($user['id'])) {
            continue;
        }

        $userId = (int) $user['id'];
        $displayName = trim((string) ($user['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($user['email'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = 'Benutzer #' . $userId;
        }

        $reservationUserLookup[$userId] = $displayName;
    }

    foreach ($rooms as $room) {
        if (!isset($room['id'])) {
            continue;
        }

        $roomLookup[(int) $room['id']] = $room;
    }

    foreach ($guests as $guest) {
        if (isset($guest['id'])) {
            $guestLookup[(int) $guest['id']] = $guest;
        }

        if (isset($guest['company_id']) && $guest['company_id'] !== null) {
            $companyId = (int) $guest['company_id'];
            if (!isset($companyGuestCounts[$companyId])) {
                $companyGuestCounts[$companyId] = 0;
            }
            $companyGuestCounts[$companyId]++;
        }
    }

    if ($reservationFormData['guest_query'] === '' && $reservationFormData['guest_id'] !== '') {
        $reservationGuestId = (int) $reservationFormData['guest_id'];
        if ($reservationGuestId > 0 && isset($guestLookup[$reservationGuestId])) {
            $reservationFormData['guest_query'] = $buildGuestReservationLabel($guestLookup[$reservationGuestId]);
        }
    }

    foreach ($reservationFormData['category_items'] as $categoryIndex => $categoryItem) {
        $categoryOccupancy = isset($categoryItem['occupancy']) ? (string) $categoryItem['occupancy'] : '';
        if ($categoryOccupancy === '') {
            $reservationFormData['category_items'][$categoryIndex]['occupancy'] = '1';
        }

        $itemPrimaryGuestId = isset($categoryItem['primary_guest_id']) ? (int) $categoryItem['primary_guest_id'] : 0;
        if ($itemPrimaryGuestId <= 0 && $reservationFormData['guest_id'] !== '') {
            $itemPrimaryGuestId = (int) $reservationFormData['guest_id'];
            if ($itemPrimaryGuestId > 0) {
                $reservationFormData['category_items'][$categoryIndex]['primary_guest_id'] = (string) $itemPrimaryGuestId;
            }
        }

        if ($itemPrimaryGuestId > 0) {
            if (isset($guestLookup[$itemPrimaryGuestId])) {
                $reservationFormData['category_items'][$categoryIndex]['primary_guest_query'] = $buildGuestReservationLabel($guestLookup[$itemPrimaryGuestId]);
            } elseif ($guestManager instanceof GuestManager) {
                $primaryGuestRecord = $guestManager->find($itemPrimaryGuestId);
                if ($primaryGuestRecord !== null) {
                    $reservationFormData['category_items'][$categoryIndex]['primary_guest_query'] = $buildGuestReservationLabel($primaryGuestRecord);
                }
            }
        }
    }

    foreach ($companies as $company) {
        if (!isset($company['id'])) {
            continue;
        }

        $companyLookup[(int) $company['id']] = $company;
    }

    if ($reservationFormData['company_query'] === '' && $reservationFormData['company_id'] !== '') {
        $reservationCompanyId = (int) $reservationFormData['company_id'];
        if ($reservationCompanyId > 0 && isset($companyLookup[$reservationCompanyId])) {
            $reservationFormData['company_query'] = $buildCompanyReservationLabel($companyLookup[$reservationCompanyId]);
        }
    }

    $reservationGuestTooltip = '';
    if ($reservationFormData['guest_id'] !== '') {
        $reservationGuestId = (int) $reservationFormData['guest_id'];
        if ($reservationGuestId > 0 && isset($guestLookup[$reservationGuestId])) {
            $reservationGuestTooltip = $buildAddressLabel($guestLookup[$reservationGuestId]);
        }
    }

    $reservationCompanyTooltip = '';
    if ($reservationFormData['company_id'] !== '') {
        $reservationCompanyId = (int) $reservationFormData['company_id'];
        if ($reservationCompanyId > 0 && isset($companyLookup[$reservationCompanyId])) {
            $reservationCompanyTooltip = $buildAddressLabel($companyLookup[$reservationCompanyId]);
        }
    }

    if ($reservationFormData['vat_rate'] === '') {
        $reservationFormData['vat_rate'] = $overnightVatRateValue;
    }

    if (
        $reservationFormData['night_count'] === ''
        && $reservationFormData['arrival_date'] !== ''
        && $reservationFormData['departure_date'] !== ''
    ) {
        try {
            $formArrival = new DateTimeImmutable($reservationFormData['arrival_date']);
            $formDeparture = new DateTimeImmutable($reservationFormData['departure_date']);
            $diff = $formArrival->diff($formDeparture);

            if ($diff->invert !== 1) {
                $reservationFormData['night_count'] = (string) max(1, (int) $diff->days);
            }
        } catch (Throwable $exception) {
            // ignore invalid date values
        }
    }

    $roomStays = [];

    if ($reservationManager !== null) {
        $includeArchivedReservations = $showArchivedReservations || $reservationSearchTerm !== '';
        $reservations = $reservationManager->all(
            $reservationSearchTerm !== '' ? $reservationSearchTerm : null,
            $includeArchivedReservations,
            $showArchivedReservations
        );
    }

    $categoryOverbookingStays = [];

    foreach ($reservations as $index => $reservation) {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $reservationStatus = isset($reservation['status']) ? (string) $reservation['status'] : 'geplant';
        if (!isset($reservationStatusMeta[$reservationStatus])) {
            $reservationStatus = 'geplant';
        }
        $statusMeta = $reservationStatusMeta[$reservationStatus];

        $reservations[$index]['status_label'] = $statusMeta['label'];
        $reservations[$index]['status_badge_class'] = $statusMeta['badge'];

        $baseLabel = $buildGuestCalendarLabel([
            'company_name' => $reservation['company_name'] ?? '',
            'last_name' => $reservation['guest_last_name'] ?? '',
            'first_name' => $reservation['guest_first_name'] ?? '',
        ]);

        $arrivalDate = $createDateImmutable($reservation['arrival_date'] ?? null);
        $departureDate = $createDateImmutable($reservation['departure_date'] ?? null);
        $createdAt = $createDateImmutable($reservation['created_at'] ?? null);
        $updatedAt = $createDateImmutable($reservation['updated_at'] ?? null);

        $guestFirstName = isset($reservation['guest_first_name']) ? trim((string) $reservation['guest_first_name']) : '';
        $guestLastName = isset($reservation['guest_last_name']) ? trim((string) $reservation['guest_last_name']) : '';
        $guestFullNameParts = array_filter([$guestFirstName, $guestLastName], static fn ($value) => $value !== '');
        $guestFullName = trim(implode(' ', $guestFullNameParts));

        $items = $reservation['items'] ?? [];
        if ($items === []) {
            $items[] = [
                'category_id' => $reservation['category_id'] ?? null,
                'room_quantity' => $reservation['room_quantity'] ?? 1,
                'room_id' => $reservation['room_id'] ?? null,
                'category_name' => $reservation['reservation_category_name'] ?? null,
                'room_number' => $reservation['room_number'] ?? null,
            ];
        }

        $rateIdValue = isset($reservation['rate_id']) && $reservation['rate_id'] !== null
            ? (int) $reservation['rate_id']
            : null;
        $rateName = '';
        if ($rateIdValue !== null) {
            if (isset($reservation['rate_name']) && $reservation['rate_name'] !== null && $reservation['rate_name'] !== '') {
                $rateName = (string) $reservation['rate_name'];
            } elseif ($rateIdValue > 0 && isset($rateLookup[$rateIdValue]['name'])) {
                $rateName = (string) $rateLookup[$rateIdValue]['name'];
            } else {
                $rateName = 'Rate #' . $rateIdValue;
            }
        }
        if ($rateName === '') {
            $rateName = 'Keine Rate zugewiesen';
        }

        $pricePerNightValue = isset($reservation['price_per_night']) && $reservation['price_per_night'] !== null
            ? (float) $reservation['price_per_night']
            : null;
        $totalPriceValue = isset($reservation['total_price']) && $reservation['total_price'] !== null
            ? (float) $reservation['total_price']
            : null;
        $vatRateValue = isset($reservation['vat_rate']) && $reservation['vat_rate'] !== null
            ? (float) $reservation['vat_rate']
            : null;

        $pricePerNightFormatted = $pricePerNightValue !== null ? $formatCurrency($pricePerNightValue) : null;
        $totalPriceFormatted = $totalPriceValue !== null ? $formatCurrency($totalPriceValue) : null;
        $vatRateFormatted = $vatRateValue !== null ? $formatPercent($vatRateValue) : null;

        $nightCount = null;
        if ($arrivalDate instanceof DateTimeImmutable && $departureDate instanceof DateTimeImmutable) {
            $nightDiff = $arrivalDate->diff($departureDate);
            if ($nightDiff->invert !== 1) {
                $nightCount = max(1, (int) $nightDiff->days);
            }
        }
        $nightCountLabel = $nightCount !== null
            ? sprintf('%d %s', $nightCount, $nightCount === 1 ? 'Nacht' : 'Nächte')
            : null;

        $detailBase = [
            'reservationId' => $reservationId,
            'reservationNumber' => isset($reservation['reservation_number']) ? (string) $reservation['reservation_number'] : '',
            'guestId' => isset($reservation['guest_id']) ? (int) $reservation['guest_id'] : null,
            'guestName' => $guestFullName,
            'guestEmail' => isset($reservation['guest_email']) ? (string) $reservation['guest_email'] : '',
            'guestPhone' => isset($reservation['guest_phone']) ? (string) $reservation['guest_phone'] : '',
            'companyId' => isset($reservation['company_id']) && $reservation['company_id'] !== null ? (int) $reservation['company_id'] : null,
            'companyName' => isset($reservation['company_name']) ? (string) $reservation['company_name'] : '',
            'status' => $reservationStatus,
            'statusLabel' => $statusMeta['label'],
            'statusBadgeClass' => $statusMeta['badge'],
            'statusColor' => $statusMeta['color'] ?? null,
            'statusTextColor' => $statusMeta['textColor'] ?? null,
            'notes' => isset($reservation['notes']) && $reservation['notes'] !== null ? (string) $reservation['notes'] : '',
            'arrivalDate' => $arrivalDate instanceof DateTimeImmutable ? $arrivalDate->format('Y-m-d') : null,
            'arrivalDateFormatted' => $arrivalDate instanceof DateTimeImmutable ? $arrivalDate->format('d.m.Y') : null,
            'departureDate' => $departureDate instanceof DateTimeImmutable ? $departureDate->format('Y-m-d') : null,
            'departureDateFormatted' => $departureDate instanceof DateTimeImmutable ? $departureDate->format('d.m.Y') : null,
            'createdByName' => isset($reservation['created_by_name']) ? (string) $reservation['created_by_name'] : '',
            'createdById' => isset($reservation['created_by']) ? (int) $reservation['created_by'] : null,
            'createdAt' => $reservation['created_at'] ?? null,
            'createdAtFormatted' => $createdAt instanceof DateTimeImmutable ? $createdAt->format('d.m.Y H:i') : '',
            'updatedByName' => isset($reservation['updated_by_name']) ? (string) $reservation['updated_by_name'] : '',
            'updatedById' => isset($reservation['updated_by']) ? (int) $reservation['updated_by'] : null,
            'updatedAt' => $reservation['updated_at'] ?? null,
            'updatedAtFormatted' => $updatedAt instanceof DateTimeImmutable ? $updatedAt->format('d.m.Y H:i') : '',
            'editUrl' => $reservationId > 0 ? 'index.php?section=reservations&editReservation=' . $reservationId : '',
            'displayPreference' => $calendarOccupancyDisplay,
            'guestLabel' => $baseLabel,
            'rateId' => $rateIdValue,
            'rateName' => $rateName,
            'pricePerNight' => $pricePerNightValue,
            'pricePerNightFormatted' => $pricePerNightFormatted,
            'totalPrice' => $totalPriceValue,
            'totalPriceFormatted' => $totalPriceFormatted,
            'vatRate' => $vatRateValue,
            'vatRateFormatted' => $vatRateFormatted,
            'nightCount' => $nightCount,
            'nightCountLabel' => $nightCountLabel,
        ];

        $reservations[$index]['items'] = $items;
        $reservations[$index]['rate_name_display'] = $rateName;
        $reservations[$index]['price_per_night_display'] = $pricePerNightFormatted;
        $reservations[$index]['total_price_display'] = $totalPriceFormatted;
        $reservations[$index]['vat_rate_display'] = $vatRateFormatted;
        $reservations[$index]['night_count_display'] = $nightCountLabel;

        foreach ($items as $item) {
            $itemCategoryId = isset($item['category_id']) ? (int) $item['category_id'] : 0;
            $itemQuantity = isset($item['room_quantity']) ? (int) $item['room_quantity'] : 1;
            if ($itemQuantity <= 0) {
                $itemQuantity = 1;
            }

            $itemCategoryName = isset($item['category_name']) && $item['category_name'] !== null
                ? (string) $item['category_name']
                : ($itemCategoryId > 0 && isset($categoryLookup[$itemCategoryId]['name']) ? (string) $categoryLookup[$itemCategoryId]['name'] : '');

            $itemCategoryCapacity = null;
            if ($itemCategoryId > 0 && isset($categoryLookup[$itemCategoryId]['capacity'])) {
                $itemCategoryCapacity = (int) $categoryLookup[$itemCategoryId]['capacity'];
            } elseif (isset($item['room_id']) && (int) $item['room_id'] > 0) {
                $roomIdForCapacity = (int) $item['room_id'];
                if (isset($roomLookup[$roomIdForCapacity]['category_id'])) {
                    $roomCategoryId = (int) $roomLookup[$roomIdForCapacity]['category_id'];
                    if ($roomCategoryId > 0 && isset($categoryLookup[$roomCategoryId]['capacity'])) {
                        $itemCategoryCapacity = (int) $categoryLookup[$roomCategoryId]['capacity'];
                    }
                }
            }

            if ($itemCategoryCapacity !== null && $itemCategoryCapacity <= 0) {
                $itemCategoryCapacity = null;
            }

            $primaryGuestId = isset($item['primary_guest_id']) ? (int) $item['primary_guest_id'] : 0;
            $primaryGuestLabel = '';
            if ($primaryGuestId > 0) {
                if (isset($guestLookup[$primaryGuestId])) {
                    $primaryGuestLabel = $buildGuestReservationLabel($guestLookup[$primaryGuestId]);
                } else {
                    $primaryGuestLabel = $buildGuestReservationLabel([
                        'id' => $primaryGuestId,
                        'first_name' => $item['primary_guest_first_name'] ?? '',
                        'last_name' => $item['primary_guest_last_name'] ?? '',
                        'company_name' => $item['primary_guest_company_name'] ?? '',
                    ]);
                }
            }

            $itemGuestCount = null;
            if (isset($item['occupancy']) && $item['occupancy'] !== null) {
                $itemGuestCount = (int) $item['occupancy'];
                if ($itemGuestCount <= 0) {
                    $itemGuestCount = null;
                }
            }

            if ($itemGuestCount === null) {
                if ($itemCategoryCapacity !== null) {
                    $itemGuestCount = $itemCategoryCapacity * $itemQuantity;
                } elseif ($itemQuantity > 0) {
                    $itemGuestCount = $itemQuantity;
                }
            }

            $itemLabel = $baseLabel;
            if ($itemGuestCount !== null && $itemGuestCount > 0) {
                $itemLabel .= sprintf(' (%d)', $itemGuestCount);
            }

            $itemRoomId = isset($item['room_id']) ? (int) $item['room_id'] : 0;
            $itemId = isset($item['id']) ? (int) $item['id'] : null;
            $itemRoomNumber = '';
            if (isset($item['room_number']) && $item['room_number'] !== null) {
                $itemRoomNumber = trim((string) $item['room_number']);
            }
            if ($itemRoomId > 0 && $itemRoomNumber === '' && isset($roomLookup[$itemRoomId]['room_number'])) {
                $itemRoomNumber = trim((string) $roomLookup[$itemRoomId]['room_number']);
            }

            $itemDetail = $detailBase;
            $itemDetail['itemId'] = $itemId;
            $itemDetail['categoryId'] = $itemCategoryId > 0 ? $itemCategoryId : null;
            $itemDetail['categoryName'] = $itemCategoryName;
            $itemDetail['roomId'] = $itemRoomId > 0 ? $itemRoomId : null;
            $itemDetail['roomNumber'] = $itemRoomNumber;
            $itemDetail['roomQuantity'] = $itemQuantity;
            $itemDetail['type'] = $itemRoomId > 0 ? 'room' : 'overbooking';
            $itemDetail['guestCount'] = $itemGuestCount;
            $itemDetail['occupancy'] = $itemGuestCount;
            $itemDetail['primaryGuestId'] = $primaryGuestId > 0 ? $primaryGuestId : null;
            $itemDetail['primaryGuestName'] = $primaryGuestLabel;
            $itemDetail['categoryCapacity'] = $itemCategoryCapacity;

            $itemRateId = isset($item['rate_id']) ? (int) $item['rate_id'] : 0;
            $itemRateName = '';
            if (isset($item['rate_name']) && $item['rate_name'] !== null && $item['rate_name'] !== '') {
                $itemRateName = (string) $item['rate_name'];
            } elseif ($itemRateId > 0 && isset($rateLookup[$itemRateId]['name'])) {
                $itemRateName = (string) $rateLookup[$itemRateId]['name'];
            }

            if ($itemRateName === '') {
                $itemRateName = $detailBase['rateName'] ?? 'Keine Rate zugewiesen';
            }

            $itemDetail['rateId'] = $itemRateId > 0 ? $itemRateId : null;
            $itemDetail['rateName'] = $itemRateName;

            $itemArrivalDate = $createDateImmutable($item['arrival_date'] ?? null) ?? $arrivalDate;
            $itemDepartureDate = $createDateImmutable($item['departure_date'] ?? null) ?? $departureDate;

            if (isset($item['price_per_night']) && $item['price_per_night'] !== null) {
                $itemDetail['pricePerNight'] = (float) $item['price_per_night'];
            }
            if (isset($item['total_price']) && $item['total_price'] !== null) {
                $itemDetail['totalPrice'] = (float) $item['total_price'];
            }

            if ($itemDetail['type'] === 'overbooking') {
                $itemDetail['roomName'] = 'Überbuchung' . ($itemCategoryName !== '' ? ' – ' . $itemCategoryName : '');
            } else {
                $roomNumberLabel = $itemRoomNumber !== '' ? $itemRoomNumber : ($itemRoomId > 0 ? (string) $itemRoomId : '');
                $itemDetail['roomName'] = $roomNumberLabel !== '' ? 'Zimmer ' . $roomNumberLabel : 'Zimmer';
            }

            if ($itemArrivalDate instanceof DateTimeImmutable) {
                $itemDetail['arrivalDate'] = $itemArrivalDate->format('Y-m-d');
                $itemDetail['arrivalDateFormatted'] = $itemArrivalDate->format('d.m.Y');
            }
            if ($itemDepartureDate instanceof DateTimeImmutable) {
                $itemDetail['departureDate'] = $itemDepartureDate->format('Y-m-d');
                $itemDetail['departureDateFormatted'] = $itemDepartureDate->format('d.m.Y');
            }

            if ($itemArrivalDate instanceof DateTimeImmutable && $itemDepartureDate instanceof DateTimeImmutable) {
                $nightDiff = $itemArrivalDate->diff($itemDepartureDate);
                if ($nightDiff->invert !== 1) {
                    $nightCount = max(1, (int) $nightDiff->days);
                    $itemDetail['nightCount'] = $nightCount;
                    $itemDetail['nightCountLabel'] = sprintf('%d %s', $nightCount, $nightCount === 1 ? 'Nacht' : 'Nächte');
                }
            }

            if (isset($itemDetail['pricePerNight'])) {
                $formatted = $formatCurrency($itemDetail['pricePerNight']);
                if ($formatted !== null) {
                    $itemDetail['pricePerNightFormatted'] = $formatted;
                }
            }
            if (isset($itemDetail['totalPrice'])) {
                $formattedTotal = $formatCurrency($itemDetail['totalPrice']);
                if ($formattedTotal !== null) {
                    $itemDetail['totalPriceFormatted'] = $formattedTotal;
                }
            }

            $itemArticlesRaw = isset($item['articles']) && is_array($item['articles']) ? $item['articles'] : [];
            $itemArticlesSummary = [];
            $itemArticlesTotal = 0.0;

            foreach ($itemArticlesRaw as $articleEntry) {
                if (!is_array($articleEntry)) {
                    continue;
                }

                $articleId = isset($articleEntry['article_id']) ? (int) $articleEntry['article_id'] : 0;
                $articleName = isset($articleEntry['article_name']) ? trim((string) $articleEntry['article_name']) : '';
                if ($articleName === '' && $articleId > 0 && isset($articleLookup[$articleId]['name'])) {
                    $articleName = trim((string) $articleLookup[$articleId]['name']);
                }
                if ($articleName === '') {
                    $articleName = $articleId > 0 ? 'Artikel #' . $articleId : 'Artikel';
                }

                $articleQuantity = null;
                if (isset($articleEntry['quantity']) && $articleEntry['quantity'] !== null && $articleEntry['quantity'] !== '') {
                    $articleQuantity = (int) $articleEntry['quantity'];
                    if ($articleQuantity <= 0) {
                        $articleQuantity = null;
                    }
                }

                $pricingTypeKey = isset($articleEntry['pricing_type']) ? (string) $articleEntry['pricing_type'] : ArticleManager::PRICING_PER_DAY;
                if (!isset($articlePricingTypes[$pricingTypeKey])) {
                    $pricingTypeKey = ArticleManager::PRICING_PER_DAY;
                }
                $pricingLabel = $articlePricingTypes[$pricingTypeKey] ?? '';

                $unitPriceValue = isset($articleEntry['unit_price']) && $articleEntry['unit_price'] !== null && $articleEntry['unit_price'] !== ''
                    ? (float) $articleEntry['unit_price']
                    : 0.0;
                $totalPriceValue = isset($articleEntry['total_price']) && $articleEntry['total_price'] !== null && $articleEntry['total_price'] !== ''
                    ? (float) $articleEntry['total_price']
                    : 0.0;
                $itemArticlesTotal += $totalPriceValue;

                $unitPriceFormatted = $unitPriceValue > 0 ? $formatCurrency($unitPriceValue) : null;
                $totalPriceFormatted = $totalPriceValue > 0 ? $formatCurrency($totalPriceValue) : null;

                $taxRateValue = null;
                if (isset($articleEntry['tax_rate']) && $articleEntry['tax_rate'] !== null && $articleEntry['tax_rate'] !== '') {
                    $taxRateValue = (float) $articleEntry['tax_rate'];
                }
                if ($taxRateValue !== null && $taxRateValue < 0) {
                    $taxRateValue = null;
                }
                $taxRateFormatted = $taxRateValue !== null ? $formatPercent($taxRateValue) : null;

                $itemArticlesSummary[] = [
                    'id' => $articleId > 0 ? $articleId : null,
                    'name' => $articleName,
                    'quantity' => $articleQuantity,
                    'pricing_type' => $pricingTypeKey,
                    'pricing_label' => $pricingLabel,
                    'unit_price' => $unitPriceValue,
                    'unit_price_formatted' => $unitPriceFormatted,
                    'total_price' => $totalPriceValue,
                    'total_price_formatted' => $totalPriceFormatted,
                    'tax_rate' => $taxRateValue,
                    'tax_rate_formatted' => $taxRateFormatted,
                ];
            }

            $articleTotalLabel = '';
            if ($itemArticlesSummary !== []) {
                $itemDetail['articles'] = $itemArticlesSummary;
                $itemDetail['articlesTotal'] = $itemArticlesTotal;
                $articlesFormattedTotal = $itemArticlesTotal > 0 ? $formatCurrency($itemArticlesTotal) : null;
                if ($articlesFormattedTotal !== null) {
                    $itemDetail['articlesTotalFormatted'] = $articlesFormattedTotal;
                    $articleTotalLabel = $articlesFormattedTotal;
                }
            }

            if ($itemRoomId > 0) {
                $roomStays[$itemRoomId][] = [
                    'label' => $itemLabel,
                    'arrival' => $itemArrivalDate,
                    'departure' => $itemDepartureDate,
                    'guestCount' => $itemGuestCount,
                    'occupancy' => $itemGuestCount,
                    'primaryGuestName' => $primaryGuestLabel,
                    'details' => $itemDetail,
                ];
                continue;
            }

            if ($itemCategoryId <= 0) {
                continue;
            }

            if (!isset($categoryOverbookingStats[$itemCategoryId])) {
                $categoryOverbookingStats[$itemCategoryId] = [
                    'reservations' => 0,
                    'rooms' => 0,
                ];
            }

            $categoryOverbookingStats[$itemCategoryId]['reservations']++;
            $categoryOverbookingStats[$itemCategoryId]['rooms'] += $itemQuantity;

            $categoryOverbookingStays[$itemCategoryId][] = [
                'label' => $itemLabel,
                'arrival' => $itemArrivalDate,
                'departure' => $itemDepartureDate,
                'quantity' => $itemQuantity,
                'guestCount' => $itemGuestCount,
                'occupancy' => $itemGuestCount,
                'primaryGuestName' => $primaryGuestLabel,
                'details' => $itemDetail,
            ];
        }
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
                    $entry = $stay['details'] ?? [];
                    if (!is_array($entry)) {
                        $entry = [];
                    }

                    $entry['label'] = $stay['label'];
                    if (!isset($entry['guestCount']) && isset($stay['guestCount'])) {
                        $entry['guestCount'] = $stay['guestCount'];
                    }
                    $entry['calendarDate'] = $dateKey;
                    $entry['date'] = $dateKey;
                    if (!isset($entry['roomId']) || $entry['roomId'] === null) {
                        $entry['roomId'] = $roomId > 0 ? $roomId : null;
                    }
                    if (!isset($entry['roomNumber']) || $entry['roomNumber'] === '') {
                        if (isset($roomLookup[$roomId]['room_number']) && $roomLookup[$roomId]['room_number'] !== null) {
                            $entry['roomNumber'] = trim((string) $roomLookup[$roomId]['room_number']);
                        } else {
                            $entry['roomNumber'] = $roomId > 0 ? (string) $roomId : '';
                        }
                    }
                    if (!isset($entry['roomName']) || $entry['roomName'] === '') {
                        $roomNumberLabel = $entry['roomNumber'] ?? '';
                        $entry['roomName'] = $roomNumberLabel !== '' ? 'Zimmer ' . $roomNumberLabel : 'Zimmer';
                    }
                    if (!isset($entry['roomQuantity'])) {
                        $entry['roomQuantity'] = 1;
                    }
                    if (!isset($entry['type'])) {
                        $entry['type'] = 'room';
                    }

                    $roomOccupancies[$roomId][$dateKey][] = $entry;
                }
            }
        }

        foreach ($categoryOverbookingStays as $categoryId => $stays) {
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

                $stayQuantity = isset($stay['quantity']) ? (int) $stay['quantity'] : 1;
                if ($stayQuantity <= 0) {
                    $stayQuantity = 1;
                }

                for ($cursor = $effectiveStart; $cursor < $effectiveEnd; $cursor = $cursor->modify('+1 day')) {
                    $dateKey = $cursor->format('Y-m-d');
                    if (!isset($categoryOverbookingOccupancies[$categoryId][$dateKey])) {
                        $categoryOverbookingOccupancies[$categoryId][$dateKey] = [
                            'labels' => [],
                            'quantity' => 0,
                            'entries' => [],
                        ];
                    }

                    $entry = $stay['details'] ?? [];
                    if (!is_array($entry)) {
                        $entry = [];
                    }

                    $entry['label'] = $stay['label'];
                    if (!isset($entry['guestCount']) && isset($stay['guestCount'])) {
                        $entry['guestCount'] = $stay['guestCount'];
                    }
                    $entry['calendarDate'] = $dateKey;
                    $entry['date'] = $dateKey;
                    $entry['roomQuantity'] = $entry['roomQuantity'] ?? $stayQuantity;
                    if (!isset($entry['categoryId']) && $categoryId > 0) {
                        $entry['categoryId'] = $categoryId;
                    }
                    if (!isset($entry['categoryName']) || $entry['categoryName'] === '') {
                        $entry['categoryName'] = isset($categoryLookup[$categoryId]['name']) ? (string) $categoryLookup[$categoryId]['name'] : '';
                    }
                    if (!isset($entry['roomName']) || $entry['roomName'] === '') {
                        $categoryNameForRoom = $entry['categoryName'] !== '' ? $entry['categoryName'] : 'Kategorie';
                        $entry['roomName'] = 'Überbuchung – ' . $categoryNameForRoom;
                    }
                    $entry['type'] = 'overbooking';
                    if (!isset($entry['roomId'])) {
                        $entry['roomId'] = null;
                    }

                    $categoryOverbookingOccupancies[$categoryId][$dateKey]['labels'][] = $stay['label'];
                    $categoryOverbookingOccupancies[$categoryId][$dateKey]['quantity'] += $stayQuantity;
                    $categoryOverbookingOccupancies[$categoryId][$dateKey]['entries'][] = $entry;
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
            'overbookings' => [],
            'overbookingReservations' => 0,
            'overbookingRooms' => 0,
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
                    'overbookings' => [],
                    'overbookingReservations' => 0,
                    'overbookingRooms' => 0,
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

    foreach ($calendarCategoryGroups as $index => $group) {
        $categoryId = $group['category']['id'] ?? null;
        if ($categoryId !== null && isset($categoryOverbookingOccupancies[$categoryId])) {
            $calendarCategoryGroups[$index]['overbookings'] = $categoryOverbookingOccupancies[$categoryId];
            $calendarCategoryGroups[$index]['overbookingReservations'] = $categoryOverbookingStats[$categoryId]['reservations'] ?? 0;
            $calendarCategoryGroups[$index]['overbookingRooms'] = $categoryOverbookingStats[$categoryId]['rooms'] ?? 0;
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
            'sort_order' => isset($categoryToEdit['sort_order']) ? (int) $categoryToEdit['sort_order'] : '',
        ];
    } elseif ($alert === null) {
        $alert = [
            'type' => 'warning',
            'message' => 'Die ausgewählte Kategorie wurde nicht gefunden.',
        ];
    }
}

if ($pdo !== null && isset($_GET['editArticle']) && $articleFormData['id'] === null) {
    if ($articleManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Artikel können derzeit nicht bearbeitet werden.',
            ];
        }
    } else {
        $articleToEdit = $articleManager->find((int) $_GET['editArticle']);

        if ($articleToEdit) {
            $articleFormData = [
                'id' => (int) $articleToEdit['id'],
                'name' => (string) ($articleToEdit['name'] ?? ''),
                'description' => (string) ($articleToEdit['description'] ?? ''),
                'price' => isset($articleToEdit['price']) ? number_format((float) $articleToEdit['price'], 2, ',', '.') : '',
                'pricing_type' => (string) ($articleToEdit['pricing_type'] ?? ArticleManager::PRICING_PER_DAY),
                'tax_category_id' => isset($articleToEdit['tax_category_id']) && $articleToEdit['tax_category_id'] !== null
                    ? (string) (int) $articleToEdit['tax_category_id']
                    : '',
            ];
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Der ausgewählte Artikel wurde nicht gefunden.',
            ];
        }
    }
}

if ($pdo !== null && isset($_GET['editTaxCategory']) && $taxCategoryFormData['id'] === null) {
    if ($taxCategoryManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Mehrwertsteuer-Kategorien können derzeit nicht bearbeitet werden.',
            ];
        }
    } else {
        $taxCategoryToEdit = $taxCategoryManager->find((int) $_GET['editTaxCategory']);

        if ($taxCategoryToEdit) {
            $taxCategoryFormData = [
                'id' => (int) $taxCategoryToEdit['id'],
                'name' => (string) ($taxCategoryToEdit['name'] ?? ''),
                'rate' => isset($taxCategoryToEdit['rate']) ? number_format((float) $taxCategoryToEdit['rate'], 2, ',', '.') : '',
            ];
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Die ausgewählte Mehrwertsteuer-Kategorie wurde nicht gefunden.',
            ];
        }
    }
}

$articleFormMode = $articleFormData['id'] !== null ? 'update' : 'create';
$taxCategoryFormMode = $taxCategoryFormData['id'] !== null ? 'update' : 'create';

if ($pdo !== null && isset($_GET['editRate']) && $rateFormData['id'] === null) {
    if ($rateManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
            ];
        }
    } else {
        $rateToEdit = $rateManager->find((int) $_GET['editRate']);

        if ($rateToEdit) {
            $rateFormMode = 'update';
            $activeRateId = (int) $rateToEdit['id'];
            $requestedRateId = $activeRateId;

            $formCategoryPrices = [];
            if (isset($rateToEdit['category_prices']) && is_array($rateToEdit['category_prices'])) {
                foreach ($rateToEdit['category_prices'] as $categoryId => $priceValue) {
                    $formCategoryPrices[(int) $categoryId] = number_format((float) $priceValue, 2, ',', '');
                }
            }

            if ($activeRateCategoryId === null && $formCategoryPrices !== []) {
                $priceKeys = array_keys($formCategoryPrices);
                if ($priceKeys !== []) {
                    $activeRateCategoryId = (int) $priceKeys[0];
                }
            }

            $rateFormData = [
                'id' => (int) $rateToEdit['id'],
                'name' => $rateToEdit['name'],
                'description' => $rateToEdit['description'] ?? '',
                'category_prices' => $formCategoryPrices,
            ];
            foreach ($categories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $categoryId = (int) $category['id'];
                if (!isset($rateFormData['category_prices'][$categoryId])) {
                    $rateFormData['category_prices'][$categoryId] = '';
                }
            }
            $ratePeriods = $rateManager->periodsForRate($activeRateId);
            if ($activeRateCategoryId !== null) {
                $rateCalendarData = $rateManager->buildYearlyCalendar($activeRateId, $activeRateCategoryId, $rateCalendarYear);
            }
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Die ausgewählte Rate wurde nicht gefunden.',
            ];
        }
    }
}

if ($pdo !== null && isset($_GET['editRatePeriod']) && $ratePeriodFormData['id'] === null) {
    if ($rateManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
            ];
        }
    } else {
        $periodToEdit = $rateManager->findPeriod((int) $_GET['editRatePeriod']);

        if ($periodToEdit) {
            $ratePeriodFormMode = 'update';
            $associatedRateId = isset($periodToEdit['rate_id']) ? (int) $periodToEdit['rate_id'] : 0;
            if ($associatedRateId > 0) {
                $activeRateId = $associatedRateId;
                $requestedRateId = $associatedRateId;
            }

            $formPeriodCategoryPrices = [];
            if (isset($periodToEdit['category_prices']) && is_array($periodToEdit['category_prices'])) {
                foreach ($periodToEdit['category_prices'] as $categoryId => $priceValue) {
                    $formPeriodCategoryPrices[(int) $categoryId] = number_format((float) $priceValue, 2, ',', '');
                }
            }

            $ratePeriodFormData = [
                'id' => (int) $periodToEdit['id'],
                'rate_id' => $associatedRateId > 0 ? (string) $associatedRateId : '',
                'start_date' => $periodToEdit['start_date'] ?? '',
                'end_date' => $periodToEdit['end_date'] ?? '',
                'days_of_week' => $periodToEdit['days_of_week_list'] ?? [],
                'category_prices' => $formPeriodCategoryPrices,
            ];
            foreach ($categories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $categoryId = (int) $category['id'];
                if (!isset($ratePeriodFormData['category_prices'][$categoryId])) {
                    $ratePeriodFormData['category_prices'][$categoryId] = '';
                }
            }
            if ($associatedRateId > 0) {
                $ratePeriods = $rateManager->periodsForRate($associatedRateId);
                if ($activeRateCategoryId === null && $formPeriodCategoryPrices !== []) {
                    $categoryPriceKeys = array_keys($formPeriodCategoryPrices);
                    if ($categoryPriceKeys !== []) {
                        $activeRateCategoryId = (int) $categoryPriceKeys[0];
                    }
                }

                if ($activeRateCategoryId !== null) {
                    $rateCalendarData = $rateManager->buildYearlyCalendar($associatedRateId, $activeRateCategoryId, $rateCalendarYear);
                }
            }
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Der ausgewählte Zeitraum wurde nicht gefunden.',
            ];
        }
    }
}

if ($pdo !== null && isset($_GET['editRateEvent']) && $rateEventFormData['id'] === null) {
    if ($rateManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Die Ratenverwaltung ist derzeit nicht verfügbar.',
            ];
        }
    } else {
        $eventToEdit = $rateManager->findEvent((int) $_GET['editRateEvent']);

        if ($eventToEdit) {
            $rateEventFormMode = 'update';
            $associatedRateId = isset($eventToEdit['rate_id']) ? (int) $eventToEdit['rate_id'] : 0;
            if ($associatedRateId > 0) {
                $activeRateId = $associatedRateId;
                $requestedRateId = $associatedRateId;
            }

            $formEventCategoryPrices = [];
            if (isset($eventToEdit['category_prices']) && is_array($eventToEdit['category_prices'])) {
                foreach ($eventToEdit['category_prices'] as $categoryId => $priceValue) {
                    $formEventCategoryPrices[(int) $categoryId] = number_format((float) $priceValue, 2, ',', '');
                }
            }

            $rateEventFormData = [
                'id' => (int) $eventToEdit['id'],
                'rate_id' => $associatedRateId > 0 ? (string) $associatedRateId : '',
                'name' => $eventToEdit['name'] ?? '',
                'start_date' => $eventToEdit['start_date'] ?? '',
                'end_date' => $eventToEdit['end_date'] ?? '',
                'default_price' => isset($eventToEdit['default_price']) && $eventToEdit['default_price'] !== null
                    ? number_format((float) $eventToEdit['default_price'], 2, ',', '')
                    : '',
                'color' => $eventToEdit['color'] ?? '#B91C1C',
                'description' => $eventToEdit['description'] ?? '',
                'category_prices' => $formEventCategoryPrices,
            ];

            foreach ($categories as $category) {
                if (!isset($category['id'])) {
                    continue;
                }

                $categoryId = (int) $category['id'];
                if (!isset($rateEventFormData['category_prices'][$categoryId])) {
                    $rateEventFormData['category_prices'][$categoryId] = '';
                }
            }

            if ($associatedRateId > 0) {
                $rateEvents = $rateManager->eventsForRate($associatedRateId);
                if ($activeRateCategoryId === null && $formEventCategoryPrices !== []) {
                    $categoryPriceKeys = array_keys($formEventCategoryPrices);
                    if ($categoryPriceKeys !== []) {
                        $activeRateCategoryId = (int) $categoryPriceKeys[0];
                    }
                }

                if ($activeRateCategoryId !== null) {
                    $rateCalendarData = $rateManager->buildYearlyCalendar($associatedRateId, $activeRateCategoryId, $rateCalendarYear);
                }
            }
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Die ausgewählte Messe wurde nicht gefunden.',
            ];
        }
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

if ($pdo !== null && isset($_GET['editReservation']) && $reservationFormData['id'] === null) {
    if ($reservationManager === null) {
        if ($alert === null) {
            $alert = [
                'type' => 'danger',
                'message' => 'Die Reservierungsverwaltung ist derzeit nicht verfügbar.',
            ];
        }
    } else {
        $reservationToEdit = $reservationManager->find((int) $_GET['editReservation']);

        if ($reservationToEdit) {
            $itemsForForm = $reservationToEdit['items'] ?? [];
            if ($itemsForForm === []) {
                $itemsForForm[] = [
                    'category_id' => $reservationToEdit['category_id'] ?? null,
                    'room_quantity' => $reservationToEdit['room_quantity'] ?? 1,
                ];
            }

            $normalizedItems = [];
            $existingTotalPrice = 0.0;
            foreach ($itemsForForm as $item) {
                $pricePerNightFormatted = isset($item['price_per_night']) && $item['price_per_night'] !== null
                    ? number_format((float) $item['price_per_night'], 2, ',', '.')
                    : '';
                $totalPriceValue = isset($item['total_price']) && $item['total_price'] !== null
                    ? (float) $item['total_price']
                    : null;
                if ($totalPriceValue !== null) {
                    $existingTotalPrice += $totalPriceValue;
                }

                $primaryGuestIdForForm = isset($item['primary_guest_id']) ? (int) $item['primary_guest_id'] : 0;
                $primaryGuestLabelForForm = isset($item['primary_guest_label']) && $item['primary_guest_label'] !== null
                    ? (string) $item['primary_guest_label']
                    : '';

                if ($primaryGuestLabelForForm === '' && $primaryGuestIdForForm > 0) {
                    if (isset($guestLookup[$primaryGuestIdForForm])) {
                        $primaryGuestLabelForForm = $buildGuestReservationLabel($guestLookup[$primaryGuestIdForForm]);
                    } else {
                        $primaryGuestLabelForForm = $buildGuestReservationLabel([
                            'id' => $primaryGuestIdForForm,
                            'first_name' => $item['primary_guest_first_name'] ?? '',
                            'last_name' => $item['primary_guest_last_name'] ?? '',
                            'company_name' => $item['primary_guest_company_name'] ?? '',
                        ]);
                    }
                }

                $articlesForForm = [];
                $articlesTotalForForm = 0.0;
                if (isset($item['articles']) && is_array($item['articles'])) {
                    foreach ($item['articles'] as $articleRow) {
                        if (!is_array($articleRow)) {
                            continue;
                        }

                        $articleIdValue = isset($articleRow['article_id']) ? (int) $articleRow['article_id'] : 0;
                        $articleQuantityValue = isset($articleRow['quantity']) ? (float) $articleRow['quantity'] : 0.0;
                        if ($articleQuantityValue <= 0) {
                            $articleQuantityValue = 1.0;
                        }

                        $articleTotalValue = isset($articleRow['total_price']) && $articleRow['total_price'] !== null
                            ? (float) $articleRow['total_price']
                            : 0.0;

                        if ($articleTotalValue <= 0.0 && isset($articleRow['unit_price'])) {
                            $articleTotalValue = (float) $articleRow['unit_price'] * $articleQuantityValue;
                        }

                        if ($articleTotalValue > 0.0) {
                            $articlesTotalForForm += $articleTotalValue;
                        }

                        $pricingTypeValue = isset($articleRow['pricing_type']) && $articleRow['pricing_type'] !== null
                            ? (string) $articleRow['pricing_type']
                            : ArticleManager::PRICING_PER_DAY;

                        if ($articleIdValue > 0 && isset($articleLookup[$articleIdValue]['pricing_type'])) {
                            $pricingTypeValue = (string) $articleLookup[$articleIdValue]['pricing_type'];
                        }

                        $quantityLabel = (string) $articleQuantityValue;
                        if (abs($articleQuantityValue - round($articleQuantityValue)) < 0.00001) {
                            $quantityLabel = (string) (int) round($articleQuantityValue);
                        } elseif (strpos($quantityLabel, '.') !== false) {
                            $quantityLabel = rtrim(rtrim(number_format($articleQuantityValue, 2, '.', ''), '0'), '.');
                        }

                        $articlesForForm[] = [
                            'article_id' => $articleIdValue > 0 ? (string) $articleIdValue : '',
                            'quantity' => $pricingTypeValue === ArticleManager::PRICING_PER_PERSON_PER_DAY
                                ? '1'
                                : $quantityLabel,
                            'total_price' => $articleTotalValue > 0.0
                                ? number_format($articleTotalValue, 2, ',', '.')
                                : '',
                            'pricing_type' => $pricingTypeValue,
                        ];
                    }
                }

                if ($articlesForForm === []) {
                    $articlesForForm[] = [
                        'article_id' => '',
                        'quantity' => '1',
                        'total_price' => '',
                        'pricing_type' => ArticleManager::PRICING_PER_DAY,
                    ];
                }

                $normalizedItems[] = [
                    'category_id' => isset($item['category_id']) && $item['category_id'] !== null ? (string) $item['category_id'] : '',
                    'room_quantity' => isset($item['room_quantity']) && (int) $item['room_quantity'] > 0 ? (string) $item['room_quantity'] : '1',
                    'occupancy' => isset($item['occupancy']) && (int) $item['occupancy'] > 0 ? (string) $item['occupancy'] : '1',
                    'room_id' => isset($item['room_id']) && $item['room_id'] !== null ? (string) $item['room_id'] : '',
                    'arrival_date' => isset($item['arrival_date']) && $item['arrival_date'] !== null ? (string) $item['arrival_date'] : ($reservationToEdit['arrival_date'] ?? ''),
                    'departure_date' => isset($item['departure_date']) && $item['departure_date'] !== null ? (string) $item['departure_date'] : ($reservationToEdit['departure_date'] ?? ''),
                    'rate_id' => isset($item['rate_id']) && $item['rate_id'] !== null ? (string) $item['rate_id'] : '',
                    'price_per_night' => $pricePerNightFormatted,
                    'total_price' => $totalPriceValue !== null ? number_format($totalPriceValue, 2, ',', '.') : '',
                    'primary_guest_id' => isset($item['primary_guest_id']) && $item['primary_guest_id'] !== null ? (string) $item['primary_guest_id'] : '',
                    'primary_guest_query' => isset($item['primary_guest_label']) && $item['primary_guest_label'] !== null ? (string) $item['primary_guest_label'] : '',
                    'articles' => $articlesForForm,
                    'articles_total' => $articlesTotalForForm > 0.0 ? number_format($articlesTotalForForm, 2, ',', '.') : '',
                ];
            }

            $existingVatRateValue = isset($reservationToEdit['vat_rate']) && $reservationToEdit['vat_rate'] !== null
                ? number_format((float) $reservationToEdit['vat_rate'], 2, '.', '')
                : $overnightVatRateValue;

            $reservationFormData = [
                'id' => (int) $reservationToEdit['id'],
                'guest_id' => (string) $reservationToEdit['guest_id'],
                'guest_query' => $buildGuestReservationLabel([
                    'id' => $reservationToEdit['guest_id'],
                    'first_name' => $reservationToEdit['guest_first_name'] ?? '',
                    'last_name' => $reservationToEdit['guest_last_name'] ?? '',
                    'company_name' => $reservationToEdit['company_name'] ?? '',
                ]),
                'company_id' => isset($reservationToEdit['company_id']) && $reservationToEdit['company_id'] !== null ? (string) $reservationToEdit['company_id'] : '',
                'company_query' => isset($reservationToEdit['company_id'], $reservationToEdit['company_name']) && $reservationToEdit['company_name'] !== null ? $buildCompanyReservationLabel([
                    'id' => $reservationToEdit['company_id'],
                    'name' => $reservationToEdit['company_name'],
                ]) : '',
                'room_id' => isset($reservationToEdit['room_id']) && $reservationToEdit['room_id'] !== null ? (string) $reservationToEdit['room_id'] : '',
                'arrival_date' => $reservationToEdit['arrival_date'],
                'departure_date' => $reservationToEdit['departure_date'],
                'night_count' => isset($reservationToEdit['night_count']) && $reservationToEdit['night_count'] !== null
                    ? (string) $reservationToEdit['night_count']
                    : '',
                'status' => $reservationToEdit['status'],
                'notes' => $reservationToEdit['notes'] ?? '',
                'reservation_number' => isset($reservationToEdit['reservation_number']) ? (string) $reservationToEdit['reservation_number'] : '',
                'grand_total' => $existingTotalPrice > 0 ? number_format($existingTotalPrice, 2, ',', '.') : '',
                'vat_rate' => $existingVatRateValue,
                'category_items' => $normalizedItems,
            ];
            $reservationFormMode = 'update';
            $isEditingReservation = true;
        } elseif ($alert === null) {
            $alert = [
                'type' => 'warning',
                'message' => 'Die ausgewählte Reservierung wurde nicht gefunden.',
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

$shouldOpenReservationModal = false;
if ($activeSection === 'reservations') {
    if ($isEditingReservation) {
        $shouldOpenReservationModal = true;
    } elseif ($openReservationModalRequested && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $reservationFormData = $reservationFormDefaults;
        $reservationFormMode = 'create';
        $isEditingReservation = false;
        $shouldOpenReservationModal = true;
    } elseif (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['form'])
        && in_array($_POST['form'], ['reservation_create', 'reservation_update'], true)
        && $alert !== null
    ) {
        $shouldOpenReservationModal = true;
    }
}

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
                <li><a class="dropdown-item" href="index.php?section=reservations&amp;openReservationModal=1">Neue Reservierung</a></li>
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
      <div class="app-container container-fluid">
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
        <div class="col-12">
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
                  <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($calendarPrevUrl) ?>" title="Vorherige <?= $calendarViewLength ?> Tage" aria-label="Vorherige <?= $calendarViewLength ?> Tage">&laquo;</a>
                  <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($calendarTodayUrl) ?>" title="Zurück zu heute">Heute</a>
                  <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($calendarNextUrl) ?>" title="Nächste <?= $calendarViewLength ?> Tage" aria-label="Nächste <?= $calendarViewLength ?> Tage">&raquo;</a>
                  <form method="get" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="section" value="dashboard">
                    <input type="hidden" name="occupancyDisplay" value="<?= htmlspecialchars($calendarOccupancyDisplay) ?>">
                    <label for="calendar-date" class="visually-hidden">Datum auswählen</label>
                    <input type="date" id="calendar-date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($calendarCurrentDateValue) ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Springen</button>
                  </form>
                  <div class="calendar-display-toggle d-flex align-items-center gap-2">
                    <span class="text-muted small">Anzeige:</span>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Anzeige der Kalenderbelegung">
                      <?php foreach ($calendarOccupancyDisplayOptions as $displayKey => $displayLabel): ?>
                        <?php
                          $isActiveDisplay = $calendarOccupancyDisplay === $displayKey;
                          $displayUrl = $calendarDisplayToggleUrls[$displayKey] ?? '#';
                        ?>
                        <a
                          class="btn btn-outline-secondary<?= $isActiveDisplay ? ' active' : '' ?>"
                          href="<?= htmlspecialchars($displayUrl) ?>"
                          role="button"
                          aria-pressed="<?= $isActiveDisplay ? 'true' : 'false' ?>"
                        ><?= htmlspecialchars($displayLabel) ?></a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </div>
              </div>
              <div class="calendar-grid-wrapper">
                <table class="table table-bordered align-middle room-calendar">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" class="category-column">Kategorie</th>
                      <th scope="col" class="room-column">Zimmer / Typ</th>
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
                        <?php
                          $roomsInGroup = array_values($group['rooms'] ?? []);
                          $roomRowCount = count($roomsInGroup);
                          $rowsForRooms = $roomRowCount > 0 ? $roomRowCount : 1;
                          $categoryRowspan = $rowsForRooms + 2;
                          $categoryData = $group['category'] ?? ['name' => 'Unbekannte Kategorie'];
                          $overbookingData = $group['overbookings'] ?? [];
                          $categoryDayStats = [];
                          foreach ($days as $day) {
                              $dateKey = $day['date'];
                              $assignedCount = 0;
                              foreach ($roomsInGroup as $room) {
                                  $roomId = isset($room['id']) ? (int) $room['id'] : 0;
                                  if ($roomId > 0 && !empty($roomOccupancies[$roomId][$dateKey])) {
                                      $assignedCount++;
                                  }
                              }

                              $overbookingCell = $overbookingData[$dateKey] ?? ['quantity' => 0];
                              $overbookedRooms = isset($overbookingCell['quantity']) ? (int) $overbookingCell['quantity'] : 0;
                              if ($overbookedRooms < 0) {
                                  $overbookedRooms = 0;
                              }

                              $freeCount = $group['totalRooms'] - $assignedCount;
                              if ($freeCount < 0) {
                                  $freeCount = 0;
                              }

                              $categoryDayStats[$dateKey] = [
                                  'assigned' => $assignedCount,
                                  'free' => $freeCount,
                                  'overbooked' => $overbookedRooms,
                              ];
                          }
                        ?>
                        <tr class="category-row<?= $group['is_uncategorized'] ? ' category-row-uncategorized' : '' ?>">
                          <th scope="rowgroup" class="category-label category-column" rowspan="<?= $categoryRowspan ?>">
                            <div class="category-label-main">
                              <span class="category-name"><?= htmlspecialchars($categoryData['name'] ?? 'Unbekannte Kategorie') ?></span>
                              <?php if (!$group['is_uncategorized'] && isset($categoryData['status'])): ?>
                                <?php
                                  $categoryStatus = strtolower((string) $categoryData['status']);
                                  $categoryStatusClass = $categoryStatus === 'aktiv'
                                    ? 'text-bg-success'
                                    : 'text-bg-secondary';
                                ?>
                                <span class="badge <?= $categoryStatusClass ?> category-status-badge"><?= htmlspecialchars(ucfirst($categoryStatus)) ?></span>
                              <?php elseif ($group['is_uncategorized']): ?>
                                <span class="badge text-bg-warning category-status-badge">Ohne Zuordnung</span>
                              <?php endif; ?>
                            </div>
                            <?php
                              $overbookingRooms = isset($group['overbookingRooms']) ? (int) $group['overbookingRooms'] : 0;
                              $overbookingReservations = isset($group['overbookingReservations']) ? (int) $group['overbookingReservations'] : 0;
                            ?>
                            <div class="category-meta">
                              <span class="category-meta-item">
                                <span class="category-meta-label">Gesamt</span>
                                <span class="category-meta-value"><?= $group['totalRooms'] ?></span>
                              </span>
                              <span class="category-meta-item">
                                <span class="category-meta-label">Frei</span>
                                <span class="category-meta-value"><?= $group['freeRooms'] ?></span>
                              </span>
                              <?php if ($overbookingRooms > 0): ?>
                                <span class="category-meta-item category-meta-item-alert">
                                  <span class="category-meta-label">Überb.</span>
                                  <span class="category-meta-value"><?= $overbookingRooms ?></span>
                                  <?php if ($overbookingReservations > 0): ?>
                                    <span class="category-meta-extra">(<?= $overbookingReservations ?>)</span>
                                  <?php endif; ?>
                                </span>
                              <?php endif; ?>
                            </div>
                          </th>
                          <th scope="row" class="room-label room-label-summary room-column">
                            <div class="room-label-main">
                              <span class="room-name">Übersicht</span>
                              <span class="room-label-details">Belegt · Frei · Überbuchung</span>
                            </div>
                          </th>
                          <?php foreach ($days as $day): ?>
                            <?php
                              $dateKey = $day['date'];
                              $stats = $categoryDayStats[$dateKey] ?? ['assigned' => 0, 'free' => 0, 'overbooked' => 0];
                            ?>
                            <td class="category-cell">
                              <div class="category-day-metric">
                                <span class="badge text-bg-primary">Belegt: <?= $stats['assigned'] ?></span>
                              </div>
                              <div class="category-day-metric text-muted">Frei: <?= $stats['free'] ?></div>
                              <?php if ($stats['overbooked'] > 0): ?>
                                <div class="category-day-metric text-danger">Überbuchung: <?= $stats['overbooked'] ?></div>
                              <?php endif; ?>
                            </td>
                          <?php endforeach; ?>
                        </tr>
                        <?php if ($roomRowCount > 0): ?>
                          <?php foreach ($roomsInGroup as $room): ?>
                            <?php
                              $roomId = isset($room['id']) ? (int) $room['id'] : 0;
                              $roomStatus = isset($room['status']) ? strtolower((string) $room['status']) : '';
                              $roomNumber = trim((string) ($room['number'] ?? $roomId));
                            ?>
                            <tr>
                              <th scope="row" class="room-label room-label-room room-column">
                                <div class="room-label-main">
                                  <span class="room-name">Zimmer <?= htmlspecialchars($roomNumber) ?></span>
                                  <?php
                                    $roomStatusBadgeClass = 'text-bg-secondary';
                                    $roomStatusText = ucfirst($roomStatus);
                                    if ($roomStatus === 'frei') {
                                        $roomStatusBadgeClass = 'text-bg-success';
                                    } elseif ($roomStatus === 'belegt') {
                                        $roomStatusBadgeClass = 'text-bg-primary';
                                    } elseif ($roomStatus === 'wartung') {
                                        $roomStatusBadgeClass = 'text-bg-warning text-dark';
                                    }
                                  ?>
                                  <?php if ($roomStatus !== ''): ?>
                                    <span class="badge room-status-badge <?= $roomStatusBadgeClass ?>"><?= htmlspecialchars($roomStatusText) ?></span>
                                  <?php endif; ?>
                                </div>
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
                                <td class="<?= htmlspecialchars(implode(' ', $cellClasses)) ?>" data-date="<?= htmlspecialchars($day['date']) ?>" data-room="<?= htmlspecialchars($roomNumber) ?>"<?= $roomId > 0 ? ' data-room-id="' . $roomId . '"' : '' ?>>
                                  <span class="visually-hidden">Zimmer <?= htmlspecialchars($roomNumber) ?> am <?= htmlspecialchars($day['date']) ?></span>
                                  <?php if ($cellOccupants !== []): ?>
                                    <?php foreach ($cellOccupants as $occupantEntry): ?>
                                      <?php
                                        $occupantLabel = '';
                                        $occupantData = null;
                                        if (is_array($occupantEntry)) {
                                            $occupantLabel = (string) ($occupantEntry['label'] ?? '');
                                            $occupantData = $occupantEntry;
                                        } else {
                                            $occupantLabel = (string) $occupantEntry;
                                        }

                                        if ($occupantLabel === '') {
                                            $occupantLabel = 'Belegt';
                                        }

                                        $occupantTitleParts = [];
                                        if (is_array($occupantData)) {
                                            if (!empty($occupantData['statusLabel'])) {
                                                $occupantTitleParts[] = 'Status: ' . $occupantData['statusLabel'];
                                            }

                                            if (!empty($occupantData['reservationNumber'])) {
                                                $occupantTitleParts[] = 'Nr.: ' . $occupantData['reservationNumber'];
                                            }

                                            $dateRange = trim(trim((string) ($occupantData['arrivalDateFormatted'] ?? '')) . ' – ' . trim((string) ($occupantData['departureDateFormatted'] ?? '')));
                                            if ($dateRange !== '–' && trim($dateRange) !== '') {
                                                $occupantTitleParts[] = 'Zeitraum: ' . $dateRange;
                                            }

                                            if (!empty($occupantData['roomName'])) {
                                                $occupantTitleParts[] = $occupantData['roomName'];
                                            }
                                            if (!empty($occupantData['guestCount'])) {
                                                $occupantTitleParts[] = 'Gäste: ' . (int) $occupantData['guestCount'];
                                            }
                                            if (!empty($occupantData['nightCountLabel'])) {
                                                $occupantTitleParts[] = 'Nächte: ' . $occupantData['nightCountLabel'];
                                            }
                                            if (!empty($occupantData['totalPriceFormatted'])) {
                                                $occupantTitleParts[] = 'Gesamt: ' . $occupantData['totalPriceFormatted'];
                                            }
                                        }

                                        $occupantTitle = $occupantTitleParts !== [] ? implode(' • ', array_filter($occupantTitleParts)) : '';
                                        $occupantAria = $occupantTitle !== '' ? $occupantTitle : $occupantLabel;
                                        $occupantAttributes = ' aria-label="' . htmlspecialchars($occupantAria) . '"';
                                        if ($occupantTitle !== '') {
                                            $occupantAttributes .= ' title="' . htmlspecialchars($occupantTitle) . '"';
                                        }

                                        $occupantDataAttr = '';
                                        if ($occupantData !== null) {
                                            $occupantDataAttr = htmlspecialchars(json_encode($occupantData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));
                                        }

                                        $occupantClasses = ['occupancy-entry'];
                                        if ($occupantData !== null) {
                                            $occupantClasses[] = 'occupancy-entry-action';
                                        }

                                        $styleAttr = '';
                                        if ($occupantData !== null && !empty($occupantData['statusColor'])) {
                                            $statusColor = (string) $occupantData['statusColor'];
                                            $statusTextColor = !empty($occupantData['statusTextColor'])
                                                ? (string) $occupantData['statusTextColor']
                                                : $calculateContrastColor($statusColor);
                                            $styleAttr = sprintf(' style="--occupancy-bg:%s;--occupancy-color:%s;"', htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8'), htmlspecialchars($statusTextColor, ENT_QUOTES, 'UTF-8'));
                                            $occupantClasses[] = 'status-colored';
                                        }

                                        $classAttr = htmlspecialchars(implode(' ', $occupantClasses));
                                        $actionAttributes = $occupantAttributes;
                                        if ($occupantData !== null) {
                                            $actionAttributes = sprintf(' role="button" tabindex="0" data-reservation=\'%s\'%s', $occupantDataAttr, $occupantAttributes);
                                        }
                                      ?>
                                      <div class="<?= $classAttr ?>"<?= $actionAttributes ?><?= $styleAttr ?>><?= htmlspecialchars($occupantLabel) ?></div>
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
                            <th scope="row" class="room-label room-column room-label-room text-muted">Noch keine Zimmer in dieser Kategorie.</th>
                            <?php foreach ($days as $day): ?>
                              <td class="room-calendar-cell empty">
                                <span class="text-muted small">–</span>
                              </td>
                            <?php endforeach; ?>
                          </tr>
                        <?php endif; ?>
                        <tr class="overbooking-row">
                          <th scope="row" class="room-label room-label-overbooking room-column">
                            <div class="room-label-main">
                              <span class="room-name">Überbuchung</span>
                              <span class="badge room-status-badge text-bg-warning text-dark">Ohne Zimmer</span>
                            </div>
                          </th>
                          <?php foreach ($days as $day): ?>
                            <?php
                              $overbookingCell = $overbookingData[$day['date']] ?? ['labels' => [], 'quantity' => 0, 'entries' => []];
                              $overbookingLabels = isset($overbookingCell['labels']) && is_array($overbookingCell['labels']) ? $overbookingCell['labels'] : [];
                              $overbookingEntries = isset($overbookingCell['entries']) && is_array($overbookingCell['entries']) ? $overbookingCell['entries'] : [];
                              $overbookingQuantity = isset($overbookingCell['quantity']) ? (int) $overbookingCell['quantity'] : 0;
                              $cellClasses = ['room-calendar-cell', 'overbooking'];
                              if ($day['isToday']) {
                                  $cellClasses[] = 'today';
                              }
                              if ($overbookingQuantity === 0) {
                                  $cellClasses[] = 'overbooking-empty';
                              }
                            ?>
                            <td class="<?= htmlspecialchars(implode(' ', $cellClasses)) ?>" data-date="<?= htmlspecialchars($day['date']) ?>">
                              <span class="visually-hidden">Überbuchungen am <?= htmlspecialchars($day['date']) ?></span>
                              <?php if ($overbookingQuantity > 0): ?>
                                <div class="overbooking-quantity badge text-bg-danger">+<?= $overbookingQuantity ?></div>
                                <?php foreach ($overbookingEntries as $entry): ?>
                                  <?php
                                    $entryLabel = (string) ($entry['label'] ?? '');
                                    if ($entryLabel === '') {
                                        $entryLabel = 'Überbuchung';
                                    }

                                    $entryTitleParts = [];
                                    if (!empty($entry['statusLabel'])) {
                                        $entryTitleParts[] = 'Status: ' . $entry['statusLabel'];
                                    }
                                    if (!empty($entry['reservationNumber'])) {
                                        $entryTitleParts[] = 'Nr.: ' . $entry['reservationNumber'];
                                    }
                                    $entryDateRange = trim(trim((string) ($entry['arrivalDateFormatted'] ?? '')) . ' – ' . trim((string) ($entry['departureDateFormatted'] ?? '')));
                                    if ($entryDateRange !== '–' && trim($entryDateRange) !== '') {
                                        $entryTitleParts[] = 'Zeitraum: ' . $entryDateRange;
                                    }
                                    if (!empty($entry['categoryName'])) {
                                        $entryTitleParts[] = 'Kategorie: ' . $entry['categoryName'];
                                    }
                                    if (!empty($entry['roomQuantity'])) {
                                        $entryTitleParts[] = 'Zimmer: ' . (int) $entry['roomQuantity'];
                                    }
                                    if (!empty($entry['guestCount'])) {
                                        $entryTitleParts[] = 'Gäste: ' . (int) $entry['guestCount'];
                                    }
                                    if (!empty($entry['nightCountLabel'])) {
                                        $entryTitleParts[] = 'Nächte: ' . $entry['nightCountLabel'];
                                    }
                                    if (!empty($entry['totalPriceFormatted'])) {
                                        $entryTitleParts[] = 'Gesamt: ' . $entry['totalPriceFormatted'];
                                    }

                                    $entryTitle = $entryTitleParts !== [] ? implode(' • ', array_filter($entryTitleParts)) : '';
                                    $entryAria = $entryTitle !== '' ? $entryTitle : $entryLabel;
                                    $entryAttributes = ' aria-label="' . htmlspecialchars($entryAria) . '"';
                                    if ($entryTitle !== '') {
                                        $entryAttributes .= ' title="' . htmlspecialchars($entryTitle) . '"';
                                    }

                                    $entryDataAttr = htmlspecialchars(json_encode($entry, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE));
                                    $entryClasses = ['occupancy-entry', 'occupancy-entry-action'];
                                    $entryStyleAttr = '';
                                    if (!empty($entry['statusColor'])) {
                                        $entryStatusColor = (string) $entry['statusColor'];
                                        $entryStatusTextColor = !empty($entry['statusTextColor']) ? (string) $entry['statusTextColor'] : $calculateContrastColor($entryStatusColor);
                                        $entryStyleAttr = sprintf(' style="--occupancy-bg:%s;--occupancy-color:%s;"', htmlspecialchars($entryStatusColor, ENT_QUOTES, 'UTF-8'), htmlspecialchars($entryStatusTextColor, ENT_QUOTES, 'UTF-8'));
                                        $entryClasses[] = 'status-colored';
                                    }
                                    $entryClassAttr = htmlspecialchars(implode(' ', $entryClasses));
                                    $entryActionAttributes = sprintf(' role="button" tabindex="0" data-reservation=\'%s\'%s', $entryDataAttr, $entryAttributes);
                                  ?>
                                  <div class="<?= $entryClassAttr ?>"<?= $entryActionAttributes ?><?= $entryStyleAttr ?>><?= htmlspecialchars($entryLabel) ?></div>
                                <?php endforeach; ?>
                                <?php if ($overbookingEntries === []): ?>
                                  <?php foreach ($overbookingLabels as $entryLabel): ?>
                                    <div class="occupancy-entry"><?= htmlspecialchars((string) $entryLabel) ?></div>
                                  <?php endforeach; ?>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="text-muted small">Keine</span>
                              <?php endif; ?>
                            </td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="<?= count($days) + 2 ?>" class="text-muted text-center py-4">Noch keine Zimmer angelegt.</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>
      <?php elseif ($activeSection === 'reservations'): ?>
      <section id="reservations" class="app-section active">
        <div class="row g-4">
          <div class="col-12">
            <div class="card module-card">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <h2 class="h5 mb-1">Reservierungsübersicht</h2>
                  <p class="text-muted mb-0">Alle Aufenthalte inklusive Historie und Verantwortlichen.</p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="badge text-bg-info"><?= count($reservations) ?> <?= $showArchivedReservations ? 'archivierte Reservierungen' : 'Einträge' ?></span>
                  <?php if ($showArchivedReservations): ?>
                    <span class="badge text-bg-secondary">Archivansicht</span>
                  <?php endif; ?>
                  <?php
                    $archiveToggleParams = ['section' => 'reservations'];
                    if ($reservationSearchTerm !== '') {
                        $archiveToggleParams['reservation_search'] = $reservationSearchTerm;
                    }
                    if (!$showArchivedReservations) {
                        $archiveToggleParams['show_archived'] = '1';
                    }
                    $archiveToggleUrl = 'index.php?' . http_build_query($archiveToggleParams);
                  ?>
                  <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($archiveToggleUrl) ?>">
                    <?= $showArchivedReservations ? 'Archiv ausblenden' : 'Archivierte anzeigen' ?>
                  </a>
                  <a class="btn btn-primary btn-sm" href="index.php?section=reservations&amp;openReservationModal=1">Neu</a>
                </div>
              </div>
              <div class="card-body">
                <form method="get" class="row g-3 align-items-end mb-3">
                  <input type="hidden" name="section" value="reservations">
                  <?php if ($showArchivedReservations): ?>
                    <input type="hidden" name="show_archived" value="1">
                  <?php endif; ?>
                  <div class="col-12 col-lg-8">
                    <label for="reservation-search" class="form-label">Suche nach Gast, Firma oder Reservierungsnummer</label>
                    <input type="search" class="form-control" id="reservation-search" name="reservation_search" placeholder="z. B. Mustermann, Musterfirma oder Res2024000001" value="<?= htmlspecialchars($reservationSearchTerm) ?>">
                  </div>
                  <div class="col-12 col-lg-4 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary flex-grow-1">Suche</button>
                    <?php if ($reservationSearchTerm !== ''): ?>
                      <?php
                        $reservationResetParams = ['section' => 'reservations'];
                        if ($showArchivedReservations) {
                            $reservationResetParams['show_archived'] = '1';
                        }
                        $reservationResetUrl = 'index.php?' . http_build_query($reservationResetParams);
                      ?>
                      <a href="<?= htmlspecialchars($reservationResetUrl) ?>" class="btn btn-link">Zurücksetzen</a>
                    <?php endif; ?>
                  </div>
                </form>

                <?php if ($showArchivedReservations): ?>
                  <div class="alert alert-info" role="status">
                    <strong>Archivansicht:</strong> Archivierte Reservierungen werden angezeigt. Nutzen Sie „Archiv ausblenden“, um zurück zu den aktiven Aufenthalten zu wechseln.
                  </div>
                <?php endif; ?>

                <?php if ($pdo === null): ?>
                  <p class="text-muted mb-0">Reservierungen werden geladen, sobald eine Datenbankverbindung besteht.</p>
                <?php elseif ($reservations === []): ?>
                  <p class="text-muted mb-0">Noch keine Reservierungen erfasst.</p>
                <?php else: ?>
                  <?php
                    $statusBadgeMap = [];
                    $statusLabelMap = [];
                    foreach ($reservationStatusMeta as $statusKey => $statusData) {
                        $statusBadgeMap[$statusKey] = $statusData['badge'];
                        $statusLabelMap[$statusKey] = $statusData['label'];
                    }
                  ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle">
                      <thead class="table-light">
                        <tr>
                          <th scope="col">Nr.</th>
                          <th scope="col">Gast &amp; Firma</th>
                          <th scope="col">Zeitraum</th>
                          <th scope="col">Rate &amp; Preise</th>
                          <th scope="col">Zimmer</th>
                          <th scope="col">Status</th>
                          <th scope="col">Historie</th>
                          <th scope="col" class="text-end">Aktionen</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($reservations as $reservation): ?>
                          <?php
                            $guestFirst = trim((string) ($reservation['guest_first_name'] ?? ''));
                            $guestLast = trim((string) ($reservation['guest_last_name'] ?? ''));
                            $guestDisplay = trim(implode(' ', array_filter([$guestFirst, $guestLast], static fn ($value) => $value !== '')));
                            if ($guestDisplay === '') {
                                $guestDisplay = 'Gast #' . (int) ($reservation['guest_id'] ?? 0);
                            }
                            $companyName = isset($reservation['company_name']) ? trim((string) $reservation['company_name']) : '';

                            $arrivalLabel = '—';
                            if (!empty($reservation['arrival_date'])) {
                                try {
                                    $arrivalLabel = (new DateTimeImmutable($reservation['arrival_date']))->format('d.m.Y');
                                } catch (Throwable $exception) {
                                    $arrivalLabel = $reservation['arrival_date'];
                                }
                            }

                            $departureLabel = '—';
                            if (!empty($reservation['departure_date'])) {
                                try {
                                    $departureLabel = (new DateTimeImmutable($reservation['departure_date']))->format('d.m.Y');
                                } catch (Throwable $exception) {
                                    $departureLabel = $reservation['departure_date'];
                                }
                            }

                            $reservationItems = $reservation['items'] ?? [];
                            if ($reservationItems === []) {
                                $reservationItems[] = [
                                    'category_id' => $reservation['category_id'] ?? null,
                                    'category_name' => $reservation['reservation_category_name'] ?? null,
                                    'room_quantity' => $reservation['room_quantity'] ?? 1,
                                    'room_id' => $reservation['room_id'] ?? null,
                                    'room_number' => $reservation['room_number'] ?? null,
                                ];
                            }

                            $reservationCategoryDetails = [];
                            $rateSummaries = [];
                            $overallTotalValue = 0.0;
                            $hasOverallTotal = false;

                            foreach ($reservationItems as $item) {
                                $itemCategoryId = isset($item['category_id']) ? (int) $item['category_id'] : 0;
                                $itemCategoryName = isset($item['category_name']) && $item['category_name'] !== null
                                    ? trim((string) $item['category_name'])
                                    : ($itemCategoryId > 0 && isset($categoryLookup[$itemCategoryId]['name'])
                                        ? trim((string) $categoryLookup[$itemCategoryId]['name'])
                                        : '');

                                $itemQuantity = isset($item['room_quantity']) ? (int) $item['room_quantity'] : 1;
                                if ($itemQuantity <= 0) {
                                    $itemQuantity = 1;
                                }
                                $itemQuantityLabel = sprintf('%d Zimmer', $itemQuantity);

                                $itemCategoryCapacity = null;
                                if ($itemCategoryId > 0 && isset($categoryLookup[$itemCategoryId]['capacity'])) {
                                    $itemCategoryCapacity = (int) $categoryLookup[$itemCategoryId]['capacity'];
                                } elseif (isset($item['room_id']) && (int) $item['room_id'] > 0) {
                                    $roomIdForCapacity = (int) $item['room_id'];
                                    if (isset($roomLookup[$roomIdForCapacity]['category_id'])) {
                                        $roomCategoryId = (int) $roomLookup[$roomIdForCapacity]['category_id'];
                                        if ($roomCategoryId > 0 && isset($categoryLookup[$roomCategoryId]['capacity'])) {
                                            $itemCategoryCapacity = (int) $categoryLookup[$roomCategoryId]['capacity'];
                                        }
                                    }
                                }

                                if ($itemCategoryCapacity !== null && $itemCategoryCapacity <= 0) {
                                    $itemCategoryCapacity = null;
                                }

                                $primaryGuestId = isset($item['primary_guest_id']) ? (int) $item['primary_guest_id'] : 0;
                                $primaryGuestLabel = '';
                                if ($primaryGuestId > 0) {
                                    if (isset($guestLookup[$primaryGuestId])) {
                                        $primaryGuestLabel = $buildGuestReservationLabel($guestLookup[$primaryGuestId]);
                                    } else {
                                        $primaryGuestLabel = $buildGuestReservationLabel([
                                            'id' => $primaryGuestId,
                                            'first_name' => $item['primary_guest_first_name'] ?? '',
                                            'last_name' => $item['primary_guest_last_name'] ?? '',
                                            'company_name' => $item['primary_guest_company_name'] ?? '',
                                        ]);
                                    }
                                }

                                $itemGuestCount = null;
                                if (isset($item['occupancy']) && $item['occupancy'] !== null) {
                                    $itemGuestCount = (int) $item['occupancy'];
                                    if ($itemGuestCount <= 0) {
                                        $itemGuestCount = null;
                                    }
                                }

                                if ($itemGuestCount === null) {
                                    if ($itemCategoryCapacity !== null) {
                                        $itemGuestCount = $itemCategoryCapacity * $itemQuantity;
                                    } elseif ($itemQuantity > 0) {
                                        $itemGuestCount = $itemQuantity;
                                    }
                                }

                                $itemArticlesSummary = [];
                                $itemArticlesTotal = 0.0;
                                $articleTotalLabel = '';
                                $articleEntries = isset($item['articles']) && is_array($item['articles']) ? $item['articles'] : [];
                                foreach ($articleEntries as $articleEntry) {
                                    if (!is_array($articleEntry)) {
                                        continue;
                                    }

                                    $articleId = isset($articleEntry['article_id']) ? (int) $articleEntry['article_id'] : 0;
                                    $articleName = isset($articleEntry['article_name']) ? trim((string) $articleEntry['article_name']) : '';
                                    if ($articleName === '' && $articleId > 0 && isset($articleLookup[$articleId]['name'])) {
                                        $articleName = trim((string) $articleLookup[$articleId]['name']);
                                    }
                                    if ($articleName === '') {
                                        $articleName = $articleId > 0 ? 'Artikel #' . $articleId : 'Artikel';
                                    }

                                    $articleQuantity = null;
                                    if (isset($articleEntry['quantity']) && $articleEntry['quantity'] !== null && $articleEntry['quantity'] !== '') {
                                        $articleQuantity = (int) $articleEntry['quantity'];
                                        if ($articleQuantity <= 0) {
                                            $articleQuantity = null;
                                        }
                                    }

                                    $pricingTypeKey = isset($articleEntry['pricing_type']) ? (string) $articleEntry['pricing_type'] : ArticleManager::PRICING_PER_DAY;
                                    if (!isset($articlePricingTypes[$pricingTypeKey])) {
                                        $pricingTypeKey = ArticleManager::PRICING_PER_DAY;
                                    }
                                    $pricingLabel = $articlePricingTypes[$pricingTypeKey] ?? '';

                                    $unitPriceValue = isset($articleEntry['unit_price']) && $articleEntry['unit_price'] !== null && $articleEntry['unit_price'] !== ''
                                        ? (float) $articleEntry['unit_price']
                                        : 0.0;
                                    $totalPriceValue = isset($articleEntry['total_price']) && $articleEntry['total_price'] !== null && $articleEntry['total_price'] !== ''
                                        ? (float) $articleEntry['total_price']
                                        : 0.0;
                                    $itemArticlesTotal += $totalPriceValue;

                                    $unitPriceFormatted = $unitPriceValue > 0 ? $formatCurrency($unitPriceValue) : null;
                                    $totalPriceFormatted = $totalPriceValue > 0 ? $formatCurrency($totalPriceValue) : null;

                                    $taxRateValue = null;
                                    if (isset($articleEntry['tax_rate']) && $articleEntry['tax_rate'] !== null && $articleEntry['tax_rate'] !== '') {
                                        $taxRateValue = (float) $articleEntry['tax_rate'];
                                    }
                                    if ($taxRateValue !== null && $taxRateValue < 0) {
                                        $taxRateValue = null;
                                    }
                                    $taxRateFormatted = $taxRateValue !== null ? $formatPercent($taxRateValue) : null;

                                    $itemArticlesSummary[] = [
                                        'id' => $articleId > 0 ? $articleId : null,
                                        'name' => $articleName,
                                        'quantity' => $articleQuantity,
                                        'pricing_type' => $pricingTypeKey,
                                        'pricing_label' => $pricingLabel,
                                        'unit_price' => $unitPriceValue,
                                        'unit_price_formatted' => $unitPriceFormatted,
                                        'total_price' => $totalPriceValue,
                                        'total_price_formatted' => $totalPriceFormatted,
                                        'tax_rate' => $taxRateValue,
                                        'tax_rate_formatted' => $taxRateFormatted,
                                    ];
                                }

                                if ($itemArticlesSummary !== []) {
                                    $articlesFormattedTotal = $itemArticlesTotal > 0 ? $formatCurrency($itemArticlesTotal) : null;
                                    if ($articlesFormattedTotal !== null) {
                                        $articleTotalLabel = $articlesFormattedTotal;
                                    }
                                }

                                $itemRoomId = isset($item['room_id']) ? (int) $item['room_id'] : 0;
                                $assignmentText = 'Noch kein Zimmer zugewiesen';
                                $assignmentClass = 'text-warning';
                                if ($itemRoomId > 0) {
                                    $roomData = $roomLookup[$itemRoomId] ?? null;
                                    $roomNumber = '';
                                    if ($roomData !== null && isset($roomData['number'])) {
                                        $roomNumber = trim((string) $roomData['number']);
                                    } elseif (isset($item['room_number']) && $item['room_number'] !== null) {
                                        $roomNumber = trim((string) $item['room_number']);
                                    }
                                    if ($roomNumber === '') {
                                        $roomNumber = '#' . $itemRoomId;
                                    }
                                    $assignmentText = 'Zuweisung: Zimmer ' . $roomNumber;
                                    $assignmentClass = 'text-muted';
                                }

                                $itemArrivalLabel = '';
                                $itemDepartureLabel = '';
                                $itemArrivalDateObj = null;
                                $itemDepartureDateObj = null;

                                if (isset($item['arrival_date']) && $item['arrival_date'] !== null && $item['arrival_date'] !== '') {
                                    try {
                                        $itemArrivalDateObj = new DateTimeImmutable((string) $item['arrival_date']);
                                        $itemArrivalLabel = $itemArrivalDateObj->format('d.m.Y');
                                    } catch (Throwable $exception) {
                                        $itemArrivalLabel = (string) $item['arrival_date'];
                                    }
                                }

                                if (isset($item['departure_date']) && $item['departure_date'] !== null && $item['departure_date'] !== '') {
                                    try {
                                        $itemDepartureDateObj = new DateTimeImmutable((string) $item['departure_date']);
                                        $itemDepartureLabel = $itemDepartureDateObj->format('d.m.Y');
                                    } catch (Throwable $exception) {
                                        $itemDepartureLabel = (string) $item['departure_date'];
                                    }
                                }

                                $stayLabel = '';
                                if ($itemArrivalLabel !== '' && $itemDepartureLabel !== '') {
                                    $stayLabel = $itemArrivalLabel . ' – ' . $itemDepartureLabel;
                                } elseif ($itemArrivalLabel !== '') {
                                    $stayLabel = 'ab ' . $itemArrivalLabel;
                                } elseif ($itemDepartureLabel !== '') {
                                    $stayLabel = 'bis ' . $itemDepartureLabel;
                                }

                                $itemNightCountLabel = '';
                                if ($itemArrivalDateObj instanceof DateTimeImmutable && $itemDepartureDateObj instanceof DateTimeImmutable) {
                                    $itemNightDiff = $itemArrivalDateObj->diff($itemDepartureDateObj);
                                    if ($itemNightDiff->invert !== 1) {
                                        $itemNightCount = max(1, (int) $itemNightDiff->days);
                                        $itemNightCountLabel = sprintf('%d %s', $itemNightCount, $itemNightCount === 1 ? 'Nacht' : 'Nächte');
                                    }
                                }

                                $itemRateId = isset($item['rate_id']) ? (int) $item['rate_id'] : 0;
                                $itemRateName = '';
                                if (isset($item['rate_name']) && $item['rate_name'] !== null && $item['rate_name'] !== '') {
                                    $itemRateName = (string) $item['rate_name'];
                                } elseif ($itemRateId > 0 && isset($rateLookup[$itemRateId]['name'])) {
                                    $itemRateName = (string) $rateLookup[$itemRateId]['name'];
                                }
                                if ($itemRateName === '') {
                                    $itemRateName = 'Keine Rate zugewiesen';
                                }

                                $itemPricePerNightValue = null;
                                if (isset($item['price_per_night']) && $item['price_per_night'] !== null && $item['price_per_night'] !== '') {
                                    $itemPricePerNightValue = (float) $item['price_per_night'];
                                }
                                $itemPricePerNightLabel = $itemPricePerNightValue !== null ? $formatCurrency($itemPricePerNightValue) : '—';

                                $itemTotalPriceValue = null;
                                if (isset($item['total_price']) && $item['total_price'] !== null && $item['total_price'] !== '') {
                                    $itemTotalPriceValue = (float) $item['total_price'];
                                    $overallTotalValue += $itemTotalPriceValue;
                                    $hasOverallTotal = true;
                                }
                                $itemTotalPriceLabel = $itemTotalPriceValue !== null ? $formatCurrency($itemTotalPriceValue) : '—';

                                $rateSummaries[] = [
                                    'rate' => $itemRateName,
                                    'price' => $itemPricePerNightLabel ?? '—',
                                    'total' => $itemTotalPriceLabel ?? '—',
                                    'period' => $stayLabel,
                                    'nights' => $itemNightCountLabel,
                                ];

                                $reservationCategoryDetails[] = [
                                    'name' => $itemCategoryName !== '' ? $itemCategoryName : 'Kategorie unbekannt',
                                    'quantity' => $itemQuantityLabel,
                                    'occupancy' => $itemGuestCount !== null ? (string) $itemGuestCount : '',
                                    'primary_guest' => $primaryGuestLabel,
                                    'assignment' => $assignmentText,
                                    'assignment_class' => $assignmentClass,
                                    'stay' => $stayLabel,
                                    'articles' => $itemArticlesSummary,
                                    'articles_total' => $articleTotalLabel,
                                ];
                            }

                            $overallTotalDisplay = null;
                            if ($hasOverallTotal) {
                                $overallTotalDisplay = $formatCurrency($overallTotalValue);
                            } elseif (isset($reservation['total_price']) && $reservation['total_price'] !== null && $reservation['total_price'] !== '') {
                                $overallTotalDisplay = $formatCurrency((float) $reservation['total_price']);
                            } elseif (isset($reservation['total_price_display']) && $reservation['total_price_display'] !== null && $reservation['total_price_display'] !== '') {
                                $overallTotalDisplay = (string) $reservation['total_price_display'];
                            }

                            $vatRateDisplay = null;
                            if (isset($reservation['vat_rate']) && $reservation['vat_rate'] !== null && $reservation['vat_rate'] !== '') {
                                $vatRateDisplay = $formatPercent((float) $reservation['vat_rate']);
                            } elseif (isset($reservation['vat_rate_display']) && $reservation['vat_rate_display'] !== null && $reservation['vat_rate_display'] !== '') {
                                $vatRateDisplay = (string) $reservation['vat_rate_display'];
                            }

                            $statusValue = (string) ($reservation['status'] ?? 'geplant');
                            $statusBadge = $statusBadgeMap[$statusValue] ?? 'text-bg-secondary';
                            $statusLabel = $statusLabelMap[$statusValue] ?? ucfirst($statusValue);

                            $createdBy = $reservation['created_by_name'] ?? null;
                            if ($createdBy === null && isset($reservation['created_by']) && $reservation['created_by'] !== null) {
                                $createdId = (int) $reservation['created_by'];
                                if (isset($reservationUserLookup[$createdId])) {
                                    $createdBy = $reservationUserLookup[$createdId];
                                }
                            }

                            $updatedBy = $reservation['updated_by_name'] ?? null;
                            if ($updatedBy === null && isset($reservation['updated_by']) && $reservation['updated_by'] !== null) {
                                $updatedId = (int) $reservation['updated_by'];
                                if (isset($reservationUserLookup[$updatedId])) {
                                    $updatedBy = $reservationUserLookup[$updatedId];
                                }
                            }

                            $createdAtLabel = null;
                            if (!empty($reservation['created_at'])) {
                                try {
                                    $createdAtLabel = (new DateTimeImmutable($reservation['created_at']))->format('d.m.Y H:i');
                                } catch (Throwable $exception) {
                                    $createdAtLabel = $reservation['created_at'];
                                }
                            }

                            $updatedAtLabel = null;
                            if (!empty($reservation['updated_at'])) {
                                try {
                                    $updatedAtLabel = (new DateTimeImmutable($reservation['updated_at']))->format('d.m.Y H:i');
                                } catch (Throwable $exception) {
                                    $updatedAtLabel = $reservation['updated_at'];
                                }
                            }
                          ?>
                          <tr>
                            <td>
                              <?php if (!empty($reservation['reservation_number'])): ?>
                                <span class="fw-semibold"><?= htmlspecialchars((string) $reservation['reservation_number']) ?></span>
                              <?php else: ?>
                                <span class="text-muted">–</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <div class="fw-semibold"><?= htmlspecialchars($guestDisplay) ?></div>
                              <?php if ($companyName !== ''): ?>
                                <div class="small text-muted">Firma: <?= htmlspecialchars($companyName) ?></div>
                              <?php else: ?>
                                <div class="small text-muted">Keine Firma hinterlegt</div>
                              <?php endif; ?>
                              <?php if (!empty($reservation['notes'])): ?>
                                <div class="small text-muted mt-1">Notiz: <?= htmlspecialchars((string) $reservation['notes']) ?></div>
                              <?php endif; ?>
                            </td>
                            <td>
                              <div><?= htmlspecialchars($arrivalLabel) ?> – <?= htmlspecialchars($departureLabel) ?></div>
                            </td>
                            <td>
                              <?php if ($rateSummaries === []): ?>
                                <div class="small text-muted">Keine Tarife erfasst.</div>
                              <?php else: ?>
                                <?php foreach ($rateSummaries as $summaryIndex => $summary): ?>
                                  <div class="fw-semibold"><?= htmlspecialchars($summary['rate']) ?></div>
                                  <div class="small text-muted">Preis/Nacht: <?= htmlspecialchars($summary['price']) ?></div>
                                  <div class="small text-muted">Gesamt: <?= htmlspecialchars($summary['total']) ?></div>
                                  <?php if ($summary['period'] !== ''): ?>
                                    <div class="small text-muted">Zeitraum: <?= htmlspecialchars($summary['period']) ?></div>
                                  <?php endif; ?>
                                  <?php if ($summary['nights'] !== ''): ?>
                                    <div class="small text-muted">Übernachtungen: <?= htmlspecialchars($summary['nights']) ?></div>
                                  <?php endif; ?>
                                  <?php if ($summaryIndex < count($rateSummaries) - 1): ?>
                                    <hr class="my-2">
                                  <?php endif; ?>
                                <?php endforeach; ?>
                              <?php endif; ?>
                              <div class="small text-muted mt-2">Gesamtsumme: <?= $overallTotalDisplay !== null ? htmlspecialchars($overallTotalDisplay) : '—' ?></div>
                              <div class="small text-muted">MwSt.: <?= $vatRateDisplay !== null ? htmlspecialchars($vatRateDisplay) : '—' ?></div>
                            </td>
                            <td>
                              <?php foreach ($reservationCategoryDetails as $detailIndex => $detail): ?>
                                <div class="fw-semibold"><?= htmlspecialchars($detail['name']) ?></div>
                                <div class="small text-muted">Zimmerbedarf: <?= htmlspecialchars($detail['quantity']) ?></div>
                                <?php if (!empty($detail['occupancy'])): ?>
                                  <div class="small text-muted">Personen: <?= htmlspecialchars($detail['occupancy']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($detail['primary_guest'])): ?>
                                  <div class="small text-muted">Meldeschein: <?= htmlspecialchars($detail['primary_guest']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($detail['stay'])): ?>
                                  <div class="small text-muted">Zeitraum: <?= htmlspecialchars($detail['stay']) ?></div>
                                <?php endif; ?>
                                <div class="small <?= htmlspecialchars($detail['assignment_class']) ?>"><?= htmlspecialchars($detail['assignment']) ?></div>
                                <?php
                                  $detailArticles = isset($detail['articles']) && is_array($detail['articles']) ? $detail['articles'] : [];
                                  $detailArticlesTotal = isset($detail['articles_total']) ? (string) $detail['articles_total'] : '';
                                ?>
                                <?php if ($detailArticles !== []): ?>
                                  <div class="small text-muted mt-1">Artikel:</div>
                                  <ul class="list-unstyled small text-muted mb-1 ps-3">
                                    <?php foreach ($detailArticles as $articleDetail): ?>
                                      <?php
                                        if (!is_array($articleDetail)) {
                                            continue;
                                        }
                                        $articleName = isset($articleDetail['name']) ? (string) $articleDetail['name'] : 'Artikel';
                                        $articleMetaParts = [];
                                        if (isset($articleDetail['quantity']) && $articleDetail['quantity'] !== null && $articleDetail['quantity'] !== '') {
                                            $articleMetaParts[] = 'Menge: ' . htmlspecialchars((string) $articleDetail['quantity']);
                                        }
                                        if (!empty($articleDetail['pricing_label'])) {
                                            $articleMetaParts[] = htmlspecialchars((string) $articleDetail['pricing_label']);
                                        }
                                        if (!empty($articleDetail['total_price_formatted'])) {
                                            $articleMetaParts[] = 'Gesamt: ' . htmlspecialchars((string) $articleDetail['total_price_formatted']);
                                        }
                                        $articleText = htmlspecialchars($articleName);
                                        if ($articleMetaParts !== []) {
                                            $articleText .= ' (' . implode(' • ', $articleMetaParts) . ')';
                                        }
                                      ?>
                                      <li><?= $articleText ?></li>
                                    <?php endforeach; ?>
                                  </ul>
                                  <?php if ($detailArticlesTotal !== ''): ?>
                                    <div class="small text-muted">Artikel gesamt: <?= htmlspecialchars($detailArticlesTotal) ?></div>
                                  <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($detailIndex < count($reservationCategoryDetails) - 1): ?>
                                  <hr class="my-2">
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </td>
                            <td>
                              <span class="badge <?= $statusBadge ?> text-uppercase"><?= htmlspecialchars($statusLabel) ?></span>
                            </td>
                            <td>
                              <div class="small">Erstellt: <?= htmlspecialchars($createdBy ?? 'Unbekannt') ?><?php if ($createdAtLabel !== null): ?> <span class="text-muted">(<?= htmlspecialchars($createdAtLabel) ?>)</span><?php endif; ?></div>
                              <div class="small">Aktualisiert: <?= htmlspecialchars($updatedBy ?? '—') ?><?php if ($updatedAtLabel !== null): ?> <span class="text-muted">(<?= htmlspecialchars($updatedAtLabel) ?>)</span><?php endif; ?></div>
                            </td>
                            <td class="text-end">
                              <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <a class="btn btn-outline-secondary btn-sm" href="index.php?section=reservations&amp;editReservation=<?= (int) $reservation['id'] ?>">Bearbeiten</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Reservierung wirklich löschen?');">
                                  <input type="hidden" name="form" value="reservation_delete">
                                  <input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm" <?= $pdo === null ? 'disabled' : '' ?>>Löschen</button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </section>
      <?php elseif ($activeSection === 'rates'): ?>
      <?php
        $isEditingRate = $rateFormMode === 'update' && $rateFormData['id'] !== null;
        $isEditingRatePeriod = $ratePeriodFormMode === 'update' && $ratePeriodFormData['id'] !== null;
        $isEditingRateEvent = $rateEventFormMode === 'update' && $rateEventFormData['id'] !== null;
        $monthNames = [
            '01' => 'Januar',
            '02' => 'Februar',
            '03' => 'März',
            '04' => 'April',
            '05' => 'Mai',
            '06' => 'Juni',
            '07' => 'Juli',
            '08' => 'August',
            '09' => 'September',
            '10' => 'Oktober',
            '11' => 'November',
            '12' => 'Dezember',
        ];
        $selectedRateId = $activeRateId !== null ? $activeRateId : ($rates !== [] ? (int) ($rates[0]['id'] ?? 0) : null);
        $selectedRateCategoryId = $activeRateCategoryId !== null
            ? $activeRateCategoryId
            : ($categories !== [] ? (int) ($categories[0]['id'] ?? 0) : null);
        $calendarHasData = $rateCalendarData['rate'] !== null
            && $selectedRateId !== null
            && $selectedRateCategoryId !== null
            && $rateCalendarData['months'] !== [];
      ?>
      <section id="rates" class="app-section active">
        <div class="row g-4">
          <div class="col-12 col-xxl-5">
            <div class="card module-card" id="rate-form">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <h2 class="h5 mb-1">Rate <?= $isEditingRate ? 'bearbeiten' : 'anlegen' ?></h2>
                  <p class="text-muted mb-0">Tarife je Kategorie verwalten und Basispreise festlegen.</p>
                </div>
                <?php if ($isEditingRate): ?>
                  <span class="badge text-bg-primary">Bearbeitung</span>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3">
                  <input type="hidden" name="form" value="<?= $isEditingRate ? 'rate_update' : 'rate_create' ?>">
                  <input type="hidden" name="active_rate_category_id" value="<?= $selectedRateCategoryId !== null ? (int) $selectedRateCategoryId : '' ?>">
                  <?php if ($isEditingRate): ?>
                    <input type="hidden" name="id" value="<?= (int) $rateFormData['id'] ?>">
                  <?php endif; ?>
                  <div class="col-12">
                    <label for="rate-name" class="form-label">Bezeichnung *</label>
                    <input type="text" class="form-control" id="rate-name" name="name" value="<?= htmlspecialchars((string) $rateFormData['name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <?php if ($categories === []): ?>
                    <div class="col-12">
                      <div class="alert alert-warning mb-0">Bitte legen Sie zuerst Kategorien an, um Preise zu hinterlegen.</div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                      <?php if (!isset($category['id'])) { continue; } ?>
                      <?php $categoryId = (int) $category['id']; ?>
                      <div class="col-12 col-md-6">
                        <label class="form-label" for="rate-category-price-<?= $categoryId ?>">Preis für <?= htmlspecialchars((string) ($category['name'] ?? 'Kategorie')) ?> (EUR) *</label>
                        <input
                          type="text"
                          class="form-control"
                          id="rate-category-price-<?= $categoryId ?>"
                          name="category_prices[<?= $categoryId ?>]"
                          value="<?= htmlspecialchars((string) ($rateFormData['category_prices'][$categoryId] ?? '')) ?>"
                          <?= $pdo === null ? 'disabled' : 'required' ?>
                        >
                        <div class="form-text">Dezimaltrenner Komma oder Punkt.</div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <div class="col-12">
                    <label for="rate-description" class="form-label">Beschreibung</label>
                    <textarea class="form-control" id="rate-description" name="description" rows="2" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $rateFormData['description']) ?></textarea>
                  </div>
                  <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                    <?php if ($isEditingRate): ?>
                      <a href="index.php?section=rates" class="btn btn-outline-secondary">Abbrechen</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingRate ? 'Rate aktualisieren' : 'Rate speichern' ?></button>
                  </div>
                </form>
                <?php if ($pdo === null): ?>
                  <p class="text-muted mt-3 mb-0">Die Formularfelder werden aktiviert, sobald eine Datenbankverbindung besteht.</p>
                <?php endif; ?>
              </div>
            </div>
            <div class="card module-card">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div>
                  <h2 class="h5 mb-1">Ratenübersicht</h2>
                  <p class="text-muted mb-0">Alle Tarife nach Kategorie und Basispreis.</p>
                </div>
                <span class="badge text-bg-info"><?= count($rates) ?> Einträge</span>
              </div>
              <div class="card-body">
                <?php if ($pdo === null): ?>
                  <p class="text-muted mb-0">Die Raten werden angezeigt, sobald eine Datenbankverbindung besteht.</p>
                <?php elseif ($rates === []): ?>
                  <p class="text-muted mb-0">Noch keine Raten angelegt.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th scope="col">Bezeichnung</th>
                          <th scope="col">Kategorien &amp; Preise</th>
                          <th scope="col" class="text-end">Aktionen</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rates as $rate): ?>
                          <?php $rateId = isset($rate['id']) ? (int) $rate['id'] : 0; ?>
                          <?php
                            $rateCategoryLinkId = null;
                            if (
                                $selectedRateCategoryId !== null
                                && isset($rate['category_prices'][$selectedRateCategoryId])
                            ) {
                                $rateCategoryLinkId = $selectedRateCategoryId;
                            } elseif (isset($rate['category_prices']) && is_array($rate['category_prices'])) {
                                foreach ($rate['category_prices'] as $categoryKey => $priceValue) {
                                    $categoryKey = (int) $categoryKey;
                                    if ($categoryKey > 0) {
                                        $rateCategoryLinkId = $categoryKey;
                                        break;
                                    }
                                }
                            }
                            if ($rateCategoryLinkId === null && $categories !== []) {
                                foreach ($categories as $categoryOption) {
                                    if (!isset($categoryOption['id'])) {
                                        continue;
                                    }

                                    $rateCategoryLinkId = (int) $categoryOption['id'];
                                    if ($rateCategoryLinkId > 0) {
                                        break;
                                    }
                                }
                            }

                            if ($rateCategoryLinkId !== null && $rateCategoryLinkId <= 0) {
                                $rateCategoryLinkId = null;
                            }

                            $rateViewParams = ['section' => 'rates', 'rateId' => $rateId];
                            $rateEditParams = ['section' => 'rates', 'editRate' => $rateId, 'rateId' => $rateId];
                            if ($rateCategoryLinkId !== null) {
                                $rateViewParams['rateCategoryId'] = $rateCategoryLinkId;
                                $rateEditParams['rateCategoryId'] = $rateCategoryLinkId;
                            }

                            $rateViewUrl = 'index.php?' . http_build_query($rateViewParams);
                            $rateEditUrl = 'index.php?' . http_build_query($rateEditParams);
                          ?>
                          <tr<?= $selectedRateId !== null && $rateId === $selectedRateId ? ' class="table-primary"' : '' ?>>
                            <td>
                              <div class="fw-semibold mb-1"><?= htmlspecialchars((string) ($rate['name'] ?? '')) ?></div>
                              <?php if (!empty($rate['description'])): ?>
                                <div class="small text-muted"><?= htmlspecialchars((string) $rate['description']) ?></div>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if ($categories === []): ?>
                                <span class="text-muted">Keine Kategorien vorhanden.</span>
                              <?php else: ?>
                                <ul class="list-unstyled mb-0 small">
                                  <?php foreach ($categories as $category): ?>
                                    <?php if (!isset($category['id'])) { continue; } ?>
                                    <?php
                                      $categoryId = (int) $category['id'];
                                      $categoryName = isset($category['name']) ? (string) $category['name'] : ('Kategorie #' . $categoryId);
                                      $categoryPrice = $rate['category_prices'][$categoryId] ?? null;
                                    ?>
                                    <li>
                                      <span class="fw-semibold"><?= htmlspecialchars($categoryName) ?>:</span>
                                      <?php if ($categoryPrice !== null): ?>
                                        € <?= number_format((float) $categoryPrice, 2, ',', '') ?>
                                      <?php else: ?>
                                        <span class="text-muted">—</span>
                                      <?php endif; ?>
                                    </li>
                                  <?php endforeach; ?>
                                </ul>
                              <?php endif; ?>
                            </td>
                            <td class="text-end">
                              <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($rateViewUrl) ?>">Anzeigen</a>
                                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($rateEditUrl) ?>">Bearbeiten</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Rate wirklich löschen?');">
                                  <input type="hidden" name="form" value="rate_delete">
                                  <input type="hidden" name="id" value="<?= $rateId ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm" <?= $pdo === null ? 'disabled' : '' ?>>Löschen</button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-12 col-xxl-7">
            <div class="card module-card" id="rate-events">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <h2 class="h5 mb-1">Messen &amp; Sonderraten</h2>
                  <p class="text-muted mb-0">Besondere Ereignisse mit eigenen Farben und Preisen je Kategorie.</p>
                </div>
                <?php if ($isEditingRateEvent): ?>
                  <span class="badge text-bg-primary">Bearbeitung</span>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3" id="rate-event-form">
                  <input type="hidden" name="form" value="<?= $isEditingRateEvent ? 'rate_event_update' : 'rate_event_create' ?>">
                  <input type="hidden" name="active_rate_category_id" value="<?= $selectedRateCategoryId !== null ? (int) $selectedRateCategoryId : '' ?>">
                  <?php if ($isEditingRateEvent): ?>
                    <input type="hidden" name="id" value="<?= (int) $rateEventFormData['id'] ?>">
                  <?php endif; ?>
                  <div class="col-12 col-lg-4">
                    <label for="rate-event-rate" class="form-label">Rate *</label>
                    <select class="form-select" id="rate-event-rate" name="rate_id" required <?= $pdo === null || $rates === [] ? 'disabled' : '' ?>>
                      <option value="">Bitte auswählen</option>
                      <?php foreach ($rates as $rate): ?>
                        <?php if (!isset($rate['id'])) { continue; } ?>
                        <?php $rateId = (int) $rate['id']; ?>
                        <option value="<?= $rateId ?>" <?= (int) ($rateEventFormData['rate_id'] ?? 0) === $rateId || ($rateEventFormData['rate_id'] === '' && $selectedRateId !== null && $rateId === $selectedRateId) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($rate['name'] ?? 'Rate #' . $rateId)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 col-lg-4">
                    <label for="rate-event-name" class="form-label">Messe *</label>
                    <input type="text" class="form-control" id="rate-event-name" name="name" value="<?= htmlspecialchars((string) $rateEventFormData['name']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <div class="col-6 col-lg-2">
                    <label for="rate-event-start" class="form-label">Start *</label>
                    <input type="date" class="form-control" id="rate-event-start" name="start_date" value="<?= htmlspecialchars((string) $rateEventFormData['start_date']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <div class="col-6 col-lg-2">
                    <label for="rate-event-end" class="form-label">Ende *</label>
                    <input type="date" class="form-control" id="rate-event-end" name="end_date" value="<?= htmlspecialchars((string) $rateEventFormData['end_date']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <div class="col-12 col-lg-4">
                    <label for="rate-event-default-price" class="form-label">Standardpreis (EUR)</label>
                    <input type="text" class="form-control" id="rate-event-default-price" name="default_price" value="<?= htmlspecialchars((string) $rateEventFormData['default_price']) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                    <div class="form-text">Optional – greift, wenn keine Kategoriepreise hinterlegt sind.</div>
                  </div>
                  <div class="col-6 col-lg-2">
                    <label for="rate-event-color" class="form-label">Farbe</label>
                    <input type="color" class="form-control form-control-color" id="rate-event-color" name="color" value="<?= htmlspecialchars((string) $rateEventFormData['color']) ?>" title="Darstellungsfarbe im Kalender" <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <div class="col-12 col-lg-6">
                    <label for="rate-event-description" class="form-label">Beschreibung</label>
                    <textarea class="form-control" id="rate-event-description" name="description" rows="1" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $rateEventFormData['description']) ?></textarea>
                  </div>
                  <?php if ($categories === []): ?>
                    <div class="col-12">
                      <div class="alert alert-warning mb-0">Bitte legen Sie Kategorien an, um Messepreise pro Kategorie zu pflegen.</div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                      <?php if (!isset($category['id'])) { continue; } ?>
                      <?php $categoryId = (int) $category['id']; ?>
                      <div class="col-12 col-md-6">
                        <label class="form-label" for="rate-event-category-price-<?= $categoryId ?>">Preis für <?= htmlspecialchars((string) ($category['name'] ?? 'Kategorie')) ?> (EUR)</label>
                        <input
                          type="text"
                          class="form-control"
                          id="rate-event-category-price-<?= $categoryId ?>"
                          name="category_prices[<?= $categoryId ?>]"
                          value="<?= htmlspecialchars((string) ($rateEventFormData['category_prices'][$categoryId] ?? '')) ?>"
                          <?= $pdo === null ? 'disabled' : '' ?>
                        >
                        <div class="form-text">Leer lassen, um Standardpreis bzw. Basispreis zu verwenden.</div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                    <?php if ($isEditingRateEvent): ?>
                      <a href="index.php?section=rates#rate-events" class="btn btn-outline-secondary">Abbrechen</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingRateEvent ? 'Messe aktualisieren' : 'Messe speichern' ?></button>
                  </div>
                </form>
                <?php if ($pdo === null): ?>
                  <p class="text-muted mt-3 mb-0">Sonderraten können nach Aufbau der Datenbankverbindung hinterlegt werden.</p>
                <?php elseif ($selectedRateId === null): ?>
                  <p class="text-muted mt-3 mb-0">Bitte wählen Sie eine Rate, um Messen zu verwalten.</p>
                <?php elseif ($rateEvents === []): ?>
                  <p class="text-muted mt-3 mb-0">Noch keine Messen für diese Rate erfasst.</p>
                <?php else: ?>
                  <div class="table-responsive mt-4">
                    <table class="table table-sm align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th scope="col">Messe</th>
                          <th scope="col">Zeitraum</th>
                          <th scope="col">Preise</th>
                          <th scope="col" class="text-end">Aktionen</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rateEvents as $event): ?>
                          <?php
                            $eventId = isset($event['id']) ? (int) $event['id'] : 0;
                            $eventColor = isset($event['color']) && $event['color'] !== null ? (string) $event['color'] : '#B91C1C';
                            $eventStart = $event['start_date'] ?? '';
                            $eventEnd = $event['end_date'] ?? '';
                            try {
                                if ($eventStart !== '') {
                                    $eventStart = (new DateTimeImmutable($eventStart))->format('d.m.Y');
                                }
                            } catch (Throwable $exception) {
                                // keep original value
                            }
                            try {
                                if ($eventEnd !== '') {
                                    $eventEnd = (new DateTimeImmutable($eventEnd))->format('d.m.Y');
                                }
                            } catch (Throwable $exception) {
                                // keep original value
                            }
                            $eventCategoryPrices = $event['category_prices'] ?? [];
                            $eventEditParams = ['section' => 'rates', 'editRateEvent' => $eventId];
                            if ($selectedRateId !== null) {
                                $eventEditParams['rateId'] = $selectedRateId;
                            }
                            if ($selectedRateCategoryId !== null) {
                                $eventEditParams['rateCategoryId'] = $selectedRateCategoryId;
                            }
                            $eventEditUrl = 'index.php?' . http_build_query($eventEditParams) . '#rate-event-form';
                          ?>
                          <tr>
                            <td>
                              <div class="d-flex align-items-center gap-2">
                                <span class="rate-event-color" style="background-color: <?= htmlspecialchars($eventColor) ?>"></span>
                                <div>
                                  <div class="fw-semibold"><?= htmlspecialchars((string) ($event['name'] ?? 'Messe #' . $eventId)) ?></div>
                                  <?php if (!empty($event['description'])): ?>
                                    <div class="small text-muted"><?= htmlspecialchars((string) $event['description']) ?></div>
                                  <?php endif; ?>
                                </div>
                              </div>
                            </td>
                            <td><?= htmlspecialchars($eventStart) ?> – <?= htmlspecialchars($eventEnd) ?></td>
                            <td>
                              <?php if ($eventCategoryPrices === [] && !isset($event['default_price'])): ?>
                                <span class="text-muted">Basispreise</span>
                              <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                  <?php if (isset($event['default_price']) && $event['default_price'] !== null): ?>
                                    <span class="badge text-bg-info">Standard: € <?= number_format((float) $event['default_price'], 2, ',', '') ?></span>
                                  <?php endif; ?>
                                  <?php foreach ($eventCategoryPrices as $categoryId => $price): ?>
                                    <?php
                                      $categoryId = (int) $categoryId;
                                      $categoryName = isset($categoryLookup[$categoryId]['name']) ? (string) $categoryLookup[$categoryId]['name'] : 'Kategorie #' . $categoryId;
                                    ?>
                                    <span class="badge text-bg-secondary"><?= htmlspecialchars($categoryName) ?>: € <?= number_format((float) $price, 2, ',', '') ?></span>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </td>
                            <td class="text-end">
                              <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($eventEditUrl) ?>">Bearbeiten</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Messe wirklich löschen?');">
                                  <input type="hidden" name="form" value="rate_event_delete">
                                  <input type="hidden" name="id" value="<?= $eventId ?>">
                                  <input type="hidden" name="rate_id" value="<?= $selectedRateId ?>">
                                  <input type="hidden" name="active_rate_category_id" value="<?= $selectedRateCategoryId !== null ? (int) $selectedRateCategoryId : '' ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm" <?= $pdo === null ? 'disabled' : '' ?>>Löschen</button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="card module-card" id="rate-calendar">
              <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                  <h2 class="h5 mb-1">Jahresraten</h2>
                  <p class="text-muted mb-0">Basispreis und Zeitraum-Anpassungen je Tag.</p>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Jahr wechseln">
                  <a href="<?= htmlspecialchars($rateCalendarPrevUrl) ?>" class="btn btn-outline-secondary">&laquo; Vorjahr</a>
                  <a href="<?= htmlspecialchars($rateCalendarResetUrl) ?>" class="btn btn-outline-secondary">Aktuelles Jahr</a>
                  <a href="<?= htmlspecialchars($rateCalendarNextUrl) ?>" class="btn btn-outline-secondary">Nächstes Jahr &raquo;</a>
                </div>
              </div>
              <div class="card-body">
                <form method="get" class="row g-3 align-items-end mb-4">
                  <input type="hidden" name="section" value="rates">
                  <div class="col-12 col-lg-4">
                    <label for="rate-calendar-select" class="form-label">Rate wählen</label>
                    <select class="form-select" id="rate-calendar-select" name="rateId" <?= $pdo === null || $rates === [] ? 'disabled' : '' ?>>
                      <?php if ($rates === []): ?>
                        <option value="">Keine Rate vorhanden</option>
                      <?php else: ?>
                        <?php foreach ($rates as $rate): ?>
                          <?php if (!isset($rate['id'])) { continue; } ?>
                          <?php $rateId = (int) $rate['id']; ?>
                          <option value="<?= $rateId ?>" <?= $selectedRateId !== null && $rateId === $selectedRateId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($rate['name'] ?? 'Rate #' . $rateId)) ?></option>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-12 col-lg-4">
                    <label for="rate-calendar-category" class="form-label">Kategorie wählen</label>
                    <select class="form-select" id="rate-calendar-category" name="rateCategoryId" <?= $pdo === null || $categories === [] ? 'disabled' : '' ?>>
                      <?php if ($categories === []): ?>
                        <option value="">Keine Kategorie vorhanden</option>
                      <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                          <?php if (!isset($category['id'])) { continue; } ?>
                          <?php $categoryId = (int) $category['id']; ?>
                          <option value="<?= $categoryId ?>" <?= $selectedRateCategoryId !== null && $categoryId === $selectedRateCategoryId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($category['name'] ?? 'Kategorie #' . $categoryId)) ?></option>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-6 col-lg-2">
                    <label for="rate-calendar-year" class="form-label">Jahr</label>
                    <input type="number" class="form-control" id="rate-calendar-year" name="rateYear" value="<?= (int) $rateCalendarYear ?>" min="2000" max="2100">
                  </div>
                  <div class="col-6 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary flex-grow-1">Anzeigen</button>
                    <a href="<?= htmlspecialchars($rateCalendarResetUrl) ?>" class="btn btn-link">Zurücksetzen</a>
                  </div>
                </form>
                <?php if ($pdo === null): ?>
                  <p class="text-muted mb-0">Kalenderdarstellung steht nach Verbindungsaufbau zur Verfügung.</p>
                <?php elseif ($selectedRateId === null || $selectedRateCategoryId === null): ?>
                  <p class="text-muted mb-0">Bitte legen Sie zunächst eine Rate und Kategorien an.</p>
                <?php elseif (!$calendarHasData): ?>
                  <p class="text-muted mb-0">Für das gewählte Jahr liegen noch keine Preisangaben vor. Der Basispreis wird verwendet.</p>
                <?php else: ?>
                  <div class="rate-calendar">
                    <?php foreach ($rateCalendarData['months'] as $monthKey => $dayEntries): ?>
                      <?php
                        $monthNumber = substr($monthKey, -2);
                        $monthLabel = $monthNames[$monthNumber] ?? $monthKey;
                        $firstOfMonth = DateTimeImmutable::createFromFormat('Y-m-d', $monthKey . '-01');
                        $blankCells = $firstOfMonth instanceof DateTimeImmutable ? (int) $firstOfMonth->format('N') - 1 : 0;
                      ?>
                      <div class="rate-calendar-month">
                        <div class="rate-calendar-month-header"><?= htmlspecialchars($monthLabel . ' ' . $rateCalendarYear) ?></div>
                        <div class="rate-calendar-grid">
                          <?php for ($i = 0; $i < $blankCells; $i++): ?>
                            <div class="rate-calendar-cell rate-calendar-cell-empty" aria-hidden="true"></div>
                          <?php endfor; ?>
                          <?php foreach ($dayEntries as $dayIndex => $day): ?>
                            <?php
                              $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', (string) $day['date']);
                              $dayNumber = $dateObj instanceof DateTimeImmutable ? (int) $dateObj->format('j') : ($dayIndex + 1);
                              $priceLabel = number_format((float) ($day['price'] ?? 0), 2, ',', '');
                              $cellClasses = ['rate-calendar-cell'];
                              $cellStyleParts = [];
                              $tooltipParts = [];
                              if (($day['source'] ?? '') === 'event') {
                                  $cellClasses[] = 'rate-calendar-cell-event';
                                  $eventColorHex = $normalizeHexColor($day['event_color'] ?? null);
                                  if ($eventColorHex !== null) {
                                      $cellStyleParts[] = '--event-color-hex: ' . htmlspecialchars($eventColorHex);
                                      $eventOverlay = $hexToRgba($eventColorHex, 0.3);
                                      if ($eventOverlay !== null) {
                                          $cellStyleParts[] = '--event-color-overlay: ' . htmlspecialchars($eventOverlay);
                                      }
                                  }
                                  $eventLabel = (string) ($day['event_label'] ?? 'Messe');
                                  if ($eventLabel !== '') {
                                      $tooltipParts[] = 'Messe: ' . $eventLabel;
                                  }
                                  if (!empty($day['period_label'])) {
                                      $tooltipParts[] = (string) $day['period_label'];
                                  }
                              } elseif (($day['source'] ?? '') === 'period') {
                                  $cellClasses[] = 'rate-calendar-cell-override';
                                  if (!empty($day['period_label'])) {
                                      $tooltipParts[] = (string) $day['period_label'];
                                  }
                              } else {
                                  $tooltipParts[] = 'Basispreis';
                              }
                              if ($tooltipParts === []) {
                                  $tooltipParts[] = 'Preisübersicht';
                              }
                              $tooltip = implode(' • ', $tooltipParts);
                              $cellStyle = $cellStyleParts === [] ? '' : ' style="' . implode('; ', $cellStyleParts) . '"';
                            ?>
                            <div class="<?= htmlspecialchars(implode(' ', $cellClasses)) ?>"<?= $cellStyle ?> title="<?= htmlspecialchars($tooltip) ?>">
                              <div class="rate-calendar-day"><?= $dayNumber ?></div>
                              <div class="rate-calendar-price">€ <?= htmlspecialchars($priceLabel) ?></div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="card module-card" id="rate-periods">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <h2 class="h5 mb-1">Preiszeiträume</h2>
                  <p class="text-muted mb-0">Saisonale Anpassungen mit optionalen Wochentagen.</p>
                </div>
                <?php if ($isEditingRatePeriod): ?>
                  <span class="badge text-bg-primary">Bearbeitung</span>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <form method="post" class="row g-3" id="rate-period-form">
                  <input type="hidden" name="form" value="<?= $isEditingRatePeriod ? 'rate_period_update' : 'rate_period_create' ?>">
                  <input type="hidden" name="active_rate_category_id" value="<?= $selectedRateCategoryId !== null ? (int) $selectedRateCategoryId : '' ?>">
                  <?php if ($isEditingRatePeriod): ?>
                    <input type="hidden" name="id" value="<?= (int) $ratePeriodFormData['id'] ?>">
                  <?php endif; ?>
                  <div class="col-12 col-lg-6">
                    <label for="rate-period-rate" class="form-label">Rate *</label>
                    <select class="form-select" id="rate-period-rate" name="rate_id" required <?= $pdo === null || $rates === [] ? 'disabled' : '' ?>>
                      <option value="">Bitte auswählen</option>
                      <?php foreach ($rates as $rate): ?>
                        <?php if (!isset($rate['id'])) { continue; } ?>
                        <?php $rateId = (int) $rate['id']; ?>
                        <option value="<?= $rateId ?>" <?= (int) ($ratePeriodFormData['rate_id'] ?? 0) === $rateId || ($ratePeriodFormData['rate_id'] === '' && $selectedRateId !== null && $rateId === $selectedRateId) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($rate['name'] ?? 'Rate #' . $rateId)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-6 col-lg-3">
                    <label for="rate-period-start" class="form-label">Start *</label>
                    <input type="date" class="form-control" id="rate-period-start" name="start_date" value="<?= htmlspecialchars((string) $ratePeriodFormData['start_date']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <div class="col-6 col-lg-3">
                    <label for="rate-period-end" class="form-label">Ende *</label>
                    <input type="date" class="form-control" id="rate-period-end" name="end_date" value="<?= htmlspecialchars((string) $ratePeriodFormData['end_date']) ?>" required <?= $pdo === null ? 'disabled' : '' ?>>
                  </div>
                  <?php if ($categories === []): ?>
                    <div class="col-12">
                      <div class="alert alert-warning mb-0">Bitte legen Sie Kategorien an, um Zeitraumpreise zu pflegen.</div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                      <?php if (!isset($category['id'])) { continue; } ?>
                      <?php $categoryId = (int) $category['id']; ?>
                      <div class="col-12 col-md-6">
                        <label class="form-label" for="rate-period-category-price-<?= $categoryId ?>">Preis für <?= htmlspecialchars((string) ($category['name'] ?? 'Kategorie')) ?> (EUR)</label>
                        <input
                          type="text"
                          class="form-control"
                          id="rate-period-category-price-<?= $categoryId ?>"
                          name="period_category_prices[<?= $categoryId ?>]"
                          value="<?= htmlspecialchars((string) ($ratePeriodFormData['category_prices'][$categoryId] ?? '')) ?>"
                          <?= $pdo === null ? 'disabled' : '' ?>
                        >
                        <div class="form-text">Leer lassen, um den Basispreis der Kategorie zu verwenden.</div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <div class="col-12 col-lg-8">
                    <label class="form-label">Wochentage</label>
                    <div class="d-flex flex-wrap gap-2">
                      <?php foreach ($ratePeriodWeekdayOptions as $weekdayValue => $weekdayLabel): ?>
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="checkbox" id="weekday-<?= $weekdayValue ?>" name="days_of_week[]" value="<?= $weekdayValue ?>" <?= in_array($weekdayValue, $ratePeriodFormData['days_of_week'], true) ? 'checked' : '' ?> <?= $pdo === null ? 'disabled' : '' ?>>
                          <label class="form-check-label" for="weekday-<?= $weekdayValue ?>"><?= htmlspecialchars($weekdayLabel) ?></label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="form-text">Ohne Auswahl gilt der Preis für alle Tage.</div>
                  </div>
                  <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                    <?php if ($isEditingRatePeriod): ?>
                      <a href="index.php?section=rates#rate-periods" class="btn btn-outline-secondary">Abbrechen</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isEditingRatePeriod ? 'Zeitraum aktualisieren' : 'Zeitraum hinzufügen' ?></button>
                  </div>
                </form>
                <?php if ($pdo === null): ?>
                  <p class="text-muted mt-3 mb-0">Preiszeiträume lassen sich nach Verbindungsaufbau pflegen.</p>
                <?php elseif ($selectedRateId === null): ?>
                  <p class="text-muted mt-3 mb-0">Bitte wählen Sie eine Rate, um Zeiträume zu verwalten.</p>
                <?php elseif ($ratePeriods === []): ?>
                  <p class="text-muted mt-3 mb-0">Noch keine spezifischen Preiszeiträume erfasst.</p>
                <?php else: ?>
                  <div class="table-responsive mt-4">
                    <table class="table table-sm align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th scope="col">Zeitraum</th>
                          <th scope="col">Wochentage</th>
                          <th scope="col">Kategoriepreise</th>
                          <th scope="col" class="text-end">Aktionen</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($ratePeriods as $period): ?>
                          <?php
                            $periodId = isset($period['id']) ? (int) $period['id'] : 0;
                            $daysList = $period['days_of_week_list'] ?? [];
                            $dayLabels = [];
                            foreach ($daysList as $weekday) {
                                $dayLabels[] = $ratePeriodWeekdayOptions[$weekday] ?? ('Tag ' . $weekday);
                            }
                            $dayText = $dayLabels !== [] ? implode(', ', $dayLabels) : 'Alle Tage';
                            $periodStart = $period['start_date'] ?? '';
                            $periodEnd = $period['end_date'] ?? '';
                            $periodCategoryLinkId = null;
                            $periodCategoryPrices = $period['category_prices'] ?? [];
                            if (
                                $selectedRateCategoryId !== null
                                && isset($periodCategoryPrices[$selectedRateCategoryId])
                            ) {
                                $periodCategoryLinkId = $selectedRateCategoryId;
                            } elseif (is_array($periodCategoryPrices)) {
                                foreach ($periodCategoryPrices as $categoryKey => $priceValue) {
                                    $categoryKey = (int) $categoryKey;
                                    if ($categoryKey > 0) {
                                        $periodCategoryLinkId = $categoryKey;
                                        break;
                                    }
                                }
                            }
                            if ($periodCategoryLinkId === null && $selectedRateCategoryId !== null) {
                                $periodCategoryLinkId = $selectedRateCategoryId;
                            }
                            if ($periodCategoryLinkId !== null && $periodCategoryLinkId <= 0) {
                                $periodCategoryLinkId = null;
                            }
                            $periodEditParams = ['section' => 'rates', 'editRatePeriod' => $periodId];
                            if ($selectedRateId !== null) {
                                $periodEditParams['rateId'] = $selectedRateId;
                            }
                            if ($periodCategoryLinkId !== null) {
                                $periodEditParams['rateCategoryId'] = $periodCategoryLinkId;
                            }
                            $periodEditUrl = 'index.php?' . http_build_query($periodEditParams) . '#rate-period-form';
                            try {
                                if ($periodStart !== '') {
                                    $periodStart = (new DateTimeImmutable($periodStart))->format('d.m.Y');
                                }
                            } catch (Throwable $exception) {
                                // keep original value
                            }
                            try {
                                if ($periodEnd !== '') {
                                    $periodEnd = (new DateTimeImmutable($periodEnd))->format('d.m.Y');
                                }
                            } catch (Throwable $exception) {
                                // keep original value
                            }
                          ?>
                          <tr>
                            <td><?= htmlspecialchars($periodStart) ?> – <?= htmlspecialchars($periodEnd) ?></td>
                            <td><?= htmlspecialchars($dayText) ?></td>
                            <td>
                              <?php if ($periodCategoryPrices === []): ?>
                                <span class="text-muted">Basispreise</span>
                              <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                  <?php foreach ($periodCategoryPrices as $categoryId => $price): ?>
                                    <?php
                                      $categoryId = (int) $categoryId;
                                      $categoryName = isset($categoryLookup[$categoryId]['name'])
                                        ? (string) $categoryLookup[$categoryId]['name']
                                        : 'Kategorie #' . $categoryId;
                                    ?>
                                    <span class="badge text-bg-secondary">
                                      <?= htmlspecialchars($categoryName) ?>: € <?= number_format((float) $price, 2, ',', '') ?>
                                    </span>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </td>
                            <td class="text-end">
                              <div class="d-flex justify-content-end gap-2 flex-wrap">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($periodEditUrl) ?>">Bearbeiten</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Preiszeitraum wirklich löschen?');">
                                  <input type="hidden" name="form" value="rate_period_delete">
                                  <input type="hidden" name="id" value="<?= $periodId ?>">
                                  <input type="hidden" name="rate_id" value="<?= $selectedRateId ?>">
                                  <input type="hidden" name="active_rate_category_id" value="<?= $selectedRateCategoryId !== null ? (int) $selectedRateCategoryId : '' ?>">
                                  <button type="submit" class="btn btn-outline-danger btn-sm" <?= $pdo === null ? 'disabled' : '' ?>>Löschen</button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </section>
      <?php elseif ($activeSection === 'articles'): ?>
      <section id="articles" class="app-section active">
        <?php if ($pdo === null): ?>
          <p class="text-muted mb-0">Die Artikelverwaltung steht nach dem Herstellen einer Datenbankverbindung zur Verfügung.</p>
        <?php else: ?>
          <div class="row g-4">
            <div class="col-12 col-xl-5">
              <div class="card module-card">
                <div class="card-header bg-transparent border-0">
                  <h2 class="h5 mb-1">Artikel <?= $articleFormMode === 'update' ? 'bearbeiten' : 'anlegen' ?></h2>
                  <p class="text-muted mb-0">Zusatzleistungen mit Bruttopreisen und Mehrwertsteuer-Zuordnung.</p>
                </div>
                <div class="card-body">
                  <form method="post" class="row g-3">
                    <input type="hidden" name="form" value="<?= $articleFormMode === 'update' ? 'article_update' : 'article_create' ?>">
                    <?php if ($articleFormMode === 'update'): ?>
                      <input type="hidden" name="id" value="<?= (int) $articleFormData['id'] ?>">
                    <?php endif; ?>
                    <div class="col-12">
                      <label for="article-name" class="form-label">Bezeichnung</label>
                      <input type="text" class="form-control" id="article-name" name="name" value="<?= htmlspecialchars((string) $articleFormData['name']) ?>" required>
                    </div>
                    <div class="col-12">
                      <label for="article-description" class="form-label">Beschreibung (optional)</label>
                      <textarea class="form-control" id="article-description" name="description" rows="3"><?= htmlspecialchars((string) $articleFormData['description']) ?></textarea>
                    </div>
                    <div class="col-12 col-sm-6">
                      <label for="article-price" class="form-label">Bruttopreis</label>
                      <div class="input-group">
                        <input type="text" class="form-control" id="article-price" name="price" value="<?= htmlspecialchars((string) $articleFormData['price']) ?>" placeholder="z.&nbsp;B. 12,50" required>
                        <span class="input-group-text">€</span>
                      </div>
                    </div>
                    <div class="col-12 col-sm-6">
                      <label for="article-pricing-type" class="form-label">Abrechnung</label>
                      <select class="form-select" id="article-pricing-type" name="pricing_type" required>
                        <?php foreach ($articlePricingTypes as $pricingTypeKey => $pricingTypeLabel): ?>
                          <option value="<?= htmlspecialchars($pricingTypeKey) ?>" <?= $articleFormData['pricing_type'] === $pricingTypeKey ? 'selected' : '' ?>><?= htmlspecialchars($pricingTypeLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-12">
                      <label for="article-tax-category" class="form-label">Mehrwertsteuer-Kategorie</label>
                      <?php
                        $articleTaxOptions = $articleTaxCategoryOptionsHtml;
                        if ($articleFormData['tax_category_id'] !== '' && (int) $articleFormData['tax_category_id'] > 0) {
                            $articleTaxOptions = str_replace(
                                sprintf('value="%d"', (int) $articleFormData['tax_category_id']),
                                sprintf('value="%d" selected', (int) $articleFormData['tax_category_id']),
                                $articleTaxCategoryOptionsHtml
                            );
                        }
                      ?>
                      <select class="form-select" id="article-tax-category" name="tax_category_id">
                        <?= $articleTaxOptions ?>
                      </select>
                      <div class="form-text">Verwalten Sie weitere Kategorien im Bereich „Steuerkategorien“ unten.</div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                      <button type="submit" class="btn btn-primary">Speichern</button>
                      <?php if ($articleFormMode === 'update'): ?>
                        <a class="btn btn-outline-secondary" href="index.php?section=articles">Abbrechen</a>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-12 col-xl-7">
              <div class="card module-card" id="article-list">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div>
                    <h2 class="h5 mb-1">Verfügbare Artikel</h2>
                    <p class="text-muted mb-0">Aktive Zusatzleistungen für Reservierungen.</p>
                  </div>
                  <span class="badge text-bg-info align-self-center"><?= count($articles) ?> Einträge</span>
                </div>
                <div class="card-body">
                  <?php if ($articles === []): ?>
                    <p class="text-muted mb-0">Noch keine Artikel hinterlegt.</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table align-middle">
                        <thead>
                          <tr>
                            <th>Bezeichnung</th>
                            <th>Preis</th>
                            <th>Abrechnung</th>
                            <th>Mehrwertsteuer</th>
                            <th class="text-end">Aktionen</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($articles as $article): ?>
                            <?php
                              if (!isset($article['id'])) { continue; }
                              $articleId = (int) $article['id'];
                              $pricingTypeKey = (string) ($article['pricing_type'] ?? ArticleManager::PRICING_PER_DAY);
                              $pricingTypeLabel = $articlePricingTypes[$pricingTypeKey] ?? $pricingTypeKey;
                              $taxLabel = 'Keine';
                              if (isset($article['tax_category_name'])) {
                                  $rateValue = isset($article['tax_category_rate']) ? number_format((float) $article['tax_category_rate'], 2, ',', '.') : '0,00';
                                  $taxLabel = sprintf('%s (%s %%)', (string) $article['tax_category_name'], $rateValue);
                              }
                            ?>
                            <tr>
                              <td>
                                <strong><?= htmlspecialchars((string) ($article['name'] ?? '')) ?></strong>
                                <?php if (!empty($article['description'])): ?>
                                  <div class="text-muted small"><?= nl2br(htmlspecialchars((string) $article['description'])) ?></div>
                                <?php endif; ?>
                              </td>
                              <td>€ <?= number_format((float) ($article['price'] ?? 0), 2, ',', '.') ?></td>
                              <td><?= htmlspecialchars($pricingTypeLabel) ?></td>
                              <td><?= htmlspecialchars($taxLabel) ?></td>
                              <td class="text-end">
                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                  <a class="btn btn-outline-secondary btn-sm" href="index.php?section=articles&amp;editArticle=<?= $articleId ?>">Bearbeiten</a>
                                  <form method="post" class="d-inline" onsubmit="return confirm('Artikel wirklich löschen?');">
                                    <input type="hidden" name="form" value="article_delete">
                                    <input type="hidden" name="id" value="<?= $articleId ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Löschen</button>
                                  </form>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="card module-card" id="tax-category-management">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                  <div>
                    <h2 class="h5 mb-1">Steuerkategorien</h2>
                    <p class="text-muted mb-0">Mehrwertsteuer-Sätze für Artikel und spätere Rechnungen.</p>
                  </div>
                  <span class="badge text-bg-secondary align-self-center"><?= count($taxCategories) ?> Kategorien</span>
                </div>
                <div class="card-body">
                  <div class="row g-4">
                    <div class="col-12 col-lg-5">
                      <form method="post" class="row g-3">
                        <input type="hidden" name="form" value="<?= $taxCategoryFormMode === 'update' ? 'tax_category_update' : 'tax_category_create' ?>">
                        <?php if ($taxCategoryFormMode === 'update'): ?>
                          <input type="hidden" name="id" value="<?= (int) $taxCategoryFormData['id'] ?>">
                        <?php endif; ?>
                        <div class="col-12">
                          <label for="tax-category-name" class="form-label">Name</label>
                          <input type="text" class="form-control" id="tax-category-name" name="name" value="<?= htmlspecialchars((string) $taxCategoryFormData['name']) ?>" required>
                        </div>
                        <div class="col-12">
                          <label for="tax-category-rate" class="form-label">Steuersatz in&nbsp;%</label>
                          <div class="input-group">
                            <input type="text" class="form-control" id="tax-category-rate" name="rate" value="<?= htmlspecialchars((string) $taxCategoryFormData['rate']) ?>" placeholder="z.&nbsp;B. 7,00" required>
                            <span class="input-group-text">%</span>
                          </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                          <button type="submit" class="btn btn-outline-primary">Speichern</button>
                          <?php if ($taxCategoryFormMode === 'update'): ?>
                            <a class="btn btn-outline-secondary" href="index.php?section=articles#tax-category-management">Abbrechen</a>
                          <?php endif; ?>
                        </div>
                      </form>
                    </div>
                    <div class="col-12 col-lg-7">
                      <?php if ($taxCategories === []): ?>
                        <p class="text-muted mb-0">Noch keine Mehrwertsteuer-Kategorien hinterlegt.</p>
                      <?php else: ?>
                        <div class="table-responsive">
                          <table class="table align-middle">
                            <thead>
                              <tr>
                                <th>Name</th>
                                <th>Satz</th>
                                <th class="text-end">Aktionen</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($taxCategories as $taxCategory): ?>
                                <?php if (!isset($taxCategory['id'])) { continue; }
                                  $taxId = (int) $taxCategory['id'];
                                  $rateValue = isset($taxCategory['rate']) ? number_format((float) $taxCategory['rate'], 2, ',', '.') : '0,00';
                                ?>
                                <tr>
                                  <td><?= htmlspecialchars((string) ($taxCategory['name'] ?? '')) ?></td>
                                  <td><?= $rateValue ?> %</td>
                                  <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                                      <a class="btn btn-outline-secondary btn-sm" href="index.php?section=articles&amp;editTaxCategory=<?= $taxId ?>#tax-category-management">Bearbeiten</a>
                                      <form method="post" class="d-inline" onsubmit="return confirm('Kategorie wirklich löschen?');">
                                        <input type="hidden" name="form" value="tax_category_delete">
                                        <input type="hidden" name="id" value="<?= $taxId ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Löschen</button>
                                      </form>
                                    </div>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </section>
      <?php elseif ($activeSection === 'categories'): ?>
      <section id="categories" class="app-section active">
        <?php $isEditingCategory = $categoryFormData['id'] !== null; ?>
        <div class="row g-4">
          <div class="col-12 col-xxl-8">
            <div class="card module-card" id="category-management">
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
                          <th scope="col" class="text-center">Reihenfolge</th>
                          <th scope="col">Status</th>
                          <th scope="col" class="text-end">Aktionen</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php $categoryCount = count($categories); ?>
                        <?php foreach ($categories as $index => $category): ?>
                          <?php
                            $positionBadge = isset($category['sort_order']) ? (int) $category['sort_order'] : ($index + 1);
                            if ($positionBadge <= 0) {
                                $positionBadge = $index + 1;
                            }
                            $isFirstCategory = $index === 0;
                            $isLastCategory = $index === $categoryCount - 1;
                          ?>
                          <tr>
                            <td>
                              <div class="fw-semibold"><?= htmlspecialchars($category['name']) ?></div>
                              <?php if (!empty($category['description'])): ?>
                                <div class="small text-muted"><?= htmlspecialchars($category['description']) ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?= (int) $category['capacity'] ?> Gäste</td>
                            <td class="text-center">
                              <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap">
                                <span class="badge text-bg-light" title="Sortierposition">#<?= $positionBadge ?></span>
                                <form method="post" class="d-inline-flex" action="index.php?section=categories#category-management">
                                  <input type="hidden" name="form" value="category_move">
                                  <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                  <div class="btn-group btn-group-sm" role="group" aria-label="Reihenfolge anpassen">
                                    <button type="submit" class="btn btn-outline-secondary" name="direction" value="up" <?= $isFirstCategory || $pdo === null ? 'disabled' : '' ?>>
                                      <span aria-hidden="true">↑</span>
                                      <span class="visually-hidden">Nach oben verschieben</span>
                                    </button>
                                    <button type="submit" class="btn btn-outline-secondary" name="direction" value="down" <?= $isLastCategory || $pdo === null ? 'disabled' : '' ?>>
                                      <span aria-hidden="true">↓</span>
                                      <span class="visually-hidden">Nach unten verschieben</span>
                                    </button>
                                  </div>
                                </form>
                              </div>
                            </td>
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
                            <td colspan="5" class="text-center text-muted py-3">Noch keine Kategorien erfasst.</td>
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

      <?php if ($activeSection === 'settings'): ?>
      <section id="settings" class="app-section active">
        <div class="row g-4">
          <div class="col-12 col-xxl-8">
            <div class="card module-card" id="status-color-settings">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div>
                  <h2 class="h5 mb-1">Einstellungen</h2>
                  <p class="text-muted mb-0">Farbcodes für Reservierungsstatus im Kalender festlegen.</p>
                </div>
                <span class="badge text-bg-secondary">Darstellung</span>
              </div>
              <div class="card-body">
                <?php if (!$settingsAvailable): ?>
                  <p class="text-muted mb-0">Die Farbverwaltung steht erst nach einer erfolgreichen Datenbankverbindung zur Verfügung.</p>
                <?php else: ?>
                  <form method="post" class="row g-4 align-items-stretch">
                    <input type="hidden" name="form" value="settings_status_colors">
                    <?php foreach ($reservationStatuses as $statusKey): ?>
                      <?php
                        $statusLabel = $reservationStatusMeta[$statusKey]['label'] ?? ucfirst($statusKey);
                        $formColorValue = $reservationStatusFormColors[$statusKey] ?? ($reservationStatusColors[$statusKey] ?? '');
                        $normalizedColor = $normalizeHexColor($formColorValue);
                        if ($normalizedColor === null) {
                            $normalizedColor = $reservationStatusColors[$statusKey] ?? '#2563EB';
                        }
                        $previewColor = $normalizedColor;
                        $previewTextColor = $calculateContrastColor($previewColor);
                      ?>
                      <div class="col-12 col-md-6" data-status-color>
                        <label class="form-label" for="status-color-value-<?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars($statusLabel) ?></label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                          <input
                            type="color"
                            class="form-control form-control-color"
                            id="status-color-picker-<?= htmlspecialchars($statusKey) ?>"
                            value="<?= htmlspecialchars($normalizedColor) ?>"
                            data-status-color-picker
                            oninput="var target=document.getElementById('status-color-value-<?= htmlspecialchars($statusKey) ?>'); if (target) { target.value = this.value; target.dispatchEvent(new Event('input')); }"
                            aria-label="Farbe für <?= htmlspecialchars($statusLabel) ?>"
                          >
                          <input
                            type="text"
                            class="form-control flex-grow-1"
                            id="status-color-value-<?= htmlspecialchars($statusKey) ?>"
                            name="status_colors[<?= htmlspecialchars($statusKey) ?>]"
                            value="<?= htmlspecialchars((string) $formColorValue) ?>"
                            placeholder="#<?= htmlspecialchars(substr($normalizedColor, 1)) ?>"
                            pattern="#?[0-9A-Fa-f]{3,6}"
                            required
                            data-status-color-input
                          >
                        </div>
                        <div class="status-color-preview mt-3" data-status-color-preview style="background-color: <?= htmlspecialchars($previewColor) ?>; color: <?= htmlspecialchars($previewTextColor) ?>;">
                          <span class="status-color-preview-label"><?= htmlspecialchars($statusLabel) ?></span>
                        </div>
                        <p class="form-text mb-0">Akzeptiert werden Hex-Werte mit oder ohne führendes #.</p>
                      </div>
                    <?php endforeach; ?>
                    <div class="col-12 d-flex justify-content-end gap-2">
                      <button type="submit" class="btn btn-primary">Farben speichern</button>
                    </div>
                  </form>
                <?php endif; ?>
            </div>
          </div>
          <div class="card module-card mt-4" id="vat-settings">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <div>
                <h2 class="h5 mb-1">Mehrwertsteuer Übernachtung</h2>
                <p class="text-muted mb-0">Steuersatz für Preisberechnungen und Reservierungen festlegen.</p>
              </div>
              <span class="badge text-bg-primary">Steuern</span>
            </div>
            <div class="card-body">
              <?php if (!$settingsAvailable): ?>
                <p class="text-muted mb-0">Die Mehrwertsteuer kann erst nach einer erfolgreichen Datenbankverbindung angepasst werden.</p>
              <?php else: ?>
                <form method="post" class="row g-3 align-items-end">
                  <input type="hidden" name="form" value="settings_vat">
                  <div class="col-md-4 col-lg-3">
                    <label for="overnight-vat-rate" class="form-label">Übernachtungs-MwSt. (%)</label>
                    <input
                      type="text"
                      class="form-control"
                      id="overnight-vat-rate"
                      name="overnight_vat_rate"
                      value="<?= htmlspecialchars(number_format((float) $overnightVatRateValue, 2, ',', '.')) ?>"
                      required
                      inputmode="decimal"
                    >
                  </div>
                  <div class="col-md-8 col-lg-9">
                    <p class="form-text mb-2">Der hinterlegte Satz wird bei neuen Reservierungen automatisch übernommen.</p>
                    <button type="submit" class="btn btn-outline-primary">Mehrwertsteuer speichern</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="card module-card mt-4" id="cache-tools">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
              <div>
                <h2 class="h5 mb-1">Browser-Cache leeren</h2>
                <p class="text-muted mb-0">Löscht zwischengespeicherte Dateien für diese Anwendung im aktuellen Browser.</p>
                </div>
                <span class="badge text-bg-warning">Browser</span>
              </div>
              <div class="card-body">
                <form method="post" class="d-flex flex-column flex-md-row gap-3 align-items-start align-items-md-center">
                  <input type="hidden" name="form" value="settings_clear_cache">
                  <div class="text-muted small flex-grow-1">
                    <p class="mb-1">Unterstützte Browser entfernen ihren Cache für diese Seite unmittelbar nach dem Ausführen.</p>
                    <p class="mb-0">Bei älteren Browsern kann ggf. ein manuelles Leeren des Cache erforderlich bleiben.</p>
                  </div>
                  <button type="submit" class="btn btn-outline-warning">Browser-Cache leeren</button>
                </form>
              </div>
            </div>
            <?php if ($settingsAvailable): ?>
            <div class="card module-card mt-4" id="database-maintenance">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div>
                  <h2 class="h5 mb-1">Datenbank aktualisieren</h2>
                  <p class="text-muted mb-0">Führt neue Tabellen und Spalten für Module automatisch nach.</p>
                </div>
                <span class="badge text-bg-info">Wartung</span>
              </div>
              <div class="card-body">
                <form method="post" class="d-flex flex-column flex-md-row gap-3 align-items-start align-items-md-center">
                  <input type="hidden" name="form" value="settings_schema_refresh">
                  <div class="text-muted small flex-grow-1">
                    <p class="mb-1">Aktualisiert Reservierungs- und Einstellungstabellen sowie neue Felder aus aktuellen Releases.</p>
                    <p class="mb-0">Verwenden Sie diese Funktion nach einem Update, falls neue Spalten fehlen.</p>
                  </div>
                  <button type="submit" class="btn btn-outline-primary">Tabellen aktualisieren</button>
                </form>
              </div>
            </div>
            <?php endif; ?>
            <div class="card module-card mt-4" id="database-backups">
              <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div>
                  <h2 class="h5 mb-1">Datenbank sichern &amp; wiederherstellen</h2>
                  <p class="text-muted mb-0">Exportiert bzw. importiert Kategorien, Zimmer, Firmen und Gäste als JSON-Datei.</p>
                </div>
                <span class="badge text-bg-secondary">Backup</span>
              </div>
              <div class="card-body">
                <?php if ($pdo === null): ?>
                  <p class="text-muted mb-0">Für Sicherungen wird eine aktive Datenbankverbindung benötigt.</p>
                <?php else: ?>
                  <div class="row g-4">
                    <div class="col-12 col-xl-6">
                      <h3 class="h6">Sicherung erstellen</h3>
                      <p class="small text-muted">Lädt eine JSON-Datei herunter, die Sie bei Bedarf wieder einspielen können.</p>
                      <form method="post">
                        <input type="hidden" name="form" value="settings_backup_export">
                        <button type="submit" class="btn btn-outline-secondary">Backup herunterladen</button>
                      </form>
                    </div>
                    <div class="col-12 col-xl-6">
                      <h3 class="h6">Sicherung wiederherstellen</h3>
                      <p class="small text-muted">Bestehende Datensätze werden durch die Inhalte der Sicherung ersetzt.</p>
                      <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Aktuelle Daten werden überschrieben. Fortfahren?');">
                        <input type="hidden" name="form" value="settings_backup_import">
                        <div class="mb-3">
                          <label for="backup-file" class="form-label">JSON-Datei auswählen</label>
                          <input type="file" class="form-control" id="backup-file" name="backup_file" accept="application/json,.json" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Backup importieren</button>
                      </form>
                    </div>
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

    <div class="modal fade" id="reservationFormModal" tabindex="-1" aria-labelledby="reservationFormModalLabel" aria-hidden="true" data-reservation-mode="<?= $isEditingReservation ? 'update' : 'create' ?>">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header align-items-start">
            <div>
              <h1 class="modal-title fs-5" id="reservationFormModalLabel"><?= $isEditingReservation ? 'Reservierung bearbeiten' : 'Neue Reservierung' ?></h1>
              <p class="mb-0 text-muted small">Aufenthalte mit Zimmer, Zeitraum und Status pflegen.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
              <?php if ($isEditingReservation): ?>
                <span class="badge text-bg-primary" data-edit-badge>Bearbeitung</span>
              <?php endif; ?>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
          </div>
          <div class="modal-body">
            <?php $isReservationEditing = $isEditingReservation; ?>
            <form method="post" class="row g-3" id="reservation-form">
              <input type="hidden" name="form" value="<?= $isReservationEditing ? 'reservation_update' : 'reservation_create' ?>">
              <?php if ($isReservationEditing): ?>
                <input type="hidden" name="id" value="<?= (int) $reservationFormData['id'] ?>">
              <?php endif; ?>
              <?php if (!empty($reservationFormData['reservation_number'])): ?>
                <div class="col-12">
                  <label class="form-label">Reservierungsnummer</label>
                  <input type="text" class="form-control" value="<?= htmlspecialchars((string) $reservationFormData['reservation_number']) ?>" readonly>
                  <div class="form-text">Die Nummer wird automatisch vergeben und bleibt unverändert.</div>
                </div>
              <?php endif; ?>
              <div class="col-12">
                <label for="reservation-guest-query" class="form-label">Gast *</label>
                <div class="typeahead position-relative" data-typeahead="guest" data-endpoint="index.php?ajax=guest_search">
                  <input type="hidden" name="guest_id" id="reservation-guest-id" value="<?= htmlspecialchars((string) $reservationFormData['guest_id']) ?>">
                  <input
                    type="search"
                    class="form-control typeahead-input"
                    id="reservation-guest-query"
                    name="guest_query"
                    placeholder="z. B. Mustermann oder Musterfirma"
                    value="<?= htmlspecialchars((string) $reservationFormData['guest_query']) ?>"
                    autocomplete="off"
                    data-minlength="2"
                    <?= $pdo === null ? 'disabled' : 'required' ?>
                    <?= $reservationGuestTooltip !== '' ? 'title="' . htmlspecialchars($reservationGuestTooltip) . '"' : '' ?>
                  >
                  <div class="typeahead-dropdown list-group shadow-sm" role="listbox" aria-label="Gastvorschläge"></div>
                </div>
                <div class="form-text">Neuer Gast? Über das Plus-Menü in der Navigation anlegen.</div>
              </div>
              <div class="col-12">
                <label for="reservation-company-query" class="form-label">Firma</label>
                <div class="typeahead position-relative" data-typeahead="company" data-endpoint="index.php?ajax=company_search">
                  <input type="hidden" name="company_id" id="reservation-company-id" value="<?= htmlspecialchars((string) $reservationFormData['company_id']) ?>">
                  <input
                    type="search"
                    class="form-control typeahead-input"
                    id="reservation-company-query"
                    name="company_query"
                    placeholder="Optional: Firmenname suchen"
                    value="<?= htmlspecialchars((string) $reservationFormData['company_query']) ?>"
                    autocomplete="off"
                    data-minlength="2"
                    <?= $pdo === null ? 'disabled' : '' ?>
                    <?= $reservationCompanyTooltip !== '' ? 'title="' . htmlspecialchars($reservationCompanyTooltip) . '"' : '' ?>
                  >
                  <div class="typeahead-dropdown list-group shadow-sm" role="listbox" aria-label="Firmenvorschläge"></div>
                </div>
                <div class="form-text">Optional: Firma zuordnen.</div>
              </div>
              <?php $categoryItemCount = count($reservationFormData['category_items']); ?>
              <div class="col-12">
                <label class="form-label">Kategorie &amp; Zimmer *</label>
                <div id="reservation-category-list" class="reservation-category-list" data-next-index="<?= $categoryItemCount ?>">
                  <?php foreach ($reservationFormData['category_items'] as $categoryIndex => $categoryItem): ?>
                    <?php
                      $selectedCategoryId = isset($categoryItem['category_id']) && $categoryItem['category_id'] !== ''
                          ? (int) $categoryItem['category_id']
                          : 0;
                      $quantityValue = isset($categoryItem['room_quantity']) && $categoryItem['room_quantity'] !== ''
                          ? (string) $categoryItem['room_quantity']
                          : '1';
                      $occupancyValue = isset($categoryItem['occupancy']) && $categoryItem['occupancy'] !== ''
                          ? (string) $categoryItem['occupancy']
                          : $quantityValue;
                      $primaryGuestIdValue = isset($categoryItem['primary_guest_id']) && $categoryItem['primary_guest_id'] !== ''
                          ? (string) $categoryItem['primary_guest_id']
                          : ($reservationFormData['guest_id'] !== '' ? (string) $reservationFormData['guest_id'] : '');
                      $primaryGuestQueryValue = isset($categoryItem['primary_guest_query']) && $categoryItem['primary_guest_query'] !== ''
                          ? (string) $categoryItem['primary_guest_query']
                          : '';
                      if ($primaryGuestQueryValue === '' && $primaryGuestIdValue !== '') {
                          $primaryGuestLookupId = (int) $primaryGuestIdValue;
                          if ($primaryGuestLookupId > 0) {
                              if (isset($guestLookup[$primaryGuestLookupId])) {
                                  $primaryGuestQueryValue = $buildGuestReservationLabel($guestLookup[$primaryGuestLookupId]);
                              } elseif ($guestManager instanceof GuestManager) {
                                  $primaryGuestRecord = $guestManager->find($primaryGuestLookupId);
                                  if ($primaryGuestRecord !== null) {
                                      $primaryGuestQueryValue = $buildGuestReservationLabel($primaryGuestRecord);
                                  }
                              }
                          }
                      }
                      $selectedRoomId = isset($categoryItem['room_id']) && $categoryItem['room_id'] !== ''
                          ? (int) $categoryItem['room_id']
                          : null;
                      $roomArrivalValue = isset($categoryItem['arrival_date']) && $categoryItem['arrival_date'] !== ''
                          ? (string) $categoryItem['arrival_date']
                          : (string) $reservationFormData['arrival_date'];
                      $roomDepartureValue = isset($categoryItem['departure_date']) && $categoryItem['departure_date'] !== ''
                          ? (string) $categoryItem['departure_date']
                          : (string) $reservationFormData['departure_date'];
                      $departureMinValue = $todayDateValue;
                      if ($roomArrivalValue !== '') {
                          try {
                              $arrivalMinDate = new DateTimeImmutable($roomArrivalValue);
                              $candidateDepartureMin = $arrivalMinDate->modify('+1 day');
                              $todayMinDate = new DateTimeImmutable($todayDateValue);
                              if ($candidateDepartureMin < $todayMinDate) {
                                  $candidateDepartureMin = $todayMinDate;
                              }
                              $departureMinValue = $candidateDepartureMin->format('Y-m-d');
                          } catch (Exception $exception) {
                              $departureMinValue = $todayDateValue;
                          }
                      }
                      $roomPricePerNightValue = isset($categoryItem['price_per_night']) ? (string) $categoryItem['price_per_night'] : '';
                      $roomTotalPriceValue = isset($categoryItem['total_price']) ? (string) $categoryItem['total_price'] : '';
                      $selectedRateIdForItem = isset($categoryItem['rate_id']) && $categoryItem['rate_id'] !== ''
                          ? (int) $categoryItem['rate_id']
                          : 0;
                      $itemRateOptionsHtml = $buildRateSelectOptions !== null
                          ? $buildRateSelectOptions($selectedRateIdForItem)
                          : $reservationRateOptionsHtml;
                      $itemArticles = isset($categoryItem['articles']) && is_array($categoryItem['articles'])
                          ? array_values($categoryItem['articles'])
                          : [['article_id' => '', 'quantity' => '1', 'total_price' => '']];
                      $articleSummaryValue = isset($categoryItem['articles_total']) ? (string) $categoryItem['articles_total'] : '';
                      $selectedRoomLabel = '';
                      if ($selectedRoomId !== null) {
                          if (isset($roomLookup[$selectedRoomId]['room_number'])) {
                              $labelNumber = trim((string) $roomLookup[$selectedRoomId]['room_number']);
                              $selectedRoomLabel = $labelNumber !== '' ? 'Zimmer ' . $labelNumber : 'Zimmer #' . $selectedRoomId;
                          } else {
                              $selectedRoomLabel = 'Zimmer #' . $selectedRoomId;
                          }
                      }
                      $disableRemove = $categoryItemCount === 1 && $categoryIndex === 0;
                    ?>
                    <div class="reservation-category-item card card-body border p-3 mb-2" data-index="<?= $categoryIndex ?>">
                      <div class="row g-2 align-items-end">
                        <div class="col-12 col-lg-4">
                          <label class="form-label">Kategorie</label>
                          <select class="form-select" name="reservation_categories[<?= $categoryIndex ?>][category_id]" <?= $pdo === null ? 'disabled' : 'required' ?>>
                            <option value="">Bitte auswählen</option>
                            <?php foreach ($categories as $category): ?>
                              <?php if (!isset($category['id'])) { continue; } ?>
                              <option value="<?= (int) $category['id'] ?>" <?= $selectedCategoryId === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($category['name'] ?? 'Kategorie #' . $category['id'])) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-6 col-lg-3">
                          <label class="form-label">Zimmer</label>
                          <select class="form-select reservation-room-select" name="reservation_categories[<?= $categoryIndex ?>][room_id]" <?= $pdo === null ? 'disabled' : '' ?>>
                            <option value="">Kein konkretes Zimmer – Überbuchung</option>
                            <?php if ($selectedRoomId !== null): ?>
                              <option value="<?= $selectedRoomId ?>" selected><?= htmlspecialchars($selectedRoomLabel) ?></option>
                            <?php endif; ?>
                          </select>
                        </div>
                        <div class="col-6 col-lg-2">
                          <label class="form-label">Zimmeranzahl</label>
                          <input type="number" class="form-control" name="reservation_categories[<?= $categoryIndex ?>][room_quantity]" min="1" value="<?= htmlspecialchars($quantityValue) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                          <div class="form-text">Automatisch 1 bei Zimmerauswahl.</div>
                        </div>
                        <div class="col-6 col-lg-3 mt-2 mt-lg-0">
                          <label class="form-label">Personen im Zimmer *</label>
                          <input type="number" class="form-control" name="reservation_categories[<?= $categoryIndex ?>][occupancy]" min="1" value="<?= htmlspecialchars($occupancyValue) ?>" <?= $pdo === null ? 'disabled' : 'required' ?>>
                          <div class="form-text">Gäste für dieses Zimmer.</div>
                        </div>
                        <div class="col-12 col-lg-4 mt-2">
                          <label class="form-label">Zimmer-Anreise</label>
                          <input type="date" class="form-control reservation-item-arrival" name="reservation_categories[<?= $categoryIndex ?>][arrival_date]" value="<?= htmlspecialchars($roomArrivalValue) ?>" min="<?= htmlspecialchars($todayDateValue) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                          <div class="form-text">Standard ist die Reservierungs-Anreise.</div>
                        </div>
                        <div class="col-12 col-lg-4 mt-2">
                          <label class="form-label">Zimmer-Abreise</label>
                          <input type="date" class="form-control reservation-item-departure" name="reservation_categories[<?= $categoryIndex ?>][departure_date]" value="<?= htmlspecialchars($roomDepartureValue) ?>" min="<?= htmlspecialchars($departureMinValue) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                          <div class="form-text">Standard ist die Reservierungs-Abreise.</div>
                        </div>
                        <div class="col-12 col-xl-4 mt-2">
                          <label class="form-label">Rate *</label>
                          <?php if ($rates === []): ?>
                            <select class="form-select reservation-item-rate" name="reservation_categories[<?= $categoryIndex ?>][rate_id]" disabled>
                              <?= $reservationRateOptionsHtml ?>
                            </select>
                            <div class="form-text text-danger">Bitte legen Sie zuvor Raten im Modul „Raten“ an.</div>
                          <?php else: ?>
                            <select class="form-select reservation-item-rate" name="reservation_categories[<?= $categoryIndex ?>][rate_id]" data-rate-select <?= $pdo === null ? 'disabled' : 'required' ?>>
                              <?= $itemRateOptionsHtml ?>
                            </select>
                            <div class="form-text">Legt die Basispreise für diese Kategorie fest.</div>
                          <?php endif; ?>
                        </div>
                        <div class="col-12 mt-3">
                          <div class="reservation-articles border rounded p-3" data-article-section>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                              <div>
                                <h3 class="h6 mb-0">Artikel</h3>
                                <div class="text-muted small">Zusatzleistungen werden automatisch in die Gesamtsumme einberechnet.</div>
                              </div>
                              <button type="button" class="btn btn-outline-secondary btn-sm reservation-article-add" data-article-add <?= $pdo === null ? 'disabled' : '' ?>>Artikel hinzufügen</button>
                            </div>
                            <div class="reservation-article-list" data-article-list>
                              <?php foreach ($itemArticles as $articleIndex => $articleRow): ?>
                                <?php
                                  $articleIdValue = isset($articleRow['article_id']) ? (string) $articleRow['article_id'] : '';
                                  $articleQuantityValue = isset($articleRow['quantity']) ? (string) $articleRow['quantity'] : '1';
                                  $articlePricingTypeValue = isset($articleRow['pricing_type'])
                                      ? (string) $articleRow['pricing_type']
                                      : ArticleManager::PRICING_PER_DAY;
                                  if ($articlePricingTypeValue === ArticleManager::PRICING_PER_PERSON_PER_DAY) {
                                      $articleQuantityValue = '1';
                                  }
                                  $articleTotalValue = isset($articleRow['total_price']) ? (string) $articleRow['total_price'] : '';
                                  $selectedArticleId = $articleIdValue !== '' ? (int) $articleIdValue : null;
                                  $articleOptions = $buildArticleSelectOptions !== null
                                      ? $buildArticleSelectOptions($selectedArticleId)
                                      : $articleSelectOptionsHtml;
                                ?>
                                <div class="reservation-article-row row g-2 align-items-end mb-2" data-article-row>
                                  <div class="col-md-6">
                                    <label class="form-label">Artikel</label>
                                    <select class="form-select reservation-article-select" name="reservation_categories[<?= $categoryIndex ?>][articles][<?= $articleIndex ?>][article_id]" data-article-select <?= $pdo === null ? 'disabled' : '' ?>>
                                      <?= $articleOptions ?>
                                    </select>
                                  </div>
                                  <div class="col-6 col-md-3" data-article-quantity-column>
                                    <label class="form-label" data-article-quantity-label>Menge</label>
                                    <input type="number" class="form-control reservation-article-quantity" name="reservation_categories[<?= $categoryIndex ?>][articles][<?= $articleIndex ?>][quantity]" min="1" value="<?= htmlspecialchars($articleQuantityValue) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                                    <div class="form-text text-muted d-none" data-article-quantity-hint>Wird automatisch je Person und Nacht berechnet.</div>
                                  </div>
                                  <div class="col-6 col-md-3">
                                    <label class="form-label">Summe (EUR)</label>
                                    <input type="text" class="form-control reservation-article-total" name="reservation_categories[<?= $categoryIndex ?>][articles][<?= $articleIndex ?>][total_price]" value="<?= htmlspecialchars($articleTotalValue) ?>" readonly>
                                  </div>
                                  <div class="col-12 text-end">
                                    <button type="button" class="btn btn-outline-danger btn-sm reservation-article-remove" data-article-remove <?= $pdo === null ? 'disabled' : '' ?>>Entfernen</button>
                                  </div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                            <div class="text-end small <?= $articleSummaryValue === '' ? 'text-muted' : 'fw-semibold' ?>" data-article-summary>
                              <?= $articleSummaryValue !== '' ? 'Artikel gesamt: € ' . htmlspecialchars($articleSummaryValue) : 'Noch keine Artikel ausgewählt.' ?>
                            </div>
                          </div>
                        </div>
                        <div class="col-12 col-xl-6 mt-2">
                          <label class="form-label">Meldeschein-Hauptgast *</label>
                          <div class="typeahead position-relative" data-typeahead="guest-item" data-endpoint="index.php?ajax=guest_search">
                            <input type="hidden" name="reservation_categories[<?= $categoryIndex ?>][primary_guest_id]" value="<?= htmlspecialchars($primaryGuestIdValue) ?>">
                            <input
                              type="search"
                              class="form-control typeahead-input"
                              name="reservation_categories[<?= $categoryIndex ?>][primary_guest_query]"
                              placeholder="Gast auswählen"
                              value="<?= htmlspecialchars($primaryGuestQueryValue) ?>"
                              autocomplete="off"
                              data-minlength="2"
                              <?= $pdo === null ? 'disabled' : 'required' ?>
                            >
                            <div class="typeahead-dropdown list-group shadow-sm" role="listbox" aria-label="Gastvorschläge"></div>
                          </div>
                          <div class="form-text">Wird auf dem Meldeschein geführt.</div>
                        </div>
                        <div class="col-6 col-xl-4 mt-2">
                          <label class="form-label">Preis pro Nacht (EUR)</label>
                          <input type="text" class="form-control reservation-item-price-night" name="reservation_categories[<?= $categoryIndex ?>][price_per_night]" value="<?= htmlspecialchars($roomPricePerNightValue) ?>" placeholder="z. B. 129,00" <?= $pdo === null ? 'disabled' : '' ?>>
                          <div class="form-text">Bruttopreis pro Zimmer.</div>
                        </div>
                        <div class="col-6 col-xl-4 mt-2">
                          <label class="form-label">Gesamtpreis (EUR)</label>
                          <input type="text" class="form-control reservation-item-price-total" name="reservation_categories[<?= $categoryIndex ?>][total_price]" value="<?= htmlspecialchars($roomTotalPriceValue) ?>" placeholder="z. B. 258,00" <?= $pdo === null ? 'disabled' : 'required' ?>>
                          <div class="form-text">Summe für diese Kategorie.</div>
                        </div>
                        <div class="col-12 mt-2 d-flex align-items-center gap-2 flex-wrap">
                          <button type="button" class="btn btn-outline-secondary btn-sm reservation-item-calc" data-calc-index="<?= $categoryIndex ?>" <?= $pdo === null || $rates === [] ? 'disabled' : '' ?>>Preis anhand Rate berechnen</button>
                          <span class="text-muted small" data-calc-feedback="<?= $categoryIndex ?>">Berechnet die Preise für diese Zeile basierend auf Rate und Zeitraum.</span>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                          <button type="button" class="btn btn-outline-danger btn-sm" data-remove-category <?= $disableRemove ? 'disabled' : '' ?>>Entfernen</button>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                  <button type="button" class="btn btn-outline-primary btn-sm" id="add-reservation-category" <?= $pdo === null ? 'disabled' : '' ?>>Weitere Kategorie hinzufügen</button>
                  <span class="text-muted small">Überbuchungen möglich, wenn kein Zimmer gewählt.</span>
                </div>
              </div>
              <template id="reservation-category-template">
                <div class="reservation-category-item card card-body border p-3 mb-2" data-index="__INDEX__">
                  <div class="row g-2 align-items-end">
                    <div class="col-12 col-lg-4">
                      <label class="form-label">Kategorie</label>
                      <select class="form-select" name="reservation_categories[__INDEX__][category_id]" <?= $pdo === null ? 'disabled' : 'required' ?>>
                        <?= $reservationCategoryOptionsHtml ?>
                      </select>
                    </div>
                    <div class="col-6 col-lg-3">
                      <label class="form-label">Zimmer</label>
                      <select class="form-select reservation-room-select" name="reservation_categories[__INDEX__][room_id]" <?= $pdo === null ? 'disabled' : '' ?>>
                        <option value="">Kein konkretes Zimmer – Überbuchung</option>
                      </select>
                    </div>
                    <div class="col-6 col-lg-2">
                      <label class="form-label">Zimmeranzahl</label>
                      <input type="number" class="form-control" name="reservation_categories[__INDEX__][room_quantity]" min="1" value="1" <?= $pdo === null ? 'disabled' : '' ?>>
                      <div class="form-text">Automatisch 1 bei Zimmerauswahl.</div>
                    </div>
                    <div class="col-6 col-lg-3 mt-2 mt-lg-0">
                      <label class="form-label">Personen im Zimmer *</label>
                      <input type="number" class="form-control" name="reservation_categories[__INDEX__][occupancy]" min="1" value="1" <?= $pdo === null ? 'disabled' : 'required' ?>>
                      <div class="form-text">Gäste für dieses Zimmer.</div>
                    </div>
                    <div class="col-12 col-lg-4 mt-2">
                      <label class="form-label">Zimmer-Anreise</label>
                      <input type="date" class="form-control reservation-item-arrival" name="reservation_categories[__INDEX__][arrival_date]" value="<?= htmlspecialchars((string) $reservationFormData['arrival_date']) ?>" min="<?= htmlspecialchars($todayDateValue) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                      <div class="form-text">Standard ist die Reservierungs-Anreise.</div>
                    </div>
                    <div class="col-12 col-lg-4 mt-2">
                      <label class="form-label">Zimmer-Abreise</label>
                      <input type="date" class="form-control reservation-item-departure" name="reservation_categories[__INDEX__][departure_date]" value="<?= htmlspecialchars((string) $reservationFormData['departure_date']) ?>" min="<?= htmlspecialchars($todayDateValue) ?>" <?= $pdo === null ? 'disabled' : '' ?>>
                      <div class="form-text">Standard ist die Reservierungs-Abreise.</div>
                    </div>
                    <div class="col-12 col-xl-4 mt-2">
                      <label class="form-label">Rate *</label>
                      <?php if ($rates === []): ?>
                        <select class="form-select reservation-item-rate" name="reservation_categories[__INDEX__][rate_id]" disabled>
                          <?= $reservationRateOptionsHtml ?>
                        </select>
                        <div class="form-text text-danger">Bitte legen Sie zuvor Raten im Modul „Raten“ an.</div>
                      <?php else: ?>
                        <select class="form-select reservation-item-rate" name="reservation_categories[__INDEX__][rate_id]" data-rate-select <?= $pdo === null ? 'disabled' : 'required' ?>>
                          <?= $reservationRateOptionsHtml ?>
                        </select>
                        <div class="form-text">Legt die Basispreise für diese Kategorie fest.</div>
                      <?php endif; ?>
                    </div>
                    <div class="col-12 mt-3">
                      <div class="reservation-articles border rounded p-3" data-article-section>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                          <div>
                            <h3 class="h6 mb-0">Artikel</h3>
                            <div class="text-muted small">Zusatzleistungen werden automatisch in die Gesamtsumme einberechnet.</div>
                          </div>
                          <button type="button" class="btn btn-outline-secondary btn-sm reservation-article-add" data-article-add <?= $pdo === null ? 'disabled' : '' ?>>Artikel hinzufügen</button>
                        </div>
                        <div class="reservation-article-list" data-article-list>
                          <div class="reservation-article-row row g-2 align-items-end mb-2" data-article-row>
                            <div class="col-md-6">
                              <label class="form-label">Artikel</label>
                              <select class="form-select reservation-article-select" name="reservation_categories[__INDEX__][articles][0][article_id]" data-article-select <?= $pdo === null ? 'disabled' : '' ?>>
                                <?= $buildArticleSelectOptions !== null ? $buildArticleSelectOptions(null) : $articleSelectOptionsHtml ?>
                              </select>
                            </div>
                              <div class="col-6 col-md-3" data-article-quantity-column>
                                <label class="form-label" data-article-quantity-label>Menge</label>
                                <input type="number" class="form-control reservation-article-quantity" name="reservation_categories[__INDEX__][articles][0][quantity]" min="1" value="1" <?= $pdo === null ? 'disabled' : '' ?>>
                                <div class="form-text text-muted d-none" data-article-quantity-hint>Wird automatisch je Person und Nacht berechnet.</div>
                              </div>
                            <div class="col-6 col-md-3">
                              <label class="form-label">Summe (EUR)</label>
                              <input type="text" class="form-control reservation-article-total" name="reservation_categories[__INDEX__][articles][0][total_price]" value="" readonly>
                            </div>
                            <div class="col-12 text-end">
                              <button type="button" class="btn btn-outline-danger btn-sm reservation-article-remove" data-article-remove <?= $pdo === null ? 'disabled' : '' ?>>Entfernen</button>
                            </div>
                          </div>
                        </div>
                        <div class="text-end small text-muted" data-article-summary>Noch keine Artikel ausgewählt.</div>
                      </div>
                    </div>
                    <div class="col-12 col-xl-6 mt-2">
                      <label class="form-label">Meldeschein-Hauptgast *</label>
                      <div class="typeahead position-relative" data-typeahead="guest-item" data-endpoint="index.php?ajax=guest_search">
                        <input type="hidden" name="reservation_categories[__INDEX__][primary_guest_id]" value="<?= htmlspecialchars((string) $reservationFormData['guest_id']) ?>">
                        <input
                          type="search"
                          class="form-control typeahead-input"
                          name="reservation_categories[__INDEX__][primary_guest_query]"
                          placeholder="Gast auswählen"
                          value="<?= htmlspecialchars((string) $reservationFormData['guest_query']) ?>"
                          autocomplete="off"
                          data-minlength="2"
                          <?= $pdo === null ? 'disabled' : 'required' ?>
                        >
                        <div class="typeahead-dropdown list-group shadow-sm" role="listbox" aria-label="Gastvorschläge"></div>
                      </div>
                      <div class="form-text">Wird auf dem Meldeschein geführt.</div>
                    </div>
                    <div class="col-6 col-xl-4 mt-2">
                      <label class="form-label">Preis pro Nacht (EUR)</label>
                      <input type="text" class="form-control reservation-item-price-night" name="reservation_categories[__INDEX__][price_per_night]" value="" placeholder="z. B. 129,00" <?= $pdo === null ? 'disabled' : '' ?>>
                      <div class="form-text">Bruttopreis pro Zimmer.</div>
                    </div>
                    <div class="col-6 col-xl-4 mt-2">
                      <label class="form-label">Gesamtpreis (EUR)</label>
                      <input type="text" class="form-control reservation-item-price-total" name="reservation_categories[__INDEX__][total_price]" value="" placeholder="z. B. 258,00" <?= $pdo === null ? 'disabled' : 'required' ?>>
                      <div class="form-text">Summe für diese Kategorie.</div>
                    </div>
                    <div class="col-12 mt-2 d-flex align-items-center gap-2 flex-wrap">
                      <button type="button" class="btn btn-outline-secondary btn-sm reservation-item-calc" data-calc-index="__INDEX__" <?= $pdo === null || $rates === [] ? 'disabled' : '' ?>>Preis anhand Rate berechnen</button>
                      <span class="text-muted small" data-calc-feedback="__INDEX__">Berechnet die Preise für diese Zeile basierend auf Rate und Zeitraum.</span>
                    </div>
                  </div>
                  <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-outline-danger btn-sm" data-remove-category>Entfernen</button>
                  </div>
                </div>
              </template>
              <template id="reservation-article-template">
                <div class="reservation-article-row row g-2 align-items-end mb-2" data-article-row>
                  <div class="col-md-6">
                    <label class="form-label">Artikel</label>
                    <select class="form-select reservation-article-select" name="reservation_categories[__INDEX__][articles][__ARTICLE_INDEX__][article_id]" data-article-select <?= $pdo === null ? 'disabled' : '' ?>>
                      <?= $buildArticleSelectOptions !== null ? $buildArticleSelectOptions(null) : $articleSelectOptionsHtml ?>
                    </select>
                  </div>
                  <div class="col-6 col-md-3" data-article-quantity-column>
                    <label class="form-label" data-article-quantity-label>Menge</label>
                    <input type="number" class="form-control reservation-article-quantity" name="reservation_categories[__INDEX__][articles][__ARTICLE_INDEX__][quantity]" min="1" value="1" <?= $pdo === null ? 'disabled' : '' ?>>
                    <div class="form-text text-muted d-none" data-article-quantity-hint>Wird automatisch je Person und Nacht berechnet.</div>
                  </div>
                  <div class="col-6 col-md-3">
                    <label class="form-label">Summe (EUR)</label>
                    <input type="text" class="form-control reservation-article-total" name="reservation_categories[__INDEX__][articles][__ARTICLE_INDEX__][total_price]" value="" readonly>
                  </div>
                  <div class="col-12 text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm reservation-article-remove" data-article-remove <?= $pdo === null ? 'disabled' : '' ?>>Entfernen</button>
                  </div>
                </div>
              </template>
              <div class="col-12">
                <hr class="my-3">
              </div>
              <div class="col-12">
                <label class="form-label">Gesamtsumme (EUR)</label>
                <input type="text" class="form-control" id="reservation-grand-total" name="grand_total" value="<?= htmlspecialchars((string) $reservationFormData['grand_total']) ?>" readonly>
                <div class="form-text">Summe aller Kategorien (Brutto). Anpassungen erfolgen je Kategorie.</div>
              </div>
              <div class="col-md-6">
                <label for="reservation-status" class="form-label">Status *</label>
                <select class="form-select" id="reservation-status" name="status" required <?= $pdo === null ? 'disabled' : '' ?>>
                  <?php foreach ($reservationStatuses as $statusOption): ?>
                    <?php $statusLabel = $reservationStatusMeta[$statusOption]['label'] ?? ucfirst($statusOption); ?>
                    <option value="<?= htmlspecialchars($statusOption) ?>" <?= $reservationFormData['status'] === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label for="reservation-notes" class="form-label">Notizen</label>
                <textarea class="form-control" id="reservation-notes" name="notes" rows="3" <?= $pdo === null ? 'disabled' : '' ?>><?= htmlspecialchars((string) $reservationFormData['notes']) ?></textarea>
                <div class="form-text">Interne Hinweise für Rezeption oder Housekeeping.</div>
              </div>
              <div class="col-12 d-flex justify-content-end gap-2 flex-wrap">
                <?php if ($isReservationEditing): ?>
                  <a href="index.php?section=reservations" class="btn btn-outline-secondary">Abbrechen</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" <?= $pdo === null ? 'disabled' : '' ?>><?= $isReservationEditing ? 'Reservierung aktualisieren' : 'Reservierung speichern' ?></button>
              </div>
            </form>
            <?php if ($pdo === null): ?>
              <p class="text-muted mt-3 mb-0">Die Formularfelder werden aktiviert, sobald eine Datenbankverbindung besteht.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="reservationDetailModal" tabindex="-1" aria-labelledby="reservationDetailModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h1 class="modal-title fs-5" id="reservationDetailModalLabel" data-detail="title">Reservierung</h1>
              <div class="small text-muted" data-detail="subtitle"></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
          </div>
          <div class="modal-body">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
              <span class="badge d-none" data-detail="status"></span>
              <a href="#" class="btn btn-outline-secondary btn-sm d-none" data-detail="edit-link">Reservierung öffnen</a>
            </div>
            <div class="alert alert-warning d-none" role="status" data-detail="overbooking">Diese Reservierung ist derzeit ohne feste Zimmerzuweisung.</div>
            <dl class="row reservation-detail-list">
              <dt class="col-sm-4">Nummer</dt>
              <dd class="col-sm-8" data-detail="number"></dd>
              <dt class="col-sm-4">Gast</dt>
              <dd class="col-sm-8" data-detail="guest"></dd>
              <dt class="col-sm-4">Firma</dt>
              <dd class="col-sm-8" data-detail="company"></dd>
              <dt class="col-sm-4">Zeitraum</dt>
              <dd class="col-sm-8" data-detail="stay"></dd>
              <dt class="col-sm-4">Kategorie / Zimmer</dt>
              <dd class="col-sm-8" data-detail="room"></dd>
              <dt class="col-sm-4">Zimmeranzahl</dt>
              <dd class="col-sm-8" data-detail="quantity"></dd>
              <dt class="col-sm-4">Personen</dt>
              <dd class="col-sm-8" data-detail="occupancy"></dd>
              <dt class="col-sm-4">Meldeschein</dt>
              <dd class="col-sm-8" data-detail="primary-guest"></dd>
              <dt class="col-sm-4">Rate</dt>
              <dd class="col-sm-8" data-detail="rate"></dd>
              <dt class="col-sm-4">Preis pro Nacht</dt>
              <dd class="col-sm-8" data-detail="price-per-night"></dd>
              <dt class="col-sm-4">Gesamtpreis</dt>
              <dd class="col-sm-8" data-detail="total-price"></dd>
              <dt class="col-sm-4 d-none" data-detail="articles-label">Artikel</dt>
              <dd class="col-sm-8 d-none" data-detail="articles"></dd>
              <dt class="col-sm-4 d-none" data-detail="articles-total-label">Artikel gesamt</dt>
              <dd class="col-sm-8 d-none" data-detail="articles-total"></dd>
              <dt class="col-sm-4">Übernachtungen</dt>
              <dd class="col-sm-8" data-detail="nights"></dd>
              <dt class="col-sm-4">MwSt.</dt>
              <dd class="col-sm-8" data-detail="vat"></dd>
            </dl>
            <div class="reservation-notes mt-3 d-none" data-detail="notes-wrapper">
              <h2 class="h6 mb-1">Notizen</h2>
              <p class="mb-0" data-detail="notes"></p>
            </div>
            <div class="reservation-audit mt-3">
              <div class="small text-muted" data-detail="created"></div>
              <div class="small text-muted" data-detail="updated"></div>
            </div>
          </div>
          <div class="modal-footer flex-wrap gap-2">
            <form method="post" id="reservation-status-form" class="d-flex flex-wrap gap-2 align-items-center">
              <input type="hidden" name="form" value="reservation_status_update">
              <input type="hidden" name="id" id="reservation-status-id">
              <input type="hidden" name="status" id="reservation-status-value">
              <input type="hidden" name="redirect_section" value="<?= htmlspecialchars($activeSection) ?>">
              <input type="hidden" name="redirect_date" id="reservation-status-date" value="<?= htmlspecialchars($calendarCurrentDateValue) ?>">
              <input type="hidden" name="redirect_display" id="reservation-status-display" value="<?= htmlspecialchars($calendarOccupancyDisplay) ?>">
              <span class="fw-semibold me-2">Status setzen:</span>
              <div class="btn-group flex-wrap" role="group" data-detail="status-buttons">
                <?php foreach ($reservationStatuses as $statusOption): ?>
                  <?php $statusLabel = $reservationStatusMeta[$statusOption]['label'] ?? ucfirst($statusOption); ?>
                  <button type="button" class="btn btn-outline-secondary" data-status-action="<?= htmlspecialchars($statusOption) ?>"><?= htmlspecialchars($statusLabel) ?></button>
                <?php endforeach; ?>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
      (function () {
        function parseISODate(value) {
          if (typeof value !== 'string' || value.trim() === '') {
            return null;
          }

          var parts = value.split('-');
          if (parts.length !== 3) {
            return null;
          }

          var year = parseInt(parts[0], 10);
          var month = parseInt(parts[1], 10);
          var day = parseInt(parts[2], 10);

          if (Number.isNaN(year) || Number.isNaN(month) || Number.isNaN(day)) {
            return null;
          }

          return new Date(Date.UTC(year, month - 1, day));
        }

        function formatISODate(date) {
          if (!(date instanceof Date)) {
            return '';
          }

          return date.toISOString().slice(0, 10);
        }

        function addDays(date, amount) {
          if (!(date instanceof Date) || typeof amount !== 'number' || Number.isNaN(amount)) {
            return null;
          }

          var copy = new Date(date.getTime());
          copy.setUTCDate(copy.getUTCDate() + amount);
          return copy;
        }

        function isBefore(first, second) {
          if (!(first instanceof Date) || !(second instanceof Date)) {
            return false;
          }

          return first.getTime() < second.getTime();
        }

        function isOnOrBefore(first, second) {
          if (!(first instanceof Date) || !(second instanceof Date)) {
            return false;
          }

          return first.getTime() <= second.getTime();
        }

        var now = new Date();
        var todayUtcDate = new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
        var todayIsoString = formatISODate(todayUtcDate);

        function setupReservationFormModal() {
          var modalElement = document.getElementById('reservationFormModal');
          var modalLibrary = window.bootstrap || (typeof bootstrap !== 'undefined' ? bootstrap : null);

          if (!modalElement || !modalLibrary || !modalLibrary.Modal) {
            return;
          }

          var modal = modalLibrary.Modal.getOrCreateInstance(modalElement);
          var shouldOpen = <?= $shouldOpenReservationModal ? 'true' : 'false' ?>;

          if (shouldOpen) {
            modal.show();
          }

          modalElement.addEventListener('shown.bs.modal', function () {
            var guestInput = modalElement.querySelector('#reservation-guest-query');
            if (guestInput) {
              try {
                guestInput.focus({ preventScroll: true });
              } catch (error) {
                guestInput.focus();
              }
            }
          });

          modalElement.addEventListener('hidden.bs.modal', function () {
            try {
              var url = new URL(window.location.href);
              var changed = false;

              if (url.searchParams.has('openReservationModal')) {
                url.searchParams.delete('openReservationModal');
                changed = true;
              }

              if (url.searchParams.has('editReservation')) {
                url.searchParams.delete('editReservation');
                changed = true;
              }

              if (changed) {
                var search = url.searchParams.toString();
                var newUrl = url.pathname + (search ? '?' + search : '') + url.hash;
                window.history.replaceState({}, '', newUrl);
              }
            } catch (error) {
              console.warn('Konnte URL nach Schließen des Reservierungsformulars nicht aktualisieren', error);
            }
          });
        }

        var reservationItemGuestTypeaheads = [];

        function initializeItemGuestTypeahead(item) {
          if (!item) {
            return;
          }

          var container = item.querySelector('[data-typeahead="guest-item"]');
          if (!container || container.getAttribute('data-typeahead-initialized') === '1') {
            return;
          }

          var instance = setupTypeahead(container, {});
          if (instance) {
            container.setAttribute('data-typeahead-initialized', '1');
            reservationItemGuestTypeaheads.push({ container: container, instance: instance });
          }
        }

        function cleanupItemGuestTypeaheads() {
          reservationItemGuestTypeaheads = reservationItemGuestTypeaheads.filter(function (entry) {
            return entry && entry.container && entry.container.isConnected;
          });
        }

        function setupReservationCategoryRepeater() {
          var container = document.getElementById('reservation-category-list');
          var template = document.getElementById('reservation-category-template');
          var articleTemplate = document.getElementById('reservation-article-template');
          var addButton = document.getElementById('add-reservation-category');

          if (!container || !template || !addButton) {
            return;
          }

          var nextIndex = parseInt(container.getAttribute('data-next-index') || '0', 10);
          if (Number.isNaN(nextIndex) || nextIndex < container.children.length) {
            nextIndex = container.children.length;
          }

          var reservationIdField = document.querySelector('#reservation-form input[name="id"]');
          var reservationIdValue = reservationIdField ? parseInt(reservationIdField.value || '0', 10) : 0;

          function formatMoney(value) {
            if (typeof value !== 'number' || Number.isNaN(value)) {
              return '';
            }

            return value.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }

          function triggerGrandTotalUpdate() {
            document.dispatchEvent(new CustomEvent('reservation:grand-total-recalculate'));
          }

          container.querySelectorAll('.reservation-category-item').forEach(function (item) {
            initializeItemGuestTypeahead(item);
          });

          container.addEventListener('input', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
              return;
            }

            if (target.matches('input[name$="[occupancy]"]')) {
              var item = target.closest('.reservation-category-item');
              recalculateArticlesForItem(item);
            }
          });

          function updateRemoveButtons() {
            var items = container.querySelectorAll('.reservation-category-item');
            items.forEach(function (item, index) {
              var button = item.querySelector('[data-remove-category]');
              if (!button) {
                return;
              }
              if (items.length === 1 && index === 0) {
                button.setAttribute('disabled', 'disabled');
              } else {
                button.removeAttribute('disabled');
              }
            });
          }

          function bindRoomQuantityBehaviour(item) {
            if (!item) {
              return;
            }

            var roomSelect = item.querySelector('.reservation-room-select');
            var quantityInput = item.querySelector('input[name$="[room_quantity]"]');

            if (!roomSelect || !quantityInput) {
              return;
            }

            function updateQuantityState() {
              if (roomSelect.value !== '') {
                quantityInput.value = '1';
                quantityInput.setAttribute('readonly', 'readonly');
                quantityInput.classList.add('reservation-quantity-readonly');
              } else {
                quantityInput.removeAttribute('readonly');
                quantityInput.classList.remove('reservation-quantity-readonly');
              }

              document.dispatchEvent(new CustomEvent('reservation:categories-changed'));
            }

            roomSelect.addEventListener('change', updateQuantityState);
            updateQuantityState();
          }

          function updateItemDateLimits(item) {
            if (!item) {
              return;
            }

            var arrivalField = item.querySelector('.reservation-item-arrival');
            var departureField = item.querySelector('.reservation-item-departure');

            var arrivalDateObj = null;

            if (arrivalField && arrivalField.value) {
              arrivalDateObj = parseISODate(arrivalField.value);
            }

            if (arrivalField) {
              if (!arrivalDateObj || !isBefore(arrivalDateObj, todayUtcDate)) {
                arrivalField.setAttribute('min', todayIsoString);
              } else {
                arrivalField.removeAttribute('min');
              }
            }

            if (!departureField) {
              return;
            }

            var minDepartureDate = new Date(todayUtcDate.getTime());
            if (arrivalDateObj instanceof Date) {
              var nextDay = addDays(arrivalDateObj, 1);
              if (nextDay instanceof Date && isBefore(minDepartureDate, nextDay)) {
                minDepartureDate = nextDay;
              }
            }

            var departureMinValue = formatISODate(minDepartureDate);
            if (departureMinValue) {
              departureField.setAttribute('min', departureMinValue);
            } else {
              departureField.removeAttribute('min');
            }

            var departureDateObj = null;
            if (departureField.value) {
              departureDateObj = parseISODate(departureField.value);
            }

            if (departureDateObj instanceof Date) {
              if (arrivalDateObj instanceof Date && isOnOrBefore(departureDateObj, arrivalDateObj)) {
                departureField.value = departureMinValue;
              } else if (isBefore(departureDateObj, minDepartureDate)) {
                departureField.value = departureMinValue;
              }
            }
          }

          function updateRoomOptions(item) {
            if (!item) {
              return;
            }

            var categorySelect = item.querySelector('select[name$="[category_id]"]');
            var roomSelect = item.querySelector('.reservation-room-select');
            var arrivalField = item.querySelector('.reservation-item-arrival');
            var departureField = item.querySelector('.reservation-item-departure');

            if (!categorySelect || !roomSelect || !arrivalField || !departureField) {
              return;
            }

            var categoryId = parseInt(categorySelect.value || '', 10);
            var arrivalValue = arrivalField.value || '';
            var departureValue = departureField.value || '';
            var currentValue = roomSelect.value || '';
            var currentLabel = '';
            if (currentValue) {
              var selectedOption = roomSelect.querySelector('option[value="' + currentValue + '"]');
              if (selectedOption) {
                currentLabel = selectedOption.textContent || '';
              }
            }

            while (roomSelect.options.length > 0) {
              roomSelect.remove(0);
            }
            roomSelect.add(new Option('Kein konkretes Zimmer – Überbuchung', ''));

            if (!categoryId || !arrivalValue || !departureValue) {
              if (currentValue) {
                var fallbackLabel = currentLabel || ('Zimmer #' + currentValue + ' (zugewiesen)');
                var fallbackOption = new Option(fallbackLabel, currentValue);
                fallbackOption.selected = true;
                roomSelect.add(fallbackOption);
              }
              roomSelect.disabled = false;
              return;
            }

            roomSelect.disabled = true;

            var params = new URLSearchParams({
              ajax: 'available_rooms',
              categoryId: categoryId,
              arrivalDate: arrivalValue,
              departureDate: departureValue
            });
            if (reservationIdValue) {
              params.append('ignoreReservationId', String(reservationIdValue));
            }

            fetch('index.php?' + params.toString())
              .then(function (response) { return response.json(); })
              .then(function (data) {
                var rooms = data && Array.isArray(data.rooms) ? data.rooms : [];
                var currentIncluded = false;

                rooms.forEach(function (room) {
                  if (!room || room.id == null) {
                    return;
                  }

                  var roomId = String(room.id);
                  var rawLabel = room.label ? String(room.label).trim() : '';
                  var optionLabel = rawLabel;

                  if (!optionLabel) {
                    optionLabel = '#' + roomId;
                  }

                  if (/^#?\d+$/u.test(optionLabel)) {
                    optionLabel = optionLabel.replace(/^#/, '');
                    optionLabel = 'Zimmer ' + optionLabel;
                  }

                  var option = new Option(optionLabel, roomId);
                  if (roomId === currentValue) {
                    option.selected = true;
                    currentIncluded = true;
                  }
                  roomSelect.add(option);
                });

                if (currentValue && !currentIncluded) {
                  var fallbackLabel = currentLabel || ('Zimmer #' + currentValue + ' (zugewiesen)');
                  var fallbackOption = new Option(fallbackLabel, currentValue);
                  fallbackOption.selected = true;
                  roomSelect.add(fallbackOption);
                }
              })
              .catch(function () {
                if (currentValue) {
                  var fallbackLabel = currentLabel || ('Zimmer #' + currentValue + ' (zugewiesen)');
                  var fallbackOption = new Option(fallbackLabel, currentValue);
                  fallbackOption.selected = true;
                  roomSelect.add(fallbackOption);
                }
              })
              .finally(function () {
                roomSelect.disabled = false;
              });
          }

          function bindItemDateBehaviour(item) {
            if (!item) {
              return;
            }

            var arrivalField = item.querySelector('.reservation-item-arrival');
            var departureField = item.querySelector('.reservation-item-departure');

            function sync() {
              updateItemDateLimits(item);
              updateRoomOptions(item);
              recalculateArticlesForItem(item);
            }

            if (arrivalField) {
              arrivalField.addEventListener('change', function () {
                var parsedArrival = arrivalField.value ? parseISODate(arrivalField.value) : null;
                if (parsedArrival instanceof Date && isBefore(parsedArrival, todayUtcDate)) {
                  arrivalField.value = todayIsoString;
                }
                sync();
                document.dispatchEvent(new CustomEvent('reservation:categories-changed'));
              });
            }

            if (departureField) {
              departureField.addEventListener('change', function () {
                sync();
                document.dispatchEvent(new CustomEvent('reservation:categories-changed'));
              });
            }

            sync();
          }

          function getItemNights(item) {
            var arrivalField = item.querySelector('.reservation-item-arrival');
            var departureField = item.querySelector('.reservation-item-departure');
            var arrival = arrivalField && arrivalField.value ? parseISODate(arrivalField.value) : null;
            var departure = departureField && departureField.value ? parseISODate(departureField.value) : null;

            if (!(arrival instanceof Date) || !(departure instanceof Date)) {
              return 0;
            }

            var diff = departure.getTime() - arrival.getTime();
            var nights = Math.round(diff / (1000 * 60 * 60 * 24));
            return nights > 0 ? nights : 0;
          }

          function getItemRoomCount(item) {
            var roomSelect = item.querySelector('.reservation-room-select');
            var quantityInput = item.querySelector('input[name$="[room_quantity]"]');
            if (roomSelect && roomSelect.value !== '') {
              return 1;
            }

            var quantity = quantityInput ? parseInt(quantityInput.value || '1', 10) : 1;
            if (Number.isNaN(quantity) || quantity <= 0) {
              quantity = 1;
            }

            return quantity;
          }

          function getItemOccupancy(item) {
            var occupancyInput = item.querySelector('input[name$="[occupancy]"]');
            var value = occupancyInput ? parseInt(occupancyInput.value || '1', 10) : 1;
            if (Number.isNaN(value) || value <= 0) {
              value = 1;
            }

            return value;
          }

          function updateArticleQuantityState(row) {
            if (!row) {
              return;
            }

            var select = row.querySelector('.reservation-article-select');
            var quantityInput = row.querySelector('.reservation-article-quantity');
            var quantityLabel = row.querySelector('[data-article-quantity-label]');
            var hint = row.querySelector('[data-article-quantity-hint]');

            var pricingType = 'per_day';
            if (select && select.options.length > 0) {
              var option = select.options[select.selectedIndex];
              if (option) {
                pricingType = option.getAttribute('data-pricing') || 'per_day';
              }
            }

            var isPerPerson = pricingType === 'per_person_per_day';

            if (quantityInput) {
              if (isPerPerson) {
                quantityInput.value = '1';
                quantityInput.classList.add('d-none');
                quantityInput.setAttribute('readonly', 'readonly');
              } else {
                quantityInput.classList.remove('d-none');
                quantityInput.removeAttribute('readonly');
              }
            }

            if (quantityLabel) {
              if (isPerPerson) {
                quantityLabel.classList.add('d-none');
              } else {
                quantityLabel.classList.remove('d-none');
              }
            }

            if (hint) {
              if (isPerPerson) {
                hint.classList.remove('d-none');
              } else {
                hint.classList.add('d-none');
              }
            }
          }

          function recalculateArticleRow(item, row) {
            if (!row) {
              return 0;
            }

            var select = row.querySelector('.reservation-article-select');
            var quantityInput = row.querySelector('.reservation-article-quantity');
            var totalInput = row.querySelector('.reservation-article-total');

            if (!select || !quantityInput || !totalInput) {
              return 0;
            }

            var selectedOption = select.options[select.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
              totalInput.value = '';
              return 0;
            }

            var unitPrice = parseFloat(selectedOption.getAttribute('data-price') || '0');
            if (!Number.isFinite(unitPrice) || unitPrice <= 0) {
              totalInput.value = '';
              return 0;
            }

            var pricingType = selectedOption.getAttribute('data-pricing') || 'per_day';
            if (pricingType === 'per_person_per_day') {
              quantityInput.value = '1';
            }

            var quantity = parseInt(quantityInput.value || '1', 10);
            if (Number.isNaN(quantity) || quantity <= 0) {
              quantity = 1;
              quantityInput.value = '1';
            }

            var roomCount = getItemRoomCount(item);
            var occupancy = getItemOccupancy(item);
            var nights = getItemNights(item);

            var effectiveUnits = 0;
            if (pricingType === 'per_person_per_day') {
              if (nights <= 0) {
                totalInput.value = '';
                return 0;
              }
              effectiveUnits = nights * roomCount * occupancy * quantity;
            } else if (pricingType === 'one_time') {
              effectiveUnits = quantity;
            } else {
              if (nights <= 0) {
                totalInput.value = '';
                return 0;
              }
              effectiveUnits = nights * roomCount * quantity;
            }

            if (!Number.isFinite(effectiveUnits) || effectiveUnits <= 0) {
              totalInput.value = '';
              return 0;
            }

            var total = unitPrice * effectiveUnits;
            if (!Number.isFinite(total) || total <= 0) {
              totalInput.value = '';
              return 0;
            }

            totalInput.value = formatMoney(total);
            return total;
          }

          function recalculateArticlesForItem(item) {
            if (!item) {
              return;
            }

            var section = item.querySelector('[data-article-section]');
            if (!section) {
              return;
            }

            var total = 0;
            section.querySelectorAll('[data-article-row]').forEach(function (row) {
              total += recalculateArticleRow(item, row);
            });

            var summary = section.querySelector('[data-article-summary]');
            if (summary) {
              if (total > 0) {
                summary.textContent = 'Artikel gesamt: € ' + formatMoney(total);
                summary.classList.remove('text-muted');
                summary.classList.add('fw-semibold');
              } else {
                summary.textContent = 'Noch keine Artikel ausgewählt.';
                summary.classList.add('text-muted');
                summary.classList.remove('fw-semibold');
              }
            }

            triggerGrandTotalUpdate();
          }

          function bindArticleSection(item) {
            if (!item) {
              return;
            }

            var section = item.querySelector('[data-article-section]');
            if (!section) {
              return;
            }

            if (!section.hasAttribute('data-next-article-index')) {
              var existing = section.querySelectorAll('[data-article-row]').length;
              section.setAttribute('data-next-article-index', String(existing));
            }

            section.addEventListener('click', function (event) {
              var target = event.target;
              if (!(target instanceof Element)) {
                return;
              }

              if (target.matches('[data-article-add]')) {
                event.preventDefault();
                if (!articleTemplate) {
                  return;
                }

                var index = item.getAttribute('data-index') || '0';
                var nextArticleIndex = parseInt(section.getAttribute('data-next-article-index') || '0', 10);
                if (Number.isNaN(nextArticleIndex) || nextArticleIndex < 0) {
                  nextArticleIndex = section.querySelectorAll('[data-article-row]').length;
                }

                var html = articleTemplate.innerHTML
                  .replace(/__INDEX__/g, String(index))
                  .replace(/__ARTICLE_INDEX__/g, String(nextArticleIndex));

                section.setAttribute('data-next-article-index', String(nextArticleIndex + 1));

                var wrapper = document.createElement('div');
                wrapper.innerHTML = html.trim();
                var row = wrapper.firstElementChild;
                if (row) {
                  section.querySelector('[data-article-list]').appendChild(row);
                  updateArticleQuantityState(row);
                  recalculateArticleRow(item, row);
                  recalculateArticlesForItem(item);
                  triggerGrandTotalUpdate();
                }
                return;
              }

              if (target.matches('[data-article-remove]')) {
                event.preventDefault();
                var row = target.closest('[data-article-row]');
                if (!row) {
                  return;
                }

                var list = section.querySelector('[data-article-list]');
                if (!list) {
                  return;
                }

                list.removeChild(row);

                if (list.querySelectorAll('[data-article-row]').length === 0) {
                  if (articleTemplate) {
                    var index = item.getAttribute('data-index') || '0';
                    var html = articleTemplate.innerHTML
                      .replace(/__INDEX__/g, String(index))
                      .replace(/__ARTICLE_INDEX__/g, '0');
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    var newRow = wrapper.firstElementChild;
                    if (newRow) {
                      list.appendChild(newRow);
                      updateArticleQuantityState(newRow);
                    }
                  }
                }

                section.setAttribute('data-next-article-index', String(list.querySelectorAll('[data-article-row]').length));

                recalculateArticlesForItem(item);
                triggerGrandTotalUpdate();
                return;
              }
            });

            section.addEventListener('change', function (event) {
              var target = event.target;
              if (!(target instanceof Element)) {
                return;
              }

              if (target.matches('.reservation-article-select')) {
                var row = target.closest('[data-article-row]');
                updateArticleQuantityState(row);
                recalculateArticleRow(item, row);
                recalculateArticlesForItem(item);
                triggerGrandTotalUpdate();
              }
            });

            section.addEventListener('input', function (event) {
              var target = event.target;
              if (!(target instanceof Element)) {
                return;
              }

              if (target.matches('.reservation-article-quantity')) {
                var row = target.closest('[data-article-row]');
                recalculateArticleRow(item, row);
                recalculateArticlesForItem(item);
                triggerGrandTotalUpdate();
              }
            });

            recalculateArticlesForItem(item);
            triggerGrandTotalUpdate();
            section.querySelectorAll('[data-article-row]').forEach(function (row) {
              updateArticleQuantityState(row);
            });
          }

          function createItem() {
            var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
            nextIndex += 1;

            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            return wrapper.firstElementChild;
          }

          addButton.addEventListener('click', function () {
            var newItem = createItem();
            if (!newItem) {
              return;
            }

            container.appendChild(newItem);
            initializeItemGuestTypeahead(newItem);
            cleanupItemGuestTypeaheads();
            bindRoomQuantityBehaviour(newItem);
            bindItemDateBehaviour(newItem);
            bindArticleSection(newItem);
            var categorySelect = newItem.querySelector('select[name$="[category_id]"]');
            if (categorySelect) {
              categorySelect.addEventListener('change', function () {
                updateRoomOptions(newItem);
              });
            }
            updateRoomOptions(newItem);
            updateRemoveButtons();
            document.dispatchEvent(new CustomEvent('reservation:categories-changed'));
          });

          container.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element) || !target.matches('[data-remove-category]')) {
              return;
            }

            var item = target.closest('.reservation-category-item');
            if (!item) {
              return;
            }

            if (container.querySelectorAll('.reservation-category-item').length <= 1) {
              return;
            }

            container.removeChild(item);
            cleanupItemGuestTypeaheads();
            updateRemoveButtons();
            document.dispatchEvent(new CustomEvent('reservation:categories-changed'));
          });

            container.querySelectorAll('.reservation-category-item').forEach(function (item) {
              bindRoomQuantityBehaviour(item);
              bindItemDateBehaviour(item);
              var categorySelect = item.querySelector('select[name$="[category_id]"]');
              if (categorySelect) {
                categorySelect.addEventListener('change', function () {
                  updateRoomOptions(item);
                });
              }
              updateRoomOptions(item);
              bindArticleSection(item);
            });
          updateRemoveButtons();

          document.addEventListener('reservation:master-dates-changed', function () {
            container.querySelectorAll('.reservation-category-item').forEach(function (item) {
              updateItemDateLimits(item);
              updateRoomOptions(item);
              recalculateArticlesForItem(item);
            });
          });

          document.addEventListener('reservation:categories-changed', function () {
            container.querySelectorAll('.reservation-category-item').forEach(function (item) {
              recalculateArticlesForItem(item);
            });
          });
        }

        function setupReservationPricing() {
          var container = document.getElementById('reservation-category-list');
          var totalField = document.getElementById('reservation-grand-total');

          if (!container) {
            return;
          }

          function formatMoney(value) {
            if (typeof value !== 'number' || Number.isNaN(value)) {
              return '';
            }

            return value.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          }

          function parseMoney(value) {
            if (typeof value !== 'string') {
              return null;
            }

            var normalized = value.replace(/[^0-9,.-]/g, '').replace(',', '.');
            if (normalized === '' || !normalized.match(/^-?\d*(\.\d+)?$/)) {
              return null;
            }

            var parsed = parseFloat(normalized);
            return Number.isNaN(parsed) ? null : parsed;
          }

          function updateGrandTotal() {
            if (!totalField) {
              return;
            }

            var sum = 0;
            container.querySelectorAll('.reservation-item-price-total').forEach(function (input) {
              var value = parseMoney(input.value || '');
              if (value !== null) {
                sum += value;
              }
            });

            container.querySelectorAll('.reservation-article-total').forEach(function (input) {
              var value = parseMoney(input.value || '');
              if (value !== null) {
                sum += value;
              }
            });

            totalField.value = sum > 0 ? formatMoney(sum) : '';
          }

          document.addEventListener('reservation:grand-total-recalculate', updateGrandTotal);

          function setItemFeedback(item, message, type) {
            var feedback = item.querySelector('[data-calc-feedback]');
            if (!feedback) {
              return;
            }

            feedback.textContent = message || '';
            feedback.classList.remove('text-danger', 'text-success', 'text-muted');

            if (type === 'error') {
              feedback.classList.add('text-danger');
            } else if (type === 'success') {
              feedback.classList.add('text-success');
            } else {
              feedback.classList.add('text-muted');
            }
          }

          function collectItemPayload(item) {
            var categorySelect = item.querySelector('select[name$="[category_id]"]');
            var rateSelect = item.querySelector('.reservation-item-rate');
            var quantityInput = item.querySelector('input[name$="[room_quantity]"]');
            var arrivalField = item.querySelector('.reservation-item-arrival');
            var departureField = item.querySelector('.reservation-item-departure');

            if (!categorySelect || !rateSelect || !quantityInput || !arrivalField || !departureField) {
              return null;
            }

            var categoryId = parseInt(categorySelect.value || '', 10);
            var rateId = parseInt(rateSelect.value || '', 10);
            var quantity = parseInt(quantityInput.value || '1', 10);
            var arrivalDate = arrivalField.value;
            var departureDate = departureField.value;

            if (Number.isNaN(categoryId) || categoryId <= 0 || Number.isNaN(rateId) || rateId <= 0 || !arrivalDate || !departureDate) {
              return null;
            }

            if (Number.isNaN(quantity) || quantity <= 0) {
              quantity = 1;
            }

            return {
              categoryId: categoryId,
              rateId: rateId,
              quantity: quantity,
              arrivalDate: arrivalDate,
              departureDate: departureDate
            };
          }

          function applyItemPricing(item, data) {
            if (!data) {
              return;
            }

            var priceNightInput = item.querySelector('.reservation-item-price-night');
            var priceTotalInput = item.querySelector('.reservation-item-price-total');

            if (priceNightInput && typeof data.price_per_night === 'number') {
              priceNightInput.value = formatMoney(data.price_per_night);
            }

            if (priceTotalInput && typeof data.total_price === 'number') {
              priceTotalInput.value = formatMoney(data.total_price);
              updateGrandTotal();
            }
          }

          container.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element) || !target.matches('.reservation-item-calc')) {
              return;
            }

            event.preventDefault();
            var item = target.closest('.reservation-category-item');
            if (!item) {
              return;
            }

            var payload = collectItemPayload(item);
            if (!payload) {
              setItemFeedback(item, 'Bitte wählen Sie Rate, Kategorie und gültige Daten.', 'error');
              return;
            }

            setItemFeedback(item, 'Berechnung läuft …', 'muted');
            target.setAttribute('disabled', 'disabled');

            fetch('index.php?ajax=rate_quote', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ items: [payload] })
            }).then(function (response) {
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }

              return response.json();
            }).then(function (data) {
              if (!data || !data.success || !Array.isArray(data.items) || data.items.length === 0) {
                var errorMessage = data && data.error ? data.error : 'Berechnung fehlgeschlagen.';
                setItemFeedback(item, errorMessage, 'error');
                return;
              }

              applyItemPricing(item, data.items[0]);
              setItemFeedback(item, 'Preise aktualisiert.', 'success');
            }).catch(function (error) {
              console.warn('Preisberechnung fehlgeschlagen', error);
              setItemFeedback(item, 'Preisberechnung nicht möglich.', 'error');
            }).finally(function () {
              target.removeAttribute('disabled');
            });
          });

          container.addEventListener('input', function (event) {
            var target = event.target;
            if (!target) {
              return;
            }

            if (target.matches('.reservation-item-price-total')) {
              updateGrandTotal();
            }
          });

          container.addEventListener('change', function (event) {
            var target = event.target;
            if (!target) {
              return;
            }

            if (target.matches('.reservation-item-rate') || target.matches('.reservation-item-arrival') || target.matches('.reservation-item-departure') || target.matches('input[name$="[room_quantity]"]')) {
              var item = target.closest('.reservation-category-item');
              if (item) {
                setItemFeedback(item, 'Berechnet die Preise für diese Zeile basierend auf Rate und Zeitraum.', 'muted');
              }
            }

            if (target.matches('.reservation-item-price-total')) {
              updateGrandTotal();
            }
          });

          document.addEventListener('reservation:categories-changed', function () {
            updateGrandTotal();
          });

          updateGrandTotal();
        }
        function setupReservationDetailModal() {
          var modalElement = document.getElementById('reservationDetailModal');
          var modalLibrary = window.bootstrap || (typeof bootstrap !== 'undefined' ? bootstrap : null);

          if (!modalElement || !modalLibrary || !modalLibrary.Modal) {
            return;
          }

          function formatMoney(value) {
            if (typeof value !== 'number' || Number.isNaN(value)) {
              return '';
            }

            return value.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
          }

          function formatPercent(value) {
            if (typeof value !== 'number' || Number.isNaN(value)) {
              return '';
            }

            return value.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %';
          }

          var defaultRedirectDate = <?= json_encode($calendarCurrentDateValue) ?>;
          var defaultRedirectDisplay = <?= json_encode($calendarOccupancyDisplay) ?>;
          var modal = modalLibrary.Modal.getOrCreateInstance(modalElement);
          var statusForm = modalElement.querySelector('#reservation-status-form');
          var statusInput = modalElement.querySelector('#reservation-status-value');
          var reservationIdInput = modalElement.querySelector('#reservation-status-id');
          var redirectDateInput = modalElement.querySelector('#reservation-status-date');
          var redirectDisplayInput = modalElement.querySelector('#reservation-status-display');
          var reservationIdState = '';
          var statusValueState = '';

          function setReservationId(value) {
            reservationIdState = value ? String(value) : '';
            if (reservationIdInput) {
              reservationIdInput.value = reservationIdState;
            }
          }

          function getReservationId() {
            return reservationIdInput ? reservationIdInput.value : reservationIdState;
          }

          function setStatusValue(value) {
            statusValueState = value ? String(value) : '';
            if (statusInput) {
              statusInput.value = statusValueState;
            }
          }

          function getStatusValue() {
            return statusInput ? statusInput.value : statusValueState;
          }

          var detailElements = {
            title: modalElement.querySelector('[data-detail="title"]'),
            subtitle: modalElement.querySelector('[data-detail="subtitle"]'),
            statusBadge: modalElement.querySelector('[data-detail="status"]'),
            number: modalElement.querySelector('[data-detail="number"]'),
            guest: modalElement.querySelector('[data-detail="guest"]'),
            company: modalElement.querySelector('[data-detail="company"]'),
            stay: modalElement.querySelector('[data-detail="stay"]'),
            room: modalElement.querySelector('[data-detail="room"]'),
            quantity: modalElement.querySelector('[data-detail="quantity"]'),
            occupancy: modalElement.querySelector('[data-detail="occupancy"]'),
            primaryGuest: modalElement.querySelector('[data-detail="primary-guest"]'),
            rate: modalElement.querySelector('[data-detail="rate"]'),
            pricePerNight: modalElement.querySelector('[data-detail="price-per-night"]'),
            totalPrice: modalElement.querySelector('[data-detail="total-price"]'),
            articlesLabel: modalElement.querySelector('[data-detail="articles-label"]'),
            articles: modalElement.querySelector('[data-detail="articles"]'),
            articlesTotalLabel: modalElement.querySelector('[data-detail="articles-total-label"]'),
            articlesTotal: modalElement.querySelector('[data-detail="articles-total"]'),
            nights: modalElement.querySelector('[data-detail="nights"]'),
            vat: modalElement.querySelector('[data-detail="vat"]'),
            notesWrapper: modalElement.querySelector('[data-detail="notes-wrapper"]'),
            notes: modalElement.querySelector('[data-detail="notes"]'),
            created: modalElement.querySelector('[data-detail="created"]'),
            updated: modalElement.querySelector('[data-detail="updated"]'),
            editLink: modalElement.querySelector('[data-detail="edit-link"]'),
            overbooking: modalElement.querySelector('[data-detail="overbooking"]'),
            statusButtonsContainer: modalElement.querySelector('[data-detail="status-buttons"]')
          };

          var statusButtons = detailElements.statusButtonsContainer
            ? detailElements.statusButtonsContainer.querySelectorAll('[data-status-action]')
            : [];

          function forEachStatusButton(callback) {
            Array.prototype.forEach.call(statusButtons, callback);
          }

          function resetModal() {
            setReservationId('');
            setStatusValue('');
            if (redirectDateInput) {
              redirectDateInput.value = defaultRedirectDate;
            }
            if (redirectDisplayInput) {
              redirectDisplayInput.value = defaultRedirectDisplay;
            }

            if (detailElements.statusBadge) {
              detailElements.statusBadge.className = 'badge d-none';
              detailElements.statusBadge.textContent = '';
              detailElements.statusBadge.style.removeProperty('background-color');
              detailElements.statusBadge.style.removeProperty('color');
            }
            if (detailElements.subtitle) {
              detailElements.subtitle.textContent = '';
            }
            if (detailElements.title) {
              detailElements.title.textContent = 'Reservierung';
            }
            if (detailElements.number) {
              detailElements.number.textContent = '';
            }
            if (detailElements.guest) {
              detailElements.guest.textContent = '';
            }
            if (detailElements.company) {
              detailElements.company.textContent = '';
            }
            if (detailElements.stay) {
              detailElements.stay.textContent = '';
            }
            if (detailElements.room) {
              detailElements.room.textContent = '';
            }
            if (detailElements.quantity) {
              detailElements.quantity.textContent = '';
            }
            if (detailElements.occupancy) {
              detailElements.occupancy.textContent = '';
            }
            if (detailElements.primaryGuest) {
              detailElements.primaryGuest.textContent = '';
            }
            if (detailElements.rate) {
              detailElements.rate.textContent = '';
            }
            if (detailElements.pricePerNight) {
              detailElements.pricePerNight.textContent = '';
            }
            if (detailElements.totalPrice) {
              detailElements.totalPrice.textContent = '';
            }
            if (detailElements.articlesLabel) {
              detailElements.articlesLabel.classList.add('d-none');
            }
            if (detailElements.articles) {
              detailElements.articles.classList.add('d-none');
              detailElements.articles.innerHTML = '';
            }
            if (detailElements.articlesTotalLabel) {
              detailElements.articlesTotalLabel.classList.add('d-none');
            }
            if (detailElements.articlesTotal) {
              detailElements.articlesTotal.classList.add('d-none');
              detailElements.articlesTotal.textContent = '';
            }
            if (detailElements.nights) {
              detailElements.nights.textContent = '';
            }
            if (detailElements.vat) {
              detailElements.vat.textContent = '';
            }
            if (detailElements.notesWrapper) {
              detailElements.notesWrapper.classList.add('d-none');
              if (detailElements.notes) {
                detailElements.notes.textContent = '';
              }
            }
            if (detailElements.created) {
              detailElements.created.textContent = '';
            }
            if (detailElements.updated) {
              detailElements.updated.textContent = '';
            }
            if (detailElements.editLink) {
              detailElements.editLink.classList.add('d-none');
              detailElements.editLink.removeAttribute('href');
            }
            if (detailElements.overbooking) {
              detailElements.overbooking.classList.add('d-none');
            }

            forEachStatusButton(function (button) {
              button.classList.remove('active', 'btn-primary');
              button.classList.add('btn-outline-secondary');
              button.disabled = true;
            });
          }

          function applyStatusButtonState(activeStatus) {
            forEachStatusButton(function (button) {
              var buttonStatus = button.getAttribute('data-status-action');
              if (!buttonStatus) {
                return;
              }

              var currentReservationId = getReservationId();
              button.disabled = !currentReservationId;

              if (currentReservationId && buttonStatus === activeStatus) {
                button.classList.add('active', 'btn-primary');
                button.classList.remove('btn-outline-secondary');
              } else {
                button.classList.remove('active', 'btn-primary');
                button.classList.add('btn-outline-secondary');
              }
            });
          }

          function formatStay(data) {
            var arrival = data && data.arrivalDateFormatted ? data.arrivalDateFormatted : '';
            var departure = data && data.departureDateFormatted ? data.departureDateFormatted : '';

            if (arrival && departure) {
              return arrival + ' – ' + departure;
            }

            if (arrival) {
              return 'ab ' + arrival;
            }

            if (departure) {
              return 'bis ' + departure;
            }

            return 'Nicht definiert';
          }

          function openModal(data) {
            resetModal();

            if (!data || typeof data !== 'object') {
              return;
            }

            if (data.reservationId) {
              setReservationId(data.reservationId);
            }

            if (redirectDateInput && data.calendarDate) {
              redirectDateInput.value = data.calendarDate;
            }

            if (redirectDisplayInput && data.displayPreference) {
              redirectDisplayInput.value = data.displayPreference;
            }

            setStatusValue(data.status || '');

            if (detailElements.title) {
              detailElements.title.textContent = data.guestLabel || data.guestName || 'Reservierung';
            }

            if (detailElements.subtitle) {
              var subtitleParts = [];
              if (data.reservationNumber) {
                subtitleParts.push(data.reservationNumber);
              }
              if (data.roomName) {
                subtitleParts.push(data.roomName);
              }
              if (data.statusLabel) {
                subtitleParts.push(data.statusLabel);
              }
              detailElements.subtitle.textContent = subtitleParts.join(' • ');
            }

            if (detailElements.number) {
              detailElements.number.textContent = data.reservationNumber || '–';
            }

            if (detailElements.statusBadge && data.statusLabel) {
              var badgeClass = 'badge ' + (data.statusBadgeClass || 'text-bg-secondary');
              detailElements.statusBadge.className = badgeClass;
              detailElements.statusBadge.textContent = data.statusLabel;
              if (data.statusColor) {
                detailElements.statusBadge.style.backgroundColor = data.statusColor;
                detailElements.statusBadge.style.color = data.statusTextColor || '#ffffff';
              } else {
                detailElements.statusBadge.style.removeProperty('background-color');
                detailElements.statusBadge.style.removeProperty('color');
              }
            }

            if (detailElements.guest) {
              detailElements.guest.textContent = data.guestName || data.guestLabel || '–';
            }

            if (detailElements.company) {
              detailElements.company.textContent = data.companyName || '–';
            }

            if (detailElements.stay) {
              detailElements.stay.textContent = formatStay(data);
            }

            if (detailElements.room) {
              var roomInfo = [];
              if (data.categoryName) {
                roomInfo.push(data.categoryName);
              }
              if (data.roomName && (!data.categoryName || data.roomName.indexOf(data.categoryName) === -1)) {
                roomInfo.push(data.roomName);
              }
              detailElements.room.textContent = roomInfo.join(' • ') || data.roomName || '–';
            }

            if (detailElements.quantity) {
              detailElements.quantity.textContent = data.roomQuantity ? String(data.roomQuantity) : '–';
            }

            if (detailElements.occupancy) {
              var occupancyLabel = '';
              if (typeof data.occupancy === 'number' && !Number.isNaN(data.occupancy)) {
                occupancyLabel = String(data.occupancy);
              } else if (typeof data.guestCount === 'number' && !Number.isNaN(data.guestCount)) {
                occupancyLabel = String(data.guestCount);
              }
              detailElements.occupancy.textContent = occupancyLabel !== '' ? occupancyLabel : '–';
            }

            if (detailElements.primaryGuest) {
              detailElements.primaryGuest.textContent = data.primaryGuestName || '–';
            }

            if (detailElements.rate) {
              detailElements.rate.textContent = data.rateName || '–';
            }

            if (detailElements.pricePerNight) {
              var priceNightLabel = '';
              if (typeof data.pricePerNightFormatted === 'string' && data.pricePerNightFormatted !== '') {
                priceNightLabel = data.pricePerNightFormatted;
              } else if (typeof data.pricePerNight === 'number') {
                priceNightLabel = formatMoney(data.pricePerNight);
              }
              detailElements.pricePerNight.textContent = priceNightLabel || '–';
            }

            if (detailElements.totalPrice) {
              var totalPriceLabel = '';
              if (typeof data.totalPriceFormatted === 'string' && data.totalPriceFormatted !== '') {
                totalPriceLabel = data.totalPriceFormatted;
              } else if (typeof data.totalPrice === 'number') {
                totalPriceLabel = formatMoney(data.totalPrice);
              }
              detailElements.totalPrice.textContent = totalPriceLabel || '–';
            }

            if (detailElements.articles) {
              detailElements.articles.innerHTML = '';
              detailElements.articles.classList.add('d-none');
            }
            if (detailElements.articlesLabel) {
              detailElements.articlesLabel.classList.add('d-none');
            }
            if (Array.isArray(data.articles) && data.articles.length && detailElements.articles) {
              var articleList = document.createElement('ul');
              articleList.className = 'list-unstyled mb-0';

              data.articles.forEach(function (article) {
                if (!article) {
                  return;
                }

                var name = article.name || 'Artikel';
                var metaParts = [];

                if (article.quantity != null && article.quantity !== '') {
                  metaParts.push('Menge: ' + article.quantity);
                }
                if (article.pricing_label) {
                  metaParts.push(article.pricing_label);
                }
                var articleTotalDisplay = '';
                if (typeof article.total_price_formatted === 'string' && article.total_price_formatted !== '') {
                  articleTotalDisplay = article.total_price_formatted;
                } else if (typeof article.total_price === 'number') {
                  articleTotalDisplay = formatMoney(article.total_price);
                }
                if (articleTotalDisplay) {
                  metaParts.push(articleTotalDisplay);
                }

                var item = document.createElement('li');
                item.textContent = metaParts.length ? name + ' (' + metaParts.join(' • ') + ')' : name;
                articleList.appendChild(item);
              });

              if (articleList.childElementCount) {
                detailElements.articles.innerHTML = '';
                detailElements.articles.appendChild(articleList);
                detailElements.articles.classList.remove('d-none');
                if (detailElements.articlesLabel) {
                  detailElements.articlesLabel.classList.remove('d-none');
                }
              }
            }

            if (detailElements.articlesTotal) {
              var articleTotalLabel = '';
              if (typeof data.articlesTotalFormatted === 'string' && data.articlesTotalFormatted !== '') {
                articleTotalLabel = data.articlesTotalFormatted;
              } else if (typeof data.articlesTotal === 'number') {
                articleTotalLabel = formatMoney(data.articlesTotal);
              }

              if (articleTotalLabel) {
                detailElements.articlesTotal.textContent = articleTotalLabel;
                detailElements.articlesTotal.classList.remove('d-none');
                if (detailElements.articlesTotalLabel) {
                  detailElements.articlesTotalLabel.classList.remove('d-none');
                }
              } else {
                detailElements.articlesTotal.textContent = '';
                detailElements.articlesTotal.classList.add('d-none');
                if (detailElements.articlesTotalLabel) {
                  detailElements.articlesTotalLabel.classList.add('d-none');
                }
              }
            } else if (detailElements.articlesTotalLabel) {
              detailElements.articlesTotalLabel.classList.add('d-none');
            }

            if (detailElements.nights) {
              var nightsLabel = '';
              if (typeof data.nightCountLabel === 'string' && data.nightCountLabel !== '') {
                nightsLabel = data.nightCountLabel;
              } else if (typeof data.nightCount === 'number') {
                nightsLabel = data.nightCount + ' ' + (data.nightCount === 1 ? 'Nacht' : 'Nächte');
              }
              detailElements.nights.textContent = nightsLabel || '–';
            }

            if (detailElements.vat) {
              var vatLabel = '';
              if (typeof data.vatRateFormatted === 'string' && data.vatRateFormatted !== '') {
                vatLabel = data.vatRateFormatted;
              } else if (typeof data.vatRate === 'number') {
                vatLabel = formatPercent(data.vatRate);
              }
              detailElements.vat.textContent = vatLabel || '–';
            }

            if (detailElements.notesWrapper) {
              var notes = data.notes || '';
              if (notes !== '') {
                detailElements.notesWrapper.classList.remove('d-none');
                if (detailElements.notes) {
                  detailElements.notes.textContent = notes;
                }
              }
            }

            if (detailElements.created) {
              var createdParts = [];
              if (data.createdAtFormatted) {
                createdParts.push('Erstellt: ' + data.createdAtFormatted);
              }
              if (data.createdByName) {
                createdParts.push('von ' + data.createdByName);
              }
              detailElements.created.textContent = createdParts.join(' ');
            }

            if (detailElements.updated) {
              var updatedParts = [];
              if (data.updatedAtFormatted) {
                updatedParts.push('Aktualisiert: ' + data.updatedAtFormatted);
              }
              if (data.updatedByName) {
                updatedParts.push('von ' + data.updatedByName);
              }
              detailElements.updated.textContent = updatedParts.join(' ');
            }

            if (detailElements.editLink) {
              if (data.editUrl) {
                detailElements.editLink.href = data.editUrl;
                detailElements.editLink.classList.remove('d-none');
              } else {
                detailElements.editLink.classList.add('d-none');
                detailElements.editLink.removeAttribute('href');
              }
            }

            if (detailElements.overbooking) {
              if (data.type === 'overbooking') {
                detailElements.overbooking.classList.remove('d-none');
              } else {
                detailElements.overbooking.classList.add('d-none');
              }
            }

            applyStatusButtonState(data.status || '');

            modal.show();
          }

          document.querySelectorAll('.occupancy-entry-action').forEach(function (element) {
            element.addEventListener('click', function (event) {
              event.preventDefault();
              var payload = element.getAttribute('data-reservation');
              if (!payload) {
                return;
              }

              try {
                openModal(JSON.parse(payload));
              } catch (error) {
                console.warn('Konnte Reservierungsdetails nicht laden', error);
              }
            });

            element.addEventListener('keydown', function (event) {
              if (event.key !== 'Enter' && event.key !== ' ') {
                return;
              }

              event.preventDefault();
              var payload = element.getAttribute('data-reservation');
              if (!payload) {
                return;
              }

              try {
                openModal(JSON.parse(payload));
              } catch (error) {
                console.warn('Konnte Reservierungsdetails nicht laden', error);
              }
            });
          });

          forEachStatusButton(function (button) {
            button.addEventListener('click', function (event) {
              event.preventDefault();

              if (!getReservationId()) {
                return;
              }

              var status = button.getAttribute('data-status-action');
              if (!status) {
                return;
              }

              setStatusValue(status);
              if (statusForm) {
                statusForm.submit();
              }
            });
          });

          modalElement.addEventListener('show.bs.modal', function () {
            applyStatusButtonState(getReservationId() ? getStatusValue() : '');
          });

          modalElement.addEventListener('hidden.bs.modal', function () {
            resetModal();
          });

          resetModal();
        }

        function collapseNavbarOnClick(selector) {
          document.querySelectorAll(selector).forEach(function (link) {
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
        }

        function debounce(fn, delay) {
          var timeoutId;
          return function () {
            var args = arguments;
            var context = this;
            clearTimeout(timeoutId);
            timeoutId = setTimeout(function () {
              fn.apply(context, args);
            }, delay);
          };
        }

        function setupTypeahead(container, options) {
          if (!container) {
            return null;
          }

          options = options || {};

          var input = container.querySelector('.typeahead-input');
          var hiddenInput = container.querySelector('input[type="hidden"]');
          var dropdown = container.querySelector('.typeahead-dropdown');
          var endpoint = container.getAttribute('data-endpoint');

          if (!input || !hiddenInput || !dropdown || !endpoint) {
            return null;
          }

          var minLength = parseInt(input.getAttribute('data-minlength') || '2', 10);
          if (Number.isNaN(minLength) || minLength < 1) {
            minLength = 1;
          }

          var limit = parseInt(container.getAttribute('data-limit') || '20', 10);
          if (Number.isNaN(limit) || limit < 1) {
            limit = 20;
          }

          var currentItems = [];
          var activeIndex = -1;
          var abortController = null;

          function setActive(index) {
            activeIndex = index;
            var nodes = dropdown.querySelectorAll('.typeahead-item');
            nodes.forEach(function (node, nodeIndex) {
              if (nodeIndex === activeIndex) {
                node.classList.add('active');
                node.setAttribute('aria-selected', 'true');
              } else {
                node.classList.remove('active');
                node.setAttribute('aria-selected', 'false');
              }
            });
          }

          function hideDropdown() {
            dropdown.classList.remove('show');
            dropdown.innerHTML = '';
            currentItems = [];
            setActive(-1);
          }

          function renderItems(items) {
            dropdown.innerHTML = '';
            if (!items.length) {
              var empty = document.createElement('div');
              empty.className = 'typeahead-empty list-group-item text-muted small';
              empty.textContent = 'Keine Treffer';
              dropdown.appendChild(empty);
              dropdown.classList.add('show');
              return;
            }

            items.forEach(function (item, index) {
              var button = document.createElement('button');
              button.type = 'button';
              button.className = 'list-group-item list-group-item-action typeahead-item';
              button.setAttribute('data-index', String(index));
              button.setAttribute('role', 'option');
              button.setAttribute('aria-selected', 'false');

              var label = document.createElement('div');
              label.className = 'fw-semibold';
              label.textContent = item.label || '';
              button.appendChild(label);

              if (item.address) {
                var details = document.createElement('div');
                details.className = 'small text-muted';
                details.textContent = item.address;
                button.appendChild(details);
                button.title = item.address;
              }

              button.addEventListener('mouseenter', function () {
                setActive(index);
              });

              button.addEventListener('click', function () {
                selectItem(index);
              });

              dropdown.appendChild(button);
            });

            dropdown.classList.add('show');
            setActive(-1);
          }

          function selectItem(index) {
            var item = currentItems[index];
            if (!item) {
              return;
            }

            hiddenInput.value = item.id != null ? String(item.id) : '';
            input.value = item.label || '';

            if (item.address) {
              input.title = item.address;
            } else {
              input.removeAttribute('title');
            }

            if (typeof options.onSelect === 'function') {
              options.onSelect(item);
            }

            hideDropdown();
          }

          function clearSelection(triggerCallback) {
            hiddenInput.value = '';
            input.removeAttribute('title');

            if (triggerCallback && typeof options.onClear === 'function') {
              options.onClear();
            }
          }

          var fetchResults = debounce(function (query) {
            if (!endpoint || input.disabled) {
              return;
            }

            if (abortController && typeof abortController.abort === 'function') {
              abortController.abort();
            }

            if (window.AbortController) {
              abortController = new AbortController();
            } else {
              abortController = null;
            }

            var url = endpoint + '&term=' + encodeURIComponent(query) + '&limit=' + encodeURIComponent(String(limit));

            fetch(url, { signal: abortController ? abortController.signal : undefined })
              .then(function (response) {
                if (!response.ok) {
                  throw new Error('Netzwerkfehler: ' + response.status);
                }
                return response.json();
              })
              .then(function (data) {
                var items = Array.isArray(data.items) ? data.items : [];
                currentItems = items;
                renderItems(items);
              })
              .catch(function (error) {
                if (error.name === 'AbortError') {
                  return;
                }
                console.warn('Typeahead request failed', error);
                currentItems = [];
                renderItems([]);
              });
          }, 200);

          input.addEventListener('input', function () {
            clearSelection(false);
            if (input.value.trim().length < minLength) {
              hideDropdown();
              if (input.value.trim().length === 0) {
                clearSelection(true);
              }
              return;
            }

            fetchResults(input.value.trim());
          });

          input.addEventListener('focus', function () {
            if (input.value.trim().length >= minLength) {
              fetchResults(input.value.trim());
            }
          });

          input.addEventListener('keydown', function (event) {
            if (!dropdown.classList.contains('show')) {
              return;
            }

            if (event.key === 'ArrowDown') {
              event.preventDefault();
              if (!currentItems.length) {
                return;
              }
              var nextIndex = activeIndex + 1;
              if (nextIndex >= currentItems.length) {
                nextIndex = 0;
              }
              setActive(nextIndex);
            } else if (event.key === 'ArrowUp') {
              event.preventDefault();
              if (!currentItems.length) {
                return;
              }
              var prevIndex = activeIndex - 1;
              if (prevIndex < 0) {
                prevIndex = currentItems.length - 1;
              }
              setActive(prevIndex);
            } else if (event.key === 'Enter') {
              if (activeIndex >= 0 && currentItems[activeIndex]) {
                event.preventDefault();
                selectItem(activeIndex);
              }
            } else if (event.key === 'Escape') {
              hideDropdown();
            }
          });

          input.addEventListener('blur', function () {
            setTimeout(hideDropdown, 150);
          });

          dropdown.addEventListener('mousedown', function (event) {
            event.preventDefault();
          });

          document.addEventListener('click', function (event) {
            if (!container.contains(event.target)) {
              hideDropdown();
            }
          });

          return {
            setValue: function (label, id, address) {
              hiddenInput.value = id != null && id !== '' ? String(id) : '';
              input.value = label || '';
              if (address) {
                input.title = address;
              } else {
                input.removeAttribute('title');
              }
            },
            clear: function () {
              clearSelection(true);
              input.value = '';
            }
          };
        }

        collapseNavbarOnClick('#primaryNav .nav-link');
        collapseNavbarOnClick('.quick-action-menu a');

        var companyContainer = document.querySelector('[data-typeahead="company"]');
        var companyTypeahead = setupTypeahead(companyContainer, {});

        var guestContainer = document.querySelector('[data-typeahead="guest"]');
        setupTypeahead(guestContainer, {
          onSelect: function (item) {
            if (item && item.company && companyTypeahead) {
              companyTypeahead.setValue(item.company.label || '', item.company.id || '', item.company.address || '');
            }

            cleanupItemGuestTypeaheads();
            reservationItemGuestTypeaheads.forEach(function (entry) {
              if (!entry || !entry.container || !entry.instance) {
                return;
              }

              var hidden = entry.container.querySelector('input[type="hidden"]');
              var input = entry.container.querySelector('.typeahead-input');
              if (hidden && (!hidden.value || hidden.value === '' || hidden.value === '0')) {
                entry.instance.setValue(item.label || '', item.id || '', item.address || '');
              } else if (hidden && item && String(item.id || '') === hidden.value && input && (!input.value || input.value === '')) {
                entry.instance.setValue(item.label || '', item.id || '', item.address || '');
              }
            });
          },
          onClear: function () {
            if (!companyContainer || !companyTypeahead) {
              return;
            }

            var hidden = companyContainer.querySelector('input[type="hidden"]');
            var input = companyContainer.querySelector('.typeahead-input');
            if (hidden && hidden.value === '' && input) {
              input.value = '';
              input.removeAttribute('title');
            }
          }
        });

        setupReservationFormModal();
        setupReservationCategoryRepeater();
        setupReservationPricing();
        setupStatusColorPickers();
        setupReservationDetailModal();
      })();
    </script>
  </body>
</html>
