// Shared helpers for the inventory pages (balances / ledger / batches / counts / transfers):
// status dictionaries that the prototype computed inline, formatting helpers and a debounce composable.
// The small presentational bits of the reference `_shared.tsx` live beside this file:
// Hint.vue · Tile.vue · DaysChip.vue · ProductCell.vue · FlagChips.vue.
import { onScopeDispose, ref, toValue, watch } from 'vue';
import { fmtNum, lang } from '@/i18n';

// ───────────── dictionaries (status key → { ar, en, fg, bg }) ─────────────
export const BALANCE_STATUS = {
  available: { ar: 'سليم', en: 'OK', fg: '#1d7a3e', bg: '#e6f9ec' },
  quarantine: { ar: 'محجور', en: 'Quarantine', fg: '#b23b3b', bg: '#fdecec' },
  expired: { ar: 'منتهي الصلاحية', en: 'Expired', fg: '#b23b3b', bg: '#fdecec' },
  expiring: { ar: 'قريب الانتهاء', en: 'Expiring soon', fg: '#b26a16', bg: '#fbf0dd' },
  zero: { ar: 'نافد', en: 'Out of stock', fg: '#b23b3b', bg: '#fdecec' },
};
export const FLAG_LABELS = {
  quarantine: BALANCE_STATUS.quarantine,
  blocked: { ar: 'مجمد — جرد جارٍ', en: 'Frozen — count', fg: '#654e92', bg: '#efeaf8' },
  expired: BALANCE_STATUS.expired,
  expiring: BALANCE_STATUS.expiring,
  zero: BALANCE_STATUS.zero,
  staging: { ar: 'Staging', en: 'Staging', fg: '#654e92', bg: '#efeaf8' },
  returns: { ar: 'منطقة مرتجعات', en: 'Returns zone', fg: '#0d7f93', bg: '#d9f4f9' },
  damaged: { ar: 'منطقة تالف', en: 'Damaged zone', fg: '#b23b3b', bg: '#fdecec' },
  hazmat: { ar: 'مواد خطرة', en: 'Hazmat', fg: '#b26a16', bg: '#fbf0dd' },
};
export const BATCH_STATUS = {
  ok: { ar: 'سليمة', en: 'OK', fg: '#1d7a3e', bg: '#e6f9ec' },
  expiring: { ar: 'قريبة الانتهاء', en: 'Expiring soon', fg: '#b26a16', bg: '#fbf0dd' },
  critical: { ar: 'حرجة — تُصرف أولًا', en: 'Critical — ship first', fg: '#b23b3b', bg: '#fdecec' },
  expired: { ar: 'منتهية', en: 'Expired', fg: '#b23b3b', bg: '#fdecec' },
  quarantine: { ar: 'محجورة — قرار معالجة', en: 'Quarantined', fg: '#b23b3b', bg: '#fdecec' },
};
export const COUNT_LABELS = {
  open: { ar: 'مجدول — جاهز للبدء', en: 'Scheduled — ready', fg: '#3C79F5', bg: '#e8effe' },
  counting: { ar: 'العد جارٍ — الحركات مجمدة', en: 'Counting — frozen', fg: '#b26a16', bg: '#fbf0dd' },
  variance: { ar: 'فروقات بانتظار الاعتماد', en: 'Variance review', fg: '#b23b3b', bg: '#fdecec' },
  adjusted: { ar: 'مسوّى ✓', en: 'Adjusted ✓', fg: '#1d7a3e', bg: '#e6f9ec' },
  closed: { ar: 'مُغلق', en: 'Closed', fg: '#55506a', bg: '#F1EFF6' },
};
export const COUNT_TYPES = { cycle: { ar: 'جرد دوري Cycle Count', en: 'Cycle count' }, full: { ar: 'جرد شامل', en: 'Full count' }, spot: { ar: 'جرد موضعي', en: 'Spot count' }, abc: { ar: 'جرد ABC', en: 'ABC count' } };
export const COUNT_SCOPES = { zone: { ar: 'منطقة محددة', en: 'One zone' }, all: { ar: 'كل المستودع', en: 'Whole warehouse' }, abc: { ar: 'أصناف A (أعلى 20% قيمة)', en: 'A items (top 20% value)' }, neg: { ar: 'مواقع بفروقات سابقة', en: 'Previous variances' }, exp: { ar: 'دفعات قريبة الانتهاء', en: 'Expiring batches' }, random: { ar: 'عشوائي — 20 موقعًا', en: 'Random — 20 rows' } };
export const MOVE_REASONS = { slot: { ar: 'إعادة توزيع Slotting', en: 'Slotting' }, consol: { ar: 'دمج مواقع', en: 'Consolidation' }, replen: { ar: 'تغذية منطقة الصرف', en: 'Replenishment' }, damage: { ar: 'عزل تالف', en: 'Isolate damaged' }, qtn: { ar: 'نقل للحجر', en: 'Move to quarantine' } };
export const STORAGE_LABELS = { ambient: { ar: 'جاف', en: 'Ambient' }, chilled: { ar: 'مبرد', en: 'Chilled' }, frozen: { ar: 'مجمد', en: 'Frozen' } };
export const DIRECTION_LABELS = { in: { ar: 'وارد', en: 'Inbound', fg: '#1d7a3e', bg: '#e6f9ec' }, out: { ar: 'صادر', en: 'Outbound', fg: '#3C79F5', bg: '#e8effe' } };

