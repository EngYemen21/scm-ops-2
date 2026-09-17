# B2B ops — front-end conventions (Vue 3 + Tailwind 4)

Single-page app served by Laravel (`resources/views/app.blade.php`), built with Vite. Plain JavaScript, `<script setup>`
SFCs, Pinia for session state, `@tanstack/vue-query` for server data, Tailwind 4 for styling. Arabic (RTL) first, English
second. The screens are a port of the reference React client (`apps/web/src` of the reference project): same layout,
wording, flows and API calls.

**Read `resources/js/pages/dev/KitPage.vue` first** — a complete page (list, filters, paging, detail drawer, actions,
create form) built on a real API. Open it at `/kit`.

## Run

```bash
npm run dev        # Vite dev server with HMR (keep `php artisan serve --port=8000` running, open http://127.0.0.1:8000)
npm run build      # production bundle into public/build (what `php artisan serve` uses when no dev server is running)
```

## Layout

| Path | What |
|---|---|
| `resources/js/pages/<domain>/<Name>Page.vue` | one file per route; the route table is `PAGE_FILES` in `router/routes.js` (a missing file shows "being built") |
| `resources/js/pages/<domain>/*.vue` / `*.js` | that domain's own drawers, forms, helpers (the reference's `shared.tsx` / `_shared.tsx`) |
| `resources/js/components/` | shared UI kit — import from `@/components` |
| `resources/js/layout/` | shell, drawer, modal, banners, icons |
| `resources/js/api/client.js` | `api`, `useList`, `useGet`, `useAction`, `useInvalidate`, `ApiError` |
| `resources/js/i18n.js` | `t`, `bi`, `nm`, `lang`, `fmtNum`, `fmtMoney`, `num`, `fmtDate`, `fmtDateOnly`, `fmtAgo`, `daysTo` |
| `resources/js/shared/` | status labels + colours, state machines, permissions (`SO_LABELS`, `PO_TRANSITIONS`, …) |
| `resources/js/stores/` | `useAuth()` (user, `can()`), `useWarehouse()` (`whParams`), `toast`, `confirm` |

Shared code (`components/`, `layout/`, `api/`, `stores/`, `router/`, `i18n.js`, `shared/`, `css/app.css`) is not edited by
page work. Need something new? Put it in your domain folder; propose shared changes separately.

## Rules that matter

1. **Never create components, lazy imports or promises inside a render.** Routes are lazy through the router only.
   (The React client froze on Edge because of a lazy component created during render.)
2. **Server data goes through `useList` / `useGet`**; pass params as a getter so the query follows your refs:
   `useList('/sales/orders', () => ({ page: page.value, status: status.value, ...wh.whParams }))`.
   A null path disables a query: `useGet(() => (sel.value ? `/sales/orders/${sel.value}` : null))`.
3. **Every mutation goes through `useAction().run(...)`** with `api.postIdempotent` (double clicks are replayed by the
   server, not repeated). It toasts success/errors, invalidates queries (`invalidate: ['sales', 'inventory']` = URL
   prefixes) and exposes `pending` / `error` refs — bind `:loading="act.pending.value"` and
   `<ErrorBanner :error="act.error.value" @close="act.clearError()" />`. Action buttons send no body.
4. **Permissions**: `auth.can('po.approve')` only hides what the user cannot do; the API enforces everything. A 403
   renders as "insufficient permission" through ErrorBanner / toast automatically.
5. **Text is bilingual**: `t('عربي', 'English')` in templates and script, `{ ar, en }` objects for component props
   (`:label`, `:title`, column `header`), `nm(record)` for `nameAr/nameEn`. No hard-coded single-language strings.
6. **Numbers, ids, dates are LTR + Quicksand**: classes `num`, `cell-id`, `cell-num`, `cell-date`, `ltr`. API decimals
   arrive as strings — use `num(v)` for arithmetic, `fmtMoney(v)` to show.
