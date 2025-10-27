<?php

namespace ModPMS;

use DateTimeImmutable;
class Calendar
{
    private const MONTH_NAMES = [
        1 => 'Januar',
        2 => 'Februar',
        3 => 'März',
        4 => 'April',
        5 => 'Mai',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'August',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Dezember',
    ];

    private const WEEKDAY_SHORT = [
        1 => 'Mo',
        2 => 'Di',
        3 => 'Mi',
        4 => 'Do',
        5 => 'Fr',
        6 => 'Sa',
        7 => 'So',
    ];

    private const INTL_DATE_NONE = -1;
    private const INTL_DATE_LONG = 1;
    private const INTL_CALENDAR_GREGORIAN = 1;

    private DateTimeImmutable $currentDate;

    public function __construct(?DateTimeImmutable $date = null)
    {
        $this->currentDate = ($date ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function currentDate(): DateTimeImmutable
    {
        return $this->currentDate;
    }

    public function monthLabel(): string
    {
        $formatter = $this->createIntlFormatter(
            'LLLL yyyy',
            self::INTL_DATE_LONG,
            self::INTL_DATE_NONE
        );

        if ($formatter instanceof \IntlDateFormatter) {
            try {
                $label = $formatter->format($this->currentDate);
            } catch (\Throwable $exception) {
                $label = false;
            }

            if ($label !== false) {
                return $label;
            }
        }

        $monthName = self::MONTH_NAMES[(int) $this->currentDate->format('n')] ?? $this->currentDate->format('F');

        return sprintf('%s %s', $monthName, $this->currentDate->format('Y'));
    }

    /**
     * @return array<int, array<int, array<string, int|string|bool>>>
     */
    public function weeks(): array
    {
        $start = $this->currentDate->modify('last monday');
        $weeks = [];

        for ($week = 0; $week < 6; $week++) {
            $weekDays = [];
            for ($day = 0; $day < 7; $day++) {
                $date = $start->modify(sprintf('+%d days', $week * 7 + $day));
                $weekDays[] = [
                    'day' => (int) $date->format('j'),
                    'isCurrentMonth' => $date->format('m') === $this->currentDate->format('m'),
                    'isToday' => $date->format('Y-m-d') === (new DateTimeImmutable())->format('Y-m-d'),
                    'fullDate' => $date->format('Y-m-d'),
                ];
            }
            $weeks[] = $weekDays;
        }

        return $weeks;
    }

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    public function daysOfMonth(): array
    {
        $days = [];
        $daysInMonth = (int) $this->currentDate->format('t');
        $weekdayFormatter = $this->createIntlFormatter('EE');

        for ($offset = 0; $offset < $daysInMonth; $offset++) {
            $date = $this->currentDate->modify(sprintf('+%d days', $offset));

            $days[] = [
                'day' => (int) $date->format('j'),
                'weekday' => $this->formatWeekday($date, $weekdayFormatter),
                'isToday' => $date->format('Y-m-d') === (new DateTimeImmutable())->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
            ];
        }

        return $days;
    }

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    public function daysAround(int $pastDays, int $futureDays): array
    {
        $days = [];
        $total = $pastDays + $futureDays + 1;
        $start = $this->currentDate->modify(sprintf('-%d days', $pastDays));

        $weekdayFormatter = $this->createIntlFormatter('EE');

        for ($offset = 0; $offset < $total; $offset++) {
            $date = $start->modify(sprintf('+%d days', $offset));

            $days[] = [
                'day' => (int) $date->format('j'),
                'weekday' => $this->formatWeekday($date, $weekdayFormatter),
                'isToday' => $date->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
            ];
        }

        return $days;
    }

    public function rangeLabel(int $pastDays, int $futureDays): string
    {
        $start = $this->currentDate->modify(sprintf('-%d days', $pastDays));
        $end = $this->currentDate->modify(sprintf('+%d days', $futureDays));

        $sameMonth = $start->format('Y-m') === $end->format('Y-m');
        $startPattern = $sameMonth ? 'd.' : 'd. MMMM';
        $endPattern = 'd. MMMM yyyy';

        $formatter = $this->createIntlFormatter($startPattern);
        if ($formatter instanceof \IntlDateFormatter) {
            try {
                $startLabel = $formatter->format($start);
            } catch (\Throwable $exception) {
                $startLabel = false;
            }

            if ($startLabel !== false) {
                try {
                    $formatter->setPattern($endPattern);
                    $endLabel = $formatter->format($end);
                } catch (\Throwable $exception) {
                    $endLabel = false;
                }

                if ($endLabel !== false) {
                    return sprintf('%s – %s', $startLabel, $endLabel);
                }
            }
        }

        $startLabel = $sameMonth
            ? sprintf('%d.', (int) $start->format('j'))
            : sprintf('%d. %s', (int) $start->format('j'), $this->fallbackMonthName($start));

        $endLabel = sprintf(
            '%d. %s %s',
            (int) $end->format('j'),
            $this->fallbackMonthName($end),
            $end->format('Y')
        );

        return sprintf('%s – %s', $startLabel, $endLabel);
    }

    public function viewLength(int $pastDays, int $futureDays): int
    {
        return $pastDays + $futureDays + 1;
    }

    private function formatWeekday(DateTimeImmutable $date, $formatter): string
    {
        if ($formatter instanceof \IntlDateFormatter) {
            try {
                $formatted = $formatter->format($date);
            } catch (\Throwable $exception) {
                $formatted = false;
            }

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $weekdayIndex = (int) $date->format('N');

        return self::WEEKDAY_SHORT[$weekdayIndex] ?? $date->format('D');
    }

    private function fallbackMonthName(DateTimeImmutable $date): string
    {
        return self::MONTH_NAMES[(int) $date->format('n')] ?? $date->format('F');
    }

    /**
     * @return \IntlDateFormatter|null
     */
    private function createIntlFormatter(
        string $pattern,
        int $dateType = self::INTL_DATE_NONE,
        int $timeType = self::INTL_DATE_NONE
    ) {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }

        try {
            $formatter = new \IntlDateFormatter(
                'de_DE',
                $dateType,
                $timeType,
                $this->currentDate->getTimezone()->getName(),
                self::INTL_CALENDAR_GREGORIAN,
                $pattern
            );
        } catch (\Throwable $exception) {
            return null;
        }

        if ($formatter === false) {
            return null;
        }

        return $formatter;
    }
}
