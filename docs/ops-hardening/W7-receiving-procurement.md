# W7 — Receiving and procurement · report

Workstream W7 of the operational hardening release (`SPEC.md` §W7). Test database `scm_ops_test_procurement`.
Last run: `InboundFlowTest` + `BackorderShipmentTest` + `ReceivingHardeningTest` + `tests/Feature/Procurement/*` →
**45 tests, 1927 assertions, all passed**. `node tools/check-vue.mjs resources/js/pages/inbound resources/js/pages/procurement`
→ OK. `vendor/bin/pint` on every changed PHP file → passed.

Not done in this workstream by rule (§0): no `npm run build`, no browser pass (the architect builds once at the end),
no run of other suites (acceptance, finance, integration) — see "Checks left for the architect".

## Status per item

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Rejected goods stay open · PO close · four figures | DONE | one formula (`ProcurementHelpers::openQty`) used by receiving, follow-up shipments, PO status, PO page, replenishment "incoming" |
| 2 | Several batches per receiving line | DONE | `batches[]` per line; one GRN row / batch / putaway task / movement per batch; repeated `lineNo` refused |
| 3 | Refuse at the gate · un-arrive | DONE | plain cancel stays "expected only" |
| 4 | Void a goods receipt | DONE | `grnvoid` at each receipt movement's own cost; finance invariant asserted through the receipt's journals |
| 5 | Split putaway · staging fix | DONE | optional `qty`; a staging entry now belongs to its task (reference), legacy entries cleared one at a time |
| 6 | Charges on partial invoices | DONE | expected = order figure − earlier non-cancelled invoices; over the remainder beyond tolerance → `charges` |
| 7 | Price control requisition → order | DONE | `pr_est_price`, `PR_PRICE_REASON`, `price_changed` / `price_note`, finance added to the chain, requisition row lock |

## 1. Rejected goods stay open, an order can be closed short

- **Open quantity of a PO line = `qty − received − damaged`** (policy `procurement.rejectedStaysOpen`, default on; off =
  the old behaviour, rejected counts as fulfilled). Damaged stays received. One implementation:
  `App\Services\Procurement\ProcurementHelpers::openQty()` — used by `InboundService::backorderState / createBackorder /
  postGrn`, `PoService::receipt / get / close`, `ProcurementService::suggestions` ("incoming"), `GrnVoidService`.
- A line with rejected goods keeps the PO `partial`; the follow-up shipment carries the rejected quantity. A fully
  rejected receipt closes the *shipment* (`done`, nothing to put away) and leaves the *order* `partial`.
- `grn_lines.remaining_qty` now means "still owed on the PO line after this receipt" (undelivered + rejected). The
  `shortage` exception is raised only for what was not delivered at all; the `rejected` exception says the quantity
  stays open on the order.
- `PoService::receivedPct` = (accepted + damaged) / ordered — never counts a rejection. List and detail carry
  `receipt: { ordered, received, damaged, rejected, open, waived }`; detail lines carry `openQty`, `waivedQty`.
- **`POST /procurement/po/{id}/close { reason }`** (`po.cancel`): `partial → closed` (and `received → closed`, which the
  table also allows — nothing waived). Stamps `closed_at / closed_by / close_reason`, sets `open_qty = 0`, cancels a
  shipment that is still `expected`, audit `PO.CLOSE` (waived quantity, value, lines), activity to procurement + finance.
  A shipment at the gate (`arrived | inspecting`) blocks the close. After closing: no follow-up shipment
  (`PO_NOT_RECEIVABLE`), not in "incoming", receiving on it is refused.
- Client: PO page shows four tiles (received · damaged · rejected · still owed / waived) instead of "% received", the
  line column "still owed" comes from the server, "Close short" button with a reason (`PoActions`), closed banner.
  PO list shows received / ordered with damaged · rejected · open underneath, and status pills for `partial`, `closed`.

## 2. Several batches on one receiving line

- GRN line payload: `batches: [{ qty, batchNo, mfgDate?, expiryDate }]` for the accepted quantity. Σ qty must equal
  `acceptedQty`. One `grn_lines` row per batch (`split_no` 1…n), one batch record, one putaway task, one `grn` movement
  and one staging entry each. The first row also carries the line's damaged / rejected quantities (the damaged stock is
  booked on the first batch). Without `batches` the line behaves exactly as before (backward compatible).
