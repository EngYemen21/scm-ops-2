// «العلامات والصلاحية والتحليلات» — what the tabs of the section share: label maps (with chip colours), small formats,
// the query-string state of the page, who the user is in the section (GET /insights/me) and the CSV download.
// API: routes/api/insights.php (everything under /api/insights, page.insights). The server enforces every rule: a brand
// owner's user (an "outsider") sees his own brand only; brandowner.manage sees and changes every brand; expiry levels are
// changed with expiryalert.manage; finance figures need fin.report; the stock-card export needs report.export.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { API_BASE, ApiError, buildQuery, refreshAccess, tokens, useGet } from '@/api/client';
import { bi, fmtDateOnly, fmtMoney, getLang, lang, num } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

/** `map[key]` for a key that comes from the server: only the map's own entries count. */
export const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- tones and labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
};

/** Brand owner `state` (BrandOwner::STATES): draft → active ⇄ suspended → archived. */
export const OWNER_STATE = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  active: { ar: 'نشط', en: 'Active', ...TONE.ok },
  suspended: { ar: 'موقوف', en: 'Suspended', ...TONE.warn },
  archived: { ar: 'مؤرشف', en: 'Archived', ...TONE.bad },
};
/** The button that moves an owner to a state. */
export const OWNER_MOVE = {
  active: { ar: 'تفعيل', en: 'Activate', tone: 'success' },
  suspended: { ar: 'إيقاف مؤقت', en: 'Suspend', tone: 'softAmber' },
  archived: { ar: 'أرشفة', en: 'Archive', tone: 'softRed' },
  draft: { ar: 'إرجاع لمسودة', en: 'Back to draft', tone: 'soft' },
};
export const OWNER_STATES = Object.keys(OWNER_STATE);

/** Franchisee `status` (Franchisee::STATES). Active and weak are expected to buy. */
export const FR_STATUS = {
  active: { ar: 'نشط', en: 'Active', ...TONE.ok },
  weak: { ar: 'ضعيف', en: 'Weak', ...TONE.warn },
  inactive: { ar: 'غير نشط', en: 'Inactive', ...TONE.neutral },
  stopped: { ar: 'متوقف', en: 'Stopped', ...TONE.bad },
};
export const FR_MOVE = {
  active: { ar: 'تنشيط', en: 'Set active', tone: 'softGreen' },
  weak: { ar: 'تعليم كضعيف', en: 'Mark weak', tone: 'softAmber' },
  inactive: { ar: 'تعليم كغير نشط', en: 'Mark inactive', tone: 'soft' },
  stopped: { ar: 'إيقاف الفرع', en: 'Stop', tone: 'softRed' },
};

/** Alert `severity` (BrandAlert::SEVERITIES). */
export const SEVERITY = {
  danger: { ar: 'حرج', en: 'Critical', ...TONE.bad },
  warning: { ar: 'تحذير', en: 'Warning', ...TONE.warn },
  info: { ar: 'معلومة', en: 'Info', ...TONE.blue },
  success: { ar: 'إيجابي', en: 'Positive', ...TONE.ok },
};
/** Alert `type` (BrandAlert::TYPES) — the same labels the server sends as `typeLabel`. */
export const ALERT_TYPE = {
  no_purchase: { ar: 'انقطاع الشراء', en: 'No purchase' },
  purchase_drop: { ar: 'انخفاض المشتريات', en: 'Purchase drop' },
  target_exceeded: { ar: 'تجاوز الهدف', en: 'Target exceeded' },
  target_not_met: { ar: 'الهدف لم يتحقق', en: 'Target not met' },
  cancelled_orders: { ar: 'طلبات ملغاة', en: 'Cancelled orders' },
  product_drop: { ar: 'انخفاض منتج', en: 'Product drop' },
  overdue_invoice: { ar: 'فاتورة متأخرة', en: 'Overdue invoice' },
  high_returns: { ar: 'مرتجعات مرتفعة', en: 'High returns' },
};
export const alertTypeOptions = () => Object.entries(ALERT_TYPE).map(([v, l]) => ({ v, l }));
export const severityOptions = () => Object.entries(SEVERITY).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));

