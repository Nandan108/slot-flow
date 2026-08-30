<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\EdgeRuleBase;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

/**
 * Whether the declared edge graph constrains movement.
 *
 * Before EdgeRuleBase existed there were two graphs and only one was enforced: `move()` resolved
 * edges from patterns and ignored the rules entirely, while labeled steps and the timed expansion
 * honoured them. A space could therefore declare a topology and still move quantity straight across
 * a pair it had never sanctioned.
 */
final class EdgeTopologyTest extends TestCase
{
    private static function space(EdgeRuleBase ...$base): SlotSpace
    {
        $space = SlotSpace::define(['loc' => ['wh1'], 'stt' => ['fs', 'res', 'sd']]);
        $space->edgeRules(
            [EdgeRule::allowLabeled('reserve', 'wh1.fs', 'wh1.res')],
            ...$base,
        );

        return $space;
    }

    /**
     * @param non-empty-string $from
     * @param non-empty-string $to
     */
    private static function move(SlotSpace $space, string $from, string $to, int $quantity = 5): \Nandan108\SlotFlow\MovementResult
    {
        $name = "move_{$from}_{$to}";
        $space->flow($name, static fn (Flow $flow) => $flow->move(['stt' => $from], ['stt' => $to]));

        return (new MovementEngine())->execute(
            new QuantityState($space, [['wh1.fs', 5]]),
            $space,
            $name,
            $quantity,
        );
    }

    public function testDeclaredEdgesDoNotConstrainMovementUnderTheDefaultBase(): void
    {
        // A space that declares no topology has always meant "any pair the patterns express", and
        // declaring rules for their labels or metadata must not silently change that.
        $result = self::move(self::space(), 'fs', 'sd');

        self::assertCount(1, $result->events);
        self::assertSame(0, $result->remaining);
    }

    public function testUndeclaredMovementFindsNoEdgeUnderBaseNone(): void
    {
        $result = self::move(self::space(EdgeRuleBase::None), 'fs', 'sd');

        self::assertSame([], $result->events);
        self::assertSame(5, $result->remaining, 'an unsanctioned path must refuse, not move');
    }

    public function testDeclaredMovementStillRunsUnderBaseNoneAndCarriesItsEdgeMetadata(): void
    {
        $result = self::move(self::space(EdgeRuleBase::None), 'fs', 'res');

        self::assertCount(1, $result->events);
        self::assertSame(0, $result->remaining);
        // The declared edge is returned, not a freshly built one, so a plain move() sees the label
        // and attributes its rule carries -- exactly as a labeled step does.
        self::assertSame('reserve', $result->events[0]->edge->label);
    }

    public function testBoundaryMovementsAcrossNilAreNeverConstrained(): void
    {
        // nil is the outside of the space, not a member of it, so no topology rule describes it --
        // the same reason the nil slot survives any SlotRuleBase. Were it constrained, adopting
        // EdgeRuleBase::None would silently break every create() and destroy() step.
        $space = self::space(EdgeRuleBase::None);
        $space->flow('make', static fn (Flow $flow) => $flow->create('wh1.fs'));
        $space->flow('scrap', static fn (Flow $flow) => $flow->destroy('wh1.fs'));

        $engine = new MovementEngine();

        $created = $engine->execute(new QuantityState($space), $space, 'make', 3);
        self::assertCount(1, $created->events);
        self::assertTrue($created->events[0]->edge->from->isNil());

        $destroyed = $engine->execute(new QuantityState($space, [['wh1.fs', 3]]), $space, 'scrap', 3);
        self::assertCount(1, $destroyed->events);
        self::assertTrue($destroyed->events[0]->edge->to->isNil());
    }

    public function testTighteningIsOneWay(): void
    {
        // A rule list assembled from independent contributors is what EdgeRuleBase exists for: one
        // appending rules under the default must not un-enforce what another declared.
        $space = self::space(EdgeRuleBase::None);
        $space->edgeRules([EdgeRule::allow('wh1.res', 'wh1.sd')]);

        $result = self::move($space, 'fs', 'sd');

        self::assertSame([], $result->events);
        self::assertSame(5, $result->remaining);
    }
}
