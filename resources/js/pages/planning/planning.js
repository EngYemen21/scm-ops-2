// «تخطيط الطلب والشراء» — what the views of the section share: label maps (with chip colours), small formats, the
// query-string state of the page, the lookups and the authenticated file download.
// API (routes/api/planning.php, shapes in app/Planning/Support/Shape.php):
//   GET  /planning/lookups · runs · runs/{id} · runs/{id}/{items,items/{item},requirements,patterns,events,budgets,export}
//        budgets/{id} · budgets/{id}/allocations · plans · plans/{id} · warehouse-maps · attributes/{product|customer}
//   POST runs · runs/{id}/{compute,advance,stop,freeze,archive,restore,copy,push,actuals,budgets} · budgets/{id}/{allocate,plans}
//        plans/{id}/{submit,approve,return,close,cancel,request} · plans/{id}/lines/{line}/cancel
//   PATCH runs/{id} · budgets/{id} · plans/{id} · plans/{id}/lines/{line}     PUT warehouse-maps/{odooId} · attributes/{entity}/{id}
// A record whose `owned` is true came from Odoo while the section follows Odoo: read-only here (PLANNING_OWNED_BY_SOURCE).
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { API_BASE, ApiError, buildQuery, refreshAccess, tokens, useGet } from '@/api/client';
import { bi, fmtDateOnly, fmtMoney, fmtNum, getLang, lang, num } from '@/i18n';
import { useQueryState } from '../dashboard/shared';

const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, teal: { fg: '#0d7f93', bg: '#e4f8fb' }, neutral: { fg: '#55506a', bg: '#F1EFF6' },
};

