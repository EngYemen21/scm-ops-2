# Runbook

## 1. Local development on this Windows machine (portable toolchain)

PHP, Composer and MySQL were installed as portable copies under `%USERPROFILE%\.scmops` (nothing system-wide):

| Tool | Location |
|---|---|
| PHP 8.3 + `composer.phar` | `%USERPROFILE%\.scmops\php` |
| MySQL 8.4 (port **3307**, UTC) | `%USERPROFILE%\.scmops\mysql`, data in `%USERPROFILE%\.scmops\mysqldata`, config `mysql\my.ini` |
| App database password | `%USERPROFILE%\.scmops\mysql-scm-password.txt` (also in `.env`, which is not committed) |

Git Bash:

```bash
export PATH="$USERPROFILE/.scmops/php:$PATH"
"$USERPROFILE/.scmops/mysql/bin/mysqld.exe" --defaults-file="$USERPROFILE/.scmops/mysql/my.ini" &   # start MySQL
php artisan serve --port=8000                                                                       # API + app
npm run dev                                                                                         # optional: hot reload
"$USERPROFILE/.scmops/mysql/bin/mysqladmin.exe" --defaults-file="$USERPROFILE/.scmops/mysql/my.ini" -uroot shutdown   # stop MySQL
```

Composer: `php "$USERPROFILE/.scmops/php/composer.phar" <command>`.
Databases: `scm_ops` (development), `scm_ops_test` (tests; rebuilt on every run), `scm_ops_test_*` (per-domain test runs:
`DB_DATABASE=scm_ops_test_sales php artisan test tests/Feature/Sales`).

Shell gotcha: bash heredocs on this machine strip backslashes — never write PHP through a heredoc.

Outbound HTTPS (maps, webhooks, storage) from a portable Windows PHP needs a CA bundle, otherwise every call fails with
`cURL error 60: unable to get local issuer certificate`: copy a bundle next to PHP (Git for Windows ships one at
`C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt`) and set `curl.cainfo` and `openssl.cafile` to it in `php.ini`.
Linux servers, Docker and Vercel already have one.

## 2. Deploying to a server

Any standard Laravel host works (VPS with Nginx/Apache + PHP-FPM, Laravel Forge / Cloud, Ploi, shared hosting with SSH).
Vercel / Netlify cannot run it (they do not run PHP).

1. PHP 8.3 with `pdo_mysql mbstring openssl intl curl fileinfo zip gd sodium`; MySQL 8 (utf8mb4); Node 20 only for the build.
2. Web root = the `public/` folder. HTTPS is required in production.
3. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, a long random `JWT_ACCESS_SECRET`
   (`php -r "echo bin2hex(random_bytes(32));"`), `JWT_ACCESS_TTL=15m`, `JWT_REFRESH_TTL=7d`. Leave the integration
   variables empty until a provider is contracted — the system then reports them as pending, honestly.
4. Build and migrate:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
5. **Do not seed production.** `migrate --seed` loads demo data and demo users. Create the first administrator through
   a snapshot import of your real users (section 3) or with `php artisan tinker`.
6. PHP limits: `post_max_size` and `upload_max_filesize` ≥ 8M (proof-of-delivery photo + signature arrive as base64 JSON).
7. After every deploy: `php artisan migrate --force` then the three `*:cache` commands again.
8. Health check: `GET /up`. Stock integrity check (cron it nightly): `php artisan scm:reconcile` — exit code 1 on mismatch.
   Procurement follow-up alerts (late purchase orders, expiring supplier documents) ride on the integration heartbeat once an hour;
   without a heartbeat, cron `php artisan scm:procurement-followup` daily. New permissions a release introduces are inserted by
   `migrate` (`PermissionSync`) — `php artisan scm:sync-permissions` re-runs that step by hand.
   Finance cut-over (docs/finance/ARCHITECTURE.ar.md §2): after the CFO approves the valuation basis run
   `php artisan scm:finance-open-inventory` (dry run) then `--confirm`; enter real bank accounts (IBAN) and opening balances
   as approved opening journals; `php artisan scm:finance-check` must exit 0 before the first period close.
