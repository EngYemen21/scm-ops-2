// «بوابة العملاء» — what the views of the page share: label maps (with chip colours), the page's query-string state,
// small formats, and the file helpers of the onboarding papers.
//
// API (routes/api/portal.php, all under /portal — camelCase JSON, money as decimal strings, days as YYYY-MM-DD):
//   GET summary                                    the figures of the page head
//   GET branches · branches/{id}                   POST branches · PATCH branches/{id} · POST branches/{id}/archive|restore
//   GET favorites/lists · …/{id} · …/{id}/items    POST …/lists · PATCH …/{id} · POST …/{id}/archive|restore
//       …/{id}/order-again (GET preview · POST)    POST …/{id}/items · PATCH|DELETE …/items/{item} · POST …/items/{item}/move
//   GET applications · …/{id} · …/{id}/documents/{doc}     POST applications · PATCH …/{id}
//       POST …/{id}/submit|request-correction|approve|reject|expire|reopen|notes|documents · …/documents/{doc}/remove
//   GET requests · …/{id} · line-changes            POST requests · PATCH …/{id}/lines · POST …/{id}/approve|reject|cancel|notes
//   GET statements · …/preview · …/{id} · …/{id}/lines · …/{id}/export     POST statements · POST …/{id}/void
//
// Shapes worth knowing:
//   Party     customer: { id, code, nameAr, nameEn, city, active, linked, ref } — `linked: false` = the source system's
//             customer that OPS does not have yet (only `ref` and the name it gave);  product: { id, sku, nameAr, nameEn, active, linked, ref }
//   every record: { sourceSystem: 'odoo' | null, externalRef, owned } — `owned` = it is Odoo's right now (read-only here)
//   every detail: `can: { … }` = what the server would accept now; `events: [{ kind, note, data, username, origin, at }]`
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { API_BASE, ApiError, refreshAccess, tokens } from '@/api/client';
import { fmtDate, fmtNum, lang, t } from '@/i18n';

// ---------------------------------------------------------------- colours and labels
export const TONE = {
  ok: { fg: '#1d7a3e', bg: '#e6f9ec' }, warn: { fg: '#b26a16', bg: '#fbf0dd' }, bad: { fg: '#b23b3b', bg: '#fdecec' },
  blue: { fg: '#3C79F5', bg: '#e8effe' }, violet: { fg: '#654e92', bg: '#efeaf8' }, neutral: { fg: '#55506a', bg: '#F1EFF6' }, off: { fg: '#7d7990', bg: '#F1EFF6' },
};

export const PORTAL_TABS = ['branches', 'favorites', 'applications', 'requests', 'statements'];

export const ACTIVE_LABELS = { active: { ar: 'نشط', en: 'Active', ...TONE.ok }, archived: { ar: 'مؤرشف', en: 'Archived', ...TONE.off } };
export const OWNED_CHIP = { ar: 'من أودو', en: 'From Odoo', ...TONE.violet };
export const UNLINKED_CHIP = { ar: 'غير مربوط بعميل', en: 'Not an OPS customer yet', ...TONE.warn };

