// «الاعتمادات» — what the views of the approvals section share: label maps (with chip colours), the query-string
// state of the page, small formats, the lookups of the forms and the file helpers.
//
// API (routes/api/approvals.php — every call needs page.approvals):
//   GET  /approvals/summary                       { waiting, mine: { draft, pending, approved, refused, cancelled, total }, all | null, canRequest, canManage, odooOwns, hasOdoo }
//   GET  /approvals/lookups                       { categories[Category], users[{ id, username, nameAr, nameEn }], fields[], levels[], modes[], paymentMethods[], partnerTypes[], odooOwns }
//   GET  /approvals/partners?type=&q=  ·  /approvals/products?q=           { items[] } — the pickers of the request form
//   GET  /approvals/categories?q&active&source&sort&order&page  ·  GET /approvals/categories/{id|code}  ·  POST / PATCH (approval.manage)
//   GET  /approvals/requests?scope=waiting|mine|involved|all&status&category&source&from&to&requester&amountMin&amountMax&q&sort&order&page&pageSize
//   GET  /approvals/requests/{id|number}  ·  POST /approvals/requests  ·  PATCH /approvals/requests/{id}            (approval.request)
//   POST /approvals/requests/{id}/submit | approve | refuse | withdraw | cancel | reopen | comments | approvers | approvers/{line}/remove
//   POST /approvals/requests/{id}/attachments { fileName, mime, base64 }  ·  GET …/attachments/{id} (the bytes)  ·  POST …/attachments/{id}/remove
//
// Shapes (camelCase; amounts and quantities arrive as strings):
//   Person   { id | null, nameAr, nameEn, username | null, linked }            linked = an OPS user; otherwise a person of Odoo kept by name
//   Category { id, code, nameAr, nameEn, description, active, sort, approvalMode: parallel|sequence, minApprovals, allowSelfApproval,
//              fields: { amount|date|period|partner|reference|quantity|items|location|paymentMethod|document: required|optional|no },
//              approvers[{ id, user: Person, sequence, required, minAmount }], source: 'odoo'|null, owned, sourceMeta, counts?: { requests, pending } }
//   Request  { id, number, title, status: draft|pending|approved|refused|cancelled, category, requester: Person, amount, currency, quantity, date,
//              reference, partner: { type, id, name, code?, nameAr?, nameEn? } | null, approvalMode, minApprovals, progress: { approved, needed, total },
//              approvers[{ id, user: Person, sequence, required, status: new|waiting|pending|approved|refused|cancelled|removed, comment, decidedAt, origin, isMe }],
//              my: { isRequester, lineId, lineStatus, turn }, source: 'odoo'|null, externalRef, owned, createdAt, submittedAt, decidedAt, updatedAt }
//   detail adds: createdBy, periodStart, periodEnd, location, paymentMethod, reason, cancelledAt, cancelledBy, cancelReason, sourceMeta,
//              items[{ id, lineNo, description, quantity, uom, warehouse, product | null }],
//              attachments[{ id, fileName, mime, size, source, available, uploadedBy: Person, createdAt, removed, removedAt, removedBy, removeReason, canRemove }],
//              timeline[{ id, kind, at, by: Person | null, note, meta, source }],
//              can: { edit, submit, approve, refuse, withdraw, cancel, reopen, approvers, comment, attach }, decisionBlock: { code, messageAr, messageEn } | null
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { API_BASE, ApiError, refreshAccess, tokens, useGet } from '@/api/client';
import { bi, fmtDate, fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';

const NONE = [];
/** `map[key]` for a key that comes from the server: only the map's own entries count. */
export const own = (map, key) => (key != null && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined);

// ---------------------------------------------------------------- labels
/** The colour pairs every status chip of the application uses. */
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};

export const REQUEST_STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  pending: { ar: 'بانتظار الاعتماد', en: 'Waiting for approval', ...TONE.warn },
  approved: { ar: 'معتمد ✓', en: 'Approved ✓', ...TONE.ok },
  refused: { ar: 'مرفوض', en: 'Refused', ...TONE.bad },
  cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.off },
};
export const REQUEST_STATUSES = Object.keys(REQUEST_STATUS);

