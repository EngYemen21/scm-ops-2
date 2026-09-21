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

## Phone layout

Opening the system on a phone (viewport ≤ 767px) shows the mobile app of the design — same pages, same data, same
permissions; nothing is a separate code base.

| Piece | Where |
|---|---|
| The switch | `composables/viewport.js` → `isMobile` (one media query; `app.css` uses the same `@media (max-width: 767px)`) |
| Shell | `layout/AppShell.vue`: compact topbar (logo · search · bell), warehouse chip in the title row, `MobileTabBar`, `MobileQuickActions` (+ button); the sidebar and user menu are not rendered |
| Navigation model | `layout/mobile/nav.js` — tabs, "More" groups and quick actions are all derived from `user.nav`, so RBAC stays in one place |
| Screens | `pages/MorePage.vue` (`/more`, redirects home on a wide screen) · `pages/dashboard/MobileHomeHead.vue` (greeting, fulfilment card, KPI strip, work queues — only figures of `GET /dashboard`, no decorative data) · `layout/mobile/MobileSearch.vue` (full-screen search, recent searches) |
| Building blocks | `layout/mobile/BottomSheet.vue`, `WarehouseChip.vue`; CSS classes `m-*` in `app.css` |

Rules for pages, so they keep working on a phone without extra work:

- Use `DataTable` for lists: on a phone every row becomes a card (first column = title, other cells get their header as a
  caption). Give a column `bare: true` when a caption makes no sense (chips, action buttons).
- Use `Drawer` for forms and detail panels: on a phone it is a full app screen — fixed app bar with a back arrow, scrolling body, fixed action bar — and the page behind it is frozen (`composables/scrollLock.js`). `Modal` / `confirm()` / `ask()` / `BottomSheet` are bottom sheets.
- Build forms with `.form-grid` + the input components: one column on a phone, two adjacent short fields (numbers, dates) share a row, selects get one chevron style, `PillChoice` becomes an even segmented grid.
- Line editors mark their header row `le-head` and each line `le-row`; on a phone the header goes and every line is a card, so give the inputs a caption there: `:label="cap('الكمية', 'Qty')"` (see `pages/sales/LinesEditor.vue`).
- Put command buttons in `<PageHead>`: they wrap under the title and get 44px touch height.
- Do not hard-code widths for a desktop toolbar without a fallback; on a phone `min-w-[…]` utilities inside `.main` are
  neutralised and wrapped rows stretch to the full width.
- Inputs are 16px on phones on purpose (iOS zooms the page for anything smaller).
- Check a page at 375px: no horizontal scroll, nothing under the tab bar (the shell pads `.main` for it).

## Barcodes and QR codes

Three libraries, chosen by the team: `picqer/php-barcode-generator` (CODE128), `endroid/qr-code` (QR) on the API,
`html5-qrcode` (camera scanning) in the client. Desktop and phone share all of it.

### Reading (scanning)

| Piece | Where |
|---|---|
| The one scan button | `components/ScanButton.vue` — opens `BarcodeScanner.vue` (html5-qrcode, its own lazy ~360 KB chunk) and emits `detected(code, format)`. Rendered wherever the browser can open a camera (https / localhost): phones, tablets, handhelds and desktops with a webcam. USB / Bluetooth scanners need no button — they type into the focused field. |
| Any text field | `<TextInput scan …>` puts the button inside the field; a read replaces the value and fires `enter`, exactly like a handheld scanner. **Give `scan` to every field that takes a SKU, barcode, bin, batch or document number.** |
| Scan-first fields | `ScanInput` (receiving, put-away, worker receive, dispatch, count entry, form drawers) always has it. |
| Searches | Desktop `GlobalSearch` and phone `MobileSearch`: a QR printed by this system opens its document directly; any other code is searched for. |
| Worker / driver | They have no global search, so the topbar carries a scan button: a system QR opens the document, anything else is delivered to the scan field of the page on screen (`registerScanTarget` / `deliverScan` in `stores/ui.js`). |
| Pickers | Both `ProductPicker`s: a scanned barcode that matches exactly one product is picked without another tap. |
| Accuracy | 1D codes must be read twice in a row before they are accepted (one bad frame of a damaged label can decode to a wrong number); QR / DataMatrix are accepted at once. Back camera, torch when the phone has one, native `BarcodeDetector` when available. |

### Issuing (labels)

| Piece | Where |
|---|---|
| Images | `GET /api/barcodes/code128?text=`, `GET /api/barcodes/qr?text=`, `POST /api/barcodes/batch { items: [{ type, text }] }` (≤ 300, one round trip for a whole zone) → SVG (`app/Services/Barcodes/BarcodeService.php`). SVG on purpose: sharp at any label size, no GD extension needed (the Vercel PHP runtime has none). CODE128 refuses non-ASCII text; QR carries UTF-8 (Arabic). |
| Dialog | `showLabel(spec)`, `showLabels(specs, caption)`, `showCustomLabel()` from `stores/ui.js` → `layout/LabelHost.vue`: preview, copies, print. A label print job prints ONLY the labels (`body.printing-label` rules in `app.css`), one per page — pick the label printer's paper size in the print dialog. |
| CODE128 — shipping and shelves | bin (row «ملصق» + «طباعة ملصقات المواقع» for every bin of the current filter), batch (row), product (primary barcode, else SKU), inbound shipment, GRN, fulfilment order, transfer, return. |
| QR — runs and customers | trip, sales order, purchase order (the QR holds `appLink('/trip/…')`, a link into this application), customer (its code). |
| Free text | «ملصق مخصص»: warehouses page, the desktop user menu and the phone's quick actions. |

