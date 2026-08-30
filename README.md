# SlotFlow

![CI](https://github.com/nandan108/slot-flow/actions/workflows/ci.yml/badge.svg)
![Coverage](https://codecov.io/gh/nandan108/slot-flow/branch/main/graph/badge.svg)
![Style](https://img.shields.io/badge/style-php--cs--fixer-brightgreen)
![Packagist](https://img.shields.io/packagist/v/nandan108/slot-flow)

SlotFlow is a deterministic PHP engine for inventory and commerce operations that model quantity movement across an explicit multidimensional state space.

## Mental Model

Think of SlotFlow as a constrained routing engine:

- each **slot** is a node in a graph
- each **edge** is an allowed movement
- a **flow** is an ordered movement definition

When you request a quantity movement, SlotFlow:

1. finds valid edges from the current state
2. orders them according to your policies
3. moves as much as possible
4. carries the remainder to the next options

> SlotFlow makes quantity movements explicit, deterministic, and auditable.

SlotFlow is intentionally not a full ERP, OMS, or WMS. It is the lower-level engine those systems can build on for stock movement, allocation, backorders, and delivery promises.

## Core Concepts

- A `Slot` is one concrete state such as `wh1.FP.fs`.
  - The special `nil` slot represents outside-of-space flow: both source, sink, and effectively `/dev/null`.
- A `SlotSpace` is the finite universe of valid slots generated from named dimensions.
- An `Edge` is a movement between two slots. Whether the edges you declare *limit* what a flow may
  traverse is your choice, stated with `EdgeRuleBase` — see [Constraining the topology](#constraining-the-topology).
- A `Flow` is an ordered movement definition that defines how movement is attempted.
- A `QuantityState` stores the current quantity distribution for one subject across the slot space.
- `MovementEngine` executes a requested quantity against current state and returns movement events plus any remainder.

At its core, SlotFlow acts as a declarative inventory-movement engine over a constrained state space.

**Notes**

- Flows can be instantiated independently, but are typically registered on a `SlotSpace` and referenced by name during execution.
- Flows can be reversed with `reverseIf()` and parameterized, allowing a single definition to adapt to different execution contexts.

## When SlotFlow shines

SlotFlow is a good fit when:

- quantities exist in multiple states or locations
- movement rules are non-trivial or evolving
- allocation must be deterministic and explainable
- you need to model backorders or delivery promises
- multi-line orders may release in full, partial, threshold-based, or priority-based shipments
- you need auditability (ledger-style tracking)

It is likely overkill for simple stock counters or single-location systems.

## Install

SlotFlow currently requires PHP 8.1 or newer.

```bash
composer require nandan108/slot-flow

## Licensing

SlotFlow is dual-licensed:

- `GPL-2.0-or-later` for open-source use under GPL-compatible terms
- a separate commercial license for proprietary or otherwise non-GPL-compatible use

See [LICENSE](LICENSE), [LICENSE-GPL-2.0-or-later](LICENSE-GPL-2.0-or-later), and [LICENSE-SlotFlow-Commercial](LICENSE-SlotFlow-Commercial).
```

## Minimal Example

```php
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\Policies\DimensionPriority;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\SlotSpace;

$space = SlotSpace::define([
    'loc' => ['sup', 'wh1'],
    'stt' => ['fs', 'res', 'sd'],
])
->flow('reserve', static fn (Flow $flow) => $flow
    ->move(['stt' => 'fs'], ['stt' => 'res'])
    ->orderBy(new DimensionPriority(['loc' => ['wh*', 'sup']]))
);

$inventory = new QuantityState($space, [
    ['wh1.fs', 5],
    ['sup.fs', 10],
]);

$result = (new MovementEngine())->execute(
    inventory: $inventory,
    space: $space,
    flow: 'reserve',
    quantity: 6,
    subject: 'SKU-123',
);
```

`MovementEngine::execute()` accepts either a `Flow` object or the name of a flow registered on the provided `SlotSpace`. Named execution is often the cleaner option once your flows are part of the modeled space.

### How the flow behaves

![SlotFlow overview](docs/diagrams/slotflow-overview.svg)

In this example:

- the engine first consumes `wh1.fs → wh1.res`
- then falls back to `sup.fs → sup.res`

So 5 units move from `wh1.fs` to `wh1.res`, then the remaining 1 unit moves from `sup.fs` to `sup.res`.

## Slightly more advanced routing

Flows can express real-world fallback strategies, including backorders.

```php
$space = SlotSpace::define([
    'loc' => ['sup','wh1', 'wh2'], // sup: supplier, wh*: our warehouses
    'own' => ['S', 'P'],           // S: supplier-owned / P: purchased
    'stt' => ['fs', 'res', 'sd'],  // fs: for-sale, res: reserved, sd: sold
])
->flow('backorder', static fn (Flow $flow) => $flow
    // prioritize stock we already own
    ->move(['stt' => 'fs', 'own' => 'P'], ['stt' => 'res'])

    // prefer warehouse over supplier
    ->orderBy(new DimensionPriority(['loc' => ['wh*', 'sup'],]))

    // fallback: create supplier-owned reservation (backorder)
    // this represents stock that will be ordered from the supplier
    // Note: could also be written ->create('sup.S.res') or ->create(['sup','S','res'])
    ->create(['loc' => 'sup', 'own' => 'S', 'stt' => 'res'])
    // disallow backorders beyond 100
    ->constraint(static fn (MovementEdge $edge, FlowContext $ctx): int|float =>
        max(0, 100 - $ctx->inventory->getSum('sup.S.res|sd')))
);
```

This flow encodes a common allocation policy:

1. use purchased stock first
1. prefer stock already in your warehouses
1. if insufficient, create a supplier reservation (backorder)
1. but never let open supplier backorders (`sup.S.res|sd`) exceed 100 units

This makes backordering an explicit, deterministic part of the flow.

The same policy also supports alternatives such as `'wh1|wh2'`, because priority entries are resolved through the configured slot codec before ranking edges. All values matched by the same entry share the same priority tier.

Registered flow names pair especially well with parameterized templates: you define the flow once on the `SlotSpace`, then execute it by name with different `params` depending on the request.

## Constraining the topology

By default a movement step may traverse any pair of slots its `from` and `to` patterns can express.
That is what a space declaring no edge rules has always meant, and it is usually what you want while
the model is still moving.

Where the legal transitions are part of the domain rather than an implementation detail, say so:

```php
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\Rules\EdgeRuleBase;

$space->edgeRules([
    EdgeRule::allowLabeled('reserve', ['stt' => 'fs'], ['stt' => 'res']),
    EdgeRule::allow(['stt' => 'res'], ['stt' => 'sd']),
], EdgeRuleBase::None);
```

Under `EdgeRuleBase::None` the declared graph is authoritative: a `move()` over a pair you never
declared finds no edge and moves nothing, rather than quietly succeeding. An unsanctioned path
becomes a refusal at execution instead of a movement someone has to notice afterwards.

Two things stay true whichever base you choose:

- **Boundary movements are never constrained.** `create()` and `destroy()` cross the `nil` slot,
  which is the outside of the space rather than a member of it, so no topology rule describes it.
- **Tightening is one-way.** Once `EdgeRuleBase::None` has been stated, a later `edgeRules()` call
  that does not repeat it cannot reopen the topology — so a rule list assembled from several
  independent contributors cannot be silently un-enforced by one of them.

Under `None` a movement step also receives the *declared* edge, so it sees the label and metadata
its rule carries, exactly as `stepByLabeledEdges()` does.

## Delivery Promises And Order Release

> **Experimental.** Everything in this section — the timed slot space, the planners, and demand
> scheduling — is unproven against a real workload. It is tested and documented, but no production
> consumer has yet exercised it, so its shapes are expected to change once one does. Treat it as a
> sketch of a direction rather than a settled contract, and pin an exact version if you build on it.
> The execution engine described above carries no such caveat.

SlotFlow can also plan timed delivery promises.

- `ScheduleRequest` and `EarliestArrivalSolver` build one timed movement schedule for one subject quantity
- `Demand`, `DemandLine`, `DemandScheduleRequest`, and `DemandScheduler` compose many subject schedules into one order-level promise
- release policies such as `PartialShipmentPolicy`, `FullShipmentPolicy`, `ThresholdReleasePolicy`, and `PriorityReleasePolicy` decide how ready lines turn into shipments
- timed slot spaces can also apply dispatch calendars, for example cutoff-time or no-weekend dispatch rules

## Execution Output

SlotFlow computes movement. It does not persist it, and it does not modify the state you hand it.

`MovementEngine::execute()` works against a private copy, so the quantity state you pass in is the
same afterwards. A result can therefore be held, inspected, compared against an alternative flow, or
thrown away without leaving anything half-moved — and running the same movement twice against the
same state gives the same answer both times.

To get the state a movement produces, apply the result:

```php
$result = (new MovementEngine())->execute($before, $space, 'reserve', 6, 'SKU-123');
$after  = $result->applyTo($before);   // $before is untouched
```

It produces explicit, inspectable results that you can store, audit, or replay.

The main result shapes are:

- `MovementResult::deltas()` for net per-slot current-state deltas
- `MovementResult::applyTo($state)` for the resulting state, as a copy
- `MovementResult::trace()` for a per-step decision record — see [Why did it move that?](#why-did-it-move-that)
- `MovementResult::ledgerEntries($context)` for append-only movement records
- `MovementSchedule::$steps`, `MovementSchedule::$milestones`, and `MovementSchedule::deltas()` for time-based planning output
- `DemandSchedule::$lines` and `DemandSchedule::$shipments` for multi-line order promise output
- `QuantityStateBatch::deltas()` and `QuantityStateBatch::ledgerEntries($context)` for the same outputs across many subjects

## Why did it move that?

More often the question is why it moved *nothing*. Ask the solver for a decision trace:

```php
$engine = new MovementEngine(new GreedyFlowSolver(trace: true));
$result = $engine->execute($state, $space, 'reserve', 6);

foreach ($result->trace() as $step) {
    foreach ($step['edges'] as $edge) {
        printf(
            "%s  available=%s movable=%s moved=%s\n",
            $edge['edge'], $edge['available'], $edge['movable'], $edge['moved'],
        );
    }
}
```

```
(wh1.fs) -> (wh1.res)  available=2 movable=2 moved=2
(sup.fs) -> (sup.res)  available=4 movable=0 moved=0
```

The three quantities are what make this diagnostic rather than decorative, because each drop to zero
has a different cause:

- **`available` is 0** — the source slot was empty.
- **`available` above 0, `movable` 0** — a quantity constraint capped the edge, as above.
- **`movable` above 0, `moved` 0** — the requested quantity was already satisfied upstream.

Each step also records its `candidates`, and what the filter and ordering policies did to them
(`afterFilters`, `afterOrdering`) — so a declared ordering is visible as an ordering, not only in its
effect. An empty `candidates` means no edge matched the step at all, which under
`EdgeRuleBase::None` is how a refused topology reads.

Tracing is off by default: it costs memory per step and most executions never look at one.

## Terminology

Current core terminology:

- `Flow`: generic ordered movement definition
- `QuantityState`: quantity distribution for one subject
- `QuantityStateBatch`: grouped quantity states for batch execution
- `QuantityStateDelta`: one net per-slot quantity delta

## Guide

- [Guide](docs/guide.md): core SlotFlow concepts, execution, and batch processing
- [Time And Planning Guide](docs/time-planning-guide.md): timeless planning, timed slot spaces, earliest-arrival scheduling, and demand scheduling
- [Commerce Example](docs/commerce-example.md): a fuller e-commerce flow model
- [v0.2.0 notes](docs/release-notes-v0.2.0.md): summary of the timed layer, planners, and demand scheduling additions
- [Changelog](CHANGELOG.md): release-by-release project history
- Generated API docs: https://nandan108.github.io/slot-flow

## Origin

SlotFlow originates from a real-world inventory system I developed in 2017 for a production e-commerce platform.

That system handled:

- multi-location stock allocation
- inbound stock and delivery promise computation
- reservation and booking flows
- partial shipment tracking
- movement logging (ledger)

Over time, the limitations of a tightly coupled implementation became clear: movement rules, state representation, and execution logic were all intertwined.

SlotFlow is an extraction of its core ideas as a composable engine for inventory movement and promise calculation.

For historical reference, the original implementation is preserved here:
👉 [`docs/history/original-MPB-InventoryEngine.php`](docs/history/original-MPB-InventoryEngine.php)

## Quality

- 99% automated test coverage
- Psalm level 1 clean
- CI runs Psalm, coverage, and phpDocumentor on PHP 8.1
- CI runs PHPUnit on PHP 8.1, 8.2, 8.3, 8.4, and 8.5
- Generated API docs published from source via phpDocumentor

## Release Status

`v0.3.0` sharpens the execution engine and states what was previously left implicit:

- execution is pure — `execute()` no longer modifies the state it is given
- `EdgeRuleBase` states whether the declared edge graph constrains movement
- `MovementResult::trace()` explains why a movement did what it did
- the deprecated `Cascade` / `Inventory` compatibility surface is gone

`v0.2.0` added the timed planning layer: `TimeAxis`, `TimedSlotSpace` and timed edges,
`MovementPlanner` for timeless path planning, `ScheduleRequest` and `EarliestArrivalSolver` for
timed planning, and `DemandScheduler` with shipment release policies for order-level promises.

**The execution engine is stable; the timed and demand-scheduling layer is experimental.** The
distinction is not a matter of polish — the timed layer is tested and documented — but of
validation: nothing has yet run it in anger, so its API is a hypothesis about what timed planning
needs, not a report of what it turned out to need.

The dependency between the two runs one way (timed → core) and is enforced by
`tests/BoundaryTest.php`, so the unvalidated half cannot constrain the validated one, and the core
can be refactored without keeping a scheduling design honest. `composer test:core` runs the core
suite alone; `composer test` runs everything.

## License

SlotFlow is dual-licensed under `GPL-2.0-or-later` or the SlotFlow commercial license.
See [LICENSE](LICENSE), [LICENSE-GPL-2.0-or-later](LICENSE-GPL-2.0-or-later), [LICENSE-SlotFlow-Commercial](LICENSE-SlotFlow-Commercial), and [NOTICE](NOTICE).

Organizations using SlotFlow in proprietary or otherwise non-GPL-compatible software must obtain a separate commercial license.
Reduced-fee or no-fee commercial licenses may be available on request for qualified nonprofit, humanitarian, and public-interest organizations.
