<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * One weekly open window expressed as local wall-clock start and end times on one weekday.
 *
 * @api
 */
final class WeeklyCalendarWindow
{
    /**
     * Create one weekly calendar window.
     *
     * @throws SlotFlowInvalidArgumentException
     */
    public function __construct(
        public readonly WeeklyCalendarMoment $start,
        public readonly WeeklyCalendarMoment $end,
    ) {
        if ($start->isoWeekday !== $end->isoWeekday) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar windows require start and end moments on the same weekday.',
                [
                    'start_weekday' => $start->isoWeekday,
                    'end_weekday'   => $end->isoWeekday,
                ],
            );
        }

        if ($start->compareTo($end) >= 0) {
            throw new SlotFlowInvalidArgumentException(
                'Weekly calendar windows must end after they start on the same weekday.',
                [
                    'weekday' => $start->isoWeekday,
                    'start'   => $start->clockTime(),
                    'end'     => $end->clockTime(),
                ],
            );
        }
    }

    /**
     * Build one weekly calendar window from a weekday name/number and local start/end times.
     */
    public static function between(int | string $weekday, string $start, string $end): self
    {
        $isoWeekday = WeeklyCalendarMoment::weekday($weekday);

        return new self(
            start: WeeklyCalendarMoment::at($isoWeekday, $start),
            end: WeeklyCalendarMoment::at($isoWeekday, $end),
        );
    }

    /**
     * Return the concrete start datetime for the week that begins at the given Monday midnight.
     */
    public function startDateTime(\DateTimeImmutable $weekStart): \DateTimeImmutable
    {
        return $this->start->dateTime($weekStart);
    }

    /**
     * Return the concrete end datetime for the week that begins at the given Monday midnight.
     */
    public function endDateTime(\DateTimeImmutable $weekStart): \DateTimeImmutable
    {
        return $this->end->dateTime($weekStart);
    }

    /**
     * Return a canonical key suitable for deduplication and merges.
     */
    public function signature(): string
    {
        return sprintf(
            'window:%d:%s-%s',
            $this->start->isoWeekday,
            $this->start->clockTime(),
            $this->end->clockTime(),
        );
    }

    /**
     * Compare this window to another window using canonical weekday/start/end ordering.
     */
    public function compareTo(self $other): int
    {
        return $this->start->compareTo($other->start)
            ?: $this->end->compareTo($other->end);
    }
}
