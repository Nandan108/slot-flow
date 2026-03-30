<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\PolicyInterface;

/**
 * Wrapper that assigns a stable override key to a policy inside a policy bag.
 *
 * When multiple policies of the same category share the same name, the later
 * declaration replaces the earlier one within that category.
 *
 * @api
 */
final class NamedPolicy implements PolicyInterface
{
    private function __construct(
        public readonly string $name,
        public readonly PolicyInterface $policy,
    ) {
    }

    /**
     * Wrap one policy with a stable override name.
     */
    public static function as(string $name, PolicyInterface $policy): self
    {
        return new self($name, $policy);
    }
}