// ───────────── API row shapes (subset we render) ─────────────
/**
 * @typedef {{ warehouse: string, zone: string, zoneType?: string, bin: string }} Loc
 * @typedef {{ id: string, number: string, type: string, qty: number, signedQty: number, beforeQty: number|null, afterQty: number|null, batchNo: string|null,
 *   referenceType: string|null, referenceNumber: string|null, referenceId: string|null, transactionId: string|null, username: string|null, note: string|null,
 *   createdAt: string, product: { sku: string, nameAr: string, nameEn: string }, src: Loc|null, dst: Loc|null }} Movement
 * @typedef {{ id: string, sku: string, nameAr: string, nameEn: string, storageClass: string, category: { code: string, nameAr: string, nameEn: string }|null,
 *   warehouse: string, zone: string, zoneType: string, rack: string|null, bin: string, binStatus: string, batch: string|null, expiry: string|null, daysToExpiry: number|null,
 *   onHand: number, reserved: number, allocated: number, available: number, quarantine: boolean, blocked: boolean, flags: string[], status: string, updatedAt: string }} BalanceRow
 * @typedef {{ sku: string, warehouse?: string, bin?: string, batch?: string|null }} StockFocus   what the stock drawer is opened on
 * @typedef {{ sku?: string, warehouseCode?: string, binCode?: string, batchNo?: string|null, quarantine?: boolean }} StockRef   initial values of the stock forms
 */

// ───────────── formatting ─────────────
/** Name of a record with nameAr / nameEn in the current language (reactive when used in a template / computed). */
export const pname = (p, l = lang.value) => (p ? (l === 'ar' ? p.nameAr || p.nameEn : p.nameEn || p.nameAr) || '' : '');
/** Label of a plain `{ ar, en }` dictionary entry, falling back to the raw key. */
export const dictLabel = (dict, k, l = lang.value) => (dict[k] ? (l === 'ar' ? dict[k].ar : dict[k].en) : k);
export const fmtSigned = (n) => (n == null ? '—' : n > 0 ? `+${fmtNum(n)}` : fmtNum(n));
export const signColor = (n) => (n == null ? '#a8a4b8' : n > 0 ? '#1d7a3e' : n < 0 ? '#b23b3b' : '#654e92');
export const locLabel = (l) => (l ? `${l.warehouse}/${l.bin}` : '—');
export const movementDoc = (m) => m.referenceNumber || m.referenceType || '—';
export const daysColor = (d) => (d == null ? '#7d7990' : d < 0 ? '#b23b3b' : d <= 7 ? '#b23b3b' : d <= 30 ? '#b26a16' : '#1d7a3e');
/** Batch chip key from days-left + quarantine (quarantined → critical ≤7 → expiring ≤30 → ok). */
export const batchStatusKey = (days, quarantined, expDays = 30) => (quarantined ? 'quarantine' : days == null ? 'ok' : days < 0 ? 'expired' : days <= 7 ? 'critical' : days <= expDays ? 'expiring' : 'ok');
/** Options for SelectInput / PillChoice from a `{ key: {ar,en} }` dictionary. */
export const dictOptions = (dict) => Object.entries(dict).map(([v, l]) => ({ v, l }));
/** `[{ k, label }]` tabs for `<Tabs variant="pill">` from `[{ k, ar, en }]`. */
export const pillTabs = (pills) => pills.map((p) => ({ k: p.k, label: { ar: p.ar, en: p.en } }));

/**
 * Debounced copy of a value (search inputs → server query). `source` is a ref or a getter; returns a ref.
 *   const dq = useDebounced(q);   useList(path, () => ({ q: dq.value }))
 */
export function useDebounced(source, ms = 350) {
  const out = ref(toValue(source));
  let h;
  watch(() => toValue(source), (v) => { clearTimeout(h); h = setTimeout(() => { out.value = v; }, ms); });
  onScopeDispose(() => clearTimeout(h));
  return out;
}
