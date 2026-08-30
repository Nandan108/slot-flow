# Product Backlog

# TODO
- [013] Add non-greedy engine strategy and new fixture and example for shortest path (Dijkstra)
- [014] Add `$result->trace()` to return a decision trace demonstrating how result was produced.
        E.g. `[['step' => 1,'candidates' => [...],'afterFilters' => [...],'afterOrdering' => [...],'selected' => [...],'appliedQuantity' => ...], ['step' => 2, ...]]`
- [019] Extract an `EdgeGraph` owning topology, now that [018] has settled the semantics.
- [020] Finish the temporal severance: remove `SlotSpace::defineTimed()` and `$space->timeAxis` so
        `SlotSpace.php` comes off the `tests/BoundaryTest.php` allow-list. Needs `ScheduleRequest` to
        take a `TemporalContext` explicitly, `DemandScheduleRequest` a nullable one (it deliberately
        supports an axis-less space with integer buckets), and `TimedSlotSpace::fromBaseSpace()` the
        same — then ~110 call sites threaded through, all inside the timed suite. Deferred because
        that cost falls entirely on the unvalidated layer. Subclassing is *not* the cheap route: it
        means un-finalizing `SlotSpace`, trading an import coupling for an inheritance one.
- [021] Small fixes, none urgent, all verified present:
        `EdgeRule::deny()` / `allowLabeled()` declare an optional `$label` before required `$from`,
        which emits a PHP deprecation at class load (CI runs 8.1–8.5, and `failOnDeprecation` is on
        — it currently slips through only because it fires during autoload);
        `QuantityState::all()` is documented "non-zero" but returns every entry;
        `DefaultSlotKeyCodec::matchDimensionValues()` caches alternation patterns under a key it
        never reads back, so those entries only grow;
        `src/Operations`, `src/Planners` and `src/Constraints` are empty directories.

# DONE
- [005] Fix all psalm errors before anything else!
- [006] Add docs describing all features
- [001] remove `SlotSpace::$namedPaths`, replace by `array<non-empty-string, list<Cascade>> $cascades `
- [002] introduce `SlotSpace::cascade()`, fluent interface for declaring cascade (greedy consumption)
- [003] Metadata at slot level, same as we have at edge level, to support Policy calculations
- [008] Turn CommerceFlowExample into the canonical end-to-end SlotFlow example for commerce.
- [007] Define and document the outgress pattern: MovementResults -> InventoryMutations + LedgerEntry
- [011] Show an inventory constraint example in the docs/README
- [010] Update docs around stable sort requirements
- [012] Refactor library exceptions into a unified hierarchy
- [009] Set up automated API docs with phpDocumentor
- [012] Set up CI
- [015] TimedSlotSpace, TimeAxis, TimedSlot
- [016] Solver specified at 1. engine-level default. *(Only the engine-level default shipped:
        `MovementEngine` takes a solver. There is no flow-level default and no per-execution
        override — `Flow` has no solver property and `execute()` has no solver argument. Reopen as
        a TODO if either is still wanted.)*
- [017] Implement EarliestArrivalSolver
- [018] Decide what `edgeRules()` means, and make one edge graph authoritative. Settled as an
        opt-in base (`EdgeRuleBase::All|None`), mirroring `SlotRuleBase`: enforcement is stated, not
        inferred from the presence of rules. Always-enforce was not viable — `getEdgesFrom()` is
        empty when nothing is declared, so it would have silently stopped every flow in any space
        without edge rules, including every one of InvFlux's.

