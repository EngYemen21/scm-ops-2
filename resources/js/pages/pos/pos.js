// «نقطة البيع» — what the screens of the section share: label maps (chip colours), money in halalas, the query-string
// state of the page, the lookups every screen starts from, the cart of the selling screen (kept in localStorage per
// session, so a reload keeps the sale) and its server quote.
// API (routes/api/pos.php): GET /pos/{lookups,registers,payment-methods,denominations,sessions,sessions/{id},
// sessions/{id}/report,sessions/{id}/held,orders,orders/{id},orders/{id}/receipt,catalog,catalog/scan} ·
// POST /pos/{sessions,sessions/{id}/close,sessions/{id}/cash-moves,quote,orders,orders/{id}/refund,held,held/{id}/recall,
// held/{id}/discard,registers,payment-methods,denominations,orders/{id}/issue-pending} · PATCH /pos/{registers,payment-methods,denominations}/{id}.
import { computed, onBeforeUnmount, reactive, ref, toValue, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, isApiError, useGet, uuid } from '@/api/client';
import { bi, fmtMoney, lang, num } from '@/i18n';

// ---------------------------------------------------------------- labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
};
export const SESSION_STATE = { open: { ar: 'مفتوحة', en: 'Open', ...TONE.ok }, closed: { ar: 'مغلقة', en: 'Closed', ...TONE.neutral } };
export const ORDER_KIND = { sale: { ar: 'بيع', en: 'Sale', ...TONE.blue }, refund: { ar: 'مرتجع', en: 'Refund', ...TONE.warn } };
export const METHOD_KIND = {
  cash: { ar: 'نقدي', en: 'Cash', ...TONE.ok }, card: { ar: 'بطاقة / شبكة', en: 'Card', ...TONE.blue },
  transfer: { ar: 'تحويل', en: 'Transfer', ...TONE.teal }, account: { ar: 'على الحساب', en: 'On account', ...TONE.violet },
};
export const RESTOCK = { shelf: { ar: 'إلى الرف', en: 'Back to the shelf' }, returns: { ar: 'إلى منطقة المرتجعات', en: 'To the returns area' } };
export const RECEIPT_FORMAT = { '80mm': { ar: 'إيصال حراري 80مم', en: 'Thermal 80mm' }, a4: { ar: 'فاتورة A4', en: 'A4 invoice' } };
export const DENOM_KIND = { coin: { ar: 'عملة معدنية', en: 'Coin' }, note: { ar: 'ورقة نقدية', en: 'Note' } };
export const NOT_READY = {
  warehouse: { ar: 'لا مستودع نشط', en: 'No active warehouse' }, customer: { ar: 'لا عميل افتراضي نشط', en: 'No active walk-in customer' },
  methods: { ar: 'لا طريقة دفع مربوطة بحساب', en: 'No payment method bound to an account' },
};
export const HISTORY_CHIP = { ar: 'سجل أودو', en: 'Odoo history', ...TONE.violet };
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const OWNED_HELP = {
  ar: 'هذا السجل قادم من أودو ويُدار هناك ما دام القسم يتبع أودو — يُعرض هنا للقراءة فقط.',
  en: 'This record comes from Odoo and is managed there while the section follows Odoo — it is read-only here.',
};
export const INACTIVE_CHIP = { ar: 'موقوف', en: 'Inactive', ...TONE.neutral };
export const ACTIVE_CHIP = { ar: 'نشط', en: 'Active', ...TONE.ok };

/** `map[key]` for a key that comes from the server: only the map's own entries count. */
const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);
export const labelOf = (map, key) => bi(own(map, key) || { ar: key || '—', en: key || '—' });

