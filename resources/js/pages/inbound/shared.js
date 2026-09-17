// Inbound shared helpers: labels, draft handling of the receiving form and the shipment lifecycle actions.
// Components of the domain live beside this file: ReceiveLinesGrid, ShipmentPanel, PutawayRow, PutawayList, GrnDetail, ShipmentHistory.
import { api, useAction } from '@/api/client';
import { lang, t } from '@/i18n';

/**
 * Shapes (GET /api/inbound/…):
 * @typedef {{ id, lineNo, orderedQty, acceptedQty, damagedQty, rejectedQty, previouslyReceived, openQty, batchNo?, expiryDate?, mfgDate?,
 *   suggestionAr?, suggestionEn?, suggestedBin?: { code, zone?: { code, type } }, product: { sku, nameAr, nameEn, storageClass, tracksExpiry, barcodes?: { barcode }[] } }} ShipLine
 * @typedef {{ id, number, status, eta?, carrier?, locked?, arrivedAt?, completedAt?, createdAt, po: { number, status }, supplier, warehouse,
 *   lines: ShipLine[], grns: { number, postedAt, postedBy? }[], totals: { ordered, accepted, damaged, rejected, open }, history?: object[] }} Shipment
 * @typedef {{ id, number, status: 'open'|'done', qty, batchNo?, expiryDate?, suggestionAr?, suggestionEn?, confirmedBy?, confirmedAt?,
 *   product, grn?: { number }, suggestedBin?: { code, zone? }, actualBin?: { code } }} PutawayTask
 * @typedef {{ acceptedQty: number|null, damagedQty: number|null, rejectedQty: number|null, batchNo: string, mfgDate: string, expiryDate: string, qcNote: string }} ReceiveDraft
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
export function emptyDraft(l) { return { acceptedQty: null, damagedQty: 0, rejectedQty: 0, batchNo: l.batchNo || '', mfgDate: l.mfgDate ? l.mfgDate.slice(0, 10) : '', expiryDate: l.expiryDate ? l.expiryDate.slice(0, 10) : '', qcNote: '' }; }
/** accepted + damaged + rejected of a draft. */
export const draftSum = (d) => (Number(d.acceptedQty) || 0) + (Number(d.damagedQty) || 0) + (Number(d.rejectedQty) || 0);
/** Drafts keyed by lineNo → body lines of POST /inbound/shipments/:n/grn. */
export const draftLines = (lines, d) => lines.map((l) => { const x = d[l.lineNo] || emptyDraft(l); return { lineNo: l.lineNo, acceptedQty: Number(x.acceptedQty) || 0, damagedQty: Number(x.damagedQty) || 0, rejectedQty: Number(x.rejectedQty) || 0, batchNo: x.batchNo || undefined, mfgDate: x.mfgDate || undefined, expiryDate: x.expiryDate || undefined, qcNote: x.qcNote || undefined }; });
/** Digits only → number, '' → null (quantity inputs of the receiving forms). */
export const parseQty = (s) => { const x = String(s ?? '').replace(/[^\d]/g, ''); return x === '' ? null : Number(x); };

/** Shipment lifecycle actions shared by the desktop + worker views. `onChanged(shipment)` runs after arrive / inspect. */
export function useShipmentActions(onChanged) {
  const act = useAction({ invalidate: ['inbound', 'procurement', 'inventory', 'dashboard'] });
  const arrive = (n, carrier) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/arrive`, { carrier: carrier || undefined }), { success: t(`سُجل وصول ${n} — Gate check`, `${n} arrived — gate check`) }).then((r) => { if (r) onChanged?.(r); return r; });
  const inspect = (n) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/inspect`), { success: t(`بدأ فحص ${n} — أدخل الكميات لكل سطر`, `Inspection of ${n} started — enter quantities per line`) }).then((r) => { if (r) onChanged?.(r); return r; });
  const postGrn = (n, lines, notes) => act.run(() => api.postIdempotent(`/inbound/shipments/${n}/grn`, { lines, notes: notes || undefined }), { success: (g) => t(`أُصدر ${g.number} — السليم في Inbound Staging بانتظار Putaway${g.putaways?.length ? ` (${g.putaways.length} مهمة)` : ''}`, `${g.number} posted — accepted goods in inbound staging awaiting putaway`) });
  return { act, arrive, inspect, postGrn };
}

/** First value of a query-string param (vue-router may hand back an array). */
export const queryOf = (route, k) => { const v = route.query[k]; return (Array.isArray(v) ? v[0] : v) || null; };