/** The line of one approver. `waiting` / `pending` read differently once the request is closed (see lineLabel). */
export const LINE_STATUS = {
  new: { ar: 'لم يُرسل بعد', en: 'Not sent yet', ...TONE.neutral },
  waiting: { ar: 'ينتظر دوره', en: 'Waits for his turn', ...TONE.neutral },
  pending: { ar: 'مطلوب قراره الآن', en: 'Asked now', ...TONE.warn },
  approved: { ar: 'اعتمد ✓', en: 'Approved ✓', ...TONE.ok },
  refused: { ar: 'رفض', en: 'Refused', ...TONE.bad },
  cancelled: { ar: 'أُلغي الطلب قبل قراره', en: 'Cancelled before he decided', ...TONE.off },
  removed: { ar: 'أُزيل من الطلب', en: 'Taken off the request', ...TONE.off },
};
const LINE_NOT_ASKED = { ar: 'لم يقرّر', en: 'Did not decide', ...TONE.off };
/** The chip of an approver line, given the state of its request: an undecided line of a closed request "did not decide". */
export function lineChip(line, requestStatus) {
  if (['waiting', 'pending'].includes(line.status) && ['approved', 'refused', 'cancelled'].includes(requestStatus)) return LINE_NOT_ASKED;
  return own(LINE_STATUS, line.status) || { ar: line.status, en: line.status, ...TONE.neutral };
}

export const MODE_LABELS = {
  parallel: { ar: 'معًا — يُسأل الجميع في وقت واحد', en: 'Together — everybody is asked at once' },
  sequence: { ar: 'بالتتابع — واحدًا بعد الآخر', en: 'In sequence — one after the other' },
};
export const MODE_SHORT = { parallel: { ar: 'معًا', en: 'Together', ...TONE.blue }, sequence: { ar: 'بالتتابع', en: 'In sequence', ...TONE.violet } };

/** The fields a category may ask a request to carry, in the order the form shows them. */
export const FIELD_LABELS = {
  amount: { ar: 'المبلغ', en: 'Amount' }, date: { ar: 'التاريخ', en: 'Date' }, period: { ar: 'الفترة (من — إلى)', en: 'Period (from — to)' },
  partner: { ar: 'الجهة (عميل / مورد)', en: 'Partner (customer / supplier)' }, reference: { ar: 'المرجع', en: 'Reference' }, quantity: { ar: 'الكمية', en: 'Quantity' },
  items: { ar: 'البنود (أصناف وكميات)', en: 'Lines (items and quantities)' }, location: { ar: 'المكان', en: 'Location' }, paymentMethod: { ar: 'طريقة الدفع', en: 'Payment method' },
  document: { ar: 'مرفق', en: 'Attachment' },
};
export const FIELD_KEYS = Object.keys(FIELD_LABELS);
export const LEVEL_LABELS = { required: { ar: 'إلزامي', en: 'Required', ...TONE.bad }, optional: { ar: 'اختياري', en: 'Optional', ...TONE.blue }, no: { ar: 'غير مستخدم', en: 'Not used', ...TONE.off } };
/** The path the server names for a missing field (APPROVAL_FIELDS_REQUIRED details) → its label. */
const PATH_FIELD = { amount: 'amount', date: 'date', periodStart: 'period', partner: 'partner', reference: 'reference', quantity: 'quantity', items: 'items', location: 'location', paymentMethod: 'paymentMethod', attachments: 'document' };
export const pathLabel = (path) => bi(own(FIELD_LABELS, own(PATH_FIELD, path)) || path);

export const PAYMENT_METHODS = {
  bank: { ar: 'تحويل بنكي', en: 'Bank transfer' }, cash: { ar: 'نقدًا', en: 'Cash' }, cheque: { ar: 'شيك', en: 'Cheque' }, card: { ar: 'بطاقة', en: 'Card' }, other: { ar: 'أخرى', en: 'Other' },
};
export const PAYMENT_OPTIONS = Object.entries(PAYMENT_METHODS).map(([v, l]) => ({ v, l }));
export const PARTNER_TYPES = { customer: { ar: 'عميل', en: 'Customer' }, supplier: { ar: 'مورد', en: 'Supplier' }, other: { ar: 'جهة أخرى', en: 'Other' } };
export const PARTNER_TYPE_OPTIONS = Object.entries(PARTNER_TYPES).map(([v, l]) => ({ v, l }));

export const SOURCE_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const ORIGIN_LABELS = {
  category: { ar: 'من نوع الطلب', en: 'From the category' }, requester: { ar: 'أضافه صاحب الطلب', en: 'Added by the requester' },
  manager: { ar: 'أضافه مدير الاعتمادات', en: 'Added by the manager' }, odoo: { ar: 'من أودو', en: 'From Odoo' },
};

