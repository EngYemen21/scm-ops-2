# W3 — Picking, packing, loading, dispatch · workstream report

Database `scm_ops_test_master`. Last run of the workstream's tests: **28 passed, 0 failed** (6 files, see Tests).
Client: `node tools/check-vue.mjs resources/js/pages/fulfillment` → OK, 12 files. `vendor/bin/pint` → passed on every
PHP file of the workstream.
**Not done by design of §0:** no `npm run build`, no server — the changed screens were compiled and import-checked
only, they were NOT opened in a browser (NEEDS ARCHITECT 7).

## Items

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Short pick | DONE (one deliberate deviation) | Structured reason, `fo_lines.short_qty`, reservation / SO-line counters lowered, frozen row, backorder or cancellation per policy, another system's order told line by line, an order with nothing picked is never shipped empty. **Deviation:** `BackorderService::split` / `cancel` runs ONCE, when the pick list closes, with every short line — not at each short pick (see "What the rules are now" 1 and Assumption 1). |
| 2 | Cartons | DONE | One `cartons` row per carton at pack, optional per-carton weights, labels "n / N" from the FO page / packing row / right after packing; `loaded` at load, back to `packed` at unload, delivered / returned derived on read. The list is served as **`cartonList`** — `cartons` already is (and stays) the carton COUNT of the order (NEEDS ARCHITECT 3). |
| 3 | Unpack and put-back | DONE | `POST …/unpack`, `POST …/putback` (+ `bins` for an unusable bin, `cancelOrder`), buttons with reason dialogs. |
| 4 | Batch scan, expiry by date, batch on the card | DONE | `BATCH_SCAN_REQUIRED` / `WRONG_BATCH`; `Carbon::today()`; `pickLists.tasks.batch` loaded; the card shows the batch field only when the bin holds several batches. |
| 5 | Picker assignment | DONE | claim / release / assign, `PICK_ASSIGNED`, first confirmed scan claims, `?mine=1` on pick lists and on orders, tab shows the holder, «قوائمي», buttons; the tab polls (20 s). |
| 6 | Edit a packed order | DONE | `PATCH …/package`; cartons re-issued when the count changes; trip totals recomputed, capacity warning. |
| 7 | Batch substitution | DONE | `POST …/substitute`; reserves again FEFO away from the reported row (for `expired`: away from the whole batch); the rest → short-pick path. |
| 8 | Outbound door / lane | DONE | `POST …/dock`, plan returns it, dispatch page sets it, staging tab reads STG-OUT as lanes (trip → door → orders). |
| 9 | Unload | DONE | `POST …/unload`; movement `unload` at the load cost; trip back to planning and vehicle `loading → assigned` when the truck is empty. |
| 10 | Loading sequence | DONE | `LOAD_WAIT` under `loading.enforceSequence`, `overrideSequence` + reason for `load.override`, real `load_seq` in the plan. |
| 11 | Scans at loading | DONE | `foNumber` / `scannedOrder` resolved with `ScanCodes`; `SCAN_REQUIRED` under `loading.requireOrderScan`, override = reason from `load.override`. |
| 12 | Dispatch dialog and buttons | DONE | One rule, `LoadingService::dispatchState`, used by the endpoint and published in the loading plan; the dialog counts packed-not-loaded (blocks) and not-packed (leave) apart; load / dispatch buttons disabled with the server's reason. W2's `TripsService::dispatchGate` already defers to `dispatchState` (the Trip Room says what the endpoint does). |
| 13 | Zone headers (wave picking not built) | DONE | Tasks are shown under a header per zone (code · name · temperature class) on the pick tab and the FO page. |

## What the rules are now

### 1 Short pick — `POST /fulfillment/pick-tasks/{id}/short`

- Body `{reason: notfound | damaged | expired | other, note?}` (`note` required with `other`). The old free-text
  `reason` is refused (`INVALID_INPUT`).
