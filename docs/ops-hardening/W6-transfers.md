# W6 — Transfers and stock moves · workstream report

Database `scm_ops_test_inventory`. Last run of the workstream's tests: **27 passed, 0 failed** (4 files, see Tests).
Client: `node tools/check-vue.mjs resources/js/pages/inventory resources/js/pages/returns` → OK, 37 files.
**Not done by design of §0:** no `npm run build`, no server — the changed screens were compiled and import-checked only,
they were NOT opened in a browser (see NEEDS ARCHITECT 1).

## Items

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Reservation on approval | DONE | `approve` holds every line in its source bin; a line that cannot be covered refuses the approval naming it; `cancel` / `reject` release; `ship` moves each hold with `postHold` and stores the movement's cost on the line |
| 2 | Receipts + variance | DONE | `receive` in `transit` and `partial`, accumulating, valued at the ship cost; `resolve-variance` (loss journal / returned to source); `close` refused while a remainder is open; the receipt exception is resolved by the resolution (or by the rest arriving) |
| 3 | Destination bin + arrow | DONE | `toBin` optional at creation, accepted and checked at receipt, suggested by the server; list shows `from → to` in an LTR box; stored move reference is `MOVE · from → to` |
| 4 | Carrier on the transfer | DONE | `ship` takes `{carrier, waybill, vehicleCode, driverCode}` (+ `tripId` at service level), policy `transfers.requireCarrier` → `CARRIER_REQUIRED`; shown on the transfer page. The trip link itself is the architect's |
| 5 | Back door `/inventory/move` | DONE | a source in PACK / STG-OUT / STG-IN / RET-01 is refused with `MOVE_FROM_STAGING`, the message names the way out |

Also done (from the audit facts): the in-transit figure of the transfers list / detail (`inTransitQty`) counts `partial`
transfers; the two other figures are not in my files (NEEDS ARCHITECT 2).

## What the rules are now

**Holds.** A request (`requested`) reserves nothing. `approve` calls `InventoryService::hold` per line (owner
`WarehouseTransfer`, `owner_line_id` = the line): `reserved` / `allocated` go up on the source row, so a sales order, a
manual move, another transfer's request or approval cannot take the quantity. All-or-nothing: one line short →
`INSUFFICIENT_AVAILABLE` with `details {line, sku, bin, available, requested, reason: short | quarantine | blocked}` and
nothing is held; the transfer stays `requested` (reject it, or fix the stock and approve again). `cancel` (any state
before shipping) and `reject` release the holds (`TRANSFER.RELEASE` audit). `picking` / `ready` are status steps.

**Ship.** Every hold leaves through `postHold` as a `transit` movement; `transfer_lines.unit_cost` = the movement's unit
cost. A row quarantined or frozen by a count AFTER the approval does not ship (`SOURCE_QUARANTINED` / `ROW_BLOCKED`,
naming line and bin; the hold stays; way out = lift the flag or cancel). A transfer approved before this release has no
hold: shipping holds it first, with the same refusal as an approval.

**Receive.** Allowed in `transit` and `partial`. `receivedQty` = what arrives NOW (≤ what is still on the road); a line
that is not listed is received in full (its remainder); `received_qty` accumulates. Every arrival is a `trf` movement
with `unitCost` = the line's ship cost (for transfers shipped before the column existed: the cost of their `transit`
movement). Status `partial` while anything is on the road, `received` when nothing is. The FIRST short receipt raises
one exception (kind `transfer`, owner `wm`); later short receipts do not raise another one; it is resolved automatically
when the rest arrives. A receipt of zero is allowed in `transit` (it records "nothing arrived" and opens the variance),
refused in `partial` (`RECEIVE_NOTHING`).

**Variance.** `partial` cannot close (`TRANSFER_VARIANCE_OPEN`, `details {openQty}`); `GET` no longer offers `done` in
`allowed` for `partial`. `resolve-variance`:
- `loss` — the remainder becomes `variance_qty`; ONE journal `trf-variance:{transferId}` Dr `inventory_loss` /
  Cr `inventory_in_transit`, a pair of lines per transfer line (product, warehouse = source, cost centre of the source
  warehouse), amount = remaining × ship cost; stored in `variance_journal_id`. Goods that carry no cost post nothing.