/** Expiry level `notificationType` (ExpiryLevel::CHANNELS). */
export const CHANNEL = {
  inapp: { ar: 'داخل النظام', en: 'In the app', ...TONE.blue },
  email: { ar: 'بريد إلكتروني', en: 'E-mail', ...TONE.violet },
  both: { ar: 'النظام والبريد', en: 'App and e-mail', ...TONE.teal },
};
export const channelOptions = () => Object.entries(CHANNEL).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
/** Expiry log `emailStatus`: no e-mail server is configured, so an e-mail channel is recorded as not sent. */
export const EMAIL_STATUS = {
  none: { ar: 'داخل النظام فقط', en: 'In-app only', ...TONE.neutral },
  skipped: { ar: 'البريد غير مُعدّ — لم يُرسل', en: 'E-mail not configured — not sent', ...TONE.warn },
  odoo: { ar: 'من سجل أودو', en: "From Odoo's log", ...TONE.violet },
};
export const ODOO_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const OWNED_HELP = { ar: 'هذا السجل يُدار من أودو: يُعرض هنا ويُعدَّل هناك.', en: 'This record is owned by Odoo: it is shown here and changed there.' };

/** Colour index 0..11 of an expiry level (the Odoo palette). */
const PALETTE = [
  TONE.neutral, { fg: '#b23b3b', bg: '#fdecec' }, { fg: '#b26a16', bg: '#fbf0dd' }, { fg: '#8a6d00', bg: '#fbf6d0' }, { fg: '#3C79F5', bg: '#e8effe' }, { fg: '#8a3d6b', bg: '#f8e6f0' },
  { fg: '#b05a3a', bg: '#fbe9e1' }, { fg: '#0d7f93', bg: '#e4f8fb' }, { fg: '#3b4a9c', bg: '#e6e9fb' }, { fg: '#a13a5a', bg: '#fbe4ea' }, { fg: '#1d7a3e', bg: '#e6f9ec' }, { fg: '#654e92', bg: '#efeaf8' },
];
export const levelColor = (i) => PALETTE[Math.abs(Number(i) || 0) % PALETTE.length] || TONE.neutral;
export const PALETTE_SIZE = PALETTE.length;

/** Sales line `kind` / `source`. */
export const LINE_KIND = { invoice: { ar: 'فاتورة', en: 'Invoice', ...TONE.blue }, credit_note: { ar: 'إشعار دائن', en: 'Credit note', ...TONE.warn } };
export const SOURCE = { ops: { ar: 'العمليات', en: 'OPS', ...TONE.teal }, odoo: { ar: 'أودو', en: 'Odoo', ...TONE.violet } };

