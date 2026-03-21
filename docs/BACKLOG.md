# Product Backlog

# TODO
- [004] SlotRule::allowIntersect($from, $to, $meta)
- [006] Add docs describing all features
- [007] Define and document the outgress pattern for applying movement results to application state.
    Cover both inventory projection (current state updates) and append-only ledger/event recording.
    Make the integration boundary clear without hard-coding persistence concerns into the core library.
- [008] Turn CommerceFlowExample into the canonical end-to-end SlotFlow example for commerce.
    It should demonstrate a coherent lifecycle, include outgress handling, and teach correct concepts without unresolved caveats.

# DONE
- [001] remove `SlotSpace::$namedPaths`, replace by `array<non-empty-string, list<Cascade>> $cascades `
- [002] introduce `SlotSpace::cascade()`, fluent interface for declaring cascade (greedy consumption)
- [003] Metadata at slot level, same as we have at edge level, to support Policy calculations
