<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;
/**
 * @psalm-import-type TSlotPattern from SlotSpace
 */
final class SlotRule
{
    /**
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<null|non-empty-string>|null|non-empty-string $pattern
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
     * @param array<int|string, ?non-empty-string>|non-empty-string $pattern
     * @psalm-param TSlotPattern $pattern
     * @param array<string, mixed> $meta
     */
    public static function allow(string | array | null $pattern, array $meta = []): self
    {
        return new self(true, $pattern, $meta);
    }

    /**
     * Create a deny rule for the given pattern.
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<int|string, ?non-empty-string>|non-empty-string $pattern
     * @psalm-param TSlotPattern $pattern
     */
    public static function deny(string | array | null $pattern): self
    {
        return new self(false, $pattern);
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
     * Create a list of allow rules for the given patterns.
     *
     * @param list<string|array<int|string, ?non-empty-string>> $patterns
     * @psalm-param list<TSlotPattern> $patterns
     *
     * @return list<SlotRule>
     */
    public static function denyAll(array $patterns): array
    {
        return array_map(fn ($pattern) => new self(false, $pattern), $patterns);
    }
}
