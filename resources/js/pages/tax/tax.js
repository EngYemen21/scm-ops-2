// «الضريبة والفوترة الإلكترونية» — what the tabs of the section share: label maps (with chip colours), small formats,
// the query-string state of the page, the lookups every form and filter starts from, and the authenticated download.
// API (routes/api/tax.php): GET /tax/{overview,lookups,codes,positions,profile,parties,parties/{type}/{id},
// documents/{type}/{id},returns,returns/{id},returns/{id}/entries,returns/{id}/entries/export,einvoices/summary,
// einvoices/pending,einvoices/chain,einvoices,einvoices/{id},einvoices/{id}/xml,units,units/{id},history,history/{id}}
// and the actions listed at each screen. A code / group / position whose `owned` is true is Odoo's while the section
// follows Odoo: the server refuses to change it (TAX_OWNED_BY_SOURCE) and the screens offer only what is OPS's own.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { API_BASE, ApiError, refreshAccess, tokens, useGet } from '@/api/client';
import { bi, fmtDateOnly, fmtNum, getLang, lang, num } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

/** `map[key]` for a key that comes from the server: only the map's own entries count. */
export const own = (map, key) => (map && key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- colours and labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
};

/** The labels of the server (VatReturn / Einvoice / EinvoiceUnit ::LABELS); the lookups carry the same, these cover the first paint. */
const RETURN_LABELS = { draft: ['مسودة', 'Draft'], reviewed: ['تمت المراجعة', 'Reviewed'], filed: ['مُقدَّم', 'Filed'], locked: ['مُقفل', 'Locked'], cancelled: ['ملغى', 'Cancelled'] };
const EINVOICE_LABELS = {
  not_sent: ['لم تُرسل', 'Not sent'], queued: ['بانتظار الإرسال', 'Queued'], reported: ['مُبلَّغة', 'Reported'], cleared: ['مُجازة', 'Cleared'],
  accepted_warnings: ['مقبولة مع تحذيرات', 'Accepted with warnings'], rejected: ['مرفوضة', 'Rejected'], withdrawn: ['مسحوبة', 'Withdrawn'],
};
const UNIT_LABELS = {
  not_onboarded: ['غير مربوطة بالهيئة', 'Not onboarded'], csr_ready: ['طلب الشهادة جاهز', 'Certificate request ready'], compliance: ['شهادة الامتثال صادرة', 'Compliance certificate issued'],
  compliant: ['اجتازت فحوص الامتثال', 'Compliance checks passed'], active: ['مربوطة وتعمل', 'Onboarded'], retired: ['موقوفة', 'Retired'],
};
const RETURN_TONES = { draft: TONE.blue, reviewed: TONE.violet, filed: TONE.teal, locked: TONE.ok, cancelled: TONE.neutral };
const EINVOICE_TONES = { not_sent: TONE.neutral, queued: TONE.blue, reported: TONE.ok, cleared: TONE.ok, accepted_warnings: TONE.warn, rejected: TONE.bad, withdrawn: TONE.neutral };
const UNIT_TONES = { not_onboarded: TONE.warn, csr_ready: TONE.blue, compliance: TONE.violet, compliant: TONE.teal, active: TONE.ok, retired: TONE.neutral };

const fromPairs = (labels, tones) => Object.fromEntries(Object.entries(labels).map(([k, [ar, en]]) => [k, { ar, en, ...(tones[k] || TONE.neutral) }]));
/** `[{ key, ar, en }]` of the lookups → a chip map, over the built-in labels. */
const fromRows = (rows, tones, fallback) => ({ ...fallback, ...Object.fromEntries((rows || []).map((r) => [r.key, { ar: r.ar, en: r.en || r.ar, ...(tones[r.key] || TONE.neutral) }])) });

