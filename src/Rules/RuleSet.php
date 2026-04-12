<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Rules;

/**
 * Composable container for slot and edge rules.
 *
 * @template TRuleType of SlotRule|EdgeRule
 *
 * @api
 */
final class RuleSet
{
    /**
     * Create one possibly nested rule-set container.
     *
     * @param array<SlotRule|EdgeRule|RuleSet> $rules
     *
     * @psalm-param array<TRuleType|RuleSet<TRuleType>> $rules
     */
    public function __construct(
        public array $rules,
    ) {
    }

    /**
     * Create one rule set from individual rules or nested rule sets.
     *
     * @template TFromRuleType of SlotRule|EdgeRule
     *
     * @psalm-param TFromRuleType|RuleSet<TFromRuleType> ...$rules
     *
     * @psalm-return self<TFromRuleType>
     */
    public static function from(SlotRule | EdgeRule | RuleSet ...$rules): self
    {
        return new self($rules);
    }

    /**
     * Apply $attribute metadata to all rules in the RuleSet.
     * This method recursively applies the metadata to any nested RuleSets as well.
     *
     * @param array<string, mixed> $attributes The metadata attributes to apply
     *
     * @return self A new RuleSet instance with the metadata applied to all contained rules
     *
     * @psalm-return self<TRuleType>
     */
    public function meta(array $attributes): self
    {
        $rules = array_map(
            fn ($rule) => $rule->meta($attributes),
            $this->rules,
        );

        /** @var self<TRuleType> */
        return new self($rules);
    }

    /**
     * Recursively flatten the RuleSet and return all rules as a single array.
     *
     * @return list<EdgeRule>|list<SlotRule> a flat list of all rules contained in the RuleSet, including those in nested RuleSets
     *
     * @psalm-return list<TRuleType>
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
