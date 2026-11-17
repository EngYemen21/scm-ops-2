# W4 — Sales, credit, consolidation · workstream report

Database `scm_ops_test_sales`. Last run of the workstream's tests: **44 passed, 0 failed, 1,126 assertions** (6 files, see
Tests). Client: `node tools/check-vue.mjs resources/js/pages/sales` → OK, 20 files. `vendor/bin/pint` → passed on the
PHP files of the workstream (not run on `routes/console.php` and the two finance services: only lines were added there,
see NEEDS ARCHITECT 9).
**Not done by design of §0:** no `npm run build`, no server — the changed screens were compiled and import-checked only,
they were NOT opened in a browser (NEEDS ARCHITECT 10).

## Items

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Customer balance | DONE | `customers.balance` = `CreditService::exposure` (unpaid invoices + open orders). `adjustBalance` is gone; the gate of `createOrder` is `CreditService::assertCanOrder`; `sync` runs after order create / cancel and — through the one-line hooks — after invoice issue / cancel, credit note, receipt record / void. Customer card shows limit · receivables · open orders · available. `scm:credit-sync` rewrites every balance. |
| 2 | Zero credit limit | DONE | Behaviour follows `sales.zeroCreditLimitUnlimited` (`assertCanOrder`). `PartnersService`: `CUSTOMER_HAS_BALANCE` (cash terms / limit 0 while exposure > 0), `CUSTOMER_LIMIT_BELOW_BALANCE` with the real exposure. The customer form and card say what 0 means under the current policy. |
| 3 | Discount control | DONE | One rule for quotations and orders. Draft is always saved; `sent` / `approved` / conversion need the discount approval; `POST /sales/quotations/{id}/approve-discount`; a direct order above the limit only from a holder of `discount.approve` (stamped). `GET /sales/policy`; the client no longer hard-codes 30 / 15. |
| 4 | Expired quotations | DONE | `send`, `approve`, `convert` → `QT_EXPIRED` and the quotation becomes `expired` (committed before the refusal); `scm:quotations-expire` daily; `POST …/extend` (`expired → draft`, or just the date). Detail card shows the state and disables Send / Approve / Convert with the reason. |
| 5 | Shortage at order time | DONE | `allowBackorder` on `POST /sales/orders` and on convert; ONE linked backorder order; "nothing available" → the order itself waits. `allocateOrder` reserves what it can. `retryBackorders` + `scm:backorders-allocate` every 10 min. List filter "معلّقة", detail shows the links and ordered vs shipped. The GRN hook is not mine to add (NEEDS ARCHITECT 2). |
| 6 | Consolidation | DONE | Cancelled orders are detached at advance (audited); waiting orders are tried once at `readypick` and named; remove before picking (and later for an order with no live fulfilment order); `POST …/cancel`; add / remove checks; gates count `partial` / `failed`; `OC_EMPTY`. |
| 7 | Pick sequence | DONE | `fulfill` sorts tasks by temperature (ambient / hazmat → chilled → frozen), then zone, aisle, rack, bin. |

## What the rules are now

### 1–2 Credit

- **Exposure** (`CreditService::exposure`, foundation): `receivables` (customer invoices `issued | partially_paid`,
  `total − paid_amount`) + `openOrders` (VAT-inclusive value of our orders in `confirmed … outfordel`; orders with a
  `source_system` are not counted). `customers.balance` is that figure, rewritten by `sync()`.
- **Where sync runs.** `SalesService::createOrder` and `cancelOrder` (not for an order of another system);
  `CustomerInvoiceService::issueFromDelivery`, `creditFromReturn`, `cancel`; `PaymentsService::receive`, `void`
  (one line each: `app(\App\Services\Sales\CreditService::class)->sync(…)`, inside the caller's transaction, after its
  own work). `BackorderService::cancel` (foundation) and W3's put-back sync on their side.
- **Gate.** `createOrder` locks the customer row, then `assertCanOrder(customer, order total)`: with a limit,
  `exposure + order ≤ limit` (`CREDIT_LIMIT`, details `{limit, balance, receivables, openOrders, order}`); with limit 0,
  unlimited — unless `sales.zeroCreditLimitUnlimited` is off: then refused while receivables > 0 (`CREDIT_NONE`).
  The whole ordered value counts, also the part that goes to a backorder.
