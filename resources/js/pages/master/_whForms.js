// Warehouse structure: API shapes, label maps and select-option builders shared by WarehousesPage and its forms.
// The forms of the reference's `_whForms.tsx` are one file each in this folder: WarehouseForm, ZoneForm, BinForm,
// StaffForm, DockForm, MoveForm, BinStatusForm — each a FormDrawer posting to the API.
import { bi } from '@/i18n';

/**
 * @typedef {{ id: string, code: string, nameAr: string, nameEn: string, city?: string|null, type: string, areaM2?: number|null, docks: number, tempZones: string, hours?: string|null,
 *             openDate?: string|null, active: boolean, createdAt?: string, zonesCount?: number, binsCount?: number }} Warehouse
 * @typedef {{ id: string, code: string, nameAr: string, nameEn: string, type: string, pickStrategy: string, minTempC?: number|null, maxTempC?: number|null, capacityUnits?: number|null,
 *             maxKg?: number|null, active: boolean, racksCount: number, binsCount: number }} Zone
 * @typedef {Warehouse & { zones: Zone[], counts?: { zones: number, bins: number, users: number, staff: number, docks_: number, vehicles: number }, binsByStatus: Record<string, number> }} WarehouseDetail
 * @typedef {{ id: string, code: string, shelfNo?: number|null, type: string, capacityUnits?: number|null, maxKg?: number|null, status: string, fixedProductId?: string|null,
 *             zone: { id?: string, code: string, nameAr: string, nameEn?: string, type: string, pickStrategy: string }, rack?: { id: string, code: string, aisle: number, rackNo: number, levels: number }|null,
 *             fixedProduct?: { id: string, sku: string, nameAr: string, nameEn: string }|null, _count?: { balances: number } }} Bin
 * @typedef {{ id: string, who: string, role: string, zones?: string|null, shift?: string|null, fromDate?: string|null, cert?: string|null, createdAt: string }} Staff
 * @typedef {{ id: string, number: string, dock: string, type: string, reference: string, date: string, slot: string, carrier?: string|null, createdAt: string }} Dock
 * @typedef {{ zone: Zone, racksCount: number, binsCount: number, firstBin: string, lastBin: string, messageAr?: string, messageEn?: string }} ZoneCreated
 * @typedef {{ sku?: string, fromBin?: string, batchNo?: string|null, toBin?: string }} MovePreset
 */

