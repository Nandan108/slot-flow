<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class SlotSpace
{
    /** @var array<non-empty-string, list<non-empty-string>> */
    private array $dimensions = [];

    /** @var list<non-empty-string> */
    private array $dimensionNames = [];

    /** @var array<non-empty-string, array<non-empty-string, list<non-empty-string>>> */
    private array $dimensionExpansions = [];

    /** @var \Closure(array<non-empty-string, string>): non-empty-string */
    public \Closure $serializer;
    /** @var \Closure(string): array<non-empty-string, string> */
    public \Closure $deserializer;

    /** @var array<string, SlotKey> */
    private array $slotsByKey = [];

    /**
     * @param array<non-empty-string, list<non-empty-string>>              $dimensions
     * @param ?\Closure(array<non-empty-string, string>): non-empty-string $serializer
     * @param ?\Closure(string): array<non-empty-string, string>           $deserializer
     */
    public static function define(
        array $dimensions,
        ?callable $serializer = null,
        ?callable $deserializer = null,
        string $serializeSeparator = '.',
    ): self {
        return new self(
            $dimensions,
            $serializer,
            $deserializer,
            $serializeSeparator,
        );
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>>              $dimensions
     * @param ?\Closure(array<non-empty-string, string>): non-empty-string $serializer
     * @param ?\Closure(string): array<non-empty-string, string>           $deserializer
     */
    public function __construct(
        array $dimensions,
        ?callable $serializer,
        ?callable $deserializer,
        string $serializeSeparator = '.',
    ) {
        if (null === $deserializer && '' === $serializeSeparator) {
            throw new \InvalidArgumentException('A non-empty serialize separator is required when using the default serializer/deserializer');
        }

        // throw if any of the values contain the separator, since the default serializer uses it
        foreach ($dimensions as $name => $values) {
            foreach ($values as $value) {
                if (str_contains($value, $serializeSeparator)) {
                    throw new \InvalidArgumentException("Dimension values cannot contain '$serializeSeparator': $name => $value");
                }
            }
        }

        $this->dimensions = $dimensions;
        $this->serializer = \Closure::fromCallable(
            $serializer ??
            /** @param array<non-empty-string, string> $values **/
            function (array $values) use ($serializeSeparator) {
                // throw if $value keys are not the same as dimension names
                if (array_keys($values) !== $this->dimensionNames) {
                    throw new \InvalidArgumentException('Value keys must match dimension names: '.implode(', ', $this->dimensionNames));
                }

                // make sure the values are in the same order as the dimensions
                /** @var non-empty-string $key */
                $key = implode($serializeSeparator, array_map(fn ($name) => $values[$name], $this->dimensionNames));

                return $key;
            },
        );

        $this->deserializer = \Closure::fromCallable(
            $deserializer ??
            function (string $key) use ($serializeSeparator) {
                $exploded = explode($serializeSeparator, $key);
                // throw if the number of exploded parts does not match the number of dimensions
                if (count($exploded) !== count($this->dimensions)) {
                    throw new \InvalidArgumentException("Key '$key' does not match the expected format for dimensions: ".implode(', ', $this->dimensionNames));
                }
                $dimensions = array_combine($this->dimensionNames, $exploded);

                // throw if one of the values does not match the expected values for its dimension
                $this->validateDimensionValues($dimensions, true);

                // split the key by the separator and map it back to the dimension names
                return $dimensions;
            },
        );

        foreach ($this->cartesian($dimensions) as $values) {
            $key = ($this->serializer)($values);

            $this->slotsByKey[$key] = new SlotKey($key, $values, $this);
        }
    }

    /**
     * @param non-empty-string $dimension
     *
     * @return list<non-empty-string>
     */
    private function matchDimensionValues(string $dimension, string $pattern): array
    {
        $values = $this->dimensions[$dimension] ?? null;
        if (null === $values) {
            throw new \InvalidArgumentException("Unknown dimension: $dimension");
        }

        if (str_contains($pattern, '*')) {
            $cached = $this->dimensionExpansions[$dimension][$pattern] ?? null;
            if (null !== $cached) {
                return $cached;
            }
            $parts = explode('*', $pattern);
            $regex = '/^'.implode('.*', array_map('preg_quote', $parts)).'$/';
            /** @var list<non-empty-string> $matches */
            $matches = array_values(preg_grep($regex, $values));

            return $this->dimensionExpansions[$dimension][$pattern] = $matches;
        }

        if (!in_array($pattern, $values, true)) {
            throw new \InvalidArgumentException(
                "Value '$pattern' is not valid for dimension '$dimension'. Expected values: "
                .implode(', ', $values),
            );
        }

        return [$pattern];
    }

    /**
     * Summary of validateDimension.
     *
     * @param array<non-empty-string, array<string|null>|string|null> $values
     *
     * @throws \InvalidArgumentException
     */
    public function validateDimensionValues(array $values, bool $allowWildcards = false, bool $allowValueArrays = false): void
    {
        foreach ($values as $dim => $val) {
            if (is_array($val)) {
                if (!$allowValueArrays) {
                    throw new \InvalidArgumentException("Array values are not allowed for dimension '$dim'");
                }
                foreach ($val as $v) {
                    $this->validateDimensionValue($dim, $v, $allowWildcards);
                }
            } else {
                $this->validateDimensionValue($dim, $val, $allowWildcards);
            }
        }
    }

    /**
     * @param non-empty-string $dimension
     *
     * @throws \InvalidArgumentException
     */
    public function validateDimensionValue(string $dimension, ?string $value, bool $allowWildcards): void
    {
        $value = $value ?? '*' ?: '*';
        $isWildcard = '*' === $value;
        $hasWildCard = $isWildcard || str_contains($value, '*');

        if ($hasWildCard && !$allowWildcards) {
            throw new \InvalidArgumentException("Value for dimension '$dimension' cannot be empty or null");
        }
        if ($isWildcard) {
            return;
        }

        if (!$this->matchDimensionValues($dimension, $value)) {
            throw new \InvalidArgumentException(
                "Unknown $dimension: '$value'. Expected values: ".implode(', ', $this->dimensions[$dimension] ?? []),
            );
        }
    }

    /**
     * @param string|array<non-empty-string, ?string>|null $pattern
     *
     * @return SlotKey[]
     */
    public function slots(string | array | null $pattern = null): array
    {
        if (null === $pattern) {
            return $this->slotsByKey;
        }

        // first expand the pattern if it's a string
        if (is_string($pattern)) {
            $pattern = ($this->deserializer)($pattern);
        }
        // then expand any wildcard values in the pattern to get a list of matching slots
        foreach ($pattern as $dim => $val) {
            $pattern[$dim] = $this->matchDimensionValues($dim, $val ?? '*' ?: '*');
        }
        /** @var array<non-empty-string, list<non-empty-string>> $pattern */

        // now we have a list of possible values for each dimension, we can generate all combinations of those values and find the corresponding slots
        $slots = [];
        foreach ($this->cartesian($pattern) as $values) {
            $key = ($this->serializer)($values);

            $slots[] = new SlotKey($key, $values, $this);
        }

        return $slots;
    }

    /**
     * Finds the slot corresponding to the given key or values. The input can be either a serialized key string
     * or an array of dimension values, which will be serialized using the defined serializer.
     * Throws an exception if the resulting key does not correspond to any defined slot.
     *
     * @param list<string>|array<non-empty-string, string>|string $keyOrValues
     *
     * @throws \InvalidArgumentException
     */
    public function slot(array | string $keyOrValues): SlotKey
    {
        // If passed $keyOrValues is a list<non-empty-string>, treat the values as positional
        // and convert it to an associative array using dimension names as keys
        if (is_array($keyOrValues)) {
            $count = count($keyOrValues);
            if (count($this->dimensions) === $count
                && array_keys($keyOrValues) === range(0, $count - 1)) {
                $keyOrValues = array_combine($this->dimensionNames, $keyOrValues);
            }
            /** @var array<non-empty-string, string> $keyOrValues */
            $key = ($this->serializer)($keyOrValues);
        } else {
            $key = $keyOrValues;
        }

        $slot = $this->slotsByKey[$key] ?? null;

        if (null === $slot) {
            throw new \InvalidArgumentException("Unknown slot: $key");
        }

        return $slot;
    }

    /**
     * Finds all slots matching the given pattern, where the pattern can contain specific values,
     * and '*' can be used as a wildcard expression to match any value for a dimension.
     * The pattern can be either a serialized key string or an array of dimension values, where
     * missing or null values are treated as '*' wildcards.
     *
     * @param array<non-empty-string, ?string>|string $pattern
     *
     * @return list<SlotKey>
     */
    private function match(array | string $pattern): array
    {
        if (is_string($pattern)) {
            $pattern = $this->normalizePatternToArray($pattern);
        }

        // for each dimension in the pattern, get the list of matching values (either
        // the specific value or all values if it's a wildcard)
        $matched = [];
        foreach ($pattern as $dim => $val) {
            $matched[$dim] = $this->matchDimensionValues($dim, $val ?? '*' ?: '*');
        }

        // then generate the cartesian product of the matched values for each dimension
        // and convert each combination of values to a slot using the serializer and the
        // slotsByKey map
        /** @var list<SlotKey> */
        return array_map(
            fn ($values) => $this->slot(($this->serializer)($values)),
            $this->cartesian($matched),
        );
    }

    /**
     * @param array<non-empty-string, ?string>|string $pattern
     * @param bool                                    $allowWildcards whether to allow wildcard values in the pattern, if false, all values must be non-empty strings and wildcards are not allowed
     *
     * @return ($allowWildcards is false ? array<non-empty-string, string> : array<non-empty-string, ?string>)
     */
    private function normalizePatternToArray(array | string $pattern, bool $allowWildcards = true): array
    {
        if (is_string($pattern)) {
            $pattern = ($this->deserializer)($pattern);
            if (!$allowWildcards) {
                $this->validateDimensionValues($pattern, false);
            }
        } else {
            // validate the pattern values
            $this->validateDimensionValues($pattern, $allowWildcards);
        }

        return $pattern;
    }

    /**
     * Generate edges using pattern expansion
     * Both wildcard and missing values are supported, with the same semantics.
     *
     * @param array<non-empty-string, ?string>|string $fromPattern Specified values match with equality, wildcard/missing match with anything
     * @param array<non-empty-string, ?string>|string $toPattern   Specified values are kept, wildcard/missing are filled in from the $fromPattern match
     *
     * @return MovementEdge[]
     */
    public function edgesBetween(array | string $fromPattern, array | string $toPattern): array
    {
        /** @var array<non-empty-string, string> */
        $toPattern = $this->normalizePatternToArray($toPattern);

        // remove missing-values placeholders from $toPattern, those will be filled in from the $from slot
        $toPattern = array_filter($toPattern, fn (mixed $value) => '*' !== $value && '' !== $value);

        $edges = [];
        foreach ($this->match($fromPattern) as $from) {
            // values from $to override values from $from when they exist
            $edges[] = new MovementEdge($from, $from->with($toPattern));
        }

        return $edges;
    }

    /**
     * Generate a single edge. Wildcard and missing values are not supported.
     *
     * @psalm-type NodePattern = array<non-empty-string, string>|string
     *
     * @param ?NodePattern $fromPattern
     * @param ?NodePattern $toPattern
     */
    public function move(array | string | null $fromPattern, array | string | null $toPattern): MovementEdge
    {
        $toSlot =
        /** @param ?NodePattern $p */
        fn (array | string | null $p): ?SlotKey => null === $p
            ? null
            : $this->slot($this->normalizePatternToArray($p, false));

        return new MovementEdge($toSlot($fromPattern), $toSlot($toPattern));
    }

    /**
     * Generate a full path from a list of (from, to) pattern tuples.
     * Wildcard are supported when both from and to patterns are specified.
     *
     * @psalm-type NodePattern = array<non-empty-string, string>|string
     *
     * @param list<array{?NodePattern, ?NodePattern}|null> $fromToPatterns
     */
    public function path(array $fromToPatterns, bool $reverse): MovementPath
    {
        $edges = [];
        foreach (array_filter($fromToPatterns) as $fromTo) {
            [$from, $to] = $fromTo;
            if (null === $from) {
                if (null === $to) {
                    continue; // skip no-op edge
                }
                $newEdges = array_map(fn (SlotKey $mp) => new MovementEdge($mp, null), $this->match($to));
            } elseif (null === $to) {
                $newEdges = array_map(fn (SlotKey $mp) => new MovementEdge(null, $mp), $this->match($from));
            } else {
                $newEdges = $this->edgesBetween($from, $to);
            }
            $edges = [...$edges, ...$newEdges];
        }

        $path = new MovementPath(...$edges);
        if ($reverse) {
            $path = $path->reverse(flipEdges: true);
        }

        return $path;
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     *
     * @return list<array<non-empty-string, non-empty-string>>
     */
    private function cartesian(array $dimensions): array
    {
        $result = [[]];

        foreach ($dimensions as $name => $values) {
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
