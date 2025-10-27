<?php

namespace ModPMS;

use DateTimeImmutable;
use PDO;
use RuntimeException;

class ReportManager
{
    private const EXCLUDED_STATUSES = ['storniert', 'noshow'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBreakfastList(DateTimeImmutable $date): array
    {
        $rows = $this->fetchItemsForPeriod($date, $date->modify('+1 day'));

        $entries = [];
        foreach ($rows as $row) {
            $arrival = $this->createDate($row['item_arrival'] ?? $row['reservation_arrival'] ?? null);
            $departure = $this->createDate($row['item_departure'] ?? $row['reservation_departure'] ?? null);

            if ($arrival !== null && $arrival > $date) {
                continue;
            }

            if ($departure !== null && $departure <= $date) {
                continue;
            }

            $guestName = $this->buildGuestName($row);
            $roomLabel = $this->buildRoomLabel($row);
            $companyName = $this->normalizeCompanyName($row['company_name'] ?? null);
            $occupancy = $this->normalizeOccupancy($row);
            $reservationNumber = isset($row['reservation_number']) ? (string) $row['reservation_number'] : '';
            $status = isset($row['reservation_status']) ? (string) $row['reservation_status'] : '';

            $hasBreakfast = false;
            if (isset($row['article_names']) && $row['article_names'] !== null) {
                $articleNames = explode('||', (string) $row['article_names']);
                foreach ($articleNames as $articleName) {
                    $normalized = $this->normalizeForMatch($articleName);
                    if ($normalized === '') {
                        continue;
                    }

                    if (str_contains($normalized, 'frühstück') || str_contains($normalized, 'fruehstueck') || str_contains($normalized, 'breakfast')) {
                        $hasBreakfast = true;
                        break;
                    }
                }
            }

            $entries[] = [
                'room_label' => $roomLabel,
                'guest_name' => $guestName,
                'company_name' => $companyName,
                'arrival' => $arrival,
                'departure' => $departure,
                'occupancy' => $occupancy,
                'reservation_number' => $reservationNumber,
                'status' => $status,
                'has_breakfast' => $hasBreakfast,
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCleaningList(DateTimeImmutable $date): array
    {
        $rows = $this->fetchItemsForPeriod($date, $date->modify('+1 day'));

        $entries = [];
        $dateKey = $date->format('Y-m-d');

        foreach ($rows as $row) {
            $arrival = $this->createDate($row['item_arrival'] ?? $row['reservation_arrival'] ?? null);
            $departure = $this->createDate($row['item_departure'] ?? $row['reservation_departure'] ?? null);

            if ($arrival !== null && $arrival > $date) {
                continue;
            }

            $state = 'Aufenthalt';
            if ($departure !== null && $departure->format('Y-m-d') === $dateKey) {
                $state = 'Abreise';
            } elseif ($arrival !== null && $arrival->format('Y-m-d') === $dateKey) {
                $state = 'Anreise';
            } elseif ($arrival !== null && $arrival < $date && ($departure === null || $departure > $date)) {
                $state = 'Bleiber';
            }

            if ($state === 'Aufenthalt' && $departure !== null && $departure <= $date) {
                continue;
            }

            $guestName = $this->buildGuestName($row);
            $roomLabel = $this->buildRoomLabel($row);
            $companyName = $this->normalizeCompanyName($row['company_name'] ?? null);
            $occupancy = $this->normalizeOccupancy($row);
            $reservationNumber = isset($row['reservation_number']) ? (string) $row['reservation_number'] : '';

            $entries[] = [
                'room_label' => $roomLabel,
                'guest_name' => $guestName,
                'company_name' => $companyName,
                'arrival' => $arrival,
                'departure' => $departure,
                'occupancy' => $occupancy,
                'reservation_number' => $reservationNumber,
                'state' => $state,
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonthlyReport(DateTimeImmutable $periodStart): array
    {
        $periodEnd = $periodStart->modify('first day of next month');
        $rows = $this->fetchItemsForPeriod($periodStart, $periodEnd);

        $entries = [];
        $arrivals = 0;
        $departures = 0;
        $totalRoomNights = 0;
        $totalOvernights = 0;
        $entryCount = 0;
        $statusBreakdown = [];

        foreach ($rows as $row) {
            $arrival = $this->createDate($row['item_arrival'] ?? $row['reservation_arrival'] ?? null);
            $departure = $this->createDate($row['item_departure'] ?? $row['reservation_departure'] ?? null);

            if ($arrival === null || $departure === null) {
                continue;
            }

            if ($arrival >= $departure) {
                continue;
            }

            if ($arrival >= $periodEnd || $departure <= $periodStart) {
                continue;
            }

            $guestName = $this->buildGuestName($row);
            $roomLabel = $this->buildRoomLabel($row);
            $companyName = $this->normalizeCompanyName($row['company_name'] ?? null);
            $status = isset($row['reservation_status']) ? (string) $row['reservation_status'] : '';
            $reservationNumber = isset($row['reservation_number']) ? (string) $row['reservation_number'] : '';
            $occupancy = $this->normalizeOccupancy($row);

            if ($arrival >= $periodStart && $arrival < $periodEnd) {
                $arrivals++;
            }
            if ($departure > $periodStart && $departure <= $periodEnd) {
                $departures++;
            }

            $periodEffectiveStart = $arrival < $periodStart ? $periodStart : $arrival;
            $periodEffectiveEnd = $departure > $periodEnd ? $periodEnd : $departure;
            $periodNightCount = $this->calculateNightCount($periodEffectiveStart, $periodEffectiveEnd);
            $fullNightCount = $this->calculateNightCount($arrival, $departure);

            $totalRoomNights += $periodNightCount;
            $totalOvernights += $periodNightCount * $occupancy;

            if ($periodNightCount > 0) {
                $entryCount++;
            }

            if (!isset($statusBreakdown[$status])) {
                $statusBreakdown[$status] = ['status' => $status, 'count' => 0];
            }
            if ($periodNightCount > 0) {
                $statusBreakdown[$status]['count']++;
            }

            $articleTotal = isset($row['article_total_price']) ? (float) $row['article_total_price'] : 0.0;
            $roomTotal = isset($row['item_total_price']) ? (float) $row['item_total_price'] : 0.0;

            $allocationRatio = $fullNightCount > 0 ? min(1.0, $periodNightCount / $fullNightCount) : 1.0;
            $roomRevenue = $roomTotal * $allocationRatio;
            $articleRevenue = $articleTotal * $allocationRatio;

            $entries[] = [
                'reservation_number' => $reservationNumber,
                'guest_name' => $guestName,
                'company_name' => $companyName,
                'room_label' => $roomLabel,
                'arrival' => $arrival,
                'departure' => $departure,
                'nights_in_period' => $periodNightCount,
                'occupancy' => $occupancy,
                'status' => $status,
                'room_revenue' => $roomRevenue,
                'article_revenue' => $articleRevenue,
                'total_revenue' => $roomRevenue + $articleRevenue,
            ];
        }

        ksort($statusBreakdown);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'reservation_count' => $entryCount,
            'total_room_nights' => $totalRoomNights,
            'total_overnights' => $totalOvernights,
            'average_stay' => $entryCount > 0 ? $totalRoomNights / $entryCount : 0.0,
            'entries' => $entries,
            'status_breakdown' => array_values($statusBreakdown),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMonthlyClosingReport(DateTimeImmutable $periodStart): array
    {
        $periodEnd = $periodStart->modify('first day of next month');
        $rows = $this->fetchItemsForPeriod($periodStart, $periodEnd);

        $entries = [];
        $totalRoomRevenue = 0.0;
        $totalArticleRevenue = 0.0;
        $totalOvernights = 0;
        $statusFinancials = [];

        foreach ($rows as $row) {
            $arrival = $this->createDate($row['item_arrival'] ?? $row['reservation_arrival'] ?? null);
            $departure = $this->createDate($row['item_departure'] ?? $row['reservation_departure'] ?? null);

            if ($arrival === null || $departure === null) {
                continue;
            }

            if ($arrival >= $departure) {
                continue;
            }

            if ($arrival >= $periodEnd || $departure <= $periodStart) {
                continue;
            }

            $periodEffectiveStart = $arrival < $periodStart ? $periodStart : $arrival;
            $periodEffectiveEnd = $departure > $periodEnd ? $periodEnd : $departure;
            $periodNightCount = $this->calculateNightCount($periodEffectiveStart, $periodEffectiveEnd);
            $fullNightCount = $this->calculateNightCount($arrival, $departure);
            $occupancy = $this->normalizeOccupancy($row);

            $articleTotal = isset($row['article_total_price']) ? (float) $row['article_total_price'] : 0.0;
            $roomTotal = isset($row['item_total_price']) ? (float) $row['item_total_price'] : 0.0;
            $status = isset($row['reservation_status']) ? (string) $row['reservation_status'] : '';

            $allocationRatio = $fullNightCount > 0 ? min(1.0, $periodNightCount / $fullNightCount) : 1.0;
            $roomRevenue = $roomTotal * $allocationRatio;
            $articleRevenue = $articleTotal * $allocationRatio;
            $totalRevenue = $roomRevenue + $articleRevenue;

            $totalRoomRevenue += $roomRevenue;
            $totalArticleRevenue += $articleRevenue;
            $totalOvernights += $periodNightCount * $occupancy;

            if (!isset($statusFinancials[$status])) {
                $statusFinancials[$status] = [
                    'status' => $status,
                    'room_revenue' => 0.0,
                    'article_revenue' => 0.0,
                    'total_revenue' => 0.0,
                ];
            }

            $statusFinancials[$status]['room_revenue'] += $roomRevenue;
            $statusFinancials[$status]['article_revenue'] += $articleRevenue;
            $statusFinancials[$status]['total_revenue'] += $totalRevenue;

            $entries[] = [
                'reservation_number' => isset($row['reservation_number']) ? (string) $row['reservation_number'] : '',
                'guest_name' => $this->buildGuestName($row),
                'company_name' => $this->normalizeCompanyName($row['company_name'] ?? null),
                'room_label' => $this->buildRoomLabel($row),
                'arrival' => $arrival,
                'departure' => $departure,
                'nights_in_period' => $periodNightCount,
                'occupancy' => $occupancy,
                'status' => $status,
                'room_revenue' => $roomRevenue,
                'article_revenue' => $articleRevenue,
                'total_revenue' => $totalRevenue,
            ];
        }

        ksort($statusFinancials);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_room_revenue' => $totalRoomRevenue,
            'total_article_revenue' => $totalArticleRevenue,
            'total_revenue' => $totalRoomRevenue + $totalArticleRevenue,
            'total_overnights' => $totalOvernights,
            'entries' => $entries,
            'status_financials' => array_values($statusFinancials),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchItemsForPeriod(DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): array
    {
        $statusPlaceholders = implode(', ', array_fill(0, count(self::EXCLUDED_STATUSES), '?'));

        $sql = 'SELECT '
            . 'ri.id AS item_id, '
            . 'ri.reservation_id, '
            . 'r.reservation_number, '
            . 'r.status AS reservation_status, '
            . 'r.arrival_date AS reservation_arrival, '
            . 'r.departure_date AS reservation_departure, '
            . 'ri.arrival_date AS item_arrival, '
            . 'ri.departure_date AS item_departure, '
            . 'ri.room_quantity AS item_room_quantity, '
            . 'ri.occupancy AS item_occupancy, '
            . 'r.room_quantity AS reservation_room_quantity, '
            . 'ri.total_price AS item_total_price, '
            . 'rm.room_number, '
            . 'rc.name AS category_name, '
            . 'pg.first_name AS primary_guest_first_name, '
            . 'pg.last_name AS primary_guest_last_name, '
            . 'g.first_name AS reservation_guest_first_name, '
            . 'g.last_name AS reservation_guest_last_name, '
            . 'c.name AS company_name, '
            . 'SUM(COALESCE(ria.total_price, 0)) AS article_total_price, '
            . 'GROUP_CONCAT(DISTINCT ria.article_name ORDER BY ria.article_name SEPARATOR "||") AS article_names '
            . 'FROM reservation_items ri '
            . 'INNER JOIN reservations r ON r.id = ri.reservation_id '
            . 'LEFT JOIN rooms rm ON rm.id = ri.room_id '
            . 'LEFT JOIN room_categories rc ON rc.id = ri.category_id '
            . 'LEFT JOIN guests pg ON pg.id = ri.primary_guest_id '
            . 'LEFT JOIN guests g ON g.id = r.guest_id '
            . 'LEFT JOIN companies c ON c.id = r.company_id '
            . 'LEFT JOIN reservation_item_articles ria ON ria.reservation_item_id = ri.id '
            . 'WHERE r.archived_at IS NULL '
            . 'AND r.status NOT IN (' . $statusPlaceholders . ') '
            . 'AND (('
            . '    (COALESCE(ri.arrival_date, r.arrival_date) IS NULL OR COALESCE(ri.arrival_date, r.arrival_date) < ?)
'
            . '    AND (COALESCE(ri.departure_date, r.departure_date) IS NULL OR COALESCE(ri.departure_date, r.departure_date) > ?)
'
            . ') OR COALESCE(ri.departure_date, r.departure_date) = ?)
'
            . 'GROUP BY '
            . 'ri.id, ri.reservation_id, r.reservation_number, r.status, '
            . 'r.arrival_date, r.departure_date, '
            . 'ri.arrival_date, ri.departure_date, '
            . 'ri.room_quantity, ri.occupancy, r.room_quantity, '
            . 'ri.total_price, rm.room_number, rc.name, '
            . 'pg.first_name, pg.last_name, g.first_name, g.last_name, c.name '
            . 'ORDER BY '
            . 'rm.room_number IS NULL, rm.room_number, rc.name, r.reservation_number, ri.id';

        $params = array_merge(
            self::EXCLUDED_STATUSES,
            [
                $periodEnd->format('Y-m-d'),
                $periodStart->format('Y-m-d'),
                $periodStart->format('Y-m-d'),
            ]
        );

        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Berichte konnten nicht vorbereitet werden.');
        }

        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }

    private function buildGuestName(array $row): string
    {
        $primaryFirst = $this->normalizeString($row['primary_guest_first_name'] ?? '');
        $primaryLast = $this->normalizeString($row['primary_guest_last_name'] ?? '');
        $reservationFirst = $this->normalizeString($row['reservation_guest_first_name'] ?? '');
        $reservationLast = $this->normalizeString($row['reservation_guest_last_name'] ?? '');

        if ($primaryFirst !== '' || $primaryLast !== '') {
            return trim($primaryFirst . ' ' . $primaryLast);
        }

        if ($reservationFirst !== '' || $reservationLast !== '') {
            return trim($reservationFirst . ' ' . $reservationLast);
        }

        $companyName = $this->normalizeString($row['company_name'] ?? '');
        if ($companyName !== '') {
            return $companyName;
        }

        return 'Gast';
    }

    private function buildRoomLabel(array $row): string
    {
        $roomNumber = $this->normalizeString($row['room_number'] ?? '');
        if ($roomNumber !== '') {
            return 'Zimmer ' . $roomNumber;
        }

        $categoryName = $this->normalizeString($row['category_name'] ?? '');
        if ($categoryName !== '') {
            return $categoryName;
        }

        return 'Unzugewiesen';
    }

    private function normalizeCompanyName(?string $value): string
    {
        $normalized = $this->normalizeString($value ?? '');

        return $normalized !== '' ? $normalized : 'Privatgast';
    }

    private function normalizeOccupancy(array $row): int
    {
        $occupancy = isset($row['item_occupancy']) ? (int) $row['item_occupancy'] : 0;
        if ($occupancy > 0) {
            return $occupancy;
        }

        $roomQuantity = isset($row['item_room_quantity']) ? (int) $row['item_room_quantity'] : 0;
        if ($roomQuantity > 0) {
            return $roomQuantity;
        }

        $reservationQuantity = isset($row['reservation_room_quantity']) ? (int) $row['reservation_room_quantity'] : 0;
        if ($reservationQuantity > 0) {
            return $reservationQuantity;
        }

        return 1;
    }

    private function calculateNightCount(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end <= $start) {
            return 0;
        }

        $diff = $start->diff($end);

        return $diff->invert === 1 ? 0 : max(0, (int) $diff->days);
    }

    private function createDate($value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date instanceof DateTimeImmutable) {
            return $date;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function normalizeString(?string $value): string
    {
        return trim((string) $value);
    }

    private function normalizeForMatch(?string $value): string
    {
        $normalized = $this->normalizeString($value);
        if (function_exists('mb_strtolower')) {
            $normalized = mb_strtolower($normalized, 'UTF-8');
        } else {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }
}
