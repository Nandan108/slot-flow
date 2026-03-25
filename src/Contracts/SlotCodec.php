<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

use Nandan108\SlotFlow\SlotSpace;

/**
 * @psalm-import-type TSlotArrayPattern from SlotSpace
 * @psalm-import-type TSlotTuplePattern from SlotSpace
 * @psalm-import-type TDimensionName from SlotSpace
 * @psalm-import-type TDimensionValue from SlotSpace
 *
 * @api
 */
interface SlotCodec
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(SlotSpace $space);

    public function isWildcard(?string $value): bool;

    /** @return non-empty-string */
    public function wildcard(): string;

    /**
     * @return non-empty-string a special key representing out-of-space source/sink slot
     */
    public function nilKey(): string;

    /** @return non-empty-string */
    public function dimensionSeparator(): string;

    /** @return non-empty-string */
    public function alternative(): string;

    /**
     * Serialize a slot or slot-pattern array to the codec's canonical string form.
     *
     * List inputs are interpreted positionally in dimension order. Associative inputs
     * are interpreted by dimension name, with missing values treated as wildcards.
     *
     * @param list<?string>|array<non-empty-string, ?string>|null $values
     *
     * @psalm-param null|TSlotTuplePattern|TSlotArrayPattern $values
     *
     * @return non-empty-string
     */
    public function serialize(?array $values): string;

    /**
     * @return ?array<non-empty-string, ?non-empty-string>
     *
     * @psalm-return ?TSlotArrayPattern
     *
     * @throws \InvalidArgumentException
     */
    public function deserialize(?string $key): ?array;

    /**
     * @param array<non-empty-string, list<non-empty-string>> $dimensions
     */
    public function initialDimensionValueValidation(array $dimensions): void;

    /**
     * Validate that the given values are valid for their respective dimensions,
     * and throw an exception if not. This is used to validate patterns before trying to match them.
     *
     * @param array<non-empty-string, array<string|null>|string|null> $values
     *
     * @throws \InvalidArgumentException
     */
    public function validateDimensionValues(array $values, bool $allowWildcards = false, bool $allowValueArrays = false): void;

    /**
     * Validate that the given value is valid for the given dimension, and throw an exception if not.
     *
     * @param non-empty-string $dimension
     *
     * @throws \InvalidArgumentException
     */
    public function validateDimensionValue(string $dimension, ?string $value, bool $allowWildcards): void;

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
    public function matchDimensionValues(string $dimension, ?string $pattern): array;
}
