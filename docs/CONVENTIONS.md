# B2B ops — Laravel conventions

This system is a port of the reference NestJS/React system (`docs/reference/`) to **Laravel 13 + MySQL 8 + Vue 3 +
Tailwind 4**. The HTTP contract is kept identical on purpose: same paths, methods, permissions, camelCase JSON, error
codes. That is what lets the reference test-suites be ported 1:1 and the Vue client mirror the React one.

Reference source to port from: `C:\Users\Lenovo\Desktop\ملف برنامج بي تو بي اوبس` (`apps/api/src/modules/<module>`,
`apps/api/test`, `apps/web/src`, `packages/shared/src`, `docs/`).

## Running things (Windows, portable toolchain)

```bash
export PATH="$USERPROFILE/.scmops/php:$PATH"          # php 8.3 + composer.phar live here
php artisan serve --port=8000                         # API + app
php artisan migrate:fresh --seed                      # rebuild + demo data (needs SEED_PASSWORD in .env)
php artisan scm:reconcile                             # ledger = balances check (exit 1 on mismatch)
php artisan test                                      # whole suite on MySQL database scm_ops_test
DB_DATABASE=scm_ops_test_sales php artisan test tests/Feature/Sales   # one domain on its own database
```

MySQL 8.4 (portable) listens on 127.0.0.1:3307; start it with
`"$USERPROFILE/.scmops/mysql/bin/mysqld.exe" --defaults-file="$USERPROFILE/.scmops/mysql/my.ini"`.

Shell gotcha: bash heredocs on this machine strip backslashes — **never write PHP through a heredoc**; create and edit
PHP files with the file tools.

## Layout and ownership

| What | Where |
|---|---|
| Routes of a domain | `routes/api/<domain>.php` (auto-loaded inside the `auth.api` + `idempotent` group) |
| Controllers | `app/Http/Controllers/Api/<Domain>/*Controller.php` — thin: validate, call a service, return |
| Business logic | `app/Services/<Domain>/*Service.php` |
| Tests | `tests/Feature/<Domain>/*Test.php` extending `Tests\ApiTestCase` |

Shared code — **do not modify** (propose changes instead): `app/Support/*`, `app/Services/Core/*`,
`app/Services/Inventory/InventoryService.php`, `app/Services/Exceptions/ExceptionsService.php`, `app/Http/Middleware/*`,
`app/Models/*` (generated), `bootstrap/app.php`, `routes/api.php`, `config/*`, `database/*`, `tests/ApiTestCase.php`.
A domain may add NEW helper classes inside its own `app/Services/<Domain>/` folder.

## Patterns

**Controller**

```php
public function approve(Request $request, string $id)
{
    $data = $request->validate(['note' => 'nullable|string']);          // keys are camelCase, exactly as the client sends
    return $this->service->approve(AuthUser::current(), $id, $data['note'] ?? null);
}
```

- Validation failures become `400 VALIDATION / INVALID_INPUT` with `details: [{path, message}]` automatically.
- Action buttons POST with **no body**; `validate()` with only optional rules handles that. Never require a body for an action.
- Return arrays or models; models serialise to **camelCase** (`BaseModel::toArray`). To add computed fields:
  `return $po->toArray() + ['totals' => $totals];`. Loaded relations serialise themselves (camelCase, nested).
- Look documents up by **id or number**: `Model::where('id', $x)->orWhere('number', $x)->first() ?? throw AppError::notFound('PO_NOT_FOUND', 'أمر الشراء غير موجود', 'PO not found');`
  No route-model binding (ids imported from the old system are cuids, new ones are ULIDs).
- Routes declare permissions: `->middleware('perm:po.approve')` (keys in `config/scm.php` → `PERMISSIONS`). Reads that the
  reference leaves open stay open.

**Service**

- Attributes are **snake_case** (`$po->supplier_id`, `$line->received_qty`); request/response keys are camelCase. Map explicitly.
- Every multi-row change runs in `DB::transaction(function () { ... })`. Nested calls join the outer transaction.
- Errors: `throw AppError::rule('PO_TRANSITION', 'عربي', 'English')` — factories `validation|unauthorized|forbidden|notFound|conflict|rule`.
  Keep the **same error codes** as the reference (tests and the client depend on them).
- State machines: `Sm::assert('PO_TRANSITIONS', $from, $to, 'PO_TRANSITION')` / `Sm::can(...)`; tables in `config/scm.php`.
- Audit every mutation: `$audit->log($user, ['action' => 'PO.APPROVE', 'entityType' => 'PurchaseOrder', 'entityId' => ..., 'entityNumber' => ..., 'oldValue' => ..., 'newValue' => ...])`
  and status changes with `$audit->status($user, 'PurchaseOrder', $id, $number, $from, $to, $note)`.
