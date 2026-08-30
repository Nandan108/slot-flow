<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\NamedPolicy;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\TimedDurationContext;
use Nandan108\SlotFlow\Time\TimedDurationResolverInterface;
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use Nandan108\SlotFlow\Time\TimedQuantityState;
use Nandan108\SlotFlow\Time\TimedSlotSpace;
use Nandan108\SlotFlow\Time\WeeklyCalendar;
use Nandan108\SlotFlow\Time\WeeklyCalendarMoment;
use Nandan108\SlotFlow\Time\WeeklyCalendarWindow;
use Nandan108\SlotFlow\Time\WeeklyDispatchCalendar;
use PHPUnit\Framework\TestCase;

final class TimeAwareTestCodec extends DefaultSlotKeyCodec
{
}

final class TimeLayerTest extends TestCase
{
    public function testTimeAxisParsesAndNormalizesCompositeTimeExpressions(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 200,
            aliases: ['shift:x' => 8, 'day' => 24],
        );

        self::assertSame(27, $axis->parse('1d3h'));
        self::assertSame(27, $axis->parse('d1h3'));
        self::assertSame(27, $axis->parse('day1hour3'));
        self::assertSame(44, $axis->parse('1hour1day3h1shift1x'));
        self::assertSame(48, $axis->parse('x3day1'));

