<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class EdgeRule
{
    /**
     * @param array<non-empty-string, ?string>|string|null $from
     * @param array<non-empty-string, ?string>|string|null $to
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
     * @param array<non-empty-string, ?string>|string|null $from
     * @param array<non-empty-string, ?string>|string|null $to
     */
    public static function allow(?string $label = null, string | array | null $from, string | array | null $to = null, array $meta = []): self
    {
        return new self(true, $from, $to, $label, $meta);
    }

    /**
     * @param array<non-empty-string, ?string>|string|null $from
     * @param array<non-empty-string, ?string>|string|null $to
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
     * @param array<non-empty-string, ?string>|string|null $from
     * @param array<non-empty-string, ?string>|string|null $to
     */
    public static function connect(string | array | null $from, string | array | null $to = null, array $meta = []): RuleSet
    {
        return new RuleSet([
            self::allow(null, $from, $to, $meta),
            self::allow(null, $to, $from, $meta),
        ]);
    }
}