// ───────────────────────────── labels ─────────────────────────────
export const ZONE_TYPE_LABELS = {
  ambient: { ar: 'عادي', en: 'Ambient', fg: '#654e92', bg: '#EFEAF8' }, chilled: { ar: 'مبرد +4°', en: 'Chilled +4°', fg: '#3C79F5', bg: '#e8effe' }, frozen: { ar: 'مجمد −18°', en: 'Frozen −18°', fg: '#0d5866', bg: '#d9f4f9' },
  hazmat: { ar: 'كيماويات HZ', en: 'Hazmat', fg: '#b26a16', bg: '#fbf0dd' }, quarantine: { ar: 'حجر', en: 'Quarantine', fg: '#b23b3b', bg: '#fdecec' }, staging: { ar: 'تجهيز', en: 'Staging', fg: '#0d7f93', bg: '#E4F8FB' },
  returns: { ar: 'مرتجعات', en: 'Returns', fg: '#55506a', bg: '#F1EFF6' }, damaged: { ar: 'تالف', en: 'Damaged', fg: '#b23b3b', bg: '#fdecec' },
};
export const BIN_STATUS_LABELS = {
  active: { ar: 'نشط', en: 'Active', fg: '#1d7a3e', bg: '#e6f9ec' }, blocked: { ar: 'محظور', en: 'Blocked', fg: '#b23b3b', bg: '#fdecec' }, full: { ar: 'ممتلئ', en: 'Full', fg: '#b26a16', bg: '#fbf0dd' }, inactive: { ar: 'موقوف', en: 'Inactive', fg: '#a8a4b8', bg: '#F1EFF6' },
};
export const WH_TYPE_LABELS = {
  dc: { ar: 'مركز توزيع', en: 'Distribution centre', fg: '#654e92', bg: '#EFEAF8' }, hub: { ar: 'محور', en: 'Hub', fg: '#0d7f93', bg: '#E4F8FB' }, cross: { ar: 'عبور Cross-dock', en: 'Cross-dock', fg: '#b26a16', bg: '#fbf0dd' }, cold: { ar: 'مستودع مبرد', en: 'Cold store', fg: '#3C79F5', bg: '#e8effe' },
};
export const TEMP_LABELS = { all: { ar: 'جاف + مبرد + مجمد', en: 'Dry + chilled + frozen' }, dry: { ar: 'جاف فقط', en: 'Dry only' }, cold: { ar: 'مبرد / مجمد فقط', en: 'Cold only' } };
export const PICK_LABELS = { fefo: { ar: 'FEFO — الأقرب انتهاءً أولًا', en: 'FEFO — first expiry first out' }, fifo: { ar: 'FIFO — الأقدم دخولًا أولًا', en: 'FIFO — first in first out' }, lifo: { ar: 'LIFO — الأحدث دخولًا أولًا', en: 'LIFO — last in first out' } };
export const BIN_TYPE_LABELS = { shelf: { ar: 'رف', en: 'Shelf' }, pallet: { ar: 'طبلية أرضية', en: 'Floor pallet' }, flow: { ar: 'Flow rack', en: 'Flow rack' }, bulk: { ar: 'Bulk', en: 'Bulk' }, dock: { ar: 'رصيف', en: 'Dock' }, virtual: { ar: 'افتراضي', en: 'Virtual' } };
export const STAFF_ROLE_LABELS = { picker: { ar: 'مجهّز (Picker)', en: 'Picker' }, receiver: { ar: 'مستلم', en: 'Receiver' }, putaway: { ar: 'تخزين (Putaway)', en: 'Putaway' }, packer: { ar: 'تعبئة', en: 'Packer' }, loader: { ar: 'تحميل', en: 'Loader' }, counter: { ar: 'عدّاد جرد', en: 'Counter' }, forklift: { ar: 'مشغّل رافعة', en: 'Forklift operator' }, super: { ar: 'مشرف', en: 'Supervisor' }, manager: { ar: 'مدير', en: 'Manager' } };
export const STAFF_ZONES_LABELS = { all: { ar: 'كل المناطق', en: 'All zones' }, amb: { ar: 'العادية فقط', en: 'Ambient only' }, cold: { ar: 'المبرد والمجمد', en: 'Cold chain' }, hz: { ar: 'الكيماويات HZ', en: 'Hazmat HZ' } };
export const SHIFT_LABELS = { am: { ar: 'صباحية', en: 'Morning' }, pm: { ar: 'مسائية', en: 'Evening' }, night: { ar: 'ليلية', en: 'Night' } };
export const DOCK_TYPE_LABELS = { in: { ar: 'وارد', en: 'Inbound', fg: '#0d7f93', bg: '#E4F8FB' }, out: { ar: 'صادر', en: 'Outbound', fg: '#654e92', bg: '#EFEAF8' }, ret: { ar: 'مرتجعات', en: 'Returns', fg: '#b26a16', bg: '#fbf0dd' } };
export const MOVE_REASON_LABELS = { slot: { ar: 'تخصيص موقع (Slotting)', en: 'Slotting' }, consol: { ar: 'دمج مواقع', en: 'Consolidation' }, replen: { ar: 'تعبئة موقع الالتقاط', en: 'Replenishment' }, damage: { ar: 'تالف → منطقة التالف', en: 'Damage' }, qtn: { ar: 'حجر → منطقة الحجر', en: 'Quarantine' } };

// ───────────────────────────── helpers ─────────────────────────────
/** Label of key `k` in a `{ key: { ar, en } }` map, the raw key when unknown, "—" when empty. */
export const lbl = (m, k) => (k && m[k] ? bi(m[k]) : k || '—');
/** Select options `[value, { ar, en }]` of a label map. */
export const opts = (m) => Object.entries(m).map(([v, l]) => [v, l]);
export const whOpts = (ws) => (ws || []).map((w) => ({ v: w.code, l: { ar: `${w.code} — ${w.nameAr}`, en: `${w.code} — ${w.nameEn || w.nameAr}` } }));
export const zoneOpts = (zs) => (zs || []).map((z) => ({ v: z.code, l: { ar: `${z.code} — ${z.nameAr}`, en: `${z.code} — ${z.nameEn || z.nameAr}` } }));
/** Query prefixes refreshed after a structural change. */
export const INV = ['warehouses', 'bins', 'master', 'inventory'];
