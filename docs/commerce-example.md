# Commerce Example

This page explains the fixture in [tests/Fixtures/CommerceFlowExample.php](../tests/Fixtures/CommerceFlowExample.php).

It is not meant to model an entire commerce platform. It is meant to show how a commerce inventory domain can be expressed on top of SlotFlow without making SlotFlow itself commerce-specific.

That distinction matters:

- SlotFlow is the generic engine
- this fixture is one domain translation layer built on top of it

## Why The Example Uses `variant`

The core library uses the generic term `subject`.

The commerce fixture uses `variant`, because that is easier to read for developers coming from product-catalog and inventory systems. The example maps that domain term into the generic batch API through `subjectGetter`.

## Dimensions

The example defines three dimensions:

- `loc`: location
  - `sup`, `eu`, `wh1`, `wh2`, `cst`
- `own`: ownership type
  - `CS`: consignment stock still owned by supplier
  - `CP`: consignment stock later purchased by us
  - `FP`: firm-purchase stock
- `stt`: stock state
  - `fs`: for sale
  - `res`: reserved
  - `sd`: sold
  - `ret`: returned
  - `def`: defective

This produces slots such as:

- `wh1.CS.fs`
- `sup.CS.sd`
- `wh2.FP.def`

## Slot And Edge Rules

The fixture starts permissive, then adds a few constraints:

- all slots are allowed
- `wh2.*.*` carries slot metadata `food-storage=true`
- movement between consignment ownership and firm-purchase ownership is denied
- returned consignment stock may regularize into `CP.fs`
- direct movement between supplier and customer is denied

These rules are deliberately small. The point of the fixture is to demonstrate how to shape a space, not to exhaust every commerce rule.

## Implemented Flows

The current example documents these flows:

### Receive Purchase Order

Cascade: `receive-po`

- move `sup.{own}.{from-state}` to `{loc}.{own}.{from-state}`
- if requested quantity exceeds available supplier quantity, create the overflow in `{loc}.{own}.fs`

This is used to model reception and reception reversal with `reverseIf(...)`.

### Reserve

Cascade: `reserve`

- move `*.fs` to `*.res`
- prefer warehouse stock before supplier stock
- prefer `FP`, then `CP`, then `CS`

This is intentionally modeled as a late-stage hold, right before payment gateway engagement, not as a casual cart-add action.

### Release

Cascade: `release`

- move `*.res` back to `*.fs`

This models reservation timeout or payment failure.

### Book

Cascade: `book`

- move `*.res` to `*.sd`

This models the conversion of a confirmed reservation into sold stock.

### Discard Defective

Cascade: `discard`

- destroy `*.def`

This uses the `nil` sink through `Cascade::destroy(...)`.

## Intentionally Omitted For Now

The example currently does not implement most post-booking flows. In particular, it leaves dispatch, delivery, returns, and regularization out of the active API surface.

That is deliberate. A smaller example that is coherent is better than a larger one with half-modeled flows.

It is also deliberate because SlotFlow itself should not accumulate an ever-growing catalog of commerce workflows. That richer domain layer belongs in a separate package.

## Row Shape

The fixture ingests rows with this shape:

```php
[
    'var' => 'A',
    'mvQtty' => 2,
    'loc' => 'wh1',
    'own' => 'CS',
    'ifs' => 3,
    'inv' => [
        'fs' => 5,
        'res' => 1,
        'sd' => 0,
    ],
]
```

Notes:

- `var` is the commerce-facing subject identifier
- `mvQtty` is the requested movement quantity for that variant
- `inv` may contain quantities for multiple states in one row
- `ifs` is ingested as inventory-side slot metadata

## About `ifs`

`ifs` means "initial for-sale" quantity.

In the legacy consignment model, it represented the maximum supplier consignment quantity that could legitimately be restored to `sup.CS.fs` during cancellations. That matters when sold stock may have been allocated from a mix of supplier and warehouse sources.

In SlotFlow, `ifs` is not modeled as canonical slot metadata. It is ingested as inventory-side slot attributes, because it is dynamic per inventory instance, not a permanent property of the slot definition.

That metadata is then available to policies through `CascadeContext::slotAttribute(...)`.

## Reservation Timing

The fixture intentionally assumes:

- cart add does not create a hard stock reservation
- `reserve` happens only right before payment is engaged

That choice keeps delivery promises and booking allocation aligned. It avoids the common problem where low-intent cart activity blocks the best warehouse stock from real buyers.

## Relationship To The Generic API

The commerce fixture is just one translation layer over the generic engine:

- `variant` maps to core `subject`
- row ingestion maps database-ish rows into `InventoryBatch`
- named commerce flows map to `Cascade` definitions

If you understand this fixture, you understand the intended way to embed SlotFlow into an application.

Just do not read it as "the product." It is an example of one possible product layer.
