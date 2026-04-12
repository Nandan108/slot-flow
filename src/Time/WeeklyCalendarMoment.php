<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * One weekly release or dispatch moment expressed as ISO weekday and time of day.
 *
 * @api
 */
final class WeeklyCalendarMoment
{
    /**
     * Create one weekly calendar moment.
     *
     * @throws SlotFlowInvalidArgumentException
     */
    public function __construct(
        /** ISO weekday number from 1 (Monday) to 7 (Sunday). */
        public readonly int $isoWeekday,
        /** Hour component of the local wall-clock time. */
        public readonly int $hour,
        /** Minute component of the local wall-clock time. */
        public readonly int $minute = 0,
        /** Second component of the local wall-clock time. */
        public readonly int $second = 0,
    ) {
        if ($isoWeekday < 1 || $isoWeekday > 7) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar weekday must be between 1 (Monday) and 7 (Sunday).',
                ['weekday' => $isoWeekday],
            );
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar time must be a valid clock time.',
                ['hour' => $hour, 'minute' => $minute, 'second' => $second],
            );
        }
    }

    /**
     * Build one weekly calendar moment from a weekday name/number and `HH:MM[:SS]` time string.
     *
     * @param int|'mon'|'monday'|'tue'|'tues'|'tuesday'|'wed'|'wednesday'|'thu'|'thur'|'thurs'|'thursday'|'fri'|'friday'|'sat'|'saturday'|'sun'|'sunday' $weekday
     */
    public static function at(int | string $weekday, string $time): self
    {
        [$hour, $minute, $second] = self::parseTimeOfDay($time);

        return new self(
            isoWeekday: self::normalizeWeekday($weekday),
            hour: $hour,
            minute: $minute,
            second: $second,
        );
    }

    /**
     * Normalize a weekday name or number to ISO weekday form.
     */
    public static function weekday(int | string $weekday): int
    {
        return self::normalizeWeekday($weekday);
    }

    /**
     * Resolve this weekly moment into one concrete datetime inside the week that starts at the given Monday midnight.
     */
    public function dateTime(\DateTimeImmutable $weekStart): \DateTimeImmutable
    {
        $days = $this->isoWeekday - 1;

        return $weekStart
            ->modify("+{$days} days")
            ->setTime($this->hour, $this->minute, $this->second);
    }

    /**
     * Return the canonical local wall-clock string for this moment.
     */
    public function clockTime(): string
    {
        return sprintf('%02d:%02d:%02d', $this->hour, $this->minute, $this->second);
    }

    /**
     * Return a canonical key suitable for deduplication and merges.
     */
    public function signature(): string
    {
        return sprintf('moment:%d:%s', $this->isoWeekday, $this->clockTime());
    }

    /**
     * Compare this moment to another moment using canonical weekday/time ordering.
     */
    public function compareTo(self $other): int
    {
        return [$this->isoWeekday, $this->hour, $this->minute, $this->second]
            <=>
            [$other->isoWeekday, $other->hour, $other->minute, $other->second];
    }

    private static function normalizeWeekday(int | string $weekday): int
    {
        if (is_int($weekday)) {
            return $weekday;
        }

        $normalized = strtolower($weekday);

        return match ($normalized) {
            'mon', 'monday'                    => 1,
            'tue', 'tues', 'tuesday'           => 2,
            'wed', 'wednesday'                 => 3,
            'thu', 'thur', 'thurs', 'thursday' => 4,
            'fri', 'friday'                    => 5,
            'sat', 'saturday'                  => 6,
            'sun', 'sunday'                    => 7,
            default                            => throw new SlotFlowInvalidArgumentException(
                'Weekly calendar weekday must be an ISO weekday number or weekday name.',
                ['weekday' => $weekday],
            ),
        };
    }

    /**
     * @return array{int, int, int}
     */
    private static function parseTimeOfDay(string $time): array
    {
        if (!preg_match('/^(?<hour>\d{1,2}):(?<minute>\d{2})(?::(?<second>\d{2}))?$/', $time, $matches)) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar time must use HH:MM or HH:MM:SS format.',
                ['time' => $time],
            );
        }

        $hour = (int) $matches['hour'];
        $minute = (int) $matches['minute'];
        $second = isset($matches['second']) ? (int) $matches['second'] : 0;

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar time must be a valid clock time.',
                ['time' => $time],
            );
        }

        return [$hour, $minute, $second];
    }
}
