# SlotFlow Guide

Generated API docs: https://nandan108.github.io/slot-flow

To build them locally, run `composer phpdoc` and open `build/phpdoc/index.html`.

## What SlotFlow Models

SlotFlow models quantity movement through a finite graph of slots.

It is intentionally domain-neutral. The same primitives can be used for:

- inventory engines
- fulfillment and logistics
- supply-chain planning and transfers
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

```php
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\RuleSet;
use Nandan108\SlotFlow\Rules\SlotRule;
use Nandan108\SlotFlow\SlotSpace;

$space = SlotSpace::define([
    'loc' => ['sup', 'wh1', 'wh2'],
    'own' => ['CS', 'CP', 'FP'],
    'stt' => ['fs', 'res', 'sd', 'ret', 'def'],
])->slotRules([
    SlotRule::allow('*'),
    SlotRule::allow('wh2.*.*')->meta(['food-storage' => true]),
])->edgeRules([
    EdgeRule::disconnect(['own' => 'C*'], ['own' => 'FP']),
    EdgeRule::allow(['own' => 'C*', 'stt' => 'ret'], ['own' => 'CP', 'stt' => 'fs']),
]);
```

Key points:

- slot rules decide which slots exist in the usable space
- edge rules decide which movements are allowed between those slots
- both slot rules and edge rules can carry attributes

Slot rules behave a bit like firewall rules:

- if the first rule is an allow rule, the usable space starts empty and matching slots are added
- if the first rule is a deny rule, the usable space starts full and matching slots are removed
- if no slot rules are given, the full cartesian slot space is kept
- later rules are applied in order, so they can override earlier ones

## 2. Address Slots And Patterns

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

That is why `Flow::create()` is shorthand for `nil -> slot`, and `Flow::destroy()` is shorthand for `slot -> nil`.

Examples:

```php
$slot = $space->slot(['loc' => 'wh1', 'own' => 'CS', 'stt' => 'fs']);
$same = $space->slot('wh1.CS.fs');
$allWarehouseForsale = $space->matchPartial(['loc' => 'wh*', 'stt' => 'fs']);
```

## 3. Build Quantity State

`QuantityState` represents the quantity state of one subject.

```php
use Nandan108\SlotFlow\QuantityState;

$inventory = new QuantityState($space, [
    ['wh1.FP.fs', 5],
    ['sup.CS.sd', 2],
]);
```

For ingestion from rows, `QuantityState::fromRows()` lets you map arbitrary row shapes into slot tuples:

```php
$inventory = QuantityState::fromRows(
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

Each tuple can optionally include a third element: per-state slot attributes.

That metadata is stored in `QuantityState`, not in the canonical `SlotSpace`, and is later available to policies through `FlowContext`.

This distinction matters in generic modeling:

- slot metadata is structural and shared
- state-side attributes are dynamic and item-specific

`Inventory` remains available as a deprecated compatibility alias for `QuantityState`.

## 4. Define Flows

A flow is a sequence of movement steps. Each step can define:

- a source pattern
- a destination pattern
- ordering policies
- filter policies
- quantity constraints
- allocation policies

Example:

```php
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Policies\DimensionPriority;

$reserve = Flow::define('reserve', static fn (Flow $c) => $c
    ->move(['stt' => 'fs'], ['stt' => 'res'])
    ->orderBy(new DimensionPriority([
        'loc' => ['wh*', 'sup'],
        'own' => ['FP', 'CP', 'CS'],
    ])));
```

`DimensionPriority` accepts ordered dimension patterns, not just literal values. The patterns are expanded through the slot-space codec, so entries like `'wh*'` or `'wh1|wh2'` behave the same way they do in slot patterns. Each entry defines one priority tier, so all matching values share the same rank.

`orderBy()` can take multiple ordering policies. Earlier policies have higher precedence, and later ones act as tie-breakers. This works because SlotFlow applies them in reverse registration order and relies on stable sorting: when a later policy ranks two edges equally, their previous order is preserved.

Useful flow helpers:

- `move($from, $to)`
- `create($to)` for `nil -> slot`
- `destroy($from)` for `slot -> nil`
- `reverseIf($condition)`
- `stepByLabeledEdges(...)`

### Reversible flows

`reverseIf(bool $condition, bool $flipEdges = true)` lets you reuse the same flow definition in forward or reverse form.

- if `$condition` is `false`, you get a clone of the original flow
- if `$condition` is `true`, the step order is reversed
- if `$flipEdges` is also `true`, each step direction is flipped as well (true reverse operation)

This is useful for rollback-style flows such as releasing reservations, correcting receptions, or undoing previously modeled movement paths.

### Parameterized template flows

A slot pattern may name a **parameter** instead of a literal value, using names that match `/[-\w]+/` — `{loc}`, `{own}`, `{from-state}`. Both pattern forms take them:

```php
// string form
$flow->move('sup.{own}.{state}', '{loc}.{own}.{state}');

