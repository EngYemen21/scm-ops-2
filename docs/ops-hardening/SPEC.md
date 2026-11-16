# Operational hardening 2026-11 — engineering specification

Six review lists (51 claims) were checked against the code on 2026-11-15: 30 true, 18 partly true, 3 false. This
document is the decision taken on each one and exactly what is built. The Arabic summary for the business is
`DECISIONS.ar.md`. Work is split into seven workstreams that run **in parallel on one working tree** — the rules of
§0 exist so they cannot step on each other.

---

## 0. Rules for every workstream

**Read first:** `docs/CONVENTIONS.md` (backend), `docs/FRONTEND.md` (client), this section, your own section.

**Environment (Windows, Git Bash).** `export PATH="$USERPROFILE/.scmops/php:$PATH"`. MySQL is already running on
127.0.0.1:3307. Run ONLY your own test files on YOUR database:
`DB_DATABASE=<your db> php artisan test <your test files>` (the database is rebuilt and seeded on every run, ~15 s).
Never run the whole suite, never use `scm_ops_test` or `scm_ops` or another workstream's database. Client:
`node tools/check-vue.mjs <the folders you changed>` — **never `npm run build`** (the architect builds once at the end).
PHP files are written with the file tools only: a bash heredoc strips backslashes on this machine. In Git Bash never
pass an argument that starts with `/` to a node script. Do not install packages. Do not start servers.

**Git.** Do not commit, push, stash, reset, checkout or clean. Other workstreams' files change under you — that is
expected. If a test run fails because of a file you do not own (another workstream is mid-edit), wait a minute and run
again (up to 5 times) and never "fix" that file.

**Ownership.** Edit only the files listed for your workstream, plus NEW files inside your folders. The foundation is
already in place and is read-only for you:

| Foundation (do not edit) | What it gives you |
|---|---|
| `database/migrations/2026_11_15_000000_ops_hardening.php` | every new table / column of this release — read it, your columns are there |
| `config/scm.php`, `resources/js/shared/constants.json` | new states / transitions / movement types / permissions (already added, listed below) |
| `app/Services/Core/SettingsService.php` | the new policies (already added, listed below) |
| `app/Services/Inventory/InventoryService.php` | `hold()`, `activeHolds()`, `releaseHolds()`, `postHold()`, `reserve(... excludeRows)`; reconcile counts holds |
| `app/Finance/Services/CostingService.php` | movement types `unload` (Dr inventory / Cr goods in transit, pass `unitCost`) and `grnvoid` (Dr GRNI / Cr inventory at the `unitCost` you pass); `trf` honours `unitCost` |
| `app/Services/Sales/BackorderService.php` | `split()` (quantity → linked backorder order) and `cancel()` |
| `app/Services/Sales/CreditService.php` | `exposure()`, `sync()`, `assertCanOrder()` |
| `app/Services/Fulfillment/ScanCodes.php` | `ScanCodes::fo($code)` / `matches($code, $fo)`: FO, SO, package, label ref or carton code → fulfilment order |
| `app/Models/{InventoryHold,Carton,StoredFile}.php`, `app/Finance/Models/DebitNote.php` | models of the new tables |
| `app/Support/PermissionSync.php` | new permissions reach the database by themselves |

If you truly need a column that is not in the foundation migration, create
`database/migrations/2026_11_15_0000NN_<workstream>_extra.php` (NN = your workstream number × 10) — additive only.
If you need a change in a foundation file or in another workstream's file, do NOT make it: implement your side, and
describe the needed change precisely in your final report ("NEEDS ARCHITECT").

You MAY add casts / relations for the new columns to `app/Models/*` (never change existing ones), and you may add your
route lines to a shared routes file and your one-line hook to another domain's service **only where your section says
so**. Re-read a shared file immediately before editing it and run `php -l` on it right after.

**New states / transitions (already in config + constants).**
SO: `picking → confirmed|cancelled`, `picked → confirmed|cancelled`, `packed → picked`, `loaded → packed`.
FO: `picking|picked → cancelled`, `packed → picked`, `loaded → packed`.
Trip: `loading|ready → planned|vassigned|dassigned`. Shipment: `arrived → cancelled|expected`,
`inspecting → cancelled`, `putaway → inspecting`. Return: states `shipped`, `cancelled`;
`pending → cancelled`, `approved → cancelled|shipped`, `shipped → closed`. Transfer: `partial → partial|received`.
Maintenance state `cancelled`. Quotation: `expired → draft`. Movement types `unload`, `grnvoid`.

**New permissions.** `pick.override` (wm), `load.override` (wm, disp), `discount.approve` (gm), `grn.void` (wm) —
super has all. Existing ones newly granted: `return.create` + `page.returns` → proc, `so.allocate` → sales, wm.

**New policies (`$settings->get(key)`).** `sales.allowBackorder` (true), `sales.zeroCreditLimitUnlimited` (true),
`fulfillment.backorderShortPicks` (true), `fulfillment.blockBinOnShortPick` (true), `loading.enforceSequence` (true),
`loading.requireOrderScan` (true), `delivery.requireScan` (true), `delivery.requireSignature` (true),
`delivery.serviceMinutes` (15), `fleet.avgSpeedKph` (40), `fleet.driverHourlyCost` (0), `fleet.overtimeAfterHours` (8),
`fleet.overtimeMultiplier` (1.5), `fleet.maintCostPerKm` (0), `fleet.depreciationPerKm` (0),
`returns.requireBatchOnRestock` (true), `transfers.requireCarrier` (false), `procurement.rejectedStaysOpen` (true),
`procurement.prPriceTolerancePct` (10).

