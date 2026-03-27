<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Codecs;

use Nandan108\SlotFlow\Contracts\SlotCodec;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;

/**
 * Built-in dot-separated codec with wildcard and alternative support.
 *
 * @psalm-import-type TSlotArrayPattern from SlotSpace
 * @psalm-import-type TSlotTuplePattern from SlotSpace
 * @psalm-import-type TDimensionValuePattern from SlotSpace
 * @psalm-import-type TDimensionName from SlotSpace
 * @psalm-import-type TDimensionValue from SlotSpace
 *
 * @api
 */
class DefaultSlotKeyCodec implements SlotCodec
{
    public const string SEPARATOR = '.';
    public const string WILDCARD = '*';
    public const string ALTERNATIVE = '|';

    /** Represents source or sink */
    public const string NIL_KEY = 'nil';

    public function __construct(
        private SlotSpace $space,
        public readonly ?TimeAxis $timeAxis = null,
    ) {
    }

    /** @return non-empty-string */
    #[\Override]
    public function nilKey(): string
    {
        return self::NIL_KEY;
    }

    /** @return non-empty-string */
    #[\Override]
    public function dimensionSeparator(): string
    {
        return self::SEPARATOR;
    }

    /** @return non-empty-string */
    #[\Override]
    public function wildcard(): string
    {
        return self::WILDCARD;
    }

    /** @return non-empty-string */
    #[\Override]
    public function alternative(): string
    {
        return self::ALTERNATIVE;
    }

    #[\Override]
    public function isWildcard(?string $value): bool
    {
        return null === $value || '' === $value || self::WILDCARD === $value;
    }

    /**
     * @param list<?string>|array<non-empty-string, ?string>|null $values
     *
     * @psalm-param null|TSlotTuplePattern|TSlotArrayPattern $values
     *
     * @return non-empty-string
     */
    #[\Override]
    public function serialize(?array $values): string
    {
        if (null === $values) {
            return self::NIL_KEY;
        }

        $dimNames = $this->space->dimensionNames();
        if (array_is_list($values)) {
            if (count($values) !== count($dimNames)) {
                throw new SlotFlowInvalidArgumentException(
                    'Slot tuple must have the same number of elements as dimensions: '.count($dimNames),
                    ['expected_dimension_count' => count($dimNames), 'received_count' => count($values)],
                );
            }

            $values = array_combine($dimNames, $values);
        } elseif (array_diff(array_keys($values), $dimNames)) {
            // throw if $value keys are not a subset of the dimension names
            throw new SlotFlowInvalidArgumentException(
                'Value keys must be a subset of dimension names: '.implode(', ', $dimNames),
                ['dimension_names' => $dimNames, 'received_keys' => array_keys($values)],
            );
        }

        // make sure the values are in the same order as the dimensions
        /** @var list<non-empty-string> $serializedValues */
        $serializedValues = [];
        foreach ($dimNames as $name) {
            /** @var non-empty-string $value */
            $value = $values[$name] ?? self::WILDCARD; // treat missing values as wildcards
            $serializedValues[] = $value;
        }

        /** @var non-empty-string $key */
        $key = implode(self::SEPARATOR, $serializedValues);

        return $key;
    }

    /**
     * @return ?array<non-empty-string, ?non-empty-string>
     *
     * @psalm-return ?TSlotArrayPattern
     */
    #[\Override]
    public function deserialize(?string $key): ?array
    {
        if (null === $key || '' === $key || self::NIL_KEY === $key) {
            return null;
        }
        if ($this->isWildcard($key)) {
            /** @var TSlotArrayPattern $dimensions */
            $dimensions = array_fill_keys($this->space->dimensionNames(), $this->wildcard());

            return $dimensions;
        }

        $exploded = explode(self::SEPARATOR, $key);
        // throw if the number of exploded parts does not match the number of dimensions
        if (count($exploded) !== count($this->space->dimensionNames())) {
            throw new SlotFlowInvalidArgumentException(
                "Key '$key' does not match the expected format for dimensions: ".implode(', ', $this->space->dimensionNames()),
                ['key' => $key, 'dimension_names' => $this->space->dimensionNames()],
            );
        }
        $dimensions = array_combine($this->space->dimensionNames(), $exploded);

        /** @var TSlotArrayPattern $dimensions */
        $dimensions = array_map(fn ($v) => $this->isWildcard($v) ? self::WILDCARD : $v, $dimensions); // treat empty and null values as wildcards

        // throw if one of the values does not match the expected values for its dimension
        $this->validateDimensionValues($dimensions, true);

        // split the key by the separator and map it back to the dimension names
        return $dimensions;
    }

