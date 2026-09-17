// Pieces shared by the three manual stock forms (AdjustForm / MoveForm / QuarantineForm).
import { computed } from 'vue';
import { lang } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';

/** Warehouse select options of the current user: `CODE · name`. Call inside `<script setup>`. */
export function useWhOpts() {
  const wh = useWarehouse();
  return computed(() => wh.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` })));
}

/** SKU scan field; locked when the form was opened from a balance row. */
export const skuField = (locked) => ({ k: 'sku', label: { ar: 'المنتج SKU', en: 'Product SKU' }, type: 'scan', required: true, disabled: locked, dir: 'ltr' });
export const batchField = { k: 'batchNo', label: { ar: 'الدفعة (اختياري)', en: 'Batch (optional)' }, dir: 'ltr', ph: { ar: 'اتركه فارغًا لرصيد بلا دفعة', en: 'Blank = no batch' } };
/** "—" / blank batch → no batch. */
export const cleanBatch = (v) => ({ ...v, batchNo: v.batchNo && v.batchNo !== '—' ? String(v.batchNo).trim() : undefined });
