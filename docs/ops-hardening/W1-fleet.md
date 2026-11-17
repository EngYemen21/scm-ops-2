# W1 — Fleet master data, corrections, trip cost · report

Database `scm_ops_test_transport`. Last run of my test files:
`tests/Feature/Transport/{FleetTest,FleetCorrectionsTest,TripCostTest}.php` → **24 tests passed, 1,582 assertions**.
`vendor/bin/pint` clean on every PHP file I touched · `node tools/check-vue.mjs resources/js/pages/transport` → OK (53 files).
The client was checked statically only (the checker + a template-binding check): §0 forbids building and starting
servers, so **no screen was opened in a browser** — see NEEDS ARCHITECT 1.

## Status per item

| # | Item | Status | Note |
|---|---|---|---|
| 1 | Trip cost engine | DONE | `TripCostService::compute` (all components + `basis` + `computed_at`), `rebuildTripCosts` delegates, recompute endpoint, `TripCost.vue`, trip field on the fuel form, cost fields on the vehicle / driver forms |
| 2 | Vehicle edit UI + odometer rule | DONE | `VehicleForm` edit mode, "تجديد الوثائق" on the documents tab, `VEHICLE_ODOMETER_LOWER` + `VEHICLE.ODOMETER_CORRECTION` |
| 3 | Driver edit UI + rule/text agreement | DONE | `DriverForm` edit mode; the block follows licence + iqama, the medical certificate only warns; alert texts say exactly that. W2 has already added the iqama check to `driverCanAssign` (two small follow-ups under NEEDS ARCHITECT 3) |
| 4 | Fuel / maintenance corrections | DONE | PATCH + void for fuel, PATCH + cancel for maintenance, row actions, struck rows, the ops-request fuel fix |
| 5 | "opCardExpiry conflict", "driver form lacks medical / default vehicle" | no change, as decided | — |

## What was built

**Trip cost sheet.** `TripCostService::compute(Trip|string)` always re-reads the stored trip, never throws for missing
master data, posts no journal, and writes `trip_costs` + `computed_at` + `basis` (`{source, detail}` per component):

