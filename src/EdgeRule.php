<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

/**
 * @psalm-import-type TSlotPattern from SlotSpace
 */
final class EdgeRule
{
    /**
     * @param array<int|string, ?string>|string|null $from
     * @param array<int|string, ?string>|string|null $to
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     *
     * @throws \InvalidArgumentException
     *
     * @psalm-suppress TypeDoesNotContainType
     */
    public function __construct(
        public readonly bool $allow,
        public readonly string | array | null $from,
        public readonly string | array | null $to = null,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    ) {
    }

    /**
     * @param array<int|string, ?string>|string|null $from
     * @param array<int|string, ?string>|string|null $to
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public static function allowLabeled(?string $label = null, string | array | null $from, string | array | null $to = null, array $meta = []): self
    {
        return new self(true, $from, $to, $label, $meta);
    }

    /**
     * @param array<int|string, ?string>|string|null $from
     * @param array<int|string, ?string>|string|null $to
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public static function allow(string | array | null $from, string | array | null $to = null, array $meta = []): self
    {
        return new self(true, $from, $to, null, $meta);
    }

    /**
     * @param array<int|string, ?string>|string|null $from
     * @param array<int|string, ?string>|string|null $to
     *
     * @psalm-param TSlotPattern $from
     * @psalm-param TSlotPattern $to
     */
    public static function deny(?string $label = null, string | array | null $from, string | array | null $to = null, array $meta = []): self
    {
        return new self(false, $from, $to, $label, $meta);
    }

    public function meta(array $attributes): self
    {
        return new self($this->allow, $this->from, $this->to, $this->label, $attributes + $this->attributes);
    }

    /**
     * Allow movement between $from and $to in both directions.
     *
     * @param array<int|string, ?string>|string|null $patternA
     * @param array<int|string, ?string>|string|null $patternB
     *
     * @psalm-param TSlotPattern $patternA
     * @psalm-param TSlotPattern $patternB
     *
     * @return RuleSet<EdgeRule>
     *
     * @psalm-return RuleSet<EdgeRule>
     */
    public static function connect(string | array | null $patternA, string | array | null $patternB = null, array $meta = []): RuleSet
    {
        return new RuleSet([
            self::allow($patternA, $patternB, $meta),
            self::allow($patternB, $patternA, $meta),
        ]);
    }

    /**
     * Deny movement between two patterns in both directions.
     *
     * @param array<int|string, ?string>|string|null $patternA
     * @param array<int|string, ?string>|string|null $patternB
     *
     * @psalm-param TSlotPattern $patternA
     * @psalm-param TSlotPattern $patternB
     *
     * @return RuleSet<EdgeRule>
     *
     * @psalm-return RuleSet<EdgeRule>
     */
    public static function disconnect(string | array | null $patternA, string | array | null $patternB = null, array $meta = []): RuleSet
    {
        return new RuleSet([
            self::deny(null, $patternA, $patternB, $meta),
            self::deny(null, $patternB, $patternA, $meta),
        ]);
    }
}