- **Customer master.** `GET /customers/{id}` carries `credit: {limit, receivables, openOrders, exposure, available,
  limited}`; its `balance` and `availableCredit` are computed live from it. The list keeps using the stored column
  (sortable). `PATCH /customers/{id}`: `CUSTOMER_CREDIT_LIMIT` (unchanged, first) → `CUSTOMER_HAS_BALANCE` when the change
  switches to cash terms or to a zero limit while exposure > 0 → `CUSTOMER_LIMIT_BELOW_BALANCE` when the limit is
  *tightened* (lowered, or set from 0) below the exposure; details carry `{exposure, receivables, openOrders, limit}`.
  An unchanged or raised limit never blocks an unrelated edit (the form always sends the field).
- **`scm:credit-sync`**: every customer, prints `CODE: before → after` for the ones rewritten; scheduled nightly (02:30)
  as a safety net.

### 3 Discount

`sales.maxDiscountPct` (default 30). "Above the limit" = any line with `discPct >` it.

- Quotation: `POST /sales/quotations` saves a draft with any discount (0–100). Above the limit: when the author holds
  `discount.approve` it is stamped at once (`discount_approved_by`); otherwise it waits and the holders of the permission
  are notified. `action: sent` from a user who cannot approve → `DISCOUNT_APPROVAL` (nothing saved).
  `send` / `approve` without the stamp → `DISCOUNT_APPROVAL` (`details {discountPct, maxPct}`).
- `POST /sales/quotations/{id}/approve-discount` (`discount.approve`, optional `{note}`): stamps the approver, audit
  `QT.DISCOUNT_APPROVE`, activity to `sales`. Idempotent (the first approver stays). `DISCOUNT_NOT_NEEDED` when nothing is
  above the limit, `QT_CLOSED` for `rejected | converted`. Allowed in `draft | sent | approved | expired`.
- Order: a direct order above the limit → `DISCOUNT_APPROVAL` unless the user holds `discount.approve`
  (`sales_orders.discount_approved_by` = the user). An order from a quotation takes the quotation's approver; a quotation
  approved before the limit was lowered is held at conversion until its discount is approved.
- Every quotation response carries `discount: {maxPct, linePct, aboveMax, approvedBy, needsApproval}`;
  `GET /sales/quotations?discount=pending` is the approver's work list.

### 4 Validity

A quotation is valid through the end of its `valid_until` day. `send`, `approve`, `convert` on an open quotation past
that day: it is moved to `expired` in its own committed transaction (audit + activity), then `QT_EXPIRED`
(`details {validUntil}`); on an already expired one: `QT_EXPIRED`. `reject` of an expired quotation stays
`QT_TRANSITION` — the exit of `expired` is the extension. `POST /sales/quotations/{id}/extend {validUntil}`:
`VALID_PAST` for a past date, `QT_CLOSED` for `rejected | converted`; `expired → draft` (it is sent and approved again;
a discount approval is kept), an open quotation just gets the date. `scm:quotations-expire` (daily 00:10) expires every
`draft | sent | approved` quotation past its date. Responses carry `validity: {expired, daysLeft}` (computed, so a lapsed
quotation is shown as such before anything moved it).

### 5 Shortage, backorders, allocation

- `POST /sales/orders` (and `…/quotations/{id}/convert`) without `allowBackorder`: refused as before,
  `INSUFFICIENT_AVAILABLE` with `details {sku, available, requested, shortages: [{sku, nameAr, nameEn, available,
  requested}], backorderAllowed}`. Two lines of one product share its availability.
- With `allowBackorder: true` and policy `sales.allowBackorder`: every line reserves what is available; the missing
  quantities go to ONE backorder order (`BackorderService::split`, reason `no_stock`): the original keeps
  `qty = reserved`, `ordered_qty`, `short_qty`, `has_shortage`, is `allocated` / `fulfil_status full`, and its weight is
  recomputed; the backorder is `confirmed` / `backordered`, `backorder_of_id` = the original. Nothing available at all →
  no second order: the order itself is `confirmed` / `backordered`. Policy off → the flag is ignored and the refusal says
  `backorderAllowed: false`.
