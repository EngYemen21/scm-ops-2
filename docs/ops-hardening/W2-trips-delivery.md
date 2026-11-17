# W2 — Trips and delivery execution · report

Database used: `scm_ops_test_flows`. Last run of the W2 test files: **49 tests · 1,380 assertions · all passed**
(`TripsTest` 17, `TransportMapTest` 7, `DeliveryFlowTest` 11, `PhoneTrackingTest` 4, `IntegrationsTest` 10).
`vendor/bin/pint` passed on every PHP file changed; `node tools/check-vue.mjs resources/js/pages/transport` → OK (53 files).
The client was checked statically only — no build was run (§0), so nothing was opened in a browser.

## Items

| # | Item | State | Note |
|---|---|---|---|
| 1 | POD signature / photo are stored | **DONE** | `DatabaseStorage` (table `stored_files`) is what `AdapterFactory::storage()` returns without S3; `closeStop` decodes, validates and stores both files and writes `pod_attachments` with `stored` / `uploaded`, key, mime, size; `GET /integrations/files/{key}`; the client compresses the photo and shows both files; the "Integration Pending" texts of the POD are gone. |
| 2 | Planned arrival per stop | **DONE** | `TripsService::recomputeEtas()`; `eta`, `eta_source`, `window_ok` per stop, `planned_end` on the trip; called after create / reorder (so optimise too) / assign / add / remove order; window never touched; stops screen shows window and planned arrival separately, flags late stops, has ▲ ▼. |
| 3 | Scan and signature at delivery | **DONE** | `scans`, `noScanReason`, `deliveredCartons`; `SCAN_REQUIRED`, `WRONG_PACKAGE` (+ exception `wrong`), `SIGNATURE_REQUIRED`, `SIGNATURE_REASON`, `CARTONS_EXCEED`; both policies on and off are tested. |
| 4 | Partial delivery per item | **DONE** | `lines: [{lineNo, deliveredQty}]`; `PARTIAL_LINES`, `LINES_REQUIRED`; invoice, FO / SO lines and the return use the driver's figures; return lines carry the batch (pick tasks, last picked first), also for failed / rejected; no return without lines. |
| 5 | Trip dead ends | **DONE** | driver may be assigned / replaced / dropped while `loading | ready` (`TRIP_LOADED_VEHICLE` for the vehicle); `orders/add`, `orders/{fo}/remove`; row locks in assign / unassign / reorder / add / remove / cancel / close; expired iqama blocks assignment. |
| 6 | `close()` cost hook, texts, odometer | **DONE** | `TripCostService::compute` in a savepoint inside the close transaction; a costing error is an activity line, never a failed close; the message claims a cost only when there is one; optional `odometer` in the body. |
| — | `assertCanDispatch` / `TripLoad.vue` = the endpoint's rule | **DONE** | `TripsService::dispatchGate()` (data) + `assertCanDispatch()` (its first blocker); the Trip Room shows the server's blockers; when the fulfilment domain's `LoadingService::dispatchState` refuses for a reason the gate does not list, that reason becomes the blocker. |

## Endpoints