export const RETURN_STATUS = fromPairs(RETURN_LABELS, RETURN_TONES);
export const EINVOICE_STATUS = fromPairs(EINVOICE_LABELS, EINVOICE_TONES);
export const UNIT_STATUS = fromPairs(UNIT_LABELS, UNIT_TONES);
/** EinvoiceHistory::LABELS (not in the lookups). */
export const HISTORY_STATUS = {
  accepted: { ar: 'أُرسلت وقُبلت', en: 'Sent and accepted', ...TONE.ok }, not_sent: { ar: 'لم تُرسل', en: 'Not sent', ...TONE.neutral },
  error: { ar: 'تعذّر الإرسال', en: 'Sending failed', ...TONE.bad }, cancelling: { ar: 'بانتظار الإلغاء', en: 'Waiting to be cancelled', ...TONE.warn },
  cancelled: { ar: 'ملغاة', en: 'Cancelled', ...TONE.neutral },
};
/** Odoo's `move_type` of a history row. */
export const MOVE_TYPE = {
  out_invoice: { ar: 'فاتورة عميل', en: 'Customer invoice' }, out_refund: { ar: 'إشعار دائن لعميل', en: 'Customer credit note' },
  in_invoice: { ar: 'فاتورة مورد', en: 'Vendor bill' }, in_refund: { ar: 'إشعار دائن من مورد', en: 'Vendor credit note' }, entry: { ar: 'قيد', en: 'Journal entry' },
};
/** The kind of a document behind a figure (VatSources::DOC_LABELS) and of an e-invoice. */
export const DOC_KIND = {
  invoice: { ar: 'فاتورة عميل', en: 'Customer invoice' }, credit_note: { ar: 'إشعار دائن', en: 'Credit note' }, debit_note: { ar: 'إشعار مدين', en: 'Debit note' },
  supplier_invoice: { ar: 'فاتورة مورد', en: 'Supplier invoice' }, expense: { ar: 'سند مصروف', en: 'Expense voucher' }, fuel: { ar: 'سجل وقود', en: 'Fuel record' },
  maintenance: { ar: 'أمر صيانة', en: 'Maintenance order' },
};
/** Which OPS document type carries a tax code (Classifier::DOC_TYPES). */
export const DOC_TYPE = {
  CustomerInvoice: { ar: 'فاتورة عميل', en: 'Customer invoice' }, SupplierInvoice: { ar: 'فاتورة مورد', en: 'Supplier invoice' }, DebitNote: { ar: 'إشعار مدين لمورد', en: 'Supplier debit note' },
  Expense: { ar: 'سند مصروف', en: 'Expense voucher' }, FuelRecord: { ar: 'سجل وقود', en: 'Fuel record' }, MaintenanceOrder: { ar: 'أمر صيانة', en: 'Maintenance order' },
};
/** How a document got its tax code (Classifier `how`; `nobox` = its code feeds no line of the return). */
export const HOW = {
  explicit: { ar: 'حدّده مستخدم للمستند', en: 'Set on the document by a person', ...TONE.violet }, partner: { ar: 'رمز العميل / المورد', en: 'The partner\'s code', ...TONE.blue },
  position: { ar: 'من الموقف الضريبي', en: 'From the fiscal position', ...TONE.teal }, default: { ar: 'الرمز الافتراضي', en: 'The default code', ...TONE.neutral },
  none: { ar: 'بلا رمز', en: 'No code', ...TONE.bad }, nobox: { ar: 'رمز بلا بند', en: 'Code without a line', ...TONE.warn }, cleared: { ar: 'أُزيل القرار', en: 'Decision removed', ...TONE.neutral },
};
export const PROFILE_KIND = { standard: { ar: 'قياسية (منشآت)', en: 'Standard (B2B)', ...TONE.violet }, simplified: { ar: 'مبسطة (أفراد)', en: 'Simplified (B2C)', ...TONE.teal } };
export const INVOICE_KIND = { auto: { ar: 'تلقائي (حسب الرقم الضريبي)', en: 'Automatic (by VAT number)' }, standard: { ar: 'قياسية دائمًا', en: 'Always standard' }, simplified: { ar: 'مبسطة دائمًا', en: 'Always simplified' } };
export const USE = { sale: { ar: 'مبيعات', en: 'Sales', ...TONE.blue }, purchase: { ar: 'مشتريات', en: 'Purchases', ...TONE.violet } };
export const FREQUENCY = { monthly: { ar: 'شهري', en: 'Monthly' }, quarterly: { ar: 'ربع سنوي', en: 'Quarterly' } };
export const SIDE = { sale: { ar: 'مبيعات', en: 'Sales' }, purchase: { ar: 'مشتريات', en: 'Purchases' }, output: { ar: 'ضريبة المخرجات', en: 'Output VAT' }, input: { ar: 'ضريبة المدخلات', en: 'Input VAT' } };
export const COLUMN = { amount: { ar: 'المبلغ', en: 'Amount' }, adjustment: { ar: 'التعديل', en: 'Adjustment' } };
export const LEVEL = {
  blocker: { cls: 'red', ar: 'يمنع المراجعة', en: 'Blocks the review' }, warning: { cls: 'amber', ar: 'تنبيه', en: 'Warning' }, info: { cls: 'teal', ar: 'للعلم', en: 'For information' },
  error: { cls: 'red', ar: 'خطأ', en: 'Error' },
};
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const ARCHIVED_CHIP = { ar: 'مؤرشف', en: 'Archived', ...TONE.neutral };
export const DEFAULT_CHIP = { ar: 'افتراضي', en: 'Default', ...TONE.ok };
export const SELECT = { ar: '— اختر —', en: '— select —' };
export const ANY = { ar: '— الكل —', en: '— all —' };
export const SAR = { ar: 'ر.س', en: 'SAR' };

