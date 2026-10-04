# B2B Integration — Runbook

How to switch the Sales ↔ OPS integration on, watch it, repair it and switch it off.
Design: [ARCHITECTURE.md](ARCHITECTURE.md). Code: `app/Integration/` (OPS), `api/_lib/integration.js` (Sales).

The integration is **off by default on both sides**. With it off, both systems behave exactly as before.

Lesson from the first live run: a signed path must not contain a raw comma — Vercel re-encodes it (`,` → `%2C`) before
the request reaches the application, so the signature no longer matches. Lists in query strings are sent encoded.

---

## 1. Go-live gate (read first)

Go-live is a sequence of gates; each must be true before the next step.

| # | Gate | State (2026-10-04) |
|---|---|---|
| 1 | **Sales sign-in is hardened** — registered account (phone + 4-digit PIN with lockout; SMS OTP later), role and tenant from the account record, `/api/state` scoped to the tenant, ownership checked on every command | **done** — built and tested in Sales (`docs/SECURITY.md`, 93 HTTP checks in CI, upgrade rehearsed on the previous schema), merged to `master` with the owner's approval |
| 2 | **Sales production is on that version** — deploy, then `POST /api/admin/migrate` (additive + data upgrade), then sign in once per account to replace the temporary PIN | **deployed and migrated** (old OTP/role-pick actions answer 400, `/api/state` 401 without a session, migration idempotent); the owner still has to sign in to each seed account once |
| 3 | **Stage 1 — preparation** (`OPS_INTEGRATION_ORDERS=false` on Sales): customers and products flow to OPS, availability flows back; **orders stay manual in Sales** | **live since 2026-10-04** — signed health 200 (wrong secret 401), first cycle delivered 11 customers + 28 products (all processed, 13 delivery sites), availability refreshed for 28 products, GitHub Actions heartbeat every 5 minutes green |
| 4 | **Products mapped and stocked** — every Sales product linked to its OPS SKU in برج التكامل → ربط الأصناف, with stock received in OPS | **done as a pilot (2026-10-04, owner's choice)** — the 28 Sales products did not exist in OPS, so they were created there and linked (`scm:integration-adopt-products`), with *estimated* weight/dimensions and a **trial opening balance of 100 each in RYD**; Sales reads ATP 100 for all 28. Before real operation: correct the attributes and replace the trial balances by a stock count |
| 5 | **Stage 2 — operation** (`OPS_INTEGRATION_ORDERS=true`, `OPS_INTEGRATION_SINCE=<switch date>`): approved orders reserve stock in OPS and are fulfilled there | **on since 2026-10-04T13:43Z** (`OPS_INTEGRATION_SINCE`): orders approved after that moment go to OPS; older ones stay manual. Verified by signed cycles (nothing old was sent, availability refreshed). **First live order verified the same day:** ORD-2497 placed in Sales by the owner account → OPS `SO-2026-00126`, reserved FEFO in RYD (ATP 100 → 99), `order.accepted` + `order.reserved` applied in Sales and shown in the order (desktop and mobile). Still to be exercised live: picking → dispatch → delivery/POD → customer receipt (proven only locally by the E2E) |

Why the gates matter: before gate 1 an anonymous visitor could approve an order, which with the integration on reserves
real stock; before gate 4 an order would wait in OPS as "product not mapped" while Sales has already disabled its manual
fulfilment buttons for it.

Enabling locally (§6) has none of these risks.

## 2. Configuration

Secrets live only in environment variables — never in the repositories (the Sales repository is public).

Generate one secret per direction-pair (the same value is set on both sides) and one for the scheduler:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

**OPS** (`config/integration.php`):

| Variable | Value |
|---|---|
| `INTEGRATION_SALES_ENABLED` | `true` |
| `INTEGRATION_KEYS_SALES` | `k1:<secret>` (during a rotation: `k2:<new>,k1:<old>` — the first pair signs outgoing calls) |
| `INTEGRATION_SALES_EVENTS_URL` | `https://<sales-host>/api/integration/events` |
| `INTEGRATION_SALES_CYCLE_URL` | `https://<sales-host>/api/integration/run` |
| `INTEGRATION_SALES_RECONCILE_URL` | `https://<sales-host>/api/integration/orders` |
| `INTEGRATION_SCHEDULER_ENABLED` / `INTEGRATION_KEYS_SCHEDULER` | `true` / `s1:<scheduler secret>` (only when an external scheduler is used) |
| `INTEGRATION_DEFAULT_WAREHOUSE` | warehouse that fulfils Sales orders (default `RYD`) |
| `INTEGRATION_OPPORTUNISTIC_SECONDS` | `120` (0 = only run cycles from a scheduler / heartbeat) |

**Sales** (Vercel → Project → Settings → Environment Variables):

| Variable | Value |
|---|---|
| `OPS_INTEGRATION_ENABLED` | `true` |
| `OPS_API_URL` | `https://<ops-host>` (no trailing slash) |
| `OPS_KEY_ID` / `OPS_KEY_SECRET` | `k1` / the same `<secret>` as in `INTEGRATION_KEYS_SALES` |
| `OPS_KEY_ID_PREV` / `OPS_KEY_SECRET_PREV` | the previous pair, only during a rotation |
| `OPS_INTEGRATION_SINCE` | ISO date: orders created before it are never sent by the repair step (set it to the go-live day) |
| `OPS_INTEGRATION_ORDERS` | `false` = stage 1 (master data + availability only, orders stay manual in Sales); unset / `true` = orders are handed to OPS |
| `PIN_PEPPER`, `SEED_PIN`, `SEED_PHONE_BASE`, `SEED_PIN_TEMPORARY` | Sales sign-in (not integration) — see Sales `docs/SECURITY.md`; must be set before the Sales migration |

Then: OPS `php artisan migrate --force` (creates `int_*`, adds the service user `svc.sales` and the permissions
`integration.view` / `integration.manage`), Sales `POST /api/admin/migrate` (adds the `integration_*` tables and the
`ops_*` columns — additive, existing data untouched).

## 3. First synchronisation

1. Sales admin portal → run command `integration.sync` (or wait for the first cycle): every customer and product is
   sent to OPS. OPS creates the customers (with their branches as delivery sites) and lists the products for mapping.
2. OPS → **برج التكامل → ربط الأصناف**: link each Sales product to its OPS SKU (suggestions by name are hints only;
   a link is always made by a person, one-to-one). Orders containing an unmapped product wait (`blocked`) and are
   replayed automatically the moment the mapping exists.
   * **The product does not exist in OPS** → «إنشاء في العمليات»: creates it from the Sales record (name, pack) and
     links it in one step. OPS needs weight, dimensions and storage class for every product; the form opens with an
     estimate read from the pack text (`PackEstimator`: "كرتون 4×4 لتر" → 16 kg, a 4:3:2.5 carton sized for it) which
     the steward corrects. The new product has no stock. Needs `integration.manage` + `product.manage`.
   * **Pilot only — in bulk:** `php artisan scm:integration-adopt-products sales [--stock=N] [--warehouse=RYD] [--dry-run]`
     does the same for every unmapped product with the *estimated* attributes, and with `--stock` posts a **trial
     opening balance** (an audited adjustment by `svc.sales` whose reason says «رصيد افتتاحي تجريبي») into a bin of the
     matching storage zone. Before real operation: correct the attributes on the product screens and replace the trial
     balances by a stock count.
3. Check `GET /api/v1/inventory/availability?products=…` from Sales (the catalogue then shows متوفر / كمية محدودة /
   غير متوفر, exact quantities for B2B staff only).

## 4. How cycles run (no worker needed)

| Path | When | What |
|---|---|---|
| Inline | right after a request commits | the events that request produced are delivered at once (5 s timeout; failure never affects the user) |
| Opportunistic | after a mutating / system / tower request, at most every `INTEGRATION_OPPORTUNISTIC_SECONDS` | full cycle: inbox retries, waiting backorders, deliveries, the Sales cycle, reconciliation |
| Scheduler | `php artisan schedule:work` or cron `* * * * * php artisan schedule:run` | same cycle every minute (`scm:integration-run`) |
| Heartbeat | signed `POST /api/v1/ops/heartbeat` (scope `ops:run`) | same cycle — for hosts without a scheduler; the workflow `.github/workflows/integration-cycle.yml` in the Sales repository calls it every 5 minutes once its variables are set |

One cycle on OPS also triggers the Sales cycle (`cycle_url`): outbox retries, repair of approved orders that were
never sent, changed customers / products, refresh of the availability copy. So **one** scheduler drives both systems.

## 5. Watching and repairing — برج التكامل (`/itower`)

| Symptom | Where | Action |
|---|---|---|
| System shows «متعثّر» / «متوقف» | Overview | receiver is failing; deliveries wait and retry by themselves (30 s → 12 h, 8 steps). Fix the receiver; nothing is lost |
| Dead letter > 0 | الصادر → `dead` | read the error, fix the cause, **إعادة** (retry). A `DELIVERY_DEAD` exception stays open until a person closes it with a note |
| «بانتظار ربط» > 0 | الوارد → `blocked` | map the product (or wait for the customer event); the events replay automatically |
| `EVENT_REJECTED` | الاستثناءات | the sender sent something invalid (code + reason are in the exception and were returned to the sender). Fix at the source; rejected events are never retried automatically |
| `CANCEL_TOO_LATE` (critical) | الاستثناءات | Sales cancelled after picking started. Stop the shipment manually (OPS cancel / return flow) and tell Sales; OPS never cancels a started order by itself |
| `RECEIPT_MISMATCH` | الاستثناءات | the customer's receipt in Sales differs from the POD. Investigate with the driver / customer; settle with a return or a credit note in Sales |
| `RECON_MISMATCH` / `RECON_MISSING` | الاستثناءات | the two systems disagree about an order. Open **تتبع**, find the event that did not arrive, replay it. The exception closes itself once both sides agree |
| Where is order X? | تتبع رحلة | enter `ORD-…` or `SO-…`: every event in and out, the OPS documents (SO, FO, trip, POD, returns), exceptions, with payloads |

Every manual action (retry, replay, map, unmap, resolve) is written to the audit log with the user.

## 6. End-to-end test on one machine

Runs the real Sales code and the real OPS code against local databases, connected only by the signed API:

```bash
# Sales on a local PostgreSQL (never the live database)
LOCAL_PG_URL=postgres://… PORT=3100 MIGRATE_KEY=local ADMIN_KEY=local SEED_PIN=<4 digits> PIN_PEPPER=local \
OPS_INTEGRATION_ENABLED=true OPS_API_URL=http://127.0.0.1:8101 OPS_KEY_ID=k1 OPS_KEY_SECRET=<secret> \
node scripts/local-server.mjs                      # in the Sales repository

# OPS twice (the PHP dev server handles one request at a time; OPS and Sales call each other)
INTEGRATION_SALES_ENABLED=true INTEGRATION_KEYS_SALES=k1:<secret> INTEGRATION_SCHEDULER_ENABLED=true \
INTEGRATION_KEYS_SCHEDULER=s1:<sched> INTEGRATION_SALES_EVENTS_URL=http://127.0.0.1:3100/api/integration/events \
INTEGRATION_SALES_CYCLE_URL=http://127.0.0.1:3100/api/integration/run \
INTEGRATION_SALES_RECONCILE_URL=http://127.0.0.1:3100/api/integration/orders \
php -S 127.0.0.1:8100 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php   # from public/, and again on :8101

OPS_PASSWORD=<seed password> SALES_PIN=<SEED_PIN> SALES_ADMIN_KEY=local SALES_SECRET=<secret> SCHED_SECRET=<sched> node tools/e2e-sales-ops.mjs
```

It walks the definition of done: customer → Sales order → availability → reservation → fulfilment → picking →
packing → dispatch → driver → delivery + POD → Sales status → customer receipt, then a shortage completed by arriving
stock, a cancellation, a partial delivery with its return, reconciliation, and (optionally) an OPS outage.

## 7. Mandatory scenarios → where each is proven

| # | Scenario | Test |
|---|---|---|
| 1 | Order, stock fully available | `OrderJourneyTest::test_a_fully_available_order…`, E2E step 2 |
| 2 | Partially available | `OrderJourneyTest::test_a_shortage_reserves_what_exists…`, E2E step 6 |
| 3 | Product not available | same (availability `none` → `backordered`) |
| 4 | Procurement requirement raised | same (`procurement.required` + shortage exception for buyers) |
| 5 | Stock arrives → re-allocated to the order | same (cycle completes the backorder first-come-first-served) |
| 6–8 | Fulfilment, shipping, delivery | `OrderJourneyTest` full journey, E2E steps 3–4 |
| 9 | Delivery failed | `ControlTowerTest::test_a_failed_delivery_reaches_sales_with_its_reason` (reason + automatic return; Sales puts the order on hold) |
| 10 | Customer return | `ControlTowerTest::test_a_partial_delivery_and_its_return…`, E2E step 8 |
| 11 | Sales down while sending | E2E step 10 (order kept in the Sales outbox, delivered on retry) |
| 12 | OPS down | same + `IntegrationCoreTest::test_delivery_failures_back_off…` |
| 13 | Same event twice | `IntegrationCoreTest::test_events_are_received_once…`, `OrderJourneyTest` (same event, and same order with a new event id) |
| 14 | Events out of order | `IntegrationCoreTest` (stale), `OrderJourneyTest::test_orders_wait_for_missing_mappings…` (cancel before confirm) |
| 15 | Data differs between systems | `ControlTowerTest::test_reconciliation_flags_disagreements…`, E2E step 9 |
| 16 | Consumer fails | `IntegrationCoreTest::test_a_failing_consumer_is_retried…` |
| 17 | Retry succeeds | same |
| 18 | Event goes to the dead-letter queue | same + `ControlTowerTest::test_dead_deliveries_and_exceptions…` |
| 19 | Replay | `IntegrationCoreTest` (replay after fix), `ControlTowerTest` (from the tower) |
| 20 | Unauthorised caller | `IntegrationCoreTest::test_the_gateway_only_accepts_correctly_signed_system_calls` |

Load: the gateway is rate-limited per system key (600 req/min by default); a batch carries up to 100 events.
No load test against production has been run.

## 8. Key rotation

1. OPS: `INTEGRATION_KEYS_SALES=k2:<new>,k1:<old>` → deploy (accepts both, signs with `k2`).
2. Sales: `OPS_KEY_ID=k2`, `OPS_KEY_SECRET=<new>`, `OPS_KEY_ID_PREV=k1`, `OPS_KEY_SECRET_PREV=<old>` → deploy.
3. After a day without `k1` traffic: remove `k1` from OPS and the `_PREV` pair from Sales.

## 9. Rollback

| Level | How | Effect |
|---|---|---|
| Pause Sales → OPS | Sales `OPS_INTEGRATION_ENABLED=false` | Sales stops recording and sending events; manual buttons (advance, hold) work again for orders not yet sent. Orders already in OPS continue there |
| Pause OPS → Sales | OPS `INTEGRATION_SALES_EVENTS_URL=` (empty) | events keep being recorded with their deliveries waiting (`SUBSCRIBER_NOT_CONFIGURED`); nothing is lost; they flow again when the URL is back |
| Stop everything | OPS `INTEGRATION_SALES_ENABLED=false` | every call from Sales answers 401; Sales keeps its events in its outbox and retries (8 steps, ~22 h) then dead-letters them for a replay |
| Code | both changes are additive (new tables / nullable columns); reverting the deploy needs no data migration | |

Nothing in a rollback deletes data. Dead or parked events are replayable from the tower once the link is back.