- A repeated `lineNo` is refused (it used to overwrite the first one silently).
- Client: "+ دفعة أخرى" under a line in `ReceiveLinesGrid` and on the worker screen — each extra row is a quantity
  carved out of the accepted total with its own number and dates; the first batch is what is left.

## 3. Refuse at the gate, undo an arrival

- **`POST /inbound/shipments/{id}/refuse { reason }`** (`shipment.receive`): `arrived | inspecting` with no receipt in
  force → `cancelled`, `refused_at / refused_by / refused_reason`, exception (kind `rejected`) to procurement, activity.
  The PO status is untouched and a follow-up shipment can be opened at once (`backorder.allowed`).
- **`POST /inbound/shipments/{id}/unarrive`** (`shipment.receive`): `arrived → expected`, clears `arrived_at` and
  `carrier`, audit `SHIPMENT.UNARRIVE`.
- `POST …/cancel` stays "expected only" (the new table transitions `arrived|inspecting → cancelled` belong to refuse);
  `POST …/inspect` is restricted to `arrived` (the table's `putaway → inspecting` is the void's reopening, not a button).
- Client: "Undo arrival" and "Refuse at the gate" in `ShipmentPanel` and on the worker screen, refused banner / chip.

## 4. Void a goods receipt

- **`POST /inbound/grns/{id}/void { reason }`** (`grn.void`), service `App\Services\Inbound\GrnVoidService`.
- Allowed while untouched, checked in this order; each blocker answers `GRN_VOID_LATE` with `details.blocker`:
  `putaway` (a task is done or partly done) · `return` (a non-cancelled purchase return references the receipt) ·
  `stock` / `damaged` (the quantity is no longer free in STG-IN / DMG-01) · `invoice` (a non-cancelled supplier invoice
  bills more than the other live receipts accepted). A waiting follow-up shipment answers `GRN_VOID_FOLLOWUP_OPEN`.
  `GET /inbound/grns/{id}` carries `void: { allowed, code, blocker, reasonAr, reasonEn }` so the page shows the button
  or the reason.
- Effects, one transaction: a `grnvoid` movement for every receipt movement (`grn` out of STG-IN, `dmg` out of DMG-01)
  at that movement's own `unit_cost`; putaway tasks and their staging entries `cancelled`; PO-line and shipment-line
  counters rolled back; PO `open_qty` recomputed and status restored (`received | partial` when other live receipts
  remain, else `confirmed | sent`); shipment `putaway → inspecting` so it can be received again (or `→ done` when a
  later shipment already took over the remainder — the open quantity then goes on a new follow-up); GRN stamped
  `voided_at / voided_by / void_reason`; the receipt's open exceptions resolved with a note; audit `GRN.VOID`; activity
  to warehouse, procurement, finance.
- **Finance invariant** (test): after the void the journals of the receipt sum to 0 on `inventory` and on `grni`.
- A voided GRN stays in the log with `voided: true` (`GET /inbound/grns?voided=0` hides them), is struck through on
  the shipment / PO pages, and is excluded from: invoice matching (`SupplierInvoiceService`), `acceptedQty` on the PO
  page, the PO list's GRN count, the PO trace, "received this month", supplier performance. A shipment's `grn` field
  is the receipt in force.

## 5. Split putaway, staging entries

- **`POST /inbound/putaway/{id}/confirm`** takes optional `qty` (1 … what is left): that quantity moves to the scanned
  bin, `done_qty` accumulates, the task stays `open` until nothing is left; the shipment is `done` when no task is open.
  Response adds `qty, doneQty, remaining, taskDone`.
