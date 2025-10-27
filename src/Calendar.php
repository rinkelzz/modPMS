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
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                'de_DE',
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                $this->currentDate->getTimezone()->getName(),
                \IntlDateFormatter::GREGORIAN,
                'LLLL yyyy'
            );

            if ($formatter !== false) {
                $label = $formatter->format($this->currentDate);
                if ($label !== false) {
                    return $label;
                }
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
        $weekdayFormatter = null;
        if (class_exists(\IntlDateFormatter::class)) {
            $weekdayFormatter = new \IntlDateFormatter(
                'de_DE',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $this->currentDate->getTimezone()->getName(),
                \IntlDateFormatter::GREGORIAN,
                'EE'
            );
            if ($weekdayFormatter === false) {
                $weekdayFormatter = null;
            }
        }

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

        $weekdayFormatter = null;
        if (class_exists(\IntlDateFormatter::class)) {
            $weekdayFormatter = new \IntlDateFormatter(
                'de_DE',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $this->currentDate->getTimezone()->getName(),
                \IntlDateFormatter::GREGORIAN,
                'EE'
            );
            if ($weekdayFormatter === false) {
                $weekdayFormatter = null;
            }
        }

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

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                'de_DE',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $this->currentDate->getTimezone()->getName(),
                \IntlDateFormatter::GREGORIAN,
                $startPattern
            );

            if ($formatter !== false) {
                $startLabel = $formatter->format($start);
                if ($startLabel !== false) {
                    $formatter->setPattern($endPattern);
                    $endLabel = $formatter->format($end);
                    if ($endLabel !== false) {
                        return sprintf('%s – %s', $startLabel, $endLabel);
                    }
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
            $formatted = $formatter->format($date);
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
}