    /**
     * Validate that the given values are valid for their respective dimensions, and throw an exception if not. This is used to validate patterns before trying to match them.
     *
     * @param array<non-empty-string, array<string|null>|string|null> $values
     *
     * @throws SlotFlowInvalidArgumentException
     */
    #[\Override]
    public function validateDimensionValues(array $values, bool $allowWildcards = false, bool $allowValueArrays = false): void
    {
        foreach ($values as $dim => $val) {
            if (is_array($val)) {
                if (!$allowValueArrays) {
                    throw new SlotFlowInvalidArgumentException(
                        "Array values are not allowed for dimension '$dim'",
                        ['dimension' => $dim],
                    );
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
     * @throws SlotFlowInvalidArgumentException
     */
    #[\Override]
    public function validateDimensionValue(string $dimension, ?string $value, bool $allowWildcards): void
    {
        $value = $value ?? self::WILDCARD ?: self::WILDCARD; // normalize empty and null to wildcard
        $isWildcard = $this->isWildcard($value);
        $hasWildCard = $isWildcard || str_contains($value, self::WILDCARD);

        if ($hasWildCard && !$allowWildcards) {
            throw new SlotFlowInvalidArgumentException(
                "Value for dimension '$dimension' cannot be empty or null",
                ['dimension' => $dimension, 'value' => $value],
            );
        }
        if ($isWildcard) {
            return;
        }

        if (!$this->matchDimensionValues($dimension, $value)) {
            throw new SlotFlowInvalidArgumentException(
                "Unknown $dimension: '$value'. Expected values: ".implode(', ', $this->space->dimensionValues($dimension)),
                ['dimension' => $dimension, 'value' => $value, 'expected_values' => $this->space->dimensionValues($dimension)],
            );
        }
    }

    /** @var array<non-empty-string, array<non-empty-string, list<non-empty-string>>> */
    private array $dimensionExpansions = [];

    /**
     * For a single dimension, find all values matching the given pattern, where the pattern can be:
     * - a specific value, which matches only that value
     * - a WILDCARD, which matches all values for that dimension
     * - a string containing one or more WILDCARDs, which matches any value that fits the pattern
     * - null or empty string, which are treated the same as the WILDCARD and match all values for that dimension
     * - a series of ALTERNATIVE string patterns, e.g. 'a|b*d|d*'
     *
     * @param non-empty-string $dimension
     *
     * @return list<non-empty-string>
     */
    #[\Override]
    public function matchDimensionValues(string $dimension, ?string $pattern): array
    {
        $pattern = $pattern ?? self::WILDCARD ?: self::WILDCARD; // normalize null to wildcard

        $values = $this->space->dimensionValues($dimension);

        $allMatches = [];

        foreach (explode(self::ALTERNATIVE, $pattern) as $subPattern) {
            if (str_contains($subPattern, self::WILDCARD)) {
                $cached = $this->dimensionExpansions[$dimension][$subPattern] ?? null;
                if (null !== $cached) {
                    $allMatches = [...$allMatches, ...$cached];
                    continue;
                }
                $parts = explode(self::WILDCARD, $subPattern);
                $regex = '/^'.implode('.*', array_map('preg_quote', $parts)).'$/';
                /** @var list<non-empty-string> $matches */
                $matches = array_values(preg_grep($regex, $values));
                $allMatches = [...$allMatches, ...$matches];
            } else {
                if (!in_array($subPattern, $values, true)) {
                    throw new SlotFlowInvalidArgumentException(
                        "Value '$subPattern' is not valid for dimension '$dimension'. Expected values: "
                        .implode(', ', $values),
                        ['dimension' => $dimension, 'value' => $subPattern, 'expected_values' => $values],
                    );
                }
                $allMatches = [...$allMatches, $subPattern];
            }
        }

        /** @var list<non-empty-string> $allMatches */
        $allMatches = array_unique($allMatches);

        return $this->dimensionExpansions[$dimension][$pattern] = $allMatches;
    }

    /**
     * Validate that the dimension values are not incompatible with
     * the separator, wildcard, or alternative characters used by the codec.
     *
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     */
    #[\Override]
    public function initialDimensionValueValidation(array $dimensions): void
    {
        $separator = $this->dimensionSeparator();
        foreach ($dimensions as $name => $values) {
            foreach ($values as $value) {
                if ($separator && str_contains($value, $separator)) {
                    throw new SlotFlowInvalidArgumentException(
                        "Dimension values cannot contain '{$separator}': $name => $value",
                        ['dimension' => $name, 'value' => $value, 'reserved_character' => $separator],
                    );
                }
                if (str_contains($value, self::WILDCARD)) {
                    throw new SlotFlowInvalidArgumentException(
                        "Dimension values cannot contain wildcard '{$this->wildcard()}': $name => $value",
                        ['dimension' => $name, 'value' => $value, 'reserved_character' => $this->wildcard()],
                    );
                }
                if (str_contains($value, self::ALTERNATIVE)) {
                    throw new SlotFlowInvalidArgumentException(
                        "Dimension values cannot contain alternative character '{$this->alternative()}': $name => $value",
                        ['dimension' => $name, 'value' => $value, 'reserved_character' => $this->alternative()],
                    );
                }
            }
        }
    }
}
