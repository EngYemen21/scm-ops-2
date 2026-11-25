// «قوائم الأسعار» — what the views of the page share: label maps (with chip colours), formats, the query-string state,
// the lookups and the parser of a pasted price sheet.
// API (routes/api/pricing.php, all under /api/pricing):
//   GET lookups · summary · price · supplier-price          POST prices
//   GET lists · lists/{id} · lists/{id}/rules · lists/{id}/customers · customers · customers/{id} · list-prices ·
//       supplier-prices · supplier-prices/suppliers
//   pricelist.manage:      POST lists · PATCH lists/{id} · POST lists/{id}/{archive|restore|default|undefault|duplicate|rules|prices|customers}
//                          PATCH rules/{id} · POST rules/{id}/{archive|restore} · POST customers/{id}/{assign|unassign}
//                          POST list-prices · list-prices/bulk · list-prices/{product}/clear
//   supplierprice.manage:  POST supplier-prices · PATCH supplier-prices/{id} · POST supplier-prices/{id}/{archive|restore}
// Shapes (camelCase; a price is a decimal string with two to four decimals, a quantity or a percentage a number):
//   List     { id, code, name, currency, active, isDefault, validFrom, validTo, period, usable, note, rulesCount, activeRulesCount,
//              customersCount, pendingRules?, baseFor?, sourceSystem, externalRef, owned, updatedAt }
//   Rule     { id, listId, appliesTo: product|category|all, product?, category?, minQty, validFrom, validTo, period, compute: fixed|percent|formula,
//              fixedPrice, percent, base: list|cost|pricelist, baseList?, discountPct, surcharge, roundTo, minMargin, maxMargin, active, note,
//              pending, pendingLabel, text: { ar, en }, owned }
//   Customer { id, code, nameAr, nameEn, city, active, assignment: { listId, list, basis: manual|partner|order, basisRef, basisAt, note, owned } | null,
//              effective: { list | null, via: assigned|default|none }, locked? }
//   Answer   { found, price, currency, source: rule|list_price|none, qty, date, customer?, product, priceList?: { …, via }, assignment?, rule?,
//              listPrice, discountPct, reason: { ar, en }, notes: [{ ar, en }], problem, steps: [{ kind, ar, en, value }], considered: [{ outcome, why, rule }] }
//   Offer    SupplierPrice + { net, leadDaysEffective, orderable, notOrderableWhy, best }
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { bi, fmtDateOnly, lang, nm, num, t } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

const NONE = [];

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses. */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const DEFAULT_CHIP = { ar: 'الافتراضية', en: 'Default', ...TONE.blue };
export const ARCHIVED_CHIP = { ar: 'مؤرشفة', en: 'Archived', ...TONE.off };
export const WAITING_CHIP = { ar: 'بانتظار الربط', en: 'Waiting for a link', ...TONE.warn };
/** Where today stands against a validity period. */
export const PERIOD = {
  valid: { ar: 'سارية', en: 'Valid', ...TONE.ok }, expired: { ar: 'منتهية', en: 'Expired', ...TONE.bad }, future: { ar: 'لم تبدأ', en: 'Not started', ...TONE.blue },
};
export const APPLIES = {
  product: { ar: 'منتج', en: 'Product', ...TONE.neutral }, category: { ar: 'فئة', en: 'Category', ...TONE.violet }, all: { ar: 'كل المنتجات', en: 'Everything', ...TONE.blue },
};
export const COMPUTE = { fixed: { ar: 'سعر ثابت', en: 'Fixed price' }, percent: { ar: 'خصم بنسبة', en: 'Discount %' }, formula: { ar: 'معادلة', en: 'Formula' } };
export const BASE = { list: { ar: 'السعر المعلن للمنتج', en: "The product's list price" }, cost: { ar: 'تكلفة المنتج', en: "The product's cost" }, pricelist: { ar: 'سعره في قائمة أخرى', en: 'Its price on another list' } };
/** How OPS knows the list of a customer. */
export const BASIS = {
  manual: { ar: 'حُدِّدت هنا', en: 'Set here', ...TONE.neutral },
  partner: { ar: 'من بطاقة العميل في أودو', en: 'Odoo — the partner', ...TONE.violet },
  order: { ar: 'من آخر طلب في أودو', en: 'Odoo — the latest order', ...TONE.warn },
};
export const BASIS_HELP = {
  manual: { ar: 'اختارها مستخدم في هذا النظام', en: 'Chosen by a person in OPS' },
  partner: { ar: 'حقل «قائمة الأسعار» في بطاقة العميل في أودو — تُغيَّر هناك', en: 'The price list on the partner in Odoo — changed there' },
  order: { ar: 'قُرئت من قائمة أسعار آخر طلب للعميل في أودو لأن النسخة لا تحمل حقل البطاقة — يمكن تصحيحها هنا', en: "Read from the price list of the customer's latest Odoo order, because the copy does not carry the partner's own field — it can be corrected here" },
};
/** Which list a customer is priced with. */
export const VIA = {
  assigned: { ar: 'قائمة العميل', en: "The customer's list", ...TONE.ok }, default: { ar: 'القائمة الافتراضية', en: 'The default list', ...TONE.blue },
  given: { ar: 'القائمة المختارة', en: 'The list picked', ...TONE.neutral }, none: { ar: 'بلا قائمة', en: 'No list', ...TONE.off },
};
/** Where a customer price came from. */
export const SOURCE = {
  rule: { ar: 'قاعدة في القائمة', en: 'A rule of the list', ...TONE.ok }, list_price: { ar: 'السعر المعلن للمنتج', en: "The product's list price", ...TONE.blue },
  none: { ar: 'لا سعر', en: 'No price', ...TONE.bad },
};
/** Where a supplier price came from. */
export const SUPPLIER_SOURCE = {
  supplier_price: { ar: 'قائمة أسعار المورد', en: "The supplier's price list", ...TONE.ok }, product_supplier: { ar: 'ربط المنتج بالمورد', en: "The product's supplier link", ...TONE.warn },
  purchase_price: { ar: 'سعر الشراء على المنتج', en: "The product's purchase price", ...TONE.warn }, none: { ar: 'لا سعر', en: 'No price', ...TONE.bad },
};
/** Why a rule that was looked at did or did not decide. */
export const OUTCOME = {
  applied: { ar: 'طُبِّقت', en: 'Applied', ...TONE.ok }, belowMinQty: { ar: 'الكمية أقل', en: 'Quantity too low', ...TONE.warn }, notStarted: { ar: 'لم تبدأ', en: 'Not started', ...TONE.blue },
  expired: { ar: 'منتهية', en: 'Expired', ...TONE.bad }, outranked: { ar: 'سبقتها قاعدة', en: 'Outranked', ...TONE.off },
  future: { ar: 'لم تبدأ', en: 'Not started', ...TONE.blue }, tier: { ar: 'شريحة أخرى', en: 'Another tier', ...TONE.off },
};
export const SELECT_PH = { ar: '— اختر —', en: '— select —' };