| Method · path | Body | Permission | Error codes |
|---|---|---|---|
| **new** `GET /integrations/files/{key}` (`?as=data` → JSON `{key, fileName, kind, mime, size, dataUrl}`) | — | signed in; a driver only for PODs of his own trips | `FILE_NOT_FOUND` 404, `NOT_YOUR_TRIP` 403, `STORAGE_UNAVAILABLE` 503 |
| **new** `POST /delivery/stops/{id}/scan` | `{code}` → `{code, match, order}` | `delivery.execute` | `WRONG_PACKAGE` 422 (raises the exception), `ARRIVE_FIRST`, `POD_DUPLICATE`, `TRIP_STATE`, `TRANSFER_STOP`, `NOT_YOUR_TRIP` |
| changed `POST /delivery/stops/{id}/deliver` | + `scans: string[]`, `noScanReason`, `deliveredCartons`; `signature` / `photo` must be real images (data URL or base64) | `delivery.execute` | + `SCAN_REQUIRED` 422, `WRONG_PACKAGE` 422, `SIGNATURE_REQUIRED` 422, `SIGNATURE_REASON` 422, `ATTACHMENT_TOO_LARGE` 400, `ATTACHMENT_INVALID` 400, `CARTONS_EXCEED` 400, `TRANSFER_STOP` 422 |
| changed `POST /delivery/stops/{id}/partial` | the same + `lines: [{lineNo, deliveredQty}]` (a line that is not listed = delivered in full) or the old `deliveredQty` for a one-line order | `delivery.execute` | + `PARTIAL_LINES` 400, `LINES_REQUIRED` 400 (`PARTIAL_QTY` kept for the single figure) |
| changed `POST /delivery/stops/{id}/fail` | unchanged | `delivery.execute` | + `TRANSFER_STOP`; the return now has one line per batch |
| changed `GET /delivery/my-trips`, `GET /delivery/trips/{id}` | — | — | response: `policy {requireScan, requireSignature, noScanReasons, …}`, `onBehalf`, `eta {status: planned | unavailable, time, source, windowOk, stop}` (was `integration_pending`), stops carry `eta / etaSource / windowOk`, `pod.attachments`, `pod.scanOk` |
| **new** `POST /transport/trips/{number}/orders/add` | `{foNumber}` | `trip.manage` | `FO_NOT_FOUND` 404, `FO_NOT_READY`, `FO_OTHER_WH`, `FO_ON_TRIP`, `VEHICLE_UNFIT`, `TRIP_LOCKED` |
| **new** `POST /transport/trips/{number}/orders/{fo}/remove` | `{reason?}` | `trip.manage` | `FO_NOT_ON_TRIP`, `ORDER_LOADED`, `TRIP_LAST_ORDER`, `TRIP_LOCKED` |
| changed `POST /transport/trips/{number}/assign` | unchanged | `trip.assign` | driver accepted while `loading | ready`; vehicle there → `TRIP_LOADED_VEHICLE` 422 |
| changed `POST /transport/trips/{number}/unassign` | `{vehicle?, driver?}` | `trip.assign` | **behaviour**: no flag = both (as before); ONE flag now leaves the other side alone (it used to drop both); `TRIP_LOADED_VEHICLE` while loading |
| changed `POST /transport/trips/{number}/close` | `{odometer?}` | `trip.close` | `ODOMETER_BACKWARDS` 422; response + `message`, `messageEn`, `costComputed` |
| changed `GET /transport/trips/{number}` | — | — | + `canAssignDriver`, `canRemoveOrders`, `dispatchGate {ok, blockers[{code, ar, en}], loaded, packedNotLoaded, leaving}`, `plannedEnd`, stops with `eta / etaSource / windowOk` and `pod.attachments` |
| changed `GET /transport/trips` | — | — | rows + `plannedEta {next, source, late}`, `progress {done, total}` |
| changed `POST /integrations/attachments`, `POST /integrations/pods/{id}/attachments` | unchanged | unchanged | rows are `stored` (database) or `uploaded` (S3) with `storageKey` + `url`; never `integration_pending` |

## Rules worth knowing

- **Storage.** `AdapterFactory::storage()` = S3 when its four variables are set, else `DatabaseStorage`. A row says where its
  file lives (`uploaded` | `stored`) and `FileStore::read` follows the row, so files written before S3 is connected stay
  readable. In `closeStop` a failing object store does not fail the delivery: the file goes to the database (a POD is
  taken once). The generic attachment endpoints still answer `STORAGE_UPLOAD_FAILED`. Only PNG / JPEG / WebP / GIF are
  accepted for a POD, recognised from the bytes; limits 400 KB / 2 MB. The integrations status row for storage stays
  `integration_pending` (the provider) with the detail "files are kept in the database".
- **Planned arrival.** Start = `planned_start`; legs from the maps adapter (the same request and cache key as the trip
  map — one provider call serves both) else straight line × 1.3 ÷ `fleet.avgSpeedKph`; + `delivery.serviceMinutes` per
  stop. A stop without coordinates gets no eta (its service time still passes); a warehouse without coordinates → no
  eta at all; skipped stops are not planned; an arrival after midnight reads `00:10 +1` and misses its window.
- **Proof.** State checks → quantities → files → signature → scan. Everything that can refuse runs before the
  transaction, so the `wrong` exception survives the refusal (one exception per stop and code). `scan_ok`: true = a
  code identified the order, false = delivered on a reason / no matching code, null = nothing scanned and the policy off.
- **Lock order.** Orders (by id) → trip → trip order → vehicle — the order loading and dispatch use.

## Files

