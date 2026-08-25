<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\Rules\SlotRuleBase;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class SlotConfinementTest extends TestCase
{
    private static function space(): SlotSpace
    {
        return SlotSpace::define([
            'stt' => ['atp', 'pnd'],
            'loc' => ['oh', 'sup', 'inb'],
        ]);
    }

    /**
     * Every real slot. A `null` pattern means the *nil* slot, not "all", so the wildcard has to be
     * spelled per axis.
     *
     * @return list<string>
     */
    private static function keys(SlotSpace $space): array
    {
        $keys = array_map(
            static fn ($slot): string => (string) $slot,
            $space->matchPartial(['stt' => null, 'loc' => null]),
        );
        sort($keys);

        return $keys;
    }

    public function testConfinementRemovesOnlyTheConstrainedValuesMisplacedSlots(): void
    {
        $space = self::space()->confine('stt', 'pnd', 'loc', ['sup', 'inb']);

        self::assertSame(
            ['atp.inb', 'atp.oh', 'atp.sup', 'pnd.inb', 'pnd.sup'],
            self::keys($space),
            'pnd loses oh; atp is untouched everywhere',
        );
    }

    /**
     * The property the rule sequence cannot offer: two confinements compose to their intersection
     * whichever order they are applied in, so independently-authored constraints cannot fight.
     */
    public function testConfinementsCommute(): void
    {
        $forward = self::space()
            ->confine('stt', 'pnd', 'loc', ['sup', 'inb'])
            ->confine('stt', 'atp', 'loc', ['oh', 'sup']);

        $reverse = self::space()
            ->confine('stt', 'atp', 'loc', ['oh', 'sup'])
            ->confine('stt', 'pnd', 'loc', ['sup', 'inb']);

        self::assertSame(self::keys($forward), self::keys($reverse));
        self::assertSame(['atp.oh', 'atp.sup', 'pnd.inb', 'pnd.sup'], self::keys($forward));
    }

    /** A confinement narrows for its own value only — it can never empty the space. */
    public function testARepeatedConfinementIsIdempotent(): void
    {
        $once = self::space()->confine('stt', 'pnd', 'loc', ['sup']);
        $twice = self::space()->confine('stt', 'pnd', 'loc', ['sup'])->confine('stt', 'pnd', 'loc', ['sup']);

        self::assertSame(self::keys($once), self::keys($twice));
    }

    public function testAnUnknownDimensionIsRefused(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);

        self::space()->confine('stt', 'pnd', 'nope', ['sup']);
    }

    public function testAnUnknownValueIsRefused(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);

        self::space()->confine('stt', 'qi', 'loc', ['sup']);
    }

    public function testAnUnknownPermittedValueIsRefused(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);

        self::space()->confine('stt', 'pnd', 'loc', ['sup', 'nowhere']);
    }

    public function testConfiningToNothingIsRefusedRatherThanEmptyingTheValue(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);

        self::space()->confine('stt', 'pnd', 'loc', []);
    }

    /**
     * The base is stated, not read off the first rule. An inclusion-led sequence used to start from
     * nothing; under the default it now narrows a full space, and the caller asks for the old
     * meaning by name.
     */
    public function testTheBaseIsStatedRatherThanInferredFromTheFirstRule(): void
    {
        $rules = [SlotRule::allow(['stt' => 'atp', 'loc' => 'oh'])];

        self::assertSame(
            ['atp.oh'],
            self::keys(self::space()->slotRules($rules, SlotRuleBase::None)),
            'None: only what an inclusion admits',
        );

        self::assertCount(
            6,
            self::keys(self::space()->slotRules($rules)),
            'All (default): an inclusion over a full space narrows nothing',
        );
    }

    /**
     * With the base fixed, an exclusion narrows wherever it sits — which is what makes a sequence
     * safe to assemble from contributors who cannot know their own position in it.
     */
    public function testExclusionsNarrowRegardlessOfPosition(): void
    {
        $deny = SlotRule::deny(['stt' => 'pnd', 'loc' => 'oh']);
        $allow = SlotRule::allow(['stt' => 'atp', 'loc' => 'oh']);

        self::assertSame(
            self::keys(self::space()->slotRules([$deny, $allow])),
            self::keys(self::space()->slotRules([$allow, $deny])),
        );
    }

    /**
     * A pattern naming a dimension the space lacks matches nothing, because matching compares
     * against a null value. Silence there is indistinguishable from a typo — and over an empty
     * base it yields a space with no slots and no explanation.
     */
    public function testARuleNamingAnUnknownDimensionIsRefused(): void
    {
        $this->expectException(SlotFlowInvalidArgumentException::class);

        self::space()->slotRules([SlotRule::deny(['chan' => 'web'])]);
    }
}