- `POST /sales/orders/{id}/allocate`: all → `allocated` / `full`; part → stays `confirmed` / `partial` (audit
  `SO.ALLOCATE_PARTIAL`), answer carries `allocation: {complete, gained, missing: [{sku, nameAr, need}]}`; nothing at all
  → `INSUFFICIENT_AVAILABLE`, nothing changed; an order with no quantity left (all of it moved to a backorder) → `SO_EMPTY`.
- `SalesService::retryBackorders(int $limit = 50)`: our `confirmed` orders, oldest first, each in its own transaction.
  `$limit` bounds the orders actually attempted; an order none of whose products has stock is skipped without using the
  budget, so a long queue of unfillable orders cannot starve the ones behind it. Returns `{checked, tried, completed,
  partial, orders}`. `scm:backorders-allocate {--limit=50}` every 10 minutes.
- `fulfill` creates no execution line for a sales-order line with `qty = 0`, and no pick task for an allocation with
  nothing left to pick.
- `GET /sales/orders`: `waiting=allocation` (status `confirmed` — the "معلّقة" list), `shortage=1` (`has_shortage`),
  `backorders=1` (linked backorder orders). `GET /sales/orders/{id}`: `backorderOf {number, status}`,
  `backorders [{number, status, fulfilStatus}]`, `waiting [{sku, nameAr, nameEn, need, available}]`, and on lines
  `orderedQty` / `shortQty`.