- Staging entries are created with `reference_type = PutawayTask` (the task's id / number) and cleared through that
  reference. Entries of receipts posted before this release (reference = the GRN) are cleared one at a time, by id —
  the audited bug (two tasks of one GRN with the same quantity cleared both entries) is pinned by a test.
- Client: quantity box on the putaway row (empty = everything left), "stored X — Y left" on the task.

## 6. Charges on partial invoices

- Expected discount / shipping of an invoice = the order's figure − what earlier non-cancelled invoices carried
  (`SupplierInvoiceService::chargesLeft`). Billing more than that remainder by more than
  `procurement.invoiceTolerancePct` of the **order's** figure → variance `charges` with `fields`, `expected`,
  `actual`, `po`, `previouslyInvoiced` and a text that names the remainder. Billing less is no variance.
- `GET /procurement/po/{id}` carries `charges: { discount: { po, invoiced, remaining }, shipping: { … } }`; the invoice
  form prefills the remainder and warns before submit when the typed charges exceed it.

## 7. Price control from requisition to order

- `to-po` stores each line's requisition estimate in `po_lines.pr_est_price`. A price above it by more than
  `procurement.prPriceTolerancePct` needs `priceReason` (≥ 5 characters) → else `PR_PRICE_REASON` (details: tolerance
  and the lines with estimate / price / %). The order gets `price_changed = true`, `price_note`, an audit entry
  `PO.PRICE_ABOVE_ESTIMATE`, an activity line to finance, and `finance` is appended to the approval chain when the
  value tier did not already include it. `PO.CREATE` audit now carries `prPrices`.
- The requisition row is locked inside the conversion transaction (`PrService::lockForConversion`) for both `to-po`
  and `to-rfq`: the second of two simultaneous conversions waits, then gets `PR_TRANSITION`.
- **`GET /procurement/policy`** (open read): `minSupplierScore, prPriceTolerancePct, invoiceTolerancePct,
  rejectedStaysOpen, allowSelfApproval, allowExcessReceipt` — the settings API needs `settings.manage`, so the screens
  could not read a policy before.
- Client: the drawer shows estimate, new price and % change per line, asks for the reason when a line is above the
  tolerance, and reads the supplier-score limit from the policy (also `PoForm`, `AwardDrawer`). The PO page shows the
  price-change banner (lines, estimate → price, %, the written reason) and the estimate under each unit price; the PO
  list flags such orders.

## Endpoints

| Method · path | Body | Permission | Error codes |
|---|---|---|---|
| `POST /inbound/shipments/{id}/refuse` | `{ reason }` (3–500) | `shipment.receive` | `SHIPMENT_NOT_FOUND` 404 · `SHIPMENT_LOCKED` · `SHIPMENT_HAS_GRN` · `SHIPMENT_TRANSITION` 422 · `INVALID_INPUT` 400 |
| `POST /inbound/shipments/{id}/unarrive` | — | `shipment.receive` | `SHIPMENT_TRANSITION` · `SHIPMENT_LOCKED` 422 |
| `POST /inbound/shipments/{id}/cancel` (changed) | — | `po.cancel` | `SHIPMENT_TRANSITION` for anything but `expected` |
| `POST /inbound/shipments/{id}/inspect` (changed) | — | `shipment.receive` | `SHIPMENT_TRANSITION` for anything but `arrived` |
| `POST /inbound/shipments/{id}/grn` (changed) | lines may carry `batches: [{ qty, batchNo, mfgDate?, expiryDate? }]` | `grn.post` | new: `LINE_DUPLICATE` · `BATCH_SPLIT_SUM` · `BATCH_SPLIT_DUPLICATE` 400 · `SHIPMENT_CANCELLED` · `PO_NOT_RECEIVABLE` 422 (existing: `BATCH_REQUIRED`, `EXPIRY_REQUIRED`, `BAD_QTY`, `OVER_RECEIPT`, `GRN_DUPLICATE` …) |
| `POST /inbound/grns/{id}/void` | `{ reason }` (3–500) | `grn.void` | `GRN_NOT_FOUND` 404 · `GRN_VOIDED` 409 · `GRN_VOID_LATE` · `GRN_VOID_FOLLOWUP_OPEN` 422 |
| `GET /inbound/grns` (changed) | `voided=0|1` filter; rows carry `voided` | — | — |
| `GET /inbound/grns/{id}` (changed) | adds `voided`, `void { … }`, `lines[].splitNo` | — | — |
| `GET /inbound/shipments/{id}` (changed) | adds `grn`, `grns[].voidedAt`, `refused*`, `policy` | — | — |
| `POST /inbound/putaway/{id}/confirm` (changed) | optional `qty` | `putaway.confirm` | new: `PUTAWAY_QTY` 400 · `PUTAWAY_CANCELLED` 409 |
| `POST /procurement/po/{id}/close` | `{ reason }` (3–500) | `po.cancel` | `PO_NOT_FOUND` 404 · `PO_TRANSITION` · `PO_CLOSE_SHIPMENT_OPEN` 422 |
| `GET /procurement/po`, `/po/{id}` (changed) | add `receipt`; detail adds `charges`, `canClose`, `rejectedStaysOpen`, `prPriceTolerancePct`, `lines[].openQty / waivedQty / priceChangePct / priceAboveEstimate` | — | — |
| `POST /procurement/pr/{id}/to-po` (changed) | optional `priceReason` | `po.create` | new: `PR_PRICE_REASON` 422 |
| `POST /procurement/invoices` (changed rule) | unchanged | `invoice.register` | variance `charges` now against the remainder |
| `GET /procurement/policy` | — | signed-in | — |

## Files

Server: `app/Services/Inbound/InboundService.php`, `app/Services/Inbound/GrnVoidService.php` (new),
`app/Services/Procurement/{PoService,PrService,ProcurementService,ProcurementHelpers,ProcurementHousekeeping,SupplierInvoiceService}.php`,
`app/Http/Controllers/Api/Inbound/InboundController.php`, `app/Http/Controllers/Api/Procurement/{PoController,PrController,ProcurementController}.php`,
`routes/api/inbound.php`, `routes/api/procurement.php`.

Client: `resources/js/pages/inbound/{shared.js,ReceiveLinesGrid,ShipmentPanel,ShipmentsTab,ShipmentPage,WorkerReceivePage,PutawayRow,GrnDetail,GrnTab,GrnPage}.vue`,
`resources/js/pages/procurement/{shared.js,PoPage,PoTab,PoActions,PoForm,AwardDrawer,PrToPoDrawer,InvoiceForm,InvoiceDrawer,InvoicesTab}.vue`.
`ProcurementPage.vue` was not touched by W7.

No migration was added; no foundation file and no other workstream's file was edited.

## Tests

New — `tests/Feature/Flows/ReceivingHardeningTest.php` (10 tests):
rejected goods stay open and arrive on a follow-up · policy off = old behaviour · order closed short (permission,
reason, shipment at the gate, expected shipment cancelled, audit, activity, final) · refuse at the gate and un-arrive
(every rejection, exception, follow-up, closed order) · batches on a line (duplicate line, sum, expiry, duplicate batch,
validation, rows / tasks / movements / batches) · split putaway and own staging entry · legacy staging entries · receipt
void (permissions, stock, **journals of the receipt back to zero**, order, shipment, tasks, exceptions, re-receipt,
lists) · too late to void (putaway, damaged gone, staging stock moved, purchase return, invoice) · void next to a
follow-up shipment. `reconcile()['ok']` after every stock move.

New — `tests/Feature/Procurement/ProcurementHardeningTest.php` (5 tests): charges against the remainder (partial share
matched, repeated full shipping disputed, cancelled invoice frees the remainder) · tolerance and discount cases ·
price above the estimate (reason, flag, finance step, audit, approvals, single conversion) · policy-driven tolerance,
no duplicate finance step, no injection through the PO form · policy endpoint, "incoming", close.

Rewritten to the new rule (old behaviour was pinned):
- `InboundFlowTest::test_full_receiving_story…` — the 10 rejected units stay open: GRN line `remainingQty` 0 → 10,
  PO `['partial', 10]` → `['partial', 20]`; staging entries are asserted through their task reference.
- `InboundFlowTest::test_a_fully_rejected_receipt…` — adds: the order stays `partial` with 20 open and a follow-up is allowed.
- `ProcurementReadsTest` — `shipments[].grns[]` keys gain `voidedAt`.
- `InboundFlowTest:69` (cancel after arrival → `SHIPMENT_TRANSITION`) keeps its meaning and passes unchanged.
- `ProcurementFlowTest:221` unchanged (that product has no estimate, so the price rule does not apply).

## NEEDS ARCHITECT

1. **`config/scm.php` `PO_TRANSITIONS`** — there is no `sent → closed` / `confirmed → closed`. An order whose only
   delivery was refused or cancelled and that the supplier will never redeliver cannot be closed (cancel is refused
   after send). `PoService::close` goes through the table, so adding the two transitions is all that is needed.
   The void also restores `received → partial` and `partial | received → sent | confirmed`; these are not in the table
   and are written as a correction with their own audit status line — add them if the table should describe them.
2. **`SHIPMENT_TRANSITIONS`** — no `done → inspecting`. Voiding a receipt that had no putaway task (everything damaged
   or rejected) leaves the shipment `done` and the open quantity goes on a follow-up shipment. `GrnVoidService` uses
   `Sm::can`, so adding the transition makes such a shipment reopen by itself.
3. **`app/Integration/Services/AvailabilityService.php:89-93`** — "incoming" sent to the sales platform still uses
   `qty − received_qty` (damaged is counted as still coming, and the status list is its own). Replace with
   `ProcurementHelpers::openQty($l)` (select `damaged_qty`, `rejected_qty`) so ATP uses the same open quantity.
4. **`app/Services/Platform/ReportsService.php`** (`GRN_AGG`, the GRN / supplier reports, lines 26, 243-283) and
   **`app/Services/Platform/DashboardService.php:126, 357-358`** read `goods_receipts` without `voided_at IS NULL`:
   a voided receipt still counts in the receiving reports, supplier OTIF report and daily GRN counters.
5. **Outbox** — a void emits no integration event. `InventoryReceived` is a B2B event type; if the sales platform
   acts on it, a "receipt voided" message (and its contract) is needed. Likewise nothing is emitted for a PO closed short.
6. **`app/Models/PurchaseOrder.php::receivedPct()`** (generated model) still derives the percentage from `open_qty`;
   W7 no longer calls it. Remove it or make it delegate to `PoService::receivedPct`.
7. **`docs/CONVENTIONS.md`, section "Receiving"** — add: rejected quantity stays open; refuse / un-arrive / void are
   the exits; staging entries reference their putaway task.
8. **Hint texts outside W7's rule** — `SuppliersTab.vue` / `SupplierDrawer.vue` hints still print the score limit "65"
   as text (the forms now read the policy).

## Assumptions for the business to confirm

1. With several batches on a line, the line's **damaged** quantity is booked on the **first** batch (the payload has
   one damaged figure per line, not per batch).
