<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Batch\InventoryBatch;
use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\Policies\AvailableInventorySortPolicy;
use Nandan108\SlotFlow\Policies\AvailableQuantitySortPolicy;
use Nandan108\SlotFlow\Results\InventoryMutation;
use Nandan108\SlotFlow\Results\QuantityStateDelta;
use Nandan108\SlotFlow\Runtime\CascadeContext;
use Nandan108\SlotFlow\Runtime\FlowContext;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

/**
 * Covers the deprecated API surface so it can be removed cleanly later
 * by deleting this file alongside the legacy aliases and accessors.
 *
 * @psalm-suppress DeprecatedClass
 * @psalm-suppress DeprecatedMethod
 */
final class LegacyApiCompatibilityTest extends TestCase
{
    public function testLegacyInventoryAndCascadeAliasesStillExecute(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo'],
            'stt' => ['fs', 'sd'],
        ]);

        $inventory = new Inventory($space, [['foo.fs', 2]]);
        $cascade = Cascade::define('sell', static fn (Cascade $cascade) => $cascade
            ->move('foo.fs', 'foo.sd'));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 2);

        self::assertSame(0, $result->remaining);
        self::assertCount(1, $result->events);
        self::assertSame('(foo.fs) -> (foo.sd)', (string) $result->events[0]->edge);
        self::assertSame(0, $inventory->get('foo.fs'));
        self::assertSame(2, $inventory->get('foo.sd'));
    }

    public function testLegacyCascadeRegistrationAndLookupPreserveMessages(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ])->cascade('book', static fn (Cascade $cascade) => $cascade->move('foo.fs', 'bar.sd'));

        self::assertInstanceOf(Cascade::class, $space->getCascade('book'));
        self::assertSame('foo.fs', $space->getCascade('book')->steps()[0]->from);
        self::assertSame('bar.sd', $space->getCascade('book')->steps()[0]->to);

        try {
            $space->cascade('book', static fn (Cascade $cascade) => $cascade->move('bar.sd', 'foo.fs'));
            self::fail('Expected duplicate legacy cascade rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Cascade 'book' already defined", $e->getMessage());
        }

        try {
            SlotSpace::define([
                'loc' => ['foo'],
                'stt' => ['fs'],
            ])->getCascade('missing');
            self::fail('Expected missing legacy cascade');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Cascade 'missing' not defined", $e->getMessage());
        }
    }

    public function testLegacyCascadeArrayRegistrationStillCreatesACascade(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ])->cascade('book', [[['stt' => 'fs'], ['stt' => 'sd']]]);

        self::assertInstanceOf(Flow::class, $space->getFlow('book'));
        self::assertInstanceOf(Cascade::class, $space->getCascade('book'));
        self::assertSame(['stt' => 'fs'], $space->getFlow('book')->steps()[0]->from);
        self::assertSame(['stt' => 'sd'], $space->getFlow('book')->steps()[0]->to);
    }

    public function testLegacyCascadeLookupCanWrapAFlowAsACascade(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo', 'bar'],
            'stt' => ['fs', 'sd'],
        ])->flow(
            'book',
            static fn (Flow $flow) => $flow->move('foo.fs', 'bar.sd'),
        );

        $cascade = $space->getCascade('book');

        self::assertInstanceOf(Cascade::class, $cascade);
        self::assertSame('book', $cascade->name());
        self::assertSame('foo.fs', $cascade->steps()[0]->from);
        self::assertSame('bar.sd', $cascade->steps()[0]->to);
    }

    public function testLegacyCascadeContextAliasStillWorks(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo'],
            'stt' => ['fs'],
        ]);

        $context = new CascadeContext($space, [], new Inventory($space), 1);

        self::assertInstanceOf(FlowContext::class, $context);
        self::assertSame(1, $context->quantity);
    }

    public function testLegacyInventoryBatchAndDeltaAliasesStillWork(): void
    {
        /** @psalm-type TLegacyRow = array{subject: non-empty-string, qty: int} */
        $space = SlotSpace::define([
            'loc' => ['foo'],
            'stt' => ['fs'],
        ]);

        /** @var list<TLegacyRow> $rows */
        $rows = [['subject' => 'A', 'qty' => 2]];
        $batch = InventoryBatch::fromRows(
            space: $space,
            rows: $rows,
            /** @param TLegacyRow $row */
            subjectGetter: static fn (array $row): string => $row['subject'],
            /** @param TLegacyRow $row */
            slotRowGetter: static fn (array $row): array => [
                [$space->slot('foo.fs'), $row['qty']],
            ],
            /** @param list<TLegacyRow> $rows */
            quantityGetter: static fn (array $rows): int => $rows[0]['qty'],
        );

        $mutation = new InventoryMutation($space->slot('foo.fs'), 2);

        self::assertCount(1, $batch->items());
        self::assertInstanceOf(QuantityStateDelta::class, $mutation);
    }

    public function testLegacyAvailableInventoryPolicyAliasStillWorks(): void
    {
        $policy = new AvailableInventorySortPolicy();

        self::assertInstanceOf(AvailableQuantitySortPolicy::class, $policy);
    }

    public function testLegacyMutationsAccessorsStillWork(): void
    {
        $space = SlotSpace::define([
            'loc' => ['foo'],
            'stt' => ['fs', 'sd'],
        ]);

        $result = (new MovementEngine())->execute(
            new Inventory($space, [['foo.fs', 2]]),
            $space,
            Cascade::define('sell', static fn (Cascade $cascade) => $cascade->move('foo.fs', 'foo.sd')),
            2,
        );

        self::assertCount(2, $result->mutations());

        $batch = new InventoryBatch([
            new \Nandan108\SlotFlow\Batch\BatchItem('A', 2, new Inventory($space, [['foo.fs', 2]])),
        ]);
        $batch->items()[0]->setMovementResult($result);

        self::assertCount(2, $batch->mutations());
    }
}
