# B2B Integration & Synchronization — Architecture

Status: **phases 0–12 built and verified locally end-to-end; stage 1 is live in production since 2026-10-04** —
signed link between the two live sites, customers and products flowing Sales → OPS, availability flowing back, a
5-minute heartbeat. The 28 Sales products were created in OPS and linked as a pilot (estimated physical
attributes, trial opening balance — RUNBOOK §1 gate 4). **Order hand-off (stage 2) was switched on the same day** for orders approved
after the switch; a first real order was reserved end-to-end on the live sites (RUNBOOK §1 gate 5) — the fulfilment
steps after reservation have so far been run only in the local E2E. Operations: [RUNBOOK.md](RUNBOOK.md). Owner: B2B engineering.
Systems: **B2B Sales** (`salem-cell/b2b-platform`, vanilla JS + Vercel functions + Neon Postgres, live at
b2b-platform-ten.vercel.app) and **B2B OPS** (this repository, Laravel 13 + Vue 3, live at scm-ops-laravel.vercel.app).

Everything below was derived from reading both code bases (file:line evidence in the discovery notes at the end).
Nothing is assumed to exist that does not.

---

## 1. Current architecture assessment

### 1.1 B2B Sales — what it is today

| Area | Fact | Integration consequence |
|---|---|---|
| Runtime | Static ES-module front end; 6 serverless functions; `POST /api/command {cmd}` dispatches 63 commands in `api/_lib/logic.js` | One choke point to emit events from |
| Database | 22 tables, **no foreign keys, no CHECK constraints, no transactions** (Neon HTTP driver, one HTTP call per statement) | Multi-write commands can half-apply; event emission must be atomic with the change (`sql.transaction([...])`) |
| Auth | *At discovery:* OTP accepted any 4 digits, the caller picked any non-admin role, tenant = f(role). **Fixed (phase 0):** registered account = phone + 4-digit PIN with lockout; role and tenant come from the account record (Sales `docs/SECURITY.md`) | Was the blocking risk for go-live (§9); SMS OTP can later replace the PIN check in one function (`verifyLogin`) |
| Data exposure | *At discovery:* `GET /api/state` returned the whole database to any session. **Fixed (phase 0):** the snapshot is built per account scope; every command checks record ownership | Customers see the availability *level* only; exact ATP is for the B2B team |
| Products | `products(id P-xxxx, name, unit free text, cat, price, img, is_out)`; no SKU/barcode/UoM/stock; ids from `count(*)` | Product identity must be **mapped** to OPS SKUs; never auto-created from names |
| Orders | `orders(id ORD-n, st, items jsonb [{pid,qty}], stamps 6×'HH:MM', branch NAME, by_user persona)`; **no price snapshot**, no line ids, no dates | Order event must carry a price snapshot (fix in Sales); lines keyed by `pid` |
| Lifecycle | `ops → purch → b2b → (hold) → ship → done/short`, `rej`; partial release creates `ORD-n-B` | `b2b` = commercially confirmed = hand-over point to OPS |
| Customers | `clients` (bigint `Date.now()` ids) + parallel `frs` (franchise) linked by CR/name; branches in **two** places (global `branches` table and `clients.branches` JSON) with **simulated** coordinates | Customer + branch identity from Sales; real coordinates are verified in OPS |
| Money | One hard-coded wallet `4030-118842`; normal orders never invoiced | Finance integration out of scope until Sales tenancy is fixed |
| Integration primitives | none: no external ids, no idempotency, no webhooks/outbox, no `updated_at` | Everything below is new on the Sales side |

### 1.2 B2B OPS — what it is today