**Quality bar.** Every rule gets: the server check with a named error code (`AppError::rule('CODE', 'عربي', 'English')`),
audit (`$audit->log` / `$audit->status`), an activity line where a person should know, a test for the happy path and
one for each rejection, and `reconcile()['ok']` asserted after anything that moves stock. Tests that pin the OLD
behaviour in your files are rewritten to the new rule — say so in the report. Client: every new action has its button,
its disabled-with-reason state, its error banner; text is bilingual; no new shared component — reuse `@/components`.
A dead end (a state with no way out) is a bug: every new state has its exit.

**Report (your final message, plain text).** For each item of your section: `DONE` / `PARTIAL` / `NOT DONE` + one
line why; endpoints added or changed (method, path, body, permission, error codes); files changed; tests added and the
last test run result (counts); anything under "NEEDS ARCHITECT"; any assumption you made that the business should
confirm. Also write the same content to `docs/ops-hardening/W<n>-<name>.md`.

---

## W1 — Fleet master data, corrections, trip cost  ·  database `scm_ops_test_transport`

Files: `app/Services/Transport/FleetService.php`, `app/Services/Transport/TripCostService.php`,
`app/Finance/Services/FleetPosting.php`, `app/Http/Controllers/Api/Transport/FleetController.php`, fleet lines of
`routes/api/transport.php`; client `resources/js/pages/transport/{Fleet*,Vehicle*,DriverForm,DriverDrawer,FuelForm,
Maintenance*,TripCost}.vue` (+ their helper js); tests `tests/Feature/Transport/Fleet*Test.php` and new ones there.
Do not edit `TripsService.php` (W2 owns it and already calls `TripCostService::compute()` from `close`).

1. **Trip cost engine (claim: partly true — fuel and tolls were fed, driver / overtime / maintenance / depreciation
   never).** Implement `TripCostService::compute(Trip|string $trip): array`, idempotent, never throws for missing
   master data, writes `trip_costs` + `computed_at` + `basis` (json: per component `{source, detail}` with source
   `actual | estimated | allocated | not_configured`):
   - `fuel`: Σ cost of non-voided fuel records with `trip_id` (actual). None, but the trip has km and the vehicle has
     ≥ 2 fuel records → `km ÷ vehicle average km/L × last price per litre` (estimated). Else 0 / not_configured.
   - `driver` / `ot`: hours = actual_end − actual_start (0 when either is missing). Rate = `drivers.hourly_cost` ??
     `fleet.driverHourlyCost`. Regular = min(hours, `fleet.overtimeAfterHours`) × rate; overtime = the rest × rate ×
     `fleet.overtimeMultiplier`. Rate 0 → not_configured.
   - `maint`: direct maintenance vouchers on the trip (as today) + `km × rate`, rate = the vehicle's closed
     maintenance cost of the last 365 days ÷ km of its closed trips in that window when that distance ≥ 500 km, else
     `fleet.maintCostPerKm`.
   - `dep`: `km × (purchase_cost − salvage_value) ÷ life_km` when the vehicle has them, else `km × fleet.depreciationPerKm`.
   - `tolls`, `other` (and `parking`, `third` if a voucher account role distinguishes them): from posted expense
     vouchers linked to the trip, as `FleetPosting::rebuildTripCosts` does today — keep ONE implementation
     (`rebuildTripCosts` delegates to `compute`).
   Trip costing is management accounting: it posts NO journal (fuel / maintenance / vouchers already posted theirs).
   `POST /transport/trips/{number}/cost/recompute` (`trip.close`). `TripCost.vue` shows every component with its
   source label and the total, and the misleading sentence about automatic computation is replaced by what is true.
   The fuel form gets an optional trip field (the vehicle's trips of the last 14 days) — the API already takes `tripNumber`.
   Vehicle form: `purchaseCost`, `salvageValue`, `lifeKm`; driver form: `hourlyCost` (all optional).
2. **Vehicle edit UI (claim true).** `VehicleForm.vue` gets an edit mode (PATCH, endpoint exists): documents
   (registration, insurance, inspection, operating card), capacities, warehouse, GPS id, cost fields. A "تجديد الوثائق"
   action on the drawer's documents tab opens it on the dates. Odometer: the PATCH must refuse a value LOWER than the
   current one unless `odometerReason` is given (audited as `VEHICLE.ODOMETER_CORRECTION`).
3. **Driver edit UI (claim partly true).** `DriverForm.vue` edit mode (PATCH exists): mobile, licence no / type /
   expiry, iqama expiry, medical expiry, shift, default vehicle, hourly cost. Make the rule and the text agree:
   an expired **licence or iqama** blocks assignment (`TripsService::driverCanAssign` is W2's — report it under NEEDS
   ARCHITECT with the exact rule; in YOUR code make `refreshDocumentAlerts` texts say what really happens: licence and
   iqama stop assignment, the medical certificate warns).
4. **Fuel / maintenance corrections (claim true; DELETE is refused on purpose — a posted record is voided, never
   deleted).**
   - `PATCH /transport/fuel/{number}` (`fuel.manage`) `{liters?, cost?, odometer?, station?, date?, tripNumber?, reason}`:
     reason required; recompute km/L, cost/km and the anomaly flag for this record and the next one of the vehicle;
     when the cost changes reverse the journal (`LedgerService::reverse`) and post the new one; fix the vehicle
     odometer when this record carried the highest reading; rebuild the cost of the old and the new trip; stamp
     `corrected_*`. Rules: the new odometer may not be below the previous record's nor above the next one's.
   - `POST /transport/fuel/{number}/void` `{reason}`: `voided_*`, journal reversed, vehicle odometer back to the
     highest remaining reading, trip cost rebuilt. A voided record is excluded from lists' totals, averages and costs.
   - `PATCH /transport/maintenance/{number}` (open only): kind, description, workshop, start date, odometer.
   - `POST /transport/maintenance/{number}/cancel` `{reason}` (open only) → status `cancelled`; the vehicle leaves
     `maintenance` through the state machine when it has no other open order.
   - UI: row actions in the maintenance / fuel tab (edit, void / cancel with a reason), voided rows shown struck.
   - Fix found by the audit: fuel created by processing a driver's ops request (`FleetService` near the
     `amount / 2.33` line) invents litres and posts nothing to the ledger. It must go through the same path as a fuel
     record (`createFuel` + `FleetPosting::fuel`) with the amount as cost and litres only when the request has them
     (else litres 0 and `full=false`, so the km/L average ignores it).
