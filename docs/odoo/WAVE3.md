# Odoo → OPS, wave 3: every remaining section Odoo has

Owner (2026-11-22): "I want everything that is in Odoo." Wave 2 (`docs/odoo/WAVE2.md`) covers the backup, the
archive, Approvals, CRM and People & expenses. This wave builds the OPS sections for what is left of Odoo's used
applications. **`docs/odoo/WAVE2.md` §0 (ground rules), §1 (what is in place) and §8 (what a team hands back) apply
word for word** — read them first; `docs/odoo/COVERAGE.ar.md` has the usage figures behind every decision.

Already registered for you (shared files are not yours to edit): sidebar keys, page files and permissions below;
projection class names in `config/odoo.php` (`odoo.projections`), each with its switch `odoo.project.<key>`.

| Team | Folder / API prefix | Page file | Permissions | Projection (key) | Test database | Migration |
|---|---|---|---|---|---|---|
| P1 Price lists | `app/Pricing` · `/api/pricing` | `resources/js/pages/pricing/PricingPage.vue` | `page.pricing`, `pricelist.manage`, `supplierprice.manage` | `App\Pricing\OdooPricingProjection` (`pricing`) | `scm_ops_test_w3a` | `2026_11_24_000100_pricing.php` |
| P2 Commissions | `app/Commissions` · `/api/commissions` | `pages/commissions/CommissionsPage.vue` | `page.commissions`, `commission.manage`, `commission.approve` | `App\Commissions\OdooCommissionsProjection` (`commissions`) | `scm_ops_test_w3b` | `2026_11_24_000200_commissions.php` |
| P3 Customer portal | `app/Portal` · `/api/portal` | `pages/portal/PortalPage.vue` | `page.portal`, `portal.manage` | `App\Portal\OdooPortalProjection` (`portal`) | `scm_ops_test_w3c` | `2026_11_24_000300_portal.php` |
| P4 New-product requests | `app/ProductRequests` · `/api/product-requests` | `pages/preq/ProductRequestsPage.vue` | `page.preq`, `preq.create`, `preq.review` | `App\ProductRequests\OdooProductRequestsProjection` (`preq`) | `scm_ops_test_w3d` | `2026_11_24_000400_product_requests.php` |
| P5 Workspace | `app/Workspace` · `/api/workspace` | `pages/workspace/WorkspacePage.vue` | `page.workspace`, `ticket.manage`, `project.manage`, `doc.manage` | `App\Workspace\OdooWorkspaceProjection` (`workspace`) | `scm_ops_test_w3e` | `2026_11_24_000500_workspace.php` |
| P6 Payroll, time off, attendance | `app/Payroll` · `/api/payroll` | `pages/payroll/PayrollPage.vue` | `page.payroll`, `payroll.manage`, `payroll.approve`, `leave.request`, `leave.approve`, `attendance.manage` | `App\Payroll\OdooPayrollProjection` (`payroll`) | `scm_ops_test_w3f` | `2026_11_24_000600_payroll.php` |

Tests go in `tests/Feature/<Folder name>/`. Each section must be a good module in its own right AND a faithful home
for every record Odoo holds of it: learn the real shapes from the copy before designing (field names and counts).

## P1 — Price lists

