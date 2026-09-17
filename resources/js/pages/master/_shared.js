// Shared pieces of the master-data pages (Products / Product profile / Warehouses): API shapes, label maps,
// formatting helpers, lookups and select-option builders. The components that used to live in the reference's
// `_shared.tsx` are one file each in this folder: SkuPill, Tile, StockPerWarehouse, StockRows, MovementsTable,
// EditProductForm, NewProductModal, CategoryForm, ReasonForm, BarcodesPanel, SuppliersPanel, ProductDrawer.
import { useGet, useList } from '@/api/client';
import { bi, fmtNum, getLang } from '@/i18n';

// ───────────────────────────── API shapes ─────────────────────────────
/**
 * @typedef {{ id?: string, code: string, nameAr: string, nameEn?: string }} Ref
 * @typedef {Ref & { parentId?: string|null, active: boolean, parent?: Ref|null, children?: Category[], _count?: { products: number, children?: number } }} Category
 * @typedef {Ref & { parentId: string|null, active: boolean, pathAr: string, children: string[] }} LookupCategory
 * @typedef {{ warehouses: Array<Ref & { city?: string, type: string, tempZones: string, docks: number, dockList: string[], zones: Array<Ref & { type: string, pickStrategy: string }> }>,
 *             categories: LookupCategory[], uoms: Ref[], suppliers: Array<Ref & { score: number, leadDays: number, isNew: boolean, blocksPo: boolean }>,
 *             dockSlots: Array<{ key: string, label: string }> }} Lookups
 * @typedef {{ id: string, barcode: string, isPrimary: boolean, uom?: Ref|null }} Barcode
 * @typedef {{ id: string, supplierSku?: string|null, price?: number|string|null, leadDays?: number|null, preferred: boolean, supplier: Ref & { score?: number, leadDays?: number, isNew?: boolean, active?: boolean } }} SupplierLink
 * @typedef {{ id: string, sku: string, nameAr: string, nameEn: string, brand?: string|null, storageClass: 'ambient'|'chilled'|'frozen', weightKg: number, unitsPerPallet?: number|null,
 *             purchasePrice?: number|string|null, tracksExpiry: boolean, reorderMin: number, reorderMax?: number|null, lengthCm?: number|null, widthCm?: number|null, heightCm?: number|null,
 *             volumeM3?: number|null, shelfLifeDays?: number|null, preferredSupplierName?: string|null, active: boolean, createdAt?: string, updatedAt?: string,
 *             category?: Category|null, baseUom?: Ref|null, homeWarehouse?: Ref|null, primaryBarcode?: string|null, barcodes?: Barcode[], suppliers?: SupplierLink[] }} Product
 * @typedef {{ id: string, sku: string, nameAr: string, warehouse: string, zone: string, zoneType: string, rack?: string|null, bin: string, binId: string, binStatus: string, batch?: string|null,
 *             expiry?: string|null, daysToExpiry?: number|null, onHand: number, reserved: number, allocated: number, available: number, quarantine: boolean, blocked: boolean, status: string }} StockRow
 * @typedef {{ product: object, totalAvailable: number, perWarehouse: Array<{ warehouseId: string, code: string, nameAr: string, onHand: number, reserved: number, available: number, quarantine: number }>, rows: StockRow[] }} ProductStock
 * @typedef {{ id: string, number: string, type: string, qty: number, signedQty: number, batchNo?: string|null, referenceType?: string|null, referenceNumber?: string|null, note?: string|null,
 *             username?: string|null, createdAt: string, product?: { sku: string, nameAr: string }, src?: { warehouse: string, zone: string, bin: string }|null, dst?: { warehouse: string, zone: string, bin: string }|null }} Movement
 */

