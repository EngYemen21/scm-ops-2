// Shared fulfillment building blocks (reference `pages/fulfillment/shared.tsx`): FO stepper, task labels, helpers.
// Components of the same reference file live beside this module: PickTaskCard, PackRow, FoActions.
//
// API shapes consumed here (camelCase JSON):
//   FoLine   { id, lineNo, qty, pickedQty, packedQty, shortQty, deliveredQty?, returnedQty?, product: { sku, nameAr, nameEn?, weightKg?, storageClass? } }
//   PickTask { id, seq, qty, pickedQty, status, batchNo?, pickedBy?, pickedAt?, substituted, shortReason?, note?, batchScan?,
//              bin: { code, zone?: { code, nameAr, nameEn?, type } }, product: { sku, nameAr, nameEn? }, batch?: { batchNo, expiryDate? } }
//   PickList { id, number, status, assignedTo?, assignedToId?, assignedAt?, createdAt?, completedAt?, tasks: PickTask[] }
//   Pkg      { id, number, cartons, weightKg?, volumeM3?, labelRef?, packedBy?, packedAt?, status: 'packed'|'void', voidReason? }
//   Carton   { code, seq, weightKg?, status: 'packed'|'loaded'|'delivered'|'returned', loadedAt?, deliveredAt? }
//   Fo       { number, status, cartons, weightKg, cbm, zoneAr?, loaded, loadedAt?, packedAt?, dispatchedAt?, deliveredAt?, createdAt,
//              customer: { code, nameAr, nameEn?, zone?, contact?, address? }, warehouse: { code, nameAr },
//              so?: { number, status, window?, dueDate?, priority? }, lines: FoLine[], pickLists: PickList[], packages: Pkg[],
//              trip?: { number, status, dockCode?, vehicle?: { code, plateAr?, plateEn? }, driver?: { code, nameAr } },
//              cartonList?: Carton[], stop?: { seq, trip }, pods?, returns?, history?, movements? }   (`cartons` is the COUNT)
//   PickResult { task, picked, remaining, lineDone, orderDone, movement?, message, backorder? }
import { fmtNum, lang, nm } from '@/i18n';
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
/** Why a pick could not be completed — the same four reasons for a short pick and for a batch substitution. */
export const PICK_REASONS = [
  { v: 'notfound', l: { ar: 'غير موجود في الموقع', en: 'Not at the bin' } }, { v: 'damaged', l: { ar: 'تالف', en: 'Damaged' } },
  { v: 'expired', l: { ar: 'منتهي الصلاحية', en: 'Expired' } }, { v: 'other', l: { ar: 'سبب آخر', en: 'Other' } },
];
export const reasonLabel = (k) => { const r = PICK_REASONS.find((x) => x.v === k); return r ? (lang.value === 'ar' ? r.l.ar : r.l.en) : k || ''; };
export const CARTON_LABELS = {
  packed: { ar: 'معبأ', en: 'Packed', fg: '#654e92', bg: '#f1ecf9' }, loaded: { ar: 'محمَّل', en: 'Loaded', fg: '#0d7f93', bg: '#d9f4f9' },
  delivered: { ar: 'مسلَّم', en: 'Delivered', fg: '#1d7a3e', bg: '#e6f9ec' }, returned: { ar: 'عائد', en: 'Returned', fg: '#b23b3b', bg: '#fdecec' },
};
export const PKG_LABELS = { packed: { ar: 'قائم', en: 'Live', fg: '#1d7a3e', bg: '#e6f9ec' }, void: { ar: 'مُبطل', en: 'Void', fg: '#7d7990', bg: '#F1EFF6' } };
/** Zone type → the temperature class shown in a zone header of the task list. */
export const ZONE_TYPES = {
  ambient: { ar: 'عادي', en: 'Ambient' }, chilled: { ar: 'مبرد +4°', en: 'Chilled' }, frozen: { ar: 'مجمد −18°', en: 'Frozen' }, hazmat: { ar: 'مواد خطرة', en: 'Hazmat' },
};
/** FO status history → <Timeline :items>. */
export const historyItems = (h) => (h || []).map((x) => ({ at: x.at, label: FO_LABELS[x.toStatus] ? { ar: FO_LABELS[x.toStatus].ar, en: FO_LABELS[x.toStatus].en } : x.toStatus, by: x.username, note: x.note, color: FO_LABELS[x.toStatus]?.fg }));
/** Pick progress of an FO: picked units / required units across its lines. */
export function pickProgress(fo) {
  const lines = fo?.lines || [];
  return { need: lines.reduce((s, l) => s + l.qty, 0), done: lines.reduce((s, l) => s + Math.min(l.pickedQty, l.qty), 0) };
}
/** Units of an order that could not be picked (Σ shortQty of its lines). */
export const shortUnits = (fo) => (fo?.lines || []).reduce((s, l) => s + (l.shortQty || 0), 0);

/**
 * Tasks in pick sequence, cut into runs of the same zone: `[{ zone: { code, nameAr, nameEn, type } | null, tasks: PickTask[] }]`.
 * The sequence already walks zone by zone (ambient → chilled → frozen), so each zone normally appears once.
 */
export function tasksByZone(tasks) {
  const groups = [];
  for (const task of tasks) {
    const code = task.bin?.zone?.code || '';
    const last = groups[groups.length - 1];
    if (last && (last.zone?.code || '') === code) last.tasks.push(task);
    else groups.push({ zone: task.bin?.zone || null, tasks: [task] });
  }
  return groups;
}

/**
 * One printable label per carton of a packed order, for `showLabels()`: the carton code as CODE128 (what loading and
 * delivery scan), "n / N", the customer and — when the order is on a trip — its stop.
 *   showLabels(cartonLabels({ fo: fo.number, cartons: fo.cartonList, customer: custName(fo.customer), stop: fo.stop }), fo.number)
 */
export function cartonLabels({ fo, cartons, customer, stop }) {
  const ar = lang.value === 'ar';
  const n = cartons.length;
  return cartons.map((c) => ({
    type: 'code128', text: c.code, title: customer || fo,
    sub: [ar ? `كرتون ${c.seq} / ${n}` : `Carton ${c.seq} / ${n}`, fo, stop?.seq ? (ar ? `محطة ${stop.seq}${stop.trip ? ` · ${stop.trip}` : ''}` : `Stop ${stop.seq}${stop.trip ? ` · ${stop.trip}` : ''}`) : null,
      c.weightKg ? `${fmtNum(c.weightKg, 1)} ${ar ? 'كجم' : 'kg'}` : null].filter(Boolean).join(' · '),
  }));
}
/** A label job prints at most this many labels (LabelHost): bigger orders print in ranges. */
export const LABELS_PER_JOB = 300;
/** `[[from, to], …]` (1-based, inclusive) ranges of at most LABELS_PER_JOB for n cartons. */
export function labelRanges(n) {
  const out = [];
  for (let i = 1; i <= n; i += LABELS_PER_JOB) out.push([i, Math.min(n, i + LABELS_PER_JOB - 1)]);
  return out;
}
