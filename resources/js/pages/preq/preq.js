// «طلبات الأصناف الجديدة» — what the views of the new-product requests section share: label maps (with chip colours),
// the query-string state of the page, small formats, the lookups, and the picture helpers.
//
// API (routes/api/product_requests.php — every call needs page.preq):
//   GET  /product-requests/summary      { byStatus{…}, total, waiting, oldestWaitingAt, oldestWaitingDays, mine{draft,submitted,in_review,returned,open}, approvedThisMonth, fromOdoo, follows, can{create,review} }
//   GET  /product-requests/lookups      { follows, can, selfApproval, statuses[], sourceStates[{key,ar,en}], storageClasses[], requesters[{key,nameAr,nameEn,count}], limits{pictureBytes,pictures} }
//   GET  /product-requests/board?per&q&category&supplier&requester&origin…      { columns[{status,count,oldestAt,items[Card],more}], per, ageing[{key,count}], waiting }
//   GET  /product-requests?scope=all|mine|review&status&category&supplier&customer&requester&origin&type&storage&hasProduct&from&to&q&sort&order&page&pageSize
//   GET  /product-requests/duplicates?nameAr&nameEn&packText&barcode&sku[&except=<id>] | ?request=<id>[&sku]   → Found
//   GET  /product-requests/estimate?nameAr&nameEn&packText&category     { weightKg, lengthCm, widthCm, heightCm, storageClass, uomCode, basis: name|default }
//   GET  /product-requests/next-sku (preq.review)                        { sku }
//   GET  /product-requests/{id|number}  ·  POST /product-requests (preq.create)  ·  PATCH /product-requests/{id}
//   POST /product-requests/{id}/submit { confirmDuplicate? } | cancel { reason? } | comments { text }                       (its author / a reviewer)
//   POST /product-requests/{id}/start-review | send-back { reason } | approve { sku?, confirmDuplicate?, note?, changes? } | reject { reason, existingProduct? } | link-product { product }   (preq.review)
//   POST /product-requests/{id}/pictures { fileName?, base64 }  ·  GET …/pictures/{pid} (the bytes)  ·  POST …/pictures/{pid}/remove { reason? }
//
// Shapes (camelCase; prices arrive as strings with 2–4 decimals):
//   Person  { id | null, nameAr, nameEn, username | null, external }          external = a person of Odoo kept by name (no OPS user)
//   Ref     { id, code, nameAr, nameEn }                                       a category, a supplier, a unit, a customer of OPS
//   Card    { id, number, sourceNumber, requestType: new|existing, status, statusAt, ageDays, ageHours, nameAr, nameEn, brand, packText, category: Ref|null, categoryName,
//             supplier: Ref|null, supplierName, costPrice, salePrice, currency, barcode, proposedSku, storageClass, requestedBy: Person|null, customer: Ref|null, customerName,
//             product: { id, sku, nameAr, nameEn, active }|null, productSku, productLink: created|restored|existing|manual|null, picture: Picture|null, pictureCount,
//             returnsCount, mine, sourceSystem: 'odoo'|null, sourceModel, externalRef, sourceState: { key, ar, en }|null, owned, createdAt, submittedAt, decidedAt }
//   detail adds: uom: Ref|null, uomName, packUom, packQty, shelfLifeDays, tracksExpiry, weightKg, lengthCm, widthCm, heightCm, reason, reviewer: Person|null, decidedBy: Person|null,
//             reviewStartedAt, returnReason, rejectReason, cancelReason, pictures[Picture + canRemove], events[{ id, kind, fromStatus, toStatus, by, byId, note, meta, origin, at }],
//             sourceMeta, sourceUpdatedAt, sourceDeletedAt, sourceProductRef, archive: { kind, odooId }|null,
//             can: { edit, submit, startReview, sendBack, approve, reject, cancel, comment, addPicture, linkProduct }, selfApprovalBlocked, missing[], estimate|null
//   Picture { id, fileName, mime, size, origin: ops|odoo, available, url (a path under /api), uploadedBy, createdAt }
//   Found   { barcode: { value, product|null, requests[] }, sku: { value, product|null }, names: { products[{ id, sku, nameAr, nameEn, active, match: exact|pack|similar, score }],
//             requests[{ id, number, nameAr, nameEn, packText, status, match }], rejected[{ …, rejectReason, decidedAt }] }, blocked, needsConfirmation, clean }
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { API_BASE, ApiError, api, refreshAccess, tokens, useGet } from '@/api/client';
import { bi, fmtDate, fmtNum, lang, t } from '@/i18n';
import { confirm } from '@/stores/ui';

