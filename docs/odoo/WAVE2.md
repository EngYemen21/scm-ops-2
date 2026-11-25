# Odoo → OPS, wave 2: a complete backup, an archive people can use, and the sections OPS lacked

Owner's request (2026-11-22): OPS must hold a backup of **all** of Odoo's data so that nothing is lost if Odoo fails;
all of Odoo's information must be usable inside OPS; and what Odoo has and OPS lacks must be built in OPS.

Wave 1 (`docs/odoo/ARCHITECTURE.ar.md`) built the read-only live copy. This file is the contract of wave 2 for the
teams working in parallel. Read it together with `docs/CONVENTIONS.md` and `docs/FRONTEND.md`.

## 0. Ground rules for every team

1. **Odoo is never written to, and never called by you.** Everything you need from Odoo is already in the local copy:
   `odoo_models` (one row per Odoo model: `fields` = name → Odoo type, `mirrored_count`), `odoo_records`
   (`model`, `odoo_id`, `display_name`, `write_date`, `active`, `data` = the record as Odoo returned it, `deleted_at`),
   `odoo_changes` (the feed). Read the shapes from there (`App\Integration\Odoo\Models\*`), with a throw-away PHP script
   in your scratch folder. A many2one is `[id, "label"]`, "empty" is `false`, a datetime is UTC text:
   use `App\Integration\Odoo\Projection\Val`.
2. **The development database `scm_ops` holds a three-hour copy of Odoo. Never run `migrate:fresh`, `migrate:rollback`,
   `db:wipe` or `php artisan migrate` on it**, and never delete from `odoo_*`. Your tests run on the test database
   assigned to you: `DB_DATABASE=<yours> php artisan test --no-ansi --filter=<YourTests>` (tests rebuild that database).
   PostgreSQL is run at integration, not by you: write engine-neutral code (`App\Support\Sql`, no raw MySQL-only SQL).
3. **Own files only.** Never edit: `config/scm.php`, `config/odoo.php`, `resources/js/shared/*`, `resources/js/router/*`,
   `resources/js/layout/*`, `resources/js/components/*`, `routes/console.php`, `routes/api.php`, `app/Integration/Odoo/*`
   (except the folder given to you), `bootstrap/*`, `.env*`, other teams' folders. If you need something there, say so in
   your report under "wiring".
4. No server, no browser, no `npm run build`, no `git` commands that change anything, no installs. Client check:
   `node tools/check-vue.mjs resources/js/pages/<your folder>`. Formatter: `php vendor/bin/pint <your files only>`.
5. People's files (pay, contracts, identity papers, bank accounts) are never printed in a report, a log or a test name:
   names of fields and counts only.
6. Windows shell notes: prefix every command with `cd "/c/Users/Lenovo/Desktop/ملف تجربه الاووبس المطور" &&` and
   `export PATH="$USERPROFILE/.scmops/php:$PATH"`; never put backticks or `$` inside a double-quoted shell string —
   write scripts with the Write tool and run them.
7. Quality bar: what a team of senior engineers would sign — every list has search, filters, sort, paging, empty and
   error states and a phone layout; every action is permission-checked on the server, audited, and answers in Arabic
   and English; every state machine refuses illegal moves; nothing is deleted that can be voided.

## 1. What is already in place for you

- **Pages and permissions are registered** (do not touch the shared files): sidebar keys `odoo`, `approvals`, `crm`,
  `hr` → `resources/js/pages/odoo/OdooArchivePage.vue`, `approvals/ApprovalsPage.vue`, `crm/CrmPage.vue`,
  `hr/HrPage.vue` (a missing file renders "coming soon"). Permissions: `page.odoo`, `page.approvals`, `page.crm`,
  `page.hr`, `odoo.private`, `approval.request`, `approval.manage`, `crm.manage`, `hr.manage`, `hr.private`,
  `expense.claim`, `expense.review` — granted by role in `config/scm.php` (`ROLE_PERMISSIONS`); `super` holds all.