7. **Styling**: use the design-system classes from `resources/css/app.css` (`card`, `btn`, `chip`, `pill`, `tab`, `inp`,
   `kv-grid`, `hint`, `banner`, `tile`, `row`, `col`, `grid-2`, `grid-eq`, `skel` …) and Tailwind utilities for layout
   and one-off tweaks. Tokens: `text-ink|sec|muted|faint`, `bg-canvas|soft|night`, `border-line|line-2`,
   `text-brand|violet|azure|ok|warn|bad` (+ `bg-*-soft`), `font-num`. Reference inline styles map to arbitrary values:
   `style={{ fontSize: 10.5, fontWeight: 800 }}` → `class="text-[10.5px] font-extrabold"`. Use logical utilities for RTL
   (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`), never `ml/mr/left/right`. Utilities beat component
   classes; add `!` when overriding a component class property (`class="btn !h-11"`).
8. **Honesty**: integrations that are not connected show "Integration Pending" exactly as the API reports — never a fake
   "sent", "uploaded" or "live".

## React → Vue cheatsheet

| Reference (React) | Here (Vue) |
|---|---|
| `useState(x)` | `ref(x)`; derived → `computed` |
| `useEffect(() => …, [dep])` | `watch(dep, …)` / `watchEffect`; timers cleared in `onBeforeUnmount` |
| `const { t, lang } = useLang()` | `import { t, lang } from '@/i18n'` (`lang` is a ref: `lang.value` in script, `lang` in templates) |
| `const { can } = useAuth()` | `const auth = useAuth(); auth.can('x')`, `auth.user` |
| `const { whParams } = useWarehouse()` | `const wh = useWarehouse(); wh.whParams` (spread into params getter) |
| `useNavigate()` / `<Link>` / `useParams()` / `useSearchParams()` | `useRouter().push()` / `<RouterLink>` / `useRoute().params` / `useRoute().query` |
| `<PageHead sub actions={<Btn/>} />` | `<PageHead :sub="…"><Btn … /></PageHead>` (buttons are the default slot) |
| `<DataTable columns paged onRowClick …>` with `render: (r) => <Chip/>` | same props, `@row-click`, `@page`; cell markup via `<template #cell-<key>="{ row }">`; plain text via column `value: (row) => …` |
| `<Tabs tabs active onChange>` | `<Tabs v-model="tab" :tabs="…" />` |
| `<TextInput value onChange>` / `Select` | `<TextInput v-model="x" />` / `<SelectInput v-model="x" :options="…" />` (also NumberInput, TextArea, DateInput, ScanInput, PillChoice) |
| `<FormDrawer submit onDone …>` with field `render` | same props + `@done`, `@close`; custom control via `<template #field-<k>="{ value, set, values, error }">` |
| `<Drawer open onClose footer={…}>` / `<Modal>` | `<Drawer :open @close><template #footer>…</template></Drawer>` |
| `const confirm = useConfirm()` | `import { confirm } from '@/stores/ui'` → `if (await confirm({ title, sub, tone })) …` |
| `window.prompt(…)` | `import { ask } from '@/stores/ui'` → `const reason = await ask({ title, label, required: true }); if (reason === null) return;` — native dialogs are rejected by `tools/check-vue.mjs` |
| `useToast().say(…)` | `import { toast } from '@/stores/ui'` → `toast.say({ ar, en })` |
| `act.run(() => api.postIdempotent(path, body), { success, invalidate })` | identical; `act.pending.value`, `act.error.value` |
| `<KV k v ltr />` helper of a domain | `<KV :k="{ ar, en }" :v="…" ltr />` from `@/components` |
| `window.print()` for PDF | same (`@media print` hides the shell) |

## Done means

Every tab, table, filter, drawer, form, action button, empty / loading / error state and deep link of the reference page
exists and calls the same endpoint; `npm run build` passes; the page was opened in the browser against the running API
with no console errors, and each action button was pressed once on demo data.