export const API = '/product-requests';
const NONE = [];
/** `map[key]` for a key that comes from the server: only the map's own entries count. */
export const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses. */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};

export const STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  submitted: { ar: 'بانتظار المراجعة', en: 'Waiting for review', ...TONE.warn },
  in_review: { ar: 'قيد المراجعة', en: 'In review', ...TONE.blue },
  returned: { ar: 'أُعيد للتصحيح', en: 'Returned for correction', ...TONE.violet },
  approved: { ar: 'معتمد ✓', en: 'Approved ✓', ...TONE.ok },
  rejected: { ar: 'مرفوض', en: 'Rejected', ...TONE.bad },
  cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.off },
};
export const STATUSES = Object.keys(STATUS);
/** The status filter of the lists: one status, or a group the API knows by one word. */
export const STATUS_FILTERS = {
  open: { ar: 'المفتوحة', en: 'Open' }, waiting: { ar: 'عند المراجعين', en: 'With the reviewers' },
  draft: STATUS.draft, returned: STATUS.returned, approved: { ar: 'المعتمدة', en: 'Approved' }, rejected: { ar: 'المرفوضة', en: 'Rejected' }, cancelled: { ar: 'الملغاة', en: 'Cancelled' },
};
/** What the column of the board says above its cards. */
export const BOARD_COLUMNS = {
  submitted: { ar: 'بانتظار مراجع', en: 'Waiting for a reviewer', help: { ar: 'أُرسلت ولم يأخذها أحد بعد', en: 'Sent, and nobody has taken them yet' }, ...TONE.warn },
  in_review: { ar: 'قيد المراجعة', en: 'In review', help: { ar: 'أخذها مراجع وينتظر قراره', en: 'Taken by a reviewer, waiting for his decision' }, ...TONE.blue },
  returned: { ar: 'عند أصحابها للتصحيح', en: 'Back with their authors', help: { ar: 'أُعيدت بملاحظة وتنتظر التصحيح', en: 'Sent back with a note, waiting to be corrected' }, ...TONE.violet },
};
/** How long the reviewers' queue has been waiting (`board.ageing[].key`). */
export const AGEING = {
  day: { ar: 'حتى يوم', en: 'Up to a day', ...TONE.ok },
  days3: { ar: '١ – ٣ أيام', en: '1 – 3 days', ...TONE.blue },
  week: { ar: '٣ – ٧ أيام', en: '3 – 7 days', ...TONE.warn },
  older: { ar: 'أكثر من أسبوع', en: 'More than a week', ...TONE.bad },
};

export const STORAGE = {
  ambient: { ar: 'عادي', en: 'Ambient', fg: '#55506a', bg: '#F1EFF6' },
  chilled: { ar: 'مبرد +4°', en: 'Chilled +4°', fg: '#3C79F5', bg: '#e8effe' },
  frozen: { ar: 'مجمد −18°', en: 'Frozen −18°', fg: '#0d5866', bg: '#d9f4f9' },
};
export const STORAGE_OPTIONS = Object.entries(STORAGE).map(([v, l]) => [v, l]);

export const SOURCE_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
/** Where a request came from (`origin=` of the lists). */
export const ORIGINS = {
  ops: { ar: 'رُفعت هنا', en: 'Raised here' },
  odoo_staff: { ar: 'أودو — طلبات الموظفين', en: 'Odoo — staff requests' },
  odoo_portal: { ar: 'أودو — بوابة العملاء', en: 'Odoo — customer portal' },
};
export const EXISTING_CHIP = { ar: 'صنف موجود', en: 'Existing product', ...TONE.blue };
export const TYPES = { new: { ar: 'صنف جديد', en: 'New product' }, existing: { ar: 'طلب صنف موجود في الكتالوج', en: 'A product the catalogue has' } };
/** How the product of a request got linked to it. */
export const LINKS = {
  created: { ar: 'أنشأه اعتماد هذا الطلب', en: 'Created by the approval of this request' },
  restored: { ar: 'الصنف الذي أنشأه الطلب في أودو', en: 'The product this request created in Odoo' },
  existing: { ar: 'الصنف الموجود في الكتالوج', en: 'The product the catalogue already has' },
  manual: { ar: 'ربطه مراجع يدويًا', en: 'Linked by a reviewer' },
};
/** How close a name that was found is to the one typed. */
export const MATCHES = {
  exact: { ar: 'الاسم نفسه', en: 'Same name', ...TONE.warn },
  pack: { ar: 'الاسم نفسه بعبوة أخرى', en: 'Same name, another pack', ...TONE.blue },
  similar: { ar: 'اسم قريب', en: 'Similar name', ...TONE.neutral },
};

