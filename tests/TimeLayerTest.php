<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\MovementEdge;
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

    public function testSlotSpaceCanStoreTimeAxisAndPassItToTheCodec(): void
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
        self::assertInstanceOf(TimeAwareTestCodec::class, $space->codec);
        self::assertSame($timeAxis, $space->codec->timeAxis);
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
