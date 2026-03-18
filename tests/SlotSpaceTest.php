<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\EdgeRule;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\RuleSet;
use Nandan108\SlotFlow\SlotPattern;
use Nandan108\SlotFlow\SlotRule;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\SlotSpaceBuilder;
use PHPUnit\Framework\TestCase;

final class SlotSpaceTest extends TestCase
{
    public function testItExposesDimensionsAndConcreteSlots(): void
    {
        $space = $this->makeWarehouseSpace();

        self::assertSame(['loc', 'state'], $space->dimensionNames());
        self::assertSame(['foo', 'faz', 'bar'], $space->dimensionValues('loc'));
        self::assertSame('foo.fs', $space->slot('foo.fs')->key());
        self::assertSame('faz.sd', $space->slot(['faz', 'sd'])->key());
        self::assertNull($space->slot(null)->dimensions());
    }

    public function testItRejectsReservedCharactersInDimensionValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Dimension values cannot contain '.'");

        SlotSpace::define([
            'loc'   => ['foo.bad'],
            'state' => ['fs'],
        ], DefaultSlotKeyCodec::class);
    }

    public function testExpandSlotPatternSupportsWildcardsAndAlternatives(): void
    {
        $space = $this->makeWarehouseSpace();

        self::assertSame(
            [['loc' => 'foo']],
            $space->expandSlotPattern('foo.*'),
        );

        self::assertSame(
            [['loc' => 'foo', 'state' => 'fs']],
            $space->expandSlotPattern('foo.fs'),
        );

        self::assertSame(
            [['loc' => 'foo']],
            $space->expandSlotPattern(['loc' => 'foo']),
        );

        self::assertSame(
            [
                ['loc' => 'foo', 'state' => 'fs'],
                ['loc' => 'faz', 'state' => 'fs'],
            ],
            $space->expandSlotPattern('foo|faz.fs'),
        );

        self::assertSame(
            [
                ['loc' => 'foo', 'state' => 'fs'],
                ['loc' => 'faz', 'state' => 'fs'],
            ],
            $space->expandSlotPattern('f*.fs'),
        );

        self::assertSame(
            [
                ['loc' => 'faz', 'state' => 'fs'],
                ['loc' => 'bar', 'state' => 'fs'],
            ],
            $space->expandSlotPattern('*a*.fs'),
        );

        self::assertSame(
            [
                ['loc' => 'faz', 'state' => 'fs'],
                ['loc' => 'foo', 'state' => 'fs'],
            ],
            $space->expandSlotPattern('*z|*o.fs'),
        );

        self::assertSame(
            [['state' => 'fs']],
            $space->expandSlotPattern('foo|faz|bar.fs'),
        );

        self::assertSame([null], $space->expandSlotPattern(null));
    }

    public function testCodecKeepsLiteralAlternativesWhenWildcardBranchIsCached(): void
    {
        $space = $this->makeWarehouseSpace();

        self::assertSame(['foo', 'faz'], $space->codec->matchDimensionValues('loc', 'f*'));
        self::assertSame(['bar', 'foo', 'faz'], $space->codec->matchDimensionValues('loc', 'bar|f*'));
    }

    public function testMatchExpandsMissingDimensionsInPartialArrays(): void
    {
        $space = $this->makeWarehouseSpace();

        $fooSpace = $space->matchPartial(['loc' => 'foo']);
        self::assertSame(
            ['foo.fs', 'foo.sd'],
            $this->slotKeys($fooSpace),
        );
    }

    public function testApplySlotRulesSupportsNestedRuleSetsAndSequencing(): void
    {
        $space = $this->makeWarehouseSpace()->applySlotRules(
            RuleSet::from(
                SlotRule::allow('foo.*'),
                RuleSet::from(
                    SlotRule::deny('foo.sd'),
                    SlotRule::allow('bar.sd'),
                ),
            ),
        );

        self::assertNotNull($space->trySlot('foo.fs'));
        self::assertNotNull($space->trySlot('bar.sd'));

        self::assertNull($space->trySlot('foo.sd'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown slot: "foo.sd"');
        $space->slot('foo.sd');
    }

    public function testEdgesBetweenFillsMissingTargetDimensionsFromOrigin(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'b'],
            'state' => ['fs', 'sd'],
        ]);

        self::assertSame(
            ['(foo.fs) -> (foo.sd)', '(b.fs) -> (b.sd)'],
            array_map(static fn (MovementEdge $edge): string => (string) $edge, $space->edgesBetween('*.fs', ['state' => 'sd'])),
        );
    }

    public function testGetEdgesFromAppliesAllowAndDenyRulesInOrder(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'state' => ['fs', 'sd', 'ret'],
        ])->applyEdgeRules([
            EdgeRule::allow('advance', 'foo.fs', 'foo.sd|ret'),
            EdgeRule::deny(null, 'foo.fs', 'foo.ret'),
        ]);

        self::assertSame(
            ['foo.sd'],
            array_keys($space->getEdgesFrom($space->slot('foo.fs'))),
        );
    }

    public function testGetEdgesFromKeepsRuleMetadataOnGeneratedEdges(): void
    {
        /*
        expects RuleSet<EdgeRule>|list<EdgeRule|RuleSet<EdgeRule>>, but
                RuleSet<EdgeRule|SlotRule> provided
        */

        $space = $this->makeWarehouseSpace()
            ->applyEdgeRules(
                RuleSet::from(
                    EdgeRule::allow('advance', 'foo.fs', 'foo.sd'),
                )->meta(['channel' => 'ops']),
            );

        $edges = $space->getEdgesFrom($space->slot('foo.fs'));

        self::assertSame(['channel' => 'ops'], $edges['foo.sd']->attributes);
    }

    public function testGetEdgesFromUsesNullForSinkEdges(): void
    {
        $space = $this->makeWarehouseSpace()->applyEdgeRules([
            EdgeRule::allow('complete', 'foo.sd', null),
        ]);

        $edges = array_values($space->getEdgesFrom($space->slot('foo.sd')));

        self::assertCount(1, $edges);
        self::assertSame($space->nilSlot(), $edges[0]->to);
        self::assertSame($space->slot(null), $edges[0]->to);
        self::assertSame($space->slot($space->codec->nilKey()), $edges[0]->to);
    }

    public function testEachSlotSpaceOwnsItsOwnNilSlot(): void
    {
        $left = SlotSpace::define([
            'loc'   => ['foo'],
            'state' => ['fs'],
        ]);
        $right = SlotSpace::define([
            'zone'  => ['a'],
            'state' => ['held'],
        ]);

        self::assertNotSame($left->nilSlot(), $right->nilSlot());
        self::assertSame($left, $left->nilSlot()->space());
        self::assertSame($right, $right->nilSlot()->space());
    }

    public function testCascadeCreatesSourceAndSinkEdgesInTheExpectedDirection(): void
    {
        $space = $this->makeWarehouseSpace();

        $path = $space->cascade([
            [null, 'foo.fs'],
            ['foo.fs', null],
        ], false);

        self::assertTrue($path->edges()[0]->from->isNil());
        self::assertSame('foo.fs', $path->edges()[0]->to->key());
        self::assertSame('foo.fs', $path->edges()[1]->from->key());
        self::assertSame($space->slot(null), $path->edges()[1]->to);
    }

    public function testCascadeCanReverseAndFlipEdges(): void
    {
        $space = $this->makeWarehouseSpace();

        $path = $space->cascade([
            ['foo.fs', 'foo.sd'],
            ['bar.fs', 'bar.sd'],
        ], true);

        self::assertSame('(bar.sd) -> (bar.fs)', (string) $path->edges()[0]);
        self::assertSame('(foo.sd) -> (foo.fs)', (string) $path->edges()[1]);
    }

    public function testBuilderCompilesSlotAndEdgeRules(): void
    {
        $space = (new SlotSpaceBuilder($this->makeWarehouseSpace()))
            ->slotRules([SlotRule::deny('bar.*')])
            ->edgeRules([EdgeRule::allow('advance', 'foo.fs', 'foo.sd')])
            ->compile();

        self::assertSame(['foo.sd'], array_keys($space->getEdgesFrom($space->slot('foo.fs'))));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown slot: "bar.fs"');
        $space->slot('bar.fs');
    }

    public function testMatchPartialReturnsCorrectSubsetOfPrunedSpace(): void
    {
        $space = (new SlotSpaceBuilder($this->makeWarehouseSpace()))
            ->slotRules([SlotRule::deny('bar.sd')])
            ->compile();

        $barSpace = $space->matchPartial(['loc' => 'bar']);
        self::assertSame(['bar.fs'], $this->slotKeys($barSpace));

        $edges = $space->edgesBetween('foo.*', 'bar.*');
        // bar.sd doesn't exist, so foo.sd -> bar.sd edge should be filtered out, leaving only foo.fs -> bar.fs
        self::assertSame(1, count($edges));

    }

    public function testSlotPatternMatchesUsesDimensionAccessors(): void
    {
        $space = $this->makeWarehouseSpace();
        $pattern = SlotPattern::from(['loc' => 'foo', 'state' => 'fs'], $space);

        self::assertTrue($pattern->matches($space->slot('foo.fs')));
        self::assertFalse($pattern->matches($space->slot('foo.sd')));
        self::assertFalse($pattern->matches($space->nilSlot()));
    }

    private function makeWarehouseSpace(): SlotSpace
    {
        return SlotSpace::define([
            'loc'   => ['foo', 'faz', 'bar'],
            'state' => ['fs', 'sd'],
        ]);
    }

    /**
     * @param list<\Nandan108\SlotFlow\SlotKey> $slots
     *
     * @return list<string>
     */
    private function slotKeys(array $slots): array
    {
        return array_map(static fn ($slot): string => $slot->key(), $slots);
    }
}