/** The fields of the form, as the history names them (`events[].meta.changes[].field`) and as the server names a missing one. */
export const FIELDS = {
  nameAr: { ar: 'الاسم (عربي)', en: 'Name (Arabic)' }, nameEn: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, brand: { ar: 'العلامة التجارية', en: 'Brand' },
  category: { ar: 'التصنيف', en: 'Category' }, categoryName: { ar: 'التصنيف (كما كُتب)', en: 'Category (as written)' }, supplier: { ar: 'المورد', en: 'Supplier' },
  supplierName: { ar: 'المورد (كما كُتب)', en: 'Supplier (as written)' }, uom: { ar: 'الوحدة', en: 'Unit' }, uomName: { ar: 'الوحدة (كما كُتبت)', en: 'Unit (as written)' },
  packUom: { ar: 'وحدة العبوة', en: 'Pack unit' }, packText: { ar: 'العبوة', en: 'Pack' }, packQty: { ar: 'وحدات العبوة', en: 'Units per pack' },
  costPrice: { ar: 'سعر التكلفة', en: 'Cost price' }, salePrice: { ar: 'سعر البيع', en: 'Selling price' }, barcode: { ar: 'الباركود', en: 'Barcode' },
  proposedSku: { ar: 'رمز الصنف المقترح', en: 'Proposed code' }, storageClass: { ar: 'شرط التخزين', en: 'Storage' }, shelfLifeDays: { ar: 'مدة الصلاحية (يوم)', en: 'Shelf life (days)' },
  tracksExpiry: { ar: 'تتبع الصلاحية', en: 'Expiry tracking' }, weightKg: { ar: 'الوزن كجم', en: 'Weight kg' }, lengthCm: { ar: 'الطول سم', en: 'Length cm' },
  widthCm: { ar: 'العرض سم', en: 'Width cm' }, heightCm: { ar: 'الارتفاع سم', en: 'Height cm' }, reason: { ar: 'سبب الطلب', en: 'Reason' },
  customer: { ar: 'العميل', en: 'Customer' }, customerName: { ar: 'العميل (كما كُتب)', en: 'Customer (as written)' },
};
export const fieldLabel = (key) => bi(own(FIELDS, key) || key);

/** What happened to a request (`events[].kind`), in words, with the colour of its dot. */
export const EVENTS = {
  created: { ar: 'رُفع الطلب', en: 'Request raised', color: '#7d7990' },
  edited: { ar: 'عدّله صاحبه', en: 'Edited by its author', color: '#7d7990' },
  submitted: { ar: 'أُرسل للمراجعة', en: 'Sent for review', color: '#b26a16' },
  resubmitted: { ar: 'صُحّح وأُعيد إرساله', en: 'Corrected and sent again', color: '#b26a16' },
  review_started: { ar: 'بدأت المراجعة', en: 'Review started', color: '#3C79F5' },
  returned: { ar: 'أُعيد للتصحيح', en: 'Sent back for correction', color: '#654e92' },
  corrected: { ar: 'صحّحه المراجع', en: 'Corrected by the reviewer', color: '#3C79F5' },
  approved: { ar: 'اعتُمد', en: 'Approved', color: '#1d7a3e' },
  rejected: { ar: 'رُفض', en: 'Rejected', color: '#b23b3b' },
  cancelled: { ar: 'أُلغي', en: 'Cancelled', color: '#7d7990' },
  comment: { ar: 'ملاحظة', en: 'Comment', color: '#1BC4DB' },
  picture_added: { ar: 'أُضيفت صورة', en: 'Picture added', color: '#0d7f93' },
  picture_removed: { ar: 'أُزيلت صورة', en: 'Picture removed', color: '#b26a16' },
  product_linked: { ar: 'رُبط بصنف من الكتالوج', en: 'Linked to a catalogue product', color: '#1d7a3e' },
  source_step: { ar: 'خطوة اعتماد في أودو', en: 'An approval step in Odoo', color: '#654e92' },
};
/** Who approved each of Odoo's three steps (`sourceMeta.approvals`). */
export const ODOO_STEPS = {
  supplyChainManager: { ar: 'مدير الإمداد', en: 'Supply chain manager' }, salesManager: { ar: 'مدير المبيعات', en: 'Sales manager' }, systemAdmin: { ar: 'مدير النظام', en: 'System administrator' },
};
/** Odoo's approvals as [{ step, name }] in the order of its flow (the stored map keeps no order). */
export function odooApprovals(map) {
  if (!map || typeof map !== 'object') return NONE;
  const keys = [...Object.keys(ODOO_STEPS).filter((k) => map[k]), ...Object.keys(map).filter((k) => !own(ODOO_STEPS, k) && map[k])];
  return keys.map((k) => ({ step: bi(own(ODOO_STEPS, k) || k), name: String(map[k]) }));
}
const shown = (v) => (v == null || v === '' ? '—' : v === true ? t('نعم', 'yes') : v === false ? t('لا', 'no') : String(v));
/**
 * The lines that follow the label of a history entry: which fields changed, which product, which file.
 * `stateLabel(key)` words one of Odoo's own states.
 */