/** `portal_applications.status` — the states of the review. */
export const APPLICATION_STATUS = {
  draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral },
  submitted: { ar: 'بانتظار المراجعة', en: 'Waiting for review', ...TONE.warn },
  correction: { ar: 'بانتظار تصحيح العميل', en: 'Correction requested', ...TONE.blue },
  accepted: { ar: 'بانتظار توقيع العميل', en: 'Waiting for signature', ...TONE.violet },
  approved: { ar: 'معتمد — صار عميلًا', en: 'Approved — a customer', ...TONE.ok },
  rejected: { ar: 'مرفوض', en: 'Rejected', ...TONE.bad },
  expired: { ar: 'منتهٍ', en: 'Expired', ...TONE.off },
};
/** The lists of applications a pill asks for by one word (the API knows the groups). */
export const APPLICATION_FILTERS = [
  { k: 'review', label: { ar: 'بانتظار المراجعة', en: 'To review' } }, { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'approved', label: { ar: 'المعتمدة', en: 'Approved' } },
  { k: 'rejected,expired', label: { ar: 'المرفوضة والمنتهية', en: 'Rejected & expired' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
];
/** Odoo's own word for the state of an application, shown beside the OPS one. */
export const ODOO_APPLICATION_STATE = {
  draft: { ar: 'مسودة', en: 'Draft' }, extracting: { ar: 'قراءة المستندات', en: 'Extracting' }, pending_review: { ar: 'بانتظار المراجعة', en: 'Pending review' },
  pending_signature: { ar: 'بانتظار التوقيع', en: 'Pending signature' }, signed: { ar: 'وُقّع', en: 'Signed' }, activated: { ar: 'مفعَّل', en: 'Activated' },
  rejected: { ar: 'مرفوض', en: 'Rejected' }, expired: { ar: 'منتهٍ', en: 'Expired' },
};
export const LEGAL_TYPES = { establishment: { ar: 'مؤسسة', en: 'Establishment' }, llc: { ar: 'ذات مسؤولية محدودة', en: 'LLC' }, jsc: { ar: 'مساهمة', en: 'Joint stock' }, sole_prop: { ar: 'مؤسسة فردية', en: 'Sole proprietorship' }, other: { ar: 'أخرى', en: 'Other' } };
export const LEGAL_TYPE_OPTIONS = Object.entries(LEGAL_TYPES).map(([v, l]) => ({ v, l }));
export const CONTACT_ROLES = { purchaser: { ar: 'مسؤول المشتريات', en: 'Purchaser' }, receiver: { ar: 'مستلم البضاعة', en: 'Receiver' }, accountant: { ar: 'المحاسب', en: 'Accountant' }, manager: { ar: 'المدير', en: 'Manager' }, other: { ar: 'آخر', en: 'Other' } };
export const CONTACT_ROLE_OPTIONS = Object.entries(CONTACT_ROLES).map(([v, l]) => ({ v, l }));
/** The papers of an application. */
export const DOCUMENT_KINDS = {
  cr: { ar: 'السجل التجاري', en: 'Commercial registration' }, vat: { ar: 'شهادة التسجيل الضريبي', en: 'VAT certificate' }, national_address: { ar: 'العنوان الوطني', en: 'National address' },
  owner_id: { ar: 'هوية المالك', en: 'Owner’s identity' }, bank_letter: { ar: 'خطاب البنك', en: 'Bank letter' }, chamber: { ar: 'شهادة الغرفة التجارية', en: 'Chamber certificate' }, other: { ar: 'مستند آخر', en: 'Other document' },
};
export const DOCUMENT_KIND_OPTIONS = Object.entries(DOCUMENT_KINDS).map(([v, l]) => ({ v, l }));
/** Why a paper cannot be opened by this reader now (`documents[].unavailable`). */
export const DOCUMENT_UNAVAILABLE = {
  removed: { ar: 'أُزيل من الطلب', en: 'Removed from the application' }, private: { ar: 'ورقة هوية أو حساب بنكي — يفتحها من يبتّ في الطلبات', en: 'An identity or bank paper — opened by who decides applications' },
  not_archived: { ar: 'من أودو — لم يُؤرشف محتواها هنا بعد', en: 'From Odoo — its content is not archived here yet' },
};
/** How an approval named the customer (`customerBy`). */
export const CUSTOMER_BY = {
  new: { ar: 'عميل جديد', en: 'A new customer' }, link: { ar: 'رابط قائم', en: 'An existing link' }, vat: { ar: 'الرقم الضريبي', en: 'VAT number' }, cr: { ar: 'السجل التجاري', en: 'Commercial registration' },
  person: { ar: 'حدّده المراجع', en: 'Chosen by the reviewer' },
};
/** Why a customer is shown as a candidate of an application. */
export const CANDIDATE_WHY = { sameNumber: { ar: 'الرقم نفسه', en: 'Same number' }, sameName: { ar: 'الاسم نفسه', en: 'Same name' }, samePhone: { ar: 'الهاتف نفسه', en: 'Same phone' }, similarName: { ar: 'اسم مشابه', en: 'Similar name' } };

/** `portal_order_requests.status`. */
export const REQUEST_STATUS = {
  pending: { ar: 'بانتظار القرار', en: 'Pending', ...TONE.warn }, approved: { ar: 'معتمد', en: 'Approved', ...TONE.ok }, rejected: { ar: 'مرفوض', en: 'Rejected', ...TONE.bad }, cancelled: { ar: 'ملغى', en: 'Cancelled', ...TONE.off },
};
/** `portal_line_changes.change_type` and what a modification changed. */
export const CHANGE_TYPE = { added: { ar: 'أُضيف', en: 'Added', ...TONE.ok }, modified: { ar: 'عُدّل', en: 'Modified', ...TONE.blue }, removed: { ar: 'حُذف', en: 'Removed', ...TONE.bad } };
export const CHANGE_FIELD = { qty: { ar: 'الكمية', en: 'Quantity' }, price: { ar: 'سعر الوحدة', en: 'Unit price' } };

/** `portal_statements.status` and the kinds of its lines. */
export const STATEMENT_STATUS = { draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral }, issued: { ar: 'صادر', en: 'Issued', ...TONE.ok }, void: { ar: 'ملغى', en: 'Void', ...TONE.off } };
export const LINE_KIND = {
  invoice: { ar: 'فاتورة', en: 'Invoice', ...TONE.blue }, credit_note: { ar: 'إشعار دائن', en: 'Credit note', ...TONE.violet }, payment: { ar: 'سداد', en: 'Payment', ...TONE.ok },
  reversal: { ar: 'إلغاء مستند', en: 'Reversal', ...TONE.bad }, other: { ar: 'حركة', en: 'Entry', ...TONE.neutral },
};

export const DAYS = [['sat', { ar: 'السبت', en: 'Sat' }], ['sun', { ar: 'الأحد', en: 'Sun' }], ['mon', { ar: 'الاثنين', en: 'Mon' }], ['tue', { ar: 'الثلاثاء', en: 'Tue' }], ['wed', { ar: 'الأربعاء', en: 'Wed' }], ['thu', { ar: 'الخميس', en: 'Thu' }], ['fri', { ar: 'الجمعة', en: 'Fri' }]];
export const HUB_ROLES = { full: { ar: 'كامل الصلاحيات', en: 'Full' }, operations: { ar: 'طلبات الشراء', en: 'Order requests' }, orders_only: { ar: 'الطلبات فقط', en: 'Orders only' }, invoices_only: { ar: 'الفواتير فقط', en: 'Invoices only' } };

export const ORIGIN_TABS = [{ k: '', label: { ar: 'كل المصادر', en: 'Any origin' } }, { k: 'ops', label: { ar: 'نظام العمليات', en: 'OPS' } }, { k: 'odoo', label: { ar: 'أودو', en: 'Odoo' } }];
export const STATE_TABS = [{ k: '', label: { ar: 'النشطة', en: 'Active' } }, { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } }];
export const LINKED_TABS = [{ k: '', label: { ar: 'كل العملاء', en: 'Any customer' } }, { k: 'yes', label: { ar: 'عملاء النظام', en: 'OPS customers' } }, { k: 'no', label: { ar: 'غير مربوطين بعد', en: 'Not linked yet' } }];
export const SELECT_PLACEHOLDER = { ar: '— اختر —', en: '— select —' };

