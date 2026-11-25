# Odoo → OPS: the brief of every section team (waves 3 and 4)

One file a team can be handed. Read, in this order: this file's "Rules for every team", your own brief below,
your section of `docs/odoo/WAVE3.md` or `docs/odoo/WAVE4.md` (scope, folder, page, permissions, projection class, test
database, migration name), `docs/odoo/WAVE2.md` §0 §1 §8, `docs/CONVENTIONS.md`, `docs/FRONTEND.md`.

## Rules for every team

1. **Odoo is never called and never written to.** Everything comes from the local copy: `odoo_models`, `odoo_records`
   (`data` = the record as Odoo returned it; a many2one is `[id, "label"]`, "empty" is `false`, a datetime is UTC text —
   use `App\Integration\Odoo\Projection\Val`), `odoo_changes`. Read real shapes with a throw-away PHP script in your
   scratch folder. Models the connection user cannot read: their shapes are in `docs/odoo/shapes/unreadable.txt` —
   design from that file, write the projection against those field names, prove it with
   `tests/Feature/Integration/FakeOdoo.php` records of that shape, and say plainly that no real record could be loaded.
2. **The development database `scm_ops` holds the live copy: never run migrate, migrate:fresh, db:wipe or any delete
   on it.** Tests run only on the test database of your row:
   `cd "/c/Users/Lenovo/Desktop/ملف تجربه الاووبس المطور" && export PATH="$USERPROFILE/.scmops/php:$PATH" && DB_DATABASE=<yours> php artisan test --no-ansi tests/Feature/<Folder>`
   (each run rebuilds that database from ALL migration files in the tree). One test command at a time: the machine is
   small and other teams test too. No server, no browser, no `npm run build`, no git command that changes anything.
3. **Shared tree.** Write your classes BEFORE the migration or route file that references them; never leave a
   half-written migration or route file on disk. A failure caused by ANOTHER team's file: wait a minute, run again,
   do not touch their files.
4. **Own files only**: `app/<Folder>/**`, `routes/api/<name>.php`, `resources/js/pages/<page folder>/**`,
   `tests/Feature/<Folder>/**`, your migration. Never edit `config/*`, `resources/js/shared|router|layout|components`,
   `routes/console.php`, `routes/api.php`, `app/Integration/Odoo/*`, `app/Finance/*`, `app/Services/*`, other
   sections' folders. What you need there goes in your report under "wiring" (exact lines).
5. **Engine-neutral code**: MySQL 8.4 and PostgreSQL 17 both run the suite at integration (`App\Support\Sql`; no
   MySQL-only JSON functions on `odoo_records` in a hot path — read the copy in chunks and aggregate in PHP or in your
   own tables). The migration ends with `PermissionSync::ensure()` and has the PostgreSQL citext block.
6. **Model work to imitate** (integrated, green on both engines): `database/migrations/2026_11_23_000300_crm.php`,
   `app/Crm/**` (services, controllers, ownership rule `…_OWNED_BY_SOURCE`), `app/Crm/OdooCrmProjection.php` (plan /
   apply / consume, idempotent, `<AREA>_NOT_INSTALLED` when its tables are missing, links by identity only),
   `routes/api/crm.php`, `resources/js/pages/crm/**` (page with tabs, lists, drawers, query-string state, phone
   layout), `tests/Feature/Crm/**`.
7. **Projection base** `App\Integration\Odoo\Projection\OdooProjection`: implement `key()`, `models()`,
   `project(OdooRecord $r)`. Each section has its own place in the feed, so listing a model another section also reads
   is fine. The runs feed a section only after its first whole `apply()`.
8. **People's data** (pay, identity numbers, bank accounts, personal papers): never in a log, an audit value, a test
   name or a report — names of fields and counts only; returned by the API only to the permissions your brief names.