export function eventLines(e, stateLabel = (k) => k) {
  const m = e.meta || {};
  const out = [];
  if (Array.isArray(m.changes)) m.changes.forEach((c) => out.push(`${fieldLabel(c.field)}: ${shown(c.old)} ← ${shown(c.new)}`));
  if (e.kind === 'approved' && m.sku) out.push(t(`الصنف في الكتالوج: ${m.sku}`, `Product in the catalogue: ${m.sku}`));
  if (e.kind === 'approved' && m.selfApproved) out.push(t('اعتمده من رفعه (السياسة تسمح بذلك)', 'Approved by the person who raised it (the policy allows it)'));
  if (e.kind === 'approved') odooApprovals(m.approvals).forEach((a) => out.push(`${a.step}: ${a.name}`));
  if (Array.isArray(m.confirmedDuplicateOf) && m.confirmedDuplicateOf.length) out.push(t(`أُكّد أنه صنف آخر غير: ${m.confirmedDuplicateOf.join('، ')}`, `Confirmed to be another product than: ${m.confirmedDuplicateOf.join(', ')}`));
  if (e.kind === 'rejected' && m.existingProduct) out.push(t(`الصنف الموجود: ${m.existingProduct}`, `Existing product: ${m.existingProduct}`));
  if ((e.kind === 'picture_added' || e.kind === 'picture_removed') && m.fileName) out.push(String(m.fileName));
  if (e.kind === 'product_linked') out.push([m.from, m.to].filter(Boolean).join(' ← '));
  if (e.kind === 'source_step') out.push([m.from, m.to].filter(Boolean).map(stateLabel).join(' ← '));
  return out.filter(Boolean);
}

// ---------------------------------------------------------------- formats
/** A person as the API sends him. */
export const personName = (p) => (p ? (lang.value === 'ar' ? p.nameAr || p.nameEn : p.nameEn || p.nameAr) || '—' : '—');
/** A category / supplier / unit / customer: the OPS record when it is one, otherwise the name as it was written. */
export const refName = (rec, fallback = null) => (rec ? (lang.value === 'ar' ? rec.nameAr || rec.nameEn : rec.nameEn || rec.nameAr) || rec.code || '' : fallback || '');
/** The name of the product in the reader's language, and the other one under it when there is one. */
export const productName = (r) => (lang.value === 'ar' ? r.nameAr || r.nameEn : r.nameEn || r.nameAr) || '';
export const otherName = (r) => { const o = lang.value === 'ar' ? r.nameEn : r.nameAr; return o && o !== productName(r) ? o : ''; };
export const fmtAt = (v) => (v ? fmtDate(v) : '—');
/** A price with the decimals it has (two to four): "12.50", "6.3043". */
export const fmtPrice = (v, currency = 'SAR') => {
  if (v == null || v === '' || Number.isNaN(Number(v))) return '—';
  return `${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} ${currency === 'SAR' ? t('ر.س', 'SAR') : currency}`;
};
/** A measure with the decimals it has (up to two). */
export const fmtMeasure = (v) => (v == null || v === '' || Number.isNaN(Number(v)) ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 }));
export const sizeText = (n) => (n == null ? '' : n >= 1024 * 1024 ? `${fmtNum(n / 1024 / 1024, 1)} MB` : `${fmtNum(Math.max(1, Math.round(n / 1024)))} KB`);
/** "L × W × H" in cm, or '' when a dimension is missing. */
export const dimsText = (r) => (r.lengthCm && r.widthCm && r.heightCm ? `${fmtMeasure(r.lengthCm)} × ${fmtMeasure(r.widthCm)} × ${fmtMeasure(r.heightCm)}` : '');