        self::assertSame('h0', $axis->key(0));
        self::assertSame('1d3h', $axis->humanKey(27));
        self::assertSame('3d1x', $axis->humanKey(80));
        self::assertSame(0, $axis->parse(0));
        self::assertSame(80, $axis->parse('d3x1'));
        self::assertSame('h80', $axis->normalize('d3x1'));
        self::assertSame('hour', $axis->bucket);
        self::assertSame(['shift' => 8, 'day' => 24], $axis->aliases);
        self::assertTrue($axis->contains('d3x1'));
        self::assertFalse($axis->contains('d9'));
    }

    public function testTimeAxisRejectsUnknownUnitsNegativeValuesAndDuplicateShorthands(): void
    {
        $axis = TimeAxis::define(bucket: 'hour', horizon: 24, aliases: ['day' => 24]);

        try {
            $axis->parse('w1');
            self::fail('Expected unknown time unit rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Unknown time unit in expression.', $e->getMessage());
        }

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Time values must be zero or greater.');
        $axis->parse(-1);
    }

    public function testTimeAxisAndWeeklyHelpersCoverRemainingValidationBranches(): void
    {
        try {
            TimeAxis::define(bucket: 'hour', horizon: 10, aliases: ['day' => 0]);
            self::fail('Expected invalid alias multiplier rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time alias multipliers must be positive integers.', $e->getMessage());
        }

        $axis = TimeAxis::define(bucket: 'Hour', horizon: 48, aliases: ['day' => 24], humanKeyParts: ['d']);
        self::assertSame('1d1H', $axis->humanKey(25));

        try {
            $axis->dateTime(new \DateTimeImmutable('@-3600'));
            self::fail('Expected pre-time-zero rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time values must not resolve before the axis time zero.', $e->getMessage());
        }

        try {
            new WeeklyCalendarMoment(0, 12);
            self::fail('Expected invalid weekday rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar weekday must be between 1 (Monday) and 7 (Sunday).', $e->getMessage());
        }

        try {
            new WeeklyCalendarMoment(1, 24);
            self::fail('Expected invalid clock time rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar time must be a valid clock time.', $e->getMessage());
        }

        try {
            /** @psalm-suppress InvalidArgument */
            WeeklyCalendarMoment::at('noday', '12:00');
            self::fail('Expected invalid weekday name rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar weekday must be an ISO weekday number or weekday name.', $e->getMessage());
        }

        try {
            WeeklyCalendarMoment::at('mon', 'nope');
            self::fail('Expected invalid time format rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar time must use HH:MM or HH:MM:SS format.', $e->getMessage());
        }

        try {
            WeeklyCalendarMoment::at('mon', '24:00');
            self::fail('Expected invalid time-of-day rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar time must be a valid clock time.', $e->getMessage());
        }

        try {
            WeeklyCalendar::fromMap(['noday' => ['10:00']]);
            self::fail('Expected invalid day-selector rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar day selectors must use weekday names or ISO weekday numbers (1-7).', $e->getMessage());
        }
    }

    public function testTimeAxisRejectsInvalidBucketAndHumanKeyConfigurations(): void
    {
        try {
            new TimeAxis('hour-1', 3600, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 24);
            self::fail('Expected invalid bucket rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString('Time bucket name must contain letters only', $e->getMessage());
        }

        $axis = TimeAxis::define(bucket: 'hour', horizon: 24, aliases: ['day' => 24, 'shift' => 8]);

        try {
            $axis->key(-1);
            self::fail('Expected negative time index rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time index must be zero or greater.', $e->getMessage());
        }

        try {
            $axis->parse('1d?');
            self::fail('Expected invalid trailing content rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Invalid trailing content in time expression.', $e->getMessage());
        }

        try {
            TimeAxis::define(bucket: 'hour', horizon: 24, aliases: ['day' => 24], humanKeyParts: ['w']);
            self::fail('Expected unknown human key part rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Human key parts must reference known time shorthands.', $e->getMessage());
        }

        try {
            TimeAxis::define(bucket: 'hour', horizon: 24, aliases: ['day' => 24], humanKeyParts: ['d', 'd']);
            self::fail('Expected duplicate human key part rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Human key parts must be unique.', $e->getMessage());
        }

        try {
            TimeAxis::define(bucket: 'hour', horizon: 24, aliases: ['day' => 24], humanKeyParts: []);
            self::fail('Expected empty human key parts rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Human key parts cannot be empty.', $e->getMessage());
        }
    }

    public function testTimeAxisRejectsDuplicateFirstLettersAcrossBucketAndAliases(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Time bucket and aliases must have unique first letters.');

        TimeAxis::define('day', 10, ['dock' => 2]);
    }

    public function testSlotSpaceStoresTimeAxisAndHonoursACustomCodecClass(): void
    {
        $timeAxis = TimeAxis::define('hour', 24, ['shift' => 8, 'day' => 24]);
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw', 'wip'],
            ],
            timeAxis: $timeAxis,
            codecClass: TimeAwareTestCodec::class,
        );

        self::assertSame($timeAxis, $space->timeAxis);
        self::assertSame($timeAxis, $space->temporal()->axis);
        self::assertInstanceOf(TimeAwareTestCodec::class, $space->codec);
    }

    public function testCodecIsConstructedWithoutATimeAxis(): void
    {
        // A codec serializes dimension values; time is a separate axis the timed layer expands
        // along, never a slot dimension. The codec contract stays time-free so the timed layer
        // cannot reach into it — see tests/BoundaryTest.php.
        $constructor = (new \ReflectionClass(DefaultSlotKeyCodec::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(1, $constructor->getNumberOfParameters());
        self::assertSame('space', $constructor->getParameters()[0]->getName());
    }

    public function testTimeAxisCanFormatHumanKeysWithCustomParts(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 300,
            aliases: ['shift' => 8, 'day' => 24],
            humanKeyParts: ['d', 'h'],
        );

        self::assertSame(['d', 'h'], $axis->humanKeyParts);
        self::assertSame('9d16h', $axis->humanKey(232));
        self::assertSame('h0', $axis->humanKey(0));
    }

    public function testTimeAxisNormalizesTimeZeroAndConvertsBetweenBucketsAndDateTimes(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 48,
            aliases: ['day' => 24],
            timeZero: new \DateTimeImmutable('2026-03-30T14:23:10+00:00'),
        );

        self::assertSame('2026-03-30T14:00:00+00:00', $axis->timeZero->format(DATE_ATOM));
        self::assertSame(0, $axis->parse(new \DateTimeImmutable('2026-03-30T14:59:59+00:00')));
        self::assertSame(27, $axis->parse(new \DateTimeImmutable('2026-03-31T17:10:00+00:00')));
        self::assertSame('2026-03-31T17:00:00+00:00', $axis->dateTime(27)->format(DATE_ATOM));
        self::assertSame('2026-03-31T17:00:00+00:00', $axis->dateTime('1d3h')->format(DATE_ATOM));
        self::assertSame('1d3h', $axis->humanKey(new \DateTimeImmutable('2026-03-31T17:59:59+00:00')));
        self::assertTrue($axis->contains(new \DateTimeImmutable('2026-04-01T12:00:00+00:00')));
        self::assertFalse($axis->contains(new \DateTimeImmutable('2026-03-30T13:59:59+00:00')));
    }

    public function testTimeAxisDefineUsesDeterministicEpochAnchorByDefault(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 48,
            aliases: ['day' => 24],
        );

        self::assertSame('1970-01-01T00:00:00+00:00', $axis->timeZero->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame('1970-01-02T03:00:00+00:00', $axis->dateTime('1d3h')->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function testTimeAxisNormalizesNegativeAnchorsDownToPreviousBucketBoundary(): void
    {
        $axis = new TimeAxis(
            bucket: 'hour',
            secondsInBucket: 3600,
            timeZero: new \DateTimeImmutable('1969-12-31T23:30:00+00:00'),
            horizon: 24,
        );

        self::assertSame('1969-12-31T23:00:00+00:00', $axis->timeZero->format(DATE_ATOM));
    }

    public function testTimeAxisConstructorAcceptsStringTimeZero(): void
    {
        $axis = new TimeAxis(
            bucket: 'hour',
            secondsInBucket: 3600,
            timeZero: '2026-03-30T14:23:10+00:00',
            horizon: 24,
        );

        self::assertSame('2026-03-30T14:00:00+00:00', $axis->timeZero->format(DATE_ATOM));
    }

    public function testTimeAxisStartingNowNormalizesTheProvidedInstantToTheBucketBoundary(): void
    {
        $axis = TimeAxis::startingNow(
            bucket: 'hour',
            horizon: 24,
            now: new \DateTimeImmutable('2026-03-30T14:23:10+00:00'),
        );

        self::assertSame('2026-03-30T14:00:00+00:00', $axis->timeZero->format(DATE_ATOM));
    }

    public function testTimeAxisCoversConstructorValidationParsingAndDateRoundingBranches(): void
    {
        try {
            new TimeAxis('hour', 3600, 'not-a-date', 24);
            self::fail('Expected invalid time-zero rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time axis zero point must be a valid datetime string or DateTimeImmutable.', $e->getMessage());
        }

        try {
            new TimeAxis('hour', 0, new \DateTimeImmutable('@0'), 24);
            self::fail('Expected invalid bucket-size rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time bucket size must be greater than zero seconds.', $e->getMessage());
        }

        try {
            new TimeAxis('hour', 3600, new \DateTimeImmutable('@0'), -1);
            self::fail('Expected invalid horizon rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time horizon must be zero or greater.', $e->getMessage());
        }

        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 48,
            aliases: ['day' => 24],
            timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
        );

        self::assertSame('h0', $axis->humanKey(0));
        self::assertSame(12, $axis->parse('12'));
        self::assertSame(1, $axis->ceil(new \DateTimeImmutable('2026-03-30T00:00:01+00:00')));
        self::assertSame(1, $axis->ceil('1h'));
        self::assertSame('2026-03-30T12:00:00+00:00', $axis->dateTime(new \DateTimeImmutable('2026-03-30T12:59:59+00:00'))->format(DATE_ATOM));

        try {
            $axis->parse('');
            self::fail('Expected empty time rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time value cannot be empty.', $e->getMessage());
        }

        try {
            $axis->parse(new \DateTimeImmutable('2026-03-29T23:00:00+00:00'));
            self::fail('Expected before-time-zero rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Time values must not resolve before the axis time zero.', $e->getMessage());
        }

        try {
            TimeAxis::define('fortnight', 10);
            self::fail('Expected custom-bucket seconds rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('TimeAxis::define() requires secondsInBucket for non-standard buckets.', $e->getMessage());
        }
    }

    public function testWeeklyCalendarResolvesNextMatchingBucketAtOrAfterTheEarliestTime(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 24 * 14,
            timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
        );
        $calendar = new WeeklyCalendar([
            WeeklyCalendarMoment::at('tue', '18:00'),
            WeeklyCalendarMoment::at('fri', '09:00'),
        ]);

        self::assertSame(42, $calendar->nextTime($axis, 24));
        self::assertSame(105, $calendar->nextTime($axis, 72));
    }

    public function testWeeklyCalendarCanRejectWhenNoMatchingTimeFallsWithinTheAxisHorizon(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 24,
            aliases: ['day' => 24],
            timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
        );
        $calendar = new WeeklyCalendar([
            WeeklyCalendarMoment::at('fri', '09:00'),
        ]);

        try {
            $calendar->nextTime($axis, 0);
            self::fail('Expected nextTime() to reject when no matching time lies within the axis horizon.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar could not resolve a next matching time.', $e->getMessage());
        }
    }

    public function testWeeklyCalendarUsesWallClockTimesAcrossDstTransitions(): void
    {
        $zurich = new \DateTimeZone('Europe/Zurich');
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 24 * 14,
            timeZero: new \DateTimeImmutable('2026-03-23T00:00:00', $zurich),
        );
        $calendar = new WeeklyCalendar([
            WeeklyCalendarMoment::at('sun', '08:00'),
        ]);

        self::assertSame(
            '2026-03-29T08:00:00+02:00',
            $axis->dateTime($calendar->nextTime($axis, 0))->format(DATE_ATOM),
        );
    }

    public function testWeeklyCalendarFromMapCanExpandGroupedAndNumericDaySelectors(): void
    {
        $fromMap = WeeklyCalendar::fromMap([
            'mon-thu,fri' => ['10:00', '13:00-16:00'],
            '6,7'         => ['09:00'],
        ]);

        self::assertCount(7, $fromMap->moments);
        self::assertCount(5, $fromMap->windows);
        self::assertInstanceOf(WeeklyCalendarWindow::class, $fromMap->windows[0]);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(static fn (WeeklyCalendarMoment $moment): int => $moment->isoWeekday, $fromMap->moments));
    }

    public function testWeeklyCalendarWindowsReleaseImmediatelyWhenAlreadyInsideTheWindow(): void
    {
        $axis = TimeAxis::define(
            bucket: 'hour',
            horizon: 24 * 7,
            timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
        );
        $calendar = WeeklyCalendar::fromMap([
            'mon' => ['10:00-13:00'],
        ]);

        self::assertSame(11, $calendar->nextTime($axis, 11));
        self::assertSame(10, $calendar->nextTime($axis, 9));
    }

    public function testWeeklyCalendarCanMergeAndDeduplicateEntries(): void
    {
        $merged = WeeklyCalendar::merge(
            WeeklyCalendar::fromMap([
                'mon' => ['10:00', '13:00-16:00'],
            ]),
            WeeklyCalendar::fromMap(
                ['mon,fri' => ['10:00', '13:00-16:00']],
                rejectInvalidLocalTimes: true,
            ),
        );

        self::assertCount(2, $merged->moments);
        self::assertCount(2, $merged->windows);
        self::assertTrue($merged->rejectInvalidLocalTimes);
    }

    public function testWeeklyCalendarFromMapCanHandleWraparoundRanges(): void
    {
        $calendar = WeeklyCalendar::fromMap([
            'fri-mon' => ['10:00'],
        ]);

        self::assertSame([1, 5, 6, 7], array_map(static fn (WeeklyCalendarMoment $moment): int => $moment->isoWeekday, $calendar->moments));
    }

    public function testWeeklyCalendarRejectsEmptyCalendarsAndEmptyMerges(): void
    {
        try {
            new WeeklyCalendar([]);
            self::fail('Expected empty weekly calendar rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar must define at least one weekly moment or window.', $e->getMessage());
        }

        try {
            WeeklyCalendar::merge();
            self::fail('Expected empty weekly calendar merge rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar merge requires at least one calendar.', $e->getMessage());
        }
    }

    public function testWeeklyCalendarFromMapRejectsInvalidSelectorsAndWindowExpressions(): void
    {
        try {
            WeeklyCalendar::fromMap(['mon,,wed' => ['10:00']]);
            self::fail('Expected empty day-selector segment rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar day selectors must not contain empty segments.', $e->getMessage());
        }

        try {
            WeeklyCalendar::fromMap(['8' => ['10:00']]);
            self::fail('Expected invalid numeric weekday rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar day selectors must use weekday names or ISO weekday numbers (1-7).', $e->getMessage());
        }

        try {
            WeeklyCalendar::fromMap(['funday' => ['10:00']]);
            self::fail('Expected invalid weekday token rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar day selectors must use weekday names or ISO weekday numbers (1-7).', $e->getMessage());
        }

        try {
            WeeklyCalendar::fromMap(['mon' => ['16:00-13:00']]);
            self::fail('Expected descending window rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar windows must end after they start on the same weekday.', $e->getMessage());
        }
    }

    public function testWeeklyCalendarWindowRejectsCrossDayWindowsAndExposesHelpers(): void
    {
        try {
            new WeeklyCalendarWindow(
                WeeklyCalendarMoment::at('mon', '10:00'),
                WeeklyCalendarMoment::at('tue', '11:00'),
            );
            self::fail('Expected cross-day weekly window rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar windows require start and end moments on the same weekday.', $e->getMessage());
        }

        $window = WeeklyCalendarWindow::between('wed', '09:00', '11:00');
        $weekStart = new \DateTimeImmutable('2026-03-30T00:00:00+00:00');

        self::assertSame('window:3:09:00:00-11:00:00', $window->signature());
        self::assertSame('2026-04-01T09:00:00+00:00', $window->startDateTime($weekStart)->format(DATE_ATOM));
        self::assertSame('2026-04-01T11:00:00+00:00', $window->endDateTime($weekStart)->format(DATE_ATOM));
        self::assertGreaterThan(0, $window->compareTo(WeeklyCalendarWindow::between('mon', '09:00', '11:00')));
    }

    public function testWeeklyCalendarMomentHelpersAndValidation(): void
    {
        $moment = WeeklyCalendarMoment::at('fri', '09:15:30');

        self::assertSame(5, WeeklyCalendarMoment::weekday('fri'));
        self::assertSame('09:15:30', $moment->clockTime());
        self::assertSame('moment:5:09:15:30', $moment->signature());
        self::assertGreaterThan(0, $moment->compareTo(WeeklyCalendarMoment::at('thu', '09:15:30')));

        try {
            WeeklyCalendarMoment::at('fri', '25:00');
            self::fail('Expected invalid time-of-day rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Weekly calendar time must be a valid clock time.', $e->getMessage());
        }
    }

    public function testWeeklyCalendarCanRejectNonexistentLocalTimes(): void
    {
        $zurich = new \DateTimeZone('Europe/Zurich');
        $calendar = new WeeklyCalendar(
            [WeeklyCalendarMoment::at('sun', '02:30')],
            rejectInvalidLocalTimes: true,
        );
        $axis = TimeAxis::define(
            bucket: 'minute',
            horizon: 60 * 24 * 14,
            timeZero: new \DateTimeImmutable('2026-03-23T00:00:00', $zurich),
        );

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Weekly calendar local time does not exist in the configured timezone.');

        $calendar->nextTime($axis, 0);
    }

    public function testTimedSlotSpaceResolvesTimedSlotsByTupleOrSerializedKey(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw', 'wip', 'fg'],
            ],
            timeAxis: TimeAxis::define(bucket: 'hour', horizon: 10, aliases: ['day' => 24]),
        );
        $timed = TimedSlotSpace::fromBaseSpace($space);

        $slot = $timed->slot('sup.raw', 'h3');

        self::assertSame($space->timeAxis, $timed->axis);
        self::assertSame('sup.raw@h3', $slot->key);
        self::assertSame('h3', $slot->timeKey);
        self::assertSame(3, $slot->timeIndex);
        self::assertSame('sup', $slot->dimension('loc'));
        self::assertTrue($slot->equals($timed->slot('sup.raw@h3')));
        self::assertSame('sup.raw@h5', $slot->at(5)->key);
        self::assertSame('sup.raw@3h', $slot->humanKey());
    }

    public function testTimedSlotSpaceRejectsInvalidTimedSlotInputs(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw', 'wip'],
            ],
            timeAxis: TimeAxis::define(bucket: 'hour', horizon: 4),
        );
        $timed = TimedSlotSpace::fromBaseSpace($space);

        try {
            $timed->slot('sup.raw@', null);
            self::fail('Expected invalid serialized timed slot rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Invalid timed slot key; expected the form slot@time.', $e->getMessage());
        }

        try {
            $timed->slot('sup.raw@h1', 2);
            self::fail('Expected duplicate time rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed slot keys already include a time suffix; do not also pass a separate time.', $e->getMessage());
        }

        try {
            $timed->slot('sup.raw');
            self::fail('Expected missing time rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed slots require an explicit time unless the serialized key already contains @time.', $e->getMessage());
        }

        try {
            /** @phpstan-ignore argument.type */
            $timed->slot('', 1);
            self::fail('Expected concrete base slot rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed slots require a concrete base slot key or Slot instance.', $e->getMessage());
        }

        try {
            $timed->slot('sup.raw', 5);
            self::fail('Expected time horizon rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed slot lies outside the configured time horizon.', $e->getMessage());
        }
    }

    public function testTimedSlotSpaceRequiresAnAxisWhenTheBaseSpaceHasNone(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Timed slot space requires a TimeAxis, either passed explicitly or declared on the base SlotSpace.',
        );

        TimedSlotSpace::fromBaseSpace($this->makeTimedBaseSpace());
    }

    public function testTimedSlotSpaceAddsHoldoverAndDurationExpandedEdges(): void
    {
        $space = $this->makeTimedBaseSpace()->edgeRules([
            EdgeRule::allowLabeled('ship', 'sup.raw', 'plant.raw', ['duration' => '2d', 'lane' => 'truck']),
            EdgeRule::allowLabeled('process', 'plant.raw', 'plant.wip', ['duration' => 8]),
            EdgeRule::allowLabeled('finish', 'plant.wip', 'plant.fg', ['duration' => '1d']),
        ]);

        $timed = TimedSlotSpace::fromBaseSpace(
            $space,
            TimeAxis::define(bucket: 'hour', horizon: 72, aliases: ['day' => 24]),
        );

        $origin = $timed->slot('sup.raw', 0);
        $edges = $timed->getEdgesFrom($origin);

        self::assertSame(
            ['sup.raw@h1', 'plant.raw@h48'],
            array_map(static fn (TimedMovementEdge $edge): string => $edge->to->key, $edges),
        );
        self::assertSame('hold', $edges[0]->label);
        self::assertSame(['duration' => 1, 'timed-kind' => 'holdover'], $edges[0]->attributes);
        self::assertSame('ship', $edges[1]->label);
        self::assertSame(48, $edges[1]->attributes['duration']);
        self::assertSame('movement', $edges[1]->attributes['timed-kind']);
        self::assertSame('truck', $edges[1]->attributes['lane']);
    }

    public function testTimedSlotSpaceCanUseAWeeklyDispatchCalendar(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['wh1', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define(
                bucket: 'hour',
                horizon: 24 * 7,
                aliases: ['day' => 24],
                timeZero: new \DateTimeImmutable('2026-03-30T00:00:00+00:00'),
            ),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'wh1.fs', 'cust.sd', ['duration' => '1d']),
        ])->setDispatchCalendar(
            new WeeklyDispatchCalendar(new WeeklyCalendar([
                WeeklyCalendarMoment::at('tue', '08:00'),
                WeeklyCalendarMoment::at('thu', '08:00'),
            ])),
        );

        $timed = TimedSlotSpace::fromBaseSpace($space);
        $edges = $timed->getEdgesFrom($timed->slot('wh1.fs', 17));

        self::assertSame('wh1.fs@h17', $edges[1]->from->key);
        self::assertSame(32, $edges[1]->attributes['dispatch-time']);
        self::assertSame(15, $edges[1]->attributes['wait-duration']);
        self::assertSame('cust.sd@h56', $edges[1]->to->key);
        self::assertSame('cust.sd@2d8h', $edges[1]->to->humanKey());
    }

    public function testTimedSlotSpaceCoversDurationDispatchAndPlannerHelperBranches(): void
    {
        $plannerRule = new class implements PlannerRuleInterface, PolicyInterface {
        };
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'dest'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 12),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'src.fs', 'dest.sd', ['duration' => 1])->plannerRules($plannerRule),
        ])->flow(
            'ship',
            static fn (Flow $flow) => $flow->stepByLabeledEdges('ship')->policies(NamedPolicy::as('planner', $plannerRule)),
        );

        $timed = TimedSlotSpace::fromBaseSpace($space);
        self::assertInstanceOf(TimedQuantityState::class, $timed->timedQuantityState(new QuantityState($space, [['src.fs', 1]]), 0));
        self::assertCount(1, $timed->getEdgesFrom($timed->slot('src.fs', 0))[1]->plannerRules());

        try {
            TimedSlotSpace::fromBaseSpace(
                baseSpace: $space,
                durationResolver: static fn (): array => [],
            )->getEdgesFrom($timed->slot('src.fs', 0));
            self::fail('Expected invalid closure duration rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed movement edge duration must be an int or time expression string.', $e->getMessage());
        }

        try {
            TimedSlotSpace::fromBaseSpace(
                baseSpace: $space,
                dispatchCalendar: static fn (): string => 'later',
            )->getEdgesFrom($timed->slot('src.fs', 0));
            self::fail('Expected invalid dispatch calendar rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Dispatch calendar must resolve to an integer time index.', $e->getMessage());
        }
    }

    public function testTimedQuantityStateCopyAndTimedSlotInputBranches(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src'],
                'stt' => ['fs'],
            ],
            timeAxis: TimeAxis::define('hour', 6),
        );
        $timed = TimedSlotSpace::fromBaseSpace($space);
        $slot = $timed->slot('src.fs', 1);
        $state = new TimedQuantityState($timed, [[$slot, 2]]);
        $copy = $state->copy();

        self::assertSame(2, $state->get($slot));
        self::assertSame(2, $copy->get($slot));
    }

    public function testTimedSlotSpaceSkipsEdgesThatArriveBeyondTheHorizon(): void
    {
        $space = $this->makeTimedBaseSpace()->edgeRules([
            EdgeRule::allowLabeled('finish', 'plant.wip', 'plant.fg', ['duration' => 5]),
        ]);

        $timed = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 4));

        self::assertSame(
            ['plant.wip@t4'],
            array_map(
                static fn (TimedMovementEdge $edge): string => $edge->to->key,
                $timed->getEdgesFrom($timed->slot('plant.wip', 3)),
            ),
        );
    }

    public function testTimedSlotSpaceCanResolveDurationFromSlotMetadata(): void
    {
        $resolver = new class implements TimedDurationResolverInterface {
            public ?string $seenFrom = null;

            #[\Override]
            public function resolve(MovementEdge $edge, TimedDurationContext $context): int | string
            {
                $this->seenFrom = $context->from->key;

                return (string) ($edge->to->attributes['handling-duration'] ?? '0');
            }
        };
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw', 'wip', 'fg'],
            ],
            timeAxis: TimeAxis::define(bucket: 'hour', horizon: 72, aliases: ['day' => 24]),
        )
            ->setDurationResolver($resolver)
            ->slotRules([
                SlotRule::allow('*'),
                SlotRule::allow('plant.raw', ['handling-duration' => '1d']),
            ])
            ->edgeRules([
                EdgeRule::allowLabeled('ship', 'sup.raw', 'plant.raw'),
            ]);

        $timed = TimedSlotSpace::fromBaseSpace($space);

        $edges = $timed->getEdgesFrom($timed->slot('sup.raw', 0));

        self::assertSame(
            ['sup.raw@h1', 'plant.raw@h24'],
            array_map(static fn (TimedMovementEdge $edge): string => $edge->to->key, $edges),
        );
        self::assertSame('sup.raw@h0', $resolver->seenFrom);
        self::assertSame(24, $edges[1]->attributes['duration']);
    }

    public function testTimedSlotSpaceRejectsInvalidDurationValuesAndCanEnumerateSlotsAtATime(): void
    {
        $space = $this->makeTimedBaseSpace()->edgeRules([
            EdgeRule::allowLabeled('ship', 'sup.raw', 'plant.raw', ['duration' => ['bad']]),
        ]);
        $timed = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('hour', 8));

        try {
            $timed->getEdgesFrom($timed->slot('sup.raw', 0));
            self::fail('Expected invalid duration metadata rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed movement edge duration must be an int or time expression string.', $e->getMessage());
        }

        $spaceWithResolver = $this->makeTimedBaseSpace()
            ->edgeRules([EdgeRule::allowLabeled('ship', 'sup.raw', 'plant.raw')]);
        $timedWithResolver = TimedSlotSpace::fromBaseSpace(
            $spaceWithResolver,
            TimeAxis::define('hour', 8),
            static fn (): array => [],
        );

        try {
            $timedWithResolver->getEdgesFrom($timedWithResolver->slot('sup.raw', 0));
            self::fail('Expected invalid duration resolver rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed movement edge duration must be an int or time expression string.', $e->getMessage());
        }

        $validTimed = TimedSlotSpace::fromBaseSpace($spaceWithResolver, TimeAxis::define('hour', 2));
        $slotKeys = array_map(static fn ($slot): string => $slot->key, $validTimed->slotsAt(1));
        sort($slotKeys);
        self::assertSame(
            ['plant.fg@h1', 'plant.raw@h1', 'plant.wip@h1', 'sup.fg@h1', 'sup.raw@h1', 'sup.wip@h1'],
            $slotKeys,
        );
    }

    public function testTimedQuantityStateCanBeExpandedSplitAndMerged(): void
    {
        $space = $this->makeTimedBaseSpace();
        $timedSpace = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 12));
        $baseState = new QuantityState($space, [
            ['sup.raw', 10],
            ['plant.fg', 2],
        ]);

        $timedState = TimedQuantityState::fromQuantityState($timedSpace, $baseState, 0);

        self::assertSame(10, $timedState->get('sup.raw@t0'));
        self::assertSame(2, $timedState->get('plant.fg@t0'));

        $timedState->add($timedSpace->slot('sup.raw', 0), -4);
        $timedState->add($timedSpace->slot('sup.raw', 1), 4);
        $timedState->add($timedSpace->slot('plant.fg', 1), 2);
        $timedState->add($timedSpace->slot('plant.fg', 1), 3);

        self::assertSame(6, $timedState->get('sup.raw@t0'));
        self::assertSame(4, $timedState->get('sup.raw@t1'));
        self::assertSame(5, $timedState->get('plant.fg@t1'));

        $copy = $timedState->copy();
        $copy->add($timedSpace->slot('plant.fg', 1), 2);

        self::assertSame(5, $timedState->get('plant.fg@t1'));
        self::assertSame(7, $copy->get('plant.fg@t1'));
    }

    public function testTimedQuantityStateAcceptsSerializedTimedTuples(): void
    {
        $space = $this->makeTimedBaseSpace();
        $timedSpace = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 6));
        $timedState = new TimedQuantityState($timedSpace, [
            ['sup.raw@t1', 3],
            ['plant.wip', 2, 4],
        ]);

        self::assertSame(
            ['sup.raw@t1' => 3, 'plant.wip@t4' => 2],
            $timedState->all(),
        );
    }

    public function testTimedQuantityStateRejectsAmbiguousUntimedTuples(): void
    {
        $space = $this->makeTimedBaseSpace();
        $timedSpace = TimedSlotSpace::fromBaseSpace($space, TimeAxis::define('tick', 6));

        try {
            new TimedQuantityState($timedSpace, [['sup.raw', 3]]);
            self::fail('Expected untimed tuple rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame(
                'Timed slot tuples must provide either a TimedSlot, a serialized timed key, or a separate time value.',
                $e->getMessage(),
            );
        }
    }

    public function testTimedQuantityStateAcceptsBaseSlotTuplesAndTimedSlotSpaceRejectsBadDispatchData(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw'],
            ],
            timeAxis: TimeAxis::define('tick', 4),
        )->edgeRules([
            EdgeRule::allow('sup.raw', 'plant.raw'),
        ]);

        $timedSpace = TimedSlotSpace::fromBaseSpace($space, dispatchCalendar: static fn (): int => -1);
        $timedState = new TimedQuantityState($timedSpace, [
            [$space->slot('sup.raw'), 3, 1],
        ]);

        self::assertSame(3, $timedState->get('sup.raw@t1'));

        try {
            $timedSpace->getEdgesFrom($timedSpace->slot('sup.raw', 0));
            self::fail('Expected early-dispatch rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame(
                'Dispatch calendar cannot move a departure earlier than the current timed slot.',
                $e->getMessage(),
            );
        }

        $badDurationSpace = TimedSlotSpace::fromBaseSpace(
            $space,
            durationResolver: static fn (): array => [],
        );

        try {
            $badDurationSpace->getEdgesFrom($badDurationSpace->slot('sup.raw', 0));
            self::fail('Expected invalid duration resolver return type.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed movement edge duration must be an int or time expression string.', $e->getMessage());
        }

        $badDispatchTypeSpace = TimedSlotSpace::fromBaseSpace(
            $space,
            dispatchCalendar: static fn (): string => 'later',
        );

        try {
            $badDispatchTypeSpace->getEdgesFrom($badDispatchTypeSpace->slot('sup.raw', 0));
            self::fail('Expected invalid dispatch calendar return type.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Dispatch calendar must resolve to an integer time index.', $e->getMessage());
        }
    }

    public function testTimedSlotAndTimedMovementEdgeStringHelpers(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['sup', 'plant'],
                'stt' => ['raw'],
            ],
            timeAxis: TimeAxis::define(bucket: 'hour', horizon: 4),
        )->edgeRules([
            EdgeRule::allowLabeled('ship', 'sup.raw', 'plant.raw', ['duration' => 2]),
        ]);
        $timed = TimedSlotSpace::fromBaseSpace($space);
        $edge = $timed->getEdgesFrom($timed->slot('sup.raw', 0))[1];

        self::assertSame('sup.raw@h0', (string) $edge->from);
        self::assertSame('(sup.raw@h0) -> (plant.raw@h2)', (string) $edge);
    }

    private function makeTimedBaseSpace(): SlotSpace
    {
        return SlotSpace::define([
            'loc' => ['sup', 'plant'],
            'stt' => ['raw', 'wip', 'fg'],
        ]);
    }
}
