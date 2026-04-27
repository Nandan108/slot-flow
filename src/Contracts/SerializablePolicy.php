<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Contracts;

/**
 * Opt-in interface for policies that can be fully described as a plain array
 * and reconstructed from it.
 *
 * Implemented independently of PolicyInterface so that any policy class can
 * opt in without forcing all policy implementations to be serializable.
 *
 * @api
 */
interface SerializablePolicy
{
    /**
     * Return a JSON-serializable description of this policy.
     *
     * Must include a 'type' key whose value uniquely identifies the class.
     *
     * @return array<string, mixed>
     */
    public function toDefinition(): array;

    /**
     * Reconstruct a policy instance from a previously serialized definition.
     *
     * @param array<string, mixed> $data
     */
    public static function fromDefinition(array $data): static;
}
