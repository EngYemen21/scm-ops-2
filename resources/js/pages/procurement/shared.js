// Procurement shared pieces: label maps, option lists, small helpers and the option composables.
// (The reference's `shared.tsx` — its components live next to this file, one per .vue.)
//
// API shapes used across the domain (camelCase JSON, decimals arrive as strings):
//   Product    { id, sku, nameAr, nameEn, purchasePrice?, tracksExpiry?, storageClass?, preferredSupplierName? }
//   Supplier   { id, code, nameAr, nameEn, category?, categoryEn?, leadDays, otif, fillRate, score, ordersCount, totalValue,
//                cr?, vat?, contact?, email?, terms?, iban?, minOrder?, notes?, isNew, active }
//   Approval   { id, step, roleKey, labelAr, labelEn, decision: 'pending'|'approved'|'rejected'|'cancelled', approver?, note?, decidedAt? }
//   Po         { id, number, status, total, dueDate, paymentTerms?, reference?, notes?, createdBy?, createdAt, sentAt?, approvedAt?,
//                confirmedAt?, openQty?, supplier, warehouse, approvals[], currentStep?, lines?[], _count?{lines,grns},
//                shipments?[{ number, status, eta?, grns?[] }], grns?[], sources?{ rfq?, prs?[] } }
//   Pr         { id, number, status, priority, needDate?, justification, costCenter?, requestedBy, createdAt, estTotal, warehouse, lines[], approvals[] }
//   Rfq        { id, number, status, closeDate, invitedRule, terms?, notes?, pr?, warehouse?, lines[{ lineNo, qty, product }], _count?{suppliers,quotations} }
//   Quotation  { id, number, supplierRef?, status, date, validUntil?, paymentTerms?, deliveryTerms?, minOrder, leadDays, attachmentName?,
//                attachmentType?, supplier, rfq?, lines[{ price, qty?, vatPct?, leadDays?, product }] }
//   Comparison { rfq, need[], invited[], quotes[ComparisonRow], rec, weights? }
//   ComparisonRow { quotationId, number, supplierRef?, status, supplier, price, unitPrice, lead, pay?, deliveryTerms?, min, score,
//                validUntil?, complete, expired, moqExceedsNeed, weighted, rec, reasons[], reasonsEn[] }
//   Suggestion { sku, nameAr, nameEn, storageClass, warehouse, avail, reserved, adc, lead, safety, incoming, sug, urgent, supplier?, price, why[], whyE[] }
//   LineDraft  { sku, name?, qty: number|null, price?: number|null, tracksExpiry? }   (line editor rows)
import { computed } from 'vue';
import { useList } from '@/api/client';
import { fmtNum, lang } from '@/i18n';
import { useAuth } from '@/stores/auth';

