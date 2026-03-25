# SlotFlow API Reference

This document covers the current public API surface of SlotFlow. Signatures below are taken from the codebase as it exists today.

## SlotSpace API

### `SlotSpace`

The central model container. It defines the finite multidimensional space, resolves slot patterns, builds edges, and stores named cascades.

The slot space also owns the special `nil` slot, which represents outside-of-space flow in both directions: source, sink, and practical `/dev/null`.

```php
final class SlotSpace
{
    public SlotCodec $codec;
    public array $cascades = [];

    public static function define(array $dimensions, ?string $codecClass = null): self;
    public function __construct(array $dimensions, ?string $codecClass = null);
    public function slotRules(RuleSet|array $rules): self;
    public function edgeRules(RuleSet|array $rules): self;
    public function getEdgesFrom(Slot $from): array;
    public function dimensionNames(): array;
    public function dimensions(): array;
    public function dimensionValues(string $dimension): array;
    public function validateKnownDimensionNames(array $names): void;
    public function expandSlotPattern(string|array|null $pattern): array;
    public function nilSlot(): Slot;
    public function trySlot(array|string|null $keyOrValues, bool $throwOnInvalidDimensionValues = false): ?Slot;
    public function slot(array|string|null $keyOrValues): Slot;
    public function matchPartial(?array $partial): array;
    public function edgesBetween(array|string|null $fromPattern, array|string|null $toPattern): array;
    public function edgesByLabels(array $labels): array;
    public function cascade(string $name, \Closure|array $builder): self;
    public function getCascade(string $name): Cascade;
}
```

### `Slot`

One concrete point in a `SlotSpace`. A slot can be a normal in-space state or the special `nil` source/sink slot.

```php
final class Slot
{
    public function __construct(
        public readonly string $key,
        public readonly ?array $dimensions,
        public readonly SlotSpace $space,
        public readonly array $attributes = [],
    );

    public function isNil(): bool;
    public function dimension(string $name): ?string;
    public function equals(Slot $other): bool;
    public function withMeta(array $attributes): self;
    public function with(?array $overrides): array;
    public function outgoingEdges(): array;
    public function __toString(): string;
}
```

### `SlotCodec`

Serialization and pattern-matching contract for slot keys. A codec defines how slots become strings and how wildcard patterns are interpreted.

```php
interface SlotCodec
{
    public function __construct(SlotSpace $space);
    public function isWildcard(?string $value): bool;
    public function wildcard(): string;
    public function nilKey(): string;
    public function dimensionSeparator(): string;
    public function alternative(): string;
    public function serialize(?array $values): string;
    public function deserialize(?string $key): ?array;
    public function initialDimensionValueValidation(array $dimensions): void;
    public function validateDimensionValues(array $values, bool $allowWildcards = false, bool $allowValueArrays = false): void;
    public function validateDimensionValue(string $dimension, ?string $value, bool $allowWildcards): void;
    public function matchDimensionValues(string $dimension, ?string $pattern): array;
}
```

### `DefaultSlotKeyCodec`

The built-in dot-separated codec used by SlotFlow unless you provide a custom one. It handles wildcard matching, alternatives, and `nil`.

```php
class \Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec implements SlotCodec
{
    public const string SEPARATOR = '.';
    public const string WILDCARD = '*';
    public const string ALTERNATIVE = '|';
    public const string NIL_KEY = 'nil';

    public function __construct(private SlotSpace $space);
    public function nilKey(): string;
    public function dimensionSeparator(): string;
    public function wildcard(): string;
    public function alternative(): string;
    public function isWildcard(?string $value): bool;
    public function serialize(?array $values): string;
    public function deserialize(?string $key): ?array;
    public function validateDimensionValues(array $values, bool $allowWildcards = false, bool $allowValueArrays = false): void;
    public function validateDimensionValue(string $dimension, ?string $value, bool $allowWildcards): void;
    public function matchDimensionValues(string $dimension, ?string $pattern): array;
    public function initialDimensionValueValidation(array $dimensions): void;
}
```

## Slot And Edge Rules API

### `SlotRule`

Declares which slots should exist in the usable space. Slot rules are applied sequentially and can also attach structural metadata to matching slots.