/** Run.status: draft → computing → computed → frozen. */
export const RUN_STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral }, computing: { ar: 'قيد الحساب', en: 'Computing', ...TONE.blue },
  computed: { ar: 'محسوب', en: 'Computed', ...TONE.ok }, frozen: { ar: 'مجمّد', en: 'Frozen', ...TONE.violet },
};
/** Run.method. */
export const METHOD = {
  average: { ar: 'المتوسط', en: 'Average', ...TONE.neutral }, wma: { ar: 'المتوسط المتحرك المرجّح', en: 'Weighted moving average', ...TONE.blue },
  seasonal: { ar: 'موسمي (السنة الماضية)', en: 'Seasonal (last year)', ...TONE.teal }, cadence: { ar: 'إيقاع طلبات العملاء', en: 'Customer cadence', ...TONE.violet },
  none: { ar: 'لا شيء', en: 'None', ...TONE.neutral },
};
export const ITEM_STATUS = {
  shortage: { ar: 'عجز', en: 'Shortage', ...TONE.bad }, surplus: { ar: 'فائض', en: 'Surplus', ...TONE.warn },
  ok: { ar: 'سليم', en: 'OK', ...TONE.ok }, no_demand: { ar: 'لا طلب', en: 'No demand', ...TONE.neutral },
};
export const CRITICALITY = {
  critical: { ar: 'حرج', en: 'Critical', fg: '#fff', bg: '#b23b3b' }, high: { ar: 'عالٍ', en: 'High', ...TONE.warn },
  normal: { ar: 'عادي', en: 'Normal', ...TONE.neutral }, low: { ar: 'منخفض', en: 'Low', ...TONE.teal },
};
export const PRICE_SOURCE = {
  vendor: { ar: 'قائمة أسعار المورد', en: 'Supplier price list', ...TONE.ok }, supplier_link: { ar: 'ربط المنتج بالمورد', en: 'Product–supplier link', ...TONE.teal },
  last_purchase: { ar: 'آخر سعر شراء', en: 'Last purchase price', ...TONE.blue }, cost: { ar: 'سعر الشراء على المنتج', en: 'Product purchase price', ...TONE.neutral },
  none: { ar: 'لا سعر', en: 'No price', ...TONE.bad },
};
export const LEAD_SOURCE = { vendor: { ar: 'مدة توريد المورد', en: "Supplier's lead time" }, default: { ar: 'المدة الافتراضية للتشغيل', en: "The run's default lead time" } };
export const ORDERPOINT = {
  missing: { ar: 'لا حد طلب', en: 'No reorder rule', ...TONE.warn }, matches: { ar: 'يطابق الاقتراح', en: 'Matches', ...TONE.ok }, differs: { ar: 'يختلف عن الاقتراح', en: 'Differs', ...TONE.blue },
};
export const PATTERN_STATE = {
  active: { ar: 'نشط', en: 'Active', ...TONE.ok }, overdue: { ar: 'متأخر', en: 'Overdue', ...TONE.warn },
  lapsed: { ar: 'منقطع', en: 'Lapsed', ...TONE.bad }, insufficient: { ar: 'طلبات غير كافية', en: 'Too few orders', ...TONE.neutral },
};
export const BAND = { high: { ar: 'ثقة عالية', en: 'High', ...TONE.ok }, medium: { ar: 'متوسطة', en: 'Medium', ...TONE.warn }, low: { ar: 'منخفضة', en: 'Low', ...TONE.bad }, none: { ar: 'بلا', en: 'None', ...TONE.neutral } };
export const ACTUAL = { pending: { ar: 'بانتظار', en: 'Pending', ...TONE.neutral }, ordered: { ar: 'طُلب فعلًا', en: 'Ordered', ...TONE.ok }, missed: { ar: 'لم يُطلب', en: 'Missed', ...TONE.bad } };
export const BUDGET_STATUS = { draft: { ar: 'غير موزّع', en: 'Not allocated', ...TONE.neutral }, allocated: { ar: 'موزّع', en: 'Allocated', ...TONE.ok } };
export const ALLOC_STATUS = { funded: { ar: 'مموّل', en: 'Funded', ...TONE.ok }, partial: { ar: 'جزئي', en: 'Partial', ...TONE.warn }, deferred: { ar: 'مؤجّل', en: 'Deferred', ...TONE.bad } };
export const PLAN_STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral }, to_approve: { ar: 'بانتظار الاعتماد', en: 'To approve', ...TONE.warn },
  approved: { ar: 'معتمدة · قيد التنفيذ', en: 'Approved · in progress', ...TONE.blue }, done: { ar: 'مغلقة', en: 'Done', ...TONE.ok }, cancel: { ar: 'ملغاة', en: 'Cancelled', ...TONE.bad },
};
export const LINE_STATUS = {
  planned: { ar: 'مخطط', en: 'Planned', ...TONE.neutral }, rfq: { ar: 'طلب شراء', en: 'Requested', ...TONE.blue }, confirmed: { ar: 'أمر شراء', en: 'Ordered', ...TONE.violet },
  partial: { ar: 'استلام جزئي', en: 'Partly received', ...TONE.warn }, received: { ar: 'مستلم', en: 'Received', ...TONE.ok }, cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.bad },
};
export const GROUPING = { date: { ar: 'لكل تاريخ طلب', en: 'Per order date' }, week: { ar: 'لكل أسبوع', en: 'Per week' }, plan: { ar: 'للخطة كلها', en: 'Whole plan' } };
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const ARCHIVED_CHIP = { ar: 'مؤرشف', en: 'Archived', ...TONE.neutral };
export const OWNED_HELP = { ar: 'يُدار من أودو: هذا السجل يُعرض هنا للقراءة فقط ويُعدَّل هناك إلى أن يُسلَّم القسم.', en: 'Managed in Odoo: this record is read-only here and is changed there until the section is handed over.' };

