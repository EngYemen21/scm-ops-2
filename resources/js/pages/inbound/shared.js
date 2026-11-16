// Inbound shared helpers: labels, draft handling of the receiving form and the shipment lifecycle actions.
// Components of the domain live beside this file: ReceiveLinesGrid, ShipmentPanel, PutawayRow, PutawayList, GrnDetail, ShipmentHistory.
import { api, useAction } from '@/api/client';
import { lang, t } from '@/i18n';

/**
 * Shapes (GET /api/inbound/…):
 * @typedef {{ id, lineNo, orderedQty, acceptedQty, damagedQty, rejectedQty, previouslyReceived, openQty, batchNo?, expiryDate?, mfgDate?,
 *   suggestionAr?, suggestionEn?, suggestedBin?: { code, zone?: { code, type } }, product: { sku, nameAr, nameEn, storageClass, tracksExpiry, barcodes?: { barcode }[] } }} ShipLine
 * @typedef {{ id, number, status, eta?, carrier?, locked?, arrivedAt?, completedAt?, createdAt, refusedAt?, refusedBy?, refusedReason?, po: { number, status }, supplier, warehouse,
 *   lines: ShipLine[], grns: { number, postedAt, postedBy?, voidedAt? }[], grn?: string|null, totals: { ordered, accepted, damaged, rejected, open }, history?: object[],
 *   backorder?: object, policy?: { rejectedStaysOpen: boolean, allowExcess: boolean } }} Shipment   (`grn` = the receipt in force; voided ones stay in `grns` as history)
 * @typedef {{ id, number, status: 'open'|'done'|'cancelled', qty, doneQty, batchNo?, expiryDate?, suggestionAr?, suggestionEn?, confirmedBy?, confirmedAt?,
 *   product, grn?: { number }, suggestedBin?: { code, zone? }, actualBin?: { code } }} PutawayTask
 * @typedef {{ qty: number|null, batchNo: string, mfgDate: string, expiryDate: string }} BatchDraft   one MORE batch of the same line
 * @typedef {{ acceptedQty: number|null, damagedQty: number|null, rejectedQty: number|null, batchNo: string, mfgDate: string, expiryDate: string, qcNote: string, extra: BatchDraft[] }} ReceiveDraft
 *   The accepted quantity is the line's total; `extra` carves further batches out of it — the first batch (batchNo / dates of the draft) is what is left.
 */

export const WORKER_SHIP_LABELS = {
  expected: { ar: 'متوقعة — بانتظار الوصول', en: 'Expected', fg: '#55506a', bg: '#F1EFF6' }, arrived: { ar: 'وصلت — ابدأ الاستلام', en: 'Arrived — start receiving', fg: '#3C79F5', bg: '#e8effe' },
  inspecting: { ar: 'جارٍ الاستلام', en: 'Receiving', fg: '#b26a16', bg: '#fbf0dd' }, putaway: { ar: 'GRN صادر — خزّن', en: 'GRN posted — put away', fg: '#654e92', bg: '#efeaf8' },
  done: { ar: 'مكتملة ✓', en: 'Done ✓', fg: '#1d7a3e', bg: '#e6f9ec' }, cancelled: { ar: 'ملغاة', en: 'Cancelled', fg: '#b23b3b', bg: '#fdecec' },
};
export const QC_LABELS = { accepted: { ar: 'مقبول', en: 'Accepted', fg: '#1d7a3e', bg: '#e6f9ec' }, damaged: { ar: 'تالف', en: 'Damaged', fg: '#b23b3b', bg: '#fdecec' }, rejected: { ar: 'مرفوض QC', en: 'Rejected', fg: '#b26a16', bg: '#fbf0dd' } };