- `returned` — the remainder is received back into the SOURCE warehouse as a `trf` movement at the ship cost, into the
  bin it left unless `lines[].toBin` names another one (location-checked, active); `variance_qty` records it.
Then status `received` (→ `close` → `done`), `variance_resolution` / `variance_note` stored, the exception resolved,
audit `TRANSFER.VARIANCE`, activity to `wm` (+ `finance` for a loss).

**Finance invariant.** After a transfer is fully received or its variance resolved, Σ(debit − credit) on the in-transit
account over the journals whose source is the transfer is 0.00 — asserted in the tests through those journals.
Arrivals are valued line by line, so several receipts of a 4-decimal cost can leave a cent of rounding
(3 × 0.3333 = 1.00 shipped, 0.33 + 0.33 + 0.33 received): when the transfer completes, what is left is moved to the
destination warehouse's inventory account in one clearing journal `trf-clear:{transferId}`. No journal when nothing is left.

**Destination bin.** Optional at creation (checked when given). At receipt the bin is the one named in the call, else
the line's own; neither → `TO_BIN_REQUIRED`. It must exist in the destination warehouse (`BIN_NOT_FOUND`), be active
and fit the product NOW (`LOC_RULE`, naming the line). The line keeps the last bin used. `GET` carries
`lines[].suggestedToBin {code, reasonAr, reasonEn}` while goods are on the road: the bin already holding the product,
else the first free bin of its storage class.

**Move guard.** `POST /inventory/move` with `fromBin` ∈ PACK / STG-OUT / STG-IN / RET-01 → `MOVE_FROM_STAGING`
(checked right after the lookups, whatever the reason or the stock), message: unpack / put back / putaway task / return decision.

## Endpoints

| Method · path | Body | Permission | Error codes |
|---|---|---|---|
| `POST /inventory/transfers` (changed) | `lines[].toBin` is now optional | `inventory.transfer` | unchanged (`LOC_RULE` only when a bin is given) |
| `POST /inventory/transfers/{id}/approve` (changed) | `{note?}` | `inventory.transfer.approve` | + `INSUFFICIENT_AVAILABLE` (422, details above) |
| `POST /inventory/transfers/{id}/cancel`, `/reject` (changed) | `{note?}` | as before | unchanged; now release the holds |
| `POST /inventory/transfers/{id}/ship` (own route now) | `{note?, carrier?, waybill?, vehicleCode?, driverCode?}` — all optional | `inventory.transfer` | `TRANSFER_TRANSITION`, `CARRIER_REQUIRED` (422), `VEHICLE_NOT_FOUND` / `DRIVER_NOT_FOUND` (404), `ROW_BLOCKED`, `SOURCE_QUARANTINED`, `INSUFFICIENT_AVAILABLE` (422), `INVALID_INPUT` (400) |
| `POST /inventory/transfers/{id}/receive` (changed) | `{lines?: [{lineNo, receivedQty, toBin?}], note?}` | `inventory.transfer` | `TRANSFER_TRANSITION` (not transit / partial), `BAD_LINE`, `RECEIVE_EXCEEDS` (400, details `{line, remaining, shipped}`), `TO_BIN_REQUIRED`, `LOC_RULE`, `RECEIVE_NOTHING` (422), `BIN_NOT_FOUND` (404) |
| `POST /inventory/transfers/{id}/resolve-variance` (new) | `{resolution: loss \| returned, note (3–500), lines?: [{lineNo, toBin?}]}` | `inventory.transfer.approve` | `INVALID_INPUT`, `BAD_LINE` (400), `TRANSFER_NO_VARIANCE`, `LOC_RULE`, `PERIOD_CLOSED` (422), `BIN_NOT_FOUND` (404) |
| `POST /inventory/transfers/{id}/close` (changed) | — | `inventory.transfer` | + `TRANSFER_VARIANCE_OPEN` (422) |
| `GET /inventory/transfers`, `/{id}` (changed) | — | open | new fields below |
| `POST /inventory/move` (changed) | unchanged | `inventory.move` | + `MOVE_FROM_STAGING` (422, details `{bin}`) |