// ---------------------------------------------------------------- names and money
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || o.code || '—' : '—');
export const codeName = (o) => (o ? [o.code, nameOf(o)].filter((x) => x && x !== '—').join(' · ') : '—');
export const SAR = { ar: 'ر.س', en: 'SAR' };
/** An amount with its currency (zero shows as 0). */
export const money = (v) => (v == null || v === '' ? '—' : `${fmtMoney(num(v))} ${bi(SAR)}`);
/** Halalas (integers) — every sum on the client is done in halalas, like the server. */
export const cents = (v) => Math.round(num(v) * 100);
export const fromCents = (c) => Math.round(c) / 100;
export const fmtCents = (c) => `${fmtMoney(fromCents(c))} ${bi(SAR)}`;
export const pct = (v) => `${Number(num(v).toFixed(2))}%`;
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

/** Total of a denomination count `{ [value]: count }` in halalas. */
export const countsTotal = (counts) => Object.entries(counts || {}).reduce((s, [v, c]) => s + cents(v) * (Math.max(0, Math.floor(num(c))) || 0), 0);
/** `{ [value]: count }` → the API's `[{ value, count }]` (zeros dropped). */
export const countsBody = (counts) => Object.entries(counts || {}).map(([value, count]) => ({ value, count: Math.max(0, Math.floor(num(count))) })).filter((x) => x.count > 0);

/** The code of an ApiError (or null). */
export const errCode = (e) => (isApiError(e) ? e.code : null);

// ---------------------------------------------------------------- query-string state
export const VIEWS = ['sell', 'sessions', 'orders', 'setup'];

/**
 * Everything the page shows is in the query string of /pos, so every view, filter and open record is a link:
 *   view=sell|sessions|orders|setup
 *   sessions: q · state · register · cashier · from · to · source · sort · order · page
 *   orders:   q · kind · session · register · customer · cashier · from · to · pending · source · sort · order · page
 *   setup:    set=registers|methods|denominations · q · active · source · page
 *   session=<id> opens a session · order=<id> opens an order · report=<session id> shows the end-of-day report
 */