9. **Windows shell**: never put backticks or `$` inside a double-quoted shell string, and a heredoc loses backslashes
   — write scripts and PHP with the Write tool into your scratch folder
   `C:\Users\Lenovo\AppData\Local\Temp\claude\a71d37b3c668f775\265ded0f-fa55-4a47-8175-db5518594dad\scratchpad\<team>\`
   and run them. Formatter on your own PHP files only: `php vendor/bin/pint <files>`. Client check:
   `node tools/check-vue.mjs resources/js/pages/<page folder>`.
10. **Quality bar**: what a team of senior engineers would sign. Every list: search, filters, sort, paging, empty and
    error states, phone layout. Every action: permission-checked on the server, audited (`AuditService`), bilingual
    (Arabic first). Every state machine refuses illegal moves with a coded error. Money is exact (decimal, rounded
    once). Nothing is deleted that can be voided. Concurrency where two people can act on one record: row locks.
11. **A team that takes over unfinished work**: the files already in your folders were written by engineers of the
    same team who had to stop. Read every one of them first; keep what is right, fix what is wrong, finish what is
    missing (usually: the migration, the route file, the client pages, the tests). Do not start again from nothing.
12. **Hand back** (final message): 1. files; 2. endpoints with the permission of each; 3. tables; 4. what the tests
    prove (numbers) and what was NOT verified; 5. "wiring" for shared files; 6. the projection's plan on the real copy
    (counts only) by a script of your own that calls `->plan()` against `scm_ops` — it writes nothing. Facts only.
13. **Tests live together.** At integration the WHOLE suite runs in one database, folders in alphabetical order, with
    the clock fixed at 2026-09-18 10:00 (`tests/ApiTestCase.php`). Stories of other sections leave rows in the shared
    tables (customers, products, invoices, journals, bank accounts, users' documents of September 2026). So: make your
    own master data (never the demo data's), never assert a ledger-wide or table-wide total or an exact list of a
    shared table — assert on your own records, or on the difference from a baseline you took first; lists you test
    for order need a total, engine-neutral order (explicit NULL placement, a final sort by id).

## P6 — Payroll, time off, attendance (`docs/odoo/WAVE3.md` §P6)

Test database `scm_ops_test_w3f`, tests in `tests/Feature/Payroll/`, scratch folder `p6payroll`.
Builds on `app/Hr` (read, do not change): `App\Hr\Services\Workforce` (`active()`, `employee()`, `ofUser()`,
`reportsOf()`, `contractOn()`, `contractsBetween(from, to)`, `Workforce::pay(Contract)`, `reimbursementsDue()`,
`bankOf()`), `ClaimsService::pay(...)` marks an expense reimbursement paid from a payroll run, `ContractsService`;
helpers `App\Hr\Support\Hr::seesPrivate()`, `ClaimAccess::employeeId($user)`, `Source::locked()`. Posts through
finance without changing it (`LedgerService`, `ExpensesService`, `PaymentsService`, `Support/Coa.php`,
`AccountMap.php`): a balanced payroll journal if the ledger service supports it cleanly, else describe the hook.
Odoo holds (people's pay — names of fields and counts only): `hr.payslip` (13), `hr.payslip.line`, `hr.payslip.run`,
`hr.payroll.structure` (7), `hr.salary.rule` (104) with categories, payslip inputs, `hr.work.entry` (11,610) and
types, `hr.leave.type` (6), `hr.leave` (1), `hr.leave.allocation`, `hr.attendance` (2), `resource.calendar` with its
attendance lines and `resource.calendar.leaves`, the Skills app (`hr.skill*`, `hr.employee.skill`,
`hr.resume.line` 31), and — shape only — the attendance sheets (`attendance.sheet*`).
Also in scope: skills and résumé of an employee as a tab; the attendance sheet (period sheet per employee from the
attendance records and the work schedule: planned and actual sign in / out, late, early leave, overtime, absence,
weekend / holiday / leave days; an attendance policy; batches per department; draft → confirmed → approved; the
approved sheet's totals feed that month's payslip).
Self-service: roles that hold `leave.request` reach the page — a user with neither `payroll.manage` nor
`payroll.approve` sees only HIS OWN leave, attendance and payslips. Readable otherwise only with `payroll.manage` /
`payroll.approve`; bank account numbers only where a transfer file is built.
Bar: a payslip's lines add up to its net to the halala and a run's journal balances; a run is reproducible and
explains every figure; GOSI and every rate are configuration with effective dates; a month is never paid twice to
one contract; an approved run is immutable (reversal or adjustment in the next run); leave balances are a ledger that
reconciles; attendance corrections keep the original and say who changed what and why.

## Q1 — Point of sale (`docs/odoo/WAVE4.md` §Q1)

Test database `scm_ops_test_w4a`, tests in `tests/Feature/Pos/`, scratch folder `q1pos`.
Calls, without changing: prices `App\Pricing\Services\PriceService` (`customerPrice`, `customerPrices`); stock
`app/Services/Inventory/**` exactly as fulfilment issues it (`app/Services/Fulfillment/**`); accounting
`app/Finance/Services` (`CustomerInvoiceService`, `PaymentsService`, `LedgerService`, `PeriodService`), the bank and
cash accounts; products, barcodes, customers; the VAT policy of sales documents; `NumberingService`.
Odoo holds (history, last sale October 2025): `pos.config` (1), `pos.payment.method` (3), `pos.session` (29),
`pos.order` (38), `pos.order.line` (91), `pos.payment` (41), `pos.bill` (15), `pos.category`.
No terminal, printer driver or outside service: a card payment is recorded with its reference; receipts are printable
HTML (80 mm and A4).
Bar: a sale is one transaction (stock out, order, payments, accounting — all or none, tested with a failure injected
at each step) and idempotent by a client key; totals, VAT and payments reconcile to the halala; a refund never
exceeds what was sold and returns stock through the existing service; one open session per register and cashier;
closing counts cash by denomination and posts the difference with a reason; a closed session is immutable; stock
below zero is refused unless the register allows it; closed periods respected; the selling screen works with a
barcode scanner, a tablet and a phone and keeps a held sale across a reload.

## Q2 — Demand & purchase planning (`docs/odoo/WAVE4.md` §Q2)

Test database `scm_ops_test_w4b`, tests in `tests/Feature/Planning/`, scratch folder `q2planning`.
The twelve `b2b.demand.forecast*` / `b2b.purchase.plan*` shapes in `docs/odoo/shapes/unreadable.txt` ARE the
specification of the company's own module: the engine does the same work natively.
Reads / calls, without changing: OPS sales and deliveries (`app/Services/Sales`, `Fulfillment`, `Delivery`); Odoo's
history in the copy (`sale.order`, `sale.order.line`, `stock.picking`, `stock.move`, `stock.move.line`,
`stock.warehouse`, `stock.location`, `product.product`, `res.partner`) — an Odoo product / customer / supplier is an
OPS one through `int_external_refs` (system `odoo`); a mapping Odoo warehouse → OPS warehouse of your own that a
holder of `forecast.manage` fills in, never guessed; stock and what is booked (`app/Services/Inventory`,
`Procurement`, `Inbound`); reorder settings of a product through `ProductsService`; prices and lead times
`PriceService::bestSupplierPrice` / `supplierPrice`; a plan line becomes a purchase request through
`app/Services/Procurement/PrService.php`.
Bar: deterministic and self-explaining (history periods, method and fallback with its reason, on hand / booked,
projected balance per period, first shortage, safety stock, lead time and its source, quantity after minimum order
and rounding, order-by date, price and its source); each method tested against hand-computed cases incl. no history,
one order, a gap, a return, a product that started mid-window, zero demand; budget allocation tested (scores,
weights, critical first, partial funding, carry forward); a frozen run is immutable; a plan line creates its purchase
request once; large runs computed in resumable slices (a "computing" state with progress, a command and an endpoint
that advance it); Excel / CSV export. Report also one real forecast computed inside YOUR test database on a copy of
the real history, with three items checked by hand.

## Q3 — Credit accounts (`docs/odoo/WAVE4.md` §Q3)

Test database `scm_ops_test_w4c`, tests in `tests/Feature/Credit/`, scratch folder `q3credit`.
The ten `b2b.credit.*` shapes are in `docs/odoo/shapes/unreadable.txt`. For a staged request with approvers,
deadlines, an audit, comments and files read `app/Approvals/**`; for personal files kept out of the generic
attachments table read `app/Hr/Services/FilesService.php`. Acts on the customer only through
`app/Services/Sales/CreditService.php`, `app/Services/Master/PartnersService.php` (read how the credit limit and terms
are set today); notifications `NotifyService`; numbering `NumberingService`.
Bank details, national ids and the nine papers: returned only to `credit.review` / `credit.approve` /
`credit.manage` (and to the requester for his own request, masked where not his to see), never logged.
No outside service: e-signature is an adapter interface with a working "signed agreement uploaded by hand"
implementation; e-mail and WhatsApp are logged as skipped until a provider is configured.
Bar: the workflow is data and the engine enforces it (stage requirements, any / all approval, target hours with
reminder and escalation tested with travelled time, nobody approves his own request, decisions are never edited);
the approved limit, days and term reach the customer once, through the existing services, with the previous values
recorded; the accepted terms version is frozen on the request; a bilingual printable agreement.

## Q4 — Banking & reconciliation (`docs/odoo/WAVE4.md` §Q4)

Test database `scm_ops_test_w4d`, tests in `tests/Feature/Banking/`, scratch folder `q4banking`.
Builds on finance without changing it: the bank accounts (`FinanceController::banks`), `PaymentsService`,
`LedgerService` (how a balanced journal is posted, approved, reversed), `PeriodService`, `Coa`, `AccountMap`.
Odoo holds: `account.journal` (34), `account.bank.statement.line` (30), `account.bank.statement`,
`account.reconcile.model` (2) with lines, `account.payment` (11,808), `account.partial.reconcile` (73,013),
`account.full.reconcile` (54,738), `account.payment.term` (9), `res.partner.bank` (restricted: names and counts only).
Account numbers and IBANs in full only to holders of a banking permission.
Bar: opening + lines = closing enforced; a match never leaves a cent unexplained (inside a write-off rule's
tolerance and posted, or refused); a line or a document is never matched twice (row locks, tested under a simulated
race); import is repeatable (fingerprint) and reports per line taken / duplicate / refused; a reconciled statement is
immutable until reopened with a reason; un-matching restores the previous state incl. the write-off journal
(reversed, not deleted); closed periods respected; the reconciliation statement reconciles to zero.

## Q5 — Tax & e-invoicing (`docs/odoo/WAVE4.md` §Q5)

Test database `scm_ops_test_w4e`, tests in `tests/Feature/Tax/`, scratch folder `q5tax`.
Builds on finance without changing it (`CustomerInvoiceService`, `DebitNoteService`, `PaymentsService`,
`LedgerService`, `FinanceReportsService::vatReturn`, `Coa`, `AccountMap`) and on how sales and procurement documents
carry VAT. Odoo holds: `account.tax` (22), `account.tax.group` (3), `account.tax.repartition.line` (114),
`account.fiscal.position` (3), `account.edi.document` (8,606), `account.edi.format` (1).
Signing keys and certificates of an e-invoicing unit: stored encrypted (Laravel Crypt), never logged, never returned
by any endpoint. No real credentials exist: the module runs "not onboarded" by default and says so on the screen;
nothing is ever sent to the tax authority from tests (`Http::fake` only). Check technical details against the
authority's published specifications if your web tools reach them; list what you verified against a source and what
you wrote from knowledge only; never send project data outside.
Bar: the VAT return's every figure drills down to its documents and its total is reconciled against the ledger's VAT
accounts; e-invoice numbering (counter + previous-hash chain) is gap-free and safe under concurrent posting (row
locks, tested).

## Q6 — Brands, expiry & insights (`docs/odoo/WAVE4.md` §Q6)

Test database `scm_ops_test_w4f`, tests in `tests/Feature/Insights/`, scratch folder `q6insights`.
Shapes of `brand.owner`, `brand.franchisee.mapping`, `brand.owner.dashboard.alert`, `expiry.notification.level`,
`expiry.notification.log` are in `docs/odoo/shapes/unreadable.txt`. Find out first what OPS ALREADY shows and never
build a second copy: stock, lots, expiry and movements (`app/Services/Inventory/**`, the balances / ledger / batches
pages — the "stock card" may already be the ledger: add only what is missing); sales, receivables, ageing,
profitability (`FinanceReportsService`), returns; products carry `brand`; Odoo's history in the copy for the years
before OPS; notifications `NotifyService`; the existing dashboards.
A brand owner is an outsider looking in: a user linked to a brand owner sees ONLY his brand's products and his
franchisees' figures, and only what his access flags allow (margin and cost also need `inventory.view_cost`);
enforced in the service on every query, tested with two owners and a user who is neither.
Bar: every dashboard figure is reproducible from a list the user can open and its definition is written beside it;
the eight alert types are computed by documented, tested rules with configurable thresholds, each raised once per
occurrence; an expiry alert is raised once per lot and level; figures that overlap existing reports agree with them
to the halala (tested).