Server: `app/Services/Transport/TripsService.php`, `app/Services/Delivery/DeliveryService.php`,
`app/Http/Controllers/Api/Transport/TripsController.php`, `app/Http/Controllers/Api/Delivery/DeliveryController.php`,
`app/Http/Controllers/Api/Integrations/IntegrationsController.php`, `app/Services/Integrations/IntegrationsService.php`,
`app/Services/Integrations/FileStore.php` (new), `app/Services/Integrations/Adapters/{DatabaseStorage,ReadableStorage}.php`
(new), `…/Adapters/{AdapterFactory,S3CompatibleStorage,PendingStorage}.php`, `routes/api/{transport (3 trip lines),delivery,integrations}.php`.

Client (`resources/js/pages/transport/`): `PodPanel.vue`, `DriverStopCard.vue`, `DriverPage.vue`, `FileToBase64.vue`,
`SignaturePad.vue` (comment), `PodFiles.vue` (new), `TripStops.vue`, `TripPods.vue`, `TripAssign.vue`, `TripLoad.vue`,
`TripHead.vue`, `TripTable.vue`, `TripsPage.vue`, `tmsComposables.js`, `tms.js` (exports added only).

Tests: `tests/Feature/Flows/DeliveryFlowTest.php`, `tests/Feature/Flows/DeliveryFixtures.php` (new trait),
`tests/Feature/Transport/TripsTest.php`, `tests/Feature/Transport/TransportMapTest.php`, `tests/Feature/Platform/IntegrationsTest.php`.

## Tests rewritten because they pinned the old behaviour

- `DeliveryFlowTest`: attachments `integration_pending` → `stored` with bytes read back; the 60 / 40 proportional split
  (48 / 32) → per-line figures; `eta: integration_pending` → `unavailable` / `planned`; every delivery now carries a
  scan and a signature.
- `TripsTest`: "assignment is locked once loading started" → driver change accepted, vehicle → `TRIP_LOADED_VEHICLE`;
  `test_dispatch_gate_for_the_fulfillment_domain` → the endpoint's rule.
- `TransportMapTest`: trips are created before the maps provider is configured (creating a trip now plans its arrivals,
  which would otherwise call the provider before `Http::fake`).
- `IntegrationsTest`: three assertions on `integration_pending` storage → `stored` (+ the file is read back).

## NEEDS ARCHITECT

1. **Other suites that deliver without a proof** (not mine): replace `'signature' => 'sig.png'` / `'photo' => 'door.jpg'`
   by a real image (`Tests\Feature\Flows\DeliveryFixtures::signatureImage()`), add `'scans' => [<FO number>]`:
   `AcceptanceTest.php:215` (and `:217` expects `'stored'`, not `'integration_pending'`), `Finance/FinanceFlowTest.php:181`,
   `Integration/ControlTowerTest.php:233`, `Integration/OrderJourneyTest.php:128`. Their orders have one line, so the
   single `deliveredQty` still works.
2. **Dispatch endpoint and the iqama** — `LoadingService::dispatchState` checks blocked / off shift / licence only. The gate
   also refuses an expired iqama (W2 item 5). Use `TripsService::driverDispatchBlock($driver)` there (public, returns
   `[ar, en] | null`) so the two cannot differ. Until then the Trip Room can say "blocked" for an expired iqama while
   the endpoint lets the trip go.
3. **Planned arrivals after dispatch** — orders that leave the trip at dispatch become `skipped` stops; call
   `app(TripsService::class)->recomputeEtas($trip)` after the dispatch transaction (skipped stops are ignored by it).
4. **Map markers** — `TransportMapService::stops()` could add `eta` / `windowOk` so the map popups show the plan (optional).
5. **`disp` has no `delivery.execute`** — "recording on behalf of the driver" (the `SIGNATURE_REASON` path) is therefore
   reachable by `super` only. Grant it to `disp` if dispatch is meant to record deliveries.
6. Build the client and run the UI sweep: `PodPanel`, `TripStops` (reorder / add / remove), `PodFiles` viewer.

## Assumptions the business should confirm

- A planned arrival **before** the window opens counts as outside the window (the driver would wait).
- A scan of **another order's** goods is refused even when `delivery.requireScan` is off.
- A file name instead of an image (`sig.png`) is refused: a signature that was never captured is not a signature.
- A line that is not listed in a partial delivery was delivered in full.
- The last order of a trip cannot be removed — the trip is cancelled instead.
- Removing an order never lowers the trip's temperature need; adding frozen / chilled goods raises it.
- POD files in the database cost roughly 0.2–0.5 MB per delivery; connect object storage before the volume grows.
- A cost sheet of zeros is announced as "no cost recorded", not as a computed cost.
