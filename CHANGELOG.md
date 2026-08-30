# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog, and this project uses Git tags for released versions.

## [Unreleased]

### Changed

- **`MovementEngine::execute()` no longer modifies the quantity state it is given.** It works
  against a private copy, so execution is a pure computation: a result can be held, compared, or
  discarded without leaving the caller's state half-moved, and executing the same movement twice
  against the same state now gives the same answer. Callers that relied on the state being advanced
  in place should apply the result instead — `$after = $result->applyTo($before);`.
- `SlotCodec::__construct()` no longer takes a `?TimeAxis`. The codec serializes dimension values;
  time is a separate axis the timed layer expands along, never a slot dimension. **Breaking for any
  class implementing `SlotCodec` directly.**

### Added

- `MovementResult::applyTo(QuantityState): QuantityState` — the state a movement produces, as a copy.
- `QuantityState::withDelta(Slot, int|float): static` — the immutable spelling of `add()`.
- `Time\TemporalContext` — one value carrying the time axis, duration resolver and dispatch calendar,
  and the single home for normalizing raw callables into their interface wrappers. `SlotSpace` and
  `TimedSlotSpace` previously each carried their own copy of that normalization.
- `tests/BoundaryTest.php` enforces a one-way dependency from the timed/demand layer to the execution
  core, so the unvalidated half cannot constrain the validated one.
- `composer test:core` / `composer test:timed` run the two halves of the suite separately.

### Fixed

- `FlowStepBuilder::policies()` silently discarded everything declared through `orderBy()`,
  `filter()`, `constraint()` and `allocate()`: it rebuilt each typed bucket from its own policy bag,
  erasing the entries the dedicated methods had written. A flow that declared an ordering and then
  called `policies()` ran with no ordering at all and simply produced a different movement, with
  nothing reported. Buckets are now merged rather than replaced.
- `MovementResult::isComplete()` returned `false` for a fully satisfied movement of float quantities
  (`0 === 0.0` is false). `MovementSchedule` and `MovementPlan` already handled this correctly.
- `MovementResult::deltas()`, `MovementSchedule::deltas()` and `MovementPlan::deltas()` failed to
  prune slots whose float movements net to zero, for the same reason.

### Notes

- The timed and demand-scheduling layer is now marked `@experimental`. It is tested and documented,
  but no production consumer has yet exercised it, so its shape is expected to change once one does.
  The execution engine is unaffected by that caveat.

## [0.2.1] - 2026-07-01

### Added

- `SerializablePolicy` interface, implemented by `DimensionPriority`, so a dimension-priority policy can be serialized and rehydrated.

### Fixed

- `destroy([])` is now treated as "match all" rather than an empty positional tuple.
- Dimension value codes may now contain `/`, enabling hierarchical location codes (e.g. `oh/main`).

## [0.2.0] - 2026-04-12

This release extends SlotFlow beyond immediate flow execution into planning, time-aware scheduling, and order-level promise calculation.

### Added

- A new timeless planning layer via `PlanRequest`, `MovementPlan`, `PlannedStep`, `MovementPlanner`, and `GreedyPlanSolver`.
  This is for path inspection before execution: for example, deciding whether a requested quantity can reach a concrete target slot and what net slot deltas that path would imply.
- A new timed planning layer via `TimeAxis`, `TimedSlot`, `TimedMovementEdge`, `TimedQuantityState`, `TimedQuantityStateDelta`, `TimedSlotSpace`, `ScheduleRequest`, `MovementSchedule`, `ScheduledStep`, `ScheduleMilestone`, and `EarliestArrivalSolver`.
  This is for time-aware questions such as delivery-promise estimation, inbound ETA planning, dispatch cutoff modeling, and earliest-arrival transfer planning.
- A new multi-line demand-planning layer via `Demand`, `DemandLine`, `DemandScheduleRequest`, `DemandSchedule`, `DemandReleaseContext`, `DemandScheduler`, and `TimelineShipmentPlanner`.
  This is for order-level promise and shipment planning across many lines, rather than executing one movement for one subject at a time.
- Shipment release policies via `PartialShipmentPolicy`, `FullShipmentPolicy`, `ThresholdReleasePolicy`, and `PriorityReleasePolicy`.
- Shipment calendar support via `ShipmentCalendarInterface`, `ShipmentCalendarRuleInterface`, `WeeklyShipmentCalendar`, `WeeklyShipmentCalendarRule`, and `ShipmentWaveCalendarRule`.
- Weekly calendar primitives for dispatch and release windows via `WeeklyCalendar`, `WeeklyCalendarMoment`, `WeeklyCalendarWindow`, and `WeeklyDispatchCalendar`.
- Named-policy and policy-bucket helpers for attaching typed planner, shipment-calendar, and shipment-split policies to edges and flow steps.
- Dedicated fixtures and test coverage for timed planning, delivery-promise scheduling, and demand scheduling.

### Changed

- `MovementEngine` now depends on `ExecutionSolverInterface`, and the planning/scheduling layer introduces companion contracts including `PlanSolverInterface`, `ScheduleSolverInterface`, `DemandReleasePolicyInterface`, `ShipmentPlannerInterface`, and related policy interfaces.
- Slot, edge, flow, rule, and quantity-state internals were extended to support planner policies, shipment-calendar rules, timed evaluation, and planning-oriented result objects.
- `SlotSpace`, slot-pattern handling, and flow-building helpers were refactored to better support timed spaces, parameterized planning, and policy-aware edge resolution while preserving the `Cascade` compatibility alias.
- The documentation set now includes a separate time-and-planning guide, `v0.2.0` release notes, and expanded generated API docblocks.
- CI, PHPUnit/Psalm configuration, and package metadata were updated to present PHP 8.1 as the baseline supported version.
- Licensing moved from MIT to a dual-license model: `GPL-2.0-or-later` or the SlotFlow commercial license.

### Notes

- `MovementResult` remains the execution-oriented result of applying a `Flow` against current state. The new request/result types exist to answer different questions at different scopes:
  `PlanRequest` and `MovementPlan` for deterministic path selection to a target slot without mutation,
  `ScheduleRequest` and `MovementSchedule` for earliest-arrival planning over a timed space,
  and `DemandScheduleRequest` and `DemandSchedule` for composing many line schedules into planned shipment releases.
- The core movement engine remains the most mature part of the package; the new timed and demand-scheduling APIs are documented and tested, but should still be expected to evolve as more real-world planning use cases are applied to them.

## [0.1.1] - 2026-03-26

### Added

- `Flow` as the preferred generic movement-definition type, with `QuantityState` as the preferred generic quantity-state type.
- `GreedyFlowSolver` and the initial execution-solver abstraction.
- `FlowContext`, `QuantityStateDelta`, `QuantityStateBatch`, and `AvailableQuantitySortPolicy`.
- Legacy API compatibility coverage tests.

### Changed

- Core terminology was generalized away from commerce-specific naming.
- Batch processing, movement execution, and result types were refactored around the new `Flow` and `QuantityState` vocabulary.
- `Cascade` and `Inventory` were retained as compatibility aliases while the newer terminology became primary.

## [0.1.0] - 2026-03-25

### Added

- GitHub Actions CI.
- Generated API documentation via phpDocumentor.
- Public API docblocks across the existing engine surface.
- Published project license and documentation cleanup around generated docs.

### Changed

- Replaced the handwritten API reference with generated API documentation.
- Updated the README and guide to point to generated docs and CI-backed quality checks.
