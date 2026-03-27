<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Contracts\SlotCodec;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Internal\SlotPattern;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\RuleSet;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\Time\TimeAxis;

/**
 * Defines the slot space, its dimensions, the slots that exist within it, and the edges and flows that operate on it.
 *
 * @psalm-type TDimensionName = non-empty-string the name and value of a dimension must be non-empty strings
 * @psalm-type TDimensionValue = non-empty-string dimension values must be non-empty strings, and each dimension must have at least one value
 * @psalm-type TSlotKey = non-empty-string the serialized representation of a slot, used as a unique identifier, can be used as a slot pattern that matches exactly one slot
 * @psalm-type TSlotTuple = list<TDimensionValue> a tuple of dimension values in the order of dimension names, used as an alternative way to specify a slot
 * @psalm-type TSlotPartial array<TDimensionName, TDimensionValue> a partial associative specification of concrete dimension values; dimensions may be omitted, but provided values are concrete; used as a slot pattern that can match multiple slots
 * @psalm-type TSlotValues array<TDimensionName, TDimensionValue> a full associative specification of concrete dimension values; all dimensions must be present and all values must be concrete; used as a slot pattern that matches exactly one slot
 * @psalm-type TDimensionValuePattern ?non-empty-string a null or string pattern to match dimension values. Used in slot patterns. String may contain wildcards as allowed by codec. Null is equivaldent to the match-all wildcard.
 * @psalm-type TSlotTuplePattern list<TDimensionValuePattern> a tuple of dimension value patterns in the order of dimension names, used as an alternative way to specify a slot pattern.
 * @psalm-type TSlotArrayPattern array<TDimensionName, TDimensionValuePattern> a pattern specified as an associative array of dimension name to dimension value pattern, where missing or null values are treated as wildcards that match any value for the dimension. Used as a slot pattern that can match multiple slots.
 * @psalm-type TSlotPattern TSlotTuplePattern|TSlotArrayPattern|TSlotKey|null a slot pattern can be:
 *  - a string slot pattern (e.g. "sup.*.foo|bar") that is deserialized using the codec
 *  - an tuple: array value pattern in the exact order and number of dimensions defined in the slot space, where each value can be a specific value or a wildcard (null or wildcard string as defined by codec)
 *  - an array of dimension name to value patterns where missing or null values are treated as wildcards
 *  - null, to match the nil slot (for source/sink slots in edges)
 * @psalm-type TEdgePattern array{from: TSlotPattern, to: TSlotPattern}|array{TSlotPattern, TSlotPattern} a pattern to define an edge between slots, consisting of a from pattern and a to pattern. Can be used in cascade step definitions.
 *
 * @api
 */
final class SlotSpace
{
    /**
     * @var array<non-empty-string, list<non-empty-string>>
     *
     * @psalm-var array<TDimensionName, list<TDimensionValue>>
     */
    private array $dimensions = [];

    /** @var list<TDimensionName> */
    private array $dimensionNames = [];

    public SlotCodec $codec;
    public readonly ?TimeAxis $timeAxis;

    /**
     * @var array<non-empty-string, Slot>
     *
     * @psalm-var array<TSlotKey, Slot>
     */
    private array $slotsByKey = [];

    /** @var array<non-empty-string, list<Slot>> */
    private array $slotsByPattern = [];

    private Slot $nilSlot;

    /** @var array<non-empty-string, Flow> */
    public array $flows = [];

    /** @var array<non-empty-string, Flow> */
    public array $cascades = [];

    /**
     * Per slot key => the list of rules needed to generate the valid edges from that slot to other slots.
     *
     * @var array<non-empty-string, list<EdgeRule>>
     *
     * @psalm-var array<TSlotKey, list<EdgeRule>>
     */
    private array $edgeRulesByOriginSlot = [];

    /**
     * @var array<non-empty-string, array<non-empty-string, MovementEdge>>
     *
     * @psalm-var array<TSlotKey, array<TSlotKey, MovementEdge>>
     */
    private array $outgoingEdgeByOriginSlot = [];