/** Product / supplier / warehouse name in the UI language (reactive when used in a template). */
export const pn = (p, l = lang.value) => (p ? (l === 'ar' ? p.nameAr : p.nameEn || p.nameAr) : '—');
export const shipmentStep = (s) => ({ expected: 0, arrived: 1, inspecting: 2, putaway: 3, done: 4 }[s] ?? -1);
export const SHIPMENT_STEPS = [{ label: { ar: 'موعد الاستلام', en: 'Appointment' } }, { label: { ar: 'وصول + Gate', en: 'Arrival' } }, { label: { ar: 'فحص كمي ونوعي', en: 'Inspection' } }, { label: { ar: 'GRN صادر', en: 'GRN issued' } }, { label: { ar: 'مخزّن ✓', en: 'Stored ✓' } }];

/** @returns {ReceiveDraft} */
export function emptyDraft(l) { return { acceptedQty: null, damagedQty: 0, rejectedQty: 0, batchNo: l.batchNo || '', mfgDate: l.mfgDate ? l.mfgDate.slice(0, 10) : '', expiryDate: l.expiryDate ? l.expiryDate.slice(0, 10) : '', qcNote: '', extra: [] }; }
/** @returns {BatchDraft} */
export const emptyBatch = () => ({ qty: null, batchNo: '', mfgDate: '', expiryDate: '' });
/** accepted + damaged + rejected of a draft. */
export const draftSum = (d) => (Number(d.acceptedQty) || 0) + (Number(d.damagedQty) || 0) + (Number(d.rejectedQty) || 0);
/** Quantity carried by the extra batches, and what that leaves for the first batch. */
export const extraSum = (d) => (d.extra || []).reduce((a, b) => a + (Number(b.qty) || 0), 0);
export const firstBatchQty = (d) => (Number(d.acceptedQty) || 0) - extraSum(d);
/**
 * What is wrong with the batch split of a line (null = fine): every batch needs a quantity and its own number, the
 * first batch must keep something, and a product that tracks expiry needs an expiry date per batch. Mirrors the server
 * (BATCH_SPLIT_SUM / BATCH_REQUIRED / EXPIRY_REQUIRED / BATCH_SPLIT_DUPLICATE), which stays the authority.
 * @returns {{ ar: string, en: string }|null}
 */
export function splitProblem(l, d) {
  if (!d?.extra?.length) return null;
  if (d.extra.some((b) => !(Number(b.qty) > 0))) return { ar: 'أدخل كمية كل دفعة', en: 'Enter the quantity of every batch' };
  if (firstBatchQty(d) <= 0) return { ar: `كميات الدفعات الإضافية (${extraSum(d)}) يجب أن تكون أقل من الكمية المقبولة`, en: `The extra batches (${extraSum(d)}) must be less than the accepted quantity` };
  const numbers = [d.batchNo, ...d.extra.map((b) => b.batchNo)].map((x) => String(x || '').trim().toUpperCase());
  if (numbers.some((x) => !x)) return { ar: 'رقم الدفعة مطلوب لكل دفعة', en: 'Every batch needs its batch number' };
  if (new Set(numbers).size !== numbers.length) return { ar: 'رقم دفعة مكرر — اجمع كميته في صف واحد', en: 'A batch number is repeated — put its quantity in one row' };
  if (l.product.tracksExpiry && [d.expiryDate, ...d.extra.map((b) => b.expiryDate)].some((x) => !x)) return { ar: 'تاريخ الانتهاء مطلوب لكل دفعة', en: 'Every batch needs its expiry date' };
  return null;
}
/** A line is ready when its accepted quantity is entered, nothing exceeds the open quantity and its batch split is consistent. */
export const lineReady = (l, d) => !!d && d.acceptedQty != null && draftSum(d) <= l.openQty && !splitProblem(l, d);
/** Drafts keyed by lineNo → body lines of POST /inbound/shipments/:n/grn. A line with extra batches is sent with `batches` (Σ = acceptedQty). */
export const draftLines = (lines, d) => lines.map((l) => {
  const x = d[l.lineNo] || emptyDraft(l);
  const body = { lineNo: l.lineNo, acceptedQty: Number(x.acceptedQty) || 0, damagedQty: Number(x.damagedQty) || 0, rejectedQty: Number(x.rejectedQty) || 0, batchNo: x.batchNo || undefined, mfgDate: x.mfgDate || undefined, expiryDate: x.expiryDate || undefined, qcNote: x.qcNote || undefined };
  if (x.extra?.length && body.acceptedQty > 0) {
    const batch = (b, qty) => ({ qty, batchNo: String(b.batchNo || '').trim(), mfgDate: b.mfgDate || undefined, expiryDate: b.expiryDate || undefined });
    body.batches = [batch(x, firstBatchQty(x)), ...x.extra.map((b) => batch(b, Number(b.qty) || 0))];
  }
  return body;
});
/** Digits only → number, '' → null (quantity inputs of the receiving forms). */
export const parseQty = (s) => { const x = String(s ?? '').replace(/[^\d]/g, ''); return x === '' ? null : Number(x); };

