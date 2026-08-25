<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Batch\BatchItem;
use Nandan108\SlotFlow\Batch\BatchLedgerEntry;
use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;
use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\ExecutionSolverInterface;
use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\ScheduleSolverInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\Contracts\ShipmentSplitRuleInterface;
use Nandan108\SlotFlow\Demand;
use Nandan108\SlotFlow\DemandLine;
use Nandan108\SlotFlow\DemandReleaseContext;
use Nandan108\SlotFlow\DemandScheduler;
use Nandan108\SlotFlow\DemandScheduleRequest;
use Nandan108\SlotFlow\Exceptions\SlotFlowExceptionInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Exceptions\SlotFlowLogicException;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Internal\SlotPattern;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\MovementSchedule;
use Nandan108\SlotFlow\NamedPolicy;
use Nandan108\SlotFlow\Policies\AvailableQuantitySortPolicy;
use Nandan108\SlotFlow\Policies\DimensionPriority;
use Nandan108\SlotFlow\Policies\DistancePolicy;
use Nandan108\SlotFlow\Policies\PartialShipmentPolicy;
use Nandan108\SlotFlow\PolicyBuckets;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\DemandShipmentLine;
use Nandan108\SlotFlow\Results\LedgerEntry;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\Results\ScheduledStep;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\Rules\SlotRuleBase;
use Nandan108\SlotFlow\Runtime\AllocationDecision;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;
use Nandan108\SlotFlow\Time\TimedDurationContext;
use Nandan108\SlotFlow\Time\TimedMovementEdge;
use Nandan108\SlotFlow\Time\TimedSlotSpace;
use Nandan108\SlotFlow\TimelineShipmentPlanner;
use PHPUnit\Framework\TestCase;