/** What happened to a record, in words (`events[].kind`). */
const EVENT_LABELS = {
  created: { ar: 'أُنشئ', en: 'Created', ...TONE.neutral }, updated: { ar: 'عُدّلت بياناته', en: 'Edited', ...TONE.neutral }, archived: { ar: 'أُرشف', en: 'Archived', ...TONE.off },
  restored: { ar: 'استُعيد', en: 'Restored', ...TONE.ok }, note: { ar: 'ملاحظة', en: 'Note', ...TONE.blue }, submitted: { ar: 'قُدّم للمراجعة', en: 'Submitted for review', ...TONE.warn },
  resubmitted: { ar: 'أُعيد تقديمه بعد التصحيح', en: 'Resubmitted', ...TONE.warn }, correction: { ar: 'طُلب تصحيح', en: 'Correction requested', ...TONE.blue },
  accepted: { ar: 'قُبل — بانتظار التوقيع', en: 'Accepted — waiting for signature', ...TONE.violet }, approved: { ar: 'اعتُمد', en: 'Approved', ...TONE.ok }, rejected: { ar: 'رُفض', en: 'Rejected', ...TONE.bad },
  expired: { ar: 'انتهت صلاحيته', en: 'Expired', ...TONE.off }, reopened: { ar: 'أُعيد فتحه', en: 'Reopened', ...TONE.violet }, draft: { ar: 'عاد مسودة', en: 'Back to draft', ...TONE.neutral },
  cancelled: { ar: 'أُلغي', en: 'Cancelled', ...TONE.off }, pending: { ar: 'بانتظار القرار', en: 'Pending', ...TONE.warn }, document_added: { ar: 'أُضيف مستند', en: 'Document added', ...TONE.blue },
  document_removed: { ar: 'أُزيل مستند', en: 'Document removed', ...TONE.bad }, lines_changed: { ar: 'عُدّلت الأسطر', en: 'Lines changed', ...TONE.blue }, quotation: { ar: 'أُنشئ عرض سعر', en: 'Quotation made', ...TONE.ok },
  item_added: { ar: 'أُضيف منتج', en: 'Product added', ...TONE.ok }, item_removed: { ar: 'أُزيل منتج', en: 'Product removed', ...TONE.bad }, item_qty: { ar: 'عُدّلت الكمية المعتادة', en: 'Usual quantity changed', ...TONE.blue },
  issued: { ar: 'صدر الكشف', en: 'Issued', ...TONE.ok }, voided: { ar: 'أُلغي الكشف', en: 'Voided', ...TONE.bad },
};
/** One event as a row of <Timeline>: label, colour, who, when and what it carried, in words. */
export function eventItem(e) {
  const l = EVENT_LABELS[e.kind] || { ar: e.kind, en: e.kind, ...TONE.neutral };
  const d = e.data || {};
  const extra = [];
  if (d.customerCode) extra.push(t(`العميل ${d.customerNameAr || ''} (${d.customerCode})`, `Customer ${d.customerNameEn || d.customerNameAr || ''} (${d.customerCode})`));
  if (d.by && CUSTOMER_BY[d.by]) extra.push(t(`عُرف بـ: ${CUSTOMER_BY[d.by].ar}`, `Named by: ${CUSTOMER_BY[d.by].en}`));
  if (d.quotation) extra.push(t(`عرض السعر ${d.quotation}`, `Quotation ${d.quotation}`));
  if (d.number && e.kind === 'quotation') extra.push(`${d.number}${d.total != null ? ` · ${fmtNum(Number(d.total), 2)}` : ''}`);
  if (d.sku || d.nameAr) extra.push(`${lang.value === 'ar' ? d.nameAr || d.nameEn || '' : d.nameEn || d.nameAr || ''}${d.sku ? ` (${d.sku})` : ''}${d.qty ? ` × ${d.qty}` : ''}${d.from != null && d.to != null ? `: ${d.from} ← ${d.to}` : ''}`);
  if (d.fileName) extra.push(`${DOCUMENT_KINDS[d.kind] ? (lang.value === 'ar' ? DOCUMENT_KINDS[d.kind].ar : DOCUMENT_KINDS[d.kind].en) : ''} · ${d.fileName}`);
  if (d.changes && d.totalTo != null) extra.push(t(`${d.changes} تغيير · الإجمالي ${d.totalFrom} ← ${d.totalTo}`, `${d.changes} change(s) · total ${d.totalFrom} → ${d.totalTo}`));
  if (Array.isArray(d.fields) && d.fields.length) extra.push(d.fields.join(' · '));
  if (d.order) extra.push(t(`الأمر ${d.order}`, `Order ${d.order}`));
  if (d.lines != null && e.kind !== 'quotation') extra.push(t(`${d.lines} سطر`, `${d.lines} line(s)`));
  if (d.closing != null) extra.push(t(`الرصيد الختامي ${d.closing}`, `Closing balance ${d.closing}`));
  return { at: e.at, label: { ar: l.ar, en: l.en }, color: l.fg, by: e.origin === 'odoo' ? `${e.username || ''} · Odoo` : e.username, note: [e.note, ...extra].filter(Boolean).join(' — ') || null };
}

