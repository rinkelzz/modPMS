<?php

namespace ModPMS;

use DateTimeImmutable;
use IntlDateFormatter;

class Calendar
{
    private DateTimeImmutable $currentDate;

    public function __construct(?DateTimeImmutable $date = null)
    {
        $this->currentDate = $date ?? new DateTimeImmutable('first day of this month');
    }

    public function monthLabel(): string
    {
        $formatter = new IntlDateFormatter(
            'de_DE',
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            $this->currentDate->getTimezone()->getName(),
            IntlDateFormatter::GREGORIAN,
            'LLLL yyyy'
        );

        return $formatter->format($this->currentDate) ?: $this->currentDate->format('F Y');
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
        $weekdayFormatter = new IntlDateFormatter(
            'de_DE',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $this->currentDate->getTimezone()->getName(),
            IntlDateFormatter::GREGORIAN,
            'EE'
        );

        for ($offset = 0; $offset < $daysInMonth; $offset++) {
            $date = $this->currentDate->modify(sprintf('+%d days', $offset));

            $days[] = [
                'day' => (int) $date->format('j'),
                'weekday' => $weekdayFormatter->format($date) ?: $date->format('D'),
                'isToday' => $date->format('Y-m-d') === (new DateTimeImmutable())->format('Y-m-d'),
                'date' => $date->format('Y-m-d'),
            ];
        }

        return $days;
    }
}