final class LibraryCoverageTest extends TestCase
{
    public function testDefaultSlotKeyCodecCoversSerializationValidationAndMatchingBranches(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ]);
        $codec = $space->codec;

        self::assertSame(DefaultSlotKeyCodec::WILDCARD, $codec->wildcard());
        self::assertSame(DefaultSlotKeyCodec::ALTERNATIVE, $codec->alternative());
        self::assertTrue($codec->isWildcard(null));
        self::assertTrue($codec->isWildcard(''));
        self::assertFalse($codec->isWildcard('foo'));
        self::assertSame('nil', $codec->serialize(null));
        self::assertSame('foo.fs', $codec->serialize(['foo', 'fs']));
        self::assertSame('foo.*', $codec->serialize(['foo', null]));
        self::assertNull($codec->deserialize(''));
        self::assertNull($codec->deserialize('nil'));
        self::assertSame(['loc' => 'foo', 'stt' => 'fs'], $codec->deserialize('foo.fs'));
        self::assertSame(['bar'], $codec->matchDimensionValues('loc', 'bar'));
        self::assertSame(['foo', 'bar'], $codec->matchDimensionValues('loc', '*'));

        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Value keys must be a subset of dimension names');
        $codec->serialize(['bad' => 'x']);
    }

    public function testDefaultSlotKeyCodecRejectsInvalidInputs(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ]);
        $codec = $space->codec;

        try {
            $codec->deserialize('foo');
            self::fail('Expected invalid key format exception');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertInstanceOf(SlotFlowExceptionInterface::class, $e);
            self::assertSame(['key' => 'foo', 'dimension_names' => ['loc', 'stt']], $e->debugContext());
            self::assertStringContainsString('does not match the expected format', $e->getMessage());
        }

        try {
            $codec->serialize(['foo']);
            self::fail('Expected invalid tuple length rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame(['expected_dimension_count' => 2, 'received_count' => 1], $e->debugContext());
            self::assertStringContainsString('Slot tuple must have the same number of elements as dimensions', $e->getMessage());
        }

        try {
            $codec->validateDimensionValues(['loc' => ['foo']], allowValueArrays: false);
            self::fail('Expected value array rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame("Array values are not allowed for dimension 'loc'", $e->getMessage());
        }

        try {
            $codec->validateDimensionValue('loc', '*', false);
            self::fail('Expected wildcard rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame("Value for dimension 'loc' cannot be empty or null", $e->getMessage());
        }

        try {
            $codec->validateDimensionValue('loc', 'baz', true);
            self::fail('Expected unknown value rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString("Value 'baz' is not valid for dimension 'loc'", $e->getMessage());
        }

        $codec->validateDimensionValues(['loc' => ['foo', 'bar']], allowWildcards: true, allowValueArrays: true);

        try {
            $codec->validateDimensionValue('loc', 'z*', true);
            self::fail('Expected unmatched wildcard rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString("Unknown loc: 'z*'", $e->getMessage());
        }

        try {
            $codec->matchDimensionValues('bad', '*');
            self::fail('Expected unknown dimension rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Unknown dimension: bad', $e->getMessage());
        }

        try {
            $codec->matchDimensionValues('loc', 'baz');
            self::fail('Expected invalid literal rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString("Value 'baz' is not valid for dimension 'loc'", $e->getMessage());
        }

        try {
            $codec->initialDimensionValueValidation(['loc' => ['bad*']]);
            self::fail('Expected wildcard char rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString("cannot contain wildcard '*'", $e->getMessage());
        }

        try {
            $codec->initialDimensionValueValidation(['loc' => ['bad|alt']]);
            self::fail('Expected alternative char rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString("cannot contain alternative character '|'", $e->getMessage());
        }
    }

    public function testPolicyAndSlotHelpersCoverRemainingAccessorBranches(): void
    {
        $space = SlotSpace::defineTimed([
            'loc' => ['src', 'dest'],
            'stt' => ['fs', 'sd'],
        ], TimeAxis::define('hour', 24))->flow('ship', static fn (Flow $flow) => $flow->move('src.fs', 'dest.sd'));

        $plannerRule = new class implements PlannerRuleInterface, PolicyInterface {
        };
        $shipmentRule = new class implements ShipmentCalendarRuleInterface {
            #[\Override]
            public function releaseTime(
                DemandReleaseContext $context,
                DemandShipmentLine $line,
                ScheduledStep $step,
                int $earliestReleaseTime,
            ): int {
                return $earliestReleaseTime;
            }
        };

        $edge = (new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd'), 'ship'))->meta(
            PolicyBuckets::mergeEdgeAttributes([], [NamedPolicy::as('planner', $plannerRule), $shipmentRule]),
        );

        self::assertCount(2, $edge->policies());
        self::assertCount(2, $edge->plannerRules());
        self::assertCount(1, $edge->shipmentCalendarRules());

        $slot = $space->slot('src.fs')->withMeta(['lane' => 'truck']);
        self::assertTrue($slot->equals($space->slot('src.fs')));
        self::assertTrue(isset($slot['loc']));
        self::assertTrue(isset($slot[0]));
        self::assertFalse(isset($slot['missing']));
        self::assertSame('src', $slot['loc']);
        self::assertSame('src', $slot[0]);
        self::assertSame([], $slot->with(['loc' => 'missing']));

        try {
            $slot['loc'] = 'dest';
            self::fail('Expected immutable slot offsetSet rejection.');
        } catch (\LogicException $e) {
            self::assertSame('Slot dimensions are immutable.', $e->getMessage());
        }

        try {
            unset($slot['loc']);
            self::fail('Expected immutable slot offsetUnset rejection.');
        } catch (\LogicException $e) {
            self::assertSame('Slot dimensions are immutable.', $e->getMessage());
        }

        $timedSpace = TimedSlotSpace::fromBaseSpace($space);
        $timedEdge = new TimedMovementEdge(
            from: $timedSpace->slot('src.fs', 0),
            to: $timedSpace->slot('dest.sd', 1),
            baseEdge: $edge,
            label: 'ship',
            attributes: ['duration' => 1] + PolicyBuckets::mergeEdgeAttributes([], [NamedPolicy::as('planner', $plannerRule)]),
        );
        $scheduled = new ScheduledStep('sched-1', $timedEdge, 1, [NamedPolicy::as('planner', $plannerRule)]);
        self::assertCount(1, $scheduled->plannerRules());

        $event = new MovementEvent($edge, 2, 5, 1);
        self::assertSame(3, $event->finalFrom());
        self::assertSame(3, $event->finalTo());
        /** @psalm-suppress DeprecatedMethod */
        self::assertSame(2, $event->ledgerEntry(['source' => 'test'])->quantity);

        $inventory = new QuantityState($space, [[$space->slot('src.fs')->withMeta(['bin' => 'A']), 2, ['bin' => 'A']]]);
        self::assertSame(['src.fs' => ['bin' => 'A']], $inventory->allSlotAttributes());

        $splitRule = new class implements ShipmentSplitRuleInterface {
        };
        self::assertSame([], PolicyBuckets::planner([]));
        self::assertSame([], PolicyBuckets::shipmentCalendar([]));
        self::assertSame([], PolicyBuckets::shipmentSplit([]));
        self::assertSame([], PolicyBuckets::all([]));
        self::assertSame([], PolicyBuckets::resolveCategory([], PolicyBuckets::matchesAny(...)));
        self::assertSame(['x' => 1], PolicyBuckets::mergeEdgeAttributes(['x' => 1], []));

        try {
            PolicyBuckets::mergeEdgeAttributes([], [$plannerRule, new class implements PolicyInterface {
            }]);
            self::fail('Expected non-planner edge policy rejection.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('EdgeRule::policies() only accepts planner policies.', $e->getMessage());
        }

        $flow = Flow::define('bucket-flow', static fn (Flow $flow) => $flow->move('src.fs', 'dest.sd'));
        $step = $flow->steps()[0];
        PolicyBuckets::applyToStep($step, []);
        PolicyBuckets::applyToStep($step, [NamedPolicy::as('planner', $plannerRule), $shipmentRule, $splitRule]);
        self::assertCount(3, $step->plannerPolicies);
        self::assertCount(1, $step->shipmentCalendarPolicies);
        self::assertCount(1, $step->shipmentSplitPolicies);
    }

    /** @psalm-type TRow array{variant: non-empty-string, loc: non-empty-string, qty: int} $rows */
    public function testInventoryBatchAndBatchItemCoverFactoryAndMutationGuards(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs', 'sd'],
        ]);

        $inventory = new QuantityState($space);
        $inventory->setSlotQtty($space->slot('foo.fs'), 3);
        self::assertSame(['foo.fs' => 3], $inventory->all());

        $copy = $inventory->copy();
        $copy->add($space->slot('foo.fs'), 2);
        self::assertSame(3, $inventory->get($space->slot('foo.fs')));
        self::assertSame(3, $inventory->get('foo.fs'));
        self::assertSame(5, $copy->get($space->slot('foo.fs')));

        $inventory->addFromRows(
            [['loc' => 'foo', 'fs' => 1, 'sd' => 2]],
            static fn (array $row): array => [
                [['loc' => $row['loc'], 'stt' => 'fs'], $row['fs']],
                [['loc' => $row['loc'], 'stt' => 'sd'], $row['sd']],
            ],
        );

        self::assertSame(4, $inventory->get($space->slot('foo.fs')));
        self::assertSame(4, $inventory->get(['loc' => 'foo', 'stt' => 'fs']));
        self::assertSame(4, $inventory->get(['foo', 'fs']));
        self::assertSame(2, $inventory->get($space->slot('foo.sd')));
        self::assertSame(0, $inventory->get(null));
        self::assertSame(6, $inventory->getSum($space->slot('foo.fs'), 'foo.sd'));
        self::assertSame(6, $inventory->getSum(['foo', null]));
        self::assertSame(6, $inventory->getSum('foo.*'));
        self::assertSame(6, $inventory->getSum('foo.fs|sd'));
        self::assertSame(6, $inventory->getSum('foo.*', 'foo.fs'));
        $inventory->setTuple([['foo.fs', 7]]);
        self::assertSame(7, $inventory->get('foo.fs'));
        $inventory->setTuple([[['loc' => 'foo', 'stt' => 'fs'], 8]]);
        self::assertSame(8, $inventory->get('foo.fs'));

        $batch = QuantityStateBatch::fromRows(
            space: $space,
            rows: [['variant' => 'A', 'loc' => 'foo', 'qty' => 2]],
            /** @param TRow $row */
            subjectGetter: static fn (array $row): string => $row['variant'],
            /** @param TRow $row */
            slotRowGetter: static fn (array $row): array => [
                [$space->slot([$row['loc'], 'fs']), $row['qty']],
            ],
            /** @param list<TRow> $rows */
            quantityGetter: static fn (array $rows): int => $rows[0]['qty'],
        );

        self::assertSame('A', $batch->items()[0]->subject);
        self::assertSame(2, $batch->items()[0]->inventory->get($space->slot('foo.fs')));

        try {
            $inventory->setTuple([[['loc' => 'foo'], 1]]);
            self::fail('Expected ambiguous inventory tuple pattern rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Inventory tuple slot pattern must resolve to exactly one slot.', $e->getMessage());
            self::assertSame(['foo.fs', 'foo.sd'], $e->debugContext()['matched_slots']);
        }

        $prunedSpace = SlotSpace::define([
            'loc' => ['foo'],
            'stt' => ['fs', 'sd'],
        ])->slotRules([SlotRule::allow('foo.fs')], SlotRuleBase::None);

        try {
            (new QuantityState($prunedSpace))->setTuple([['foo.sd', 1]]);
            self::fail('Expected unknown inventory tuple slot rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Unknown slot: "foo.sd"', $e->getMessage());
            self::assertSame(['slot' => 'foo.sd'], $e->debugContext());
        }

        $result = new MovementResult([], 1);
        $item = new BatchItem('A', 1, new QuantityState($space));
        $item->setMovementResult($result);
        self::assertSame($result, $item->movementResult());

        try {
            $item->setMovementResult($result);
            self::fail('Expected duplicate result guard');
        } catch (SlotFlowLogicException $e) {
            self::assertInstanceOf(SlotFlowExceptionInterface::class, $e);
            self::assertSame(['has_existing_result' => true], $e->debugContext());
            self::assertSame('Movement result already set', $e->getMessage());
        }

        $space->subjectKeyResolver(static fn (): string => '');
        $this->expectException(SlotFlowInvalidArgumentException::class);
        $this->expectExceptionMessage('Subject key must be a non-empty string.');
        /** @psalm-suppress InvalidArgument */
        QuantityStateBatch::fromRows(
            $space,
            [['variant' => 'A', 'loc' => 'foo', 'qty' => 2]],
            /** @param TRow $row */
            static fn (array $row): string => $row['variant'],
            /** @param TRow $row */
            static fn (array $row): array => [
                [['loc' => $row['loc'], 'stt' => 'fs'], $row['qty']],
            ],
            /** @param list<TRow> $rows */
            static fn (array $rows): int => $rows[0]['qty'],
        );
    }

    public function testCascadeAndBuilderStorePoliciesAndHandleReversalModes(): void
    {
        $orderedBy = static fn (FlowContext $ctx): array => $ctx->edges;
        $secondaryOrder = static fn (FlowContext $ctx): array => array_reverse($ctx->edges);
        $constraint = static fn (MovementEdge $edge, FlowContext $ctx): int => 1;

        $cascade = Flow::define('build', static fn (Flow $cascade) => $cascade
            ->move('foo.fs', 'foo.sd')
            ->orderBy($orderedBy, $secondaryOrder)
            ->constraint($constraint)
            ->move('bar.fs', 'bar.sd'));

        self::assertSame('build', $cascade->name());
        self::assertCount(2, $cascade->steps());
        self::assertSame([$secondaryOrder, $orderedBy], $cascade->steps()[0]->orderingPolicies);
        self::assertSame([$constraint], $cascade->steps()[0]->quantityConstraintPolicies);

        $same = $cascade->reverseIf(false);
        self::assertNotSame($cascade, $same);
        self::assertSame($cascade->steps(), $same->steps());

        $reversed = $cascade->reverseIf(true, false);
        self::assertSame('foo.fs', $cascade->steps()[0]->from);
        self::assertSame('bar.fs', $reversed->steps()[0]->from);
        self::assertSame('bar.sd', $reversed->steps()[0]->to);
    }

    public function testMovementPrimitivesExposeDerivedValues(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ]);
        $edge = new MovementEdge($space->slot('foo.fs'), $space->slot('bar.sd'));
        $event = new MovementEvent($edge, 2, 5, 1);
        $nilEvent = new MovementEvent(new MovementEdge($space->nilSlot(), $space->slot('foo.fs')), 2, null, null);
        $complete = new MovementResult([$event], 0);
        $incomplete = new MovementResult([], 1);

        self::assertSame('(foo.fs) -> (bar.sd)', (string) $edge);
        self::assertSame(['x' => 1], $edge->meta(['x' => 1])->attributes);
        self::assertSame(['x' => 1], $space->slot('foo.fs')->withMeta(['x' => 1])->attributes);
        self::assertSame(3, $event->finalFrom());
        self::assertSame(3, $event->finalTo());
        self::assertNull($nilEvent->finalFrom());
        self::assertNull($nilEvent->finalTo());
        self::assertCount(2, $event->deltas());
        self::assertSame('foo.fs', $event->deltas()[0]->slot->key);
        self::assertSame(-2, $event->deltas()[0]->delta);
        self::assertSame('bar.sd', $event->deltas()[1]->slot->key);
        self::assertSame(2, $event->deltas()[1]->delta);
        self::assertCount(1, $nilEvent->deltas());
        self::assertSame('foo.fs', $nilEvent->deltas()[0]->slot->key);
        self::assertSame(2, $nilEvent->deltas()[0]->delta);
        self::assertSame(['ref' => 'abc'], $event->ledgerEntry(['ref' => 'abc'])->context);
        self::assertSame(3, $event->ledgerEntry()->finalFrom());
        self::assertSame(3, $event->ledgerEntry()->finalTo());
        self::assertTrue($complete->isComplete());
        self::assertFalse($incomplete->isComplete());
        self::assertCount(2, $complete->deltas());
        self::assertSame('foo.fs', $complete->deltas()[0]->slot->key);
        self::assertSame(-2, $complete->deltas()[0]->delta);
        self::assertSame('bar.sd', $complete->deltas()[1]->slot->key);
        self::assertSame(2, $complete->deltas()[1]->delta);
        self::assertSame(['ref' => 'abc'], $complete->ledgerEntries(['ref' => 'abc'])[0]->context);
    }

    public function testInventoryBatchProvidesOutgressHelpers(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ]);

        $batch = new QuantityStateBatch([
            new BatchItem('A', 2, new QuantityState($space)),
            new BatchItem('B', 1, new QuantityState($space)),
            new BatchItem('C', 4, new QuantityState($space)),
        ]);

        $aEvent = new MovementEvent(new MovementEdge($space->slot('foo.fs'), $space->slot('bar.sd')), 2, 5, 1);
        $bEvent = new MovementEvent(new MovementEdge($space->nilSlot(), $space->slot('foo.fs')), 1, null, 2);

        $aResult = new MovementResult([$aEvent], 0);
        $bResult = new MovementResult([$bEvent], 0);

        $batch->items()[0]->setMovementResult($aResult);
        $batch->items()[1]->setMovementResult($bResult);

        $mutations = $batch->deltas();
        self::assertCount(3, $mutations);
        self::assertSame('A', $mutations[0]->subject);
        self::assertSame('foo.fs', $mutations[0]->slot->key);
        self::assertSame(-2, $mutations[0]->delta);
        self::assertSame('A', $mutations[1]->subject);
        self::assertSame('bar.sd', $mutations[1]->slot->key);
        self::assertSame(2, $mutations[1]->delta);
        self::assertSame('B', $mutations[2]->subject);
        self::assertSame('foo.fs', $mutations[2]->slot->key);
        self::assertSame(1, $mutations[2]->delta);

        $entries = $batch->ledgerEntries(['operationId' => 'op-1']);
        self::assertCount(2, $entries);
        self::assertSame('A', $entries[0]->subject);
        self::assertSame('(foo.fs) -> (bar.sd)', (string) $entries[0]->edge);
        self::assertSame('foo.fs', $entries[0]->edge->from->key);
        self::assertSame('bar.sd', $entries[0]->edge->to->key);
        self::assertSame(3, $entries[0]->finalFrom());
        self::assertSame(3, $entries[0]->finalTo());
        self::assertSame(['operationId' => 'op-1'], $entries[0]->context);
        self::assertSame('B', $entries[1]->subject);
        self::assertSame('(nil) -> (foo.fs)', (string) $entries[1]->edge);
    }

    public function testMovementResultsCollapseOppositeEventsAndBatchOutgressSkipsUntouchedItems(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ]);

        $forward = new MovementEvent(new MovementEdge($space->slot('foo.fs'), $space->slot('bar.sd')), 2, 5, 1);
        $reverse = new MovementEvent(new MovementEdge($space->slot('bar.sd'), $space->slot('foo.fs')), 2, 3, 3);
        $balanced = new MovementResult([$forward, $reverse], 0);

        self::assertSame([], $balanced->deltas());

        $entryWithNilSink = new LedgerEntry(
            new MovementEdge($space->slot('foo.fs'), $space->nilSlot()),
            2,
            5,
            null,
        );
        self::assertSame(3, $entryWithNilSink->finalFrom());
        self::assertNull($entryWithNilSink->finalTo());
        self::assertNull((new LedgerEntry(
            new MovementEdge($space->nilSlot(), $space->slot('foo.fs')),
            1,
            null,
            2,
        ))->finalFrom());

        $batchEntryWithNilSource = new BatchLedgerEntry(
            'A',
            new MovementEdge($space->nilSlot(), $space->slot('foo.fs')),
            1,
            null,
            2,
        );
        self::assertNull($batchEntryWithNilSource->finalFrom());
        self::assertSame(3, $batchEntryWithNilSource->finalTo());
        self::assertNull((new BatchLedgerEntry(
            'B',
            new MovementEdge($space->slot('foo.fs'), $space->nilSlot()),
            2,
            5,
            null,
        ))->finalTo());

        $batch = new QuantityStateBatch([
            new BatchItem('A', 2, new QuantityState($space)),
            new BatchItem('B', 2, new QuantityState($space)),
        ]);
        $batch->items()[0]->setMovementResult($balanced);

        self::assertSame([], $batch->deltas());
        self::assertCount(2, $batch->ledgerEntries(['op' => 'balanced']));
    }

    public function testInventoryPreservesPerSlotAttributesForConstraintPolicies(): void
    {
        /** @psalm-type TIfsRow = array{variant: non-empty-string, qty: int, ifs: int, inv: array{fs: int, sd: int}} */
        $space = SlotSpace::define([
            'loc'   => ['sup', 'wh1'],
            'own'   => ['CS'],
            'stt'   => ['fs', 'sd'],
        ]);

        $batch = QuantityStateBatch::fromRows(
            space: $space,
            /** @var list<TIfsRow> */
            rows: [[
                'variant' => 'A',
                'qty'     => 3,
                'ifs'     => 4,
                'inv'     => ['fs' => 3, 'sd' => 5],
            ]],
            /** @param TIfsRow $row */
            subjectGetter: static fn (array $row): string => $row['variant'],
            /** @param TIfsRow $row */
            slotRowGetter: static fn (array $row): array => [
                [$space->slot('wh1.CS.fs')->withMeta(['ifs' => $row['ifs']]), $row['inv']['fs']],
                [$space->slot('sup.CS.sd'), $row['inv']['sd']],
            ],
            /** @param list<TIfsRow> $rows */
            quantityGetter: static fn (array $rows): int => $rows[0]['qty'],
        );

        $cascade = Flow::define('cancel', static fn (Flow $cascade) => $cascade
            ->move('sup.CS.sd', 'wh1.CS.fs')
            ->constraint(static function (MovementEdge $edge, FlowContext $ctx): int | float {
                $attrs = $ctx->slotAttributes($edge->to);
                // initial consignment for-sale quantity
                $ifs = (int) ($attrs['ifs'] ?? 0);

                // cover direct attribute access and helper method branches
                self::assertSame($ifs, $ctx->slotAttribute($edge->to, 'ifs', 0));

                // target capacity is initial consignment minus current inventory on the slot
                $targetCapacity = $ifs - (int) $ctx->inventory->get($edge->to);

                return max(0, $targetCapacity);
            }));

        (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $space,
            cascade: $cascade,
        );

        $result = $batch->items()[0]->movementResult();
        self::assertNotNull($result);
        self::assertSame(2, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame('(sup.CS.sd) -> (wh1.CS.fs)', (string) $result->events[0]->edge);
        self::assertSame(1, $result->events[0]->quantity);
        self::assertSame(['ifs' => 4], $batch->items()[0]->inventory->slotAttributes($space->slot('wh1.CS.fs')));
    }

    public function testSlotPrimitivesAndRulesCoverHelpers(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ])->edgeRules(EdgeRule::connect('foo.fs', 'bar.sd')->all());
        $unlabeled = EdgeRule::allow('foo.fs', 'bar.sd');

        $foo = $space->slot('foo.fs');
        $bar = $space->slot('bar.sd');
        $nilPattern = SlotPattern::from(null, $space);

        self::assertTrue($foo->equals($space->slot('foo.fs')));
        self::assertFalse($foo->equals($bar));
        self::assertNull($unlabeled->label);
        self::assertSame(['capability' => 'hazmat'], SlotRule::allow('foo.fs')->meta(['capability' => 'hazmat'])->attributes);
        self::assertSame(['bar.fs'], array_map(static fn (Slot $slot): string => $slot->key, $space->nilSlot()->with(['loc' => 'bar', 'stt' => 'fs'])));
        self::assertSame([], $foo->with(['loc' => 'baz']));
        self::assertTrue($nilPattern->matches($space->nilSlot()));
        self::assertFalse($nilPattern->matches($foo));
        self::assertCount(2, SlotRule::denyAll(['foo.fs', 'bar.sd']));
        self::assertArrayHasKey('bar.sd', $space->getEdgesFrom($foo));
    }

    public function testSlotSpaceAndCascadeCoverRemainingFeatureBranches(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allowLabeled('ship', 'foo.fs', 'bar.sd'),
            EdgeRule::allow('bar.fs', 'foo.sd'),
        ]);

        self::assertSame(
            ['loc' => ['foo', 'bar'], 'stt' => ['fs', 'sd']],
            $space->dimensions(),
        );
        self::assertSame(
            [['loc' => 'foo']],
            $space->expandSlotPattern(['foo', null]),
        );
        self::assertSame(['foo.fs'], array_map(
            static fn (MovementEdge $edge): string => $edge->from->key,
            $space->edgesByLabels(['ship']),
        ));

        $assoc = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ]);
        $assoc->flow('assoc', [['from' => 'foo.fs', 'to' => 'bar.sd']]);
        self::assertSame('foo.fs', $assoc->getFlow('assoc')->steps()[0]->from);
        self::assertSame('bar.sd', $assoc->getFlow('assoc')->steps()[0]->to);

        $destroyCascade = Flow::define('destroyer', static fn (Flow $cascade) => $cascade
            ->stepByLabeledEdges('ship')
            ->destroy('bar.sd'));
        self::assertNull($destroyCascade->steps()[1]->to);
        self::assertSame('bar.sd', $destroyCascade->steps()[1]->from);

        try {
            $space->validateKnownDimensionNames(['bad1', 'bad2']);
            self::fail('Expected multiple unknown dimensions rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Invalid slot values: unknown dimensions: [bad1, bad2]', $e->getMessage());
        }

        try {
            $space->expandSlotPattern(['foo']);
            self::fail('Expected tuple length rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString('same number of elements as dimensions', $e->getMessage());
        }

        try {
            $space->slot(['loc' => 'foo', 'extra' => 'oops']);
            self::fail('Expected invalid slot array rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertStringContainsString('missing dimensions: [stt]', $e->getMessage());
            self::assertStringContainsString('unknown dimensions: [extra]', $e->getMessage());
        }

        try {
            (new Flow('empty'))->stepByLabeledEdges();
            self::fail('Expected empty label rejection');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('At least one edge label is required', $e->getMessage());
        }
    }

    public function testPoliciesOrderAndFilterEdges(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'dest'],
            'own'   => ['CS', 'FP'],
            'stt'   => ['fs', 'sd'],
        ]);

        $edges = [
            new MovementEdge($space->slot('b.CS.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->slot('a.FP.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->slot('a.CS.fs'), $space->slot('dest.CS.sd')),
        ];
        $context = new FlowContext($space, $edges, new QuantityState($space), 1, null, [
            'distance' => [
                'b.CS.fs->dest.CS.sd' => 20,
                'a.FP.fs->dest.CS.sd' => 10,
                'a.CS.fs->dest.CS.sd' => 5,
            ],
        ]);

        $priority = new DimensionPriority([
            'loc' => ['a', 'b'],
            'own' => ['CS', 'FP'],
        ]);
        $ordered = $priority->orderEdges($context);
        self::assertSame(['a.CS.fs', 'a.FP.fs', 'b.CS.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key, $ordered));

        $tiedEdges = [
            new MovementEdge($space->slot('dest.CS.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->slot('dest.FP.fs'), $space->slot('dest.CS.sd')),
        ];
        $tied = $priority->orderEdges(new FlowContext($space, $tiedEdges, new QuantityState($space), 1));
        self::assertSame(['dest.CS.fs', 'dest.FP.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key, $tied));

        $equalRankEdges = [
            new MovementEdge($space->slot('a.CS.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->slot('a.CS.fs'), $space->slot('dest.FP.sd')),
        ];
        $equalRank = $priority->orderEdges(new FlowContext($space, $equalRankEdges, new QuantityState($space), 1));
        self::assertSame(['dest.CS.sd', 'dest.FP.sd'], array_map(static fn (MovementEdge $edge): string => $edge->to->key, $equalRank));

        $patternPriority = new DimensionPriority([
            'loc' => ['a|b', 'dest'],
            'own' => ['CS', 'FP'],
        ]);
        $groupedPatternEdges = [
            new MovementEdge($space->slot('b.CS.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->slot('a.FP.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->slot('dest.CS.fs'), $space->slot('dest.CS.sd')),
        ];
        $groupedPatternOrdered = $patternPriority->orderEdges(new FlowContext($space, $groupedPatternEdges, new QuantityState($space), 1));
        self::assertSame(
            ['b.CS.fs', 'a.FP.fs', 'dest.CS.fs'],
            array_map(static fn (MovementEdge $edge): string => $edge->from->key, $groupedPatternOrdered),
        );

        $distancePolicy = new DistancePolicy(max: 10);
        $filtered = $distancePolicy->filterEdges($context);
        self::assertSame(['a.FP.fs', 'a.CS.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key, $filtered));

        $availableInventory = new AvailableQuantitySortPolicy();
        $inventorySorted = $availableInventory->orderEdges(new FlowContext($space, $edges, new QuantityState($space, [
            ['a.CS.fs', 1],
            ['a.FP.fs', 8],
            ['b.CS.fs', 3],
        ]), 1));
        self::assertSame(['a.FP.fs', 'b.CS.fs', 'a.CS.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key, $inventorySorted));
        $nilPreferred = $availableInventory->orderEdges(new FlowContext($space, [
            new MovementEdge($space->slot('a.CS.fs'), $space->slot('dest.CS.sd')),
            new MovementEdge($space->nilSlot(), $space->slot('dest.CS.sd')),
        ], new QuantityState($space, [
            ['a.CS.fs', 99],
        ]), 1));
        self::assertFalse($nilPreferred[0]->from->isNil());
        self::assertTrue($nilPreferred[1]->from->isNil());

        $orderedByDistance = (new DistancePolicy())->orderEdges(new FlowContext($space, $edges, new QuantityState($space), 1, null, [
            'distance' => static fn (MovementEdge $edge): int => match ($edge->from->key) {
                'b.CS.fs' => 3,
                'a.FP.fs' => 2,
                default   => 1,
            },
        ]));
        self::assertSame(['a.CS.fs', 'a.FP.fs', 'b.CS.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key, $orderedByDistance));
        self::assertSame($edges, (new DistancePolicy())->filterEdges(new FlowContext($space, $edges, new QuantityState($space), 1)));
        self::assertSame(['b.CS.fs', 'a.FP.fs', 'a.CS.fs'], array_map(
            static fn (MovementEdge $edge): string => $edge->from->key,
            (new DistancePolicy(max: 1))->orderEdges(new FlowContext($space, $edges, new QuantityState($space), 1)),
        ));
    }

    /** @psalm-suppress InvalidPropertyAssignmentValue */
    public function testMovementEngineCoversCallableAndInterfacePolicyPaths(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'cS', 'dest'],
            'stt'   => ['fs', 'sd'],
        ]);
        $inventory = new QuantityState($space, [
            ['a.fs', 4],
            ['b.fs', 4],
        ]);

        $cascade = Flow::define('policy-branches', static fn (Flow $cascade) => $cascade
            ->move('a|b.fs', 'dest.sd')
            ->filter(new class implements EdgeFilterPolicyInterface {
                public function filterEdges(FlowContext $ctx): array
                {
                    return $ctx->edges;
                }
            })
            ->filter(static fn (FlowContext $ctx): array => array_reverse($ctx->edges))
            ->orderBy(new class implements EdgeOrderingPolicyInterface {
                public function orderEdges(FlowContext $ctx): array
                {
                    return array_reverse($ctx->edges);
                }
            })
            ->constraint(new class implements QttyConstraintPolicyInterface {
                public function constraint(MovementEdge $edge, FlowContext $ctx): int | float
                {
                    return 'a.fs' === $edge->from->key ? 1 : 99;
                }
            })
            ->constraint(static fn (MovementEdge $edge, FlowContext $ctx): string => 'skip')
            ->allocate(new class implements AllocationPolicyInterface {
                public function allocate(FlowContext $ctx): array
                {
                    return [
                        new AllocationDecision($ctx->edges[0], 2),
                        new AllocationDecision($ctx->edges[1], 2),
                    ];
                }
            })
            ->move('dest.sd', null));

        $step = $cascade->steps()[0];
        $step->filterPolicies[] = new \stdClass();
        $step->orderingPolicies[] = static fn (FlowContext $ctx): array => $ctx->edges;
        $step->orderingPolicies[] = new \stdClass();
        $step->quantityConstraintPolicies[] = new \stdClass();
        $step->allocationPolicies[] = new \stdClass();

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 3);

        self::assertSame(0, $result->remaining);
        self::assertCount(2, $result->events);
        self::assertSame('a.fs', $result->events[0]->edge->from->key);
        self::assertSame(1, $result->events[0]->quantity);
        self::assertSame('b.fs', $result->events[1]->edge->from->key);
        self::assertSame(2, $result->events[1]->quantity);
    }

    public function testMovementEngineCoversDecisionLoopContinueAndBreakBranches(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'c', 'dest'],
            'state' => ['fs', 'sd'],
        ]);
        $inventory = new QuantityState($space, [
            ['a.fs', 1],
            ['b.fs', 2],
            ['c.fs', 0],
        ]);

        $cascade = Flow::define('decisions', static fn (Flow $cascade) => $cascade
            ->move('a|b|c.fs', 'dest.sd')
            ->allocate(new class implements AllocationPolicyInterface {
                public function allocate(FlowContext $ctx): array
                {
                    $byFrom = [];
                    foreach ($ctx->edges as $edge) {
                        $byFrom[$edge->from->key] = $edge;
                    }

                    return [
                        new AllocationDecision($byFrom['c.fs'], 1),
                        new AllocationDecision($byFrom['a.fs'], 1),
                        new AllocationDecision($byFrom['b.fs'], 2),
                        new AllocationDecision($byFrom['a.fs'], 1),
                    ];
                }
            }));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 3);

        self::assertSame(0, $result->remaining);
        self::assertCount(2, $result->events);
        self::assertSame('a.fs', $result->events[0]->edge->from->key);
        self::assertSame('b.fs', $result->events[1]->edge->from->key);
    }

    public function testMovementEngineCanFilterEdgesUsingSubjectContext(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'dest'],
            'state' => ['fs', 'sd'],
        ]);
        $inventory = new QuantityState($space, [
            ['a.fs', 3],
            ['b.fs', 3],
        ]);

        $cascade = Flow::define('subject-filter', static fn (Flow $cascade) => $cascade
            ->move('a|b.fs', 'dest.sd')
            ->filter(static function (FlowContext $ctx): array {
                $allowedSources = is_array($ctx->subject) ? ($ctx->subject['allowed_sources'] ?? []) : [];
                if (!is_array($allowedSources) || [] === $allowedSources) {
                    return $ctx->edges;
                }

                return array_values(array_filter(
                    $ctx->edges,
                    static fn (MovementEdge $edge): bool => in_array($edge->from->dimension('loc'), $allowedSources, true),
                ));
            }));

        $space->subjectKeyResolver(static fn (array $subject): string => 'allowed-sources');
        $result = (new MovementEngine())->execute(
            $inventory,
            $space,
            $cascade,
            2,
            ['allowed_sources' => ['b']],
        );

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame('b.fs', $result->events[0]->edge->from->key);
    }

    public function testMovementEngineResolvesParamsInsideArrayPatternsWithoutTouchingNullWildcards(): void
    {
        $space = SlotSpace::define([
            'loc' => ['a', 'b', 'dest'],
            'stt' => ['fs', 'sd'],
        ]);
        $inventory = new QuantityState($space, [
            ['a.fs', 2],
            ['b.fs', 3],
        ]);

        $cascade = Flow::define('param-array-pattern', static fn (Flow $cascade) => $cascade
            ->move(['loc' => '{loc}', 'stt' => null], ['loc' => 'dest', 'stt' => 'sd']));

        $result = (new MovementEngine())->execute(
            inventory: $inventory,
            space: $space,
            cascade: $cascade,
            quantity: 2,
            params: ['loc' => 'a'],
        );

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame('(a.fs) -> (dest.sd)', (string) $result->events[0]->edge);
    }

    public function testMovementEngineBreaksEarlyAndCoversSlotSpaceErrorBranches(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
            'empty' => [],
        ]);
        $inventory = new QuantityState($space, [[['foo', 'fs', '*'], 1]]);
        $cascade = Flow::define('noop', static fn (Flow $cascade) => $cascade
            ->move('foo.fs.*', 'bar.sd.*')
            ->move('bar.sd.*', null));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 0);
        self::assertSame([], $result->events);

        $denyFirst = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ])->slotRules([
            SlotRule::deny('foo.fs'),
        ]);
        $sameSpace = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs'],
        ]);

        self::assertNull($denyFirst->trySlot('foo.fs'));
        self::assertNotNull($denyFirst->trySlot('bar.fs'));
        self::assertSame([], $denyFirst->getEdgesFrom($denyFirst->slot('bar.fs')));
        self::assertSame($sameSpace, $sameSpace->slotRules([]));
        self::assertSame([[]], $denyFirst->expandSlotPattern(['stt' => '*']));
        self::assertSame('foo.fs', SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ])->slot(['foo', 'fs'])->key);
        self::assertSame($denyFirst->nilSlot(), $denyFirst->trySlot('nil'));
        self::assertSame('test', SlotSpace::define(['kind' => ['test']])->dimensionValues('kind')[0]);

        try {
            $denyFirst->dimensionValues('bad');
            self::fail('Expected unknown dimension');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Unknown dimension: bad', $e->getMessage());
        }

        try {
            $denyFirst->expandSlotPattern(['bad' => 'x']);
            self::fail('Expected unknown dimension in pattern');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Unknown dimension: bad', $e->getMessage());
        }

        try {
            $denyFirst->getFlow('missing');
            self::fail('Expected missing flow');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame("Flow 'missing' not defined", $e->getMessage());
        }

        $cachedEdgesSpace = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ])->edgeRules([
            EdgeRule::allowLabeled(null, 'foo.fs', 'bar.sd'),
        ]);
        $first = $cachedEdgesSpace->getEdgesFrom($cachedEdgesSpace->slot('foo.fs'));
        $second = $cachedEdgesSpace->getEdgesFrom($cachedEdgesSpace->slot('foo.fs'));
        self::assertSame($first, $second);
    }

    public function testFlowAndQuantityStateProvideGenericAliases(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ])->flow('transfer', static fn (Flow $flow) => $flow->move('foo.fs', 'bar.sd'));

        $state = new QuantityState($space, [['foo.fs', 2]]);
        $result = (new MovementEngine())->execute(
            inventory: $state,
            space: $space,
            cascade: 'transfer',
            quantity: 2,
        );

        self::assertSame(0, $result->remaining);
        self::assertSame(0, $state->get('foo.fs'));
        self::assertSame(2, $state->get('bar.sd'));
        self::assertSame('transfer', $space->getFlow('transfer')->name());
    }

    public function testMovementEngineAcceptsInjectedSolver(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo'],
            'stt' => ['fs'],
        ]);
        $state = new QuantityState($space, [['foo.fs', 3]]);
        $flow = Flow::define('noop', static fn (Flow $f) => $f);

        $engine = new MovementEngine(new class implements ExecutionSolverInterface {
            public function execute(
                QuantityState $state,
                SlotSpace $space,
                Flow $flow,
                int | float $quantity,
                mixed $subject = null,
                array $appContext = [],
                array $params = [],
            ): MovementResult {
                return new MovementResult([], 99);
            }
        });

        $result = $engine->execute($state, $space, $flow, 3);

        self::assertSame(99, $result->remaining);
        self::assertSame(3, $state->get('foo.fs'));
    }

    public function testDemandSchedulerAndTimelineShipmentPlannerCoverRemainingArrivalBranches(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'other', 'cust'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 6),
        )->flow('ship', static fn (Flow $flow) => $flow->move('src.fs', 'cust.sd'));

        $timedSpace = TimedSlotSpace::fromBaseSpace($space);
        $offTarget = new TimedMovementEdge(
            $timedSpace->slot('src.fs', 0),
            $timedSpace->slot('other.sd', 1),
            new MovementEdge($space->slot('src.fs'), $space->slot('other.sd')),
        );
        $targetHold = new TimedMovementEdge(
            $timedSpace->slot('cust.sd', 0),
            $timedSpace->slot('cust.sd', 2),
            null,
            'hold',
            ['duration' => 2],
        );
        $movementSchedule = new MovementSchedule([
            new ScheduledStep('sched-off-target', $offTarget, 1),
            new ScheduledStep('sched-hold', $targetHold, 1),
        ], 0);

        $scheduler = new DemandScheduler(new class($movementSchedule) implements ScheduleSolverInterface {
            public function __construct(private readonly MovementSchedule $schedule)
            {
            }

            public function schedule(ScheduleRequest $request): MovementSchedule
            {
                return $this->schedule;
            }
        });

        $request = new DemandScheduleRequest(
            demand: new Demand([new DemandLine('sku', 1)]),
            space: $space,
            flow: 'ship',
            target: 'cust.sd',
            statesBySubjectKey: ['sku' => new QuantityState($space, [['src.fs', 1]])],
            releasePolicy: new PartialShipmentPolicy(),
        );

        $schedule = $scheduler->schedule($request);

        self::assertCount(1, $schedule->lines[0]->arrivals);
        self::assertSame(2, $schedule->lines[0]->arrivals[0]->time);
        self::assertSame([2], array_map(static fn ($shipment): int => $shipment->releaseTime, $schedule->shipments));
        self::assertSame([], (new TimelineShipmentPlanner())->plan($request, []));
    }

    /** @psalm-suppress InvalidArgument, DeprecatedMethod */
    public function testSlotSpaceAndResultGuardsCoverRemainingBranches(): void
    {
        $space = SlotSpace::defineTimed(
            dimensions: [
                'loc' => ['src', 'dest'],
                'stt' => ['fs', 'sd'],
            ],
            timeAxis: TimeAxis::define('hour', 4),
        );

        $overridden = SlotPattern::from(null, $space)->partialOverride('src.fs')->expand();
        self::assertSame(['src.fs'], array_keys($overridden));
        self::assertSame('true', $space->subjectKey(true));
        self::assertSame('false', $space->subjectKey(false));
        self::assertSame('stringable', $space->subjectKey(new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        }));
        self::assertSame($space->slot('src.fs'), $space->trySlot($space->slot('src.fs')));
        self::assertSame($space, $space->edgeRules([]));

        try {
            new LedgerEntry(new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd')), 1, null, 0);
            self::fail('Expected missing source quantity to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('initialFrom must be provided for non-nil sources', $e->getMessage());
        }

        try {
            new LedgerEntry(new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd')), 1, 0, null);
            self::fail('Expected missing destination quantity to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('initialTo must be provided for non-nil sinks', $e->getMessage());
        }

        $event = new MovementEvent(new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd')), 1, 2, 3);
        self::assertCount(2, $event->mutations());

        try {
            /** @psalm-suppress InvalidArgument */
            $space->setDurationResolver(new \stdClass());
            self::fail('Expected invalid duration resolver.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame(
                'Timed duration resolver must be a Closure or TimedDurationResolverInterface instance.',
                $e->getMessage(),
            );
        }

        $space->setDurationResolver(static fn (): array => []);
        $durationResolver = $space->getDurationResolver();
        $context = new TimedDurationContext(
            TimedSlotSpace::fromBaseSpace($space),
            $space->timeAxis,
            TimedSlotSpace::fromBaseSpace($space)->slot('src.fs', 0),
            new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd')),
            0,
        );

        try {
            $durationResolver?->resolve(new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd')), $context);
            self::fail('Expected invalid duration resolver return type.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Timed movement edge duration must be an int or time expression string.', $e->getMessage());
        }

        $space->setDispatchCalendar(static fn (): string => 'later');
        try {
            $space->getDispatchCalendar()?->dispatchTime(new MovementEdge($space->slot('src.fs'), $space->slot('dest.sd')), $context);
            self::fail('Expected invalid dispatch calendar return type.');
        } catch (SlotFlowInvalidArgumentException $e) {
            self::assertSame('Dispatch calendar must resolve to an integer time index.', $e->getMessage());
        }
    }

    public function testMovementEngineParamResolutionCoversSlotAndEmptyStringBranches(): void
    {
        $space = SlotSpace::define([
            'loc' => ['src', 'dest'],
            'stt' => ['fs', 'sd'],
        ]);

        $state = new QuantityState($space, [['src.fs', 1]]);
        $slotFlow = Flow::define('param-resolution', static fn (Flow $flow) => $flow
            ->move('src.fs', ['loc' => '{dest}', 'stt' => 'sd']));

        /** @psalm-suppress InvalidArgument */
        $result = (new MovementEngine())->execute(
            inventory: $state,
            space: $space,
            cascade: $slotFlow,
            quantity: 1,
            params: ['dest' => 'dest', '' => 'ignored', 0 => 'ignored', 'nullish' => null],
        );

        self::assertTrue($result->isComplete());

        try {
            (new MovementEngine())->execute(
                inventory: new QuantityState($space, [['src.fs', 1]]),
                space: $space,
                cascade: Flow::define('bad-array-pattern', static fn (Flow $flow) => $flow->move(['loc' => '{slot}', 'stt' => 'fs'], 'dest.sd')),
                quantity: 1,
                params: ['slot' => ''],
            );
            self::fail('Expected empty resolved pattern value.');
        } catch (SlotFlowInvalidArgumentException $e) {
            // The pattern is refused where the parameter is resolved, not later at the codec:
            // a leftover `{slot}` reaching the codec reads as a missing dimension VALUE, which
            // sends the reader looking in the schema instead of at the call.
            self::assertSame(
                'Slot pattern for dimension \'loc\' needs parameter "slot", which resolved to an empty value.',
                $e->getMessage(),
            );
        }
    }
}
