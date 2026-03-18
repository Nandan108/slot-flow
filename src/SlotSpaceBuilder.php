<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

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
            ->applySlotRules($this->slotRules->all())
            ->applyEdgeRules($this->edgeRules->all());
    }
}
