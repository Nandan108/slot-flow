<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Rules;

use Nandan108\SlotFlow\SlotSpace;

/**
 * Declarative include/exclude rule for shaping the valid slot space.
 *
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @api
 */
final class SlotRule
{
    /**
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<non-empty-string|null>|non-empty-string|null $pattern
     *
     * @psalm-param TSlotPattern $pattern
     */
    public function __construct(
        public readonly bool $allow,
        public readonly string | array | null $pattern,
        /** @var array<string, mixed> */
        public readonly array $attributes = [],
    ) {
    }

    /**
     * Create an allow rule for the given pattern.
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<non-empty-string, ?non-empty-string>|non-empty-string $pattern
     * @param array<string, mixed>                                        $meta
     *
     * @psalm-param TSlotPattern $pattern
     */
    public static function allow(string | array | null $pattern, array $meta = []): self
    {
        return new self(true, $pattern, $meta);
    }

    /**
     * Create a deny rule for the given pattern.
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<non-empty-string, ?non-empty-string>|non-empty-string $pattern
     * @param array<non-empty-string, ?non-empty-string>|non-empty-string ...$patterns
     *
     * @psalm-param TSlotPattern $pattern
     * @psalm-param list<TSlotPattern> $patterns
     *
     * @psalm-return self|RuleSet<self>
     */
    public static function deny($pattern, string | array | null ...$patterns): self | RuleSet
    {
        return 0 === count($patterns)
            ? new self(false, $pattern)
            : new RuleSet(array_map(
                fn (string | array | null $p) => new self(false, $p),
                [$pattern, ...$patterns],
            ));
    }

    /**
     * Return a copy of the rule with additional metadata attributes.
     *
     * Existing attributes keep precedence over newly provided ones.
     *
     * @param array<string, mixed> $attributes
     */
    public function meta(array $attributes): self
    {
        return new self($this->allow, $this->pattern, $attributes + $this->attributes);
    }

    /**
     * Create a list of deny rules for the given patterns.
     *
     * @param list<string|array<int|string, ?non-empty-string>> $patterns
     *
     * @psalm-param list<TSlotPattern> $patterns
     *
     * @return list<SlotRule>
     */
    public static function denyAll(array $patterns): array
    {
        return array_map(fn ($pattern) => new self(false, $pattern), $patterns);
    }
}