5. Claim "opCardExpiry validation conflict": **no change** — `sometimes|required` on update only forbids clearing the
   date, which protects the document check. Claim "driver form lacks medical / default vehicle": **false**, both exist.

## W2 — Trips and delivery execution  ·  database `scm_ops_test_flows`

Files: `app/Services/Transport/TripsService.php`, `app/Services/Delivery/DeliveryService.php`,
`app/Http/Controllers/Api/Transport/TripsController.php`, `app/Http/Controllers/Api/Delivery/DeliveryController.php`,
trip lines of `routes/api/transport.php`, `routes/api/delivery.php`, `app/Services/Integrations/*` (storage adapters,
attachments), `routes/api/integrations.php`; client `resources/js/pages/transport/{Trip*,DriverPage,DriverStopCard,
PodPanel,FailPanel,tmsComposables.js,…}` (everything trip / driver that is not in W1's list); tests
`tests/Feature/Transport/Trips*Test.php`, `tests/Feature/Flows/DeliveryFlowTest.php` and new ones.

1. **POD signature / photo are stored (claim true).** A local folder is NOT the fallback: production is serverless,
   a file written to disk is gone on the next request. New adapter `DatabaseStorage` (table `stored_files`, base64 in
   `content`), selected by `AdapterFactory` when no S3 is configured; with S3 configured, S3. `closeStop` stores the
   signature and the photo it receives (data URL → bytes; limit 400 KB signature, 2 MB photo → `ATTACHMENT_TOO_LARGE`)
   through the adapter and writes the `pod_attachments` row with `status = stored | uploaded`, `storage_key`, `mime`,
   `size` — never `integration_pending` again. `GET /integrations/files/{key}` streams a stored file to a signed-in
   user who may see the POD. `PodPanel` compresses the photo in the browser (longest side 1280, JPEG 0.7) before
   sending. The trip room / POD views show the signature and the photo; the hard-coded "Integration Pending" texts go.
   The generic `POST /integrations/attachments` uses the same adapter.
2. **Planned arrival per stop (claim partly true — there was no ETA at all; `planned_time` is the customer window).**
   `TripsService::recomputeEtas(Trip)`: start = `planned_start` on the trip date at the warehouse coordinates; legs
   from the maps adapter when it answers, else straight-line distance × 1.3 ÷ `fleet.avgSpeedKph`; plus
   `delivery.serviceMinutes` per stop. Writes `trip_stops.eta` (HH:mm), `eta_source` (maps | estimate), `window_ok`
   (eta inside the stop's `window` "HH:mm–HH:mm"; null when no window). Stops without coordinates get null and the
   chain continues from the last known point. Called on create, reorder, optimise and assign (planned start may
   change). The customer **window is never changed** by a reorder — it is a promise to the customer. Client: the stops
   table shows window and ETA as two things (today the window is labelled "ETA"), flags a stop whose ETA is outside
   its window, and gets up / down controls to reorder while the trip is editable (`POST …/stops/reorder` exists and
   had no UI).
3. **Scan and signature at delivery (claim true).** Deliver / partial accept `scans: string[]`, `noScanReason`.
   With `delivery.requireScan`: at least one scan must resolve (`ScanCodes::fo`) to this stop's order; a scan that
   resolves to ANOTHER order rejects with `WRONG_PACKAGE` (and raises an exception of kind `wrong` for dispatch); no
   valid scan and no reason → `SCAN_REQUIRED`; a reason is accepted, stored (`no_scan_reason`) and raises a warning
   exception. `scan_ok`, `scans`, `delivered_cartons` (optional count, ≤ order cartons) are stored on the POD.
   With `delivery.requireSignature`: signature or photo required, except when the caller is recording on behalf of the
   driver (`trip.manage`, not the trip's driver) and gives `notes` — then `SIGNATURE_REASON` if notes are empty.
   `PodPanel` / `DriverStopCard`: scan field (the app's `ScanButton` / `TextInput scan`), "لا أستطيع المسح" with a
   reason list (label damaged, scanner / camera failed, recorded on behalf).
4. **Partial delivery per item (returns claim 9, true — proration by rounded ratio is removed).**
   `POST /delivery/stops/{id}/partial` takes `lines: [{lineNo, deliveredQty}]` (0 … line qty; at least one line
   short and at least one unit delivered, else `PARTIAL_LINES`). Total = Σ lines. A legacy call with only
   `deliveredQty` is accepted when the order has ONE line; with more lines → `LINES_REQUIRED`. FO / SO line
   quantities, the invoice and the return all use the driver's figures. The return's lines carry the batch: returned
   quantity of a line is taken from that line's pick tasks, last picked first, one return line per batch.
   A return is created only when it has lines. `PodPanel`: a quantity stepper per item (defaults to full).
   The same batch rule applies to the full return of a failed / rejected delivery.
5. **Trip dead ends found by the audit.**
   - A trip that started loading with a vehicle but no driver could never get one (assignment locked) and could never
     be dispatched or cancelled. `assign` now accepts a **driver** change while the trip is `loading | ready`
     (vehicle change stays refused there: `TRIP_LOADED_VEHICLE` "أنزل الحمولة أولًا" — W3 provides unload, after which
     the trip is editable again).
   - `POST /transport/trips/{number}/orders/{fo}/remove` (`trip.manage`) while the trip is editable or loading and the
     order is not loaded: the order leaves the trip, its stop is deleted and the sequence closed up, ETAs recomputed,
     trip totals recomputed. `POST …/orders/add {foNumber}` likewise (editable trips; same checks as create).
   - `unassign` and `assign` re-check the status under a row lock inside their transaction.
   - `driverCanAssign`: an expired iqama blocks like an expired licence.
6. `close()` calls `app(TripCostService::class)->compute($t)` inside its transaction, wrapped so that a costing error
   never blocks the close (report it as an activity line). The close message says cost was computed only when it was.
   Odometer at close: keep adding the trip km, but accept an optional `odometer` reading in the close body
   (≥ current) and prefer it.

## W3 — Picking, packing, loading, dispatch  ·  database `scm_ops_test_master`

Files: `app/Services/Fulfillment/*` (not `ScanCodes.php`), `app/Http/Controllers/Api/Fulfillment/*`,
`routes/api/fulfillment.php`; client `resources/js/pages/fulfillment/*`; tests
`tests/Feature/Sales/Fulfillment*Test.php` and new `tests/Feature/Fulfillment/*`.

1. **Short pick (claims 1 true, 2 partly true).** `shortPick` keeps reducing the FO line (the FO is what ships) and
   now: adds to `fo_lines.short_qty`; lowers the sales-order line's `reserved_qty` / `allocated_qty` and the
   reservation by the short quantity; then, for an order of ours (`source_system` null): `BackorderService::split`
   when `fulfillment.backorderShortPicks`, else `BackorderService::cancel`. For an order from another system: only
   `has_shortage`, and the `picking.completed` / a new `order.short` event carries `lines: [{sku, ordered, picked,
   short}]` whenever the order has a shortage (today the flag is lost when a later normal pick closes the list).
   Reason is structured: `notfound | damaged | expired | other` + note. With `fulfillment.blockBinOnShortPick` and
   reason `notfound`: the balance row is frozen (`InventoryService::setBlocked`) and the exception says so — the bin
   is not allocated again until a count releases it. An order whose every task ended short with nothing picked does
   NOT become `picked`: the FO is cancelled (`picking → cancelled`), the SO goes back to `confirmed`
   (`fulfil_status = backordered`) or is cancelled per the policy — never an empty shipment.
2. **Cartons (claim 3 true).** `pack` creates one `cartons` row per carton (`code = {FO number}-C{seq, 2 digits}`),
   optional per-carton weights (`cartonWeights: number[]`, else total ÷ n). `GET /fulfillment/orders/{id}` returns
   `cartons`. The FO page prints one label per carton ("1 / N", code as CODE128, customer, stop) with the existing
   bulk label helper. Cartons follow their order: `loaded` at load, `delivered` at delivery (W2 does not touch them —
   update them from the FO status in `load` / `unload`; delivered / returned are set by a small listener you add in
   `FulfillmentService` and that W2 does not need to call: derive on read if simpler). The pack message stops
   claiming a printed slip.
3. **Unpack and put-back (claim 4 true).**
   - `POST /fulfillment/orders/{id}/unpack` `{reason}` (`pack.confirm`; FO `packed`, not loaded): STG-OUT → PACK per
     batch (movement type `move`), package + cartons voided, staging entry cleared, FO and SO → `picked`.
   - `POST /fulfillment/orders/{id}/putback` `{reason, cancelOrder?: bool}` (`pick.override`; FO `picking | picked`):
     every picked quantity goes PACK → the bin it was picked from (type `restock`; when that bin is no longer usable
     the body may carry `bins: {taskId: binCode}`), tasks and pick list cancelled, FO `cancelled`, the order's
     reservations released, SO line counters reset; SO → `cancelled` when `cancelOrder` (then `CreditService::sync`),
     else → `confirmed` with `fulfil_status = backordered` so `allocate` can reserve it again.
   - Buttons on the FO page / pack row with a reason prompt.
4. **Scan override (claim 5 FALSE — typing the code is already possible for everyone; no change).** What the audit
   did find: the batch is never checked. When the task's bin holds more than one batch of the product with stock,
   `confirm` requires `scannedBatch` equal to the task's batch number (`BATCH_SCAN_REQUIRED`, `WRONG_BATCH`); the card
   shows the field only then. Fix the expiry check to compare dates (`Carbon::today()`, as allocation does), and
   eager-load `pickLists.tasks.batch` so the expiry shows on the card.
5. **Picker assignment (claim 7 true).** `POST /fulfillment/pick-lists/{id}/claim` (`pick.confirm`) — sets
   `assigned_to*`, `assigned_at` when free (`PICK_ASSIGNED` when another picker holds it);
   `POST …/release` (the holder or `pick.override`); `POST …/assign {username}` (`pick.override`). `pick` and
   `shortPick` on a list held by someone else → `PICK_ASSIGNED` unless `pick.override`; the first confirm on a free
   list claims it. `GET pick-lists?mine=1`. The pick tab shows who holds each list, a "قوائمي" filter and the claim /
   release buttons; `shortPick` stamps `picked_by` / `picked_at`.
6. **Edit a packed order (claim 8 true).** `PATCH /fulfillment/orders/{id}/package` `{cartons?, weightKg?, volumeM3?,
   cartonWeights?}` (`pack.confirm`; `packed`, not loaded): package + FO + cartons updated (cartons re-issued when the
   count changes), audited; when the order is on a trip the trip's kg / cbm / pallets are recomputed.
7. **Batch substitution (claim 10 true).** `POST /fulfillment/pick-tasks/{id}/substitute` `{reason: damaged | expired
   | notfound | other, note?}` (`pick.confirm`): the task's unpicked quantity is released from its row, the task is
   closed (`cancelled` when nothing was picked, else `done` at what was picked), and the same quantity is reserved
   again FEFO **excluding that row** (`reserve(... excludeRows)`), creating new tasks (`substituted = true`) on the same
   list. Nothing else available → the short-pick path of item 1. Reason `notfound` freezes the row as in item 1;
   `damaged` / `expired` raise an exception for the inventory controller.
8. **Outbound door / lane (claim 9 partly true — one STG-OUT area, a dock calendar nobody links).**
   `POST /fulfillment/trips/{trip}/dock` `{dockCode}` (`load.confirm`; trip not dispatched) sets `trips.dock_code`
   (a dock of the trip's warehouse of type out; `DOCK_BUSY` when another loading trip holds it). The loading plan
   returns it; the staging tab groups staged orders by trip → door; the dispatch page shows and sets the door.
9. **Unload (dispatch claim 1 true).** `POST /fulfillment/trips/{trip}/unload` `{foNumber, reason}` (`load.confirm`;
   trip `loading | ready`): movement type `unload` into STG-OUT per batch with `unitCost` = the load cost of that
   movement, `trip_orders.loaded = false`, loading line marked unloaded and plan totals fixed, FO and SO → `packed`,
   cartons → `packed`, staging entry re-opened. When nothing is left loaded: trip → `planned | vassigned | dassigned`
   (by what is assigned) and the vehicle `loading → assigned` through the state machine.
10. **Loading sequence (claim 2 partly true).** With `loading.enforceSequence`: an order cannot be loaded while an
    order of a LATER stop on the same trip is still `alloc | picking | picked | packed`-and-not-loaded → `LOAD_WAIT`,
    naming those orders and the two ways out (wait, or remove them from the trip). `overrideSequence: true` +
    `reason` is accepted from `load.override` and audited. `load_seq` in the plan is the real `trip_orders.load_seq`.
11. **Scans at loading (claims 5 true + audit).** `load` resolves `foNumber` and `scannedOrder` with `ScanCodes`
    (FO, SO, package, label, carton). With `loading.requireOrderScan`: a `scannedOrder` that resolves to this order
    is required (`SCAN_REQUIRED`), unless `load.override` + `reason`. The dispatch page's scan field loads the order
    it resolves to.
12. **Dispatch dialog and buttons (claims 3, 4 true).** The dialog counts separately: packed-but-not-loaded (blocks —
    the button is disabled and says which orders) and not-yet-packed (will leave the trip). "Confirm load" is disabled
    with the reason when the trip has no vehicle. Dispatch is disabled without a driver. One dispatch rule: the Trip
    Room's capacity tab and `assertCanDispatch` say what the endpoint does.
13. Multi-zone / wave picking (claim 6 true): **not built** — one list per order with location sequencing fits three
    warehouses and a handful of pickers; the real cold-chain rule (ambient → chilled → frozen last) is added to the
    sequence by W4 in `SalesService::fulfill`. Show the zone as a group header on the task list.

## W4 — Sales, credit, consolidation  ·  database `scm_ops_test_sales`

Files: `app/Services/Sales/SalesService.php`, `Shape.php`, `app/Http/Controllers/Api/Sales/*`, `routes/api/sales.php`,
customer-credit parts of `app/Services/Master/PartnersService.php`; one-line `CreditService::sync` hooks in
`app/Finance/Services/{CustomerInvoiceService,PaymentsService}.php`; one scheduled command in `routes/console.php`;
client `resources/js/pages/sales/*`; tests `tests/Feature/Sales/{SalesOrders,Quotations,Consolidations}Test.php` + new.

1. **Customer balance (claim 1 true).** `customers.balance` = `CreditService::exposure` (open receivables + open
   orders). The credit gate of `createOrder` is `CreditService::assertCanOrder`; remove `adjustBalance`; call
   `CreditService::sync(customerId)` after: order created / cancelled, invoice issued / cancelled, credit note,
   receipt recorded / voided (hooks in the finance services, after their own work, same transaction). Customer
   screens show limit · receivables · open orders · available. `scm:credit-sync` command rewrites every balance (run
   it once in the migration path: report it under NEEDS ARCHITECT for the release notes).
2. **Zero credit limit (claim 2 partly true — "0 = cash customer" is deliberate).** Behaviour by
   `sales.zeroCreditLimitUnlimited` (in `assertCanOrder`, already implemented). In `PartnersService`: switching a
   customer to cash terms / limit 0 while it has exposure is refused (`CUSTOMER_HAS_BALANCE`); lowering a limit below
   exposure stays refused with the real figure. The customer form says what 0 means under the current policy.
3. **Discount control (claim 4 true).** One rule for quotations and orders: a line discount above
   `sales.maxDiscountPct` needs `discount.approve`. Quotation: creation is allowed as `draft` by anyone with
   `sales.manage`; `sent` / `approved` require that a holder of `discount.approve` approved the discount
   (`POST /sales/quotations/{id}/approve-discount`, stamps `discount_approved_by`) — `DISCOUNT_APPROVAL` otherwise.
   Direct order: `DISCOUNT_APPROVAL` unless the user holds `discount.approve` (stamped) or the order comes from a
   quotation whose discount was approved. `GET /sales/policy` → `{maxDiscountPct, vatPct, allowBackorder,
   zeroCreditLimitUnlimited}`; the client stops hard-coding 30 / 15.
4. **Expired quotations (claim 6 true).** `approve`, `sent` and `convert` reject a quotation past `valid_until`
   (`QT_EXPIRED`) and move it to `expired`; `scm:quotations-expire` (daily) does the same for draft / sent / approved;
   `POST /sales/quotations/{id}/extend {validUntil}` (`sales.manage`) → `expired → draft` (or just a new date when not
   expired). The detail page shows the expiry state and disables Convert with the reason.
5. **Shortage at order time (claim 7 partly true).** `createOrder` accepts `allowBackorder: true` (only when
   `sales.allowBackorder`): each line reserves what is available; lines with a shortage are split with
   `BackorderService::split` (reason `no_stock`) into ONE backorder order. Nothing available at all → the order
   itself is created `confirmed` / `fulfil_status = backordered` with nothing reserved. Without the flag the order is
   refused as today, and the error details say what is available so the form can offer "قبول مع تعليق الباقي".
   `allocateOrder` reserves what it can: all → `allocated`; part → stays `confirmed` (`fulfil_status = partial`).
   `SalesService::retryBackorders(int $limit = 50)` tries waiting orders of ours oldest first; `scm:backorders-allocate`
   runs it every 10 minutes and after a GRN posts (report the GRN hook under NEEDS ARCHITECT). SO list: a "معلّقة"
   filter; SO detail: links between an order and its backorders, ordered vs shipped per line.
6. **Consolidation (claims 3, 5 partly true + audit).** Advancing skips cancelled orders (they are detached from the
   batch, audited). At `readypick` an order still waiting for stock is tried once (`allocateOrder`); if still short
   the error names it and the batch can go on without it: `POST …/orders/{so}/remove` is allowed in any state before
   `picking` and the UI shows Remove for two-order batches too; `POST /sales/consolidations/{id}/cancel` dissolves an
   `open | consolidating` batch. `addRemoveOrder` refuses an order that is in another batch or not `confirmed |
   allocated`, and a remove of an order that is not in this batch. Gates count `partial` / `failed` fulfilment orders
   as finished; a batch with no live order cannot advance (`OC_EMPTY`).
7. **Pick sequence (picking claim 6).** In `fulfill`, tasks sort by temperature first — ambient / hazmat, then
   chilled, then frozen last — then zone code, aisle, rack, bin.

## W5 — Returns, purchase returns, debit notes  ·  database `scm_ops_test_platform`

Files: `app/Services/Returns/*`, `app/Http/Controllers/Api/Returns/*`, `routes/api/returns.php`, new
`app/Finance/Services/DebitNoteService.php` (+ its read routes in `routes/api/finance.php` and a list in the finance
client only if small); client `resources/js/pages/returns/{Returns*,Return*,DecisionModal,shared.js,…}` (NOT the
`Transfer*` files — W6), new `resources/js/pages/procurement/PurchaseReturnsTab.vue` and its ONE tab registration in
`ProcurementPage.vue`; tests `tests/Feature/Flows/ReturnsFlowTest.php` + new.

1. **Receipt, inspection and decision per line (claims 1, 6 true; 4 partly true).**
   - `receive` takes optional `lines: [{lineNo, receivedQty}]` (0 … qty; default full). Only the received quantity
     enters RET-01; a shortfall raises an exception; a line with 0 is closed as not received.
   - `inspect` takes `findings?` and `lines: [{lineNo, inspectedQty, condition: good | damaged | expired | defective |
     opened, note?}]` and stores them; the columns the screen always showed empty are now real.
   - `decide` takes either the legacy `{decision, binCode?, note?}` (applies to every line; the bin is used only for
     lines it fits — others are placed automatically) or `lines: [{lineNo, parts: [{qty, decision, binCode?,
     batchNo?, expiryDate?, supplierCode?, note?}]}]` with Σ parts = received quantity of the line. One movement and
     one `return_decisions` row (with `line_id`) per part; the header `decision` is the single decision or `mixed`.
   - The modal: one row per line (split a line with "+ قرار آخر"), decision pills per row, bin per row with the
     hint that an empty bin means automatic placement by storage class.
2. **Batch on restock (claim 7 true).** A product with `tracks_expiry` is restocked only with a batch: the line's
   batch, or `batchNo` (+ `expiryDate` when the batch does not exist — it is then created) on the part →
   `BATCH_REQUIRED` under `returns.requireBatchOnRestock`. A batch number given at creation that does not exist for
   the product is rejected (`BATCH_NOT_FOUND`) instead of being stored as text.
3. **Cancel (claim 12 true).** `POST /returns/{id}/cancel {reason}` (`return.flow`): `pending | approved` →
   `cancelled`, holds released. A return created by a delivery (goods on the truck) can be cancelled only when its
   trip is closed and nothing was received — `RTN_ON_TRUCK` otherwise.
4. **Return to supplier from a customer return (claim 10a true).** The `sup` decision part takes `supplierCode`
   (default: the supplier of the batch's goods receipt, else the product's preferred supplier); no supplier →
   `SUPPLIER_REQUIRED` naming the field. The option is hidden when the user cannot choose one.
5. **Purchase return from stock + debit note (claims 10b, 11 partly true; audit: supplier and damaged returns were
   creating stock from nothing in RET-01).** A return of type `sup` or `dmg` whose lines carry `fromBin` is
   `source_mode = stock`: approval holds the quantity in its bin (`InventoryService::hold`, quarantined stock allowed);
   - `sup`: `POST /returns/{id}/ship {carrier?, note?}` (`return.flow`) → `postHold` with type `supret`, partner =
     supplier (Dr payable / Cr inventory at cost — already the costing rule), status `shipped`, then
     `DebitNoteService::issue` and `closed` when the note exists.
   - `dmg`: approval → `POST /returns/{id}/ship` moves bin → DMG-01 (`dmg`, internal) and closes.
   - The legacy path (no `fromBin`: goods physically arriving, received into RET-01) stays for customer / delivery
     returns only; `sup` / `dmg` without `fromBin` are refused (`FROM_BIN_REQUIRED`) so the books are never inflated.
   - `DebitNoteService::issue(ReturnOrder)`: number `DN-YYYY-NNNNN`; lines at the purchase price (`unit_price` on the
     return line, default the last PO price of that supplier for the product, else average cost); VAT at the PO's
     rate. Journal (one, idempotent `eventKey`): the stock movement already debited payable at cost, so the note posts
     the difference — Dr payable (price − cost + VAT) / Cr purchase price variance (price − cost) / Cr VAT input (VAT);
     a negative difference swaps the variance side. Appears on the supplier statement; `GET /finance/debit-notes`,
     `GET /finance/debit-notes/{number}`; cancelling reverses the journal.
   - `PurchaseReturnsTab` in the procurement page: list of `sup` returns with status and debit note; "مرتجع جديد":
     supplier → optional PO / GRN → lines picked from the stock rows of that supplier's receipts (bin, batch,
     available) with quantity and price; actions approve / ship / cancel by permission.
6. **Customer credit note (audit: unreachable).** A manual customer return may reference the delivery it returns
   (`foNumber` or `invoiceNumber`); receiving it reverses cost of sales and issues the credit note
   (`CustomerInvoiceService::creditFromReturn`) for the received quantity. Without a reference it is received at
   average cost against inventory adjustment as today, and the screen says no credit note will be issued.

## W6 — Transfers and stock moves  ·  database `scm_ops_test_inventory`

Files: `app/Services/Inventory/{TransfersService,InventoryOpsService}.php`,
`app/Http/Controllers/Api/Inventory/TransfersController.php`, transfer lines of `routes/api/inventory.php`; client
`resources/js/pages/inventory/TransferPage.vue`, `resources/js/pages/returns/{TransfersTab,TransferForm,
TransferLinesPicker}.vue`; tests `tests/Feature/Inventory/TransfersTest.php` + new.

1. **Reservation on approval (claim 2 true).** `approve` holds every line in its source bin
   (`InventoryService::hold`, owner `WarehouseTransfer`); a line that cannot be covered refuses the approval naming
   it. `reject` / `cancel` release. `ship` moves each hold with `postHold` (type `transit`) and stores the movement's
   unit cost in `transfer_lines.unit_cost`. The sentence on the tab is now true. `picking` / `ready` stay status steps.
2. **Receipts (claim 3 true).** `receive` is allowed in `transit` and `partial`; `receivedQty` is what arrives NOW
   (≤ remaining), accumulated in `received_qty`; `trf` movements carry `unitCost = transfer_lines.unit_cost`, so the
   in-transit account clears. Status `partial` until everything is received or the variance is resolved.
   `POST /inventory/transfers/{id}/resolve-variance` `{resolution: loss | returned, note, lines?: [{lineNo, toBin}]}`
   (`inventory.transfer.approve`): `loss` → one journal Dr inventory loss / Cr inventory in transit for
   Σ remaining × unit cost (idempotent key, stored in `variance_journal_id`), `variance_qty` per line; `returned` →
   the remainder is received back into the source warehouse (its original bin unless `toBin`). Then `received`.
   `close` on a transfer with an unresolved remainder is refused (`TRANSFER_VARIANCE_OPEN`). The exception raised at
   the short receipt is resolved by the resolution.
3. **Destination bin (claim 5a true).** `toBin` is optional at creation; `receive` lines accept `toBin`
   (`TO_BIN_REQUIRED` when neither exists). The bin is location-checked and must be active AT RECEIPT. The receipt
   form suggests the bin already holding the product, else the first bin of the right class. **Arrow (5b true):** the
   list shows `from → to` in an LTR box like the detail page; same fix for the stored move reference text.
4. **Carrier on the transfer (claim 8 true — the trip link itself is built by the architect after you finish).**
   `ship` accepts `{carrier?, waybill?, vehicleCode?, driverCode?}` and stores them; with `transfers.requireCarrier`
   one of (vehicle + driver) or carrier is required (`CARRIER_REQUIRED`). Expose them on the transfer and its page.
   Keep `ship` structured so a later caller can pass `tripId`.
5. **Back door (audit).** `POST /inventory/move` refuses a source in PACK / STG-OUT / STG-IN / RET-01 (stock that
   belongs to a document in progress): `MOVE_FROM_STAGING`, pointing to unpack / put-back / putaway.

## W7 — Receiving and procurement  ·  database `scm_ops_test_procurement`

Files: `app/Services/Inbound/*`, `app/Services/Procurement/*`, their controllers, `routes/api/{inbound,procurement}.php`;
client `resources/js/pages/inbound/*`, `resources/js/pages/procurement/*` (W5 adds one tab to `ProcurementPage.vue`);
tests `tests/Feature/Flows/{InboundFlow,BackorderShipment}Test.php`, `tests/Feature/Procurement/*` + new.

1. **Rejected goods (claim 1 true).** With `procurement.rejectedStaysOpen`: open quantity of a PO line =
   `qty − received − damaged` (rejected goods never entered and are still owed). The PO stays `partial`, a follow-up
   shipment can be opened for them, and "incoming" everywhere uses the same open quantity. Damaged stays received
   (it is in DMG-01 and is settled by a purchase return). `POST /procurement/po/{id}/close {reason}` (`po.cancel`):
   `partial → closed`, waiving what is open (`closed_*`, audit, activity to procurement + finance) — the action the
   state machine always had and nothing implemented. PO page: received / damaged / rejected / open shown as four
   figures, never "% received" that counts rejections.
2. **Several batches per receiving line (claim 2 true).** A GRN line may carry `batches: [{qty, batchNo, mfgDate?,
   expiryDate}]` for its accepted quantity (Σ = acceptedQty; `BATCH_SPLIT_SUM`); one `grn_lines` row per split
   (`split_no`), one batch, one putaway task and one `grn` movement each. Without `batches` the line behaves as
   today. A repeated `lineNo` in the payload is rejected instead of silently overwriting. `ReceiveLinesGrid` and the
   worker screen: "+ دفعة أخرى" under a line.
3. **Refuse at the gate (claim 3 true).** `POST /inbound/shipments/{id}/refuse {reason}` (`shipment.receive`):
   `arrived | inspecting` with no GRN → `cancelled` (`refused_*`), exception to procurement, the PO stays open and a
   follow-up shipment can be opened. `POST …/unarrive` (`shipment.receive`): `arrived → expected` for a wrong click.
4. **Void a goods receipt (claim 4 true).** `POST /inbound/grns/{id}/void {reason}` (`grn.void`). Allowed only while
   the receipt is untouched: every putaway task still open, damaged quantity still in DMG-01, no supplier invoice on
   the receipt's quantity → else `GRN_VOID_LATE` naming the blocker and pointing to a purchase return. Effects, one
   transaction: `grnvoid` movements out of STG-IN / DMG-01 at each receipt movement's unit cost, putaway tasks and
   staging entries cancelled, PO-line and shipment-line counters and the PO status / open quantity rolled back,
   shipment `putaway → inspecting` so it can be received correctly, GRN stamped `voided_*`, the exceptions the GRN
   raised resolved with a note. A voided GRN is excluded from lists' totals and from invoice matching.
5. **Split putaway (claim 5 true).** `confirm` takes optional `qty` (1 … remaining): that quantity moves to the
   scanned bin, `done_qty` accumulates, the task stays open until done; the shipment is `done` when no quantity
   remains. Fix found by the audit: staging entries are cleared by their own id / reference, not by matching `qty`.
6. **Charges on partial invoices (claim 6 partly true + a worse finding).** Expected discount and shipping of an
   invoice = the PO's figure minus what earlier non-cancelled invoices of that PO already carried. Billing more than
   the remainder (beyond `procurement.invoiceTolerancePct` of the PO figure) → variance `charges` (today a repeated
   full shipping charge passes as "matched" and is paid twice); billing less is no variance. The form prefills the
   remainder.
7. **Price control from requisition to order (claim 7 partly true).** `to-po` stores each line's requisition estimate
   in `po_lines.pr_est_price`; a price above the estimate by more than `procurement.prPriceTolerancePct` requires
   `priceReason` (`PR_PRICE_REASON`), sets `price_changed` + `price_note`, and adds `finance` to the approval chain
   when it is not there. The conversion locks the requisition row (two users cannot convert it twice). The drawer
   shows estimate, new price and % change per line and reads the supplier-score limit from the policy; the PO page
   shows the price-change banner to approvers.