/** How long a request has stood where it stands: «الآن», «منذ 5 س», «منذ 3 أيام» ('' for a closed one). */
export function ageText(r) {
  if (r.ageHours == null) return '';
  if (r.ageHours < 1) return t('الآن', 'just now');
  if (r.ageHours < 24) return t(`منذ ${fmtNum(r.ageHours)} س`, `${fmtNum(r.ageHours)}h`);
  const d = r.ageDays;
  return t(d === 1 ? 'منذ يوم' : d === 2 ? 'منذ يومين' : d <= 10 ? `منذ ${fmtNum(d)} أيام` : `منذ ${fmtNum(d)} يومًا`, `${fmtNum(d)}d`);
}
/** The colour of an age: fresh, a day, three days, a week. */
export const ageTone = (days) => (days == null ? TONE.neutral : days >= 7 ? TONE.bad : days >= 3 ? TONE.warn : days >= 1 ? TONE.blue : TONE.ok);

// ---------------------------------------------------------------- query-string state
const TABS = ['board', 'all', 'mine'];
const SORTS = ['createdAt', 'number', 'nameAr', 'status', 'statusAt', 'submittedAt', 'decidedAt', 'costPrice', 'salePrice', 'requestedBy'];
const DAY = /^\d{4}-\d{2}-\d{2}$/;
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));

/**
 * Everything the section shows lives in the query string of /preq, so every view is a link:
 *   tab=board|all|mine · page · q · status · category · supplier · requester · origin · type · storage · product=1|0 · from · to · sort · order   (the filters of a list)
 *   open=<number> opens a request · new=1 opens the form of a new one · edit=1 (beside open=) edits the open request
 */
