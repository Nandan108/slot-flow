<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Internal;

use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\RuleSet;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\SlotSpace;

/**
 * @internal
 */
final class SlotSpaceBuilder
{
    /** @var RuleSet<SlotRule> */
    private RuleSet $slotRules;

    /** @var RuleSet<EdgeRule> */
    private RuleSet $edgeRules;

    public function __construct(
        private SlotSpace $space,
    ) {
        $this->slotRules = new RuleSet([]);
        $this->edgeRules = new RuleSet([]);
    }

    /**
     * Set the slot rules for the SlotSpaceBuilder.
     *
     * @param array<SlotRule|RuleSet<SlotRule>> $rules
     */
    public function slotRules(array $rules): self
    {
        $this->slotRules = new RuleSet($rules);

        return $this;
    }

    /**
     * Set the edge rules for the SlotSpaceBuilder.
     *
     * @param array<EdgeRule|RuleSet<EdgeRule>> $rules
     */
    public function edgeRules(array $rules): self
    {
        $this->edgeRules = new RuleSet($rules);

        return $this;
    }

    public function compile(): SlotSpace
    {
        return $this->space
            ->slotRules($this->slotRules->all())
            ->edgeRules($this->edgeRules->all());
    }
}