/** Budget weights (BudgetsService / Budget::WEIGHTS) in the order the allocator scores them. */
export const WEIGHTS = [
  { k: 'urgency', l: { ar: 'الإلحاح (قرب تاريخ الطلب)', en: 'Urgency (order date close)' } },
  { k: 'stockout', l: { ar: 'خطر نفاد المخزون', en: 'Stock-out risk' } },
  { k: 'criticality', l: { ar: 'أهمية المنتج', en: 'Product criticality' } },
  { k: 'velocity', l: { ar: 'سرعة الحركة', en: 'Velocity' } },
  { k: 'confidence', l: { ar: 'ثقة التوقع', en: 'Forecast confidence' } },
  { k: 'customer', l: { ar: 'أهمية العملاء', en: 'Customer weight' } },
  { k: 'leadTime', l: { ar: 'طول مدة التوريد', en: 'Lead time' } },
];

/** Why a line of history was left out of the run (diagnostics.skipped keys of HistoryCollector). */
export function skipLabel(key) {
  const k = String(key || '');
  if (k.startsWith('odooWarehouseNotMapped:')) {
    const id = k.split(':')[1];
    return { ar: `مستودع أودو #${id} غير مربوط بمستودع OPS (من الإعدادات)`, en: `Odoo warehouse #${id} is not mapped to an OPS warehouse (see Settings)` };
  }
  return own(SKIP, k) || { ar: k, en: k };
}
const SKIP = {
  odooProductNotLinked: { ar: 'منتج أودو غير مرتبط بمنتج في OPS', en: 'Odoo product not linked to an OPS product' },
  productOutsideRun: { ar: 'منتج خارج تحديد التشغيل', en: 'Product outside the run' },
  otherWarehouse: { ar: 'مستودع غير مختار في التشغيل', en: 'Warehouse not in the run' },
  customerExcluded: { ar: 'عميل مستثنى', en: 'Excluded customer' },
  customerNotSelected: { ar: 'عميل غير مختار (تصفية العملاء)', en: 'Customer not selected (customer filter)' },
  unit: { ar: 'وحدة قياس لا تتحول إلى وحدة المنتج', en: 'Unit that cannot be converted to the product unit' },
};
export const READ_ORIGIN = { ops: { ar: 'سطور من OPS', en: 'Lines from OPS' }, odoo: { ar: 'سطور من أودو', en: 'Lines from Odoo' } };

// ---------------------------------------------------------------- formats
export const SAR = { ar: 'ر.س', en: 'SAR' };
export const money = (v) => (v == null ? '—' : `${fmtMoney(num(v))} ${bi(SAR)}`);
export const qty = (v, d = 0) => (v == null || v === '' ? '—' : fmtNum(num(v), Number.isInteger(num(v)) ? 0 : Math.max(d, 2)));
export const pct = (v) => (v == null ? '—' : `${Number(num(v).toFixed(1))}%`);
export const fmtDay = (d) => (d ? fmtDateOnly(String(d).slice(0, 10).replace(/-/g, '/')) : '—');
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || o.name || '—' : '—');
export const label = (map, k) => bi(own(map, k) || { ar: k ?? '—', en: k ?? '—' });
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
export const options = (map) => Object.entries(map).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
export const yesNo = (v) => bi(v ? { ar: 'نعم', en: 'Yes' } : { ar: 'لا', en: 'No' });

// ---------------------------------------------------------------- query-string state
/**
 * Everything the page shows is in the query string of /planning:
 *   tab=runs|plans|settings
 *   runs list:  q · status · method · active=yes|no|all · origin · sort · order · page
 *   run=<id> opens a run; rtab=items|requirements|patterns|budgets; its lists use prefixed keys
 *     items i*: iq istatus iwh ibuy ifb iop icrit iprice isort iorder ipage   requirements r*: rq rwh rcrit rlate rsort rorder rpage
 *     patterns p*: pq pstate pband psort porder ppage · events e*: eactual epage
 *   item=<id> opens an item of the open run; budget=<id> a budget scenario (a* = its allocations: astatus amonth apage)
 *   plans list: pl_q · pl_status · pl_page;   plan=<id> opens a plan
 *   settings: set=maps|product|customer · sq · scrit · spage
 */
