# SlotFlow Time And Planning Guide

This guide covers the `v0.2.0` layer added on top of the core movement engine:

- timeless path planning
- time-aware slot spaces
- earliest-arrival scheduling
- multi-line demand scheduling and shipment release calculation

If you only need flow execution against current state, start with [guide.md](guide.md) first.

## 1. Timeless Planning

`MovementPlanner` answers a different question than `MovementEngine`.

- `MovementEngine` applies movement immediately against a `QuantityState`
- `MovementPlanner` builds a path-oriented plan first and leaves the original state untouched

Use timeless planning when you want to inspect how quantity would move to a concrete target before deciding whether to execute anything.

```php
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementPlanner;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\SlotSpace;

$space = SlotSpace::define([
    'loc' => ['sup', 'wh', 'cust'],
    'stt' => ['fs', 'sd'],
])->edgeRules([
    EdgeRule::allowLabeled('receive', 'sup.fs', 'wh.fs'),
    EdgeRule::allowLabeled('ship', 'wh.fs', 'cust.sd'),
])->flow(
    'source-order',
    static fn (Flow $flow) => $flow
        ->stepByLabeledEdges('receive')
        ->stepByLabeledEdges('ship'),
);

$inventory = new QuantityState($space, [['sup.fs', 5]]);

$plan = (new MovementPlanner())->plan(
    inventory: $inventory,
    space: $space,
    flow: 'source-order',
    quantity: 3,
    target: 'cust.sd',
);
```

The resulting `MovementPlan` gives you:

- ordered `PlannedStep` objects
- `remaining` quantity if the target cannot be fully satisfied
- `deltas()` for net per-slot impact

The target is a concrete terminal slot. That keeps planning deterministic: the solver is answering "build a path to this slot", not "pick any acceptable destination".

## 2. Time-Aware Slot Spaces

Timed scheduling builds on a base `SlotSpace` plus a `TimeAxis`.

`TimeAxis` defines:

- the planning bucket, such as hour or day
- the planning horizon
- optional aliases such as `day => 24`
- the reference origin used to convert between bucket indexes and calendar time

`TimedSlotSpace::fromBaseSpace()` then expands the base graph into timed slots and timed edges.

Durations can come from:

- edge metadata, for example `['duration' => '2d']`
- custom duration resolvers
- dispatch calendars that delay when an edge may be used

Weekly helpers are available when you want shipping or dispatch to happen only at certain wall-clock moments or windows:

- `WeeklyCalendar`
- `WeeklyCalendarMoment`
- `WeeklyCalendarWindow`
- `WeeklyDispatchCalendar`
- `WeeklyShipmentCalendar`

## 3. Delivery Promise Scheduling

If your `SlotSpace` has a `TimeAxis`, you can plan earliest-arrival movement schedules with `EarliestArrivalSolver`.
Use this when you want to estimate the earliest feasible arrival for display or decision-making, for example on a product, cart, or checkout screen.
The resulting schedule is a planning artifact, not a committed inventory mutation.

This is useful for:

- delivery-promise calculation
- inbound and transfer planning
- dispatch scheduling
- backorder ETA estimation

Example:

```php
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\Rules\EdgeRule;
use Nandan108\SlotFlow\ScheduleRequest;
use Nandan108\SlotFlow\Solvers\EarliestArrivalSolver;
use Nandan108\SlotFlow\SlotSpace;
use Nandan108\SlotFlow\Time\TimeAxis;

$space = SlotSpace::defineTimed(
    dimensions: [
        'loc' => ['sup', 'wh', 'cust'],
        'stt' => ['po', 'fs', 'sd'],
    ],
    timeAxis: TimeAxis::define(bucket: 'hour', horizon: 24 * 7, aliases: ['day' => 24]),
)->edgeRules([
    EdgeRule::allowLabeled('receive-standard', 'sup.po', 'wh.fs', ['duration' => '2d']),
    EdgeRule::allowLabeled('receive-express', 'sup.po', 'wh.fs', ['duration' => '1d']),
    EdgeRule::allowLabeled('ship-standard', 'wh.fs', 'cust.sd', ['duration' => '1d']),
])->flow(
    'promise-deliver',
    static fn (Flow $flow) => $flow
        ->stepByLabeledEdges('receive-standard', 'receive-express')
        ->stepByLabeledEdges('ship-standard'),
);

$schedule = (new EarliestArrivalSolver())->schedule(new ScheduleRequest(
    state: $inventory,
    space: $space,
    flow: 'promise-deliver',
    quantity: 4,
    target: 'cust.sd',
));
```

The resulting `MovementSchedule` gives you:

- timed steps
- arrival milestones
- net timed deltas
- a concrete earliest arrival for the requested quantity

`ScheduleRequest` extends the same basic planning model as `MovementPlanner`, but with a required `startTime` and a timed target search over a `TimedSlotSpace`.

In practice:

- use `MovementPlanner` when time does not matter
- use `EarliestArrivalSolver` when the path duration and arrival time matter
- use `MovementEngine` when you are ready to apply the movement now

## 4. Multi-Line Demand Scheduling

`DemandScheduler` sits one layer above `ScheduleRequest`.

Instead of scheduling one subject quantity, it schedules many demand lines and then turns the resulting arrival timelines into planned shipments.

```php
use Nandan108\SlotFlow\Demand;
use Nandan108\SlotFlow\DemandLine;
use Nandan108\SlotFlow\DemandScheduleRequest;
use Nandan108\SlotFlow\DemandScheduler;
use Nandan108\SlotFlow\Policies\PartialShipmentPolicy;

$schedule = (new DemandScheduler())->schedule(new DemandScheduleRequest(
    demand: new Demand([
        new DemandLine('sku-1', 2),
        new DemandLine('sku-2', 1),
    ]),
    space: $space,
    flow: 'promise-deliver',
    target: 'cust.sd',
    statesBySubjectKey: [
        'sku-1' => $inventoryBySku['sku-1'],
        'sku-2' => $inventoryBySku['sku-2'],
    ],
    releasePolicy: new PartialShipmentPolicy(),
));
```

The resulting `DemandSchedule` contains:

- one `DemandLineSchedule` per requested line
- per-line arrival slices at the requested target
- one or more `DemandShipment` objects produced by the shipment planner

Built-in release policies include:

- `PartialShipmentPolicy`: ship every ready quantity immediately
- `FullShipmentPolicy`: wait until all lines are ready
- `ThresholdReleasePolicy`: release once a fill threshold is reached
- `PriorityReleasePolicy`: allow higher-priority subjects to ship sooner

You can further shape shipment timing with:

- `consolidationWindow` on `DemandScheduleRequest`
- order-level `ShipmentCalendarInterface`
- step- or edge-attached shipment calendar rules such as `ShipmentWaveCalendarRule` and `WeeklyShipmentCalendarRule`

This layer is aimed at promise and release calculation, not stock mutation. Once you choose to commit the result, your application is still responsible for persisting any resulting stock updates, holds, or reservation movements.

## 5. Result Shapes

The planning-oriented result types mirror the structure of the core execution result:

- `MovementPlan` is the timeless planning result
- `MovementSchedule` is the timed planning result
- `DemandSchedule` is the multi-line shipment-planning result

All three are immutable summaries intended for inspection and downstream persistence decisions.