2. **Discount** follows the same rule as shipping: an invoice discount above what is left of the order's discount is a
   `charges` variance (it is in the buyer's favour, but it is not what was ordered); a smaller discount is not.
3. A disputed, not yet cancelled invoice still **occupies** the order's charges (the next invoice sees the remainder
   after it) — cancel it to free them.
4. Closing an order **cancels its expected shipment** automatically; a truck at the gate must be received or refused
   first. Closing sets the order's open quantity to 0 and reports the waived quantity separately.
5. Price control compares with the requisition line's **estimate** only; a line that had no estimate (product without
   a purchase price) is not checked. Finance is appended as the **last** approval step.
6. A receipt cannot be voided while a **purchase return** that is not cancelled / rejected references it.
7. After a void, when a later shipment of the same order was already received, the voided receipt's shipment is
   **closed** rather than reopened, and the remainder is received on a new follow-up shipment.
8. An order with everything rejected and nothing accepted is `partial` (the only status that keeps it receivable and
   closable).

## Checks left for the architect

- Run the suites W7 could not: acceptance, finance (`FinanceFlowTest` test_02/03 receive and invoice through the API —
  payloads unchanged; a single invoice with the order's full shipping is still `matched`), integration, and W5's
  `PurchaseReturnsTest` (it reads `goods_receipts.voided_at` and GRN lines, which may now be several per line).
- Build the client and open: receiving (shipment panel in `arrived` / `inspecting`, "+ دفعة أخرى", putaway partial
  quantity), `/grn/:number` (void button and its disabled reason), `/po/:number` (four tiles, close short, price
  banner), requisition → PO drawer, invoice form on a PO with charges.
- PostgreSQL run: the new queries use the query builder only (no raw engine-specific SQL).