// ---------------------------------------------------------------- small formats
/** Name of a party / record that carries nameAr + nameEn. */
export const nameOf = (o) => (o ? (lang.value === 'ar' ? o.nameAr || o.nameEn : o.nameEn || o.nameAr) || '' : '');
/** A customer in one line: "name (code)" — or the name the source gave, for one OPS does not have yet. */
export const customerLine = (c) => (c ? `${nameOf(c) || '—'}${c.code ? ` (${c.code})` : ''}` : '—');
/** The value a list is filtered by to show the rows of the same customer. */
export const customerKey = (c) => (c ? (c.linked ? c.code : c.ref ? `ref:${c.ref}` : '') : '');
/** Money: always two decimals, grouped. */
export const money = (v) => (v == null || v === '' ? '—' : fmtNum(Number(v), 2));
export const moment = (v) => (v ? fmtDate(v) : '—');
/** An API day (`YYYY-MM-DD`) as the same calendar day on screen. */
export const day = (v) => (typeof v === 'string' && v.length >= 10 ? v.slice(0, 10).replace(/-/g, '/') : '—');
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
export const monthStartIso = () => `${todayIso().slice(0, 8)}01`;
export const daysLabel = (days) => (days && days.length ? DAYS.filter(([k]) => days.includes(k)).map(([, l]) => (lang.value === 'ar' ? l.ar : l.en)).join(' · ') : t('كل الأيام', 'Every day'));
export const labelOf = (map, k) => (map[k] ? (lang.value === 'ar' ? map[k].ar : map[k].en) : k || '—');