- **API route files are picked up by themselves**: create `routes/api/<module>.php` (see `routes/api/finance.php`).
- **A module of its own** lives in `app/<Module>/{Http,Models,Services,Support}` like `app/Finance`.
- **From the copy to an OPS section**: extend `App\Integration\Odoo\Projection\OdooProjection` (read its header). You
  implement `key()`, `models()` and `project(OdooRecord $r)`; it gives you `plan()`, `apply()`, `consume()`, links
  (`linked()`, `link()`), the service user (`actor()`), counting (`count()`), and the guarded loop. The runs call your
  `consume()` after every synchronisation; `php artisan scm:odoo-project <area> [--apply]` does the first load.
  Class names are already registered: `App\Approvals\OdooApprovalsProjection`, `App\Crm\OdooCrmProjection`,
  `App\Hr\OdooHrProjection`.
- **Ownership rule** for records that came from Odoo: tables carry `source_system` (`'odoo'` or null) and
  `external_ref` (the Odoo id as text). While `OdooProjection::owns('<area>')` is true such records are Odoo's: OPS shows
  them, lets people add what is OPS's own (notes, attachments) and refuses edits of what Odoo owns with a clear
  message (`…_OWNED_BY_SOURCE`, 422). Records created in OPS are fully OPS's. When the switch is turned off everything
  is editable: the area has been handed over.

## 2. Team B — backup guarantee (integration lead; `app/Integration/Odoo/*` core)

