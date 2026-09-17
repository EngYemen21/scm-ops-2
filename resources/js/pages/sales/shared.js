// Shared sales building blocks (reference `pages/sales/shared.tsx`): form options, lookups, name helpers, the line-draft
// model of the multi-line editor (totals incl. VAT, client validation, request body) and status-history mapping.
// Components of the same reference file live beside this module: ProductPicker, LinesEditor, TotalsBox, QuotationForm,
// SoForm, CustomerForm, ConvertModal, SoDetail, SoDrawer.
//
// API shapes consumed here (camelCase JSON):
//   CustomerLite { code, nameAr, nameEn?, zone?, city?, terms?, contact?, priceList?, creditLimit?, balance? }
//   ProductLite  { sku, nameAr, nameEn?, baseUom?: { code } | null, sellPrice?, price? }
//   Totals       { sub, vatPct, vat, total }
//   Hist         { fromStatus?, toStatus, username?, note?, at }
//   Qt           { number, status, date, validUntil?, terms?, delivery?, notes?, createdBy?, attachments?: string[], customer,
//                  lines: [{ lineNo, qty, price, discPct?, product: { sku, nameAr, nameEn?, baseUom?: {code}|null } }], so?, totals, history? }
//   So           { number, status, date, dueDate?, window?, priority, kg, cbm, createdBy?, customer, warehouse: { code, nameAr },
//                  lines: [{ id, lineNo, qty, price, discPct?, reservedQty, allocatedQty, pickedQty, deliveredQty?, product, allocations? }],
//                  totals, fos?, quotation?, consolidation?, pods?, returns?, history? }
//   Lookups      { warehouses: [{ code, nameAr, nameEn }], customers: CustomerLite[] }
import { useGet } from '@/api/client';
import { lang, nm, num, t } from '@/i18n';
import { QT_LABELS } from '@/shared';

// ---------------------------------------------------------------- constants (prototype form options)
/** Payment terms value of a cash customer (same literal as the server). */
export const CASH_TERMS = 'نقدي / محفظة';
export const WINDOWS = [['08:00–11:00', '08:00–11:00'], ['09:00–13:00', '09:00–13:00'], ['13:00–17:00', '13:00–17:00']];
export const TERMS = [[CASH_TERMS, { ar: 'نقدي / محفظة', en: 'Cash / wallet' }], ['آجل 30', { ar: 'آجل 30', en: 'Net 30' }], ['آجل 45', { ar: 'آجل 45', en: 'Net 45' }], ['آجل 60', { ar: 'آجل 60', en: 'Net 60' }]];
export const DELIVERY = [['توصيل المستودع', { ar: 'توصيل المستودع', en: 'Warehouse delivery' }], ['استلام من المستودع', { ar: 'استلام من المستودع', en: 'Customer pickup' }], ['توصيل طرف ثالث', { ar: 'توصيل طرف ثالث', en: 'Third-party delivery' }]];
export const CITIES = [['الرياض', { ar: 'الرياض', en: 'Riyadh' }], ['جدة', { ar: 'جدة', en: 'Jeddah' }], ['الدمام', { ar: 'الدمام', en: 'Dammam' }]];
export const PRICE_LISTS = [['A', { ar: 'A — مطاعم', en: 'A — Restaurants' }], ['F', { ar: 'F — فرنشايز', en: 'F — Franchise' }], ['H', { ar: 'H — فنادق', en: 'H — Hotels' }], ['C', { ar: 'C — كافيهات', en: 'C — Cafés' }]];
export const PRICE_LIST_NAMES = { A: { ar: 'مطاعم', en: 'Restaurants' }, F: { ar: 'فرنشايز', en: 'Franchise' }, H: { ar: 'فنادق', en: 'Hotels' }, C: { ar: 'كافيهات', en: 'Cafés' } };
export const PRIORITIES = [['normal', { ar: 'عادية', en: 'Normal' }], ['high', { ar: 'عالية', en: 'High' }]];
export const SO_STEPS = ['confirmed', 'preparing', 'picking', 'packed', 'readydisp', 'outfordel', 'delivered'];
export const SAR = { ar: 'ر.س', en: 'SAR' };
export const VAT_PCT = 15;
export const MAX_DISC = 30;