export function usePosNav(defaultView = 'sell') {
  const route = useRoute();
  const router = useRouter();
  const get = (k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || ''; };
  const view = computed(() => (VIEWS.includes(get('view')) ? get('view') : toValue(defaultView)));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  const replace = (query) => router.replace({ path: route.path, query });
  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '' || v === false) delete next[k]; else next[k] = String(v); }
    return replace(next);
  }
  /** Opens a view; the filters of the previous one are dropped. */
  const go = (to, params = {}) => replace({ view: to, ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  return {
    get, view, page, patch, go, setFilter, setPage,
    sessionOpen: computed(() => get('session') || null), orderOpen: computed(() => get('order') || null), reportOpen: computed(() => get('report') || null),
    openSession: (id) => patch({ session: id, order: null }), closeSession: () => patch({ session: null }),
    openOrder: (id) => patch({ order: id }), closeOrder: () => patch({ order: null }),
    openReport: (id) => patch({ report: id, session: null, order: null }), closeReport: () => patch({ report: null }),
  };
}

/** A search box bound to a key of the query string (350 ms after the last key, at once on Enter). */
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

// ---------------------------------------------------------------- lookups
/** GET /pos/lookups, shared by every screen (one request, fresh for half a minute; every POS mutation invalidates it). */
export function usePosLookups() {
  const q = useGet('/pos/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const list = (key) => computed(() => data.value?.[key] || []);
  const registers = list('registers');
  const methods = list('methods');
  const denominations = list('denominations');
  const bankAccounts = list('bankAccounts');
  const methodById = computed(() => Object.fromEntries(methods.value.map((m) => [m.id, m])));
  /** The payment methods a register takes (active), with the full method (requiresReference, nameEn) joined in. */
  const methodsOf = (register) => (register?.methods || []).map((m) => ({ ...m, ...(methodById.value[m.id] || {}) })).filter((m) => m.active !== false);
  return {
    query: q, data, registers, methods, denominations, bankAccounts, methodById, methodsOf,
    loaded: computed(() => !!data.value),
    session: computed(() => data.value?.session || null),
    follows: computed(() => !!data.value?.follows),
    vatPct: computed(() => data.value?.vatPct ?? null),
    can: computed(() => data.value?.can || {}),
    registerById: (id) => registers.value.find((r) => r.id === id || r.code === id) || null,
    registerOptions: computed(() => registers.value.map((r) => ({ v: r.id, l: { ar: `${r.code} · ${r.nameAr}`, en: `${r.code} · ${r.nameEn || r.nameAr}` } }))),
    bankOptions: computed(() => bankAccounts.value.map((b) => ({ v: b.id, l: { ar: `${b.code} · ${b.nameAr}`, en: `${b.code} · ${b.nameEn || b.nameAr}` } }))),
  };
}

// ---------------------------------------------------------------- the cart
const CART_KEY = (sessionId) => `pos.cart.${sessionId}`;
const readLs = (k) => { try { const s = localStorage.getItem(k); return s ? JSON.parse(s) : null; } catch { return null; } };
const writeLs = (k, v) => { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch { /* storage full or blocked: the sale still works, only a reload loses it */ } };
/** Drops the stored cart of a session (it was closed). */
export const forgetCart = (sessionId) => { if (sessionId) writeLs(CART_KEY(sessionId), null); };
export const rememberRegister =(id) => { try { if (id) localStorage.setItem('pos.register', id); } catch { /* ignore */ } };
export const lastRegister = () => { try { return localStorage.getItem('pos.register') || ''; } catch { return ''; } };

/**
 * The sale being rung up, kept per session in localStorage (`pos.cart.<sessionId>`):
 *   { lines: [{ productId, sku, nameAr, nameEn, price, available, qty, discountPct }], customer: { code, nameAr, nameEn } | null,
 *     clientKey (one per sale, kept until that sale succeeds), heldId (the held sale it was recalled from) }
 */
export function useCart(sessionId) {
  const state = reactive({ lines: [], customer: null, clientKey: uuid(), heldId: null });
  let loadedFor = null;
  function load(id) {
    loadedFor = id;
    const s = id ? readLs(CART_KEY(id)) : null;
    state.lines = Array.isArray(s?.lines) ? s.lines.filter((l) => l && l.productId).map((l) => ({ ...l, qty: Math.max(1, Math.floor(num(l.qty)) || 1), discountPct: num(l.discountPct) })) : [];
    state.customer = s?.customer && s.customer.code ? s.customer : null;
    state.clientKey = typeof s?.clientKey === 'string' && s.clientKey.length >= 8 ? s.clientKey : uuid();
    state.heldId = s?.heldId || null;
  }
  watch(() => toValue(sessionId), (id) => load(id), { immediate: true });
  watch(state, () => {
    if (!loadedFor) return;
    writeLs(CART_KEY(loadedFor), { lines: state.lines, customer: state.customer, clientKey: state.clientKey, heldId: state.heldId });
  }, { deep: true });

  /** Adds a product (catalogue / scan shape); the same product again adds one to its line. Returns the line index. */
  function add(p) {
    const i = state.lines.findIndex((l) => l.productId === p.id);
    if (i >= 0) {
      state.lines[i].qty += 1;
      if (p.price != null) state.lines[i].price = p.price;
      if (p.available != null) state.lines[i].available = p.available;
      return i;
    }
    state.lines.push({ productId: p.id, sku: p.sku, nameAr: p.nameAr, nameEn: p.nameEn, price: p.price, available: p.available, qty: 1, discountPct: 0 });
    return state.lines.length - 1;
  }
  const setQty = (i, q) => { const n = Math.floor(num(q)); if (state.lines[i]) state.lines[i].qty = Math.max(1, Math.min(100000, n || 1)); };
  const setDiscount = (i, d, max = 100) => { if (state.lines[i]) state.lines[i].discountPct = Math.max(0, Math.min(max, num(d))); };
  const remove = (i) => { state.lines.splice(i, 1); };
  const setCustomer = (c) => { state.customer = c && c.code ? { code: c.code, nameAr: c.nameAr || '', nameEn: c.nameEn || '' } : null; };
  /** A new sale: empty cart, walk-in customer, a new client key. */
  function clear() { state.lines = []; state.customer = null; state.heldId = null; state.clientKey = uuid(); }
  const renewKey = () => { state.clientKey = uuid(); };
  /** Loads a recalled held sale: `{ lines: [{ productId, qty, discountPct }], customer }` (names come back with the quote). */
  function loadHeld(payload, heldId) {
    state.lines = (payload?.lines || []).filter((l) => l && l.productId).map((l) => ({ productId: l.productId, sku: l.sku || '', nameAr: l.nameAr || '', nameEn: l.nameEn || '', price: l.price ?? null, available: l.available ?? null, qty: Math.max(1, Math.floor(num(l.qty)) || 1), discountPct: num(l.discountPct) }));
    state.customer = payload?.customer ? (typeof payload.customer === 'string' ? { code: payload.customer, nameAr: '', nameEn: '' } : payload.customer) : null;
    state.heldId = heldId || null;
    state.clientKey = uuid();
  }
  /** The lines as the API wants them. */
  const body = () => state.lines.map((l) => ({ productId: l.productId, qty: l.qty, discountPct: num(l.discountPct) }));
  const count = computed(() => state.lines.reduce((s, l) => s + l.qty, 0));
  return { state, add, setQty, setDiscount, remove, setCustomer, clear, renewKey, loadHeld, body, count, reload: () => load(loadedFor) };
}

/**
 * The server's price of the cart: `POST /pos/quote` 350 ms after the last change (the server is the only source of
 * prices, discounts, VAT and the total). `stale` is true while the cart differs from what was quoted.
 */
export function useQuote(sessionId, cart) {
  const quote = ref(null);
  const error = ref(null);
  const loading = ref(false);
  const quotedSig = ref('');
  let timer = null;
  let seq = 0;
  const sig = () => JSON.stringify([toValue(sessionId), cart.state.customer?.code || '', cart.body()]);
  const stale = computed(() => cart.state.lines.length > 0 && quotedSig.value !== sig());
  async function run() {
    clearTimeout(timer);
    const id = toValue(sessionId);
    const lines = cart.body();
    const s = sig();
    if (!id || !lines.length) { quote.value = null; error.value = null; quotedSig.value = s; loading.value = false; return; }
    const mine = ++seq;
    loading.value = true;
    try {
      const r = await api.post('/pos/quote', { session: id, customer: cart.state.customer?.code || undefined, lines });
      if (mine !== seq) return;
      quote.value = r; error.value = null; quotedSig.value = s;
      // a recalled sale carries only ids: names and prices come back with the quote
      (r.lines || []).forEach((ql, i) => {
        const l = cart.state.lines[i];
        if (l && l.productId === ql.productId) { if (!l.sku) l.sku = ql.sku; if (!l.nameAr) l.nameAr = ql.nameAr; if (!l.nameEn) l.nameEn = ql.nameEn; if (l.price == null) l.price = ql.unitPrice; }
      });
      const c = cart.state.customer;
      if (c && r.customer && (r.customer.code === c.code || r.customer.id === c.code) && (!c.nameAr || c.code !== r.customer.code)) cart.setCustomer(r.customer); // fills the name of a code typed or recalled
    } catch (e) {
      if (mine !== seq) return;
      error.value = e; quotedSig.value = s;
    } finally { if (mine === seq) loading.value = false; }
  }
  const schedule = () => { clearTimeout(timer); timer = setTimeout(run, 350); };
  watch(() => sig(), () => schedule(), { immediate: true });
  onBeforeUnmount(() => clearTimeout(timer));
  return { quote, error, loading, stale, run };
}