Complete scope of the copy (people's files with restricted reading, correspondence and value-change log, attachment
list, Odoo's dictionary, per-company values), `scm:odoo-verify` (is the copy complete?), `scm:odoo-export` /
`scm:odoo-import-archive` (portable archive), `scm:odoo-files` (attachment content, table `odoo_files`:
`attachment_id` unique, `disk`, `path`, `size`, `checksum`, `stored_at`; download `GET /api/odoo-backup/files/{id}`),
the deletion guard, and the hook that calls the archive indexer after every page the mirror writes.

## 3. Team A1 — the archive, server side (`app/Integration/Odoo/Archive/**`)

Everything Odoo holds, readable in OPS as documents — not as raw technical records. Test database
`scm_ops_test_platform`. Files: `app/Integration/Odoo/Archive/**`, `config/odoo_archive.php`,
`database/migrations/2026_11_23_000100_odoo_archive.php`, `routes/api/odoo_archive.php`,
`tests/Feature/Integration/OdooArchive*Test.php`.

**Index.** One table `odoo_docs` (you design the columns and indexes; it must serve every list below from an index on
MySQL 8 and PostgreSQL): one row per indexed record with typed columns — kind, number, title, partner (id + name),
product (id), parent (model + id, for lines / children / messages / attachments), date, state, amount + currency,
responsible user, two free dimensions, a lower-cased search text, active / deleted. `ArchiveIndexer::index(string
$model, int[] $odooIds)` (re)indexes records from `odoo_records` and is called by the mirror after every page it
writes; `php artisan scm:odoo-archive-index [--model=*] [--fresh]` builds the index for what is already copied
(1.4 M records and growing to about 2.2 M with messages: chunked, resumable, safe to stop). Register the command in a
service provider or route file of your own, not in `routes/console.php`.

**Definitions** (`config/odoo_archive.php`): sections → kinds. Each kind: Odoo model (+ a condition, e.g.
`move_type`), Arabic and English labels, list columns, filters, state labels with tones, the summary fields of the
detail (labelled in Arabic and English), child lists (lines), related documents, whether it is people's files.
Cover every model of the copy that holds records (`select model, mirrored_count from odoo_models where status =
'active' and mirrored_count > 0`), grouped as people know them:

| Section | Kinds (Odoo model) |
|---|---|
| Sales | orders `sale.order` + lines · customers `res.partner` · price lists + items · commissions (`commission.invoice.line`, `invoice.commission.tracking`, `sales.commission`) · customer favourites (`customer.portal.favorite.list` + items) · portal order requests and line changes (`hub.*`) · new-product requests (`b2b.new.product`, `hub.product.request`) · customer statements (+ lines) · customer branches · onboarding applications |
| Purchasing | orders `purchase.order` + lines · suppliers · supplier prices `product.supplierinfo` |
| Inventory | transfers `stock.picking` + moves + move lines · lots · scrap · valuation layers · warehouses, locations, operation types |
| Accounting | customer invoices and credit notes, supplier bills and refunds, journal entries (all `account.move` by `move_type`) + journal items · payments · bank statement lines · chart of accounts · journals · taxes · e-invoice documents `account.edi.document` · reconciliations |
| Products | products (template + variant) · categories · packaging · units |
| Approvals | requests `approval.request` + approvers · categories |
| CRM | leads / opportunities `crm.lead` · stages · teams |
| People (private) | employees · departments · jobs · contracts · payslips + lines · expenses + reports · time off · attendance · work entries |
| Fleet | vehicles · services · odometer · contracts |
| Other | helpdesk tickets · project tasks · documents · point-of-sale orders + lines + payments · calendar events · users |

A model with records that fits nowhere gets a plain kind under "Other" (name, date, state when it has one) so nothing
of the copy is unreachable.

**API** — all under `/api/odoo-archive`, permission `page.odoo`; a private kind also needs `odoo.private`
(403 `ODOO_PRIVATE`); list answers use the `Paging` envelope.

| Call | Answer |
|---|---|
| `GET catalog` | `{ ready, indexed, sections: [{ key, label, kinds: [{ key, label, model, count, private, columns: [{ key, label, type }], filters: [{ key, label, type: choice\|daterange\|partner\|product\|text, options?: [{ value, label, tone }] }], sorts, defaultSort }] }] }` — `type` of a column: `text, number, qty, money, date, datetime, state, partner, product, user, bool` |
| `GET docs/{kind}` `?q&state&from&to&partner&product&dim1&dim2&sort&order&page&pageSize` | items `{ odooId, number, title, partner: { id, name }\|null, date, state, amount, currency, user, active, cells: { <column key>: value } }` + `totals: { amount }` |
| `GET docs/{kind}/{odooId}` | `{ kind, model, odooId, number, title, state: { value, label, tone }, summary: [{ label, value, type, link?: { kind, id } }], sections: [{ key, label, kind, columns, rows: [{ odooId, cells }], total, more }], related: [{ label, kind, items: [{ odooId, number, date, state, amount }] }], history: [{ at, author, type: note\|email\|system, text, changes: [{ field, old, new }] }], attachments: [{ id, name, mimetype, size, archived, url }], fields: [{ name, label, type, value }] }` — `history` from `mail.message` + `mail.tracking.value` (people's direct chats never appear: only messages attached to the document); `fields` = every copied field with Odoo's own label from `ir.model.fields` when it is in the copy; `attachments[].url` = `/api/odoo-backup/files/{id}` when table `odoo_files` holds it, else null |
| `GET partners/{odooId}` | the partner's profile, counts and latest documents per kind, open receivable / payable from posted invoices and bills |
| `GET products/{odooId}` | the product, Odoo stock per warehouse, latest sales, purchases and stock moves |
| `GET search?q=` | `{ groups: [{ kind, label, total, items: [first 5] }] }` across kinds |
| `GET docs/{kind}/export` (same filters; needs `report.export`) | CSV, UTF-8 with BOM, at most 50,000 rows, the columns of the list |

Detail lists (`sections[].rows`) return the first 200 rows and `more`; lines beyond that are read through
`GET docs/{child kind}?parent=<model>:<id>`. Money keeps Odoo's own figures and currency: nothing is recomputed.

**Acceptance:** tests that build a small mirror by inserting `odoo_models` / `odoo_records` rows (no Odoo), then prove
the index, every filter and sort of two representative kinds, the detail with lines, related documents, history and
attachments, the partner page, the search, the export, the privacy rule, and that re-indexing a changed or deleted
record updates its row. A measured note in your report: index build time and list response times on the real copy
(`scm_ops`) for the three largest kinds — you may READ `scm_ops` and you may create and fill `odoo_docs` there with
your own migration statement run by hand through a script (it is your table), nothing else.

## 4. Team A2 — the archive, client side (`resources/js/pages/odoo/**`)

`OdooArchivePage.vue` and its parts, against the API of §3 (start from canned answers of that exact shape; the server
team works in parallel). A home with the sections as cards (counts, people's files marked and hidden without
`odoo.private`), a global search, one list view for any kind driven by the catalog (columns, filters, sort, paging,
totals, export button), one detail drawer for any document (summary, lines, related documents as links, history as a
timeline, attachments, "all fields" tab), a partner page and a product page. Everything in the query string (deep
links: `/odoo?kind=sale_order&state=sale&q=…&doc=sale_order:123`). Phone layout. No raw technical names on the main
tabs; Arabic first. Follow the look of `resources/js/pages/integration/Odoo*.vue` and reuse `@/components`.

## 5. Team N1 — Approvals (`app/Approvals/**`, `resources/js/pages/approvals/**`, `routes/api/approvals.php`)

Odoo's Approvals app is used daily (about 1,500 requests, 5,000 approver lines, 11 categories). Build the OPS section
that replaces it: categories (who approves, in which order, how many are required, which fields a request must carry),
requests (title, category, requester, amount, dates, partner, reference, quantity / items, reason, attachments),
decisions per approver (approve, refuse, with a comment; withdraw; cancel), notifications to the next approver, a
"waiting for me" list, my requests, all requests (with `approval.manage`), audit. Then
`App\Approvals\OdooApprovalsProjection` (models `approval.category`, `approval.category.approver`,
`approval.request`, `approval.approver`, `approval.product.line`): Odoo's categories and the whole history become OPS
records (Odoo users are matched to OPS users by e-mail or login when they exist, otherwise kept by name). Test
database `scm_ops_test_procurement`. Migration `2026_11_23_000200_approvals.php`. Do not touch the purchase-order /
requisition approvals that already exist (`PoApproval`, `PrApproval`).

## 6. Team N2 — CRM (`app/Crm/**`, `resources/js/pages/crm/**`, `routes/api/crm.php`)

Odoo's CRM holds about 790 leads / opportunities with stages, teams, lost reasons and tags. Build the OPS section:
pipeline by stage (board and list), a lead / opportunity (contact, company, expected revenue, probability, priority,
salesperson, team, source, tags, next activity with its date, notes), won / lost with a reason, conversion of a won
opportunity into an OPS customer (existing `PartnersService::createCustomer`, never a duplicate: by link, then by VAT
number, otherwise a person confirms) and optionally a quotation, activities (to-dos with a due date and an owner,
overdue highlighted), a small funnel report. Then `App\Crm\OdooCrmProjection` (`crm.stage`, `crm.team`,
`crm.lost.reason`, `crm.tag`, `crm.lead`). Test database `scm_ops_test_sales`. Migration
`2026_11_23_000300_crm.php`.

## 7. Team N3 — People and expenses (`app/Hr/**`, `resources/js/pages/hr/**`, `routes/api/hr.php`)

Odoo holds 31 employees, 9 departments, 22 job positions, 28 contracts, about 170 expense lines in 160 expense
reports (with receipts), time-off types; payroll is hardly used (13 payslips) and is NOT rebuilt in this wave. Build:
the staff directory (employee: work details open to `page.hr`; the personal file — identity papers with expiry dates,
family, home address, bank account — and contracts only with `hr.private`), departments and jobs, employee documents
with expiry alerts (residence permit, passport, licences), contracts (start, end, wage, type — `hr.private`), and
**expense claims**: an employee files a claim with lines and receipts (`expense.claim`), a reviewer approves or refuses
(`expense.review`), an approved claim is posted to the books through the existing `App\Finance\Services\ExpensesService`
(read it first; do not change it — ask under "wiring" if it lacks something) and paid. Then
`App\Hr\OdooHrProjection` (`hr.department`, `hr.job`, `hr.employee`, `hr.contract`, `hr.expense.sheet`,
`hr.expense`): Odoo's people and the history of expenses become OPS records; an employee is linked to an OPS user or
driver by e-mail / login when one exists. Test database `scm_ops_test_master`. Migration `2026_11_23_000400_hr.php`.

## 8. What each team hands back

1. The files it created or changed. 2. Endpoints and the permission of each. 3. Tables. 4. What its tests prove
(numbers) and what was NOT verified. 5. "Wiring": anything it needs in a shared file. 6. For projections: the plan
printed on the real copy by a script of its own that calls `->plan()` (counts only) — it writes nothing.
