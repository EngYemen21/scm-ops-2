// Shared fulfillment building blocks (reference `pages/fulfillment/shared.tsx`): FO stepper, task labels, helpers.
// Components of the same reference file live beside this module: PickTaskCard, PackRow.
//
// API shapes consumed here (camelCase JSON):
//   FoLine   { id, lineNo, qty, pickedQty, packedQty, deliveredQty?, returnedQty?, product: { sku, nameAr, nameEn?, weightKg?, storageClass? } }
//   PickTask { id, seq, qty, pickedQty, status, batchNo?, pickedBy?, pickedAt?, bin: { code, zone?: { code } }, product: { sku, nameAr, nameEn? }, batch?: { batchNo, expiryDate? } }
//   PickList { id, number, status, assignedTo?, createdAt?, completedAt?, tasks: PickTask[] }
//   Pkg      { id, number, cartons, weightKg?, volumeM3?, labelRef?, packedBy?, packedAt? }
//   Fo       { number, status, cartons, weightKg, cbm, zoneAr?, loaded, loadedAt?, packedAt?, dispatchedAt?, deliveredAt?, createdAt,
//              customer: { code, nameAr, nameEn?, zone?, contact?, address? }, warehouse: { code, nameAr },
//              so?: { number, status, window?, dueDate?, priority? }, lines: FoLine[], pickLists: PickList[], packages: Pkg[],
//              trip?: { number, status, vehicle?: { code, plateAr?, plateEn? }, driver?: { code, nameAr } }, pods?, returns?, history?, movements? }
//   PickResult { task, picked, remaining, lineDone, orderDone, movement?, message }
import { nm } from '@/i18n';
import { FO_LABELS } from '@/shared';

export const FO_STEPS = ['alloc', 'picking', 'picked', 'packed', 'loaded', 'onroute', 'delivered'];
/** Stepper position of a fulfillment-order status: `{ idx, failed }`. */
export function foStepIndex(status) {
  const map = { alloc: 0, picking: 1, picked: 2, packed: 3, loaded: 4, onroute: 5, delivered: 6, partial: 6, failed: 5, cancelled: 0 };
  return { idx: map[status] ?? 0, failed: status === 'failed' || status === 'cancelled' };
}
/** Customer / product display name in the UI language ('—' when missing). Reactive: reads `lang`. */
export const custName = (c) => (c ? nm(c) || '—' : '—');
export const prodName = (p) => (p ? nm(p) || '—' : '—');

export const TASK_LABELS = {
  open: { ar: 'مفتوح', en: 'Open', fg: '#b26a16', bg: '#fbf0dd' }, partial: { ar: 'جزئي', en: 'Partial', fg: '#b26a16', bg: '#fbf0dd' },
  done: { ar: 'تم ✓', en: 'Done ✓', fg: '#1d7a3e', bg: '#e6f9ec' }, short: { ar: 'نقص', en: 'Short', fg: '#b23b3b', bg: '#fdecec' }, cancelled: { ar: 'ملغاة', en: 'Cancelled', fg: '#7d7990', bg: '#F1EFF6' },
};
/** FO status history → <Timeline :items>. */
export const historyItems = (h) => (h || []).map((x) => ({ at: x.at, label: FO_LABELS[x.toStatus] ? { ar: FO_LABELS[x.toStatus].ar, en: FO_LABELS[x.toStatus].en } : x.toStatus, by: x.username, note: x.note, color: FO_LABELS[x.toStatus]?.fg }));
/** Pick progress of an FO: picked units / required units across its lines. */
export function pickProgress(fo) {
  const lines = fo?.lines || [];
  return { need: lines.reduce((s, l) => s + l.qty, 0), done: lines.reduce((s, l) => s + Math.min(l.pickedQty, l.qty), 0) };
}