New response fields. Transfer: `carrier`, `waybill`, `vehicle {id, code, plateAr, plateEn}`, `driver {id, code, nameAr,
nameEn}`, `trip {id, number, status}`, `tripId / vehicleId / driverId`, `varianceResolution / varianceNote /
varianceJournalId`, `varianceQty`, `inTransitQty` (transit + partial). Lines: `varianceQty`, `remainingQty`, and
`unitCost` ONLY for users with `inventory.view_cost`. Detail only: `heldQty` (+ per line), `lines[].suggestedToBin`,
`variance {openQty, qty, resolution, note, journal {id, number}, openValue*, lossValue*}` (* with `inventory.view_cost`),
`policy {requireCarrier}`, `exceptions[].textEn`. `receive` answers `+ {partial, exception, receivedNow, remainingQty}`;
`resolve-variance` answers `+ {resolution, varianceQty, journal}`.

Service: `TransfersService::ship(AuthUser $user, string $id, ?string $note = null, array $opts = [])` with
`$opts = carrier, waybill, vehicleCode, driverCode, tripId`. `tripId` (id or number) sets `trip_id` and takes the trip's
vehicle and driver unless they are named; unknown → `TRIP_NOT_FOUND`. It is NOT accepted over HTTP yet, on purpose.

## Files

Server: `app/Services/Inventory/TransfersService.php` (rewritten), `app/Services/Inventory/InventoryOpsService.php`
(move guard + reference arrow only), `app/Http/Controllers/Api/Inventory/TransfersController.php`,
`routes/api/inventory.php` (two transfer routes added before the generic action route).

Client: `resources/js/pages/inventory/TransferPage.vue`, new `TransferShipModal.vue` and `TransferVarianceModal.vue`
beside it, `resources/js/pages/inventory/MoveForm.vue` (refusal message only),
`resources/js/pages/returns/{TransfersTab,TransferForm,TransferLinesPicker}.vue`. No shared component, no `shared.js` change.

Tests: `tests/Feature/Inventory/TransfersTest.php`, `InventoryOpsTest.php`, new `TransferShippingTest.php`,
`TransferReceiptsTest.php`; `InventoryFixtures.php` (+ `stockAtCost`, `inTransitNet` helpers).

## Tests

`DB_DATABASE=scm_ops_test_inventory php artisan test tests/Feature/Inventory/TransfersTest.php
tests/Feature/Inventory/TransferShippingTest.php tests/Feature/Inventory/TransferReceiptsTest.php
tests/Feature/Inventory/InventoryOpsTest.php` → 27 tests passed. `CountsTest.php` (uses the fixture trait I added
helpers to) was run once with them: 30 tests, 1049 assertions, all green. `reconcile()['ok']` is asserted after every
stock move; the in-transit invariant in every scenario that completes a transfer.

- `TransfersTest` (5, existing file). **Rewritten to the new rule:**
  `test_partial_receive_raises_a_transfer_exception` → `…_and_cannot_close_before_the_variance_is_resolved` (it pinned
  "second receive refused, `allowed == ['done']`, close with the variance open"; now: the remainder stays receivable,
  `RECEIVE_EXCEEDS` past it, `allowed == ['partial','received']`, close → `TRANSFER_VARIANCE_OPEN`, loss journal, exception
  resolved, then close). In `test_reject_cancel_and_stock_that_disappears_before_shipping` the ship of a row quarantined
  after approval now answers `SOURCE_QUARANTINED` (was `INSUFFICIENT_AVAILABLE`) and cancel is asserted to release the
  hold. The full-flow test additionally asserts hold on approval, cost on the line, cost hidden from `wm`, in-transit = 0.
- `TransferShippingTest` (6, new): approval holds / refuses naming the line / held stock out of reach of move and new
  requests / cancel frees it; approval refused for a quarantined or frozen row; ship from the hold with carrier fields,
  `ROW_BLOCKED`, `SOURCE_QUARANTINED`, `VEHICLE_NOT_FOUND`, `DRIVER_NOT_FOUND`, `TRANSFER_SHIPPED`; policy
  `transfers.requireCarrier` (`CARRIER_REQUIRED` ×4, carrier alone OK, vehicle + driver OK); `tripId` at service level
  (`TRIP_NOT_FOUND`, vehicle / driver taken from the trip); transfer approved before holds existed.
- `TransferReceiptsTest` (6, new): bin optional at creation / `TO_BIN_REQUIRED` / `LOC_RULE` (staging, blocked bin) /
  `BIN_NOT_FOUND` / suggestion / three receipts into two bins / one exception / `RECEIVE_NOTHING` / `RECEIVE_EXCEEDS`;
  receipts at the ship cost after the average changed; rounding cleared; `returned` (validation, `BAD_LINE`, default bin,
  `TRANSFER_NO_VARIANCE` in transit); nothing arrived → returned to another bin when the original one is blocked; goods
  without cost written off with no journal.
