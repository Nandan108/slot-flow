# Origin of SlotFlow

Before SlotFlow, inventory movements were implemented in a large,
multi-responsibility class:

- tightly coupled data + behavior
- implicit movement rules
- hardcoded routing logic
- difficult to test or reuse

See: [original-MPB-InventoryEngine.php](original-MPB-InventoryEngine.php)

## Key limitations

- No explicit state space model
- Movement paths encoded imperatively
- No composable constraints
- Limited reusability across domains

## What SlotFlow changes

SlotFlow extracts these concepts into:

- SlotSpace (state modeling)
- MovementPath (routing)
- Constraints (limits)
- MovementEngine (execution)

This allows the same core engine to be reused across domains such as:
inventory, logistics, manufacturing, etc.
