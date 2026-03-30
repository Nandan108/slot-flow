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
    /** @var non-empty-list<WeeklyCalendarMoment> */
    public readonly array $moments;

    /**
     * @param list<WeeklyCalendarMoment> $moments
     */
    public function __construct(
        array $moments,
        public readonly bool $rejectInvalidLocalTimes = false,
    ) {
        if ([] === $moments) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar must define at least one weekly moment.',
                [],
            );
        }

        usort(
            $moments,
            static fn (WeeklyCalendarMoment $a, WeeklyCalendarMoment $b): int => [$a->isoWeekday, $a->hour, $a->minute, $a->second]
                <=>
                [$b->isoWeekday, $b->hour, $b->minute, $b->second],
        );

        $this->moments = $moments;
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

            foreach ($this->moments as $moment) {
                $candidate = $moment->dateTime($candidateWeek);
                $this->assertValidLocalTime($moment, $candidate);
                if ($candidate < $earliestDateTime) {
                    continue;
                }

                return $axis->ceil($candidate);
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
}