```php
final class \Nandan108\SlotFlow\Rules\SlotRule
{
    public function __construct(
        public readonly bool $allow,
        public readonly string|array|null $pattern,
        public readonly array $attributes = [],
    );

    public static function allow(string|array|null $pattern, array $meta = []): self;
    public static function deny($pattern, string|array|null ...$patterns): self|RuleSet;
    public function meta(array $attributes): self;
    public static function denyAll(array $patterns): array;
}
```

### `EdgeRule`

Declares which movements are allowed or denied between slots. Edge rules shape the directed graph that cascades execute against.

```php
final class \Nandan108\SlotFlow\Rules\EdgeRule
{
    public function __construct(
        public readonly bool $allow,
        public readonly string|array|null $from,
        public readonly string|array|null $to = null,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    );

    public static function allowLabeled(?string $label = null, string|array|null $from, string|array|null $to = null, array $meta = []): self;
    public static function allow(string|array|null $from, string|array|null $to = null, array $meta = []): self;
    public static function deny(?string $label = null, string|array|null $from, string|array|null $to = null, array $meta = []): self;
    public function meta(array $attributes): self;
    public static function connect(string|array|null $patternA, string|array|null $patternB = null, array $meta = []): RuleSet;
    public static function disconnect(string|array|null $patternA, string|array|null $patternB = null, array $meta = []): RuleSet;
}
```

### `RuleSet`

A lightweight container for composing and decorating groups of `SlotRule` or `EdgeRule` instances. Useful when you want to build rule bundles and apply shared metadata.

```php
final class \Nandan108\SlotFlow\Rules\RuleSet
{
    public function __construct(public array $rules);
    public static function from(SlotRule|EdgeRule|RuleSet ...$rules): self;
    public function meta(array $attributes): self;
    public function all(): array;
}
```

## Inventory API

### `Inventory`

Holds the quantity state for one subject. It is the mutable execution input that `MovementEngine` reads from and updates while processing a cascade.

```php
final class Inventory
{
    public function __construct(private SlotSpace $space, array $tuples = []);
    public function get(Slot $slot): int|float;
    public function setTuple(array $slots): void;
    public function setSlotQtty(Slot $slot, int|float $quantity): void;
    public function add(Slot $slot, int|float $delta): void;
    public function all(): array;
    public function slotAttributes(Slot $slot): array;
    public function slotAttribute(Slot $slot, string $name, mixed $default = null): mixed;
    public function copy(): self;
    public function addFromRows(array $rows, \Closure $resolver): self;
    public static function fromRows(SlotSpace $space, array $rows, \Closure $resolver): self;
}
```

## Cascade API

### `Cascade`

A named ordered movement program. Each step describes how quantity may move, and step order determines fallback and overflow behavior.

`reverseIf()` makes cascades reusable in both forward and reverse form. With `$flipEdges = true`, the cascade reverses both step order and edge direction.

Cascades may also be parameterized by embedding placeholders such as `{loc}` or `{from-state}` inside string patterns. Those placeholders are substituted at execution time from `MovementEngine::execute(..., params: [...])`.

```php
final class Cascade
{
    public function __construct(string $name);
    public static function define(string $name, \Closure $builder): self;
    public function name(): string;
    public function steps(): array;
    public function reverseIf(bool $condition, bool $flipEdges = true): self;
    public function move(string|array|null $from, string|array|null $to): CascadeStepBuilder;
    public function create(string|array|null $to): CascadeStepBuilder;
    public function destroy(string|array|null $from): CascadeStepBuilder;
    public function stepByLabeledEdges(string ...$edgeLabels): CascadeStepBuilder;
}
```

### `CascadeStepBuilder`

Normally obtained from `Cascade::move()`, `create()`, `destroy()`, or `stepByLabeledEdges()`.

The fluent builder used to attach policies to the current cascade step and optionally start the next step.

`orderBy()` may be called with multiple policies. Earlier policies have higher precedence, while later ones act as tie-breakers. SlotFlow applies them in reverse registration order and relies on stable sorting so equal-ranked edges keep their previous order.

