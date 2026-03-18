<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @template TRuleType of SlotRule|EdgeRule
 */
final class RuleSet
{
    /**
     * @param array<TRuleType|RuleSet<TRuleType>> $rules
     */
    public function __construct(
        public array $rules,
    ) {
    }

    /**
     * @template TFromRuleType of SlotRule|EdgeRule
     *
     * @param TFromRuleType|RuleSet<TFromRuleType> ...$rules
     *
     * @return self<TFromRuleType>
     */
    public static function from(SlotRule | EdgeRule | RuleSet ...$rules): self
    {
        return new self($rules);
    }

    /**
     * Apply $attribute metadata to all EdgeRules in the RuleSet.
     * This method recursively applies the metadata to any nested RuleSets as well.
     *
     * @param array<string, mixed> $attributes The metadata attributes to apply
     *
     * @return self<TRuleType> A new RuleSet instance with the metadata applied to all EdgeRules
     */
    public function meta(array $attributes): self
    {
        $rules = array_map(
            fn ($rule) => $rule instanceof EdgeRule || $rule instanceof RuleSet
                ? $rule->meta($attributes)
                : $rule,
            $this->rules,
        );

        /** @var self<TRuleType> */
        return new self($rules);
    }

    /**
     * Recursively flatten the RuleSet and return all rules as a single array.
     *
     * @return list<TRuleType>
     */
    public function all(): array
    {
        $allRules = [];
        foreach ($this->rules as $rule) {
            if ($rule instanceof RuleSet) {
                $allRules = array_merge($allRules, $rule->all());
            } else {
                $allRules[] = $rule;
            }
        }

        return $allRules;
    }
}