| Component | Figure | `source` |
|---|---|---|
| fuel | Σ cost of non-voided fuel records with the trip's id | actual |
| | none linked, trip has km, vehicle has ≥ 2 live fuel records → km ÷ km/L × last price per litre | estimated |
| driver / ot | hours (actual_end − actual_start) × `drivers.hourly_cost` ?? `fleet.driverHourlyCost`; beyond `fleet.overtimeAfterHours` × `fleet.overtimeMultiplier` | actual |
| maint | posted maintenance vouchers on the trip + km × rate (vehicle's closed maintenance cost of the 365 days before the trip ended ÷ km of its closed trips in that window when ≥ 500 km, else `fleet.maintCostPerKm`) | allocated (actual when only vouchers) |
| dep | km × (purchase − salvage) ÷ life km from the vehicle card, else km × `fleet.depreciationPerKm` | allocated |
| tolls / other | posted vouchers on the trip: account role `fleet_other` → tolls, anything else but maintenance → other | actual |
| parking / third | 0 — the chart has no account role that tells them apart | not_configured (`no_account_role`) |

A component without a data source is 0 with `source: not_configured` and `detail.reason`
(`no_vehicle | no_driver | no_km | no_times | no_rate | no_history | no_account_role`). The sheet is rebuilt at trip
close (W2 calls `compute`), whenever a fuel record or an expense voucher of the trip is created / corrected / voided
(`FleetPosting::rebuildTripCosts` → `compute`, the one implementation; `ExpensesService` untouched), and on demand.

**Fuel.** One path for every fuel record (`FleetService::recordFuel`): the form and a processed driver request both go
through it. Every record stores `prev_odometer` (the vehicle's reading before it). km/L is measured from the fill
before it (the vehicle's previous reading for the first fill). Correction and void keep the chain consistent: the
record and the next fill are re-measured, the anomaly alert is raised / cleared, the vehicle odometer follows when the
record carried its reading, the journal is reversed (+ re-posted as `fuel:{id}:v{n}` for a correction), the trips'
cost sheets are rebuilt. A voided record stays listed (struck) and counts in no total, anomaly filter, KPI, average or
trip cost.

**Maintenance.** Open orders are edited (one audit row per field) or cancelled with a reason; a cancelled order posts
nothing, cannot be closed, and releases the vehicle from «maintenance» through the state machine when no other order
is open. Lists show open → closed → cancelled.

**Driver requests.** Processing a fuel request records a real fuel record: the amount is the cost, it is posted to
the ledger and charged to the request's trip (when the trip runs on that vehicle); litres and the odometer are taken
only when the person processing types them from the receipt — otherwise litres 0, `full = false`, no km/L. Processing
a maint / tire / vehicle request opens the order through the same path as the form; the workshop is what the processor
typed or empty (never «—»), and can be set later by editing the order.

**Vehicles / drivers.** Edit forms send only what changed. Odometer: a lower reading needs `odometerReason`. Renewing
a document re-evaluates that vehicle's / driver's alerts in the same call. Driver block: licence under
`fleet.driverLicenseMinDays` or an expired iqama → blocked (at the sweep, at creation and at every document edit),
lifted automatically when the document is renewed, and not liftable by hand while the document still blocks.

## Endpoints

| Method · path | Body | Permission | Errors |
|---|---|---|---|
| **POST** `/transport/trips/{number}/cost/recompute` (new) | — | `trip.close` | `TRIP_NOT_FOUND` 404 |
| **PATCH** `/transport/fuel/{number}` (new) | `{liters?, cost?, odometer?, station?, date?, tripNumber?, reason}` | `fuel.manage` | `INVALID_INPUT` (reason missing / < 3 chars), `REASON_REQUIRED`, `FUEL_NOT_FOUND` 404, `FUEL_VOIDED`, `FUEL_ODOMETER_BELOW_PREVIOUS`, `FUEL_ODOMETER_ABOVE_NEXT`, `FUEL_PRICE`, `BAD_DATE`, `TRIP_NOT_FOUND` 404, `FUEL_TRIP_VEHICLE`, `PERIOD_CLOSED` (from the ledger) |
| **POST** `/transport/fuel/{number}/void` (new) | `{reason}` | `fuel.manage` | `INVALID_INPUT`, `FUEL_NOT_FOUND` 404, `FUEL_VOIDED`, `PERIOD_CLOSED` |
| **PATCH** `/transport/maintenance/{number}` (new) | `{kind?, desc?, shop?, startDate?, odometer?, cost?, downDays?, nextKm?}` | `maintenance.manage` | `MAINT_NOT_FOUND` 404, `MAINT_NOT_OPEN`, `BAD_DATE` |
| **POST** `/transport/maintenance/{number}/cancel` (new) | `{reason}` | `maintenance.manage` | `INVALID_INPUT`, `MAINT_NOT_FOUND` 404, `MAINT_NOT_OPEN` |
| POST `/transport/maintenance/{number}/close` (changed) | — | — | + `MAINT_CANCELLED` |
| GET `/transport/maintenance` (changed) | `status=cancelled` accepted; order open → closed → cancelled | open | — |
| GET `/transport/fuel` (changed) | + `status=active\|voided`, `trip=<number>`; rows carry `tripNumber`, `voided*`, `corrected*`; envelope + `totals {records, liters, cost, voided}`; `anomaly=true` skips voided | open | — |
| POST `/transport/fuel` (changed) | `tripNumber` is now checked | `fuel.manage` | + `TRIP_NOT_FOUND` 404, `FUEL_TRIP_VEHICLE` (an unknown trip used to be ignored silently) |
| POST / PATCH `/transport/vehicles[/{code}]` (changed) | + `purchaseCost`, `salvageValue`, `lifeKm`; PATCH + `odometerReason` | `vehicle.manage` | + `VEHICLE_ODOMETER_LOWER` 422, `VEHICLE_SALVAGE` 400 |
| POST / PATCH `/transport/drivers[/{code}]` (changed) | + `hourlyCost` | `driver.manage` | PATCH + `DRIVER_DOCS_BLOCK` 422 (`blocked:false` while a document blocks) |
| GET `/transport/drivers[/{code}]` (changed) | + `docBlock {doc, days, ar, en} \| null`, `docs[].effect: blocks \| warns` | open | — |
| POST `/transport/ops-requests/{number}/status` (changed) | + `liters?`, `odometer?` (fuel), `shop?` (maint / tire / vehicle) when `to = processed` | `opreq.manage` | + `FUEL_ODOMETER`, `FUEL_PRICE` (the request stays where it was) |

There is no DELETE on fuel or maintenance on purpose (405, tested). Audit actions added: `TRIP.COST_RECOMPUTE`,
`FUEL.CORRECT`, `FUEL.VOID`, `MAINT.UPDATE`, `MAINT.CANCEL`, `VEHICLE.ODOMETER_CORRECTION`.

## Files

Server: `app/Services/Transport/TripCostService.php` (implemented), `app/Services/Transport/FleetService.php`,
`app/Finance/Services/FleetPosting.php`, `app/Http/Controllers/Api/Transport/FleetController.php`,
`routes/api/transport.php` (fleet lines + the recompute line only),
**new** `database/migrations/2026_11_15_000010_w1_extra.php` (`fuel_records.prev_odometer`, additive, nullable),
`app/Models/FuelRecord.php` (one cast for that column).

Client (`resources/js/pages/transport/`): `TripCost.vue`, `FuelForm.vue`, `MaintenanceForm.vue`,
`FleetMaintFuelTab.vue`, `VehicleForm.vue`, `VehicleDrawer.vue`, `DriverForm.vue`, `DriverDrawer.vue`,
`FleetDriversTab.vue`, `FleetOpsRequestsTab.vue`, `FleetPage.vue`; **new** `fleet.js` (labels + pure helpers — kept
out of `tms.js` so two workstreams never edit one file), `FuelTripField.vue`, `OpsRequestProcessModal.vue`.

Tests: `tests/Feature/Transport/FleetTest.php` (11), **new** `FleetCorrectionsTest.php` (7), **new** `TripCostTest.php` (6).

Tests rewritten because they pinned the old behaviour (`FleetTest::test_ops_requests_flow`):
- "233 SAR ÷ 2.33 = 100 litres" → litres 0, `full = false`, no km/L, cost 233, a journal exists;
- the maintenance order of a tire request: `shop` is null, not «—».
Added to `FleetTest::test_permission_denied…`: the five new endpoints.

## NEEDS ARCHITECT

1. **Browser pass.** Nothing of the client was opened in a browser. After the build, please run the UI sweep on:
   `/fleet` (all tabs; maintenance / fuel row actions: edit, cancel, correct, void), the vehicle drawer ("تعديل",
   documents tab → "تجديد الوثائق", struck voided fuel), the driver drawer ("تعديل", documents tab, disabled unblock),
   the ops-request "معالجة" dialog, the trip room → "التكلفة" tab ("إعادة الاحتساب").
2. **`app/Services/Platform/ReportsService.php` ~362–364** (fleet report) still sums voided fuel and cancelled
   maintenance: add `AND fr.voided_at IS NULL` to the fuel sub-query and `AND mo.status <> 'cancelled'` to the
   maintenance one.
3. **`TripsService::driverCanAssign` (W2)** — the iqama check is in. Two leftovers:
   (a) the reason of a blocked driver still reads «وثائق السائق تنتهي قريبًا — الإسناد موقوف», which is wrong for a
   block set by hand; suggested: `السائق موقوف عن الإسناد` / `Driver is blocked from assignment` (my screens already
   show the real cause from `docBlock`);
   (b) a licence under `fleet.driverLicenseMinDays` blocks only once the sweep (or a document edit) has set
   `blocked`. Exact rule to make it immediate: `$lic !== null && $lic < (int) $settings->get('fleet.driverLicenseMinDays')`
   → not assignable. And the sweep is not scheduled anywhere: it runs when the alert list is opened (at most every
   5 min), on "generate", and now on every driver creation / document edit — a daily `schedule` entry in
   `routes/console.php` calling `FleetService::refreshDocumentAlerts()` would close the gap.
4. **Extra migration + model cast** (allowed by §0, listed for the merge): `2026_11_15_000010_w1_extra.php`,
   `app/Models/FuelRecord.php`.
5. **Odometer at trip close vs. fuel readings (W2's `close`).** Closing a trip adds the planned km to the vehicle
   odometer. When that overshoots the real reading, the next real fuel reading is refused by `FUEL_ODOMETER`. The way
   out exists now (vehicle "تعديل" → lower the odometer with a reason), but the business may prefer the close not to
   move the odometer of a vehicle that has fuel readings.
6. Optional tidy-up: `tms.js` `MAINT_STATUS_LABELS` has no `cancelled`; my screens use `MAINT_STATE_LABELS` from
   `fleet.js`.

## Assumptions for the business to confirm

1. **km/L is measured between fills** (this record's reading − the previous fill's), not from the vehicle's current
   odometer as before. With the old base every fill after a closed trip looked like an anomaly (the close had already
   moved the odometer). Same result as before whenever no trip was closed between two fills.
2. **Fuel estimate**: the vehicle's consumption is the litre-weighted km/L of its own live fills (the vehicle card's
   average only when no fill measured a distance); the price is the latest fill's.
3. **Maintenance history window** ends when the trip ended (a later recomputation gives the same figure); a vehicle
   with ≥ 500 km of history and no closed maintenance gets a real 0 "from history", not the policy rate.
4. **Depreciation policy rate** (`fleet.depreciationPerKm`) applies to any vehicle without a cost card, rented and
   third-party vehicles included. Finance already keeps purchase cost / salvage per vehicle in the fixed-asset
   register; the vehicle card is a second place by design of the spec.
5. **Tolls** = every voucher on account role `fleet_other` (tolls, parking and other fleet costs are one account);
   parking and third-party stay 0 until the chart separates them.
6. A driver's **hourly cost of 0** means 0 (it does not fall back to the policy); empty means "use the policy".
7. **Re-posting**: a fuel correction reverses and re-posts when the amount, the **day** or the **trip** changes (the
   spec names the amount; the day decides the period and the trip is a ledger dimension). The reversal is dated today,
   the new journal on the record's date; a closed period refuses the correction. A record that never reached the
   ledger (written before finance went live) is corrected / voided without touching it.
8. **Reasons** need at least 3 characters.
9. A fuel record can only be charged to a **trip that runs on the same vehicle** (`FUEL_TRIP_VEHICLE`).
10. **Fuel correction bounds**: not below the previous fuel record, not above the next one (the vehicle odometer never
    drops below the reading it had before the record through a fuel correction; lowering it further is the audited
    vehicle correction).
11. **Driver block**: an expired iqama now blocks like the licence; a block caused by a document cannot be lifted by
    hand (`DRIVER_DOCS_BLOCK`) and lifts itself on renewal; a driver created with an expired iqama starts blocked.
    A documents alert is critical only for licence / iqama — the medical certificate alone stays a warning.
12. **Cancelling a maintenance order** does not clear the vehicle's maintenance-due alert (the service was not done).
13. **Driver fuel request**: litres and odometer are typed by whoever processes the request (the request itself has
    no such fields); without them the fill is cost-only.
