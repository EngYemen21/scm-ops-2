# B2B ops — Supply Chain Operations (Laravel + Vue)

Procurement · WMS · Inventory · Sales fulfillment · TMS · Fleet · Driver app · POD · Returns · Exceptions.
Arabic-first (RTL) with English, multi-user, role-based.

| Layer | Technology |
|---|---|
| API | Laravel 13 (PHP 8.3), MySQL 8 (primary) or PostgreSQL 17, JWT access tokens + rotating refresh tokens |
| Client | Vue 3 (`<script setup>`), Vue Router, Pinia, TanStack Query, Tailwind CSS 4, Vite |
| Tests | PHPUnit feature tests against real databases — the whole suite runs on MySQL **and** PostgreSQL in CI |

This is a port of the validated NestJS/React reference system with the **same HTTP contract** (paths, payloads,
permissions, error codes) and the same screens. Reference material lives in `docs/reference/`.

## Quick start

**دليل الفريق بالعربية: [docs/TEAM-START.ar.md](docs/TEAM-START.ar.md)**

Requirements: PHP 8.3 (extensions: pdo_mysql, mbstring, openssl, intl, curl, fileinfo, zip, gd, sodium), Composer 2, and
either Docker or a MySQL 8 server. Node 20+ only when you change the client (`public/build` is committed).

```bash
git clone https://github.com/salem-cell/scm-ops-laravel.git && cd scm-ops-laravel
php tools/setup.php --docker         # or, with your own MySQL: fill DB_* in .env, then `php tools/setup.php`
php artisan serve --port=8000        # open http://127.0.0.1:8000
```

`tools/setup.php` installs the packages, creates `.env`, **generates every secret locally** (APP_KEY, JWT_ACCESS_SECRET,
the Docker database password, SEED_PASSWORD), starts MySQL, migrates, loads the demo data and prints the demo password.
It is safe to re-run; only `--fresh` drops data. `.env` is never committed.

Demo logins (password = `SEED_PASSWORD` in your `.env`): `admin`, `sales`, `wm`, `inv`, `proc`, `disp`, `worker`,
`driver`, `gm`, `finance`.

After changing `resources/js` or `resources/css`, run `npm run build` and commit `public/build` with the change.
CI (`.github/workflows/ci.yml`) runs the backend suite on MySQL 8.4, checks and builds the client, and rehearses this
quick start from scratch on every push and pull request.

## Everyday commands

```bash
php artisan test                                   # whole suite (database scm_ops_test, rebuilt + seeded per run)
php artisan test tests/Feature/Sales               # one domain
php artisan scm:reconcile [RYD]                    # inventory ledger = balances? (exit 1 on mismatch)
php artisan migrate:fresh --seed                   # reset the dev database to the demo data
vendor/bin/pint                                    # PHP code style
node tools/check-vue.mjs resources/js/pages/sales  # static check of Vue files (imports, exports, templates)
```

## Where things are

```
app/Http/Controllers/Api/<Domain>/   thin controllers: validate → service → return
app/Services/<Domain>/               business logic (transactions, state machines, audit)
app/Services/Inventory/InventoryService.php   the ONLY code that changes stock (ledger + row locks + FEFO)
app/Services/Core/                   audit trail, document numbering, settings, activity/notifications/outbox
app/Support/                         AppError (error contract), AuthUser, Paging, state machines
app/Models/                          93 Eloquent models (generated; snake_case columns, camelCase JSON)
routes/api/<domain>.php              routes + permissions of each domain
config/scm.php                       statuses, state machines, labels, permission catalogue
resources/js/                        Vue client (pages/<domain>, components, layout, api, stores, router)
tests/Feature/                       feature tests per domain + AcceptanceTest (the full from-scratch story)
docs/                                CONVENTIONS.md (backend) · FRONTEND.md (client) · RUNBOOK.md (run / deploy / migrate data)
tools/                               generators and checkers
```

## Principles the code keeps

- **Server is the authority**: permissions (`perm:` middleware → 403 + audit row, nothing changed), validation, state
  machines and business rules are enforced by the API; the client only hides what a user cannot do.
- **Stock integrity**: every quantity change is an append-only ledger movement posted by `InventoryService` inside a
  transaction with row locks — no negative stock, no overselling under concurrency, FEFO that never picks expired or
  quarantined batches. `scm:reconcile` proves ledger = balances.
- **Idempotency**: mutations sent with `Idempotency-Key` are replayed, not repeated (double clicks, retries).
- **Honest integrations**: storage, GPS, maps, messaging, ERP and the B2B webhook report `integration_pending` until a
  provider is configured in `.env`; nothing ever claims to be sent, uploaded or live when it is not.
- **Traceability**: audit log, status history and activity feed for every document; deep links between them.

Read `docs/CONVENTIONS.md` before changing the API and `docs/FRONTEND.md` before changing the client.
