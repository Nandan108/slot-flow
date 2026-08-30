<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\AllocationPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeFilterPolicyInterface;
use Nandan108\SlotFlow\Contracts\EdgeOrderingPolicyInterface;
use Nandan108\SlotFlow\Contracts\PlannerRuleInterface;
use Nandan108\SlotFlow\Contracts\PolicyInterface;
use Nandan108\SlotFlow\Contracts\QttyConstraintPolicyInterface;
use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\Contracts\ShipmentSplitRuleInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Internal\FlowStep;

/**
 * Routes mixed policy bags into the typed buckets used by execution and planning.
 *
 * @api
 */
final class PolicyBuckets
{
    public const ALL = 'policies';
    public const ORDERING = 'ordering-policies';
    public const FILTER = 'filter-policies';
    public const QUANTITY_CONSTRAINT = 'quantity-constraint-policies';
    public const ALLOCATION = 'allocation-policies';
    public const PLANNER = 'planner-policies';
    public const SHIPMENT_CALENDAR = 'shipment-calendar-rules';
    public const SHIPMENT_SPLIT = 'shipment-split-rules';

    /**
     * Attach planner-capable policies to one edge-rule metadata array.
     *
     * @param array<array-key, mixed>           $attributes
     * @param array<array-key, PolicyInterface> $policies
     *
     * @return array<array-key, mixed>
     */
    public static function mergeEdgeAttributes(array $attributes, array $policies): array
    {
        if ([] === $policies) {
            return $attributes;
        }

        foreach ($policies as $policy) {
            $unwrapped = self::unwrap($policy)['policy'];
            if (!$unwrapped instanceof PlannerRuleInterface) {
                throw new SlotFlowInvalidArgumentException(
                    'EdgeRule::policies() only accepts planner policies.',
                    ['policy_class' => $unwrapped::class],
                );
            }
        }

        $all = [...self::all($attributes), ...$policies];
        $planner = self::resolveCategory(
            $all,
            static fn (PolicyInterface $policy): bool => $policy instanceof PlannerRuleInterface,
        );

        return [
            self::ALL               => $all,
            self::PLANNER           => $planner,
            self::SHIPMENT_CALENDAR => self::resolveCategory(
                $planner,
                static fn (PolicyInterface $policy): bool => $policy instanceof ShipmentCalendarRuleInterface,
            ),
            self::SHIPMENT_SPLIT => self::resolveCategory(
                $planner,
                static fn (PolicyInterface $policy): bool => $policy instanceof ShipmentSplitRuleInterface,
            ),
        ] + $attributes;
    }

