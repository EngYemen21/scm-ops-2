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
