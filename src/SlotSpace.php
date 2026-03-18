<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\SlotKeyCodec;

final class SlotSpace
{
    /** @var array<non-empty-string, list<non-empty-string>> */
    private array $dimensions = [];

    /** @var list<non-empty-string> */
    private array $dimensionNames = [];

    /** @var array<non-empty-string, array<non-empty-string, list<non-empty-string>>> */
    private array $dimensionExpansions = [];

    public SlotKeyCodec $codec;

    /** @var array<non-empty-string, SlotKey> */
    private array $slotsByKey = [];

    private SlotKey $nilSlot;

    /**
     * @var array<non-empty-string, MovementEdge[]>
     */
    public array $namedPaths = [];

    /**
     * Per slot key => the list of rules needed to generate the valid edges from that slot to other slots.
     *
     * @var array<non-empty-string, EdgeRule[]>
     */
    private array $edgeRulesByOriginSlot = [];

    /**
     * @var array<non-empty-string, array<non-empty-string, MovementEdge>>
     */
    private array $outgoingEdgeByOriginSlot = [];

    /**
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     * @param ?class-string<SlotKeyCodec>                     $codecClass
     */
    public static function define(
        array $dimensions,
        ?string $codecClass = null,
    ): self {
        return new self($dimensions, $codecClass);
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     * @param ?class-string<SlotKeyCodec>                     $codecClass
     */
    public function __construct(
        array $dimensions,
        ?string $codecClass = null,
    ) {
        /** @psalm-suppress UnsafeInstantiation */
        $this->codec = new ($codecClass ?? DefaultSlotKeyCodec::class)($this);

        $this->codec->initialDimensionValueValidation($dimensions);

        $this->dimensions = $dimensions;
        $this->dimensionNames = array_keys($dimensions);

        // initialize nil slot
        $nilKey = $this->codec->nilKey();
        $this->slotsByKey[$nilKey] = $this->nilSlot = new SlotKey($nilKey, null, $this);

        // build full cartesian product of dimensions to get all possible slots
        foreach ($this->cartesian($dimensions) as $values) {
            $key = $this->codec->serialize($values);

            $this->slotsByKey[$key] = new SlotKey($key, $values, $this);
        }
    }

    /**
     * This function is to be used after the SlotSpace is constructed and applies provided inclusion/exclusion
     * rules in sequential order, to shape the slot space into a shape meaningful for the application domain.
     *
     * A "full slot space" is defined by the cartesian product of all dimensions and their values, and contains
     * all possible combinations of dimension values.
     * Inclusion rules add matching slots to the valid set, while exclusion rules remove remove them.
     *
     * Since the rules are applied sequentially, later rules may override earlier ones.
     * The starting slot space is determined by the first rule in the list:
     * If it is an exclusion rule, then we start with a full slot space.
     * If it is an inclusion rule, then we start with an empty slot space.
     *
     * @param RuleSet<SlotRule>|list<SlotRule|RuleSet<SlotRule>> $rules list of patterns to include or exclude certain slots.
     *                                                                  If the list is empty, all combinations of dimensions are included.
     *                                                                  Exclusion patterns start with '-', inclusion patterns start with '+' or have no prefix. Patterns are applied in order, so later patterns override earlier ones.
     *                                                                  If the first pattern starts with '-', it is treated as an exclusion pattern and all slots are included by default. If the first pattern starts with '+', it is treated as an inclusion pattern and no slots are included by default.
     */
    public function applySlotRules(RuleSet | array $rules): self
    {
        // flatten potentially nested RuleSet into a single list of SlotRule
        $rules = (is_array($rules))
            ? (new RuleSet($rules))->all()
            : $rules->all();

        if (empty($rules)) {
            return $this; // no rules, keep all slots
        }

        $slots = $rules[0]->allow ? [] : $this->slotsByKey;

        foreach ($rules as $rule) {
            $ruleSlots = SlotPattern::from($rule->pattern, $this)->expand();
            $slots = $rule->allow
                ? array_merge($slots, $ruleSlots)
                : array_diff_key($slots, $ruleSlots);
        }

        $this->slotsByKey = $slots;

        return $this;
    }

    /**
     * This is used to generate the valid edges between slots, after the valid slots have been determined by the slot rules.
     * The starting point is always an empty set of edges, and the rules are applied sequentially to add edges between slots matching the from and to patterns.
     *
     * Edge rules are stored at origin slot level, to be lazily evaluated into actual edges when needed.
     *
     * @param RuleSet<EdgeRule>|list<EdgeRule|RuleSet<EdgeRule>> $rules
     */
    public function applyEdgeRules(RuleSet | array $rules): self
    {
        $rules = (is_array($rules))
            ? (new RuleSet($rules))->all()
            : $rules->all();

        if (empty($rules)) {
            return $this; // no rules, keep all slots
        }

        foreach ($rules as $rule) {
            $ruleSlots = SlotPattern::from($rule->from, $this)->expand();
            foreach ($ruleSlots as $slotKey => $_) {
                $this->edgeRulesByOriginSlot[$slotKey][] = $rule;
            }
        }

        return $this;
    }

    /**
     * Apply edge rules in sequence to generate a list of edges
     * Cache edge list by slot key at $this->outgoingEdgeByOriginSlot.
     *
     * @return array<non-empty-string, MovementEdge>
     */
    public function getEdgesFrom(SlotKey $from): array
    {
        $fromKey = $from->key();
        $edges = $this->outgoingEdgeByOriginSlot[$fromKey] ?? [];
        if ($edges) {
            return $edges;
        }

        $rules = $this->edgeRulesByOriginSlot[$fromKey] ?? [];

        $edges = [];
        foreach ($rules as $rule) {
            foreach (SlotPattern::from($rule->to, $this)->expand() as $toKey => $toSlot) {
                if ($rule->allow) {
                    $edges[$toKey] ??= new MovementEdge($from, $toSlot, $rule->label);
                    $edges[$toKey] = $edges[$toKey]->meta($rule->attributes);
                } else {
                    unset($edges[$toKey]);
                }
            }
        }

        return $this->outgoingEdgeByOriginSlot[$fromKey] = $edges;
    }

    /**
     * @return list<non-empty-string>
     */
    public function dimensionNames(): array
    {
        return $this->dimensionNames;
    }

    /** @return array<non-empty-string, list<non-empty-string>> */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Get the list of all possible valid values for a specific dimension.
     *
     * @param non-empty-string $dimension
     *
     * @return list<non-empty-string>
     */
    public function dimensionValues(string $dimension): array
    {
        return $this->dimensions[$dimension] ??
            throw new \InvalidArgumentException("Unknown dimension: $dimension");
    }

    /**
     * Expand a string or array of Slot pattern into a list of partials.
     *
     * @param string|array<non-empty-string, ?string>|null $pattern
     *
     * @return list<array<non-empty-string, non-empty-string>|null>
     */
    public function expandSlotPattern(string | array | null $pattern): array
    {
        if (!is_array($pattern)) {
            $pattern = $this->codec->deserialize($pattern);
            if (null === $pattern) {
                return [null];
            }
        }

        foreach ($pattern as $dimension => $val) {
            $values = $this->dimensions[$dimension] ?? null;
            $pattern[$dimension] = [];
            if (null === $values) {
                throw new \InvalidArgumentException("Unknown dimension: $dimension");
            }
            if ($this->codec->isWildcard($val)) {
                // treat missing and wildcard values as the same: matching all values for the dimension
                continue;
            }
            /** @var string $val */
            foreach (explode($this->codec->alternative(), $val) as $altVal) {
                /** @var non-empty-string $altVal */
                $patternValues = $this->codec->matchDimensionValues($dimension, $altVal);
                if (count($patternValues) === count($values)) {
                    // skip validation if the pattern matches all values for the dimension
                    continue 2;
                }
                $pattern[$dimension] = [...$pattern[$dimension], ...$patternValues];
            }
            /** @var array<non-empty-string, list<non-empty-string>> $pattern */
            if (count($pattern[$dimension]) === count($values)) {
                unset($pattern[$dimension]);
            }
        }

        /** @var array<non-empty-string, list<non-empty-string>> $pattern */
        return $this->cartesian(array_filter($pattern));
    }

    public function nilSlot(): SlotKey
    {
        return $this->nilSlot;
    }

    /**
     * Finds the slot corresponding to the given key or values. The input can be either a serialized key string
     * or an array of dimension values, which will be serialized using the defined serializer.
     *
     * All dimensions must be specified in the input, and wildcards are not allowed.
     *
     * @see SlotPattern::from for more flexible pattern matching with support for wildcards and missing values.
     *
     * @param list<string>|array<non-empty-string, string>|string $keyOrValues
     *
     * @return SlotKey|null Returns the SlotKey if found, or null if no matching slot exists
     */
    public function trySlot(array | string | null $keyOrValues): ?SlotKey
    {
        if (null === $keyOrValues || $this->codec->nilKey() === $keyOrValues) {
            return $this->nilSlot();
        }
        // If passed $keyOrValues is a list<non-empty-string>, treat the values as positional
        // and convert it to an associative array using dimension names as keys
        if (is_array($keyOrValues)) {
            $count = count($keyOrValues);
            if (count($this->dimensions) === $count
                && array_keys($keyOrValues) === range(0, $count - 1)) {
                $keyOrValues = array_combine($this->dimensionNames, $keyOrValues);
            }
            /** @var array<non-empty-string, string> $keyOrValues */
            $key = $this->codec->serialize($keyOrValues);
        } else {
            $key = $keyOrValues;
        }

        return $this->slotsByKey[$key] ?? null;
    }

    /**
     * Finds the slot corresponding to the given key or values. The input can be either a serialized key string
     * or an array of dimension values, which will be serialized using the defined serializer.
     *
     * All dimensions must be specified in the input, and wildcards are not allowed.
     *
     * @see SlotPattern::from for more flexible pattern matching with support for wildcards and missing values.
     *
     * @param list<string>|array<non-empty-string, string>|string $keyOrValues
     *
     * @throws \InvalidArgumentException if the resulting key does not correspond to any defined slot
     */
    public function slot(array | string | null $keyOrValues): SlotKey
    {
        $slot = $this->trySlot($keyOrValues);

        if (null === $slot) {
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            throw new \InvalidArgumentException('Unknown slot: '.(json_encode($keyOrValues) ?: 'unrepresentable value'));
        }

        return $slot;
    }

    /**
     * Finds all slots matching the given partial pattern, where the pattern can contain specific values,
     * and '*' can be used as a wildcard expression to match any value for a dimension.
     * The pattern can be either a serialized key string or an array of dimension values, where
     * missing or null values are treated as '*' wildcards.
     *
     * @param array<non-empty-string, ?string> $partial
     *
     * @return list<SlotKey>
     */
    public function matchPartial(?array $partial): array
    {
        // a null partial matches only the nil (source/sink) slot.
        if (null === $partial) {
            return [$this->nilSlot()];
        }

        // for each dimension in the pattern, get the list of matching values (either
        // the specific value or all values if it's a wildcard)
        $matched = [];
        foreach ($partial as $dim => $val) {
            $matched[$dim] = $this->codec->matchDimensionValues($dim, $val);
        }

        // generate the cartesian product of the matched values for each dimension
        $cartesian = $this->cartesian($matched + $this->dimensions);

        // convert each combination of values to a slot using the serializer and the slotsByKey map
        $result = [];
        foreach ($cartesian as $values) {
            $slot = $this->slotsByKey[$this->codec->serialize($values)] ?? null;
            // skip slots that have been excluded by the slot rules and therefore do not exist in the slotsByKey map
            if (null !== $slot) {
                $result[] = $slot;
            }
        }

        return $result;
    }

    /**
     * Generate edges using pattern expansion
     * Both wildcard and missing values are supported, with the same semantics.
     *
     * @param non-empty-string|array<non-empty-string, ?string>|null $fromPattern Specified values match with equality, wildcard/missing match with anything
     * @param non-empty-string|array<non-empty-string, ?string>|null $toPartials  Specified values are kept, wildcard/missing are filled in from the $fromPattern match
     *
     * @return MovementEdge[]
     */
    public function edgesBetween(array | string | null $fromPattern, array | string | null $toPartials): array
    {
        $edges = [];
        $toPartials = $this->expandSlotPattern($toPartials);
        foreach (SlotPattern::from($fromPattern, $this)->expand() as $fromSlot) {
            foreach ($toPartials as $toPartial) {
                foreach ($fromSlot->with($toPartial) as $toSlot) {
                    if ($fromSlot !== $toSlot) {
                        $edges[] = new MovementEdge($fromSlot, $toSlot);
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * Generate a full path from a list of (from, to) pattern tuples.
     * Wildcard are supported when both from and to patterns are specified.
     *
     * @psalm-type NodePattern = array<non-empty-string, string>|non-empty-string
     *
     * @param list<array{?NodePattern, ?NodePattern}|null> $fromToPatterns
     */
    public function cascade(array $fromToPatterns, bool $reverse): MovementPath
    {
        $edges = [];
        foreach (array_filter($fromToPatterns) as [$from, $to]) {
            $newEdges = $this->edgesBetween($from, $to);
            $edges = [...$edges, ...$newEdges];
        }

        $path = new MovementPath(...$edges);
        if ($reverse) {
            $path = $path->reverse(flipEdges: true);
        }

        return $path;
    }

    /**
     * Generate the cartesian product of the given dimensions, where the input
     * is an array of dimension name to list of values, and the output is a list
     * of all combinations of dimension values, where each combination is represented as an array of dimension name to value.
     *
     * Dimensions with empty value lists are ignored.
     *
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     *
     * @return list<array<non-empty-string, non-empty-string>>
     */
    private function cartesian(array $dimensions): array
    {
        $result = [[]];

        foreach (array_filter($dimensions) as $name => $values) {
            $append = [];

            foreach ($result as $product) {
                foreach ($values as $value) {
                    $append[] = $product + [$name => $value];
                }
            }

            $result = $append;
        }

        return $result;
    }
}
