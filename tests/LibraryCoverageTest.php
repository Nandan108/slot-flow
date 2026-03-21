<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\AllocationDecision;
use Nandan108\SlotFlow\AllocationPolicyInterface;
use Nandan108\SlotFlow\AvailableInventorySortPolicy;
use Nandan108\SlotFlow\BatchItem;
use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\CascadeContext;
use Nandan108\SlotFlow\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\DimensionPriority;
use Nandan108\SlotFlow\DistancePolicy;
use Nandan108\SlotFlow\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\EdgeRule;
use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\InventoryBatch;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementEvent;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Slot;
use Nandan108\SlotFlow\SlotPattern;
use Nandan108\SlotFlow\SlotRule;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class LibraryCoverageTest extends TestCase
{
    public function testDefaultSlotKeyCodecCoversSerializationValidationAndMatchingBranches(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
        ]);
        $codec = $space->codec;

        self::assertSame(DefaultSlotKeyCodec::WILDCARD, $codec->wildcard());
        self::assertSame(DefaultSlotKeyCodec::ALTERNATIVE, $codec->alternative());
        self::assertTrue($codec->isWildcard(null));
        self::assertTrue($codec->isWildcard(''));
        self::assertFalse($codec->isWildcard('foo'));
        self::assertSame('nil', $codec->serialize(null));
        self::assertNull($codec->deserialize(''));
        self::assertNull($codec->deserialize('nil'));
        self::assertSame(['loc' => 'foo', 'state' => 'fs'], $codec->deserialize('foo.fs'));
        self::assertSame(['bar'], $codec->matchDimensionValues('loc', 'bar'));
        self::assertSame(['foo', 'bar'], $codec->matchDimensionValues('loc', '*'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value keys must be a subset of dimension names');
        $codec->serialize(['bad' => 'x']);
    }

    public function testDefaultSlotKeyCodecRejectsInvalidInputs(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
        ]);
        $codec = $space->codec;

        try {
            $codec->deserialize('foo');
            self::fail('Expected invalid key format exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('does not match the expected format', $e->getMessage());
        }

        try {
            $codec->validateDimensionValues(['loc' => ['foo']], allowValueArrays: false);
            self::fail('Expected value array rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Array values are not allowed for dimension 'loc'", $e->getMessage());
        }

        try {
            $codec->validateDimensionValue('loc', '*', false);
            self::fail('Expected wildcard rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Value for dimension 'loc' cannot be empty or null", $e->getMessage());
        }

        try {
            $codec->validateDimensionValue('loc', 'baz', true);
            self::fail('Expected unknown value rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Value 'baz' is not valid for dimension 'loc'", $e->getMessage());
        }

        $codec->validateDimensionValues(['loc' => ['foo', 'bar']], allowWildcards: true, allowValueArrays: true);

        try {
            $codec->validateDimensionValue('loc', 'z*', true);
            self::fail('Expected unmatched wildcard rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unknown loc: 'z*'", $e->getMessage());
        }

        try {
            $codec->matchDimensionValues('bad', '*');
            self::fail('Expected unknown dimension rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unknown dimension: bad', $e->getMessage());
        }

        try {
            $codec->matchDimensionValues('loc', 'baz');
            self::fail('Expected invalid literal rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Value 'baz' is not valid for dimension 'loc'", $e->getMessage());
        }

        try {
            $codec->initialDimensionValueValidation(['loc' => ['bad*']]);
            self::fail('Expected wildcard char rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("cannot contain wildcard '*'", $e->getMessage());
        }

        try {
            $codec->initialDimensionValueValidation(['loc' => ['bad|alt']]);
            self::fail('Expected alternative char rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("cannot contain alternative character '|'", $e->getMessage());
        }
    }

    /** @psalm-type TRow array{variant: non-empty-string, loc: non-empty-string, qty: int} $rows */
    public function testInventoryBatchAndBatchItemCoverFactoryAndMutationGuards(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'state' => ['fs', 'sd'],
        ]);

        $inventory = new Inventory($space);
        $inventory->setSlotQtty($space->slot('foo.fs'), 3);
        self::assertSame(['foo.fs' => 3], $inventory->all());

        $copy = $inventory->copy();
        $copy->add($space->slot('foo.fs'), 2);
        self::assertSame(3, $inventory->get($space->slot('foo.fs')));
        self::assertSame(5, $copy->get($space->slot('foo.fs')));

        $inventory->addFromRows(
            [['loc' => 'foo', 'fs' => 1, 'sd' => 2]],
            static fn (array $row): array => [
                [['loc' => $row['loc'], 'state' => 'fs'], $row['fs']],
                [['loc' => $row['loc'], 'state' => 'sd'], $row['sd']],
            ],
        );

        self::assertSame(4, $inventory->get($space->slot('foo.fs')));
        self::assertSame(2, $inventory->get($space->slot('foo.sd')));

        $batch = InventoryBatch::fromRows(
            space: $space,
            rows: [['variant' => 'A', 'loc' => 'foo', 'qty' => 2]],
            /** @param TRow $row */
            variantGetter: static fn (array $row): string => $row['variant'],
            /** @param TRow $row */
            slotRowGetter: static fn (array $row): array => [
                [$space->slot([$row['loc'], 'fs']), $row['qty']],
            ],
            /** @param list<TRow> $rows */
            quantityGetter: static fn (array $rows): int => $rows[0]['qty'],
            variantIdGetter: null,
        );

        self::assertSame('A', $batch->items()[0]->variant());
        self::assertSame(2, $batch->items()[0]->inventory()->get($space->slot('foo.fs')));

        $result = new MovementResult([], 1);
        $item = new BatchItem('A', 1, new Inventory($space));
        $item->setMovementResult($result);
        self::assertSame($result, $item->movementResult());

        try {
            $item->setMovementResult($result);
            self::fail('Expected duplicate result guard');
        } catch (\LogicException $e) {
            self::assertSame('Movement result already set', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant ID must be a non-empty string.');
        /** @psalm-suppress InvalidArgument */
        InventoryBatch::fromRows(
            $space,
            [['variant' => 'A', 'loc' => 'foo', 'qty' => 2]],
            /** @param TRow $row */
            static fn (array $row): string => $row['variant'],
            /** @param TRow $row */
            static fn (array $row): array => [
                [['loc' => $row['loc'], 'state' => 'fs'], $row['qty']],
            ],
            /** @param list<TRow> $rows */
            static fn (array $rows): int => $rows[0]['qty'],
            static fn (): string => '',
        );
    }

    public function testCascadeAndBuilderStorePoliciesAndHandleReversalModes(): void
    {
        $orderedBy = static fn (CascadeContext $ctx): array => $ctx->edges;
        $secondaryOrder = static fn (CascadeContext $ctx): array => array_reverse($ctx->edges);
        $constraint = static fn (MovementEdge $edge, CascadeContext $ctx): int => 1;

        $cascade = Cascade::define('build', static fn (Cascade $cascade) => $cascade
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
            'state' => ['fs', 'sd'],
        ]);
        $edge = new MovementEdge($space->slot('foo.fs'), $space->slot('bar.sd'));
        /** @var MovementEvent<int> */
        $event = new MovementEvent($edge, 2, 5, 1);
        $nilEvent = new MovementEvent(new MovementEdge($space->nilSlot(), $space->slot('foo.fs')), 2, null, null);
        $complete = new MovementResult([$event], 0);
        $incomplete = new MovementResult([], 1);

        self::assertSame('(foo.fs) -> (bar.sd)', (string) $edge);
        self::assertSame(['x' => 1], $edge->meta(['x' => 1])->attributes);
        self::assertSame(['x' => 1], $space->slot('foo.fs')->meta(['x' => 1])->attributes);
        self::assertSame(3, $event->finalFrom());
        self::assertSame(3, $event->finalTo());
        self::assertNull($nilEvent->finalFrom());
        self::assertNull($nilEvent->finalTo());
        self::assertTrue($complete->isComplete());
        self::assertFalse($incomplete->isComplete());
    }

    public function testSlotPrimitivesAndRulesCoverHelpers(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
        ])->applyEdgeRules(EdgeRule::connect('foo.fs', 'bar.sd')->all());
        $unlabeled = EdgeRule::allow('foo.fs', 'bar.sd');

        $foo = $space->slot('foo.fs');
        $bar = $space->slot('bar.sd');
        $nilPattern = SlotPattern::from(null, $space);

        self::assertTrue($foo->equals($space->slot('foo.fs')));
        self::assertFalse($foo->equals($bar));
        self::assertNull($unlabeled->label);
        self::assertSame(['capability' => 'hazmat'], SlotRule::allow('foo.fs')->meta(['capability' => 'hazmat'])->attributes);
        self::assertSame(['bar.fs'], array_map(static fn (Slot $slot): string => $slot->key(), $space->nilSlot()->with(['loc' => 'bar', 'state' => 'fs'])));
        self::assertSame([], $foo->with(['loc' => 'baz']));
        self::assertTrue($nilPattern->matches($space->nilSlot()));
        self::assertFalse($nilPattern->matches($foo));
        self::assertCount(2, SlotRule::denyAll(['foo.fs', 'bar.sd']));
        self::assertArrayHasKey('bar.sd', $space->getEdgesFrom($foo));
    }

    public function testPoliciesOrderAndFilterEdges(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'sink'],
            'own'   => ['C', 'F'],
            'state' => ['fs', 'sd'],
        ]);

        $edges = [
            new MovementEdge($space->slot('b.C.fs'), $space->slot('sink.C.sd')),
            new MovementEdge($space->slot('a.F.fs'), $space->slot('sink.C.sd')),
            new MovementEdge($space->slot('a.C.fs'), $space->slot('sink.C.sd')),
        ];
        $context = new CascadeContext($edges, new Inventory($space), 1, null, [
            'distance' => [
                'b.C.fs->sink.C.sd' => 20,
                'a.F.fs->sink.C.sd' => 10,
                'a.C.fs->sink.C.sd' => 5,
            ],
        ]);

        $priority = new DimensionPriority([
            'loc' => ['a', 'b'],
            'own' => ['C', 'F'],
        ]);
        $ordered = $priority->orderEdges($context);
        self::assertSame(['a.C.fs', 'a.F.fs', 'b.C.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key(), $ordered));

        $tiedEdges = [
            new MovementEdge($space->slot('sink.C.fs'), $space->slot('sink.C.sd')),
            new MovementEdge($space->slot('sink.F.fs'), $space->slot('sink.C.sd')),
        ];
        $tied = $priority->orderEdges(new CascadeContext($tiedEdges, new Inventory($space), 1));
        self::assertSame(['sink.C.fs', 'sink.F.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key(), $tied));

        $equalRankEdges = [
            new MovementEdge($space->slot('a.C.fs'), $space->slot('sink.C.sd')),
            new MovementEdge($space->slot('a.C.fs'), $space->slot('sink.F.sd')),
        ];
        $equalRank = $priority->orderEdges(new CascadeContext($equalRankEdges, new Inventory($space), 1));
        self::assertSame(['sink.C.sd', 'sink.F.sd'], array_map(static fn (MovementEdge $edge): string => $edge->to->key(), $equalRank));

        $distancePolicy = new DistancePolicy(max: 10);
        $filtered = $distancePolicy->filterEdges($context);
        self::assertSame(['a.F.fs', 'a.C.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key(), $filtered));

        $availableInventory = new AvailableInventorySortPolicy();
        $inventorySorted = $availableInventory->orderEdges(new CascadeContext($edges, new Inventory($space, [
            [$space->slot('a.C.fs'), 1],
            [$space->slot('a.F.fs'), 8],
            [$space->slot('b.C.fs'), 3],
        ]), 1));
        self::assertSame(['a.F.fs', 'b.C.fs', 'a.C.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key(), $inventorySorted));

        $orderedByDistance = (new DistancePolicy())->orderEdges(new CascadeContext($edges, new Inventory($space), 1, null, [
            'distance' => static fn (MovementEdge $edge): int => match ($edge->from->key()) {
                'b.C.fs' => 3,
                'a.F.fs' => 2,
                default  => 1,
            },
        ]));
        self::assertSame(['a.C.fs', 'a.F.fs', 'b.C.fs'], array_map(static fn (MovementEdge $edge): string => $edge->from->key(), $orderedByDistance));
        self::assertSame($edges, (new DistancePolicy())->filterEdges(new CascadeContext($edges, new Inventory($space), 1)));
        self::assertSame(['b.C.fs', 'a.F.fs', 'a.C.fs'], array_map(
            static fn (MovementEdge $edge): string => $edge->from->key(),
            (new DistancePolicy(max: 1))->orderEdges(new CascadeContext($edges, new Inventory($space), 1)),
        ));
    }

    /** @psalm-suppress InvalidPropertyAssignmentValue */
    public function testMovementEngineCoversCallableAndInterfacePolicyPaths(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'c', 'sink'],
            'state' => ['fs', 'sd'],
        ]);
        $inventory = new Inventory($space, [
            [$space->slot('a.fs'), 4],
            [$space->slot('b.fs'), 4],
        ]);

        $cascade = Cascade::define('policy-branches', static fn (Cascade $cascade) => $cascade
            ->move('a|b.fs', 'sink.sd')
            ->filter(new class implements EdgeFilterPolicyInterface {
                public function filterEdges(CascadeContext $ctx): array
                {
                    return $ctx->edges;
                }
            })
            ->filter(static fn (CascadeContext $ctx): array => array_reverse($ctx->edges))
            ->orderBy(new class implements EdgeOrderingPolicyInterface {
                public function orderEdges(CascadeContext $ctx): array
                {
                    return array_reverse($ctx->edges);
                }
            })
            ->constraint(new class implements QttyConstraintPolicyInterface {
                public function constraint(MovementEdge $edge, CascadeContext $ctx): int | float
                {
                    return 'a.fs' === $edge->from->key() ? 1 : 99;
                }
            })
            ->constraint(static fn (MovementEdge $edge, CascadeContext $ctx): string => 'skip')
            ->allocate(new class implements AllocationPolicyInterface {
                public function allocate(CascadeContext $ctx): array
                {
                    return [
                        new AllocationDecision($ctx->edges[0], 2),
                        new AllocationDecision($ctx->edges[1], 2),
                    ];
                }
            })
            ->move('sink.sd', null));

        $step = $cascade->steps()[0];
        $step->filterPolicies[] = new \stdClass();
        $step->orderingPolicies[] = static fn (CascadeContext $ctx): array => $ctx->edges;
        $step->orderingPolicies[] = new \stdClass();
        $step->quantityConstraintPolicies[] = new \stdClass();
        $step->allocationPolicies[] = new \stdClass();

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 3);

        self::assertSame(0, $result->remaining());
        self::assertCount(2, $result->events());
        self::assertSame('a.fs', $result->events()[0]->edge()->from->key());
        self::assertSame(1, $result->events()[0]->quantity());
        self::assertSame('b.fs', $result->events()[1]->edge()->from->key());
        self::assertSame(2, $result->events()[1]->quantity());
    }

    public function testMovementEngineCoversDecisionLoopContinueAndBreakBranches(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'c', 'sink'],
            'state' => ['fs', 'sd'],
        ]);
        $inventory = new Inventory($space, [
            [$space->slot('a.fs'), 1],
            [$space->slot('b.fs'), 2],
            [$space->slot('c.fs'), 0],
        ]);

        $cascade = Cascade::define('decisions', static fn (Cascade $cascade) => $cascade
            ->move('a|b|c.fs', 'sink.sd')
            ->allocate(new class implements AllocationPolicyInterface {
                public function allocate(CascadeContext $ctx): array
                {
                    $byFrom = [];
                    foreach ($ctx->edges as $edge) {
                        $byFrom[$edge->from->key()] = $edge;
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

        self::assertSame(0, $result->remaining());
        self::assertCount(2, $result->events());
        self::assertSame('a.fs', $result->events()[0]->edge()->from->key());
        self::assertSame('b.fs', $result->events()[1]->edge()->from->key());
    }

    public function testMovementEngineCanFilterEdgesUsingSubjectContext(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['a', 'b', 'sink'],
            'state' => ['fs', 'sd'],
        ]);
        $inventory = new Inventory($space, [
            [$space->slot('a.fs'), 3],
            [$space->slot('b.fs'), 3],
        ]);

        $cascade = Cascade::define('subject-filter', static fn (Cascade $cascade) => $cascade
            ->move('a|b.fs', 'sink.sd')
            ->filter(static function (CascadeContext $ctx): array {
                $allowedSources = is_array($ctx->subject) ? ($ctx->subject['allowed_sources'] ?? []) : [];
                if (!is_array($allowedSources) || [] === $allowedSources) {
                    return $ctx->edges;
                }

                return array_values(array_filter(
                    $ctx->edges,
                    static fn (MovementEdge $edge): bool => in_array($edge->from->dimension('loc'), $allowedSources, true),
                ));
            }));

        $result = (new MovementEngine())->execute(
            $inventory,
            $space,
            $cascade,
            2,
            ['allowed_sources' => ['b']],
        );

        self::assertSame(0, $result->remaining());
        self::assertCount(1, $result->events());
        self::assertSame('b.fs', $result->events()[0]->edge()->from->key());
    }

    public function testMovementEngineBreaksEarlyAndCoversSlotSpaceErrorBranches(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
            'empty' => [],
        ]);
        $inventory = new Inventory($space, [[$space->slot(['foo', 'fs', '*']), 1]]);
        $cascade = Cascade::define('noop', static fn (Cascade $cascade) => $cascade
            ->move('foo.fs.*', 'bar.sd.*')
            ->move('bar.sd.*', null));

        $result = (new MovementEngine())->execute($inventory, $space, $cascade, 0);
        self::assertSame([], $result->events());

        $denyFirst = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
        ])->applySlotRules([
            SlotRule::deny('foo.fs'),
        ]);
        $sameSpace = SlotSpace::define([
            'loc'   => ['foo'],
            'state' => ['fs'],
        ]);

        self::assertNull($denyFirst->trySlot('foo.fs'));
        self::assertNotNull($denyFirst->trySlot('bar.fs'));
        self::assertSame([], $denyFirst->getEdgesFrom($denyFirst->slot('bar.fs')));
        self::assertSame($sameSpace, $sameSpace->applySlotRules([]));
        self::assertSame([[]], $denyFirst->expandSlotPattern(['state' => '*']));
        self::assertSame('foo.fs', SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
        ])->slot(['foo', 'fs'])->key());
        self::assertSame($denyFirst->nilSlot(), $denyFirst->trySlot('nil'));
        self::assertSame('test', SlotSpace::define(['kind' => ['test']])->dimensionValues('kind')[0]);

        try {
            $denyFirst->dimensionValues('bad');
            self::fail('Expected unknown dimension');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unknown dimension: bad', $e->getMessage());
        }

        try {
            $denyFirst->expandSlotPattern(['bad' => 'x']);
            self::fail('Expected unknown dimension in pattern');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unknown dimension: bad', $e->getMessage());
        }

        try {
            $denyFirst->getCascade('missing');
            self::fail('Expected missing cascade');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Cascade 'missing' not defined", $e->getMessage());
        }

        $cachedEdgesSpace = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'state' => ['fs', 'sd'],
        ])->applyEdgeRules([
            EdgeRule::allowLabeled(null, 'foo.fs', 'bar.sd'),
        ]);
        $first = $cachedEdgesSpace->getEdgesFrom($cachedEdgesSpace->slot('foo.fs'));
        $second = $cachedEdgesSpace->getEdgesFrom($cachedEdgesSpace->slot('foo.fs'));
        self::assertSame($first, $second);
    }
}