The camera needs HTTPS (or localhost) and the user's permission; `BarcodeScanner` explains each failure. Round-trip
checked: every code drawn by the API (bin, FO, shipment, EAN-13 digits, QR link, QR with Arabic) decodes to the same
text with html5-qrcode's own decoder.

## Maps (Mapbox)

Provider: **Mapbox**, one variable — `MAPBOX_PUBLIC_TOKEN` (a *public* `pk.…` token; a secret `sk.…` token is refused by
`AdapterFactory::maps()` and never reaches the browser). Restrict the token by URL in the Mapbox account. Without it
every map shows the honest "Integration Pending" placeholder and the API answers `integration_pending`.

| What | Where |
|---|---|
| Server adapter (Directions with live traffic, Optimization) | `app/Services/Integrations/Adapters/MapboxMaps.php` |
| Transport map API | `app/Services/Transport/TransportMapService.php`, `routes/api/transport.php` (`map/config`, `map`, `trips/{n}/map`, `trips/{n}/optimize`) |
| Client loader (config, lazy `mapbox-gl` chunk, place search) | `resources/js/composables/mapbox.js` |
| Map component | `components/MapView.vue` — markers + lines in, `@marker` out; `pickable` + `v-model:pin` for picking |
| Location control for forms | `components/LocationField.vue` → `LocationPicker.vue` (search in Saudi Arabia, tap, drag the pin) |
| Screens | `pages/transport/TripMap.vue` (trip room), `pages/transport/FleetMap.vue` (transport tower, fleet) |

Rules:

- **Only stored facts are drawn**: warehouse / customer / stop coordinates, the GPS fix a driver's phone attached to a
  proof of delivery, a vehicle position written by a telematics provider. A stop without coordinates is listed as
  "not located" — it is never guessed from its address. Live vehicle tracking stays `integration_pending` until a GPS
  provider is configured (`GPS_PROVIDER_URL`).
- A trip's line is warehouse → located stops in sequence → warehouse, cached 15 minutes by its exact point list. When the
  provider has no answer the client draws straight *dashed* links, which cannot be mistaken for a road.
- "Optimise stop order" (`trip.manage`, planning stages only, ≤ 11 stops, every stop and the warehouse located) asks the
  provider for the shortest round trip and applies it through the normal `reorderStops` rule — same audit, same lock.
  `GET trips/{n}/map` carries `optimize: { allowed, code, reasonAr, reasonEn }`, so the button shows only when it can work.
- A stop copies its customer's coordinates when the trip is created and falls back to the customer's current ones.
- `mapbox-gl` (≈ 510 kB gzip) is its own chunk, loaded only when a map is on screen. On phones the map uses cooperative
  gestures (two fingers to pan) so it never hijacks the page scroll; the picker, being a dialog, pans with one finger.
- Demo coordinates: `DemoCoordinatesSeeder` (runs with the demo seed; on an existing database:
  `php artisan db:seed --class='Database\Seeders\DemoCoordinatesSeeder' --force` — it never overwrites a set value).

## Tables — the balanced grid

Every table follows the products master table: a header band, column dividers, cells centred vertically, fixed-width
columns (codes, numbers, dates, chips, actions) centred under a centred header, flexible text columns starting at the
column edge, plain values on one line with an ellipsis and the full text in the tooltip.

- `DataTable` does it for you. Alignment comes from the column: `align` if set, otherwise `start` for `kind: 'name'` and
  any `fr` width, `center` for everything else. The header always uses the same alignment as its cells.
- **The minimum width is derived from the tracks** (fixed px + the `minmax()` minimum of each flexible column + row
  padding). `:min-width` is ignored — it used to force a scrollbar on tables that still fitted. A table scrolls only
  when its columns really do not fit; budget: **1150 px of tracks** fits a 1440 screen beside the sidebar.
- Very wide tables (14+ columns, e.g. trips) take `compact`: narrower cell padding so they still fit.
- Widths: give codes 90–110 px, numbers 56–72, dates 82–96, chips 84–140, two buttons 120–150; keep ONE or two flexible
  columns (`minmax(…px, …fr)`) for names / descriptions so the slack lands where text needs it.
- Hand-written header / row grids opt into the same look with the `bgrid` class on both (text column = first child;
  `s2` / `s3` when it is the second / third).
- Phones are untouched: below 768 px rows become cards (see Phone layout).
