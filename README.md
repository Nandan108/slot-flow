## Origin

SlotFlow originates from a real-world inventory system developed in 2017 for a production e-commerce platform.

That system handled:

- multi-location stock allocation
- inbound stock and delivery promise computation
- reservation and booking flows
- partial shipment tracking
- movement logging (ledger)

Over time, the limitations of a tightly coupled implementation became clear:
movement rules, state representation, and execution logic were all intertwined.

SlotFlow will be a rethinking of those ideas as a **generic, composable flow engine**.

For historical reference, the original implementation is preserved here:
👉 [`docs/history/original-MPB-InventoryEngine.php`](docs/history/original-MPB-InventoryEngine.php)
