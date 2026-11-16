# W5 — Returns, purchase returns, debit notes

Workstream report (SPEC.md §W5). Test database `scm_ops_test_platform`. Last run of the three W5 test files:
**18 tests, 806 assertions, all passing**; `vendor/bin/pint` clean on every PHP file touched;
`node tools/check-vue.mjs resources/js/pages/returns resources/js/pages/procurement` → OK (54 files).
The client was verified with the static checker only — it was not opened in a browser (no server / build in this phase).

## Status per item

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Receipt, inspection and decision per line | DONE | `receive` takes `lines[{lineNo, receivedQty}]`; `inspect` stores quantity / condition / note per line; `decide` accepts one decision or parts per line; one movement + one `return_decisions` row (with `line_id`) per part; header / line decision `mixed`. Modal rebuilt per line with "+ قرار آخر". |
| 2 | Batch on restock | DONE | `BATCH_REQUIRED` / `EXPIRY_REQUIRED` under `returns.requireBatchOnRestock`; unknown batch at creation → `BATCH_NOT_FOUND`; a new batch is created at the decision with its expiry date. |
| 3 | Cancel | DONE | `POST /returns/{id}/cancel`; holds released; delivery return → `RTN_ON_TRUCK` until its trip is over. |
| 4 | Return to supplier from a customer return | DONE | `supplierCode` on the part; default = return's supplier → supplier of the batch's goods receipt → product's preferred supplier; `SUPPLIER_REQUIRED` names the field; the pill is hidden when no supplier can be named. A debit note is issued for it too. |
| 5 | Purchase return from stock + debit note | DONE | `source_mode = stock`, holds at approval, `POST /returns/{id}/ship`, `DebitNoteService`, finance routes, `PurchaseReturnsTab`. Not done: a debit-note list inside the finance page (not in W5's files) — see NEEDS ARCHITECT 6. |
| 6 | Customer credit note | DONE | A manual `cust` return may carry `foNumber` / `invoiceNumber` → `source_mode = post_delivery` → the existing `creditFromReturn` runs at receipt for the received quantity. Without a reference the screens say no credit note will be issued. |

## Endpoints

All under `/api`. Reads of returns stay open to every signed-in user.

| Method · path | Body | Permission | Error codes |
|---|---|---|---|
| `POST /returns` (changed) | + `foNumber?`, `invoiceNumber?` (type `cust`), `poNumber?`, `grnNumber?` (type `sup`), `lines.*.fromBin?`, `lines.*.unitPrice?` | `return.create` | `FROM_BIN_REQUIRED` 422 (`sup` / `dmg` line without `fromBin`), `FROM_BIN_NOT_ALLOWED` 400, `FROM_BIN_INVALID` 422 (staging / returns zone; `dmg` from DMG-01), `NO_STOCK` 422, `BATCH_REQUIRED` 400 (bin holds several batches), `INSUFFICIENT_AVAILABLE` 422, `BATCH_NOT_FOUND` 400, `PO_NOT_FOUND` / `GRN_NOT_FOUND` 404, `REFERENCE_MISMATCH` 422, `INVOICE_NOT_FOUND` / `FO_NOT_FOUND` 404, `INVOICE_CANCELLED`, `FO_NOT_INVOICED`, `CUSTOMER_MISMATCH`, `NOT_ON_INVOICE`, `RETURN_EXCEEDS_INVOICED` 422; existing `SUPPLIER_REQUIRED`, `SKU_NOT_FOUND`, `WH_NOT_FOUND` |
| `GET /returns` (changed) | filters + `supplier`, `sourceMode`; `status` accepts a comma list; every item carries `debitNotes[]` (active) | open | — |
| `GET /returns/{id}` (changed) | + `flow{fromStock, blocked, cancel{allowed, code, reasonAr, reasonEn}, credit, requireBatchOnRestock}`, `holds[]`, `debitNotes[]`, `creditNote`, `po`, `grn`; lines + `pendingQty`, `needsBatch`, `batchOptions[]`, `suggestedSupplier`; decisions + `lineNo`, `sku`, `binCode`, `batchNo`, `supplier` | open | `RTN_NOT_FOUND` |
| `GET /returns/stock-rows` (new) | query `warehouse` (required), `supplier?`, `po?`, `grn?`, `q?`, `scope=all?` → `{rows[], receipts{pos, grns}, scope, truncated}` | `return.create` | `WH_NOT_FOUND`, `SUPPLIER_NOT_FOUND`, `PO_NOT_FOUND`, `GRN_NOT_FOUND` 404 |
| `POST /returns/{id}/approve` (changed) | — ; a stock return holds every line in its bin | `return.flow` | `INSUFFICIENT_AVAILABLE` 422 (line named), `FROM_BIN_REQUIRED` 422 (old `sup` / `dmg` return without a source), `RTN_TRANSITION` |
| `POST /returns/{id}/cancel` (new) | `{reason}` | `return.flow` | `REASON_REQUIRED` 400, `RTN_ON_TRUCK` 422, `RTN_TRANSITION` 422 |
| `POST /returns/{id}/receive` (changed) | `{lines?: [{lineNo, receivedQty}], notes?}` | `return.flow` | `BAD_QTY`, `LINE_NOT_FOUND`, `LINE_DUPLICATE` 400, `NOTHING_RECEIVED`, `RTN_FROM_STOCK`, `FROM_BIN_REQUIRED` 422 |
| `POST /returns/{id}/inspect` (changed) | `{findings?, lines?: [{lineNo, inspectedQty, condition, note?}]}`; repeated while in `inspect` it corrects the record | `return.flow` | `BAD_QTY`, `LINE_NOT_FOUND`, `LINE_DUPLICATE` 400 |
| `POST /returns/{id}/decide` (changed) | `{decision, binCode?, supplierCode?, note?}` or `{lines: [{lineNo, parts: [{qty, decision, binCode?, batchNo?, expiryDate?, supplierCode?, note?}]}]}` | `return.decide` | `PARTS_SUM` 422, `LINE_MISSING`, `LINE_NOT_FOUND`, `LINE_DUPLICATE`, `BIN_NOT_APPLICABLE`, `BATCH_REQUIRED`, `EXPIRY_REQUIRED`, `SUPPLIER_REQUIRED` 400, `SUPPLIER_NOT_FOUND` 404, existing `WRONG_LOCATION`, `BIN_NOT_FOUND`, `NO_BIN`, `RTN_DECISION_STATE` |
| `POST /returns/{id}/ship` (new) | `{carrier?, note?}` | `return.flow` | `RTN_NOT_FROM_STOCK`, `RTN_NOTHING_HELD`, `RTN_TRANSITION` 422; ledger errors (`PERIOD_CLOSED` …) roll the whole step back |
| `POST /returns/{id}/debit-note` (new) | `{lines?: [{lineNo, unitPrice}]}` — re-issue after the note was cancelled / for a return left `shipped` | `return.flow` | `DN_NOTHING_TO_CLAIM` 422, `DN_EXISTS` 409, `LINE_NOT_FOUND` 400 |
| `GET /finance/debit-notes` (new) | filters `status`, `supplier`, `return`, `from`, `to`, `q` | `gl.view` | — |
| `GET /finance/debit-notes/{number}` (new) | — | `gl.view` | `DN_NOT_FOUND` 404 |
| `POST /finance/debit-notes/{number}/cancel` (new) | `{reason}` (≥ 5 characters) → 201 | `gl.approve` | `DN_STATE` 422, `DN_NOT_FOUND` 404 |

## How it works

**Two paths, kept in `returns.source_mode`.**

- *Goods that arrive* (customer / delivery; `source_mode` null or `post_delivery`): pending → approved → received → inspect →
  closed. Only the received quantity enters RET-01; a shortfall raises a `shortage` exception (owner: dispatcher for a
  delivery return, warehouse otherwise); a line with 0 stays without a decision ("لم يُستلم"). A receipt of nothing at all
  is refused (`NOTHING_RECEIVED` — cancel instead).
- *Goods taken from stock* (`sup` / `dmg`, `source_mode = stock`): pending → approved (one `inventory_holds` row per line,
  quarantined stock allowed) → shipped → closed, in one `ship` call. `sup`: `postHold` with `supret` + supplier partner,
  then the debit note. `dmg`: `postHold` with `dmg` into DMG-01 (internal, no journal). Nothing is created in RET-01.
  Source bins in a staging or returns zone are refused.

**Decision.** One decision for the whole return keeps working; its bin is used for the lines it fits and the others are
placed automatically (a bin that fits no line is still `WRONG_LOCATION`). With parts, `binCode` is strict and applies to
restock only (`BIN_NOT_APPLICABLE` otherwise). The received quantity sits in RET-01 under the line's batch; a part decided
under another batch is first re-identified inside RET-01 with two internal `move` movements (no accounting), then moved
by its decision movement — so such a part shows three movements.

**Batches on delivery returns.** When the delivery flow hands over a line without a batch, the returned quantity is
spread over the batches the order was loaded from, last loaded first, one return line per batch (same rule as W2's
spec). A return that names the delivery later takes the batch only when one batch was delivered.

**Debit note** (`App\Finance\Services\DebitNoteService`, table `fin_debit_notes`, number `DN-YYYY-NNNNN`). One note per
(return, supplier), derived from the `sup` rows of `return_decisions` and their `supret` movements. Price: the return
line's `unit_price` → the line of the return's purchase order → the last order (approved or later) of that supplier for
the product → the cost the stock left at. VAT: the purchase order's rate, else `sales.vatPct`. One journal
(`eventKey = dn:{noteId}`): Dr payable (price − cost + VAT) / Cr purchase price variance (price − cost) / Cr VAT input;
negative amounts swap sides. Together with the stock movement the supplier's payable goes down by exactly the note
total and inventory by the cost (asserted in the tests, including a price below cost). Cancelling reverses the journal;
the goods stay returned and a corrected note is issued from the return.

**Customer credit note.** `CustomerInvoiceService` was not edited. The return is handed to `creditFromReturn` as a view
whose lines carry the received quantity, one line per product. At creation the reference must be an invoiced delivery
and the quantity may not exceed invoiced − credited − still-open returns of the same delivery.

## Files

Server
- `app/Services/Returns/ReturnsService.php` (rewritten), `app/Services/Returns/ReturnStockSources.php` (new)
- `app/Http/Controllers/Api/Returns/ReturnsController.php`, `routes/api/returns.php`
- `app/Finance/Services/DebitNoteService.php` (new), `app/Finance/Http/DebitNotesController.php` (new),
  `routes/api/finance.php` (one import + one block of three routes at the end)
- `database/migrations/2026_11_15_000050_w5_extra.php` (new, additive): `returns.carrier`, `returns.ship_note`,
  `return_lines.inspect_note`

Client
- `resources/js/pages/returns/`: `shared.js` (exports added, none removed or renamed), `DecisionModal.vue` (rebuilt),
  `ReturnDetail.vue`, `ReturnForm.vue`, `ReturnLinesEditor.vue`, `ReturnsTab.vue`, `ReturnsPage.vue`, `ReturnDrawer.vue`,
  `ReturnPage.vue`, `ReturnStatusChip.vue`; new `ReceiveModal.vue`, `InspectModal.vue`, `DebitNoteModal.vue`,
  `ReturnStockPicker.vue`
- `resources/js/pages/procurement/`: new `PurchaseReturnsTab.vue`, `PurchaseReturnForm.vue`,
  `PurchaseReturnRowActions.vue`; `ProcurementPage.vue` — tab `ret` only (import, `TAB_KEYS`, tab list, render switch)

Tests
- `tests/Feature/Flows/ReturnsFlowTest.php` — 10 tests
- `tests/Feature/Flows/PurchaseReturnsTest.php` (new) — 5 tests
- `tests/Feature/Finance/DebitNoteTest.php` (new) — 3 tests
- `tests/Feature/Flows/ReturnsFixtures.php` (new trait; `FlowFixtures.php` untouched)

**Test rewritten because it pinned the old behaviour:** `test_damaged_scrap_and_supplier_decisions_move_stock_out_of_the_returns_area`
— it created `dmg` / `sup` returns with no source bin and expected them to be received into RET-01, and it expected the
supplier to come only from the return header. It now takes the damaged / supplier decisions on customer returns, names
the supplier on the decision, and asserts that `sup` / `dmg` without `fromBin` is refused. The other three original tests
are kept (assertions added, none relaxed).

## NEEDS ARCHITECT

1. `CustomerInvoiceService::creditFromReturn` reads `$rl->qty` and computes "already credited" once before its loop.
   W5 works around both by passing a view (received quantity, one line per product). Cleaner: read
   `received_qty ?? qty` and aggregate per product inside the method.
2. `FinanceReportsService::aging('supplier')` (~line 207) still reads approved supplier invoices only — issued debit
   notes do not reduce the aged payable. Add them as negative amounts per supplier.
3. `FinanceDashboardService::reconciliation()`: the AP sub-ledger is `Σ(invoice total − paid) − unallocated payments`;
   the GL also carries supplier returns. Subtract `Σ total` of issued debit notes (and, for goods returned whose note
   was cancelled, the movement cost). This gap existed before W5 for every `supret` with a supplier.
4. Goods of a delivery return that never come back (short receipt, or the return cancelled after the trip closed) stay
   valued in "goods in transit". W5 raises a `shortage` exception and notifies finance; the write-off entry
   (Dr inventory loss / Cr goods in transit at load cost) is an accounting decision that was not taken.
5. `docs/finance/ARCHITECTURE.ar.md` §3.3 — add the debit-note rule and "return from stock"; §1.1 says returns have no
   financial effect.
6. Finance page: no debit-notes list (those files are not W5's). Notes are read, cancelled (`gl.approve`) and re-issued
   from the return detail (procurement → purchase returns → the return). The API for a finance tab exists.
7. `mixed` is a new value of `returns.decision` / `return_lines.decision` but is not in `RETURN_DECISIONS` /
   `RETURN_DECISION_LABELS` (`config/scm.php`, `constants.json`). W5 labels it locally (server and returns client); any
   other screen that maps the decision through the shared labels will show the raw code.
8. New integration event types `return.cancelled` and `return.shipped` go to the Sales platform for returns of its
   orders (in practice only `return.cancelled`, for delivery returns).
9. Demo snapshot: `RTN-2026-0082` (`sup`, pending, no source bin) can now only be rejected or cancelled — the screens
   say why. Consider replacing it with a return from stock.
10. `SoDetail.vue` "إنشاء مرتجع" opens `/returns?so=…&customer=…`, which the returns page never read. With item 6 it
    would be natural to open the form with the delivery reference filled in.
11. Other suites were not run (rule of §0). Read for impact: `AcceptanceTest`, `DeliveryFlowTest`, `FinanceFlowTest`,
    `ControlTowerTest` use the single-decision calls, which still work. To watch: a delivery return of an
    expiry-tracked product whose order has no `load` movements would now be refused a batchless restock
    (`BATCH_REQUIRED`); none was found.

## Assumptions to confirm with the business

- A return to a supplier decided on a customer return also gets a debit note (the spec asked for the supplier only).
- Debit note without a purchase order: price = last order of that supplier for the product, else cost; VAT = `sales.vatPct`.
- One debit note per return and supplier; cancelling it needs `gl.approve`, re-issuing it needs `return.flow`.
- A purchase return may be taken from shelves, quarantine and the damaged area — not from staging or the returns area.
- A receipt of zero units is refused; the return is cancelled instead. Cancelling needs a written reason.
- A customer return that names its delivery is limited to invoiced − credited − open returns of that delivery.
- When several batches were delivered and a later return does not name one, the inspector names it at the decision.