/** Shipment lifecycle actions shared by the desktop + worker views. `onChanged(shipment)` runs after arrive / inspect. */
export function useShipmentActions(onChanged) {
  const act = useAction({ invalidate: ['inbound', 'procurement', 'inventory', 'dashboard'] });
  const arrive = (n, carrier) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/arrive`, { carrier: carrier || undefined }), { success: t(`سُجل وصول ${n} — Gate check`, `${n} arrived — gate check`) }).then((r) => { if (r) onChanged?.(r); return r; });
  const inspect = (n) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/inspect`), { success: t(`بدأ فحص ${n} — أدخل الكميات لكل سطر`, `Inspection of ${n} started — enter quantities per line`) }).then((r) => { if (r) onChanged?.(r); return r; });
  const postGrn = (n, lines, notes) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/grn`, { lines, notes: notes || undefined }), { success: (g) => t(`أُصدر ${g.number} — السليم في Inbound Staging بانتظار Putaway${g.putaways?.length ? ` (${g.putaways.length} مهمة)` : ''}`, `${g.number} posted — accepted goods in inbound staging awaiting putaway`) });
  const cancel = (n) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/cancel`), { success: t(`أُلغيت الشحنة ${n}`, `Shipment ${n} cancelled`) }).then((s) => { if (s) onChanged?.(s); return s; });
  /** arrived → expected: the arrival was registered by mistake. */
  const unarrive = (n) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/unarrive`), { success: t(`أُلغي تسجيل وصول ${n} — عادت «متوقعة»`, `Arrival of ${n} undone — expected again`) }).then((s) => { if (s) onChanged?.(s); return s; });
  /** The delivery is turned away at the gate (arrived / inspecting, nothing received). The purchase order stays open. */
  const refuse = (n, reason) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/refuse`, { reason }), { success: t(`رُفضت الشحنة ${n} عند البوابة — أمر الشراء ما زال مفتوحًا`, `Shipment ${n} refused at the gate — the purchase order stays open`) }).then((s) => { if (s) onChanged?.(s); return s; });
  /** Follow-up shipment for what is still due on the purchase order (after a partial receipt or a cancellation). */
  const backorder = (po, eta) => act.run(() => api.postIdempotent('/inbound/shipments/backorder', { po, eta: eta || undefined }), { success: (s) => t(`فُتحت الشحنة ${s.number} للكمية المتبقية على ${po}`, `Shipment ${s.number} opened for the remainder of ${po}`) }).then((s) => { if (s) onChanged?.(s); return s; });
  return { act, arrive, inspect, postGrn, cancel, unarrive, refuse, backorder };
}

/** The receipt in force of a shipment (a voided receipt is history). */
export const liveGrn = (s) => s?.grn ?? (s?.grns || []).filter((g) => !g.voidedAt).slice(-1)[0]?.number ?? null;

/** First value of a query-string param (vue-router may hand back an array). */
export const queryOf = (route, k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || null; };
