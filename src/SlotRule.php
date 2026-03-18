<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class SlotRule
{
    /**
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<non-empty-string, ?string>|string $pattern
     */
    public function __construct(
        public readonly bool $allow,
        public readonly string | array $pattern,
    ) {
    }

    /**
     * Create an allow rule for the given pattern.
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<non-empty-string, ?string>|string $pattern
     */
    public static function allow(string | array $pattern): self
    {
        return new self(true, $pattern);
    }

    /**
     * Create a deny rule for the given pattern.
     * The pattern can be a string or an array of dimension-value pairs.
     *
     * @param array<non-empty-string, ?string>|string $pattern
     */
    public static function deny(string | array $pattern): self
    {
        return new self(false, $pattern);
    }

    /**
     * Create a list of allow rules for the given patterns.
     *
     * @param list<string|array<non-empty-string, ?string>> $patterns
     *
     * @return list<SlotRule>
     */
    public static function denyAll(array $patterns): array
    {
        return array_map(fn ($pattern) => new self(false, $pattern), $patterns);
    }
}