- Activity / notifications: `$notify->activity($user, 'PurchaseOrder', $id, $number, 'نص عربي', 'English', ['proc', 'finance'])`; outbox: `$notify->event('po.sent', [...])`.
- Numbers: `$numbering->next('PO')` (same keys as the reference: PR, RFQ, SQ, PO, SHP, GRN, TX, RSV, QT, SO, OC, FO, PL, TRP, RTN, TRF, CNT, EXC …).
- Policies: `$settings->get('sales.vatPct')` (defaults in `SettingsService::DEFAULTS`).
- **Stock never changes outside `InventoryService`** (`post`, `reserve`, `release`, `setQuarantine`, `setBlocked`); all of them require an open transaction.
  Read its docblocks. Virtual bins: STG-IN, STG-OUT, PACK, QTN-01, RET-01, DMG-01.
- Exceptions raised while **rejecting** an operation must be raised outside the rolled-back transaction, or they vanish with it.
- Lists: `Paging::from($request)->paginate($query, $mapFn)` → `{items,total,page,pageSize,pages}`. Always add a tie-break
  (`->orderByDesc('created_at')->orderByDesc('id')`).

**Prisma → Eloquent cheatsheet**

| Prisma | Eloquent |
|---|---|
| `include: { lines: { include: { product: true } } }` | `->with(['lines.product'])` |
| `contains, mode: 'insensitive'` | `->where('col', 'like', "%{$q}%")` (MySQL collation is case-insensitive) |
| nested `create: [...]` | create the parent, then the children explicitly |
| `{ increment: n }` | `->update(['col' => DB::raw("col + {$n}")])` with an integer you validated, or read-modify-write on a locked row |
| `findUniqueOrThrow` | lookup + `AppError::notFound(...)` with the reference's code |
| `$queryRaw ... FOR UPDATE` | `->lockForUpdate()` |
| `Decimal` | cast `decimal:2` → **string** in JSON (same as the reference); use `(float)` for arithmetic |
| `String[]` / `Json` | json column cast to `array` |
| `new Date()` | `now()`; datetimes are stored in UTC with milliseconds |

### SQL that runs on MySQL and PostgreSQL

Use the query builder whenever possible — it is portable. In hand-written SQL: quote camelCase aliases with backticks (AS `onHand`), test booleans as `b.blocked` / `NOT b.blocked` (never `= 1`), take date arithmetic from `App\Support\Sql`, order NULLs explicitly (`ORDER BY eta IS NULL, eta`), and never rely on engine-specific locks or functions (`GET_LOCK`, `TIMESTAMPDIFF`, `GROUP_CONCAT` …). Run the suite on both engines before pushing SQL changes: `DB_CONNECTION=pgsql DB_PORT=… php artisan test` (CI does it anyway).

## Tests

- Extend `Tests\ApiTestCase`: `getAs/postAs/patchAs/putAs/deleteAs($username, $url, $body)`, `expectOk()`, `expectRejected($res, 'CODE', [422])`.
  Demo users: admin, sales, wm, inv, proc, disp, worker, driver, gm, finance.
- The database is rebuilt and seeded once per run; a test class is a sequential story. Create your own documents with
  unique codes (`self::uid()`); never rely on another class's data.
- Port the reference tests that touch the module (`apps/api/test/*.e2e-spec.ts`, `src/modules/<module>/*.spec.ts`) and add:
  the happy path end to end, each business-rule rejection (with its error code), a permission denial (403 + nothing
  changed), and `reconcile()['ok']` after any stock movement.
- Format with `vendor/bin/pint <your paths>` and lint with `php -l` before finishing.

## Receiving: one shipment = one GRN, the rest arrives on a follow-up shipment

A shipment is received once (`inspecting → putaway`). What the supplier did not deliver stays open on the purchase order
(`partial`) — and is received through a **follow-up shipment**: `POST /api/inbound/shipments/backorder { po, eta? }`
(`shipment.receive`, idempotent). It creates an `expected` shipment whose lines are exactly the open quantities of the
PO lines, so the existing no-over-receipt rule keeps working per shipment line. The same call re-opens a delivery whose
expected shipment was cancelled. Rules (`InboundService::backorderState`): the PO must be `sent | confirmed | partial`,
something must be open, and only ONE shipment per order may be waiting for goods (`expected | arrived | inspecting`) —
the PO row is locked, so two users cannot open two. `GET /inbound/shipments/{id}` carries `backorder: { allowed, openQty,
openLines, reasonAr, reasonEn, shipment }` so the client shows the button or the reason, never a dead end.

In the client every shipment row shows its next action, and the shipment opens in a drawer (`ShipmentsTab.vue` →
`ShipmentPanel.vue`) whose "next step" box names the step, the permission it needs and — when the role lacks it — says so.
