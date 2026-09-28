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