// ---------------------------------------------------------------- lookups + names
export const useLookups = () => useGet('/master/lookups', undefined, { staleTime: 60_000 });
/** Customer / product display name in the UI language ('—' when missing). Reactive: reads `lang`. */
export const custName = (c) => (c ? nm(c) || '—' : '—');
export const prodName = (p) => (p ? nm(p) || '—' : '—');
/** `A — مطاعم`; a value that is not a known list code (free text from the server) is shown as is. */
export function priceListLabel(code) {
  if (!code) return '—';
  const n = PRICE_LIST_NAMES[code];
  return n ? `${code} — ${lang.value === 'ar' ? n.ar : n.en}` : code;
}
/** `baseUom` is an object `{ code, nameAr, … }` (or a legacy string, or null) — never render the object itself. */
export const uomCode = (u) => (typeof u === 'string' ? u : u?.code || '');
/** Stepper position of a sales-order status: `{ idx, failed }`. */
export function soStepIndex(status) {
  const map = { draft: -1, confirmed: 0, reserved: 0, allocated: 0, preparing: 1, picking: 2, picked: 2, packed: 3, readydisp: 4, loaded: 4, outfordel: 5, delivered: 6, partial: 6, completed: 6, failed: 5, cancelled: 0, returned: 6 };
  return { idx: map[status] ?? 0, failed: status === 'failed' || status === 'cancelled' };
}
export const lineNetOf = (l) => num(l.qty) * num(l.price) * (1 - num(l.discPct) / 100);

// ---------------------------------------------------------------- multi-line editor model
// LineDraft { key, sku, name?, uom?, qty: number|null, price: number|null, discPct: number|null }
let lineSeq = 1;
export const newLine = () => ({ key: lineSeq++, sku: '', qty: null, price: null, discPct: 0 });
export const lineNet = lineNetOf;
export function linesTotals(lines) {
  const sub = Math.round(lines.reduce((s, l) => s + lineNet(l), 0) * 100) / 100;
  const vat = Math.round(sub * VAT_PCT) / 100;
  return { sub, vatPct: VAT_PCT, vat, total: Math.round((sub + vat) * 100) / 100 };
}
/** Client-side line validation (the server remains the authority). Returns `{ 'lines.0.qty': 'message' }`. */
export function validateLines(lines, discount) {
  const errs = {};
  lines.forEach((l, i) => {
    if (!l.sku.trim()) errs[`lines.${i}.sku`] = t('اختر المنتج', 'Pick a product');
    if (!l.qty || l.qty <= 0) errs[`lines.${i}.qty`] = t('الكمية > 0', 'Qty > 0');
    if (!l.price || l.price <= 0) errs[`lines.${i}.price`] = t('السعر > 0', 'Price > 0');
    if (discount && num(l.discPct) > MAX_DISC) errs[`lines.${i}.discPct`] = t(`الخصم فوق ${MAX_DISC}% يحتاج اعتماد مدير المبيعات`, `Discount above ${MAX_DISC}% needs sales-manager approval`);
  });
  return errs;
}
export const linesBody = (lines, discount) => lines.map((l) => ({ sku: l.sku.trim(), qty: num(l.qty), price: num(l.price), ...(discount && num(l.discPct) > 0 ? { discPct: num(l.discPct) } : {}) }));

// ---------------------------------------------------------------- select options from lookups ("name · price list (code)")
export const customerOptions = (lk) => (lk?.customers || []).map((c) => ({ v: c.code, l: `${custName(c)}${c.priceList ? ` · ${c.priceList}` : ''} (${c.code})` }));
export const warehouseOptions = (lk) => (lk?.warehouses || []).map((w) => ({ v: w.code, l: `${w.code} — ${lang.value === 'ar' ? w.nameAr : w.nameEn || w.nameAr}` }));

// ---------------------------------------------------------------- status history → <Timeline :items>
export const historyItems = (h, map) => (h || []).map((x) => ({ at: x.at, label: map[x.toStatus] ? { ar: map[x.toStatus].ar, en: map[x.toStatus].en } : x.toStatus, by: x.username, note: x.note, color: map[x.toStatus]?.fg }));
/** Status key → label in the UI language, from a label map (`SO_LABELS` …). */
export const statusLabel = (map, k) => (map[k] ? (lang.value === 'ar' ? map[k].ar : map[k].en) : k);
/** Quotation status → label (funnel / other uses). */
export const qtLabel = (k) => statusLabel(QT_LABELS, k);

/** Date `n` days from now as `YYYY-MM-DD` (form defaults). */
export const isoDatePlus = (days) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
