<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * Repeating weekly schedule used by shipment and dispatch calendars.
 *
 * @api
 */
final class WeeklyCalendar
{
    /** @var list<WeeklyCalendarMoment> */
    public readonly array $moments;
    /** @var list<WeeklyCalendarWindow> */
    public readonly array $windows;

    /**
     * @param list<WeeklyCalendarMoment|WeeklyCalendarWindow> $entries
     */
    public function __construct(
        array $entries,
        public readonly bool $rejectInvalidLocalTimes = false,
    ) {
        if ([] === $entries) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar must define at least one weekly moment or window.',
                [],
            );
        }

        $moments = [];
        $windows = [];

        foreach ($entries as $entry) {
            if ($entry instanceof WeeklyCalendarMoment) {
                $moments[] = $entry;
            } else {
                $windows[] = $entry;
            }
        }

        usort(
            $moments,
            static fn (WeeklyCalendarMoment $a, WeeklyCalendarMoment $b): int => $a->compareTo($b),
        );
        usort(
            $windows,
            static fn (WeeklyCalendarWindow $a, WeeklyCalendarWindow $b): int => $a->compareTo($b),
        );

        $this->moments = $moments;
        $this->windows = $windows;
    }

    /**
     * Build one weekly calendar from a weekday => list of moment/window expressions map.
     *
     * Example: `['mon-thu,fri' => ['10:00', '13:00-16:00'], '6,7' => ['09:00']]`
     *
     * @param array<int|string, string|list<string>> $map
     */
    public static function fromMap(array $map, bool $rejectInvalidLocalTimes = false): self
    {
        $entries = [];

        foreach ($map as $daySelector => $expressions) {
            foreach (self::expandDaySelector($daySelector) as $isoWeekday) {
                foreach ((array) $expressions as $expression) {
                    $entries[] = self::parseEntry($isoWeekday, $expression);
                }
            }
        }

        return new self($entries, $rejectInvalidLocalTimes);
    }

    /**
     * Merge multiple weekly calendars into one deduplicated calendar.
     */
    public static function merge(self ...$calendars): self
    {
        if ([] === $calendars) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar merge requires at least one calendar.',
                [],
            );
        }

        $entriesBySignature = [];
        $rejectInvalidLocalTimes = false;

        foreach ($calendars as $calendar) {
            $rejectInvalidLocalTimes = $rejectInvalidLocalTimes || $calendar->rejectInvalidLocalTimes;

            foreach ($calendar->moments as $moment) {
                $entriesBySignature[$moment->signature()] = $moment;
            }

            foreach ($calendar->windows as $window) {
                $entriesBySignature[$window->signature()] = $window;
            }
        }

        return new self(array_values($entriesBySignature), $rejectInvalidLocalTimes);
    }

    /**
     * Return the first bucket index at or after the given time that matches this weekly schedule.
     */
    public function nextTime(TimeAxis $axis, int $earliestTime): int
    {
        $earliestDateTime = $axis->dateTime($earliestTime);
        $weekStart = $this->startOfIsoWeek($earliestDateTime);

        for ($weekOffset = 0; $weekOffset < 3; ++$weekOffset) {
            $candidateWeek = $weekStart->modify("+{$weekOffset} week");
            $bestCandidate = null;

            foreach ($this->moments as $moment) {
                $candidate = $moment->dateTime($candidateWeek);
                $this->assertValidLocalTime($moment, $candidate);
                if ($candidate < $earliestDateTime) {
                    continue;
                }

                $bestCandidate = $this->earlierDateTime($bestCandidate, $candidate);
            }

            foreach ($this->windows as $window) {
                $windowStart = $window->startDateTime($candidateWeek);
                $windowEnd = $window->endDateTime($candidateWeek);
                $this->assertValidLocalTime($window->start, $windowStart);
                $this->assertValidLocalTime($window->end, $windowEnd);

                if ($earliestDateTime < $windowStart) {
                    $bestCandidate = $this->earlierDateTime($bestCandidate, $windowStart);
                    continue;
                }

                if ($earliestDateTime <= $windowEnd) {
                    $bestCandidate = $this->earlierDateTime($bestCandidate, $earliestDateTime);
                }
            }

            if ($bestCandidate instanceof \DateTimeImmutable) {
                return $axis->ceil($bestCandidate);
            }
        }

        throw new SlotFlowInvalidArgumentException(
            'Weekly calendar could not resolve a next matching time.',
            ['earliest_time' => $earliestTime],
        );
    }

    private function startOfIsoWeek(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->modify('monday this week')->setTime(0, 0, 0);
    }

    private function earlierDateTime(?\DateTimeImmutable $current, \DateTimeImmutable $candidate): \DateTimeImmutable
    {
        if (null === $current || $candidate < $current) {
            return $candidate;
        }

        return $current;
    }

    private function assertValidLocalTime(WeeklyCalendarMoment $moment, \DateTimeImmutable $candidate): void
    {
        if (
            !$this->rejectInvalidLocalTimes
            || (
                (int) $candidate->format('H') === $moment->hour
                && (int) $candidate->format('i') === $moment->minute
                && (int) $candidate->format('s') === $moment->second
            )
        ) {
            return;
        }

        throw new SlotFlowInvalidArgumentException(
            'Weekly calendar local time does not exist in the configured timezone.',
            [
                'weekday'  => $moment->isoWeekday,
                'time'     => sprintf('%02d:%02d:%02d', $moment->hour, $moment->minute, $moment->second),
                'resolved' => $candidate->format(DATE_ATOM),
            ],
        );
    }

    private static function parseEntry(int | string $weekday, string $expression): WeeklyCalendarMoment | WeeklyCalendarWindow
    {
        if (str_contains($expression, '-')) {
            $parts = explode('-', $expression, 2);
            if (2 !== count($parts)) {
                throw new SlotFlowInvalidArgumentException(
                    'Weekly calendar windows must use start-end syntax.',
                    ['expression' => $expression],
                );
            }
            [$start, $end] = $parts;
            $isoWeekday = WeeklyCalendarMoment::weekday($weekday);

            return WeeklyCalendarWindow::between($isoWeekday, trim($start), trim($end));
        }

        return WeeklyCalendarMoment::at(WeeklyCalendarMoment::weekday($weekday), trim($expression));
    }

    /**
     * @return list<int>
     */
    private static function expandDaySelector(int | string $selector): array
    {
        if (is_int($selector)) {
            return [self::isoFromZeroBased(self::normalizeDayToken((string) $selector))];
        }

        $expanded = [];

        foreach (explode(',', strtolower($selector)) as $segment) {
            $segment = trim($segment);
            if ('' === $segment) {
                throw new SlotFlowInvalidArgumentException(
                    'Weekly calendar day selectors must not contain empty segments.',
                    ['selector' => $selector],
                );
            }

            if (str_contains($segment, '-')) {
                $parts = explode('-', $segment, 2);
                if (2 !== count($parts)) {
                    throw new SlotFlowInvalidArgumentException(
                        'Weekly calendar day ranges must use start-end syntax.',
                        ['selector' => $selector, 'segment' => $segment],
                    );
                }

                [$start, $end] = $parts;
                $startIndex = self::normalizeDayToken(trim($start));
                $endIndex = self::normalizeDayToken(trim($end));
                $index = $startIndex;

                do {
                    $expanded[] = self::isoFromZeroBased($index);
                    $index = ($index + 1) % 7;
                } while ($index !== ($endIndex + 1) % 7);

                continue;
            }

            $expanded[] = self::isoFromZeroBased(self::normalizeDayToken($segment));
        }

        return array_values(array_unique($expanded));
    }

    private static function normalizeDayToken(string $token): int
    {
        $token = strtolower(trim($token));

        if (ctype_digit($token)) {
            $isoWeekday = (int) $token;
            if ($isoWeekday < 1 || $isoWeekday > 7) {
                throw new SlotFlowInvalidArgumentException(
                    'Weekly calendar numeric weekdays must be ISO values from 1 (Monday) to 7 (Sunday).',
                    ['weekday' => $token],
                );
            }

            return $isoWeekday - 1;
        }

        return match ($token) {
            'mon', 'monday' => 0,
            'tue', 'tues', 'tuesday' => 1,
            'wed', 'wednesday' => 2,
            'thu', 'thur', 'thurs', 'thursday' => 3,
            'fri', 'friday' => 4,
            'sat', 'saturday' => 5,
            'sun', 'sunday' => 6,
            default => throw new SlotFlowInvalidArgumentException(
                'Weekly calendar day selectors must use weekday names or ISO weekday numbers.',
                ['weekday' => $token],
            ),
        };
    }

    private static function isoFromZeroBased(int $index): int
    {
        return $index + 1;
    }
}