| Area | Fact | Integration consequence |
|---|---|---|
| Inventory | Central engine `InventoryService` (row locks, append-only ledger, FEFO allocation, reservation per SO line, nightly-style `reconcile()`) | Single source of truth for stock; ATP is computed here |
| Sales orders | `createOrder` is all-or-nothing: rejects with `INSUFFICIENT_AVAILABLE`; no backorder SO; statuses `draft/reserved/readydisp/completed/returned` never set | Needs an integration intake that accepts shortages (backorder + procurement requirement) |
| Fulfilment / transport / POD / returns | Complete flows with explicit guards; POD stores receiver, GPS, qty, signature/photo (S3 when configured); RMA with restock/QTN/DMG/dispose/supplier | Rich status source for Sales |
| Outbox | `integration_events` written **inside** business transactions (`NotifyService::event`) — good; but single target, no event id sent, no backoff, no DLQ, nothing scheduled, only 7 event types | Extend, don't replace |
| Inbound | No machine authentication, no inbound endpoint, no external ids | New |
| API | `/api` only, JWT for humans (15 min), `Idempotency-Key` middleware per user, `X-Request-Id` correlation, AppError envelope | Add `/api/v1` for systems; reuse error envelope + request id |
| Scheduling | Laravel scheduler (only `scm:gps-sync`); Vercel has no worker/cron by default | Delivery must work from (a) inline attempt, (b) scheduler, (c) signed heartbeat |

### 1.3 Weaknesses found and how they are handled

| # | Weakness | Handling |
|---|---|---|
| W1 | Sales auth/tenancy (any role, whole-DB snapshot) | Closed by phase 0 on the Sales side: PIN accounts, tenant isolation, 93 HTTP security checks in Sales CI (§9, §10) |
| W2 | Sales has no product master codes | `int_external_refs` mapping + Control Tower "unmapped product" queue; orders with unmapped lines park as exceptions (never lost, never guessed) |
| W3 | Sales lines have no price | Sales snapshots unit price (customer price when present) into the line at submit; event carries it |
| W4 | OPS rejects short orders | New intake: reserve what exists, backorder the rest, raise a procurement requirement, return ETA |
| W5 | OPS outbox is single-target and never retried | Per-subscriber deliveries, exponential backoff, DLQ, circuit breaker, signed bodies with event id |
| W6 | OPS SO state machine mostly unused (direct updates) | Status changes of integrated orders go through one mapper that emits events; no new unguarded paths |
| W7 | VAT recomputed from a global setting on read | Integrated order stores the commercial totals it received (Sales is SoR for price/VAT) |
| W8 | Sales branch coordinates are fake | OPS keeps its own verified site coordinates; Sales address text is informational |

---

## 2. Sales ↔ OPS entity mapping

Direction: **S→O** Sales to OPS, **O→S** OPS to Sales. Every mapping row is keyed through `int_external_refs`
(system, entity, external_id ↔ internal_id) — matching by similar names is forbidden.

| Sales entity (fields) | OPS entity (fields) | Dir | Trigger | Validation | Failure handling |
|---|---|---|---|---|---|
| `clients` (id, name, cr, city, st, type) | `customers` (code CUS-, name_ar, city, terms, credit_limit, active) | S→O | `customer.created`, `customer.updated` | id present, name non-empty; CR stored in OPS (new column) | Unknown fields ignored; invalid → exception `CUSTOMER_INVALID` |
| `clients.branches[]` / `branches` (name, city, loc.addr) | `customer_sites` (new: customer_id, external_key, name, city, address, lat/lng verified flag) | S→O | `customer.updated` | Branch key = `{clientId}:{branchName}` | Coordinates from Sales are flagged `unverified`; dispatcher confirms on the OPS map |
| `products` (id P-, name, unit, cat, price, is_out) | `products` (sku, names, UoM, dims, storage, shelf life) | mapping only | `product.created/updated` from Sales → mapping queue | One Sales product ↔ one OPS SKU (unique both ways) | Unmapped → exception `PRODUCT_UNMAPPED`; order intake parks until mapped, then replays |
| `client_products` (client_id, pid, price) | none (commercial) | — | — | — | Stays in Sales |
| `orders` (id, st=`b2b`, items[{pid,qty,price}], branch, by_user) | `sales_orders` (number SO-, source_system=`sales`, external_ref=ORD-…, customer, site, lines qty/price, due_date, window, priority) | S→O | `sales_order.confirmed` when Sales status becomes `b2b` | customer mapped, every line mapped, qty > 0, price ≥ 0 | Unique (source, external_ref) ⇒ a replay can never create a second SO; mapping gaps park the event (exception) |
| `orders` `rej` after `b2b` | SO `cancelled` (+ reservation release) | S→O | `sales_order.cancelled` | SO not yet loaded | Loaded/dispatched → exception `CANCEL_TOO_LATE` for a human |
| `orders` (`ship`, `ops_*` fields) | SO/FO/trip/POD statuses | O→S | `fulfillment.*`, `shipment.*`, `delivery.*` | sequence per order | Out-of-order → older sequence ignored |
| `orders.receive` (`done` / `short` + ticket) | POD (delivered/partial) | S→O | `sales_order.received` | — | Mismatch with POD → reconciliation exception |
| — (no entity) | `returns` (RTN-) | O→S | `return.*` | — | Shown on the Sales order |
| — (only `is_out`) | inventory balances / ATP | O→S | `inventory.changed` (+ pull API) | — | Sales caches; never computes stock |
| — | `purchase_orders` / inbound ETA | O→S | `procurement.required`, `order.eta_updated` | — | ETA shown to sales |