/** A label of a map for a key of the server, the key itself when unknown. */
export const labelOf = (map, k) => bi(own(map, k) || { ar: k ?? '—', en: k ?? '—' });
/** `{ k: {ar,en} }` → select options. */
export const optionsOf = (map) => Object.entries(map).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));

// ---------------------------------------------------------------- formats
/** An amount of the API (decimal text) with two decimals, «—» for nothing. */
export const sar = (v) => (v == null || v === '' ? '—' : fmtNum(num(v), 2));
/** Same, with the currency. */
export const money = (v) => (v == null || v === '' ? '—' : `${fmtNum(num(v), 2)} ${bi(SAR)}`);
export const pct = (v) => (v == null || v === '' ? '—' : `${Number(num(v).toFixed(2))}%`);
/** A day the API gives as `YYYY-MM-DD`, shown as the same calendar day. */
export const fmtDay = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
/** Arabic / English name of a record (nameAr / nameEn). */
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || '—' : '—');
/** `CODE · name` of a tax code. */
export const codeLabel = (c) => (c ? `${c.code} · ${nameOf(c)}` : '—');
/** Negative figures in red. */
export const signClass = (v) => (num(v) < 0 ? 'text-bad' : '');
/** The keys of `now` whose value differs from `before`. */
export function changedOnly(before, now) {
  const out = {};
  for (const [k, v] of Object.entries(now)) if (JSON.stringify(v ?? null) !== JSON.stringify(before[k] ?? null)) out[k] = v;
  return out;
}
/** '' → null for a text field of a body. */
export const textOrNull = (v) => { const s = String(v ?? '').trim(); return s === '' ? null : s; };

// ---------------------------------------------------------------- query-string state
export const TABS = ['overview', 'returns', 'einvoices', 'units', 'codes', 'positions', 'parties', 'history'];

/**
 * Everything the page shows is in the query string of /tax, so every tab, filter and open record is a link:
 *   tab=overview|returns|einvoices|units|codes|positions|parties|history · q · sort · order · page
 *   returns:   status · year · frequency                    ret=<id> opens a return; box=<n|corrections|unclassified|outside> its documents
 *   einvoices: view=register|pending|chain · status · profile · docKind · from · to · current · signed · issues    doc=<id>
 *   codes:     view=codes|groups · use · kind · active · origin · box · group        code=<id>|new
 *   positions: pos=<id>|new          parties: view=profile|customer|supplier · state · position · party=<id>
 *   history:   status · moveType · from · to             hist=<id>
 */
export function useTaxNav() {
  const qs = useQueryState();
  const { get } = qs;
  const tab = computed(() => (TABS.includes(get('tab')) ? get('tab') : 'overview'));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));

  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  /** Opens a tab; what belonged to the previous one (filters, page, open records) is dropped. */
  const go = (to, params = {}) => qs.replace({ ...(to && to !== 'overview' ? { tab: to } : {}), ...Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '').map(([k, v]) => [k, String(v)])) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  /** `{ key, order }` from the query string when the key is one the list sorts by. */
  const sortOf = (allowed) => computed(() => (allowed.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : 'desc' } : null));
  const setSort = (s) => patch({ sort: s.key, order: s.order, page: null });
  return { get, tab, page, patch, go, setFilter, setPage, sortOf, setSort };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL 350 ms after the last key, at once on
 * Enter; a deep link or a filter that clears the key refills the box.
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

/** The same for a component-local search (a drawer's list): `{ text, applied, type, apply }`. */
export function useLocalSearch(onApply) {
  const text = ref('');
  const applied = ref('');
  let timer = null;
  function apply() { clearTimeout(timer); const v = String(text.value ?? '').trim(); if (v !== applied.value) { applied.value = v; onApply?.(); } }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  function reset() { clearTimeout(timer); text.value = ''; applied.value = ''; }
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, applied, type, apply, reset };
}

// ---------------------------------------------------------------- lookups
/**
 * GET /tax/lookups, shared by every tab (one request, kept fresh for half a minute) and shaped for the pickers.
 */