export function usePreqNav() {
  const route = useRoute();
  const router = useRouter();
  const raw = (k) => { const v = route.query[k]; return Array.isArray(v) ? v[0] : v; };
  const get = (k) => raw(k) || '';
  const replace = (query) => router.replace({ path: route.path, query });
  /** Changes some keys and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return replace(next);
  }
  const tabParam = computed(() => (TABS.includes(get('tab')) ? get('tab') : ''));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  const sort = computed(() => (SORTS.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : 'desc' } : null));
  const requestOpen = computed(() => get('open') || null);
  const creating = computed(() => get('new') === '1');
  const editing = computed(() => get('edit') === '1' && !!get('open'));
  /** The filters of the query string as the API takes them (an unknown value is no filter). */
  const filters = computed(() => ({
    q: get('q'), status: own(STATUS_FILTERS, get('status')) || own(STATUS, get('status')) ? get('status') : '', category: get('category'), supplier: get('supplier'), requester: get('requester'),
    origin: own(ORIGINS, get('origin')) || get('origin') === 'odoo' ? get('origin') : '', type: own(TYPES, get('type')) ? get('type') : '', storage: own(STORAGE, get('storage')) ? get('storage') : '',
    hasProduct: ['0', '1'].includes(get('product')) ? get('product') : '', from: DAY.test(get('from')) ? get('from') : '', to: DAY.test(get('to')) ? get('to') : '',
  }));
  const filtered = computed(() => Object.values(filters.value).some(Boolean) || !!sort.value);
  /** Another tab is another list: its filters, page and open drawers start again. */
  const go = (tab, params = {}) => replace({ tab, ...pruned(params) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const setSort = (s) => patch({ sort: s ? s.key : null, order: s ? s.order : null, page: null });
  const openRequest = (number) => patch({ open: number, new: null, edit: null });
  const closeRequest = () => patch({ open: null, edit: null });
  const openNew = () => patch({ new: '1', edit: null, open: null });
  const openEdit = () => patch({ edit: '1', new: null });
  const closeForm = () => patch({ new: null, edit: null });
  return { route, get, patch, go, tabParam, page, sort, filters, filtered, requestOpen, creating, editing, setFilter, setPage, setSort, openRequest, closeRequest, openNew, openEdit, closeForm };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
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

// ---------------------------------------------------------------- lookups
/** What the section itself knows: who may do what, the people who raised requests, the limits. One request, cached two minutes. */
export function useSection() {
  const q = useGet(`${API}/lookups`, undefined, { staleTime: 120_000 });
  const data = computed(() => q.data.value || null);
  const follows = computed(() => !!data.value?.follows);
  const sourceStates = computed(() => Object.fromEntries((data.value?.sourceStates || NONE).map((s) => [s.key, { ar: s.ar, en: s.en }])));
  /** One of Odoo's own states in words. */
  const stateLabel = (key) => bi(own(sourceStates.value, key) || key);
  const requesterOptions = computed(() => (data.value?.requesters || NONE).map((p) => ({ v: p.key, l: `${personName(p)} (${fmtNum(p.count)})` })));
  return { query: q, data, follows, stateLabel, requesterOptions, limits: computed(() => data.value?.limits || { pictureBytes: 4 * 1024 * 1024, pictures: 8 }) };
}

/** The master data the forms and the filters pick from (categories, units, suppliers, customers) — the shared lookups, cached a minute. */
export function useMaster(enabled = () => true) {
  const q = useGet('/master/lookups', undefined, { enabled, staleTime: 60_000 });
  const lk = computed(() => q.data.value || null);
  const named = (x) => (lang.value === 'ar' ? x.nameAr || x.nameEn : x.nameEn || x.nameAr) || x.code;
  const categoryOptions = computed(() => (lk.value?.categories || NONE).filter((c) => c.active).map((c) => ({ v: c.id, l: lang.value === 'ar' ? c.pathAr || named(c) : named(c), sub: c.code })));
  const uomOptions = computed(() => (lk.value?.uoms || NONE).map((u) => ({ v: u.id, l: `${named(u)} (${u.code})` })));
  const supplierOptions = computed(() => (lk.value?.suppliers || NONE).map((s) => ({ v: s.id, l: named(s), sub: s.code })));
  const customerOptions = computed(() => (lk.value?.customers || NONE).map((c) => ({ v: c.id, l: named(c), sub: c.code })));
  return { query: q, lk, categoryOptions, uomOptions, supplierOptions, customerOptions };
}

// ---------------------------------------------------------------- the same name twice
/** The products and requests a PREQ_DUPLICATE_NAME refusal names (`details`), as one line for the question. */
export function duplicateNames(error) {
  const d = error && error.details && typeof error.details === 'object' ? error.details : {};
  const products = (Array.isArray(d.products) ? d.products : NONE).map((p) => `${p.sku} — ${p.nameAr}`);
  const requests = (Array.isArray(d.requests) ? d.requests : NONE).map((r) => `${r.number} — ${r.nameAr}`);
  return [...products, ...requests].slice(0, 3).join(' · ');
}
/**
 * The question the server asks through PREQ_DUPLICATE_NAME (409): a product or a running request of the same name
 * exists — is this one another product? Resolves true when the person says yes. `verb`: what goes on after the yes.
 */
export const confirmAnotherProduct = (error, verb = { ar: 'أرسل', en: 'send' }) => confirm({
  title: { ar: 'يوجد صنف أو طلب بالاسم نفسه', en: 'A product or a request of the same name exists' },
  sub: { ar: `${duplicateNames(error)} — هل المطلوب صنف آخر فعلًا (عبوة أو نوع مختلف)؟`, en: `${duplicateNames(error)} — is this really another product (another pack or kind)?` },
  okLabel: { ar: `نعم، صنف آخر — ${verb.ar}`, en: `Yes, another product — ${verb.en}` }, cancelLabel: { ar: 'تراجع', en: 'Back' }, tone: 'dark',
});
/**
 * Sends a request to the reviewers (POST …/submit) through `act` (a useAction). When the server asks whether a
 * product of the same name is another product, the person is asked and — on a yes — the request is sent with that
 * confirmation. Resolves the request, or undefined when it was not sent (the reason is in `act.error`, except after a "no").
 */
export async function sendForReview(act, idOrNumber) {
  const path = `${API}/${encodeURIComponent(idOrNumber)}/submit`;
  const options = { success: t('أُرسل الطلب للمراجعة', 'The request was sent for review'), errorToast: false };
  const sent = await act.run(() => api.postIdempotent(path, {}), options);
  if (sent !== undefined) return sent;
  const refusal = act.error.value;
  if (!refusal || refusal.code !== 'PREQ_DUPLICATE_NAME') return undefined;
  act.clearError();
  if (!(await confirmAnotherProduct(refusal))) return undefined;
  return act.run(() => api.postIdempotent(path, { confirmDuplicate: true }), options);
}

// ---------------------------------------------------------------- pictures
/**
 * The bytes of a picture. An <img> cannot send the bearer token, so the file is fetched with it and handed back as a
 * Blob. An expired token is refreshed once.
 */
export async function fetchBlob(path) {
  const call = () => fetch(API_BASE + path, { headers: { Accept: 'application/json', ...(tokens.access ? { Authorization: `Bearer ${tokens.access}` } : {}) }, credentials: 'same-origin' });
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
  return res.blob();
}

/** Pictures on screen, by their API path: fetched once, shared by every place that shows them, let go a while after the last one. */
const held = new Map();
/** Resolves the object URL of a picture and keeps it alive until `dropPicture(path)` is called as many times. */
export function holdPicture(path) {
  let e = held.get(path);
  if (!e) { e = { refs: 0, url: null, promise: null, timer: null }; held.set(path, e); }
  e.refs += 1;
  clearTimeout(e.timer);
  if (!e.promise) {
    e.promise = fetchBlob(path).then((blob) => { e.url = URL.createObjectURL(blob); return e.url; }).catch((err) => { if (held.get(path) === e) held.delete(path); throw err; });
  }
  return e.promise;
}
export function dropPicture(path) {
  const e = held.get(path);
  if (!e) return;
  e.refs -= 1;
  if (e.refs > 0) return;
  // kept two minutes: the drawer of a row that was just closed opens again without a second download
  e.timer = setTimeout(() => { if (e.refs <= 0 && held.get(path) === e) { if (e.url) URL.revokeObjectURL(e.url); held.delete(path); } }, 120_000);
}

const fileError = (ar, en) => new ApiError({ status: 400, category: 'VALIDATION', code: 'ATTACHMENT_INVALID', message: ar, messageEn: en });
const readDataUrl = (file) => new Promise((resolve, reject) => {
  const reader = new FileReader();
  reader.onerror = () => reject(fileError(`تعذّرت قراءة الملف «${file.name}»`, `File "${file.name}" could not be read`));
  reader.onload = () => resolve(String(reader.result));
  reader.readAsDataURL(file);
});
/** Scales a picture down so its longest side is `maxSide` and re-encodes it as JPEG. Resolves the original when it cannot. */
const shrink = (dataUrl, maxSide, quality) => new Promise((resolve) => {
  const img = new Image();
  img.onload = () => {
    try {
      const scale = Math.min(1, maxSide / Math.max(img.naturalWidth || 1, img.naturalHeight || 1));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff'; // JPEG has no transparency
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      const out = canvas.toDataURL('image/jpeg', quality);
      resolve(out && out.startsWith('data:image/jpeg') ? out : dataUrl);
    } catch { resolve(dataUrl); }
  };
  img.onerror = () => resolve(dataUrl);
  img.src = dataUrl;
});
/** Decoded size of a data URL in bytes (what the server will count). */
export const dataUrlBytes = (dataUrl) => Math.floor((String(dataUrl).split(',')[1] || '').length * 3 / 4);
/**
 * A picked image as the API takes it: { fileName, base64 (a data URL), size }. A phone photo of several MB is shrunk
 * in the browser (longest side 1600 px, JPEG) before it is sent; anything that is not an image is refused here.
 */
export async function readPicture(file, maxBytes = 4 * 1024 * 1024) {
  if (!/^image\/(png|jpe?g|webp|gif)$/i.test(file.type || '')) throw fileError(`«${file.name}» ليس صورة (PNG أو JPEG أو WebP)`, `"${file.name}" is not a picture (PNG, JPEG or WebP)`);
  let dataUrl = await readDataUrl(file);
  if (file.size > 350 * 1024) dataUrl = await shrink(dataUrl, 1600, 0.82);
  const size = dataUrlBytes(dataUrl);
  if (size > maxBytes) throw fileError(`الصورة «${file.name}» أكبر من الحد المسموح`, `Picture "${file.name}" is larger than the limit`);
  return { fileName: file.name, base64: dataUrl, size };
}