Fields that look alike but are **not** the same: Sales `unit` (free-text pack, e.g. "كيس 40 كجم") ≠ OPS `base_uom`;
Sales `orders.branch` (name text) ≠ OPS `warehouse`; Sales `b2b` status (in B2B's hands) ≠ any OPS status;
Sales `stamps[4]` ("shipped") = OPS trip dispatched, not FO packed.

---

## 3. System of Record matrix

| Data | System of record | Others may | Rule |
|---|---|---|---|
| Customer identity, CR/VAT, commercial terms, credit decisions, franchise network | **Sales** | OPS: read, store copy | OPS never edits these fields of a Sales-sourced customer (UI read-only, API refuses) |
| Customer branch name/address text | **Sales** | OPS: read | — |
| Delivery-site coordinates, delivery windows actually achievable, dock notes | **OPS** | Sales: read | Sales pins are hints; OPS verifies |
| Selling price, customer price, discounts, VAT on the order | **Sales** | OPS: stores the snapshot received | OPS never reprices an integrated order |
| Commercial order (creation, approvals, commercial cancel) | **Sales** | OPS: read | OPS cannot cancel an integrated order on its own (exception to Sales instead) |
| Product operational master (SKU, barcode, UoM, dimensions, weight, storage, shelf life, batches) | **OPS** | Sales: read | — |
| Product commercial master (display name, image, category, price, on/off sale) | **Sales** | OPS: read | — |
| Product identity link | **Integration layer** (`int_external_refs`) | — | Created by an OPS data steward in the Control Tower |
| Warehouses, locations, inventory, reservations, ATP | **OPS** | Sales: read | Sales never stores stock as truth (cache only, with timestamp) |
| Procurement, suppliers, POs, receiving, ETA | **OPS** | Sales: read ETA | — |
| Fulfilment, picking, packing, dispatch, fleet, drivers, trips, POD | **OPS** | Sales: read status | Sales manual `advance/hold/partial release` are disabled for integrated orders |
| Customer receipt confirmation, shortage tickets, credit notes | **Sales** | OPS: read | Receipt vs POD differences → reconciliation exception |
| Returns execution (pickup, receiving, inspection, disposition) | **OPS** | Sales: read | Return *request/authorization* is commercial → Sales (Phase 8) |
| Integration events, deliveries, exceptions, audit | **Integration layer** | — | Append-only |

---

## 4. Integration architecture

```
 B2B Sales (Vercel/Neon)                        B2B Integration Layer (in OPS deployment, own tables + /api/v1)            B2B OPS domain
 ┌──────────────────────┐   signed HTTPS        ┌─────────────────────────────────────────────────────────────┐        ┌─────────────────┐
 │ command → tx {        │  POST /api/v1/events  │ Gateway: HMAC auth · scopes · rate limit · validation · log │        │ SalesService     │
 │   change + outbox row │ ───────────────────▶ │ Inbox (dedupe by event id, per-subject sequence)            │──────▶ │ InventoryService │
 │ }                     │                       │ Handlers (customer, order intake, cancel, receipt)          │        │ Fulfilment, TMS  │
 │ dispatcher (inline +  │ ◀─────────────────── │ Outbox → per-subscriber deliveries (backoff, DLQ, breaker)   │◀────── │ NotifyService::  │
 │  cron) · inbox handler│  POST /api/integration│ External refs · Exceptions · Reconciliation · Control Tower │        │   event (tx)     │
 └──────────────────────┘     /events (signed)   └─────────────────────────────────────────────────────────────┘        └─────────────────┘
          ▲  GET /api/v1/inventory/availability, /orders/{ref} (pull for screens + reconciliation)
```

Decisions:

1. **Contracts, not shared tables.** Each system writes only its own database. The only coupling is the versioned
   HTTP API and the event schema. Either side can be replaced by honouring the contract.
2. **The integration layer is a module with its own tables (`int_*`) and its own API prefix (`/api/v1`)**, deployed
   with OPS for now (one less service to run on Vercel/Neon, transactional access to the OPS outbox). It calls the
   OPS domain only through its services, never its tables directly. Extracting it into its own deployment later
   means moving `app/Integration` + `int_*` tables and pointing OPS's outbox at it — no contract changes.
3. **Transactional outbox on both sides.** A business change and its event are committed together (OPS: same DB
   transaction; Sales: `sql.transaction([...])`). Delivery happens after commit.
4. **Push first, pull to repair.** Events are pushed (inline attempt right after commit → near real-time). Missed or
   failed deliveries are retried by the scheduler/heartbeat; the pull APIs serve screens and reconciliation.
5. **Exactly-once effect, at-least-once delivery.** Receivers dedupe on `event.id` (inbox unique key) and every
   creating handler also has a natural unique key (`sales_orders.source_system + external_ref`).
6. **Ordering per entity.** Each event carries `sequence` per `subject`; a receiver ignores an event older than the
   last one applied for that subject (recorded as `skipped_stale`, visible in the Control Tower).
7. **No AI in operational decisions.** Data is captured clean (events, ledger, ETA vs actual) so forecasting can be
   added later as advisory only.

Scheduling (Vercel has no worker): `php artisan scm:integration-run` every minute where a scheduler exists; without
one the same cycle runs opportunistically after a mutating / system request (at most every
`INTEGRATION_OPPORTUNISTIC_SECONDS`) and from the signed `POST /api/v1/ops/heartbeat` (GitHub Actions cron in the
Sales repository, every 5 min once configured). One OPS cycle also triggers the Sales cycle. The inline attempt makes
the normal path immediate; the cycle only repairs.

---

## 5. API contracts (gateway `/api/v1`, systems only)

All requests are signed (§9). Errors use the existing envelope `{category, code, message, messageEn, details,
requestId}`. Every response echoes `X-Request-Id`; `X-Correlation-Id` is echoed when sent.

| Method & path | Scope | Purpose |
|---|---|---|
| `GET /api/v1/health` | any | liveness + this system's view of the caller (key id, scopes) |
| `POST /api/v1/events` | `events:write` | Receive one event or a batch (≤ 100). Answers per event, in order: `processed` / `duplicate` / `stale` / `blocked` (parked until a mapping exists) / `rejected` / `failed` (will be retried here), each with its code. 202 when stored |
| `GET /api/v1/inventory/availability?products=P-1,P-2&warehouse=` | `inventory:read` | ATP per mapped Sales product: `{onHand, reserved, available, quarantine, damaged, expired, nearExpiry, incoming, incomingEta, atp, asOf}` |
| `GET /api/v1/orders/{externalRef}` | `orders:read` | OPS view of a Sales order: status, mapped Sales status, timeline, ETA, shipment/driver, POD summary, returns, exceptions |
| `GET /api/v1/products?mapped=1` | `products:read` | OPS operational master for mapped products — **planned, not built** (Sales shows only availability today) |
| `POST /api/v1/ops/heartbeat` | `ops:run` | Runs one integration cycle (inbox retries, waiting backorders, deliveries, the other systems' cycles, reconciliation when due) |

Sales exposes, all signed by OPS: `POST /api/integration/events` (same envelope, OPS→Sales), `POST /api/integration/run`
(its cycle: outbox retries, repair of unsent approved orders, changed customers / products, availability refresh) and
`GET /api/integration/orders?ids=…` (reconciliation read).

People use the Control Tower API `/api/integration/*` (session + `integration.view` / `integration.manage`): overview,
inbox, deliveries, exceptions, `events/{id}` (payload), `trace/{key}`, retry / replay / resolve, `mappings/{entity}`,
`run`, `reconcile`.

---

## 6. Event catalog

Envelope (CloudEvents-compatible names):

```json
{
  "id": "01J9…",               // ULID, unique forever — the dedupe key
  "type": "sales_order.confirmed",
  "source": "sales",            // sales | ops
  "subject": "ORD-2482",        // entity the event is about
  "sequence": 7,                 // monotonic per (source, subject)
  "time": "2026-10-04T09:12:33.120Z",
  "schemaVersion": 1,
  "correlationId": "ORD-2482",  // business journey id (the Sales order) — carried by every downstream event
  "causationId": null,           // id of the event that caused this one
  "data": { }
}
```

| Type | Source | Subject | `data` (v1) |
|---|---|---|---|
| `customer.created` / `customer.updated` | sales | client id | `{id, name, cr, vat?, city, type, active, creditLimit, branches:[{key,name,city,address,lat?,lng?}]}` |
| `product.created` / `product.updated` | sales | P-id | `{id, name, unit, category, price, active}` (commercial view, for the mapping queue) |
| `sales_order.confirmed` | sales | ORD-id | `{id, customerId, branch:{key,name,address}, lines:[{lineNo, productId, qty, unitPrice, discountPct}], vatPct, totals:{net,vat,gross}, requiredDate?, window?, priority, notes, salesRep, confirmedAt}` |
| `sales_order.cancelled` | sales | ORD-id | `{id, reason}` |
| `sales_order.received` | sales | ORD-id | `{id, result: done\|short, lines:[{productId, receivedQty}]}` |
| `order.accepted` | ops | ORD-id | `{opsOrder: SO-…, warehouse, availability: full\|partial\|none, lines:[{productId, qty, reserved, backordered}]}` |
| `order.backordered` | ops | ORD-id | `{opsOrder, lines:[{productId, missing}], eta?}` |
| `order.reserved` | ops | ORD-id | `{opsOrder, lines:[{productId, reserved}]}` |
| `order.released` / `picking.started` / `picking.completed` / `order.packed` / `order.loaded` | ops | ORD-id | `{opsOrder, status, fulfilmentOrder, at}` |
| `shipment.dispatched` | ops | ORD-id | `{trip, vehicle, driver, eta?, at}` |
| `delivery.completed` / `delivery.partial` / `delivery.failed` | ops | ORD-id | `{trip, pod, at, receiver?, deliveredQty, returnedQty, reason?, gps?}` |
| `order.cancelled` / `order.cancel_rejected` | ops | ORD-id | `{opsOrder, status, reason}` — the answer to `sales_order.cancelled` (released, or refused because execution started) |
| `order.eta_updated` | ops | ORD-id | `{eta, reason}` — **planned** (today the ETA travels with `order.backordered` / `procurement.required`) |
| `inventory.changed` | ops | P-id | `{atp, available, incoming, incomingEta, asOf}` — accepted by Sales, **not yet emitted by OPS**: Sales pulls availability every cycle instead |
| `procurement.required` | ops | ORD-id | `{lines:[{productId, missing}], pr?, po?, eta?}` |
| `return.created` / `return.approved` / `return.received` / `return.inspect` / `return.closed` / `return.rejected` | ops | ORD-id | `{return, returnType, returnStatus, decision?}` |
| `integration.exception` | either | entity | `{code, details}` — informational |

Schema versions are additive within a major version; a breaking change ships as `schemaVersion: 2` while v1 is
still accepted.

---

## 7. Order state mapping

Sales owns the commercial status (`orders.st`); OPS's detailed status travels as `ops_status` on the Sales order and
is shown in the order drawer. Only the transitions marked ★ change the Sales `st`.

| OPS event / status | Sales `st` | Sales `ops_status` (shown) |
|---|---|---|
| (Sales) `ops`, `purch` | unchanged | — (not sent to OPS) |
| (Sales) → `b2b` ★ → `sales_order.confirmed` | `b2b` | `sent` |
| `order.accepted` full / `order.reserved` | `b2b` | `reserved` |
| `order.accepted` partial / `order.backordered` | `b2b` | `backordered` (+ ETA) |
| `order.released`, `picking.*`, `order.packed` | `b2b` | `picking` / `picked` / `packed` |
| `shipment.dispatched` ★ | `ship` (stamps[4]) | `out_for_delivery` |
| `delivery.completed` | `ship` | `delivered` (POD) — customer confirms receipt in Sales → `done`/`short` ★ |
| `delivery.partial` | `ship` | `delivered_partial` |
| `delivery.failed` ★ | `hold` (reason = failure reason) | `delivery_failed` |
| `order.cancelled` (OPS-side, exceptional) ★ | `hold` | `ops_cancelled` — commercial decision required |
| `return.*` | unchanged | `return_*` |
| (Sales) `rej` after `b2b` → `sales_order.cancelled` | `rej` | `cancel_requested` → `cancelled` |

In integrated mode Sales disables `orders.advance`, partial release and `orders.hold/resume` for orders already sent
to OPS — those are operational and now driven by OPS events.

---

## 8. Error & retry strategy

| Mechanism | Where | Rule |
|---|---|---|
| Inline attempt | both | right after commit, timeout 5 s, never blocks the user's action on failure |
| Retry with exponential backoff | both outboxes, OPS inbox | delays 30 s, 2 m, 10 m, 30 m, 1 h, 3 h, 6 h, 12 h (+ jitter); `next_attempt_at` stored |
| Dead-letter | both | after 8 failed attempts → `dead`; raises an `int_exceptions` row; never deleted |
| Idempotency | receiver | inbox unique `event_id`; creating handlers also unique on natural keys; duplicate → `duplicate` answer, 200 |
| Ordering | receiver | `sequence` per subject; stale events recorded and skipped |
| Timeouts | outbound HTTP | 5 s connect+read; counted as failure |
| Circuit breaker | per subscriber | 5 consecutive failures → open 5 min (no calls, deliveries wait); half-open after; closes on success |
| Validation errors | receiver | 4xx `rejected` — not retried by the sender; exception raised on both sides |
| Mapping gaps | OPS inbox | event parked `blocked` with exception (e.g. `PRODUCT_UNMAPPED`); **replayed automatically** when the mapping is created |
| Replay | Control Tower | any processed/dead event can be replayed; handlers are idempotent |

---

## 9. Security model

* **Service authentication:** HMAC-SHA256 request signing (no shared passwords, no user JWT for machines).
  Headers: `X-B2B-System`, `X-B2B-Key-Id`, `X-B2B-Timestamp` (±300 s), `X-B2B-Signature` =
  hex(HMAC(secret, `timestamp\nMETHOD\npath\nsha256(body)`)). Two active keys per system allow rotation.
* **Secrets** live only in environment variables (`INTEGRATION_KEYS_<SYSTEM>`, Sales `OPS_INTEGRATION_*`). Never in
  source, never in `.env.example` values. The Sales repository is **public**: nothing secret may be committed there.
* **Least privilege:** each system has scopes (`events:write`, `inventory:read`, `orders:read`, `products:read`,
  `ops:run`); event types are allow-listed per source.
* **Rate limiting:** per system key (default 600 req/min) on `/api/v1`.
* **Validation:** envelope + per-type payload schema; body ≤ 1 MB; batch ≤ 100.
* **Transport:** HTTPS only (Vercel).
* **Audit:** every accepted inbound event, handler outcome, manual retry/replay/resolve is written to `audit_logs`
  (who, what, when, source, destination, before/after, correlation id, event id, result) + `int_*` tables.
* **Human access:** Control Tower requires `integration.view` / `integration.manage` permissions.
* **Go-live gate (Sales):** order hand-off requires that (1) login binds the caller to a registered account with a
  fixed role and tenant, and (2) `/api/state` returns only the caller's tenant data — both delivered by phase 0
  (phone + 4-digit PIN with lockout for now; SMS OTP later). The remaining gate is operational, not security:
  Sales products must be mapped to OPS products with stock before `OPS_INTEGRATION_ORDERS` is switched on (RUNBOOK §1).

---

## 10. Implementation roadmap

| Phase | Scope | Status |
|---|---|---|
| 0 | Sales hardening: registered accounts (phone + 4-digit PIN, lockout, forced change of temporary PIN), role/tenant from the account record, scoped snapshot, object-level ownership checks, per-tenant wallets, price snapshot on order lines | done in Sales (`docs/SECURITY.md`, `scripts/test-security.mjs` — 93 checks, upgrade rehearsed on the previous schema); **live since 2026-10-04** (migration summary: 11 clients, 21 orders, 8 sign-in accounts). SMS OTP: later, replaces the PIN check only |
| 1 | Discovery (this document) | done |
| 2 | Integration layer core in OPS: `int_*` tables, HMAC gateway `/api/v1`, inbox (dedupe/sequence), outbox deliveries (backoff, DLQ, breaker), external refs, exceptions, scheduler + heartbeat | done — `app/Integration`, `IntegrationCoreTest` |
| 3 | Master data: customer + branch intake, product mapping queue + UI | done — `MasterDataSyncTest`, tower → mappings |
| 4 | Inventory/ATP API + `inventory.changed` | done (pull API; `inventory.changed` push not built) |
| 5 | Order intake: `sales_order.confirmed` → SO with reserve-what-exists + backorder; cancel | done — `OrderIntakeService`, `OrderJourneyTest` |
| 6 | Fulfilment status events → Sales; Sales inbound endpoint + drawer timeline | done — `OrderEvents`; Sales `api/integration/events.js` + drawer panel |
| 7 | Delivery + POD events | done |
| 8 | Returns + procurement requirement + ETA | done — returns events, shortage → procurement exception + ETA (PR/PO creation stays a buyer's action) |
| 9 | Reliability hardening on Sales side (outbox, dispatcher, inbox) | done — Sales `api/_lib/integration.js` |
| 10 | Control Tower UI, traceability by correlation id, reconciliation engine | done — `/itower`, `TowerService`, `ReconciliationService`, `ControlTowerTest` |
| 11 | End-to-end + failure-scenario tests (the 20 mandatory scenarios) | done — 19 integration tests on MySQL + PostgreSQL, `tools/e2e-sales-ops.mjs` (35 checks across both real systems, signing in to Sales with PIN accounts); no load test |
| 12 | Production readiness: runbook, monitoring, rollback (feature flags both sides) | done — `RUNBOOK.md` (enable, monitor, rotate, roll back); production enabling not done |

Definition of done = the journey Customer → Sales order → availability → reservation → OPS fulfilment → picking →
packing → dispatch → driver → delivery → POD → Sales status updated runs with no double entry, proven by an
end-to-end test against both systems, not by HTTP 200s.
