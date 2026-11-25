# Odoo → OPS, wave 4: what is left of Odoo's applications

Owner (2026-11-22): "I want everything that is in Odoo." Waves 2 and 3 (`docs/odoo/WAVE2.md`, `docs/odoo/WAVE3.md`)
cover the backup, the archive and eleven sections. This wave builds the OPS sections for the applications that are left
(`docs/odoo/COVERAGE.ar.md` §2). **`docs/odoo/WAVE2.md` §0 (ground rules), §1 (what is in place) and §8 (what a team
hands back) apply word for word** — read them first.

Already registered for you (shared files are not yours to edit): sidebar keys, page files and permissions below;
projection class names in `config/odoo.php` (`odoo.projections`), each with its switch `odoo.project.<key>`.

| Team | Folder / API prefix | Page file | Permissions | Projection (key) | Test database | Migration |
|---|---|---|---|---|---|---|
| Q1 Point of sale | `app/Pos` · `/api/pos` | `resources/js/pages/pos/PosPage.vue` | `page.pos`, `pos.sell`, `pos.manage` | `App\Pos\OdooPosProjection` (`pos`) | `scm_ops_test_w4a` | `2026_11_25_000100_pos.php` |
| Q2 Demand & purchase planning | `app/Planning` · `/api/planning` | `pages/planning/PlanningPage.vue` | `page.planning`, `forecast.manage`, `purchaseplan.manage`, `purchaseplan.approve` | `App\Planning\OdooPlanningProjection` (`planning`) | `scm_ops_test_w4b` | `2026_11_25_000200_planning.php` |
| Q3 Credit accounts | `app/Credit` · `/api/credit` | `pages/credit/CreditPage.vue` | `page.credit`, `credit.request`, `credit.review`, `credit.approve`, `credit.manage` | `App\Credit\OdooCreditProjection` (`credit`) | `scm_ops_test_w4c` | `2026_11_25_000300_credit.php` |
| Q4 Banking & reconciliation | `app/Banking` · `/api/banking` | `pages/banking/BankingPage.vue` | `page.banking`, `bankstatement.manage`, `bank.reconcile` | `App\Banking\OdooBankingProjection` (`banking`) | `scm_ops_test_w4d` | `2026_11_25_000400_banking.php` |
| Q5 Tax & e-invoicing | `app/Tax` · `/api/tax` | `pages/tax/TaxPage.vue` | `page.tax`, `vatreturn.manage`, `vatreturn.file`, `einvoice.manage` | `App\Tax\OdooTaxProjection` (`tax`) | `scm_ops_test_w4e` | `2026_11_25_000500_tax.php` |
| Q6 Brands, expiry & insights | `app/Insights` · `/api/insights` | `pages/insights/InsightsPage.vue` | `page.insights`, `brandowner.manage`, `expiryalert.manage` | `App\Insights\OdooInsightsProjection` (`insights`) | `scm_ops_test_w4f` | `2026_11_25_000600_insights.php` |

Tests go in `tests/Feature/<Folder name>/`. Each section must be a good module in its own right AND a faithful home
for every record Odoo holds of it.

## The shapes of what the connection user cannot read

Odoo refuses the connection user the RECORDS of some custom modules (demand forecast, purchase plan, credit account,
brand owner, expiry notifications, attendance sheets) but its dictionary is in the copy. Their complete shapes —
every field with its type, label, relation and the options of each selection — are in
**`docs/odoo/shapes/unreadable.txt`** (made from `ir.model`, `ir.model.fields`, `ir.model.fields.selection` of the
copy). For those models: design the OPS section from that file, write the projection against those field names, and
prove it with `tests/Feature/Integration/FakeOdoo.php` records of that shape. The day the Odoo user is given the
access, the records enter the copy by themselves and your projection loads them — say so in your report, do not
pretend a load happened. For every other model read real records from the copy as WAVE2 §0.1 says.

The sections already in the tree are yours to READ and call, not to change: `app/Pricing` (one price service:
customer price and best supplier price), `app/Approvals`, `app/Crm`, `app/Hr`, `app/Portal`, `app/Workspace`,
`app/Finance`, `app/Services/**`. Where an existing service has no clean entry for what you need, expose your side
and describe the hook under "wiring" instead of changing their code.

## Q1 — Point of sale