- In one transaction (locks: sales order → fulfilment order → pick list → task): the task closes `short` (stamped
  `picked_by` / `picked_at`, `short_reason`, `note`); the unpicked units leave the reservation — balance row
  (`InventoryService::unreserve`), allocation (released, or closed at what was picked), `inventory_reservations.qty`,
  SO line `reserved_qty` / `allocated_qty`; the FO line ships less (`qty ↓`, `short_qty ↑`); the sales order gets
  `has_shortage` at once. An order that was still `alloc` moves to `picking`.
- **Frozen row.** Reason `notfound` + `fulfillment.blockBinOnShortPick`: `InventoryService::setBlocked([row], true)`,
  audit `INVENTORY.FREEZE`; the `missing` exception (wm) says so. The row is not allocatable until a count releases it
  (CountsService unblocks on post / cancel). `damaged` / `expired` do not freeze: they open a second exception for the
  inventory controller (`ownerRole inv`, kind `damaged` / `other`).
- **When the pick list closes** (no open / partial task — by this short pick or by a later normal pick):
  - something was picked → FO `picked`. Our order (`source_system` null): ONE
    `BackorderService::split($so, [soLineId => short_qty …], 'short_pick')` under `fulfillment.backorderShortPicks`,
    else ONE `BackorderService::cancel(…)`. The response of the call that closed the list carries `backorder`.
    Another system's order: nothing is split; `picking.completed` carries `shortPicked: true` and
    `lines: [{sku, ordered, picked, short}]`.
  - nothing was picked → **no shipment**: FO `cancelled`, pick list `cancelled`, the order leaves its trip (stop
    skipped, totals recomputed), reservations released, SO line counters reset; the sales order goes back to
    `confirmed` / `fulfil_status = backordered` (lines untouched — `allocate` / the integration top-up reserve it again),
    or — our order under the cancel policy — is `cancelled` and `CreditService::sync` runs. Another system's order
    always waits (cancelling it is that system's decision).
- Another system's order additionally gets `order.short` `{fulfilmentOrder, reason, lines[, fulfilmentCancelled]}` at
  every short pick.

### 2 Cartons

`pack` body: `{cartons?, weightKg?, volumeM3?, cartonWeights?: number[]}`. One row per carton
(`{FO}-C01 …`, at least two digits), weight = the given one or total ÷ n; with `cartonWeights` the order weight is
their sum. 1 … 2000 cartons. The response and `GET /fulfillment/orders/{id}` serve `cartonList:
[{code, seq, weightKg, status, loadedAt, deliveredAt}]`. Status: `packed` / `loaded` written by load and unload; after
dispatch read from the order — `delivered` (delivered), `returned` (failed), and on a partial delivery `delivered` when
the carton's code is among the POD `scans` (all delivered when nothing was scanned). A void carton gives its code
back (`{code}~{id tail}`), so re-packing prints C01 … again and an old label of a carton that no longer exists resolves
to nothing. The packing activity line no longer claims a printed slip; the client opens the label dialog after packing.

### 3 Unpack, put-back, package edit

- `unpack` (`pack.confirm`; packed, not loaded): STG-OUT → PACK per batch (`move`), package `status = void`
  (`voided_at / voided_by / void_reason`), cartons void, staging entry cleared, FO + SO → `picked`.
- `putback` (`pick.override`; `picking | picked`): each picked quantity PACK → its own bin (`restock`), `locCheck` on
  the bin — when it fails `BIN_UNUSABLE` names the task (`details {taskId, sku, bin, qty}`) and `bins: {taskId: code}`
  gives another bin; tasks cancelled (a `short` task keeps saying so), list + FO cancelled, FO leaves its trip,
  `InventoryService::release`, SO line counters reset; SO `cancelled` (+ credit sync) with `cancelOrder`, else
  `confirmed` / backordered. `cancelOrder` on another system's order → `PUTBACK_EXTERNAL`.
- `PATCH …/package` (`pack.confirm`; packed, not loaded): package + FO + cartons; `cartonsReissued` when the count
  changed (also issues rows for an order packed before cartons existed); trip `kg / cbm / pallets` and the stop's `kg`
  recomputed; `warning` when the trip now exceeds its vehicle.

### 4–5 Picking

- `confirm` accepts `scannedBatch`. Required when the task has a batch and the bin holds stock of more than one batch
  of the product; whenever sent it must equal the task's batch. Tasks of an order carry `batchScan` (computed on read).
- Lists: `claim` (free list; idempotent for the holder), `release` (holder or `pick.override`), `assign {username}`
  (`pick.override`; the user must be active and hold `pick.confirm`). `confirm` / `short` / `substitute` on a list held
  by someone else → `PICK_ASSIGNED` (409) unless `pick.override` (a supervisor scans without taking the list); the
  first confirmed scan on a free list takes it (a rejected scan does not).

### 7 Substitution — `POST /fulfillment/pick-tasks/{id}/substitute`

The unpicked quantity is released from its row, then as much as exists elsewhere is reserved again
(`reserve(... excludeRows)`) and becomes new tasks (`substituted = true`) at the end of the same list. The original
task: `cancelled` (nothing picked) or `done` at what was picked; when only part could be re-reserved it stays `short`
for the rest, which goes the way of item 1. Freeze / exceptions as in item 1.

### 8–12 Loading and dispatch

- **Plan** `GET /fulfillment/trips/{trip}/loading`: rows gain `loadSeq` (real, null until loaded), `so`, `waitFor`
  (later stops not packed yet — policy), `loadFirst` (later packed stops); plus `dock {code, options[{code, busyBy}]}`,
  `loadGate {code, reasonAr, reasonEn} | null`, `dispatch {allowed, code, reasonAr, reasonEn, loaded[],
  packedNotLoaded[], notPacked[]}`, `policy {enforceSequence, requireOrderScan}`, `trip.dockCode`, `trip.warehouse`.
  A skipped stop / cancelled order is no longer a row. `nextToLoad` = the first order that may really go in.
- **Load**: `foNumber` and `scannedOrder` are any code of the order (FO, SO or its QR link, package, `LBL-…`, carton).
  Order of checks: trip / vehicle → order on trip → loaded / packed → vehicle scan → order scan → vehicle state →
  sequence → capacity → temperature → door. Sequence: later stops still `alloc | picking | picked` → `LOAD_WAIT`
  (policy; `details {waitFor, loadFirst}`); later stops packed but not loaded → `LOAD_ORDER` (always, as before).
  `overrideSequence: true` + `reason` (`load.override`) passes both. No `scannedOrder` under the policy →
  `SCAN_REQUIRED`, unless the caller holds `load.override` and gives `reason`. Overrides are audited
  (`LOAD.OVERRIDE`, field `scan` / `sequence`) and announced. A re-loaded order re-uses its loading line; `load_seq`
  = highest loaded sequence + 1.
- **Unload** (`load.confirm`; trip `loading | ready`): reverse of load, per pick-task batch, `unitCost` = the
  `unit_cost` of the last `load` movement of that order / product / batch.
- **Door**: any dock of the trip's warehouse (`D1 … Dn`, as the warehouse master lists them); `DOCK_BUSY` when another
  trip that is `loading | ready` holds it — at `dock` and when a trip with a door starts loading.
- **Dispatch**: unchanged contract; the driver check reads documents by date and includes the iqama (as W2's gate).
- `GET /fulfillment/staging?warehouse=` → `{groups: [{trip | null, orders[]}], total}`.

## Endpoints

| Method · path (under `/api/fulfillment`) | Body | Permission | Error codes |
|---|---|---|---|
| GET `orders` (changed) | query `mine=1` | — | — |
| GET `orders/{id}` (changed) | — adds `cartonList`, `stop`, tasks `batch / batchScan / shortReason / substituted / note`, lines `shortQty`, packages `status …`, `trip.dockCode`, zone `nameAr / nameEn / type` | — | `FO_NOT_FOUND` |
| GET `pick-lists` (changed) | query `mine=1` | — | — |
| POST `pick-lists/{id}/claim` | — | `pick.confirm` | `PICK_LIST_NOT_FOUND` 404, `PICK_LIST_CLOSED` 422, `PICK_ASSIGNED` 409 |
| POST `pick-lists/{id}/release` | — | `pick.confirm` (holder, or `pick.override`) | `PICK_LIST_NOT_FOUND`, `PICK_ASSIGNED` 409 |
| POST `pick-lists/{id}/assign` | `{username}` | `pick.override` | `USER_NOT_FOUND` 404, `USER_CANNOT_PICK` 422, `PICK_LIST_CLOSED` 422 |
| POST `pick-tasks/{id}/confirm` (changed) | `+ scannedBatch?` | `pick.confirm` | new: `BATCH_SCAN_REQUIRED` 422, `WRONG_BATCH` 422, `PICK_ASSIGNED` 409, `TASK_CLOSED` 409 |
| POST `pick-tasks/{id}/short` (changed) | `{reason, note?}` | `pick.confirm` | `INVALID_INPUT` 400, `TASK_NOT_FOUND` 404, `TASK_CLOSED` 409, `FO_STATE` 422, `PICK_ASSIGNED` 409 |
| POST `pick-tasks/{id}/substitute` | `{reason, note?}` | `pick.confirm` | same as short |
| POST `orders/{id}/putback` | `{reason, cancelOrder?, bins?}` | `pick.override` | `FO_NOT_FOUND`, `PUTBACK_STATE` 422, `PUTBACK_EXTERNAL` 422, `BIN_UNUSABLE` 422, `BIN_NOT_FOUND` 404 |
| POST `orders/{id}/pack` (changed) | `+ cartonWeights?` → `+ cartons, cartonList` | `pack.confirm` | new: `CARTON_WEIGHTS` 400, `NOTHING_PICKED` 422, `BAD_CARTONS` 400 |
| POST `orders/{id}/unpack` | `{reason}` | `pack.confirm` | `FO_NOT_FOUND`, `UNPACK_STATE` 422 |
| PATCH `orders/{id}/package` | `{cartons?, weightKg?, volumeM3?, cartonWeights?}` | `pack.confirm` | `FO_NOT_FOUND`, `PACKAGE_STATE` 422, `PACKAGE_NOT_FOUND` 404, `NO_CHANGES` 400, `CARTON_WEIGHTS` 400 |
| GET `staging` | query `warehouse` | — | — |
| GET `trips/{trip}/loading` (changed) | see above | — | `TRIP_NOT_FOUND` |
| POST `trips/{trip}/dock` | `{dockCode | null}` | `load.confirm` | `TRIP_NOT_FOUND`, `TRIP_STATE`, `DOCK_NOT_FOUND` 422, `DOCK_BUSY` 422 |
| POST `trips/{trip}/load` (changed) | `{foNumber, scannedVehicle?, scannedOrder?, overrideSequence?, reason?}` → `+ overrides[]` | `load.confirm` (+ `load.override` for overrides) | new: `SCAN_REQUIRED` 422, `LOAD_WAIT` 422, `OVERRIDE_FORBIDDEN` 403, `OVERRIDE_REASON` 400, `DOCK_BUSY` 422 |
| POST `trips/{trip}/unload` | `{foNumber, reason}` | `load.confirm` | `TRIP_STATE` 422, `FO_NOT_ON_TRIP` 422, `NOT_LOADED` 422 |
| POST `trips/{trip}/dispatch` | — | `trip.dispatch` | unchanged |

Events for an order of another system: `picking.completed` (+ `shortPicked`, `lines`), new `order.short`,
`order.unpacked`, `order.unloaded`, `order.putback`. Audit actions: `PICK.CLAIM / RELEASE / ASSIGN / SHORT /
SUBSTITUTE / PUTBACK`, `INVENTORY.FREEZE`, `PACK.UNPACK`, `PACK.EDIT`, `LOAD.OVERRIDE`, `LOAD.UNLOAD`, `TRIP.DOCK`,
`TRIP.REMOVE_ORDER`.

## Files

- New: `app/Services/Fulfillment/{PickingService,PackingService,LoadingService,FulfillmentSupport}.php`,
  `database/migrations/2026_11_15_000030_w3_extra.php`, `resources/js/pages/fulfillment/FoActions.vue`,
  `tests/Feature/Fulfillment/{FulfillmentFixtures,ShortPickTest,PickingTest,PackingTest,LoadingTest}.php`.
- Changed: `app/Services/Fulfillment/FulfillmentService.php` (now the documents only: list / get),
  `app/Http/Controllers/Api/Fulfillment/FulfillmentController.php`, `routes/api/fulfillment.php`,
  `app/Models/Package.php` (cast `voided_at`), client `DispatchPage`, `FoPage`, `LoadingRow`, `PackRow`, `PackTab`,
  `PickTab`, `PickTaskCard`, `PickingPage`, `StagingTab`, `shared.js`,
  `tests/Feature/Sales/{FulfillmentFlowTest,FulfillmentNegativesTest}.php`.
- Not touched: `ScanCodes.php`, `SalesFixtures.php`, every foundation / other-workstream file.

## Tests

`DB_DATABASE=scm_ops_test_master php artisan test tests/Feature/Sales/FulfillmentFlowTest.php
tests/Feature/Sales/FulfillmentNegativesTest.php tests/Feature/Fulfillment` → **28 tests passed**.

| File | Tests | Covers |
|---|---|---|
| `Sales/FulfillmentFlowTest` (rewritten) | 5 | the story: scans, batch, partial, short 6/10 → backorder + frozen bin, cartons at pack, plan, load rules and scans, dispatch state = endpoint, overrides |
| `Sales/FulfillmentNegativesTest` (updated) | 3 | temperature, expiry by date (last day pickable), quarantine |
| `Fulfillment/ShortPickTest` | 6 | short first / closing pick → one backorder; cancel policy; freeze policy off; nothing picked (wait / cancel); another system's order (events, never split, never cancelled) |
| `Fulfillment/PickingTest` | 5 | claim / release / assign / `PICK_ASSIGNED` / `mine`; batch scan; substitution (full, partial, none, expired batch) |
| `Fulfillment/PackingTest` | 5 | carton weights, package edit + trip totals, unpack + re-pack, put-back (back to waiting, other bin, cancel, off its trip, another system) |
| `Fulfillment/LoadingTest` | 4 | `LOAD_WAIT` + override + policy off; any code of the order + scan policy off; unload (cost, trip / vehicle state, re-load); door + `DOCK_BUSY` + staging lanes |

Tests that pinned the OLD behaviour and were rewritten (`FulfillmentFlowTest`): short pick (free-text reason, reserved 0
/ availability 34 / no backorder → structured reason, frozen row, backorder order, SO line 6 + 4 short); trip stop order
(the never-picked order is now the FIRST stop, a later one would hold the others — `LoadingTest`); `nextToLoad`;
loads without a scan (now `SCAN_REQUIRED`, or a scan by FO / carton / SO link, or a supervisor override); `trip` shape
(`dockCode`). `reconcile()['ok']` is asserted after every stock change.

## NEEDS ARCHITECT

1. **Extra migration** `2026_11_15_000030_w3_extra.php` (additive): `packages.status` (default `packed`),
   `voided_at`, `voided_by`, `void_reason`; `pick_tasks.short_reason`. `app/Models/Package.php` got the `voided_at` cast.
2. **Other suites that load without the order scan** (now `SCAN_REQUIRED`) — add `'scannedOrder' => <FO number>`:
   `tests/Feature/Integration/ControlTowerTest.php:228, :264`, `tests/Feature/Integration/OrderJourneyTest.php:119`,
   `tools/e2e-sales-ops.mjs:143, :201`. (`AcceptanceTest:189`, `FinanceFlowTest:167` already send it.) Any suite
   that short-picks must send `reason: notfound|damaged|expired|other`; any pick from a bin holding two batches of the
   product must send `scannedBatch`; a trip whose LATER stop is not packed needs that stop first (or an override).
3. **`cartons` vs `cartonList`.** §W3.2 says the order document returns `cartons`; that key is the existing integer
   count (used by the client, trips, the tests). The list is `cartonList`. Likewise the model relations
   `FulfillmentOrder::cartons()` / `Package::cartons()` collide with the `cartons` column: they must never be
   eager-loaded (`toArray()` would replace the count) — the services query `Carton` directly.
4. **Sales platform (b2b-platform).** New event types for its orders: `order.short`, `order.unpacked`,
   `order.unloaded`, `order.putback` (each carries the current `status`), and `picking.completed` may carry
   `shortPicked` + `lines`. They match the `order.*` / `picking.*` subscription; the consumer must map them.
5. **Dispatch rule.** `LoadingService::dispatchState(Trip)` returns `{allowed, kind, code, reasonAr, reasonEn, loaded,
   packedNotLoaded, notPacked}`; W2's `TripsService::dispatchGate` reads it through `method_exists` — keep the
   signature. `dispatchState` now reads driver documents by date and includes `iqama_expiry` (same as the gate).
6. **Frozen rows.** A row frozen by a "not found" pick is released only by a count. If the inventory controller needs
   a direct "unfreeze" (stock found after all), that action belongs to W6 / inventory.
7. **Browser pass.** Open: Pick & Pack (take a list, a task in a two-batch bin, short / substitute dialogs, pack →
   label dialog, packed row: edit / unpack, staging lanes), FO page (cartons, put-back), Load & Dispatch (door select,
   scan a carton, override as supervisor, unload, the dispatch dialog with a not-packed order).
8. `SettingsService` descriptions are fine; nothing else needed in foundation files.

## Assumptions the business should confirm

1. **One backorder order per shipment.** The shortage of an order becomes ONE backorder order (or one cancellation)
   when its pick list closes, not one per short line the moment it is reported. Until then the order shows
   `has_shortage` and Sales is told by an activity line. Reason: three short lines would otherwise be three backorder
   orders and three later shipments; and an order of which nothing could be picked keeps its lines and simply waits.
2. An order of which nothing was picked: our order waits again (or is cancelled under the cancel policy); **another
   system's order always waits** — OPS never cancels it.
3. Only "not found" freezes the stock row. "Damaged" / "expired" stock stays allocatable until the inventory
   controller moves it (he gets an exception) — per the specification.
4. A substitution for an **expired** batch avoids that batch in every bin, not only in the reported one.
5. Loading without a scan: a holder of `load.override` gives a reason and no scan — there is no separate flag.
   `overrideSequence` lifts both sequence rules (`LOAD_WAIT` and the older `LOAD_ORDER`).
6. Doors have no type in the master data: any dock of the warehouse can be a loading door. Two planned trips may name
   the same door; the one that starts loading holds it until it leaves.
7. The packer types the real number of cartons (the field no longer starts at the order's estimate); at most 2000;
   labels print in jobs of 300.
8. Carton codes are re-used after a re-pack / a changed count (old labels must be destroyed — the dialog says so).
9. After dispatch a carton's `delivered` / `returned` is read from its order (per carton only when the driver scanned
   cartons on a partial delivery).
10. A supervisor who scans on a picker's list does not take the list from him.
11. A driver's licence / iqama is valid through its last day at dispatch (it used to be refused from 00:00 of that day).
