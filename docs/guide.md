# SlotFlow Guide

## What SlotFlow Models

SlotFlow models quantity movement through a finite graph of slots.

It is intentionally domain-neutral. The same primitives can be used for:

- inventory engines
- fulfillment and logistics
- supply-chain planning and transfers
- manufacturing and assembly stages
- repair and refurbishment loops
- reverse logistics and returns
- reservation/booking flows
- pooled resource allocation
- aggregate processing pipelines

A slot is the combination of a fixed set of dimensions. For example:

- `loc`: physical or logical location
- `own`: ownership bucket
- `stt`: stock state

If you define:

```php
[
    'loc' => ['sup', 'wh1', 'wh2'],
    'own' => ['CS', 'CP', 'FP'],
    'stt' => ['fs', 'res', 'sd'],
]
```

then `wh1.CS.fs` and `sup.FP.sd` are concrete slots in the same space.

In another domain, the dimensions might instead be things like:

- `site`, `stage`, `material`
- `node`, `state`, `carrier`
- `line`, `station`, `status`

The engine does not care. It only needs a finite space and valid transitions.

## 1. Define A Slot Space

`SlotSpace::define()` creates the full cartesian product of dimensions. You then shape it with slot rules and edge rules.

API reference: [SlotSpace API](api-reference.md#slotspace-api), [Slot And Edge Rules API](api-reference.md#slot-and-edge-rules-api)

```php
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\RuleSet;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\SlotSpace;

$space = SlotSpace::define([
    'loc' => ['sup', 'wh1', 'wh2'],
    'own' => ['CS', 'CP', 'FP'],
    'stt' => ['fs', 'res', 'sd', 'ret', 'def'],
])->slotRules(RuleSet::from(
    SlotRule::allow('*'),
    SlotRule::allow('wh2.*.*')->meta(['food-storage' => true]),
))->edgeRules([
    EdgeRule::disconnect(['own' => 'C*'], ['own' => 'FP']),
    EdgeRule::allow(['own' => 'C*', 'stt' => 'ret'], ['own' => 'CP', 'stt' => 'fs']),
]);
```

Key points:

- slot rules decide which slots exist in the usable space
- edge rules decide which movements are allowed between those slots
- both slot rules and edge rules can carry attributes

## 2. Address Slots And Patterns

API reference: [SlotSpace API](api-reference.md#slotspace-api), [Inventory API](api-reference.md#inventory-api)

You can refer to slots in a few ways:

- serialized key: `'wh1.CS.fs'`
- full associative array: `['loc' => 'wh1', 'own' => 'CS', 'stt' => 'fs']`
- tuple in dimension order
- partial pattern for matching many slots
- `null` for the special `nil` source/sink slot

Common pattern features:

- missing dimensions mean wildcard
- `*` means wildcard
- `foo|bar` means alternatives

### The `nil` slot

SlotFlow reserves a special `nil` slot to represent movement across the boundary of the modeled space.

- `nil -> slot` means quantity enters the system
- `slot -> nil` means quantity leaves the system
- in practice, it acts as both source and sink, or a typed `/dev/null`

That is why `Cascade::create()` is shorthand for `nil -> slot`, and `Cascade::destroy()` is shorthand for `slot -> nil`.

Examples:

```php
$slot = $space->slot(['loc' => 'wh1', 'own' => 'CS', 'stt' => 'fs']);
$same = $space->slot('wh1.CS.fs');
$allWarehouseForsale = $space->matchPartial(['loc' => 'wh*', 'stt' => 'fs']);
```

## 3. Build Inventories

`Inventory` represents the quantity state of one subject.

API reference: [Inventory API](api-reference.md#inventory-api)

```php
use Nandan108\SlotFlow\Inventory;

$inventory = new Inventory($space, [
    [$space->slot(['loc' => 'wh1', 'own' => 'FP', 'stt' => 'fs']), 5],
    [$space->slot(['loc' => 'sup', 'own' => 'CS', 'stt' => 'sd']), 2],
]);
```

For ingestion from rows, `Inventory::fromRows()` lets you map arbitrary row shapes into slot tuples:

```php
$inventory = Inventory::fromRows(
    $space,
    $rows,
    static function (array $row, SlotSpace $space): array {
        return [
            [
                $space->slot(['loc' => $row['loc'], 'own' => $row['own'], 'stt' => 'fs']),
                $row['fs'],
                ['ifs' => $row['ifs'] ?? 0],
            ],
        ];
    },
);
```

Each tuple can optionally include a third element: per-inventory slot attributes.

That metadata is stored in `Inventory`, not in the canonical `SlotSpace`, and is later available to policies through `CascadeContext`.

This distinction matters in generic modeling:

- slot metadata is structural and shared
- inventory-side attributes are dynamic and item-specific

## 4. Define Cascades

A cascade is a sequence of movement steps. Each step can define:

API reference: [Cascade API](api-reference.md#cascade-api), [Policy API](api-reference.md#policy-api)

- a source pattern
- a destination pattern
- ordering policies
- filter policies
- quantity constraints
- allocation policies

Example:

```php
use Nandan108\SlotFlow\Cascade;
use Nandan108\SlotFlow\Policies\DimensionPriority;

$reserve = Cascade::define('reserve', static fn (Cascade $c) => $c
    ->move(['stt' => 'fs'], ['stt' => 'res'])
    ->orderBy(new DimensionPriority([
        'loc' => ['wh*', 'sup'],
        'own' => ['FP', 'CP', 'CS'],
    ])));
```

`DimensionPriority` accepts ordered dimension patterns, not just literal values. The patterns are expanded through the slot-space codec, so entries like `'wh*'` or `'wh1|wh2'` behave the same way they do in slot patterns. Each entry defines one priority tier, so all matching values share the same rank.

`orderBy()` can take multiple ordering policies. Earlier policies have higher precedence, and later ones act as tie-breakers. This works because SlotFlow applies them in reverse registration order and relies on stable sorting: when a later policy ranks two edges equally, their previous order is preserved.

Useful cascade helpers:

- `move($from, $to)`
- `create($to)` for `nil -> slot`
- `destroy($from)` for `slot -> nil`
- `reverseIf($condition)`
- `stepByLabeledEdges(...)`

### Reversible cascades

`reverseIf(bool $condition, bool $flipEdges = true)` lets you reuse the same cascade definition in forward or reverse form.

- if `$condition` is `false`, you get a clone of the original cascade
- if `$condition` is `true`, the step order is reversed
- if `$flipEdges` is also `true`, each step direction is flipped as well (true reverse operation)

This is useful for rollback-style flows such as releasing reservations, correcting receptions, or undoing previously modeled movement paths.

### Parameterized template cascades

Cascades may contain placeholders inside string patterns, using names that match `/[-\w]+/`, for example `{loc}`, `{own}`, or `{from-state}`.

These placeholders are substituted at execution time through `MovementEngine::execute(..., params: [...])`.

- all placeholders used by the cascade should be provided at execution time
- placeholders that are not provided are left unchanged
- an unsubstituted placeholder will usually break slot-pattern expansion or matching because it is not a valid dimension value

This makes parameterized cascades act like reusable movement templates whose concrete routing is fixed only at execution time.

You can also register cascades directly on a `SlotSpace`:

```php
$space->cascade('book', [[['stt' => 'res'], ['stt' => 'sd']]]);
$book = $space->getCascade('book');
```

### Registered cascades vs ad hoc cascades

Execution accepts either:

- a `Cascade` object that you built inline or fetched from the space
- a string cascade name that resolves against the provided `SlotSpace`

Examples:

```php
$reserve = Cascade::define('reserve', static fn (Cascade $c) => $c
    ->move(['stt' => 'fs'], ['stt' => 'res']));

(new MovementEngine())->execute(
    inventory: $inventory,
    space: $space,
    cascade: $reserve,
    quantity: 3,
);

$space->cascade('reserve', static fn (Cascade $c) => $c
    ->move(['stt' => 'fs'], ['stt' => 'res']));

(new MovementEngine())->execute(
    inventory: $inventory,
    space: $space,
    cascade: 'reserve',
    quantity: 3,
);
```

In practice:

- use a `Cascade` object when building or testing a flow inline
- use a registered cascade name when the flow is part of the slot-space model and should be reused consistently
- registered names pair naturally with parameterized cascades, because the caller only needs to provide `params` for the current execution

## 5. Execute Movements

Single-subject execution:

API reference: [Execution API](api-reference.md#execution-api)

```php
use Nandan108\SlotFlow\MovementEngine;

$result = (new MovementEngine())->execute(
    inventory: $inventory,
    space: $space,
    cascade: $reserve,
    quantity: 3,
    subject: 'SKU-123',
    appContext: ['channel' => 'web'],
    params: ['loc' => 'wh1'],
);
```

Execution is greedy by default:

- each step resolves candidate edges
- filters and ordering policies reshape those edges
- quantity constraints cap what can move through each edge
- if no allocation policy is present, the engine consumes greedily in edge order
- any unspent requested quantity becomes `MovementResult::$remaining`

That behavior makes SlotFlow useful for allocation-style problems where "move as much as possible under the defined policy" is the right default.

When using parameterized cascades, `params` is also where placeholder substitution happens before slot patterns are expanded.

- placeholders are recognized in string patterns using `{name}` syntax
- names may contain letters, digits, `_`, and `-`
- missing params are not substituted, so execution should treat them as a modeling error

This same `params` mechanism works when `cascade` is a registered cascade name, which makes named parameterized cascades a good fit for application-level flow templates.

**Exceptions**
SlotFlow treats invalid patterns, definitions, and lookups as modeling/API errors. All library-thrown exceptions implement `Nandan108\SlotFlow\Exceptions\SlotFlowExceptionInterface`, so callers can catch that interface for library-wide handling or the SPL-compatible `SlotFlowInvalidArgumentException` and `SlotFlowLogicException` subclasses for narrower handling. When available, `debugContext(): array` provides structured diagnostics about the failing input or state.

## 6. Batch Execution

Use `InventoryBatch` when you want to execute the same cascade for many subjects.

API reference: [Batch API](api-reference.md#batch-api)

```php
use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\InventoryBatch;

$batch = InventoryBatch::fromRows(
    space: $space,
    rows: $rows,
    subjectGetter: fn (array $row): string => $row['sku'],
    slotRowGetter: fn (array $row): array => [
        [$space->slot(['loc' => $row['loc'], 'own' => $row['own'], 'stt' => 'fs']), $row['fs']],
    ],
    quantityGetter: fn (array $rows): int => $rows[0]['requested_qty'],
    subjectIdGetter: fn (string $subject): string => $subject,
);

$batch = (new BatchMovementEngine(new MovementEngine()))->execute(
    batch: $batch,
    space: $space,
    cascade: $reserve,
);
```

The batch API is designed to handle both common ingestion shapes:

- one row per slot for a subject
- one row containing many slots for a subject

Rows are grouped by subject ID first, then each group becomes one `BatchItem`.

That is useful not just for catalog variants, but for any domain where the moved entity represents a quantity-bearing class of things:

- SKU
- material code
- lot
- resource bucket
- batch type

## 7. Understand The Result

`MovementResult` contains the full event history for one execution.

API reference: [Execution API](api-reference.md#execution-api)

```php
$result->events;
$result->remaining;
$result->isComplete();
```

Each `MovementEvent` contains:

- `edge`
- `quantity`
- `initialFrom`
- `initialTo`
- derived helpers: `finalFrom()` and `finalTo()`

## 8. Outgress Helpers

SlotFlow does not persist anything itself. Instead, it gives you helpers for the two common outgress needs.

### Inventory Projection

Use `mutations()` when you want net per-slot deltas.

```php
foreach ($result->mutations() as $mutation) {
    $slot = $mutation->slot;
    $delta = $mutation->delta;
}
```

For batch processing:

```php
foreach ($batch->mutations() as $mutation) {
    $subject = $mutation->subject;
    $slot = $mutation->slot;
    $delta = $mutation->delta;
}
```

These are suitable for updating current stock state.

### Ledger Recording

Use `ledgerEntries()` when you want one append-only row per executed event.

```php
foreach ($result->ledgerEntries(['operationId' => 'checkout-42']) as $entry) {
    $edge = $entry->edge;
    $quantity = $entry->quantity;
    $context = $entry->context;
}
```

For batch processing:

```php
foreach ($batch->ledgerEntries(['operationId' => 'checkout-42']) as $entry) {
    $subject = $entry->subject;
    $edge = $entry->edge;
}
```

The recommended split is:

- use mutations for current inventory state
- use ledger entries for audit/history

This is one of the main boundaries of the library. SlotFlow computes the movement. Your application decides how to persist, enrich, correlate, authorize, and transact it.

## 9. Policy Context And Dynamic Inventory Attributes

Policies receive a `Runtime\CascadeContext`, which exposes:

- `space`
- `edges`
- `inventory`
- `quantity`
- `subject`
- `context`

and also:

- `slotAttributes(Slot $slot)`
- `slotAttribute(Slot $slot, string $name, mixed $default = null)`

This is the intended hook for dynamic, inventory-instance metadata such as `ifs`.

Those values should not be modeled as canonical slot metadata when they vary by inventory item or ingestion batch.

## 10. Naming

Inside the generic library, the preferred term is `subject`.

Domain examples can use a more specific term at the edges:

- commerce: `variant`
- logistics: `shipment`, `parcel`, `unit`, `lot`
- supply chain: `sku`, `material`, `item`

The important distinction is:

- core engine vocabulary stays generic
- example and application vocabulary stays domain-legible

## 11. What SlotFlow Is Not

SlotFlow is not trying to be:

- a domain-complete inventory engine
- a warehouse-management system
- an ERP
- a persistence layer
- a transaction manager
- a billing or ordering system

Those concerns belong above the engine.

If you need a domain-rich solution, the intended approach is to build a higher-level package on top of SlotFlow rather than forcing those semantics into the core.
