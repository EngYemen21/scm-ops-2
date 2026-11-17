# B2B ops — instructions for AI agents working in this repository

Supply-chain operations system: Laravel 13 (PHP 8.3) API + Vue 3 / Tailwind 4 client, MySQL 8 (primary) and
PostgreSQL 17 (production on Vercel / Neon). The toolchain is already installed — **do not install PHP, Composer,
Laravel Boost or any other package unless the task says so**.

Read before changing anything:

| Topic | File |
|---|---|
| Backend rules (layout, services, errors, state machines, tests) | `docs/CONVENTIONS.md` |
| Client rules (queries, actions, styling, bilingual text) | `docs/FRONTEND.md` |
| Running, deploying, data migration | `docs/RUNBOOK.md` |
| Finance engine (double entry, posting rules) | `docs/finance/ARCHITECTURE.ar.md` |
| Sales ↔ OPS integration | `docs/integration/ARCHITECTURE.md`, `docs/integration/RUNBOOK.md` |
| Operational hardening decisions (2026-11) | `docs/ops-hardening/DECISIONS.ar.md` |

Rules that are never broken:

1. Stock changes only through `app/Services/Inventory/InventoryService.php` (ledger + row locks); run
   `php artisan scm:reconcile` after any change that moves stock.
2. Permissions are enforced on the server (`perm:*` route middleware); the client only hides.
3. Posted journals are never edited or deleted — corrections are reversals (`LedgerService::reverse`).
4. No secrets in the repository. Integrations that are not connected report `integration_pending` honestly.
5. Every user-facing text is bilingual: `t('عربي', 'English')` / `{ ar, en }`.
6. The whole suite passes on MySQL **and** PostgreSQL before anything is pushed:
   `php artisan test`, then the same with `DB_CONNECTION=pgsql …` (see `docs/CONVENTIONS.md`).
7. After changing `resources/js` or `resources/css`: `node tools/check-vue.mjs resources/js`, `npm run build`, and
   commit `public/build` with the change. Before a release run `tools/ui-sweep.cjs` (usage in its header).
