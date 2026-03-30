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

    /** @var array<non-empty-string, int> */
    public readonly array $shorthandMultipliers;

    /** @var array<non-empty-string, int> */
    public readonly array $allMultipliers;

    /** @var list<non-empty-string> */
    public readonly array $humanKeyParts;
    public readonly int $secondsInBucket;
    public readonly \DateTimeImmutable $timeZero;

    /**
     * Create one discrete time axis and validate its bucket and alias shorthand scheme.
     *
     * @param string                       $bucket          canonical name of the base time unit, such as "hour". First letter is used as the default shorthand, such as "h".
     * @param int                          $secondsInBucket size of one bucket in seconds
     * @param \DateTimeImmutable           $timeZero        anchor instant for bucket index 0; normalized down to the nearest bucket boundary
     * @param int                          $horizon         maximum time index allowed on this axis (inclusive)
     * @param array<non-empty-string, int> $aliases         map of human-friendly alias => bucket multiplier. Value may be suffixed with a shorthand letter, such as `day: d` to specify the alias shorthand explicitly (otherwise derived from first letter of alias).
     * @param list<non-empty-string>|null  $humanKeyParts   ordered human-key shorthands such as ['d', 'h']
     */
    public function __construct(
        string $bucket,
        int $secondsInBucket,
        \DateTimeImmutable $timeZero,
        public readonly int $horizon,
        array $aliases = [],
        ?array $humanKeyParts = null,
    ) {
        if ($horizon < 0) {
            throw new SlotFlowInvalidArgumentException(
                'Time horizon must be zero or greater.',
                ['horizon' => $horizon],
            );
        }

        if ($secondsInBucket <= 0) {
            throw new SlotFlowInvalidArgumentException(
                'Time bucket size must be greater than zero seconds.',
                ['seconds_in_bucket' => $secondsInBucket],
            );
        }

        /** @return array{non-empty-string, non-empty-string} */
        $parseUnit = function (string $type, string $input): array {
            if (!preg_match('/^([a-z]+)(?::\s*([a-z]+))?$/i', $input, $matches, PREG_UNMATCHED_AS_NULL)) {
                throw new SlotFlowInvalidArgumentException(
                    $type.' name must contain letters only, and an optional ":[a-z]+" suffix for the shorthand.',
                    ['input' => $input],
                );
            }
            /** @var non-empty-string $bucket */
            $bucket = $matches[1];
            /** @var non-empty-string $shorthand */
            $shorthand = $matches[2] ?? $bucket[0];

            return [strtolower($bucket), $shorthand];
        };

        [$this->bucket, $this->bucketShorthand] = $parseUnit('Time bucket', $bucket);
        $this->secondsInBucket = $secondsInBucket;
        $this->timeZero = $this->normalizeTimeZero($timeZero);

        /** @var array<non-empty-string, int> $normalizedAliases */
        $normalizedAliases = [];
        /** @var array<non-empty-string, int> $shorthandMultipliers */
        $shorthandMultipliers = [$this->bucketShorthand => 1];

        foreach ($aliases as $alias => $multiplier) {
            [$alias, $shorthand] = $parseUnit('Time alias', $alias);

            /** @psalm-suppress DocblockTypeContradiction */
            /** @var array{non-empty-string, string} $matches */
            if (!is_int($multiplier) || $multiplier <= 0) {
                throw new SlotFlowInvalidArgumentException(
                    'Time alias multipliers must be positive integers.',
                    ['alias' => $alias, 'multiplier' => $multiplier],
                );
            }

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

        $this->allMultipliers = [
            $this->bucket          => 1,
            $this->bucketShorthand => 1,
            ...$normalizedAliases,
            ...$shorthandMultipliers,
        ];
        $this->humanKeyParts = $this->normalizeHumanKeyParts($humanKeyParts);
    }

    /**
     * Build one time axis from a canonical bucket name, horizon, and optional aliases.
     *
     * @param array<non-empty-string, int> $aliases
     * @param list<non-empty-string>|null  $humanKeyParts
     */
    public static function define(
        string $bucket,
        int $horizon,
        array $aliases = [],
        ?array $humanKeyParts = null,
        ?\DateTimeImmutable $timeZero = null,
        ?int $secondsInBucket = null,
    ): self {
        return new self(
            bucket: $bucket,
            secondsInBucket: $secondsInBucket ?? self::defaultSecondsInBucket($bucket),
            timeZero: $timeZero ?? new \DateTimeImmutable('@0'),
            horizon: $horizon,
            aliases: $aliases,
            humanKeyParts: $humanKeyParts,
        );
    }

    /**
     * Build one time axis anchored to the current wall-clock instant.
     *
     * The provided instant is normalized down to the nearest bucket boundary so
     * bucket index 0 always starts on a canonical bucket edge.
     *
     * @param array<non-empty-string, int> $aliases
     * @param list<non-empty-string>|null  $humanKeyParts
     */
    public static function startingNow(
        string $bucket,
        int $horizon,
        array $aliases = [],
        ?array $humanKeyParts = null,
        ?\DateTimeImmutable $now = null,
        ?int $secondsInBucket = null,
    ): self {
        return new self(
            bucket: $bucket,
            secondsInBucket: $secondsInBucket ?? self::defaultSecondsInBucket($bucket),
            timeZero: $now ?? new \DateTimeImmutable(),
            horizon: $horizon,
            aliases: $aliases,
            humanKeyParts: $humanKeyParts,
        );
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
     * Return one human-readable time key, preferring larger configured units first.
     */
    public function humanKey(int | string | \DateTimeImmutable $value): string
    {
        $index = $this->parse($value);
        if (0 === $index) {
            return $this->key(0);
        }

        $remaining = $index;
        $parts = [];

        foreach ($this->humanKeyParts as $part) {
            $multiplier = $this->shorthandMultipliers[$part];
            if ($multiplier > $remaining) {
                continue;
            }

            $count = intdiv($remaining, $multiplier);
            if ($count > 0) {
                $parts[] = $count.$part;
                $remaining -= $count * $multiplier;
            }
        }

        if ($remaining > 0) {
            $parts[] = $remaining.$this->bucketShorthand;
        }

        return implode('', $parts);
    }

    /**
     * Parse a time key or duration expression into canonical bucket count.
     *
     * Examples: `h12`, `d3`, `d3s1`, `12`.
     */
    public function parse(int | string | \DateTimeImmutable $value): int
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

        if ($value instanceof \DateTimeImmutable) {
            $offsetSeconds = $value->getTimestamp() - $this->timeZero->getTimestamp();
            if ($offsetSeconds < 0) {
                throw new SlotFlowInvalidArgumentException(
                    'Time values must not resolve before the axis time zero.',
                    [
                        'time_zero' => $this->timeZero->format(DATE_ATOM),
                        'value'     => $value->format(DATE_ATOM),
                    ],
                );
            }

            return intdiv($offsetSeconds, $this->secondsInBucket);
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

        $value = strtolower($value);
        [$uRe,$cRe] = ['(?<unit>[a-z]+)', "(?<count>\d+)"];
        $formatRE = '(?:'.($value[0] >= 'a' ? "$uRe$cRe" : "$cRe$uRe").')';

        $consumed = '';
        $total = 0;
        preg_match_all("/$formatRE/i", $value, $matches, \PREG_SET_ORDER);
        // ** @var non-empty-list<array<array-key, string>> $matches */
        foreach ($matches as [0 => $matched, 'unit' => $unit, 'count' => $count]) {
            $consumed .= $matched;
            $unit = strtolower($unit);
            $multiplier = $this->allMultipliers[$unit] ?? null;
            if (null === $multiplier) {
                throw new SlotFlowInvalidArgumentException(
                    'Unknown time unit in expression.',
                    [
                        'value'       => $value,
                        'unit'        => $unit,
                        'known_units' => array_keys($this->allMultipliers),
                    ],
                );
            }
            $total += (int) $count * $multiplier;
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
     * Return the first bucket index at or after the given value.
     */
    public function ceil(int | string | \DateTimeImmutable $value): int
    {
        if (!$value instanceof \DateTimeImmutable) {
            return $this->parse($value);
        }

        $offsetSeconds = $value->getTimestamp() - $this->timeZero->getTimestamp();
        if ($offsetSeconds < 0) {
            throw new SlotFlowInvalidArgumentException(
                'Time values must not resolve before the axis time zero.',
                [
                    'time_zero' => $this->timeZero->format(DATE_ATOM),
                    'value'     => $value->format(DATE_ATOM),
                ],
            );
        }

        if (0 === $offsetSeconds % $this->secondsInBucket) {
            return intdiv($offsetSeconds, $this->secondsInBucket);
        }

        return intdiv($offsetSeconds + $this->secondsInBucket - 1, $this->secondsInBucket);
    }

    /**
     * Normalize any accepted time expression to canonical `bucket shorthand + index`.
     */
    public function normalize(int | string | \DateTimeImmutable $value): string
    {
        return $this->key($this->parse($value));
    }

    /**
     * Return true when the given time expression resolves within the configured horizon.
     */
    public function contains(int | string | \DateTimeImmutable $value): bool
    {
        if ($value instanceof \DateTimeImmutable && $value->getTimestamp() < $this->timeZero->getTimestamp()) {
            return false;
        }

        return $this->parse($value) <= $this->horizon;
    }

    /**
     * Return the bucket-aligned datetime for one resolved bucket index or accepted input.
     */
    public function dateTime(int | string | \DateTimeImmutable $value): \DateTimeImmutable
    {
        $seconds = $this->parse($value) * $this->secondsInBucket;

        return $this->timeZero->setTimestamp($this->timeZero->getTimestamp() + $seconds);
    }

    /**
     * @param list<non-empty-string>|null $humanKeyParts
     *
     * @return list<non-empty-string>
     */
    private function normalizeHumanKeyParts(?array $humanKeyParts): array
    {
        if (null === $humanKeyParts) {
            $parts = $this->shorthandMultipliers;
            arsort($parts);

            return array_keys($parts);
        }

        $normalized = [];
        foreach ($humanKeyParts as $part) {
            $part = strtolower($part);
            if (!isset($this->shorthandMultipliers[$part])) {
                throw new SlotFlowInvalidArgumentException(
                    'Human key parts must reference known time shorthands.',
                    ['part' => $part, 'known_parts' => array_keys($this->shorthandMultipliers)],
                );
            }

            if (in_array($part, $normalized, true)) {
                throw new SlotFlowInvalidArgumentException(
                    'Human key parts must be unique.',
                    ['part' => $part, 'human_key_parts' => $humanKeyParts],
                );
            }

            $normalized[] = $part;
        }

        if ([] === $normalized) {
            throw new SlotFlowInvalidArgumentException(
                'Human key parts cannot be empty.',
                [],
            );
        }

        return $normalized;
    }

    private function normalizeTimeZero(\DateTimeImmutable $timeZero): \DateTimeImmutable
    {
        $timestamp = $timeZero->getTimestamp();
        $quotient = intdiv($timestamp, $this->secondsInBucket);
        if ($timestamp < 0 && 0 !== $timestamp % $this->secondsInBucket) {
            --$quotient;
        }
        $normalized = $quotient * $this->secondsInBucket;

        return $timeZero->setTimestamp($normalized);
    }

    private static function defaultSecondsInBucket(string $bucket): int
    {
        [$normalizedBucket] = strtolower($bucket) === $bucket
            ? [$bucket]
            : [strtolower($bucket)];

        return match ($normalizedBucket) {
            'second' => 1,
            'minute' => 60,
            'hour'   => 3600,
            'day'    => 86400,
            'week'   => 604800,
            'tick'   => 1,
            default  => throw new SlotFlowInvalidArgumentException(
                'TimeAxis::define() requires secondsInBucket for non-standard buckets.',
                ['bucket' => $bucket],
            ),
        };
    }
}