```php
final class \Nandan108\SlotFlow\Internal\CascadeStepBuilder
{
    public function __construct(Cascade $cascade, CascadeStep $step);
    public function orderBy(EdgeOrderingPolicyInterface|callable ...$policies): self;
    public function filter(EdgeFilterPolicyInterface|callable $policy): self;
    public function constraint(QttyConstraintPolicyInterface|callable $policy): self;
    public function allocate(AllocationPolicyInterface|callable $policy): self;
    public function move(string|array|null $from, string|array|null $to): CascadeStepBuilder;
    public function destroy(string|array|null $from): CascadeStepBuilder;
    public function create(string|array|null $to): CascadeStepBuilder;
}
```

### `CascadeContext`

Runtime context passed into policies during execution. It exposes the current candidate edges, inventory view, requested quantity, subject, and application context.

```php
final class \Nandan108\SlotFlow\Runtime\CascadeContext
{
    public function __construct(
        public readonly SlotSpace $space,
        public readonly array $edges,
        public readonly Inventory $inventory,
        public readonly int|float $quantity,
        public readonly mixed $subject = null,
        public readonly array $context = [],
    );

    public function slotAttributes(Slot $slot): array;
    public function slotAttribute(Slot $slot, string $name, mixed $default = null): mixed;
}
```

## Policy API

### `EdgeOrderingPolicyInterface`

Extension point for ordering candidate edges within a cascade step before greedy execution or allocation.

```php
interface EdgeOrderingPolicyInterface
{
    public function orderEdges(CascadeContext $ctx): array;
}
```

### `EdgeFilterPolicyInterface`

Extension point for removing ineligible edges from a cascade step before execution continues.

```php
interface EdgeFilterPolicyInterface
{
    public function filterEdges(CascadeContext $ctx): array;
}
```

### `QttyConstraintPolicyInterface`

Extension point for capping how much quantity may move through a specific edge in the current execution context.

```php
interface QttyConstraintPolicyInterface
{
    public function constraint(MovementEdge $edge, CascadeContext $ctx): int|float;
}
```

### `AllocationPolicyInterface`

Extension point for producing explicit per-edge allocation decisions instead of relying on the default greedy edge walk.

```php
interface AllocationPolicyInterface
{
    public function allocate(CascadeContext $ctx): array;
}
```

### `DimensionPriority`

A built-in ordering policy that prefers edges based on an ordered list of preferred dimension values or patterns.

```php
final class \Nandan108\SlotFlow\Policies\DimensionPriority implements EdgeOrderingPolicyInterface
{
    public function __construct(private readonly array $priorities);
    public function orderEdges(CascadeContext $ctx): array;
}
```

The `$priorities` array accepts ordered lists of dimension patterns, not only literal values. Each pattern is expanded via the current slot-space codec before ranking, so entries like `'wh*'`, `'sup'`, or `'wh1|wh2'` can be mixed in the same definition. All values matched by the same pattern share the same priority tier.

### `DistancePolicy`

A built-in filter and ordering policy that uses externally supplied distance data to reject far edges or prefer nearer ones.

```php
final class \Nandan108\SlotFlow\Policies\DistancePolicy implements EdgeFilterPolicyInterface, EdgeOrderingPolicyInterface
{
    public function __construct(private readonly int|float|null $max = null);
    public function filterEdges(CascadeContext $ctx): array;
    public function orderEdges(CascadeContext $ctx): array;
}
```

### `AvailableInventorySortPolicy`

A built-in ordering policy that prefers edges whose origin slot currently has more available quantity.

```php
final class \Nandan108\SlotFlow\Policies\AvailableInventorySortPolicy implements EdgeOrderingPolicyInterface
{
    public function orderEdges(CascadeContext $ctx): array;
}
```

### `AllocationDecision`

A value object representing one explicit allocation choice: move a specific quantity through a specific edge.

```php
final class \Nandan108\SlotFlow\Runtime\AllocationDecision
{
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int|float $quantity,
    );
}
```

## Execution API

### `MovementEngine`

The main execution engine. It resolves cascade steps into candidate edges, applies policies, performs movements, and returns a `MovementResult`.

When `params` is provided, SlotFlow substitutes placeholders inside cascade string patterns before pattern expansion. Placeholder names may match `/[-\w]+/`. Missing params are left unsubstituted, which will usually prevent the resulting slot pattern from matching correctly.

Execution may use either a `Cascade` instance or the name of a cascade registered on the `SlotSpace`.