// ---------------------------------------------------------------- formats
/** A price: two to four decimals, grouped ("1,250.00", "12.125"); «—» when there is none. */
export function price(v) {
  if (v == null || v === '') return '—';
  return num(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
}
/** A quantity or a percentage as it is, without trailing zeros. */
export const plain = (v) => (v == null || v === '' ? '—' : num(v).toLocaleString('en-US', { maximumFractionDigits: 4 }));
/** A day the API gives as `YYYY-MM-DD`. */
export const day = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
/** "من 2026/01/01 إلى 2026/12/31" · "حتى …" · "من …" · «دائمة». */
export function periodText(from, to) {
  if (from && to) return t(`من ${day(from)} إلى ${day(to)}`, `${day(from)} → ${day(to)}`);
  if (from) return t(`من ${day(from)}`, `from ${day(from)}`);
  if (to) return t(`حتى ${day(to)}`, `until ${day(to)}`);
  return t('دائمة', 'Open-ended');
}
export const nameOf = (o) => (o ? nm(o) || '—' : '—');
/** Today as `YYYY-MM-DD` (the user's own day). */
export function todayIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
/** What a rule covers, in words. */
export function ruleTarget(r) {
  if (r.appliesTo === 'all') return bi(APPLIES.all);
  if (r.appliesTo === 'category') return r.category ? `${t('فئة', 'Category')}: ${nameOf(r.category)}` : r.pendingLabel || bi(APPLIES.category);
  return r.product ? nameOf(r.product) : r.pendingLabel || bi(APPLIES.product);
}

// ---------------------------------------------------------------- query-string state
export const TABS = ['check', 'lists', 'customers', 'base', 'suppliers'];

/**
 * Everything the page shows is in the query string of /pricing, so every view, filter and open list is a link:
 *   tab=check|lists|customers|base|suppliers · q · page · sort · order
 *   check:      side=customer|supplier · customer · plist · product · qty · date · supplier
 *   lists:      state=active|archived|all · source=odoo|ops      list=<code> opens the list (lt=rules|customers · rq · rstate · rapplies · rpage)
 *   customers:  of=<list code> · assigned=yes|no · basis=manual|partner|order
 *   base:       has=yes|no · source
 *   suppliers:  view=prices|suppliers · supplier · product · period=valid|expired|future · state=active|archived|pending|all · source
 */
export function usePricingNav() {
  const qs = useQueryState();
  const { get } = qs;
  const tab = computed(() => (TABS.includes(get('tab')) ? get('tab') : 'check'));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  const listOpen = computed(() => get('list') || null);
  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a tab with its own filters; what belonged to the previous one is dropped. */
  const go = (to, params = {}) => qs.replace({ ...(to && to !== 'check' ? { tab: to } : {}), ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const openList = (code) => patch({ list: code, lt: null, rq: null, rstate: null, rapplies: null, rpage: null });
  const closeList = () => patch({ list: null, lt: null, rq: null, rstate: null, rapplies: null, rpage: null });
  /** `{ key, order }` of the table sort when the URL names one of the allowed keys. */
  const sortOf = (allowed) => (allowed.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'desc' ? 'desc' : 'asc' } : null);
  const setSort = (s) => patch({ sort: s.key, order: s.order, page: null });
  return { get, tab, page, listOpen, patch, go, setFilter, setPage, openList, closeList, sortOf, setSort };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
 *   const { text, type, apply } = useSearchParam(nav);        <TextInput :model-value="text" @update:model-value="type" @enter="apply" />
 */
export function useSearchParam(nav, key = 'q', pageKey = 'page') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.patch({ [key]: v, [pageKey]: null });
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- lookups
/**
 * GET /pricing/lookups, shared by every view (one request, kept fresh for half a minute):
 *   const lk = usePricingLookups();   lk.follows.value · lk.costVisible.value · lk.listOptions.value · lk.categoryOptions.value
 */
export function usePricingLookups() {
  const q = useGet('/pricing/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const lists = computed(() => data.value?.lists || NONE);
  const listLabel = (l) => `${l.name} · ${l.code}${l.isDefault ? ` · ${bi(DEFAULT_CHIP)}` : ''}`;
  return {
    query: q, data, lists,
    /** The section follows Odoo: what came from Odoo is read-only here. */
    follows: computed(() => !!data.value?.follows),
    /** The caller may see what the company pays (supplier prices, cost). */
    costVisible: computed(() => !!data.value?.costVisible),
    loaded: computed(() => !!data.value),
    /** Every list a customer can be given or a rule can start from (the active ones). */
    listOptions: computed(() => lists.value.filter((l) => l.active).map((l) => ({ v: l.code, l: listLabel(l) }))),
    allListOptions: computed(() => lists.value.map((l) => ({ v: l.code, l: `${listLabel(l)}${l.active ? '' : ` · ${bi(ARCHIVED_CHIP)}`}` }))),
    categoryOptions: computed(() => (data.value?.categories || NONE).map((c) => ({ v: c.id, l: { ar: c.nameAr, en: c.nameEn || c.nameAr } }))),
    currencies: computed(() => data.value?.currencies || ['SAR']),
  };
}

/** GET /pricing/summary — the figures of the head of the page (refreshed every two minutes). */
export function usePricingSummary() {
  const q = useGet('/pricing/summary', undefined, { refetchInterval: 120_000 });
  return { query: q, data: computed(() => q.data.value || null) };
}

// ---------------------------------------------------------------- a pasted price sheet
const DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '٫': '.', '٬': '' };
const latin = (s) => String(s).replace(/[٠-٩٫٬]/g, (c) => DIGITS[c]);
/** "1,250.50" · "1250,5" · "١٢٫٥" → a number, or NaN. */
function amount(cell) {
  let s = latin(cell).trim().replace(/\s/g, '');
  if (/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
  else if (/^\d+,\d+$/.test(s)) s = s.replace(',', '.');
  return /^\d+(\.\d+)?$/.test(s) ? Number(s) : NaN;
}

/**
 * Lines of "code, price" (and, with `withQty`, a third cell: the minimum quantity) as pasted from a sheet: cells are
 * separated by a tab, a semicolon or a comma, or — on a line of two cells — by spaces. A first line that is a header
 * is skipped. Returns `{ items: [{ sku, price, minQty?, line }], errors: [{ line, text, message }] }`.
 */
export function parseSheet(text, withQty = false) {
  const items = [];
  const errors = [];
  const lines = String(text || '').split(/\r?\n/);
  lines.forEach((raw, i) => {
    const row = raw.trim();
    if (!row) return;
    let cells = row.includes('\t') ? row.split('\t') : row.includes(';') ? row.split(';') : row.split(',');
    if (cells.length === 1) { const m = /^(\S+)\s+(\S+)(?:\s+(\S+))?$/.exec(row); if (m) cells = m.slice(1).filter((x) => x != null); }
    // "P001,1,250.50" — a price written with a thousands comma was split in two: put it back
    if (!row.includes('\t') && !row.includes(';') && cells.length === (withQty ? 4 : 3) && /^\d{1,3}$/.test(cells[1].trim()) && /^\d{3}(\.\d+)?$/.test(cells[2].trim())) cells = [cells[0], cells[1] + cells[2], ...cells.slice(3)];
    cells = cells.map((c) => c.trim());
    const sku = cells[0] || '';
    const value = amount(cells[1] ?? '');
    const line = i + 1;
    if (!sku || Number.isNaN(value)) {
      if (!items.length && !errors.length && sku && cells.length > 1) return; // a header line
      errors.push({ line, text: row, message: t('السطر ليس «رمز، سعر»', 'The line is not "code, price"') });
      return;
    }
    if (value <= 0) { errors.push({ line, text: row, message: t('السعر يجب أن يكون أكبر من صفر', 'The price must be greater than zero') }); return; }
    const item = { sku, price: value, line };
    if (withQty && cells[2] != null && cells[2] !== '') {
      const q = amount(cells[2]);
      if (Number.isNaN(q)) { errors.push({ line, text: row, message: t('الحد الأدنى للكمية ليس رقمًا', 'The minimum quantity is not a number') }); return; }
      item.minQty = q;
    }
    items.push(item);
  });
  return { items, errors };
}

/** The current UI language as a getter usable in plain modules. */
export const isArabic = () => lang.value === 'ar';