Odoo: one register (`pos.config`), 29 sessions, 38 orders with 91 lines and 41 payments, 3 payment methods, 15 cash
denominations (`pos.bill`), last sale in October 2025, and an A4 receipt add-on. Build a counter-sales tool that is
correct in stock and in the books: **registers** (warehouse it sells from, default "walk-in" customer, price source =
the price service of `app/Pricing`, allowed discount), **payment methods** (cash / card / transfer, each bound to a
finance bank or cash account — read `app/Finance`), **sessions** (opened by a cashier with the counted opening cash,
closed with the cash counted by denomination; the difference is shown and posted with a reason; one open session per
register), the **selling screen** (scan a barcode or search, quantity, line discount within the allowed limit,
optional customer, hold and recall a sale, split payment, change due; works on a tablet and a phone), **receipt**
(80 mm and A4 tax invoice in Arabic and English with the VAT breakdown), **refund** against an order (never more than
was sold), **stock** through the existing inventory services exactly as fulfilment issues stock (read
`app/Services/Inventory` and `app/Services/Fulfillment`; never touch balances directly; a sale that would take stock
below zero is refused unless the register allows it), **accounting** through the existing finance services (read
`app/Finance/Services/CustomerInvoiceService.php`, `PaymentsService.php`, `LedgerService.php`; one invoice + receipt
per order or one summary journal per closed session — choose what the existing services support cleanly and say
which), and an **end-of-day report** per session. Projection: register, payment methods, sessions, orders, lines and
payments as history (`source_system = 'odoo'`, read-only), linked to OPS products and customers through
`int_external_refs` where links exist.

## Q2 — Demand & purchase planning

Odoo (custom "B2B Demand Forecast & Replenishment Planning", twelve models in the shapes file — read every one): a
forecast **run** (history window, forecast horizon, method — simple average / weighted moving average / seasonal same
dates last year / customer reorder cadence —, growth factor, demand netting, history source = deliveries or confirmed
sales orders, product selection, warehouses, customer mode none / filter / split, safety stock by days of demand or
service level, vendor lead time, rounding), **items** per product and warehouse (and customer) with on hand, booked
in and out, history statistics, forecast, shortage / surplus / days of cover, suggested purchase quantity, date and
value, suggested minimum / maximum and the push of those to the reorder rule, **periods**, **history**, **customer
reorder patterns** with their **expected orders** and what really happened, **purchase requirements**, **budget
scenarios** with monthly budgets and a scored **allocation** (urgency, stock-out risk, product criticality, velocity,
confidence, customer criticality, lead time; critical first, partial funding, carry forward), and **purchase plans**
(draft → to approve → in progress → closed, lines by vendor and order date, grouped into requests for quotation,
progress from what was ordered and received).
Build that engine natively: history comes from OPS's own sales and deliveries AND from Odoo's history in the copy
(`sale.order.line`, `stock.move.line` — years of it), so the first run has real data; on hand / booked from the OPS
inventory services; lead time and price from `app/Pricing` (best supplier price) with last purchase and product cost
as fallbacks; suggested minimum / maximum written to the OPS product's reorder settings only by an explicit "push"
(read `app/Models/Product.php`); a plan line becomes a purchase request through the existing procurement service
(read `app/Services/Procurement`; `purchaseplan.approve` approves a plan). The computation must be deterministic,
explain itself (why this quantity: the numbers behind every suggestion, the fallback taken and why) and be tested
with hand-computed cases for each method. Large runs: thousands of items — compute in chunks, page every list,
export to Excel. Projection of the twelve models (shapes file).

## Q3 — Credit accounts

Odoo (custom "B2B Deferred Credit Account", ten models in the shapes file): a customer's **request** for a deferred
credit account — company data, commercial registration, owner, address, requested limit, payment days and monthly
purchase volume, payment methods, responsible persons, bank details, authorised signatories, nine document slots,
the accepted **terms version** — moving through configurable **stages** (responsible role or user, approval by any
or by all, target hours with reminder and escalation, documents / signature required, fields locked, visible to the
customer) with an **approval audit** line per stage (pending / approved / rejected / changes requested / skipped /
escalated), finance recommendation, legal notes, the chief executive's notes, the approved limit, days and payment
term, an **e-signature envelope** per request (sent / delivered / signed / declined / expired, the signed PDF) and a
**notification log** (in-app / e-mail / WhatsApp).
Build it: the request with its lines and documents (documents through the existing stored-files mechanism — read
how `app/Workspace` and `app/Hr` store files), the stage engine with deadlines, a scheduled command for reminders and
escalation, the approval audit, and on final approval the customer's credit limit and payment terms are set through
the existing customer and credit services (read `app/Services/Sales/CreditService.php`,
`app/Services/Master/PartnersService.php`; never bypass them; the request keeps what it changed and who approved).
E-signature is provider-neutral: an adapter interface with a working "signed agreement uploaded by hand"
implementation and the envelope statuses; a real provider needs credentials — list it under "wiring". Notifications:
in-app through `app/Services/Core/NotifyService.php`; e-mail and WhatsApp are logged as skipped until a provider is
configured. A printable agreement with the terms version in Arabic and English. Bank details, national ids and the
identity documents are personal: returned only to `credit.review` / `credit.approve` / `credit.manage`, masked for
everyone else, never logged. Projection of the ten models (shapes file); the request links to the OPS customer
through `int_external_refs` (`odoo` / `customer`).

## Q4 — Banking & reconciliation

