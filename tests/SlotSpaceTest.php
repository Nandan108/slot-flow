<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Internal\SlotPattern;
use Nandan108\SlotFlow\Internal\SlotSpaceBuilder;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\RuleSet;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\Runtime\CascadeContext;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class SlotSpaceTest extends TestCase
{
    public function testItExposesDimensionsAndConcreteSlots(): void
    {
        $space = $this->makeWarehouseSpace();

        self::assertSame(['loc', 'stt'], $space->dimensionNames());
        self::assertSame(['foo', 'faz', 'bar'], $space->dimensionValues('loc'));
        self::assertSame('foo.fs', $space->slot('foo.fs')->key);
        self::assertSame('faz.sd', $space->slot(['faz', 'sd'])->key);
        self::assertNull($space->slot(null)->dimensions);
    }

    public function testItRejectsReservedCharactersInDimensionValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Dimension values cannot contain '.'");

        SlotSpace::define([
            'loc'   => ['foo.bad'],
            'stt'   => ['fs'],
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
            [['loc' => 'foo', 'stt' => 'fs']],
            $space->expandSlotPattern('foo.fs'),
        );

        self::assertSame(
            [['loc' => 'foo']],
            $space->expandSlotPattern(['loc' => 'foo']),
        );

        self::assertSame(
            [
                ['loc' => 'foo', 'stt' => 'fs'],
                ['loc' => 'faz', 'stt' => 'fs'],
            ],
            $space->expandSlotPattern('foo|faz.fs'),
        );

        self::assertSame(
            [
                ['loc' => 'foo', 'stt' => 'fs'],
                ['loc' => 'faz', 'stt' => 'fs'],
            ],
            $space->expandSlotPattern('f*.fs'),
        );

        self::assertSame(
            [
                ['loc' => 'faz', 'stt' => 'fs'],
                ['loc' => 'bar', 'stt' => 'fs'],
            ],
            $space->expandSlotPattern('*a*.fs'),
        );

        self::assertSame(
            [
                ['loc' => 'faz', 'stt' => 'fs'],
                ['loc' => 'foo', 'stt' => 'fs'],
            ],
            $space->expandSlotPattern('*z|*o.fs'),
        );

        self::assertSame(
            [['stt' => 'fs']],
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

    public function testslotRulesSupportsNestedRuleSetsAndSequencing(): void
    {
        $space = $this->makeWarehouseSpace()->slotRules(
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

    public function testslotRulesAccumulatesMetadataFromMatchingAllowRules(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'bar'],
            'stt'   => ['fs', 'sd'],
        ])->slotRules([
            SlotRule::allow('foo.*', ['zone' => 'forward']),
            SlotRule::allow('*.fs', ['temperature' => 'ambient']),
            SlotRule::allow('foo.fs', ['zone' => 'reserve', 'channel' => 'web']),
        ]);

        self::assertSame(
            ['zone' => 'reserve', 'channel' => 'web', 'temperature' => 'ambient'],
            $space->slot('foo.fs')->attributes,
        );
        self::assertSame(
            ['zone' => 'forward'],
            $space->slot('foo.sd')->attributes,
        );
        self::assertSame(
            ['temperature' => 'ambient'],
            $space->slot('bar.fs')->attributes,
        );
    }

    public function testEdgesBetweenFillsMissingTargetDimensionsFromOrigin(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo', 'b'],
            'stt'   => ['fs', 'sd'],
        ]);

        self::assertSame(
            ['(foo.fs) -> (foo.sd)', '(b.fs) -> (b.sd)'],
            array_map(static fn (MovementEdge $edge): string => (string) $edge, $space->edgesBetween('*.fs', ['stt' => 'sd'])),
        );
    }

    public function testGetEdgesFromAppliesAllowAndDenyRulesInOrder(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs', 'sd', 'ret'],
        ])->edgeRules([
            EdgeRule::allowLabeled('advance', 'foo.fs', 'foo.sd|ret'),
            EdgeRule::deny(null, 'foo.fs', 'foo.ret'),
        ]);

        self::assertSame(
            ['foo.sd'],
            array_keys($space->slot('foo.fs')->outgoingEdges()),
        );
    }

    public function testGetEdgesFromKeepsRuleMetadataOnGeneratedEdges(): void
    {
        /*
        expects RuleSet<EdgeRule>|list<EdgeRule|RuleSet<EdgeRule>>, but
                RuleSet<EdgeRule|SlotRule> provided
        */

        $space = $this->makeWarehouseSpace()
            ->edgeRules(
                RuleSet::from(
                    EdgeRule::allowLabeled('advance', 'foo.fs', 'foo.sd'),
                )->meta(['channel' => 'ops']),
            );

        $edges = $space->slot('foo.fs')->outgoingEdges();

        self::assertSame(['channel' => 'ops'], $edges['foo.sd']->attributes);
    }

    public function testGetEdgesFromUsesNullForSinkEdges(): void
    {
        $space = $this->makeWarehouseSpace()->edgeRules([
            EdgeRule::allowLabeled('complete', 'foo.sd', null),
        ]);

        $edges = array_values($space->slot('foo.sd')->outgoingEdges());
        self::assertCount(1, $edges);
        self::assertSame($space->nilSlot(), $edges[0]->to);
        self::assertSame($space->slot(null), $edges[0]->to);
        self::assertSame($space->slot($space->codec->nilKey()), $edges[0]->to);
    }

    public function testEachSlotSpaceOwnsItsOwnNilSlot(): void
    {
        $left = SlotSpace::define([
            'loc'   => ['foo'],
            'stt'   => ['fs'],
        ]);
        $right = SlotSpace::define([
            'zone'  => ['a'],
            'stt'   => ['held'],
        ]);

        self::assertNotSame($left->nilSlot(), $right->nilSlot());
        self::assertSame($left, $left->nilSlot()->space);
        self::assertSame($right, $right->nilSlot()->space);
    }

    public function testSimpleCascadeShorthandStoresSourceAndSinkStepsInTheExpectedDirection(): void
    {
        $space = $this->makeWarehouseSpace()
            ->cascade('source-sink', [
                [null, 'foo.fs'],
                ['foo.fs', null],
            ]);

        $cascade = $space->getCascade('source-sink');

        self::assertCount(2, $cascade->steps());
        self::assertNull($cascade->steps()[0]->from);
        self::assertSame('foo.fs', $cascade->steps()[0]->to);
        self::assertSame('foo.fs', $cascade->steps()[1]->from);
        self::assertNull($cascade->steps()[1]->to);
    }

    public function testSimpleCascadeShorthandArraySyntaxCompilesSteps(): void
    {
        $space = $this->makeWarehouseSpace()
            ->cascade('book', [
                ['foo.fs', 'foo.sd'],
                ['bar.fs', 'bar.sd'],
            ]);

        self::assertCount(2, $space->getCascade('book')->steps());
        self::assertSame('foo.fs', $space->getCascade('book')->steps()[0]->from);
        self::assertSame('bar.sd', $space->getCascade('book')->steps()[1]->to);
    }

    public function testCascadeCanReverseAndFlipEdges(): void
    {
        $space = $this->makeWarehouseSpace();
        $inventory = new \Nandan108\SlotFlow\Inventory($space, [
            [$space->slot('foo.sd'), 1],
            [$space->slot('bar.sd'), 1],
        ]);

        $cascade = Cascade::define('reverse', static fn (Cascade $cascade) => $cascade
            ->move('foo.fs', 'foo.sd')
            ->move('bar.fs', 'bar.sd'))
            ->reverseIf(true, true);

        $result = (new \Nandan108\SlotFlow\MovementEngine())->execute(
            $inventory,
            $space,
            $cascade,
            2,
        );

        self::assertCount(2, $result->events);
        self::assertSame('(bar.sd) -> (bar.fs)', (string) $result->events[0]->edge);
        self::assertSame('(foo.sd) -> (foo.fs)', (string) $result->events[1]->edge);
    }

    public function testCascadeRegistersNamedCascades(): void
    {
        $space = $this->makeWarehouseSpace()
            ->cascade('book', static function (Cascade $cascade) {
                return $cascade
                    ->move('*.fs', '*.sd')
                    ->filter(static fn (CascadeContext $context): array => $context->edges);
            });

        self::assertArrayHasKey('book', $space->cascades);
        self::assertSame('book', $space->cascades['book']->name());
        self::assertCount(1, $space->cascades['book']->steps());
    }

    public function testCascadeRejectsDuplicateNames(): void
    {
        $space = $this->makeWarehouseSpace()
            ->cascade('book', static fn (Cascade $cascade) => $cascade->move('*.fs', '*.sd'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cascade 'book' already defined");

        $space->cascade('book', static fn (Cascade $cascade) => $cascade->move('*.sd', '*.fs'));
    }

    public function testBuilderCompilesSlotAndEdgeRules(): void
    {
        $space = (new SlotSpaceBuilder($this->makeWarehouseSpace()))
            ->slotRules([SlotRule::deny('bar.*')])
            ->edgeRules([EdgeRule::allowLabeled('advance', 'foo.fs', 'foo.sd')])
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
        $pattern = SlotPattern::from(['loc' => 'foo', 'stt' => 'fs'], $space);

        self::assertTrue($pattern->matches($space->slot('foo.fs')));
        self::assertFalse($pattern->matches($space->slot('foo.sd')));
        self::assertFalse($pattern->matches($space->nilSlot()));
    }

    private function makeWarehouseSpace(): SlotSpace
    {
        return SlotSpace::define([
            'loc'   => ['foo', 'faz', 'bar'],
            'stt'   => ['fs', 'sd'],
        ]);
    }

    /**
     * @param list<\Nandan108\SlotFlow\Slot> $slots
     *
     * @return list<string>
     */
    private function slotKeys(array $slots): array
    {
        return array_map(static fn ($slot): string => $slot->key, $slots);
    }
}