/** What happened to a request (`timeline[].kind`), in words, with the colour of its dot. */
export const EVENT_LABELS = {
  created: { ar: 'أُنشئ الطلب', en: 'Request created', color: '#7d7990' },
  edited: { ar: 'عُدّل الطلب', en: 'Request edited', color: '#7d7990' },
  submitted: { ar: 'أُرسل للاعتماد', en: 'Sent for approval', color: '#3C79F5' },
  approved: { ar: 'اعتمد', en: 'Approved', color: '#1d7a3e' },
  refused: { ar: 'رفض', en: 'Refused', color: '#b23b3b' },
  withdrawn: { ar: 'سحب قراره', en: 'Took the decision back', color: '#b26a16' },
  cancelled: { ar: 'أُلغي الطلب', en: 'Request cancelled', color: '#7d7990' },
  reopened: { ar: 'أُعيد إلى مسودة', en: 'Back to a draft', color: '#654e92' },
  comment: { ar: 'تعليق', en: 'Comment', color: '#1BC4DB' },
  attached: { ar: 'أُضيف مرفق', en: 'File attached', color: '#0d7f93' },
  detached: { ar: 'أُزيل مرفق', en: 'File removed', color: '#b26a16' },
  approver_added: { ar: 'أُضيف معتمد', en: 'Approver added', color: '#654e92' },
  approver_removed: { ar: 'أُزيل معتمد', en: 'Approver removed', color: '#b26a16' },
};
/** The line that follows the label of a timeline entry: which file, which approver, which fields. */
export function eventDetail(e) {
  const m = e.meta || {};
  if (e.kind === 'attached' || e.kind === 'detached') return [m.fileName, m.reason].filter(Boolean).join(' — ');
  if (e.kind === 'approver_added' || e.kind === 'approver_removed') return m.approver || '';
  if (e.kind === 'edited') return (m.fields || []).map((f) => bi(own(SNAPSHOT_LABELS, f) || f)).join('، ');
  if (e.kind === 'created' && m.onBehalfOf) return t(`باسم ${m.onBehalfOf}`, `On behalf of ${m.onBehalfOf}`);
  if (e.kind === 'refused' && m.recordedOn) return t(`كما سجّله أودو على سطر ${m.recordedOn}`, `As Odoo recorded it on the line of ${m.recordedOn}`);
  return '';
}
const SNAPSHOT_LABELS = {
  title: { ar: 'العنوان', en: 'title' }, amount: FIELD_LABELS.amount, quantity: FIELD_LABELS.quantity, date: FIELD_LABELS.date, periodStart: { ar: 'بداية الفترة', en: 'period start' },
  periodEnd: { ar: 'نهاية الفترة', en: 'period end' }, location: FIELD_LABELS.location, reference: FIELD_LABELS.reference, partner: { ar: 'الجهة', en: 'partner' },
  paymentMethod: FIELD_LABELS.paymentMethod, reason: { ar: 'السبب', en: 'reason' }, items: { ar: 'البنود', en: 'lines' }, approvers: { ar: 'المعتمدون', en: 'approvers' },
};

// ---------------------------------------------------------------- formats
/** A person as the API sends him. */
export const personName = (p) => (p ? (lang.value === 'ar' ? p.nameAr || p.nameEn : p.nameEn || p.nameAr) || '—' : '—');
export const catName = (c) => (c ? (lang.value === 'ar' ? c.nameAr || c.nameEn : c.nameEn || c.nameAr) || '' : '');
/** A calendar day (`YYYY-MM-DD`, no time and no zone) as the same day everywhere. */
export const fmtDay = (v) => (v ? fmtDateOnly(typeof v === 'string' ? v.slice(0, 10).replace(/-/g, '/') : v) : '—');
export const fmtAt = (v) => (v ? fmtDate(v) : '—');
export const fmtAmount = (v, currency = 'SAR') => (v == null || v === '' ? '—' : `${fmtMoney(v)} ${currency === 'SAR' ? t('ر.س', 'SAR') : currency}`);
/** A quantity: grouped, with the decimals it has (up to three). */
export const fmtQty = (v) => (v == null || v === '' || Number.isNaN(Number(v)) ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 3 }));
export const sizeText = (n) => (n == null ? '' : n >= 1024 * 1024 ? `${fmtNum(n / 1024 / 1024, 1)} MB` : `${fmtNum(Math.max(1, Math.round(n / 1024)))} KB`);
/** "2 / 3" — approvals given out of the approvals needed. */
export const progressText = (p) => (p ? `${fmtNum(p.approved)} / ${fmtNum(p.needed)}` : '—');
/** The names of the approvers a waiting request is with right now. */
export const waitingOn = (r) => (r.status === 'pending' ? (r.approvers || NONE).filter((a) => a.status === 'pending').map((a) => personName(a.user)) : NONE);