    /**
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     * @param TimeAxis|?class-string<SlotCodec>               $timeAxis
     * @param ?class-string<SlotCodec>                        $codecClass
     *
     * @psalm-param array<TDimensionName, list<TDimensionValue>> $dimensions
     */
    public static function define(
        array $dimensions,
        TimeAxis | string | null $timeAxis = null,
        ?string $codecClass = null,
    ): self {
        return new self($dimensions, $timeAxis, $codecClass);
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     * @param TimeAxis|?class-string<SlotCodec>               $timeAxis
     * @param ?class-string<SlotCodec>                        $codecClass
     *
     * @psalm-param array<TDimensionName, list<TDimensionValue>> $dimensions
     */
    public function __construct(
        array $dimensions,
        TimeAxis | string | null $timeAxis = null,
        ?string $codecClass = null,
    ) {
        $resolvedTimeAxis = $timeAxis instanceof TimeAxis ? $timeAxis : null;
        $resolvedCodecClass = is_string($timeAxis) ? $timeAxis : $codecClass;

        $this->timeAxis = $resolvedTimeAxis;

        /** @psalm-suppress UnsafeInstantiation */
        $this->codec = new ($resolvedCodecClass ?? DefaultSlotKeyCodec::class)($this, $this->timeAxis);

        $this->codec->initialDimensionValueValidation($dimensions);

        $this->dimensions = $dimensions;
        $this->dimensionNames = array_keys($dimensions);

        // initialize nil slot
        $nilKey = $this->codec->nilKey();
        $this->slotsByKey[$nilKey] = $this->nilSlot = new Slot($nilKey, null, $this);

        // build full cartesian product of dimensions to get all possible slots
        foreach ($this->cartesian($dimensions) as $values) {
            $key = $this->codec->serialize($values);

            $this->slotsByKey[$key] = new Slot($key, $values, $this);
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
    public function slotRules(RuleSet | array $rules): self
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
            if ($rule->allow) {
                foreach ($ruleSlots as $slotKey => $slot) {
                    $slots[$slotKey] = ($slots[$slotKey] ?? $slot)->withMeta($rule->attributes);
                }

                continue;
            }

            $slots = array_diff_key($slots, $ruleSlots);
        }

        $this->slotsByKey = $slots;
        $this->slotsByPattern = [];

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
    public function edgeRules(RuleSet | array $rules): self
    {
        /** @psalm-suppress UnnecessaryVarAnnotation */
        /** @var list<EdgeRule> $rules */
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
     *
     * @psalm-return array<TSlotKey, MovementEdge>
     */
    public function getEdgesFrom(Slot $from): array
    {
        $fromKey = $from->key;
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
     *
     * @psalm-return list<TDimensionName>
     */
    public function dimensionNames(): array
    {
        return $this->dimensionNames;
    }

    /**
     * @return array<non-empty-string, list<non-empty-string>>
     *
     * @psalm-return array<TDimensionName, list<TDimensionValue>>
     *
     * @api
     */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Get the list of all possible valid values for a specific dimension.
     *
     * @param non-empty-string $dimension
     *
     * @psalm-param TDimensionName $dimension
     *
     * @return list<non-empty-string>
     *
     * @psalm-return list<TDimensionValue>
     */
    public function dimensionValues(string $dimension): array
    {
        return $this->dimensions[$dimension] ??
            throw new SlotFlowInvalidArgumentException(
                "Unknown dimension: $dimension",
                ['dimension' => $dimension, 'known_dimensions' => $this->dimensionNames],
            );
    }

    /**
     * @param array $names list of dimension names that must all exist in the slot space
     *
     * @psalm-param array<string> $names
     *
     * @throws SlotFlowInvalidArgumentException
     */
    public function validateKnownDimensionNames(array $names): void
    {
        if ($extra = array_diff($names, $this->dimensionNames)) {
            if (1 === count($extra)) {
                throw new SlotFlowInvalidArgumentException(
                    'Unknown dimension: '.reset($extra),
                    ['dimension' => reset($extra), 'known_dimensions' => $this->dimensionNames],
                );
            }

            throw new SlotFlowInvalidArgumentException(
                'Invalid slot values: unknown dimensions: ['.implode(', ', $extra).']',
                ['unknown_dimensions' => array_values($extra), 'known_dimensions' => $this->dimensionNames],
            );
        }
    }

    /**
     * Expand a string or array of Slot pattern into a list of partials.
     *
     * @param string|array<non-empty-string, ?string>|null $pattern
     *
     * @psalm-param TSlotPattern $pattern
     *
     * @return list<array<non-empty-string, non-empty-string>|null>
     *
     * @psalm-return list<TSlotPartial>|list<null>
     *
     * @throws SlotFlowInvalidArgumentException if the pattern is invalid or contains unknown dimensions or values
     */
    public function expandSlotPattern(string | array | null $pattern): array
    {
        if (!is_array($pattern)) {
            $pattern = $this->codec->deserialize($pattern);
            if (null === $pattern) {
                return [null];
            }
        } elseif (array_is_list($pattern)) {
            if (count($pattern) !== count($this->dimensionNames)) {
                throw new SlotFlowInvalidArgumentException(
                    'Slot pattern tuple must have the same number of elements as dimensions: '.count($this->dimensionNames),
                    ['expected_dimension_count' => count($this->dimensionNames), 'received_count' => count($pattern)],
                );
            }
            $pattern = array_combine($this->dimensionNames, $pattern);
        } else {
            /** @var array<non-empty-string, string|null> $pattern */
            $this->validateKnownDimensionNames(array_keys($pattern));
        }

        foreach ($pattern as $dimension => $val) {
            $values = $this->dimensions[$dimension];
            $pattern[$dimension] = [];

            if ($this->codec->isWildcard($val)) {
                // treat missing and wildcard values as the same: matching all values for the dimension
                continue;
            }
            /** @var non-empty-string $dimension */
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

    public function nilSlot(): Slot
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
     * @param Slot|string|array<string>|null $keyOrValues
     *
     * @psalm-param Slot|TSlotPattern $keyOrValues
     *
     * @return ?Slot Returns the SlotKey if found, or null if no matching slot exists
     */
    public function trySlot(Slot | array | string | null $keyOrValues, bool $throwOnInvalidDimensionValues = false): ?Slot
    {
        if ($keyOrValues instanceof Slot) {
            return $keyOrValues;
        }

        if (null === $keyOrValues || $this->codec->nilKey() === $keyOrValues) {
            return $this->nilSlot();
        }
        // If passed $keyOrValues is a list<non-empty-string>, treat the values as positional
        // and convert it to an associative array using dimension names as keys
        if (is_array($keyOrValues)) {
            if (array_is_list($keyOrValues)) {
                $keyOrValues = array_combine($this->dimensionNames, $keyOrValues);
            } else {
                // if it's already an associative array, validate keys are exactly equal to dimension names
                $keys = array_keys($keyOrValues);
                $missing = array_diff($this->dimensionNames, $keys);
                $extra = array_diff($keys, $this->dimensionNames);
                if ($missing || $extra) {
                    // include the missing and extra keys in the error message for easier debugging
                    $gotMissing = $missing ? 'missing dimensions: ['.implode(', ', $missing).']' : '';
                    $gotExtra = $extra ? 'unknown dimensions: ['.implode(', ', $extra).']' : '';
                    throw new SlotFlowInvalidArgumentException(
                        'Invalid slot values: got '.implode(', ', $this->dimensionNames).implode(', ', array_filter([$gotMissing, $gotExtra])),
                        [
                            'expected_dimensions' => $this->dimensionNames,
                            'received_dimensions' => $keys,
                            'missing_dimensions'  => array_values($missing),
                            'unknown_dimensions'  => array_values($extra),
                        ],
                    );
                }
            }

            /** @psalm-var TSlotArrayPattern $keyOrValues */
            $key = $this->codec->serialize($keyOrValues);
        } else {
            $key = $keyOrValues;
        }

        $slot = $this->slotsByKey[$key] ?? null;
        if (null === $slot && $throwOnInvalidDimensionValues) {
            // try to deserialize the key to get more specific error messages about invalid dimension values
            /** @psalm-var TSlotArrayPattern $keyOrValues */
            $keyOrValues = is_array($keyOrValues) ? $keyOrValues : $this->codec->deserialize($key);
            $this->codec->validateDimensionValues($keyOrValues, false);
        }

        return $slot;
    }

    /**
     * Finds the slot corresponding to the given key or values. The input can be either a serialized key string
     * or an array of dimension values, which will be serialized using the defined serializer.
     *
     * All dimensions must be specified in the input, and wildcards are not allowed.
     *
     * @see SlotPattern::from for more flexible pattern matching with support for wildcards and missing values.
     *
     * @param Slot|string|array<string>|null $keyOrValues
     *
     * @psalm-param Slot|TSlotPattern $keyOrValues
     *
     * @throws SlotFlowInvalidArgumentException if the resulting key does not correspond to any defined slot
     */
    public function slot(Slot | array | string | null $keyOrValues): Slot
    {
        $slot = $this->trySlot($keyOrValues, true);

        if (null === $slot) {
            /** @psalm-suppress RiskyTruthyFalsyComparison */
            throw new SlotFlowInvalidArgumentException(
                'Unknown slot: '.(json_encode($keyOrValues) ?: 'unrepresentable value'),
                ['slot' => $keyOrValues],
            );
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
     * @psalm-param array<TDimensionName, TDimensionValuePattern> $partial
     *
     * @return list<Slot>
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
     * Resolve a slot pattern directly to matching slots.
     *
     * Exact string keys are short-circuited through the slot registry. General string
     * patterns are cached after expansion so repeated lookups can reuse the resolved
     * slot list without re-running pattern deserialization and matching.
     *
     * @psalm-param TSlotPattern $pattern
     *
     * @return list<Slot>
     */
    public function matchPattern(array | string | null $pattern): array
    {
        $cacheKey = is_string($pattern)
            ? $pattern
            : $this->codec->serialize($pattern);

        $slot = $this->slotsByKey[$cacheKey] ?? null;
        if (null !== $slot) {
            return [$slot];
        }

        if (isset($this->slotsByPattern[$cacheKey])) {
            return $this->slotsByPattern[$cacheKey];
        }

        $slotsByKey = [];
        foreach ($this->expandSlotPattern($pattern) as $partial) {
            foreach ($this->matchPartial($partial) as $slot) {
                $slotsByKey[$slot->key] = $slot;
            }
        }

        $slots = array_values($slotsByKey);
        $this->slotsByPattern[$cacheKey] = $slots;

        return $slots;
    }

    /**
     * Generate edges using pattern expansion
     * Both wildcard and missing values are supported, with the same semantics.
     *
     * @param non-empty-string|array<non-empty-string, ?string>|null $fromPattern Specified values match with equality, wildcard/missing match with anything
     * @param non-empty-string|array<non-empty-string, ?string>|null $toPattern   Specified values are kept, wildcard/missing are filled in from the $fromPattern match
     *
     * @psalm-param TSlotPattern $fromPattern Specified values match with equality, wildcard/missing match with anything
     * @psalm-param TSlotPattern $toPattern  Specified values are kept, wildcard/missing are filled in from the $fromPattern match
     *
     * @return MovementEdge[]
     */
    public function edgesBetween(array | string | null $fromPattern, array | string | null $toPattern): array
    {
        $edges = [];
        $toPartials = $this->expandSlotPattern($toPattern);
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
     * Return all currently valid edges generated from rules matching any of the given labels.
     *
     * @param list<non-empty-string> $labels
     *
     * @return list<MovementEdge>
     */
    public function edgesByLabels(array $labels): array
    {
        $labelSet = array_fill_keys($labels, true);
        $edges = [];

        foreach ($this->slotsByKey as $slot) {
            foreach ($this->getEdgesFrom($slot) as $edge) {
                if (null === $edge->label || !isset($labelSet[$edge->label])) {
                    continue;
                }

                $edges[] = $edge;
            }
        }

        return $edges;
    }

    /**
     * Register a named cascade definition.
     *
     * @deprecated use flow() instead
     *
     * @psalm-suppress DeprecatedClass
     *
     * @param non-empty-string $name
     * @param \Closure(Cascade):mixed|list<array{
     *     0?: array<int|string, string|null>|string|null,
     *     1?: array<int|string, string|null>|string|null,
     *     from?: array<int|string, string|null>|string|null,
     *     to?: array<int|string, string|null>|string|null
     * }> $builder
     *
     * @psalm-param \Closure(Cascade):mixed|list<TEdgePattern> $builder
     */
    public function cascade(string $name, \Closure | array $builder): self
    {
        /** @psalm-suppress DeprecatedClass */
        if (isset($this->flows[$name])) {
            throw new SlotFlowInvalidArgumentException(
                "Cascade '$name' already defined",
                ['cascade' => $name],
            );
        }

        if (is_array($builder)) {
            $cascade = Cascade::define(
                $name,
                static function (Cascade $cascade) use ($builder): void {
                    foreach ($builder as $edgePattern) {
                        /** @psalm-suppress DocblockTypeContradiction */
                        if (array_is_list($edgePattern)) {
                            /** @var array{TSlotPattern, TSlotPattern} $edgePattern */
                            $cascade->move($edgePattern[0], $edgePattern[1]);
                        } else {
                            /** @var array{from:TSlotPattern, to:TSlotPattern} $edgePattern */
                            $cascade->move($edgePattern['from'], $edgePattern['to']);
                        }
                    }
                },
            );
            $this->flows[$name] = $cascade;
            $this->cascades[$name] = $cascade;

            return $this;
        }

        $this->flows[$name] = Cascade::define($name, $builder);
        $this->cascades[$name] = $this->flows[$name];

        return $this;
    }

    /**
     * Register a named flow definition.
     *
     * @param non-empty-string $name
     * @param \Closure(Flow):mixed|list<array{
     *     0?: array<int|string, string|null>|string|null,
     *     1?: array<int|string, string|null>|string|null,
     *     from?: array<int|string, string|null>|string|null,
     *     to?: array<int|string, string|null>|string|null
     * }> $builder
     *
     * @psalm-param \Closure(Flow):mixed|list<TEdgePattern> $builder
     */
    public function flow(string $name, \Closure | array $builder): self
    {
        if (isset($this->flows[$name])) {
            throw new SlotFlowInvalidArgumentException(
                "Flow '$name' already defined",
                ['flow' => $name],
            );
        }

        if (is_array($builder)) {
            $flow = Flow::define(
                $name,
                static function (Flow $flow) use ($builder): void {
                    foreach ($builder as $edgePattern) {
                        /** @psalm-suppress DocblockTypeContradiction */
                        if (array_is_list($edgePattern)) {
                            /** @var array{TSlotPattern, TSlotPattern} $edgePattern */
                            $flow->move($edgePattern[0], $edgePattern[1]);
                        } else {
                            /** @var array{from:TSlotPattern, to:TSlotPattern} $edgePattern */
                            $flow->move($edgePattern['from'], $edgePattern['to']);
                        }
                    }
                },
            );
            $this->flows[$name] = $flow;
            $this->cascades[$name] = $flow;

            return $this;
        }

        $this->flows[$name] = Flow::define($name, $builder);
        $this->cascades[$name] = $this->flows[$name];

        return $this;
    }

    public function getFlow(string $name): Flow
    {
        if (!isset($this->flows[$name])) {
            throw new SlotFlowInvalidArgumentException(
                "Flow '$name' not defined",
                ['flow' => $name],
            );
        }

        return $this->flows[$name];
    }

    /**
     * @deprecated use getFlow() instead
     *
     * @psalm-suppress DeprecatedClass
     */
    public function getCascade(string $name): Cascade
    {
        /** @psalm-suppress DeprecatedClass */
        if (!isset($this->flows[$name])) {
            throw new SlotFlowInvalidArgumentException(
                "Cascade '$name' not defined",
                ['cascade' => $name],
            );
        }

        $flow = $this->flows[$name];

        /** @psalm-suppress DeprecatedClass */
        /** @psalm-suppress MixedArgumentTypeCoercion */
        return $flow instanceof Cascade
            ? $flow
            : Cascade::define($flow->name(), static function (Cascade $cascade) use ($flow): void {
                foreach ($flow->steps() as $step) {
                    if (null !== $step->edgeLabels) {
                        $builder = $cascade->stepByLabeledEdges(...$step->edgeLabels);
                    } else {
                        $builder = $cascade->move($step->from, $step->to);
                    }

                    foreach (array_reverse($step->orderingPolicies) as $policy) {
                        $builder->orderBy($policy);
                    }

                    foreach ($step->filterPolicies as $policy) {
                        $builder->filter($policy);
                    }

                    foreach ($step->quantityConstraintPolicies as $policy) {
                        $builder->constraint($policy);
                    }

                    foreach ($step->allocationPolicies as $policy) {
                        $builder->allocate($policy);
                    }
                }
            });
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

        /** @psalm-var list<array<non-empty-string, non-empty-string>> $result */
        return $result;
    }
}