```php
final class MovementEngine
{
    public function execute(
        Inventory $inventory,
        SlotSpace $space,
        string|Cascade $cascade,
        int|float $quantity,
        mixed $subject = null,
        array $appContext = [],
        array $params = [],
    ): MovementResult;
}
```

If `cascade` is a string, SlotFlow resolves it via `SlotSpace::getCascade()` on the provided space before execution continues.

### `MovementResult`

The aggregate result of one execution request. It contains the full movement event list plus any requested quantity that could not be spent.

```php
final class MovementResult
{
    public function __construct(
        public readonly array $events,
        public readonly int|float $remaining,
    );

    public function isComplete(): bool;
    public function mutations(): array;
    public function ledgerEntries(array $context = []): array;
}
```

### `MovementEvent`

One concrete movement that actually occurred during execution. It records the edge used, quantity moved, and pre-move balances for traceability.

```php
final class MovementEvent
{
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int|float $quantity,
        public readonly int|float|null $initialFrom,
        public readonly int|float|null $initialTo,
    );

    public function finalFrom(): int|float|null;
    public function finalTo(): int|float|null;
    public function mutations(): array;
    public function ledgerEntry(array $context = []): LedgerEntry;
}
```

### `MovementEdge`

A directed movement candidate or executed path between two slots. It may also carry a label and metadata inherited from edge rules.

```php
final class MovementEdge
{
    public function __construct(
        public readonly Slot $from,
        public readonly Slot $to,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
    );

    public function __toString(): string;
    public function meta(array $attributes): self;
}
```

### `InventoryMutation`

A net slot delta suitable for projecting current inventory state after execution.

```php
final class InventoryMutation
{
    public function __construct(
        public readonly Slot $slot,
        public readonly int|float $delta,
    );
}
```

### `LedgerEntry`

An append-only event shape for persistence or audit logs. It represents the durable record form of a `MovementEvent`.

```php
final class LedgerEntry
{
    public function __construct(
        public readonly MovementEdge $edge,
        public readonly int|float $quantity,
        public readonly int|float|null $initialFrom,
        public readonly int|float|null $initialTo,
        public readonly array $context = [],
    );

    public function finalFrom(): int|float|null;
    public function finalTo(): int|float|null;
}
```

## Batch API

### `InventoryBatch`

Groups many subjects so the same cascade can be executed across all of them. It also provides aggregated mutation and ledger outgress helpers.

```php
final class InventoryBatch
{
    public function __construct(private array $items);
    public static function fromRows(
        SlotSpace $space,
        iterable $rows,
        \Closure $subjectGetter,
        \Closure $slotRowGetter,
        \Closure $quantityGetter,
        ?\Closure $subjectIdGetter,
    ): self;
    public function items(): array;
    public function results(): array;
    public function mutations(): array;
    public function ledgerEntries(array $context = []): array;
}
```

### `BatchMovementEngine`

Runs one cascade across an `InventoryBatch` by delegating each item to `MovementEngine`.

```php
final class BatchMovementEngine
{
    public function __construct(private MovementEngine $engine);
    public function execute(
        InventoryBatch $batch,
        SlotSpace $space,
        string|Cascade $cascade,
        array $context = [],
        array $params = [],
    ): InventoryBatch;
}
```

Like `MovementEngine`, batch execution can also reference a registered cascade by name.

### `BatchInventoryMutation`

Batch equivalent of `InventoryMutation`, with the subject attached so projected deltas can be grouped or persisted per subject.

```php
final class BatchInventoryMutation
{
    public function __construct(
        public readonly mixed $subject,
        public readonly Slot $slot,
        public readonly int|float $delta,
    );
}
```

### `BatchLedgerEntry`

Batch equivalent of `LedgerEntry`, with the subject attached for multi-subject persistence and audit trails.

```php
final class BatchLedgerEntry
{
    public function __construct(
        public readonly mixed $subject,
        public readonly MovementEdge $edge,
        public readonly int|float $quantity,
        public readonly int|float|null $initialFrom,
        public readonly int|float|null $initialTo,
        public readonly array $context = [],
    );

    public function finalFrom(): int|float|null;
    public function finalTo(): int|float|null;
}
```