export function useTaxLookups() {
  const q = useGet('/tax/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const list = (key) => computed(() => data.value?.[key] || []);
  const boxes = list('boxes');
  const codes = list('codes');
  const groups = list('groups');
  const positions = list('positions');
  const kinds = computed(() => Object.fromEntries((data.value?.kinds || []).map((k) => [k.key, { ar: k.ar, en: k.en }])));
  const boxMap = computed(() => Object.fromEntries(boxes.value.map((b) => [String(b.box), b])));
  const codeOptions = (use) => codes.value.filter((c) => c.active && (!use || c.use === use)).map((c) => ({ v: c.id, l: { ar: `${c.code} · ${c.nameAr}`, en: `${c.code} · ${c.nameEn || c.nameAr}` } }));
  return {
    query: q, data, boxes, codes, groups, positions, kinds, boxMap, codeOptions,
    follows: computed(() => !!data.value?.follows),
    returnStates: computed(() => fromRows(data.value?.returnStates, RETURN_TONES, RETURN_STATUS)),
    einvoiceStates: computed(() => fromRows(data.value?.einvoiceStates, EINVOICE_TONES, EINVOICE_STATUS)),
    unitStates: computed(() => fromRows(data.value?.unitStates, UNIT_TONES, UNIT_STATUS)),
    treatments: computed(() => (data.value?.ledgerTreatments || []).map((x) => ({ v: x.key, l: { ar: x.ar, en: x.en } }))),
    treatmentMap: computed(() => Object.fromEntries((data.value?.ledgerTreatments || []).map((x) => [x.key, { ar: x.ar, en: x.en }]))),
    environments: computed(() => (data.value?.environments || []).map((x) => ({ v: x.key, l: { ar: x.ar, en: x.en } }))),
    sellerIdSchemes: computed(() => (data.value?.sellerIdSchemes || []).map((x) => ({ v: x.key, l: { ar: `${x.key} · ${x.ar}`, en: `${x.key} · ${x.ar}` } }))),
    buyerIdSchemes: computed(() => (data.value?.buyerIdSchemes || []).map((x) => ({ v: x.key, l: { ar: `${x.key} · ${x.ar}`, en: `${x.key} · ${x.ar}` } }))),
    exemptions: list('exemptions'),
    allowedBoxes: computed(() => data.value?.allowedBoxes || {}),
    kindsByUse: computed(() => data.value?.kindsByUse || { sale: [], purchase: [] }),
    categories: computed(() => data.value?.categories || {}),
    groupOptions: computed(() => groups.value.filter((g) => g.active).map((g) => ({ v: g.id, l: { ar: g.nameAr, en: g.nameEn || g.nameAr } }))),
    positionOptions: computed(() => positions.value.filter((p) => p.active).map((p) => ({ v: p.id, l: { ar: p.nameAr, en: p.nameEn || p.nameAr } }))),
    /** Lines 1–5, 7–11 and 14 — where an adjustment may stand. */
    adjustableBoxes: computed(() => boxes.value.filter((b) => !b.sum || b.box === 14).map((b) => ({ v: String(b.box), l: { ar: `${b.box} · ${b.ar}`, en: `${b.box} · ${b.en}` } }))),
  };
}

/** The label of a line of the return ("1 · Standard rated sales"), from the lookups' catalogue. */
export const boxLabel = (boxMap, n) => {
  if (n == null || n === '') return '—';
  const b = boxMap?.[String(n)];
  return b ? `${b.box} · ${bi({ ar: b.ar, en: b.en })}` : String(n);
};

// ---------------------------------------------------------------- files
/**
 * GET a file of the API with the bearer token (a bare link cannot carry it) and hand it to the browser as a download.
 * Resolves the file name; throws ApiError on a refusal (so useAction shows it). An expired token is refreshed once.
 */
export async function downloadFile(path, params, fallbackName) {
  const qsText = params ? new URLSearchParams(Object.entries(params).filter(([, v]) => v != null && v !== '').map(([k, v]) => [k, String(v)])).toString() : '';
  const url = API_BASE + path + (qsText ? `?${qsText}` : '');
  const call = () => fetch(url, { headers: { Accept: '*/*', 'Accept-Language': getLang(), ...(tokens.access ? { Authorization: `Bearer ${tokens.access}` } : {}) }, credentials: 'same-origin', cache: 'no-store' });
  let res;
  try {
    res = await call();
    if (res.status === 401 && (await refreshAccess())) res = await call();
  } catch {
    throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر الاتصال بالخادم', messageEn: 'Cannot reach the server' });
  }
  if (!res.ok) {
    const p = await res.json().catch(() => ({}));
    throw new ApiError({ status: res.status, category: p.category, code: p.code, message: p.message, messageEn: p.messageEn, details: p.details, requestId: p.requestId });
  }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') || '';
  const name = /filename="?([^";]+)"?/.exec(cd)?.[1] || fallbackName;
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href; a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 10_000);
  return name;
}