Odoo: 58 customer price lists with 2,577 rules (`product.pricelist`, `product.pricelist.item`), a price list per
customer on the partner, and 3,823 supplier prices (`product.supplierinfo`: supplier, product, price, minimum
quantity, lead time, validity). OPS has prices typed on quotations and purchase documents, no price lists.
Build: customer price lists (currency, validity, rules by product / category / all, minimum quantity, date range,
fixed price / discount on the list price / formula with rounding, as Odoo's rules really are in the data), assignment
of a list to a customer, supplier price lists per supplier and product, and **one price service** other code calls:
"the price of product P for customer C, quantity Q, on date D" with the rule that decided it, and "the best supplier
price for P, quantity Q, on date D". Read `app/Services/Sales` and `app/Services/Procurement`: wire the price service
in only where the existing code has an obvious extension point without changing its behaviour by default; otherwise
expose it and describe the hook under "wiring". A price-check screen (pick customer + product + quantity, see the price
and why). Projection: lists, rules, customer assignments (through the customer links of `OdooMasterData`: ExternalRefs
system `odoo`, entity `customer` / `supplier` / `product`), supplier prices.

## P2 — Commissions

Odoo (custom modules): 11 commission rules (`sales.commission`), 7,082 commission lines per invoice
(`commission.invoice.line`) and their tracking (`invoice.commission.tracking`), last used in August 2026. Build:
commission plans (who earns, on what base — invoiced or collected amount —, rate or tiers, by product category /
customer / salesperson, validity), calculation of commission lines from invoices (OPS finance receivables: read
`app/Finance` for invoices and collections; do not change it), statements per salesperson and period, adjustment
lines with a reason, approval (`commission.approve`) and payout as a payable / expense through the existing finance
services (describe the call under "wiring" if no clean entry exists). A salesperson sees only his own statement
unless he holds `commission.manage`. Projection: rules and the whole history of lines, linked to OPS users and
customers where links exist.

## P3 — Customer portal

Odoo's "Customer Portal Hub" and "Branch Invoice Statement" (custom): 2,090 favourite lists with 155,853 items
(`customer.portal.favorite.list`, `customer.portal.favorite`), 73 customer branches (`portal.customer.branch`), 581
onboarding applications with documents (`portal.onboarding.application`), 25 order requests with 200 lines and 10,494
logged order-line changes (`hub.order.request`, `hub.order.request.line`, `hub.order.line.change`), 294 customer
statements with 5,997 lines (`customer.statement`, `customer.statement.line`). Build the OPS side that staff use and
that the sales platform can call: customer branches (address, contact, delivery window, linked to the OPS customer),
favourite lists per customer / branch (view, edit, reorder; "order again" builds a draft quotation only if the sales
module has a clean service for it — otherwise list it under "wiring"), onboarding applications (review, request
correction, approve → creates the OPS customer through `PartnersService::createCustomer`, never a duplicate; refuse
with a reason), order requests and the log of line changes (who changed what on which order line, before and after),
customer statements (per customer / branch and period, from Odoo's history for the past and from OPS receivables
going forward — read `app/Finance`; do not change it). Projection of all of the above. Large volumes: favourites are
155 k rows — page, index, never load a customer's whole history at once.

## P4 — New-product requests

Odoo (custom): 1,011 `b2b.new.product` records (active this month, most with pictures) and 19 `hub.product.request`.
Build the request-to-catalogue flow: a request (name in Arabic and English, brand, category, supplier, pack, unit,
proposed cost and price, barcode, storage class, shelf life, pictures, reason, requested by), review (`preq.review`:
ask for correction, reject with a reason, approve), and on approval the product is created in the OPS catalogue
through the existing `App\Services\Master\ProductsService::create` (read it; never a duplicate SKU or barcode; the
request keeps the link to the product it created). Duplicate detection while typing (same barcode, same normalised
name). Projection: both Odoo models, with their states mapped faithfully and the product link restored when Odoo's
product is already an OPS product (ExternalRefs `odoo` / `product`).

## P5 — Workspace: helpdesk, projects, documents

Odoo: Helpdesk (21 tickets, 4 teams, stages), Project (3 projects, 13 tasks, stages, personal stages, tags), Documents
(68 documents, 21 folders, tags, sharing). Light use, so one team builds three small, solid tools under one page with
three tabs: **tickets** (team, stage, priority, requester — a customer or a member of staff —, assignee, due date
from the team's response target, conversation, close with a resolution; `ticket.manage` configures teams and stages),
**projects and tasks** (project, task with assignee, deadline, stage, priority, tags, checklist, comments; board and
list; `project.manage` creates projects), **documents** (folders, tags, upload, version, link to an OPS record,
download; `doc.manage` manages folders; use the existing stored-files mechanism — read how proof-of-delivery files and
attachments are stored). Projection: tickets, projects, tasks, folders, documents (the file content of an Odoo
document comes from table `odoo_files` when the attachment was archived — link, do not copy).

## P6 — Payroll, time off, attendance (starts after People & expenses is merged)

Odoo: 13 payslips, 7 salary structures, 104 salary rules, 28 contracts, 11,610 generated work entries, 6 time-off
types (1 request), 2 attendance records. Builds on `app/Hr` (employees and contracts — read, do not change):
**time off** (types with yearly allowance, requests, approval by the manager / `leave.approve`, balances, calendar),
**attendance** (check in / out, daily sheet, late and overtime per the work schedule, corrections by
`attendance.manage`), **payroll** (pay components — basic, housing, transport, other allowances, deductions, GOSI as
configurable rates —, a monthly run that builds a payslip per active contract from the contract and the month's
unpaid leave / overtime / expense reimbursements, review, approval `payroll.approve`, posting of the run to the books
through the existing finance services and a bank transfer file listing, payslip visible to its employee). Everything
here is people's pay: readable only with `payroll.manage` / `payroll.approve`, or by the employee for his own.
Projection: Odoo's structures, rules, payslips with lines, time-off types and requests, attendance.