// array form — the same thing, one dimension at a time
$flow->move(['loc' => '{from}'], ['loc' => '{to}']);
```

Placeholders are substituted when the flow runs, from `MovementEngine::execute(..., params: [...])`.

- **Placeholders are required.** One the params do not answer throws `SlotFlowInvalidArgumentException`, naming the parameter, the dimension, and what *was* passed. The check runs where the substitution happens, so a typo in a param name is reported as a typo rather than surfacing later as an invalid dimension value.
- Edge **labels** (`stepByLabeledEdges('{collect}')`) stay tolerant by contrast: an unresolved label matches no edge instead of throwing.
- Params reach the solver through `$context['params']`, the same place a `constraint()` or `allocate()` callback reads them — so a flow can take a routing parameter and a quantity parameter together.

One definition therefore serves every value of a dimension. That matters most for two shapes a compile-time pattern cannot express at all:

- **A move whose two endpoints are both runtime values** — a transfer or putaway, where `from` and `to` are different values of the *same* dimension. Scoping the execution to one value cannot say this; two parameters can.
- **A cascade that is fixed in shape but not in place** — "fill commitments first, then availability, in whichever warehouse this receipt landed in" is one flow, not one flow per warehouse.

So reach for a parameter before reaching for a factory that builds a `Flow` per call: a parameterized flow can be registered on the space, resolved by name, and serialized, and a built one cannot.

You can also register flows directly on a `SlotSpace`:

```php
$space->flow('book', [[['stt' => 'res'], ['stt' => 'sd']]]);
$book = $space->getFlow('book');
```

### Registered flows vs ad hoc flows

Execution accepts either:

- a `Flow` object that you built inline or fetched from the space
- a string flow name that resolves against the provided `SlotSpace`

For backward compatibility, the named argument on `MovementEngine::execute()` is still called `cascade`.

Examples:

```php
$reserve = Flow::define('reserve', static fn (Flow $c) => $c
    ->move(['stt' => 'fs'], ['stt' => 'res']));

(new MovementEngine())->execute(
    inventory: $inventory,
    space: $space,
    cascade: $reserve,
    quantity: 3,
);

$space->flow('reserve', static fn (Flow $c) => $c
    ->move(['stt' => 'fs'], ['stt' => 'res']));

(new MovementEngine())->execute(
    inventory: $inventory,
    space: $space,
    cascade: 'reserve',
    quantity: 3,
);
```

In practice:

## 5. Planning And Time-Based Features

SlotFlow `v0.2.0` adds a second layer above the core movement engine:

- `MovementPlanner` for timeless path planning
- `TimeAxis` and `TimedSlotSpace` for time-aware spaces
- `ScheduleRequest` and `EarliestArrivalSolver` for earliest-arrival planning
- `DemandScheduler` and release policies for multi-line promise and shipment planning

These concepts are useful, but they are also denser than the base movement model. To keep this guide focused on the core engine, they are documented separately in [time-planning-guide.md](time-planning-guide.md).

## 6. Execute Movements

Single-subject execution:

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

When using parameterized flows, `params` is also where placeholder substitution happens before slot patterns are expanded.

- placeholders are recognized in string patterns using `{name}` syntax
- names may contain letters, digits, `_`, and `-`
- missing params are not substituted, so execution should treat them as a modeling error

This same `params` mechanism works when the `cascade` argument receives a registered flow name, which makes named parameterized flows a good fit for application-level flow templates.

### Exceptions

SlotFlow treats invalid patterns, definitions, and lookups as modeling/API errors. All library-thrown exceptions implement `Nandan108\SlotFlow\Exceptions\SlotFlowExceptionInterface`, so callers can catch that interface for library-wide handling or the SPL-compatible `SlotFlowInvalidArgumentException` and `SlotFlowLogicException` subclasses for narrower handling. When available, `debugContext(): array` provides structured diagnostics about the failing input or state.


## 7. Batch Execution

Use `QuantityStateBatch` when you want to execute the same flow for many subjects.

```php
use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;

$batch = QuantityStateBatch::fromRows(
    space: $space,
    rows: $rows,
    subjectGetter: fn (array $row): string => $row['sku'],
    slotRowGetter: fn (array $row): array => [
        [['loc' => $row['loc'], 'own' => $row['own'], 'stt' => 'fs'], $row['fs']],
    ],
    quantityGetter: fn (array $rows): int => $rows[0]['requested_qty'],
);

$batch = (new BatchMovementEngine(new MovementEngine()))->execute(
    batch: $batch,
    space: $space,
    cascade: $reserve,
);
```

`InventoryBatch` remains available as a deprecated compatibility alias for `QuantityStateBatch`.

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

## 8. Understand The Result

`MovementResult` contains the full event history for one execution.

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

## 9. Outgress Helpers

SlotFlow does not persist anything itself. Instead, it gives you helpers for the two common outgress needs.

### Inventory Projection

Use `deltas()` when you want net per-slot deltas.

```php
foreach ($result->deltas() as $mutation) {
    $slot = $mutation->slot;
    $delta = $mutation->delta;
}
```

For batch processing:

```php
foreach ($batch->deltas() as $mutation) {
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

## 10. Policy Context And Dynamic Inventory Attributes

Policies receive a `Runtime\FlowContext`, which exposes:

- `space`
- `edges`
- `inventory`
- `quantity`
- `subject`
- `context`

and also:

- `slotAttributes(Slot $slot)`
- `slotAttribute(Slot $slot, string $name, mixed $default = null)`

This is the intended hook for dynamic, inventory-instance metadata (e.g. subject dependent capacity).

Those values should not be modeled as canonical slot metadata when they vary by inventory item or ingestion batch.

## 11. Naming

Inside the generic library, the preferred term is `subject`.

Domain examples can use a more specific term at the edges:

- commerce: `variant`
- logistics: `shipment`, `parcel`, `unit`, `lot`
- supply chain: `sku`, `material`, `item`

The important distinction is:

- core engine vocabulary stays generic
- example and application vocabulary stays domain-legible

## 12. What SlotFlow Is Not

SlotFlow is not trying to be:

- a domain-complete inventory engine
- a warehouse-management system
- an ERP
- a persistence layer
- a transaction manager
- a billing or ordering system

Those concerns belong above the engine.

If you need a domain-rich solution, the intended approach is to build a higher-level package on top of SlotFlow rather than forcing those semantics into the core.