Odoo: 34 journals (bank and cash among them), 30 bank statement lines (last in March 2026), 11,808 payments, 73,013
partial and 54,738 full reconciliations, 2 reconciliation models, 9 payment terms. OPS has bank accounts
(`/api/finance/banks`), receipts and payments (`PaymentsService`) and the ledger — read `app/Finance`, do not change
it. Build: **bank statements** per OPS bank account (typed lines and import from CSV / Excel with a saved column
mapping per bank; a line that was already imported is refused by its fingerprint; opening + lines = closing is
checked), **matching** (suggestions against the unreconciled OPS receipts, payments and ledger lines of that bank's
account by amount, date window, reference and party; one line to many documents and many lines to one; confirm,
undo with a reason, everything audited), **write-off rules** (bank charges, rounding within a tolerance: account,
label — they post a journal through the existing ledger service), the **reconciliation statement** at a date (book
balance, statement balance, items in transit and outstanding, the difference must be explained to zero) and a lock:
a reconciled statement cannot be edited, only reopened by `bank.reconcile` with a reason. Projection: Odoo's bank and
cash journals are linked to OPS bank accounts by IBAN / account number / exact name (adopted, never doubled; the
unmatched are listed for a person), the statement lines as history, the reconciliation models as write-off rules.

## Q5 — Tax & e-invoicing

Odoo: 22 taxes in 3 groups, 3 fiscal positions, and 8,606 e-invoice documents (`account.edi.document`: the invoice,
the format, the state, the error). OPS today computes VAT from two ledger accounts and says in its own code that the
official return and e-invoicing are not connected (`FinanceReportsService::vatReturn`). Read how OPS documents carry
VAT (`app/Finance`, `app/Services/Sales`, `app/Services/Procurement`) and keep their behaviour. Build:
**tax codes** (rate, sales or purchases, kind: standard / zero-rated / exempt / out of scope / reverse charge /
import, the authority's category code and exemption reason), the **VAT return** per period (monthly or quarterly):
the lines of the Saudi return — standard-rated sales, sales to citizens of private services, zero-rated domestic
sales, exports, exempt sales; standard-rated purchases, imports paid at customs, imports under reverse charge,
zero-rated purchases, exempt purchases; corrections of earlier periods; net due — computed from the OPS documents
and ledger with drill-down to every document behind a figure, adjustment lines with a reason, states draft →
reviewed → filed (reference and date) → locked, and a comparison with the ledger's VAT accounts that must explain
any difference. **E-invoicing** for every OPS customer invoice, credit note and debit note per the Saudi authority's
second phase: the UBL 2.1 XML of the invoice, the invoice counter and the previous-invoice hash chain, the SHA-256
hash, the QR code (the five basic fields, and the signature fields when a signing unit is configured), signing with
the unit's key when one is configured, the onboarding record of a unit (certificate request, compliance and
production certificates — stored encrypted, never logged, never returned by the API), a client for the authority's
reporting and clearance services with its three environments, a queue with retries, the status of every document
(not sent / reported / cleared / accepted with warnings / rejected) with the authority's messages, and a screen.
Check every technical detail against the authority's published specifications if you can reach them, and list in
your report exactly what you verified against them and what you wrote from knowledge only. Without credentials the
module runs "not onboarded": XML, hash chain and the basic QR are produced and nothing is sent — that must be the
default and must be obvious on the screen. Projection: taxes, groups, fiscal positions, and Odoo's e-invoice
documents as the e-invoice history of the archived invoices.

## Q6 — Brands, expiry & insights

Odoo (custom): **Brand Owner Dashboard** (`brand.owner`, `brand.franchisee.mapping`, `brand.owner.dashboard.alert` —
shapes file), **Inventory Expiry Tracker** (`expiry.notification.level`, `expiry.notification.log` — shapes file), a
**stock card** report, and an **operations manager dashboard** (profit and loss lines, customer ageing, customer
margin, invoice counts, product expiry, slow-moving products). Build: **brand owners** (name, brand, the OPS user it
is, its products / categories — OPS products carry `brand` —, its franchisees = OPS customers with region, city,
start date, monthly purchase target and status, and what the owner may see: invoices, payments, returns, margin), the
**brand dashboard** (purchases per franchisee against target, trend, top products, returns, overdue invoices) from
OPS sales and finance data, with Odoo's history from the copy for the past, **alerts** of the eight Odoo types
computed by a scheduled command (read / unread); a user linked to a brand owner sees his brand only, unless he holds
`brandowner.manage`. **Expiry alert levels** (days before expiry, recipients, in-app / e-mail) and the log (one alert
per lot and level, never twice) from the OPS batches (read `app/Services/Inventory` and the batches page). **Stock
card**: read the existing ledger page first — add only what is missing (opening / in / out / balance per movement
with document links, export, print) instead of a second ledger. **Operations insights**: slow-moving products,
expiry buckets, customer margin, customer ageing, invoice counts — reuse the existing finance reports where they
exist (`FinanceReportsService`), add the missing ones. Projection of the five readable-by-shape models.

## Not built, and why

Calendar (4 events), Sign (1 template, no request), Website (1 record), Social marketing (no record) and Discuss
channels (80 messages) are in the archive with everything they hold; no OPS section is built for them. Meetings are
to-dos of the CRM section, and the credit section tracks the signature of its agreement.