9. Back up the MySQL database daily; the application keeps no state on disk.

Sign-in is rate limited (10 attempts per minute per username + IP, `LOGIN_ATTEMPTS_PER_MINUTE`); it needs a working
cache store (`CACHE_STORE=file` or redis).

Not done yet, by design (needs your decisions): a scheduled command that calls
`OutboxService::processPending()` once an integration is configured, log shipping / monitoring.

## 3. The Vercel deployment (demo / staging)

Live at the project's Vercel domain. Vercel has no official PHP support: the app runs on the community runtime
[`vercel-php`](https://github.com/vercel-community/php) as ONE serverless function, and the database is **PostgreSQL on
Neon** (Vercel offers no MySQL). Treat it as a demo/staging environment; the long-term production target is a PHP host
with MySQL (section 2).

| Piece | Where |
|---|---|
| Function entry | `api/index.php` → `public/index.php` (it resets `SCRIPT_NAME`, otherwise Laravel strips `/api` from every URL) |
| Routing, runtime, non-secret env | `vercel.json` (static: `/build/*` and the logo files; everything else → the function) |
| Read-only filesystem | `bootstrap/app.php` moves `storage/` to `/tmp` and trusts the platform proxy when `VERCEL` is set |
| Secrets | Vercel project env (encrypted): `APP_KEY`, `JWT_ACCESS_SECRET`, `DB_URL` (Neon **pooled** URL), `APP_URL` |
| Upload filter | `.vercelignore` |

```bash
vercel deploy --prod                                                   # deploy the working tree
SMOKE_PASSWORD=… node tools/smoke.mjs https://<domain>                # read-only check of every GET endpoint + report
```

Schema changes and (re)seeding run from a workstation against Neon's **direct** (non-pooler) host, never from the function:

```bash
DB_CONNECTION=pgsql DB_HOST=<direct host> DB_DATABASE=scm_laravel DB_USERNAME=… DB_PASSWORD=… DB_SSLMODE=require php artisan migrate --force
```

Limits to know: no queue worker or scheduler (the outbox is run from the UI; `scm:reconcile` from a workstation), cold
starts of about a second, `CACHE_STORE=database` (rate limiter and locks live in the database), files cannot be stored
on the function — uploads need object storage (issue: file storage integration).

### PostgreSQL support

Both engines run the whole test suite in CI. What makes PostgreSQL behave like the MySQL the code was written for:

- `database/migrations/…_pgsql_compatibility.php` (no-op on MySQL): text columns become `CITEXT`, so `=`, `LIKE` and
  unique keys are case-insensitive exactly as with MySQL's `*_ci` collations; a `ROUND(float, n)` overload is added.
- `App\Database\PgConnection`: `` `identifier` `` quoting in hand-written SQL is sent as `"identifier"`.
- `App\Support\Sql`: the few date functions that differ (`seconds`, `days`, `addHours`, `joinDistinct`).
- Create the database with the `C.UTF-8` locale (Neon's default). With `en_US` PostgreSQL ignores punctuation when sorting, so lists order differently from MySQL.
- Foreign keys are `DEFERRABLE` so the snapshot import can load tables in any order inside one transaction.

## 4. Moving data from the old system (NestJS / PostgreSQL)

```bash
# on a machine that can reach the old database (uses the old project's Prisma client and .env)
node tools/snapshot-reference-db.mjs "<old project>/apps/api" storage/app/live-snapshot.json

# on the new system
php artisan migrate --force
php artisan scm:import-snapshot storage/app/live-snapshot.json --keep-passwords
php artisan scm:reconcile
```

Ids are preserved, so every link between documents survives. `--keep-passwords` keeps the users' bcrypt hashes;
without it every user gets `SEED_PASSWORD` (demo use). Sessions and idempotency keys are never migrated.
The demo data itself (`database/seed-data/snapshot.json`) was produced with the same tool from the reference seed.

## 5. Regenerating generated files (rarely needed)

- `node tools/prisma-to-laravel.mjs` → schema migration + `app/Models/*` from `docs/reference/schema.prisma`.
  After go-live, change the schema with new Laravel migrations instead.
- `node tools/export-shared.mjs "<old project>/packages/shared/dist/index.js"` → `config/scm.php` +
  `resources/js/shared/constants.json`. After the old system is retired, edit those two files directly (keep them in sync).

## 6. Live vehicle tracking (Wialon — gps.tawasolmap.com)

The fleet's telematics platform is **Wialon** (Gurtam), hosted at `https://gps.tawasolmap.com`. The system reads every
unit's last position through the Wialon Remote API and copies it onto the vehicle (`vehicles.lat/lng/speed_kph/course/
gps_at/gps_online`) plus a trail (`vehicle_positions`, 7 days). Nothing else is written to Wialon.

**Access token (done once by the Wialon account owner — never share the account password):**

1. Open, signed out of Wialon:
   `https://gps.tawasolmap.com/login.html?client_id=B2Bops&access_type=0x300&activation_time=0&duration=0&redirect_uri=https://gps.tawasolmap.com/post_token.html`
   (`access_type=0x300` = view data + online tracking, read-only; `duration=0` = does not expire).
2. Sign in. The page that follows shows `access_token=…` — that 72-character string is the token.
3. Put it in the environment: `WIALON_TOKEN=…` (and `WIALON_BASE_URL=https://gps.tawasolmap.com`, the default).
   On Vercel: `vercel env add WIALON_TOKEN production` then redeploy. Locally: `.env` + `php artisan config:clear`.
4. Check: Settings → integrations shows "Wialon — الحساب <user>"; `GET /api/transport/gps/status` says `connected`.

**Pairing vehicles:** Fleet → vehicle → tab «التتبع الحي» → «اقتران بجهاز» lists the account's units; pick the one
installed in that vehicle (stored in `vehicles.gps_device_id` as the unit IMEI). The new-vehicle form offers the same
list. A paired vehicle whose unit disappeared from the account is listed as "unmatched" on the maps, never placed.

**Sync cadence:** `php artisan scm:gps-sync` runs every minute under the scheduler (`php artisan schedule:work` or a
cron entry `* * * * * php artisan schedule:run`). Without a scheduler (Vercel) the map screens trigger the same sync,
throttled to one provider call per 30 s however many people are watching; the fleet map polls every 20 s. A fix older
than 10 minutes shows the vehicle as offline (greyed marker, with its time). To revoke access, delete the token in
Wialon (user settings → tokens) or clear `WIALON_TOKEN`.

**Trails:** the sync only samples the last fix, so the trail is filled from the unit's **message history** in Wialon
(`messages/load_interval` — every fix the device sent, typically one per 20–30 s while moving). Opening a trail (trip
map, vehicle «التتبع الحي») copies the spans not yet copied (`vehicles.gps_history_from/until`, newest first, 6 h per
request, 3 requests per load — a 48 h window fills in over a few refreshes) into `vehicle_positions` (unique per
vehicle + time; standing-still repeats skipped). The window starts on a whole hour. The line drawn is that track
snapped to the streets by Mapbox Map Matching (`TraceMatcher`, per-hour blocks cached; the growing last block is
re-matched at most every 2 min); it breaks at gaps (no fix for 15 min or a 3 km jump) instead of inventing a road.

## 7. Releasing the 2026-11 operational hardening

One release, five migrations (`2026_11_15_*`), all additive. In this order:

1. `php artisan migrate --force` against the production database. The first migration also adds the new permissions
   and grants (`PermissionSync`), so no separate permission step is needed.
2. Deploy the code.
3. `php artisan scm:credit-sync` once. `customers.balance` used to be a counter that only grew; it is now the customer's
   exposure (unpaid invoices + accepted orders not yet delivered). The command prints every balance it rewrites.
4. `php artisan scm:reconcile` and `php artisan scm:finance-check` — both must report OK.
5. Smoke: `SMOKE_PASSWORD=… node tools/smoke.mjs <url>`.

What changes for the people on the floor from the first minute (all are policies, Settings → policies):

| Policy (default) | Effect |
|---|---|
| `loading.requireOrderScan` (on) | loading needs a scan of the order or one of its cartons; without it: a supervisor with `load.override` and a reason |
| `loading.enforceSequence` (on) | an order is not loaded while the order of a later stop is not packed yet |
| `delivery.requireScan` (on) | the driver scans a carton or gives a reason |
| `delivery.requireSignature` (on) | a delivery needs a signature or a photo |
| `fulfillment.backorderShortPicks` (on) | a short pick becomes a linked backorder order |
| `fulfillment.blockBinOnShortPick` (on) | "not found" freezes the bin row until it is counted |
| `returns.requireBatchOnRestock` (on) | expiry-tracked goods go back on the shelf only with a batch and a date |
| `procurement.rejectedStaysOpen` (on) | rejected goods stay owed by the supplier |
| `transfers.requireCarrier` (off) | when on, a transfer ships only with a vehicle and its driver, or a named carrier |

Clock: on the serverless deployment there is no scheduler. The integration heartbeat runs `OpsHousekeeping`: waiting
orders every 10 minutes; once a day expired quotations, driver / vehicle document alerts and the customer balances.
Under a real scheduler the same work also has its own commands (`php artisan schedule:list`).

Proof-of-delivery files are kept in the database table `stored_files` until object storage (S3) is configured; they
grow with every delivery — connect object storage before the volume does.

## 8. Odoo → OPS live mirror

Design and guarantees: `docs/odoo/ARCHITECTURE.ar.md`. OPS only reads from Odoo; nothing is installed or changed there.

Set-up on a server (once):

1. In Odoo create a dedicated user whose access rights are read-only on the apps to mirror, and an API key for it
   (user → Account Security → New API Key). A person's password works but breaks the mirror the day it is changed.
2. Environment: `ODOO_ENABLED=true`, `ODOO_URL`, `ODOO_DB`, `ODOO_LOGIN`, `ODOO_API_KEY`. Check: `php artisan scm:odoo-status`.
3. `php artisan migrate --force` (tables `odoo_*`).
4. First copy, from a machine with time (never a web request): `php artisan scm:odoo-sync --loop`. It can be stopped and
   started again; it resumes. Measure the size before pointing it at a small production database.
5. Keep it current: a scheduler that runs `scm:odoo-sync` (or calls the signed heartbeat) every minute. Without one the
   mirror only moves when the heartbeat arrives or a screen is open.
6. Optional: `php artisan scm:odoo-master` (the plan, changes nothing), then `--apply`, then `ODOO_PROJECT_MASTER=true`
   for the continuous refresh of products, customers and suppliers. The first `--apply` creates thousands of records:
   it belongs to the command line. The screen applies at most 150 records at a time and says so above that.

Watching it: Integration tower → «أودو» — overview (connection, progress, last run), Odoo stock (Odoo's own figures per
warehouse, lot and expiry; a view, never the OPS ledger), master data (what is linked, what waits, preview and apply),
models, changes, records, sync log.

Measured on the company's Odoo 17 (2026-11-22, 673 models, 1.38 million records in the 242 models that are copied):

| What | Figure |
|---|---|
| A change in Odoo reaching OPS, runs every minute | median 28 s, 98% within a minute, slowest 116 s (131 real changes) |
| First copy | 5,000 to 45,000 records a minute, depending on the model; the journal items are the slowest and largest |
| Size of the complete copy | 1,379,334 records = 1.4 GB of payload, about 1 KB a record (2.0 GB data + 0.1 GB indexes on disk in MySQL) |
| Share of accounting | the `account.*` models are 64% of the size (0.9 GB, 730,000 records) |
| Of the 673 models | 242 copied · 256 technical · 76 computed reports · 65 held back as people's files · 34 the Odoo user cannot read |

A server that received the code before `php artisan migrate` ran does not fail: the «أودو» tab shows one line and the
endpoints answer `ODOO_NOT_INSTALLED`. `scm:odoo-sync` exits quietly where the mirror is not switched on.

If storage is short, pause the models you can live without from the «النماذج» view (a paused model keeps its copy and is
not updated) or list them under `ODOO_EXCLUDE` before the first copy.

People's files (HR, payroll, contracts, attendance, partner bank accounts, the personal part of an employee's record)
are part of the copy — the owner asked for a complete backup — and are READ only by a user who holds `odoo.private`
(the system administrator by default). A deployment that must not hold them sets `ODOO_COPY_PRIVATE=false`: they are
then registered and not copied until a manager takes a model in from its panel on the «النماذج» view (`ODOO_INCLUDE`
does the same for a deployment). After changing the rules: `php artisan scm:odoo-discover` takes in what the rules no
longer keep out, `php artisan scm:odoo-reinspect` re-reads the records in place where the copied fields changed, and
`php artisan scm:odoo-recopy <model> …` drops the copy of the named models and makes it again.

A run that was killed leaves its lock for up to `seconds + 120` s; `Cache::lock('odoo:sync')->forceRelease()` frees it.
While many models still wait for their first copy, one large model takes at most 60% of a run, so the small ones are
not held back for hours.

### 8.1 The copy as a backup

| Command | What it does |
|---|---|
| `php artisan scm:odoo-verify [--heal] [--model=…]` | Compares every record id of every copied model with Odoo and reads a sample of each model again. Exit code 1 when the copy is not complete. `--heal` re-reads in place a model with missing or differing records. Scheduled nightly at 03:30 |
| `php artisan scm:odoo-files [--loop] [--retry]` | Keeps the content of Odoo's attachments on the disk named by `ODOO_FILES_DISK`, checked against Odoo's checksums; resumable. Scheduled every five minutes. The company's Odoo holds 44,569 attachments = 19.6 GB: a server without a writable disk needs an object-storage disk |
| `php artisan scm:odoo-export [dir]` | Writes the copy as portable files: one gzip JSON-lines file per model + `manifest.json` with counts and SHA-256 |
| `php artisan scm:odoo-import-archive <dir> [--check] [--force]` | Checks an archive against its manifest, or loads it into the mirror tables (models are registered as paused: a restore never depends on Odoo) |

What the copy does by itself: a record deleted in Odoo is marked, never erased; a model that loses 50 records or more
and 30% of what was looked at in one check is put ON HOLD instead (`ODOO_GUARD_MIN`) — the overview shows it, and
resuming the model from its panel confirms the deletions; Odoo being down stops the updates only.

### 8.2 The OPS sections that follow Odoo

Every application the company uses in Odoo has an OPS section of its own, loaded from the copy and kept in step with
it (`docs/odoo/COVERAGE.ar.md`; contracts in `docs/odoo/WAVE2.md`, `WAVE3.md`, `WAVE4.md`). Areas:
`approvals`, `crm`, `hr`, `pricing`, `commissions`, `portal`, `preq`, `workspace`, `payroll`, `pos`, `planning`,
`credit`, `banking`, `tax`, `insights`.

| Step | Command |
|---|---|
| Tables and the new permissions of the release | `php artisan migrate --force` (each section's migration also runs `PermissionSync::ensure()`) |
| Where every section stands | `php artisan scm:odoo-project` — copied? loaded? how many changes wait |
| What a first load would do | `php artisan scm:odoo-project <area>` (counts only, writes nothing) |
| First load | `php artisan scm:odoo-project <area> --apply` (idempotent; from the command line, never a web request) |
| Afterwards | every run applies what the change feed reports, while `ODOO_PROJECT_<AREA>` is true |
| Handing a section over to OPS | set its switch to false: the records that came from Odoo become editable in OPS |

A section is fed by the runs ONLY after its first whole load (table `odoo_projections`): until a person applied the
plan once, the runs leave it alone, so no section is ever half-filled by the changes that happen to arrive first. A
section whose migration has not run is not an error of a run. The overview of the «أودو» tab shows the same state in
the card «أقسام النظام التي تتبع أودو».

Linking people: an Odoo user is the OPS user with the same e-mail or login — give the OPS users their work e-mail and
the next load links their history (approvals they decided, opportunities they own, their expense claims).

Odoo refuses the connection user the records of some custom modules (demand forecast, purchase plan, deferred credit,
brand owners, expiry notifications, attendance sheets). Their shapes are known from Odoo's dictionary
(`docs/odoo/shapes/unreadable.txt`) and the OPS sections are built for them. Every discovery (daily) asks Odoo again:
the day an Odoo administrator grants the access, those models enter the copy by themselves and
`php artisan scm:odoo-project <area> --apply` loads them.

The archive («أرشيف أودو») is built with `php artisan scm:odoo-archive-index` once after the first copy (resumable,
`--seconds=N` for short slices); the runs keep it current afterwards and an hourly catch-up is scheduled.

### 8.3 Credentials are never copied

Whatever the model, a field that works as a key is left out of the copy (`config/odoo.php` → `never_fields`,
`never_model_fields`): passwords, portal and signup tokens, API keys, the attendance PIN and kiosk key, a discussion
channel's invitation key, and the e-invoicing unit's certificate with its secret (`…_csid_json`, the private key,
the certificate request). After changing the rule: `php artisan scm:odoo-reinspect`, then let the runs re-read the
models it names — the values leave the copy with that re-read. Identity and bank-account numbers are data, not keys:
they are copied and shown only to holders of `odoo.private` (`identity_fields`, `personal_fields`).

### 8.4 Releasing the sections that follow Odoo

Order on a server, after the code is deployed:

1. `php artisan migrate --force` — every section creates its tables and syncs its permissions.
2. Where routes are cached, nothing more: the providers are in `bootstrap/providers.php`.
3. A scheduler (or the signed heartbeat) runs: `scm:odoo-sync` every minute, `scm:odoo-files` every five minutes,
   `scm:odoo-verify --heal` nightly, `scm:odoo-archive-index` hourly, `scm:tax-einvoices` every five minutes,
   `scm:planning-compute` every minute, `scm:credit-deadlines` every fifteen minutes, `scm:insights-alerts` /
   `scm:insights-expiry` daily, `scm:insights-history` every fifteen minutes, `scm:hr-alerts` daily.
4. First load of every section, from the command line: `php artisan scm:odoo-project` lists them, then for each
   `php artisan scm:odoo-project <area>` (the plan) and `--apply`. The archive: `php artisan scm:odoo-archive-index`.
   The invoice history of the brand dashboards: `php artisan scm:insights-history --seconds=50` until it reports done.

What a person decides after the first load (each section says it on its own screen):

| Section | Decision |
|---|---|
| All | Give OPS users their work e-mail or the same login as in Odoo: their history (approvals, opportunities, claims, commissions, cashier sessions) becomes theirs at the next load |
| Banking | Link each Odoo bank or cash journal to an OPS bank account (or adopt it) on the «الدفاتر» tab: none matches by IBAN, number or name today |
| Tax & e-invoicing | The tax profile (legal name, VAT number) and the onboarding of an e-invoicing unit with the authority. Until then e-invoices are made locally and nothing is sent |
| Point of sale | The warehouse of the register and the finance account of each payment method |
| Demand planning | The mapping of Odoo warehouses to OPS warehouses on its settings tab |
| Brands | A role for outside brand owners (`page.insights` only) if they are to sign in |
| Credit, planning, brands, expiry, attendance sheets | Odoo refuses the connection user these records. Granting read access in Odoo is enough: the daily discovery takes them in, then `scm:odoo-project <area> --apply` |
