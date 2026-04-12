# SlotFlow v0.2.0

`v0.2.0` is the first release that extends SlotFlow beyond immediate quantity execution into planning and time-aware scheduling.

The core `Flow` and `MovementEngine` model is unchanged in spirit: flows still describe allowed movement, and execution still applies those movements greedily and deterministically against current state. What is new is a second layer that can answer planning questions before anything is mutated.

## Highlights

- timed slot spaces via `TimeAxis` and `TimedSlotSpace`
- timeless path planning via `MovementPlanner`
- earliest-arrival timed scheduling via `ScheduleRequest` and `EarliestArrivalSolver`
- order-level demand scheduling via `Demand`, `DemandLine`, `DemandScheduleRequest`, and `DemandScheduler`
- shipment release policies and shipment calendars
- weekly calendar helpers for dispatch and shipment release windows

## What Changed Since v0.1.1

### Timed layer

Slot spaces can now carry a `TimeAxis`, and base edges can be expanded into timed movement edges with durations and dispatch constraints.

This enables planning scenarios such as:

- delivery promise calculation
- inbound ETA calculation
- transfer planning
- dispatch cutoff modeling

### Timeless planning

`MovementPlanner` and `MovementPlan` provide a planning-oriented counterpart to `MovementEngine` and `MovementResult`.

Use this when you want to ask:

- can this quantity reach a target?
- through which path?
- what are the resulting net slot deltas?

without mutating the live `QuantityState`.

### Timed scheduling

`EarliestArrivalSolver` turns a flow plus a target into a `MovementSchedule`, made of timed steps and milestones.

This is the layer to use when "when can it arrive?" matters as much as "can it move?".

### Demand scheduling

`DemandScheduler` composes many per-line schedules into one order-level result.

This includes:

- per-line arrivals
- shipment grouping over time
- release policies such as partial, full, threshold-based, and priority-based shipment
- shipment calendars applied either at order level or as planner rules on edges/steps

## Positioning

The movement engine remains the most battle-shaped part of the package.

The new timed and planning APIs are documented and tested, but they are still earlier in their design life. They are intended to support real delivery-promise and fulfillment-planning use cases, and they may continue to evolve as those use cases become more concrete.

## Recommended Adoption

For new integrations:

- use `Flow`, `SlotSpace`, `QuantityState`, and `MovementEngine` as the stable core
- adopt `MovementPlanner` when you need previewable path planning
- adopt `ScheduleRequest` and `DemandScheduler` only when you actually need time-aware promise or shipment logic

For a fuller walkthrough, see [guide.md](guide.md).