export function usePlanningNav() {
  const qs = useQueryState();
  const { get } = qs;
  const tab = computed(() => (['runs', 'plans', 'settings'].includes(get('tab')) ? get('tab') : 'runs'));
  function patch(changes) {
    const next = { ...qs.route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '' || v === false) delete next[k]; else next[k] = String(v); }
    return qs.replace(next);
  }
  const pageOf = (key) => Math.max(1, Number(get(key)) || 1);
  /** A filter of a list: changes the key and sends the list back to its first page. */
  const setFilter = (key, value, pageKey = 'page') => patch({ [key]: value, [pageKey]: null });
  const sortOf = (sortKey, orderKey, allowed) => (allowed.includes(get(sortKey)) ? { key: get(sortKey), order: get(orderKey) === 'asc' ? 'asc' : 'desc' } : null);
  /** Opens a tab with nothing of the previous one (filters, open records). */
  const go = (to) => qs.replace(to && to !== 'runs' ? { tab: to } : {});
  return { get, tab, patch, go, pageOf, setFilter, sortOf };
}

/** A search box bound to a key of the query string (350 ms after the last key, at once on Enter). */
export function useSearchParam(nav, key, pageKey = 'page') {
  let applied = nav.get(key);
  const text = ref(applied);
  let timer = null;
  function apply() {
    clearTimeout(timer);
    const v = String(text.value ?? '').trim();
    if (v === applied) return;
    applied = v;
    nav.setFilter(key, v, pageKey);
  }
  function type(v) { text.value = v; clearTimeout(timer); timer = setTimeout(apply, 350); }
  watch(() => nav.get(key), (v) => { if (v !== applied) { applied = v; text.value = v; } });
  onBeforeUnmount(() => clearTimeout(timer));
  return { text, type, apply };
}

// ---------------------------------------------------------------- lookups
/** GET /planning/lookups — warehouses, methods, statuses, following, odooWarehouses (unmapped count), can. */
export function usePlanningLookups() {
  const q = useGet('/planning/lookups', undefined, { staleTime: 30_000 });
  const data = computed(() => q.data.value || null);
  const warehouses = computed(() => data.value?.warehouses || []);
  return {
    query: q, data, warehouses,
    following: computed(() => !!data.value?.following),
    unmapped: computed(() => Number(data.value?.odooWarehouses || 0)),
    can: computed(() => data.value?.can || {}),
    warehouseOptions: computed(() => warehouses.value.map((w) => ({ v: String(w.id), l: { ar: `${w.code} · ${w.nameAr || w.nameEn || ''}`, en: `${w.code} · ${w.nameEn || w.nameAr || ''}` } }))),
    whCode: (id) => warehouses.value.find((w) => String(w.id) === String(id))?.code || '—',
  };
}

// ---------------------------------------------------------------- files
/**
 * GET a file of the API with the session's bearer token (a bare link cannot carry it) and hand it to the browser as a
 * download — the way the other pages save their exports. Throws ApiError on a refusal (403 without report.export …).
 */
export async function downloadFromApi(path, params, fallbackName) {
  const url = `${API_BASE}${path}${buildQuery(params)}`;
  const call = () => fetch(url, { headers: { Authorization: `Bearer ${tokens.access || ''}`, 'Accept-Language': getLang() }, credentials: 'same-origin', cache: 'no-store' })
    .catch(() => { throw new ApiError({ status: 0, category: 'NETWORK', code: 'NETWORK', message: 'تعذر التنزيل: لا اتصال بالخادم', messageEn: 'Download failed: the server cannot be reached' }); });
  let res = await call();
  if (res.status === 401 && (await refreshAccess())) res = await call();
  if (!res.ok) { const p = await res.json().catch(() => ({})); throw new ApiError({ status: res.status, ...(p && typeof p === 'object' ? p : {}) }); }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') || '';
  const star = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(cd);
  let file = '';
  if (star) { try { file = decodeURIComponent(star[1].trim()); } catch { file = ''; } }
  if (!file) file = /filename\s*=\s*"?([^";]+)"?/i.exec(cd)?.[1]?.trim() || fallbackName;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = file;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  return file;
}