// ---------------------------------------------------------------- names and figures
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || o.name || '—' : '—');
export const SAR = { ar: 'ر.س', en: 'SAR' };
export const money = (v) => (v == null ? '—' : `${fmtMoney(num(v))} ${bi(SAR)}`);
export const amount = (v) => (v == null ? '—' : fmtMoney(num(v)));
export const pct = (v) => (v == null ? '—' : `${Number(num(v).toFixed(1))}%`);
/** A day the API gives as `YYYY-MM-DD` (or an ISO time), shown as that calendar day. */
export const fmtDay = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
const pad = (n) => String(n).padStart(2, '0');
export const isoDay = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const todayIso = () => isoDay(new Date());
export const monthIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
/** First day of this month, as `YYYY-MM-DD`. */
export const monthStartIso = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`; };
/** `YYYY-MM` shifted by n months. */
export function addMonths(ym, n) {
  const [y, m] = String(ym).split('-').map(Number);
  const d = new Date(y, m - 1 + n, 1);
  return monthIso(d);
}
const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const MONTHS_EN = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** `2026-03` → «مارس 2026» / "Mar 2026" (short: "03/26"). */
export function monthLabel(ym, short = false) {
  const [y, m] = String(ym || '').split('-').map(Number);
  if (!y || !m) return '—';
  if (short) return `${pad(m)}/${String(y).slice(2)}`;
  return `${(lang.value === 'ar' ? MONTHS_AR : MONTHS_EN)[m - 1]} ${y}`;
}
export const userName = (u) => (u ? (lang.value === 'ar' ? u.nameAr || u.nameEn : u.nameEn || u.nameAr) || u.username : '—');

// ---------------------------------------------------------------- query-string state
export const TABS = ['brands', 'alerts', 'expiry', 'card', 'ops'];

/**
 * Everything the page shows is in its query string, so every view is a link:
 *   tab=brands|alerts|expiry|card|ops
 *   brands:  view=owners|rules|history · q · state · sort · order · page · owner=<id> · sec=dashboard|franchisees|alerts|profile
 *            from · to (months YYYY-MM of the dashboard) · fig=sales|returns|overdue|payments · figCustomer · figMonth · figProduct · figKind
 *   alerts:  q · owner · read · type · severity · from · to · page
 *   expiry:  view=levels|upcoming|log · q · level · source · from · to · sort · order · page
 *   card:    sku · warehouse · from · to · internal · page
 *   ops:     view=summary|slow|margin|pnl|ageing|invoices · from · to · days · warehouse · q · sort · order · page
 */
export function useInsightsNav() {
  const qs = useQueryState();
  const { get } = qs;
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a tab; what belonged to the previous one is dropped. */
  const go = (tab, params = {}) => qs.replace({ tab, ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '').map(([k, v]) => [k, String(v)])) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const sortOf = (allowed, defOrder = 'desc') => computed(() => (allowed.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : get('order') === 'desc' ? 'desc' : defOrder } : null));
  const setSort = (s) => patch({ sort: s.key, order: s.order, page: null });
  return { get, page, patch, go, setFilter, setPage, sortOf, setSort };
}

/**
 * The same interface as `useInsightsNav()` on local state — for a list shown inside another view (the alerts of one
 * brand inside its drawer), whose filters must not collide with the keys of the page around it.
 */
export function useLocalNav(initial = {}) {
  const state = ref({ ...initial });
  const get = (k) => (state.value[k] == null ? '' : String(state.value[k]));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  function patch(changes) {
    const next = { ...state.value };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    state.value = next;
  }
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const sortOf = (allowed, defOrder = 'desc') => computed(() => (allowed.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : get('order') === 'desc' ? 'desc' : defOrder } : null));
  const setSort = (s) => patch({ sort: s.key, order: s.order, page: null });
  const reset = (values = {}) => { state.value = { ...values }; };
  return { get, page, patch, setFilter, setPage, sortOf, setSort, reset };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL 350 ms after the last key, at once on
 * Enter; a deep link or a cleared filter refills the box.   const { text, type, apply } = useSearchParam(nav);
 */
export function useSearchParam(nav, key = 'q') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.setFilter(key, v);
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- who the user is here
/** GET /insights/me — shared, kept fresh a minute. `me.value` is null until it arrives. */
export function useInsightsMe() {
  const q = useGet('/insights/me', undefined, { staleTime: 60_000 });
  return { query: q, me: computed(() => q.data.value || null) };
}

/** GET /insights/lookups (brandowner.manage only) — users, categories, brands for the owner form. */
export function useInsightsLookups(enabled) {
  const q = useGet('/insights/lookups', undefined, { staleTime: 30_000, enabled });
  const data = computed(() => q.data.value || null);
  return {
    query: q, data,
    users: computed(() => data.value?.users || []),
    categories: computed(() => data.value?.categories || []),
    brands: computed(() => data.value?.brands || []),
  };
}

/**
 * The active OPS users a list of recipients is chosen from. Two endpoints carry them: GET /insights/lookups (needs
 * brandowner.manage) and GET /users (needs user.manage). Whoever holds neither gets `refused` — the form then keeps
 * the recipients a level already has and says why no other can be added.
 */
export function useRecipientUsers(auth, enabled) {
  // GET /insights/expiry/recipients — the active users a level may name (expiryalert.manage)
  const allowed = computed(() => auth.can('expiryalert.manage'));
  const q = useGet(() => (allowed.value ? '/insights/expiry/recipients' : null), undefined, { staleTime: 30_000, enabled });
  return {
    users: computed(() => (q.data.value?.items || []).map((u) => ({ id: String(u.id), username: u.username, nameAr: u.nameAr, nameEn: u.nameEn }))),
    loading: computed(() => (allowed.value ? q.isLoading.value : false)),
    error: computed(() => (allowed.value ? q.error.value : null)),
    refused: computed(() => !allowed.value),
  };
}

// ---------------------------------------------------------------- file download
/**
 * GET `/api{path}` with the bearer token and save the answer as a file (the stock-card CSV). Resolves the file name;
 * throws ApiError on a refusal. An expired token is refreshed once.
 */
export async function downloadFile(path, params, fallbackName) {
  const url = `${API_BASE}${path}${buildQuery(params)}`;
  const call = () => fetch(url, { headers: { Authorization: `Bearer ${tokens.access || ''}`, 'Accept-Language': getLang(), Accept: 'text/csv, application/json' }, credentials: 'same-origin' })
    .catch(() => { throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر التنزيل', messageEn: 'Download failed' }); });
  let res = await call();
  if (res.status === 401 && (await refreshAccess())) res = await call();
  if (!res.ok) { const p = await res.json().catch(() => ({})); throw new ApiError({ status: res.status, ...p }); }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') || '';
  const file = /filename="?([^";]+)"?/.exec(cd)?.[1] || fallbackName;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = file;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  return file;
}

/** Is the page `key` (a nav key: finance, ledger, products …) in this user's menu? A link is drawn only then. */
export const canOpen = (auth, key) => !!auth.user?.nav?.includes(key);

/** Prints the page (the shell, drawers and `.no-print` blocks are hidden by app.css). */
export const printPage = () => window.print();