// ---------------------------------------------------------------- labels
export const RFQ_LABELS = {
  draft: { ar: 'مسودة', en: 'Draft', fg: '#55506a', bg: '#F1EFF6' }, open: { ar: 'مفتوح — بانتظار العروض', en: 'Open', fg: '#b26a16', bg: '#fbf0dd' },
  quoted: { ar: 'وصل عرض', en: 'Quoted', fg: '#0d7f93', bg: '#d9f4f9' }, compared: { ar: 'جاهز للمقارنة', en: 'Compared', fg: '#3C79F5', bg: '#e8effe' },
  awarded: { ar: 'تمت الترسية ✓', en: 'Awarded ✓', fg: '#1d7a3e', bg: '#e6f9ec' }, closed: { ar: 'مغلق', en: 'Closed', fg: '#55506a', bg: '#F1EFF6' }, cancelled: { ar: 'ملغى', en: 'Cancelled', fg: '#b23b3b', bg: '#fdecec' },
};
export const SQ_LABELS = {
  received: { ar: 'مستلم', en: 'Received', fg: '#0d7f93', bg: '#d9f4f9' }, awarded: { ar: 'تمت الترسية ✓', en: 'Awarded ✓', fg: '#1d7a3e', bg: '#e6f9ec' }, lost: { ar: 'لم يُرسَ', en: 'Lost', fg: '#7d7990', bg: '#F1EFF6' }, expired: { ar: 'منتهي', en: 'Expired', fg: '#a8a4b8', bg: '#F1EFF6' },
};
export const PRIORITY_LABELS = {
  urgent: { ar: 'عاجلة', en: 'Urgent', fg: '#fff', bg: '#b23b3b' }, normal: { ar: 'عادية', en: 'Normal', fg: '#55506a', bg: '#F1EFF6' }, low: { ar: 'منخفضة', en: 'Low', fg: '#a8a4b8', bg: '#F1EFF6' },
};
export const PR_STATUS_LABELS = {
  draft: { ar: 'مسودة', en: 'Draft', fg: '#55506a', bg: '#F1EFF6' }, submitted: { ar: 'بانتظار المراجعة', en: 'Submitted', fg: '#b26a16', bg: '#fbf0dd' }, review: { ar: 'قيد المراجعة', en: 'Under review', fg: '#0d7f93', bg: '#d9f4f9' },
  approved: { ar: 'معتمد', en: 'Approved', fg: '#1d7a3e', bg: '#e6f9ec' }, rejected: { ar: 'مرفوض', en: 'Rejected', fg: '#b23b3b', bg: '#fdecec' }, converted: { ar: 'معتمد — حُوّل', en: 'Converted', fg: '#654e92', bg: '#efeaf8' }, closed: { ar: 'مغلق', en: 'Closed', fg: '#55506a', bg: '#F1EFF6' },
};
/** Approval step decision chips (approvals timeline). */
export const DECISION_LABELS = {
  pending: { ar: 'بانتظار', en: 'Pending', fg: '#b26a16', bg: '#fbf0dd' }, approved: { ar: 'معتمد ✓', en: 'Approved ✓', fg: '#1d7a3e', bg: '#e6f9ec' },
  rejected: { ar: 'مرفوض', en: 'Rejected', fg: '#b23b3b', bg: '#fdecec' }, cancelled: { ar: 'ملغى', en: 'Cancelled', fg: '#7d7990', bg: '#F1EFF6' },
};
/** key → [arabic, english] */
export const SUPPLIER_CATEGORIES = { dry: ['أغذية جافة', 'Dry food'], bev: ['مشروبات', 'Beverages'], frz: ['مجمدات ومبردات', 'Frozen & chilled'], pkg: ['تغليف وورقيات', 'Packaging'], cln: ['منظفات', 'Cleaning'], eqp: ['معدات', 'Equipment'] };
export const SUPPLIER_CATEGORY_OPTIONS = Object.entries(SUPPLIER_CATEGORIES).map(([k, [ar, en]]) => ({ v: k, l: { ar, en } }));
export const TERMS_OPTIONS = [['آجل 30 يوم', { ar: 'آجل 30 يوم', en: 'Net 30' }], ['آجل 15 يوم', { ar: 'آجل 15 يوم', en: 'Net 15' }], ['عند الاستلام', { ar: 'عند الاستلام', en: 'COD' }], ['مقدم 100%', { ar: 'مقدم 100%', en: '100% advance' }]];
export const INVITE_RULES = [['cat', { ar: 'موردو الفئة', en: 'Category suppliers' }], ['top3', { ar: 'أفضل 3 تقييمًا', en: 'Top 3 by score' }], ['pref', { ar: 'المورد المفضل + بديلان', en: 'Preferred + 2 alternates' }]];
export const INVITE_RULES_WITH_MANUAL = [...INVITE_RULES, ['manual', { ar: 'اختيار يدوي', en: 'Manual' }]];
export const PRIORITY_OPTIONS = [['normal', { ar: 'عادية', en: 'Normal' }], ['urgent', { ar: 'عاجلة', en: 'Urgent' }], ['low', { ar: 'منخفضة', en: 'Low' }]];
export const SELECT_PLACEHOLDER = { ar: '— اختر —', en: '— select —' };
export const DASH_PLACEHOLDER = { ar: '—', en: '—' };

// ---------------------------------------------------------------- helpers
export const scoreColor = (s) => (s >= 90 ? '#1d7a3e' : s >= 75 ? '#b26a16' : '#b23b3b');
export const todayIso = () => new Date().toISOString().slice(0, 10);
export const plusDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
/** Product / supplier / warehouse name in the current language (reactive: reads `lang`). */
export const pname = (p) => (p ? (lang.value === 'ar' ? p.nameAr : p.nameEn) : '—');
export const sname = pname;
/** "Product × qty · Product × qty" summary of document lines. */
export const linesSummary = (lines) => (lines || []).map((l) => `${pname(l.product)} × ${fmtNum(l.qty)}`).join(' · ');
/** Every line has a quantity (and, with prices, a unit price) greater than zero. */
export const linesValid = (lines, withPrice) => lines.length > 0 && lines.every((l) => Number(l.qty) > 0 && (!withPrice || Number(l.price) > 0));
/** First string of a vue-router query value. */
export const qs = (v) => (Array.isArray(v) ? v[0] : v) || '';

// ---------------------------------------------------------------- option composables
/** Select options of the warehouses the user may see: `RYD · Riyadh`. Returns a computed array. */
export function useWarehouseOptions() {
  const auth = useAuth();
  return computed(() => (auth.user?.warehouses || []).map((w) => ({ v: w.code, l: `${w.code} · ${pname(w)}` })));
}
/** Supplier select options (`name · Score n`) + the raw supplier records. Both are computed refs. */
export function useSupplierOptions(activeOnly = true) {
  const list = useList('/suppliers', { pageSize: 200, ...(activeOnly ? { active: 'true' } : {}) });
  const suppliers = computed(() => list.data.value?.items || []);
  const options = computed(() => suppliers.value.map((s) => ({ v: s.code, l: `${pname(s)} · Score ${fmtNum(s.score)}` })));
  return { options, suppliers };
}
