# Product Backlog

# TODO
- [009] Set up automated API docs with phpDocumentor

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
