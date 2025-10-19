<?php

namespace ModPMS;

use DateTimeImmutable;

class Calendar
{
    private DateTimeImmutable $currentDate;

    public function __construct(?DateTimeImmutable $date = null)
    {
        $this->currentDate = $date ?? new DateTimeImmutable('first day of this month');
    }

    public function monthLabel(): string
    {
        $formatter = $this->createIntlDateFormatter('LLLL yyyy', 'LONG', 'NONE');

        if ($formatter !== null) {
            $label = $formatter->format($this->currentDate);

            if ($label !== false) {
                return $label;
            }
        }

        return $this->currentDate->format('F Y');
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
        $weekdayFormatter = $this->createIntlDateFormatter('EE', 'NONE', 'NONE');

        for ($offset = 0; $offset < $daysInMonth; $offset++) {
            $date = $this->currentDate->modify(sprintf('+%d days', $offset));

            $days[] = [
                'day' => (int) $date->format('j'),
                'weekday' => $this->formatWeekday($weekdayFormatter, $date),
                'isToday' => $date->format('Y-m-d') === (new DateTimeImmutable())->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
            ];
        }

        return $days;
    }

    /**
     * @return \IntlDateFormatter|null
     */
    private function createIntlDateFormatter(string $pattern, string $dateType, string $timeType)
    {
        if (!class_exists('IntlDateFormatter')) {
            return null;
        }

        $timezone = $this->currentDate->getTimezone()->getName();
        $calendar = defined('IntlDateFormatter::GREGORIAN')
            ? constant('IntlDateFormatter::GREGORIAN')
            : 1;

        $dateTypeConstant = 'IntlDateFormatter::' . $dateType;
        $timeTypeConstant = 'IntlDateFormatter::' . $timeType;

        $dateTypeValue = defined($dateTypeConstant) ? constant($dateTypeConstant) : 0;
        $timeTypeValue = defined($timeTypeConstant) ? constant($timeTypeConstant) : 0;

        return new \IntlDateFormatter(
            'de_DE',
            $dateTypeValue,
            $timeTypeValue,
            $timezone,
            $calendar,
            $pattern
        );
    }

    /**
     * @param \IntlDateFormatter|null $formatter
     */
    private function formatWeekday($formatter, DateTimeImmutable $date): string
    {
        if ($formatter !== null) {
            $weekday = $formatter->format($date);

            if ($weekday !== false) {
                return (string) $weekday;
            }
        }

        return $date->format('D');
    }
}