// ───────────────────────────── labels ─────────────────────────────
export const STORAGE_LABELS = {
  ambient: { ar: 'عادي', en: 'Ambient', fg: '#55506a', bg: '#F1EFF6' },
  chilled: { ar: 'مبرد +4°', en: 'Chilled +4°', fg: '#3C79F5', bg: '#e8effe' },
  frozen: { ar: 'مجمد −18°', en: 'Frozen −18°', fg: '#0d5866', bg: '#d9f4f9' },
};
export const BALANCE_STATUS_LABELS = {
  available: { ar: 'متاح', en: 'Available', fg: '#1d7a3e', bg: '#e6f9ec' }, quarantine: { ar: 'محجور', en: 'Quarantine', fg: '#b23b3b', bg: '#fdecec' },
  expired: { ar: 'منتهي', en: 'Expired', fg: '#b23b3b', bg: '#fdecec' }, expiring: { ar: 'قارب الانتهاء', en: 'Expiring', fg: '#b26a16', bg: '#fbf0dd' },
  zero: { ar: 'صفر', en: 'Zero', fg: '#a8a4b8', bg: '#F1EFF6' }, blocked: { ar: 'محظور', en: 'Blocked', fg: '#b23b3b', bg: '#fdecec' }, reserved: { ar: 'محجوز', en: 'Reserved', fg: '#b26a16', bg: '#fbf0dd' },
};
export const ACTIVE_LABELS = { active: { ar: 'نشط', en: 'Active', fg: '#1d7a3e', bg: '#e6f9ec' }, inactive: { ar: 'موقوف', en: 'Inactive', fg: '#b23b3b', bg: '#fdecec' } };

// ───────────────────────────── helpers ─────────────────────────────
/** Message the server sent with a mutation result (`messageAr` / `messageEn`), or the bilingual fallback. */
export const serverMsg = (d, fallback) => (getLang() === 'ar' ? d?.messageAr || d?.message : d?.messageEn || d?.messageAr || d?.message) || bi(fallback);
/** "parent ← child" path of a product category. */
export function catPath(c) {
  if (!c) return '—';
  const n = (x) => (getLang() === 'ar' ? x.nameAr : x.nameEn || x.nameAr);
  return c.parent ? `${n(c.parent)} ← ${n(c)}` : n(c);
}
/** "L×W×H" in cm, or "—" when a dimension is missing. */
export const dims = (p) => (p.lengthCm && p.widthCm && p.heightCm ? `${fmtNum(p.lengthCm, 0)}×${fmtNum(p.widthCm, 0)}×${fmtNum(p.heightCm, 0)}` : '—');
export const today = () => new Date().toISOString().slice(0, 10);
/** Wraps a left-to-right fragment (SKU, English name) in Unicode isolates — the plain-string stand-in for `<bdi dir="ltr">`. */
export const ltrText = (s) => `⁦${s ?? ''}⁩`;

/** `/master/lookups` (warehouses + zones, categories, UoMs, suppliers, dock slots), cached for a minute. */
export const useLookups = () => useGet('/master/lookups', undefined, { staleTime: 60_000 });
/** Light product list for selects (fixed bin product, move). `params` / `enabled` may be getters. */
export const useProductsList = (params, enabled = true) => useList('/products', params, { enabled });

// ───────────────────────────── select options (from lookups) ─────────────────────────────
export const storageOpts = [['ambient', STORAGE_LABELS.ambient], ['chilled', STORAGE_LABELS.chilled], ['frozen', STORAGE_LABELS.frozen]];
export const catOpts = (lk) => (lk?.categories || []).filter((c) => c.active).map((c) => ({ v: c.code, l: c.pathAr }));
export const uomOpts = (lk) => (lk?.uoms || []).map((u) => ({ v: u.code, l: { ar: `${u.nameAr} (${u.code})`, en: `${u.nameEn || u.nameAr} (${u.code})` } }));
export const lookupWhOpts = (lk) => (lk?.warehouses || []).map((w) => ({ v: w.code, l: { ar: `${w.code} — ${w.nameAr}`, en: `${w.code} — ${w.nameEn || w.nameAr}` } }));
export const supOpts = (lk) => (lk?.suppliers || []).map((s) => ({ v: s.code, l: { ar: `${s.nameAr} (${s.code})`, en: `${s.nameEn || s.nameAr} (${s.code})` } }));