// ---------------------------------------------------------------- query-string state
const TABS = ['waiting', 'mine', 'involved', 'all', 'categories'];
const SORTS = ['number', 'title', 'amount', 'status', 'requester', 'createdAt', 'submittedAt', 'decidedAt'];
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));

/**
 * Everything the section shows lives in the query string of /approvals, so every view is a link:
 *   tab=waiting|mine|involved|all|categories · page · q · status · category · source=odoo|ops · from · to · sort · order   (the filters of a list)
 *   request=<number> opens a request · new=1 (or new=<category code>) opens the form of a new one · edit=1 edits the open draft
 *   cat=<code> opens a category · cat=new the form of a new one
 */
export function useApprovalsNav() {
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
  const requestOpen = computed(() => get('request') || null);
  const categoryOpen = computed(() => get('cat') || null);
  const creating = computed(() => !!get('new'));
  const editing = computed(() => get('edit') === '1' && !!get('request'));
  /** Another tab is another list: its filters, page and open drawers start again. */
  const go = (tab, params = {}) => replace({ tab, ...pruned(params) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  const setSort = (s) => patch({ sort: s ? s.key : null, order: s ? s.order : null, page: null });
  const openRequest = (number) => patch({ request: number, new: null, edit: null, cat: null });
  const closeRequest = () => patch({ request: null, edit: null });
  const openNew = (categoryCode = null) => patch({ new: categoryCode || '1', edit: null, cat: null });
  const openEdit = () => patch({ edit: '1', new: null });
  const closeForm = () => patch({ new: null, edit: null });
  const openCategory = (code) => patch({ cat: code, request: null, new: null, edit: null });
  const closeCategory = () => patch({ cat: null });
  return { route, get, patch, go, tabParam, page, sort, requestOpen, categoryOpen, creating, editing, setFilter, setPage, setSort, openRequest, closeRequest, openNew, openEdit, closeForm, openCategory, closeCategory };
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
/** ONE request shared by the forms and the filters through the query cache (two minutes), fetched only while needed. */
export function useLookups(enabled = () => true) {
  const q = useGet('/approvals/lookups', undefined, { enabled, staleTime: 120_000 });
  const data = computed(() => q.data.value || { categories: NONE, users: NONE });
  const userOptions = computed(() => data.value.users.map((u) => ({ v: u.id, l: `${personName(u)} · ${u.username}` })));
  const categoryOptions = computed(() => data.value.categories.map((c) => ({ v: c.code, l: catName(c) })));
  return { query: q, data, userOptions, categoryOptions, loaded: computed(() => !!q.data.value) };
}

// ---------------------------------------------------------------- files
export const MAX_FILE_BYTES = 5 * 1024 * 1024;
/** A picked file as the API takes it: { fileName, mime, base64 (a data URL), size }. Rejects a file over the limit. */
export function readFile(file) {
  return new Promise((resolve, reject) => {
    if (file.size > MAX_FILE_BYTES) { reject(new ApiError({ status: 400, category: 'VALIDATION', code: 'ATTACHMENT_TOO_LARGE', message: `الملف «${file.name}» يتجاوز 5 ميجابايت`, messageEn: `File "${file.name}" exceeds 5 MB` })); return; }
    const reader = new FileReader();
    reader.onerror = () => reject(new ApiError({ status: 0, category: 'SYSTEM', code: 'CLIENT', message: `تعذّرت قراءة الملف «${file.name}»`, messageEn: `File "${file.name}" could not be read` }));
    reader.onload = () => resolve({ fileName: file.name, mime: file.type || 'application/octet-stream', base64: String(reader.result), size: file.size });
    reader.readAsDataURL(file);
  });
}

/**
 * The bytes of an attachment. A link or an <img> cannot send the bearer token, so the file is fetched with it and
 * handed back as a Blob (a file of the Odoo archive can be large: it is never turned into text on the way).
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
export const attachmentPath = (requestNumber, attachmentId) => `/approvals/requests/${encodeURIComponent(requestNumber)}/attachments/${encodeURIComponent(attachmentId)}`;
/** Hands a fetched file to the browser as a download. */
export function saveBlob(blob, fileName) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = fileName || 'file';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
export const isImage = (mime) => /^image\/(png|jpe?g|gif|webp)$/i.test(String(mime || ''));
export const isPdf = (mime) => /^application\/pdf$/i.test(String(mime || ''));