    /**
     * Apply a mixed policy bag to a flow step.
     *
     * @param array<array-key, PolicyInterface> $policies
     */
    public static function applyToStep(FlowStep $step, array $policies): void
    {
        if ([] === $policies) {
            return;
        }

        /** @var list<PolicyInterface> $mergedPolicies */
        $mergedPolicies = array_values([...$step->policies, ...$policies]);
        $step->policies = $mergedPolicies;
        $step->orderingPolicies = self::mergeCategory(
            $step->orderingPolicies,
            $step->policies,
            static fn (PolicyInterface $policy): bool => $policy instanceof EdgeOrderingPolicyInterface,
        );
        $step->filterPolicies = self::mergeCategory(
            $step->filterPolicies,
            $step->policies,
            static fn (PolicyInterface $policy): bool => $policy instanceof EdgeFilterPolicyInterface,
        );
        $step->quantityConstraintPolicies = self::mergeCategory(
            $step->quantityConstraintPolicies,
            $step->policies,
            static fn (PolicyInterface $policy): bool => $policy instanceof QttyConstraintPolicyInterface,
        );
        $step->allocationPolicies = self::mergeCategory(
            $step->allocationPolicies,
            $step->policies,
            static fn (PolicyInterface $policy): bool => $policy instanceof AllocationPolicyInterface,
        );
        $step->plannerPolicies = self::resolveCategory(
            $step->policies,
            static fn (PolicyInterface $policy): bool => $policy instanceof PlannerRuleInterface,
        );
        $step->shipmentCalendarPolicies = self::resolveCategory(
            $step->plannerPolicies,
            static fn (PolicyInterface $policy): bool => $policy instanceof ShipmentCalendarRuleInterface,
        );
        $step->shipmentSplitPolicies = self::resolveCategory(
            $step->plannerPolicies,
            static fn (PolicyInterface $policy): bool => $policy instanceof ShipmentSplitRuleInterface,
        );
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<PolicyInterface>
     */
    public static function all(array $attributes): array
    {
        /** @var list<PolicyInterface> $policies */
        $policies = $attributes[self::ALL] ?? [];

        return $policies;
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<PlannerRuleInterface>
     */
    public static function planner(array $attributes): array
    {
        /** @var list<PlannerRuleInterface> $policies */
        $policies = $attributes[self::PLANNER] ?? [];

        return $policies;
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<ShipmentCalendarRuleInterface>
     */
    public static function shipmentCalendar(array $attributes): array
    {
        /** @var list<ShipmentCalendarRuleInterface> $policies */
        $policies = $attributes[self::SHIPMENT_CALENDAR] ?? [];

        return $policies;
    }

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @return list<ShipmentSplitRuleInterface>
     */
    public static function shipmentSplit(array $attributes): array
    {
        /** @var list<ShipmentSplitRuleInterface> $policies */
        $policies = $attributes[self::SHIPMENT_SPLIT] ?? [];

        return $policies;
    }

    /**
     * Rebuild one typed bucket from the step's policy bag without discarding what was declared
     * through the dedicated builder methods.
     *
     * A step can be configured two ways: `orderBy()` / `filter()` / `constraint()` / `allocate()`
     * write straight into a typed bucket, while `policies()` appends to the untyped bag the buckets
     * are derived from. Recomputing a bucket purely from the bag therefore used to erase whatever
     * the first route had put there — silently, so a flow that declared an ordering and then called
     * `policies()` ran with no ordering at all and simply produced a different movement.
     *
     * Entries already derived from the bag are matched by identity and replaced, so calling
     * `policies()` repeatedly re-derives rather than duplicates; anything else in the bucket came
     * from a builder method and is kept.
     *
     * The two routes order their own kind differently, and that predates this method: `orderBy()`
     * unshifts, so an earlier declaration wins, while `policies()` appends, so a later one wins.
     * Keeping bucket-declared entries first preserves both conventions exactly as they were rather
     * than quietly imposing one on the other.
     *
     * @template TBucketEntry
     *
     * @param list<TBucketEntry>                $bucket
     * @param array<array-key, PolicyInterface> $policies
     * @param \Closure(PolicyInterface): bool   $matches
     *
     * @return list<TBucketEntry>
     */
    private static function mergeCategory(array $bucket, array $policies, \Closure $matches): array
    {
        /** @psalm-var list<TBucketEntry> $derived */
        $derived = self::resolveCategory($policies, $matches);

        $direct = [];
        foreach ($bucket as $entry) {
            if (!in_array($entry, $derived, true)) {
                $direct[] = $entry;
            }
        }

        return [...$direct, ...$derived];
    }

    /**
     * Resolve one typed category out of a raw policy sequence, applying named overrides.
     *
     * @template TPolicy of PolicyInterface
     *
     * @param array<array-key, PolicyInterface> $policies
     * @param \Closure(PolicyInterface): bool   $matches
     *
     * @return list<TPolicy>
     */
    public static function resolveCategory(array $policies, \Closure $matches): array
    {
        /** @var array<string, TPolicy> $named */
        $named = [];
        /** @var array<string, bool> $namedSeen */
        $namedSeen = [];
        /** @var list<array{type: 'named', name: string}|array{type: 'policy', policy: TPolicy}> $order */
        $order = [];
        /** @var list<TPolicy> $anonymous */
        $resolved = [];

        foreach ($policies as $declaredPolicy) {
            $entry = self::unwrap($declaredPolicy);
            $policy = $entry['policy'];
            if (!$matches($policy)) {
                continue;
            }

            /** @var TPolicy $policy */
            if (null === $entry['name']) {
                $order[] = ['type' => 'policy', 'policy' => $policy];
                continue;
            }

            $named[$entry['name']] = $policy;
            if (!isset($namedSeen[$entry['name']])) {
                $order[] = ['type' => 'named', 'name' => $entry['name']];
                $namedSeen[$entry['name']] = true;
            }
        }

        foreach ($order as $item) {
            if ('policy' === $item['type']) {
                if (array_key_exists('policy', $item)) {
                    $resolved[] = $item['policy'];
                }
            } elseif (array_key_exists('name', $item)) {
                $resolved[] = $named[$item['name']];
            }

        }

        return $resolved;
    }

    /**
     * @return array{name: ?string, policy: PolicyInterface}
     */
    private static function unwrap(PolicyInterface $policy): array
    {
        if ($policy instanceof NamedPolicy) {
            return ['name' => $policy->name, 'policy' => $policy->policy];
        }

        return ['name' => null, 'policy' => $policy];
    }

    public static function matchesAny(PolicyInterface $_policy): bool
    {
        return true;
    }
}