// ---------------------------------------------------------------- query-string state
const pruned = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v != null && v !== ''));

/**
 * Everything the page shows lives in the query string of /portal, so every view, filter and open drawer is a link:
 *   tab=branches|favorites|applications|requests|statements · q · page · customer=<code | ref:id> · origin · sort · order
 *   branches:      state · linked · site                         branch=<id> opens the branch
 *   favorites:     state · linked · product                      list=<id> opens the list (ipage · iq = its items)
 *   applications:  status                                        app=<id> opens the application
 *   requests:      view=changes switches to the change log; status (requests) · type · field · orderRef · product · by · from · to (log)
 *                                                                req=<id> opens the request
 *   statements:    status · from · to                            stmt=<id> opens the statement (lpage · lkind · lbranch = its lines)
 */
export function usePortalNav() {
  const route = useRoute();
  const router = useRouter();
  const get = (k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || ''; };
  const replace = (query) => router.replace({ path: route.path, query });
  /** Changes some keys of the query string and keeps the rest; an empty value removes its key. */
  function patch(changes) {
    const next = { ...route.query };
    for (const [k, v] of Object.entries(changes)) { if (v == null || v === '') delete next[k]; else next[k] = String(v); }
    return replace(next);
  }
  const tab = computed(() => (PORTAL_TABS.includes(get('tab')) ? get('tab') : 'branches'));
  const page = computed(() => Math.max(1, Number(get('page')) || 1));
  /** Opens a tab with its own filters; what belonged to the previous tab (filters, page, open drawer) is dropped. */
  const go = (to, params = {}) => replace({ ...(to && to !== 'branches' ? { tab: to } : {}), ...pruned(params) });
  const setFilter = (key, value) => patch({ [key]: value, page: null });
  const setPage = (p) => patch({ page: p > 1 ? p : null });
  /** A sortable list: `sortOf(['name', 'createdAt'])` → { key, order } | null, and the setter DataTable calls. */
  const sortOf = (keys) => (keys.includes(get('sort')) ? { key: get('sort'), order: get('order') === 'asc' ? 'asc' : 'desc' } : null);
  const setSort = (s) => patch({ sort: s.key, order: s.order, page: null });
  return { route, get, tab, page, patch, go, setFilter, setPage, sortOf, setSort };
}

/**
 * A search box bound to a key of the query string: what is typed reaches the URL (and so the list) 350 ms after the
 * last key, at once on Enter; a deep link or a filter that clears the key refills the box.
 *   const { text, type, apply } = useSearchParam(nav);   <TextInput :model-value="text" @update:model-value="type" @enter="apply" />
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
 * The bytes behind a protected path. A link or an <img> cannot send the bearer token, so the file is fetched with it
 * and handed back as a Blob (a paper of the Odoo archive can be large: it is never turned into text on the way).
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
/** Hands a fetched file to the browser as a download. */
export function saveBlob(blob, fileName) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = fileName || 'file';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
/** Shows a fetched PDF or image in a new tab (a paper is looked at far more often than it is kept). */
export function showBlob(blob) {
  const url = URL.createObjectURL(blob);
  const w = globalThis.open(url, '_blank');
  if (!w) { URL.revokeObjectURL(url); return false; } // the browser kept the new tab closed: the caller offers the download instead
  setTimeout(() => URL.revokeObjectURL(url), 120_000);
  return true;
}
export const isViewable = (mime) => /^(image\/(png|jpe?g|gif|webp)|application\/pdf)$/i.test(String(mime || ''));
export const fileSize = (n) => (n == null ? '' : n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);