- `InventoryOpsTest` (10; 1 new): `MOVE_FROM_STAGING` for each of the four bins (any reason, with or without stock, no
  ledger row), ordinary move still works and stores `MOVE · from → to`.

## NEEDS ARCHITECT

1. **Build + browser pass.** `npm run build`, then open `/trf/:number` in each state (requested → approve, ready → ship
   dialog, transit → receive with bins, partial → receive the rest / resolve variance, received → close), `/returns?tab=trf`
   and the two move forms. I could only compile the SFCs.
2. **In-transit figures outside my files** still filter `status = 'transit'` and ignore `variance_qty`:
   - `app/Services/Inventory/InventoryQueryService.php:211-213` → `whereHas('transfer', fn ($q) => $q->whereIn('status', ['transit', 'partial']))` and `$qty = max(0, $l->qty - ($l->received_qty ?? 0) - $l->variance_qty)`;
   - `app/Services/Platform/DashboardService.php:349-351` → `WHERE t.status IN ('transit', 'partial')` and `SUM(l.qty - COALESCE(l.received_qty, 0) - l.variance_qty)`.
3. **Labels / state table (foundation).** `TRANSFER_LABELS.partial.act` is still «إقفال بالفروقات / Close with variance»
   in `config/scm.php` and `resources/js/shared/constants.json`; the page overrides it locally with «استلام ما وصل /
   Receive what arrived». Suggested: change the label there, and drop `done` from `TRANSFER_TRANSITIONS.partial`
   (the service refuses it and `allowed` hides it, so nothing breaks either way).
4. **Trip link.** Call `TransfersService::ship(..., ['tripId' => $trip->id])` or add `tripId` to
   `TransfersController::ship`; `warehouse_transfers.trip_id` and the `trip` object in the responses are already there
   and the page shows the trip as a link. Vehicle / driver eligibility (inactive, blocked, documents) is deliberately
   not judged by the transfer — only existence — so the trip's own rules stay the single place for it.
5. **Old data.** Transfers closed with a shortfall before this release keep it on account 1135 and nothing re-opens a
   `done` transfer: `SELECT t.number FROM warehouse_transfers t JOIN transfer_lines l ON l.transfer_id = t.id WHERE
   t.status = 'done' GROUP BY t.number HAVING SUM(l.qty) > SUM(COALESCE(l.received_qty, 0))` lists them for a manual
   journal. Transfers that are `partial` today simply get the new buttons (their cost falls back to the `transit` movement).
   Ledger rows written before today keep the old `MOVE · a ← b` text (the ledger is append-only).
6. **Docs.** `docs/finance/ARCHITECTURE.ar.md` §3.3 should gain: receipt of a transfer at the SHIP cost; variance loss
   (Dr 5210 / Cr 1135, key `trf-variance:`); clearing of the rounding remainder (Dr/Cr 1130 / 1135, key `trf-clear:`).
7. `resources/js/pages/master/MoveForm.vue` (not mine) also posts `/inventory/move`; it shows the server's
   `MOVE_FROM_STAGING` message in its banner — the inline reason I added exists only in `pages/inventory/MoveForm.vue`.

## Assumptions for the business to confirm

1. A transit **loss is charged to the SOURCE warehouse** (its cost centre), not to the destination.
2. The variance is decided as a whole: all of the remainder is either a loss or returned. A mixed outcome is done as
   `returned`, then the damaged part is scrapped at the source.
3. A variance can be resolved only after a receipt was recorded (a receipt of 0 when the truck came back full) and only
   by a user who may approve transfers (`inventory.transfer.approve`: warehouse manager, super). The reason is mandatory.
4. Stock quarantined or frozen by a count after the approval does not ship until the flag is lifted (or the transfer is
   cancelled) — the hold alone does not override a quality hold or a count.
5. The rounding remainder of several receipts (normally 0, at most a few halalas) goes to the destination warehouse's
   inventory account, so a completed transfer changes the company's stock value by exactly nothing.
6. A request still checks availability when it is made but reserves nothing; stock is only promised at approval.
7. With `transfers.requireCarrier`, a carrier NAME alone is accepted (no waybill required).
