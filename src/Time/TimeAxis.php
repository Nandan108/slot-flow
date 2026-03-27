<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Time;

use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;

/**
 * Canonical discrete time axis used by timed slot-space expansion.
 *
 * Time keys are normalized to a canonical shorthand such as `h12`.
 * The shorthand is derived from the first letter of the bucket name and any
 * configured aliases, such as `day` or `shift`.
 *
 * @api
 */
final class TimeAxis
{
    /** @var non-empty-string */
    public readonly string $bucket;

    /** @var non-empty-string */
    public readonly string $bucketShorthand;

    /** @var array<non-empty-string, int> */
    public readonly array $aliases;

    /** @var array<string, int> */
    public readonly array $shorthandMultipliers;

    /**
     * Create one discrete time axis and validate its bucket and alias shorthand scheme.
     *
     * @param array<non-empty-string, int> $aliases map of human-friendly alias => bucket multiplier
     */
    public function __construct(
        string $bucket,
        public readonly int $horizon,
        array $aliases = [],
    ) {
        if ($horizon < 0) {
            throw new SlotFlowInvalidArgumentException(
                'Time horizon must be zero or greater.',
                ['horizon' => $horizon],
            );
        }

        if (!preg_match('/^[a-z]+$/i', $bucket)) {
            throw new SlotFlowInvalidArgumentException(
                'Time bucket name must contain letters only.',
                ['bucket' => $bucket],
            );
        }

        $bucket = strtolower($bucket);
        /** @var non-empty-string $bucket */
        $this->bucket = $bucket;
        $this->bucketShorthand = $bucket[0];
        /** @var array<non-empty-string, int> $normalizedAliases */
        $normalizedAliases = [];
        /** @var array<non-empty-string, int> $shorthandMultipliers */
        $shorthandMultipliers = [$this->bucketShorthand => 1];

        foreach ($aliases as $alias => $multiplier) {
            if (!preg_match('/^[a-z]+$/i', $alias)) {
                throw new SlotFlowInvalidArgumentException(
                    'Time alias names must contain letters only.',
                    ['alias' => $alias],
                );
            }

            if ($multiplier <= 0) {
                throw new SlotFlowInvalidArgumentException(
                    'Time alias multipliers must be positive.',
                    ['alias' => $alias, 'multiplier' => $multiplier],
                );
            }

            $alias = strtolower($alias);
            $shorthand = $alias[0];
            if (isset($shorthandMultipliers[$shorthand])) {
                throw new SlotFlowInvalidArgumentException(
                    'Time bucket and aliases must have unique first letters.',
                    ['bucket' => $this->bucket, 'alias' => $alias, 'shorthand' => $shorthand],
                );
            }

            $normalizedAliases[$alias] = $multiplier;
            $shorthandMultipliers[$shorthand] = $multiplier;
        }

        $this->aliases = $normalizedAliases;
        $this->shorthandMultipliers = $shorthandMultipliers;
    }

    /**
     * Build one time axis from a canonical bucket name, horizon, and optional aliases.
     *
     * @param array<non-empty-string, int> $aliases
     */
    public static function define(string $bucket, int $horizon, array $aliases = []): self
    {
        return new self($bucket, $horizon, $aliases);
    }

    /**
     * Return the canonical serialized time key for one bucket index.
     */
    public function key(int $index): string
    {
        if ($index < 0) {
            throw new SlotFlowInvalidArgumentException(
                'Time index must be zero or greater.',
                ['index' => $index],
            );
        }

        return $this->bucketShorthand.$index;
    }

    /**
     * Parse a time key or duration expression into canonical bucket count.
     *
     * Examples: `h12`, `d3`, `d3s1`, `12`.
     */
    public function parse(int | string $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new SlotFlowInvalidArgumentException(
                    'Time values must be zero or greater.',
                    ['value' => $value],
                );
            }

            return $value;
        }

        if ('' === $value) {
            throw new SlotFlowInvalidArgumentException(
                'Time value cannot be empty.',
                ['value' => $value],
            );
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        preg_match_all('/([a-z]+)(\d+)/i', $value, $matches, \PREG_SET_ORDER);
        if ([] === $matches) {
            throw new SlotFlowInvalidArgumentException(
                'Invalid time expression.',
                ['value' => $value],
            );
        }

        $consumed = '';
        $total = 0;

        foreach ($matches as $match) {
            $consumed .= $match[0];
            $unit = strtolower($match[1]);
            $amount = (int) $match[2];
            $multiplier = match ($unit) {
                $this->bucketShorthand => 1,
                default                => $this->shorthandMultipliers[$unit] ?? null,
            };

            if (null === $multiplier) {
                throw new SlotFlowInvalidArgumentException(
                    'Unknown time unit in expression.',
                    [
                        'value'       => $value,
                        'unit'        => $unit,
                        'known_units' => array_keys($this->shorthandMultipliers + [$this->bucketShorthand => 1]),
                    ],
                );
            }

            $total += $amount * $multiplier;
        }

        if ($consumed !== $value) {
            throw new SlotFlowInvalidArgumentException(
                'Invalid trailing content in time expression.',
                ['value' => $value, 'parsed_prefix' => $consumed],
            );
        }

        return $total;
    }

    /**
     * Normalize any accepted time expression to canonical `bucket shorthand + index`.
     */
    public function normalize(int | string $value): string
    {
        return $this->key($this->parse($value));
    }

    /**
     * Return true when the given time expression resolves within the configured horizon.
     */
    public function contains(int | string $value): bool
    {
        return $this->parse($value) <= $this->horizon;
    }
}