- A plain `cancel` is refused from `picking` on with `SO_CANCEL` (the state machine now allows `picking → cancelled` only
  for the warehouse's put-back); the `SO_CANCEL_FO` guard is unchanged.

### 6 Consolidation

- `advance`: (1) cancelled orders are detached in their own committed step (`OC.DETACH` audit + activity); (2) no live
  order left → `OC_EMPTY`; (3) `readypick`: every `confirmed` order is tried once with the allocation logic; when one
  stays short → `OC_UNALLOCATED` naming all of them (`details.orders`), and the whole step is rolled back (no fulfilment
  order, the attempt's reservations too); (4) gates: `packed`, `readydisp`, `dispatched` need every live order to have a
  fulfilment order in `packed | loaded | onroute | delivered | partial | failed` (`OC_NOT_PACKED`, `details.orders`);
  `dispatched` needs a trip on every unfinished fulfilment order (`OC_NO_TRIP`, `details.fulfilmentOrders`); `done` needs
  `delivered | partial | failed` (`OC_NOT_DELIVERED`).
- `add`: batch `open | consolidating` (`OC_LOCKED`), same warehouse (`OC_WAREHOUSE`), not in a batch (`OC_IN_BATCH`,
  naming the other batch), order `confirmed | allocated` (`OC_STATE`).
- `remove` (`POST …/remove {orderNumber}` or the body-less `POST …/orders/{so}/remove`): the order must be in this batch
  (`OC_NOT_MEMBER`); allowed in `open | consolidating | readypick`, and later only for an order that has no live
  fulfilment order (went back to waiting, or cancelled) — otherwise `OC_LOCKED`. A batch may be left with one order.
- `POST /sales/consolidations/{id}/cancel {reason?}`: allowed in `open | consolidating`, and at any stage when no live
  order is left (so an emptied batch always has an exit); `OC_LOCKED` otherwise, `OC_DONE` for a finished one. Orders go
  back to the pool untouched, the batch row is deleted, audit `OC.CANCEL` + status history + activity stay.
- `GET /sales/consolidations/{id}` carries `actions: {canAdd, removable: [numbers], canCancel, cancelReasonAr,
  cancelReasonEn}` — the screen shows exactly what the endpoints accept.

### 7 Pick sequence

Sort key of the pick tasks: temperature rank (ambient / hazmat 0, chilled 1, frozen 2 — the colder of the bin's zone type
and the product's storage class), zone code, aisle, rack, bin.

## Endpoints

| Method · path | Body | Permission | Error codes |
|---|---|---|---|
| GET `/sales/policy` (new) | — | signed in | — |
| POST `/sales/quotations` (changed) | as before | `sales.manage` | `DISCOUNT_APPROVAL` only for `action: sent` above the limit without `discount.approve`; `VALID_PAST`, `SKU_*`, `CUSTOMER_NOT_FOUND` |
| POST `/sales/quotations/{id}/send`, `/approve` (changed) | — / `{note?}` | `sales.manage` | + `QT_EXPIRED`, `DISCOUNT_APPROVAL` |
| POST `/sales/quotations/{id}/approve-discount` (new) | `{note?}` | `discount.approve` | `QT_CLOSED`, `DISCOUNT_NOT_NEEDED`, `QT_NOT_FOUND` |
| POST `/sales/quotations/{id}/extend` (new) | `{validUntil}` | `sales.manage` | `VALID_PAST`, `QT_CLOSED`, `QT_NOT_FOUND` |
| POST `/sales/quotations/{id}/convert` (changed) | `+ allowBackorder?` | `so.reserve` | + `QT_EXPIRED`, `DISCOUNT_APPROVAL`; `INSUFFICIENT_AVAILABLE` with the new details |
| GET `/sales/quotations` (changed) | `+ discount=pending` | signed in | items carry `discount`, `validity` |
| POST `/sales/orders` (changed) | `+ allowBackorder?`; `lines[].discPct` now checked | `so.reserve` | `DISCOUNT_APPROVAL`, `CREDIT_LIMIT` (new details), `CREDIT_NONE`, `INSUFFICIENT_AVAILABLE` (new details) |
| POST `/sales/orders/{id}/allocate` (changed) | — | `so.allocate` | `SO_NOT_WAITING`, `SO_EMPTY`, `INSUFFICIENT_AVAILABLE` (nothing at all) — partial is a 200 |
| POST `/sales/orders/{id}/cancel` (changed) | `{reason?}` | `so.cancel` | `SO_CANCEL` from `picking` on, `SO_CANCEL_FO` |
| GET `/sales/orders` (changed) | `+ shortage=1`, `backorders=1` | signed in | — |
| POST `/sales/consolidations/{id}/advance` (changed) | — | `consol.manage` | + `OC_EMPTY`, `OC_CHANGED` (409); `OC_UNALLOCATED`, `OC_NOT_PACKED`, `OC_NO_TRIP`, `OC_NOT_DELIVERED` now name the orders |
| POST `/sales/consolidations/{id}/add` (changed) | `{orderNumber}` | `consol.manage` | + `OC_IN_BATCH`, `OC_STATE` |
| POST `/sales/consolidations/{id}/remove` (changed) | `{orderNumber, reason?}` | `consol.manage` | + `OC_NOT_MEMBER`; `OC_LOCKED` only for an order being fulfilled once picking started |
| POST `/sales/consolidations/{id}/orders/{so}/remove` (new) | `{reason?}` | `consol.manage` | same as remove |
| POST `/sales/consolidations/{id}/cancel` (new) | `{reason?}` | `consol.manage` | `OC_LOCKED`, `OC_DONE`, `OC_NOT_FOUND` |
| GET `/customers/{id}` (changed) | — | signed in | `+ credit`, live `balance` / `availableCredit` |
| PATCH `/customers/{id}` (changed) | as before | `customer.manage` | + `CUSTOMER_HAS_BALANCE`; `CUSTOMER_LIMIT_BELOW_BALANCE` on the real exposure |

Commands: `scm:credit-sync` (nightly 02:30), `scm:quotations-expire` (daily 00:10), `scm:backorders-allocate
{--limit=50}` (every 10 minutes).

## Files

- `app/Services/Sales/SalesService.php` (rewritten in place), `app/Http/Controllers/Api/Sales/{Quotations,Orders,Consolidations}Controller.php`, `routes/api/sales.php`
- `app/Services/Master/PartnersService.php` — customer part only: `getCustomer`, `updateCustomer`, new `checkExposure`
- `app/Finance/Services/CustomerInvoiceService.php` (+3 lines), `app/Finance/Services/PaymentsService.php` (+2 lines) — the sync hooks, nothing else
- `routes/console.php` — +38 lines at the end (three commands + their schedules), nothing else
- client `resources/js/pages/sales/`: `shared.js` (`usePolicy`, `linesTotals(lines, vatPct)`, `validateLines(…, discountBlock)`, `maxLineDiscount`, `SO_CANCELLABLE`; `VAT_PCT` / `MAX_DISC` removed), `LinesEditor.vue`, `TotalsBox.vue`, `QuotationForm.vue`, `QuotationDetail.vue`, `QuotationsTab.vue`, `ConvertModal.vue`, `SoForm.vue`, `SoDetail.vue`, `OrdersTab.vue`, `CustomersTab.vue`, `CustomerForm.vue`, `ConsolPage.vue`, `BatchDetail.vue`, new `ShortagePanel.vue`
- tests: see below; `tests/Feature/Sales/SalesFixtures.php` — one helper added (`receivable()`)

## Tests

`DB_DATABASE=scm_ops_test_sales php artisan test tests/Feature/Sales/SalesOrdersTest.php tests/Feature/Sales/QuotationsTest.php tests/Feature/Sales/ConsolidationsTest.php tests/Feature/Sales/CreditTest.php tests/Feature/Sales/SeededDocumentsTest.php tests/Feature/Master/PartnersTest.php`
→ 44 passed, 1,126 assertions.

| File | Tests | What |
|---|---|---|
| `tests/Feature/Sales/SalesOrdersTest.php` | 15 | FEFO order, refusal details, order with a shortage → backorder (policy on / off, fulfil, retry command), nothing available, oldest-first retry without starvation, credit gate on real receivables, direct-order discount, cancel, cancel refused at picking, partial allocate + `SO_EMPTY`, pick list, temperature walk, permissions |
| `tests/Feature/Sales/QuotationsTest.php` | 8 | lifecycle, convert with a shortage, discount approval end to end (+ lowered limit), expiry + extension, daily command, validation, permissions |
| `tests/Feature/Sales/ConsolidationsTest.php` | 4 | full path with the gates (partial / failed), waiting order at `readypick`, add / remove rules, cancelled orders + `OC_EMPTY` + cancel, order back to waiting after picking started |
| `tests/Feature/Sales/CreditTest.php` (new) | 5 | balance follows orders / invoices / receipts / void / invoice cancel, delivery invoice + credit note through the finance services, orders of another system + `scm:credit-sync`, zero-limit policy, customer-master rules |
| `tests/Feature/Sales/SeededDocumentsTest.php` | 2 | unchanged stories |
| `tests/Feature/Master/PartnersTest.php` | 10 | credit parts adapted |

`reconcile()['ok']` is asserted after every story that reserves or moves stock.

**Tests that pinned the old behaviour and were rewritten**

- `SalesOrdersTest`: the fake starting balances (`makeCustomer(100000, 1000)`, `(500000, 236000)`, `(0, 500)`) are now
  real unpaid invoices (`receivable()`), because a figure written into `customers.balance` is overwritten by the first
  sync; `CREDIT_LIMIT` details are the new shape; `INSUFFICIENT_AVAILABLE` details gained `shortages` /
  `backorderAllowed`; `test_allocate_an_order_left_waiting_for_stock` → `…reserves_what_is_available…` (sales now holds
  `so.allocate`; partial allocation is a success); `test_cancel_is_refused_once_picking_started` is unchanged and green
  again (`SO_CANCEL`).
- `QuotationsTest`: `DISCOUNT_APPROVAL` at creation → new test `…waits_for_the_discount_approver` (a draft is saved;
  only `action: sent` is refused).
- `ConsolidationsTest`: `test_orders_waiting_allocation_block_readypick…` → `…tried_at_readypick_and_the_batch_can_go_on_without_it`.
- `PartnersTest`: the seeded `150000 − 42300` available credit and the `CUSTOMER_LIMIT_BELOW_BALANCE` case on the seeded
  balance now use the real exposure.
- `SeededDocumentsTest`: the exact key list of W3's loading plan became "these keys are present" (W3 added `dock`,
  `loadGate`, `dispatch`, `policy`).

## NEEDS ARCHITECT

1. **Release step: `php artisan scm:credit-sync` once after the migration.** Until then every stored balance is the old
   counter (the gate itself already uses the live exposure, so no order is wrongly refused; lists and the lookups show the
   old figure). On a fresh / demo database add it to the seed path too — the seeded balances (e.g. CUS-1001 42,300) are
   not what the seeded documents add up to (8,868.80).
2. **Stock-arrival hooks.** `app(\App\Services\Sales\SalesService::class)->retryBackorders();` should run after a GRN
   posts / put-away makes stock allocatable (W7, `InboundService`), and is worth adding after a return is restocked (W5)
   and a transfer is received (W6). Without them a waiting order is served by the 10-minute command.
3. **No scheduler on the serverless deployment.** `scm:backorders-allocate`, `scm:quotations-expire` and
   `scm:credit-sync` are `Schedule::command` entries; where only `POST /api/v1/ops/heartbeat` runs
   (`IntegrationRunner::run`), call `SalesService::retryBackorders()` and `expireQuotations()` from it. The rules do not
   depend on it (an expired quotation is refused at send / approve / convert anyway; "Allocate" is a button), only the
   automation does.
4. **`discount.approve` has no screen for `gm`.** The permission is granted to `gm` (and `super`), but `gm` has neither
   `page.sales` nor the `sales` nav entry, and a quotation opens at `/sales?q=…` (guarded by `page.sales`). The API works
   for `gm` (tested); in the client only `admin` can press "اعتماد الخصم" today. Needed: grant `page.sales` + nav `sales`
   to `gm` (config + `PermissionSync` grant), or give `discount.approve` to a sales-manager role.
5. **Failed / rejected delivery (W2, `DeliveryService::closeStop`).** No invoice is issued, so no sync runs and the stored
   balance keeps counting that order until the customer's next sync or the nightly command. One line after the order's
   status update fixes it: `app(\App\Services\Sales\CreditService::class)->sync($customerId);`.
6. **`CreditService::exposure` (foundation) ignores receipts on account.** A receipt (or the part of it) that is not
   allocated to an invoice does not lower the exposure. If the business collects on account, subtract posted,
   unallocated customer receipts in `exposure()`.
7. **A Sales-platform order allocated by hand** (`POST /sales/orders/{id}/allocate` on an order with `source_system`)
   changes OPS but publishes no `order.reserved` / `order.backordered` (it never did). The integration's `topUp` does it
   every minute, so the button is redundant for those orders; either hide it for them or have the endpoint delegate to
   the integration layer (needs a per-order entry point in `OrderIntakeService`).
8. **Cancelled batch = deleted row.** There is no `cancelled` state in `OC_STATES` / `OC_LABELS`, so "dissolve" removes
   the batch document (audit, status history and activity stay; the FKs are `nullOnDelete`). If cancelled batches should
   stay visible, add the state to `config/scm.php` + `constants.json` and `cancelConsolidation` becomes a status change.
9. **Style.** `routes/console.php` and the two finance services use fully qualified class names in my added lines on
   purpose (lines only, as §W4 says); `pint` would turn them into imports and rewrite neighbouring lines of the same kind
   in `console.php`. `SettingsService::DEFAULTS['sales.maxDiscountPct']` still says "needs sales-manager approval" — it is
   now "needs a holder of discount.approve".
10. **Browser pass.** Open: Sales › quotations (a draft above 30 %, an expired one), new sales order with a shortage,
    SO page of an order with a backorder, customer card, consolidation batch with a waiting order.
11. **W3 contract.** `fulfill` no longer creates an `fo_lines` row for a sales-order line with `qty = 0`; a put-back that
    returns an order to `confirmed` is served again by `allocate` / `retryBackorders` (tested with a simulated put-back).

## Assumptions the business should confirm

1. **Exposure counts everything accepted**, VAT included, also the value waiting on a backorder: a customer near his
   limit can be refused for goods that will ship later.
2. **Zero limit.** Under the default policy a customer with limit 0 is not credit-checked, and the `sales` role may edit
   limits (`customer.manage`) and place orders (`so.reserve`). The new refusals stop the switch only while something is
   owed. Closing the gap fully means turning `sales.zeroCreditLimitUnlimited` off or moving limit changes to finance.
3. **No four-eyes on the discount**: a holder of `discount.approve` who creates the quotation or the order approves his
   own discount (recorded). A user without it cannot place a direct order above the limit at all — he goes through a
   quotation.
4. **An extended expired quotation returns as a draft** (sent and approved again); its discount approval is kept.
   Validity ends at the end of the `valid_until` day (server time, UTC).
5. **Backorders**: one backorder order per accepted order, same prices, discount and delivery date; waiting orders get
   stock strictly oldest first, and a partial reservation keeps that stock for the older order.
6. **`readypick` is all-or-nothing**: when one order of the batch is still short nothing is created and the user decides
   (remove it, or wait).
7. **Batch cancel** only while `open | consolidating`, or when the batch has no live order; an order whose picking
   started leaves a batch only after the warehouse put it back or it was cancelled.
8. **Allocate with nothing available stays a refusal** (`INSUFFICIENT_AVAILABLE`, 422); a partial reservation is a
   success that leaves the order waiting.
