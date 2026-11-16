<script setup>
// New purchase return (POST /api/returns, type `sup`, taken from stock): supplier → optional purchase order / goods
// receipt → lines picked from the stock rows of that supplier's receipts (bin, batch, available) with quantity and the
// price claimed. Approval (warehouse) holds the quantity; shipping takes it out and issues the supplier debit note.
import { computed, ref } from 'vue';
import { api, useList } from '@/api/client';
import { FormDrawer } from '@/components';
import { lang, t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import ReturnStockPicker from '../returns/ReturnStockPicker.vue';
import { RETURN_REASON_LABELS } from '../returns/shared';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'done']);

const wh = useWarehouse();
const suppliers = useList('/suppliers', { pageSize: 200 }, { enabled: () => props.open });
const supplierItems = computed(() => suppliers.data.value?.items || []);
/** POs / GRNs of the chosen supplier at the chosen warehouse, handed over by the stock picker. */
const receipts = ref({ pos: [], grns: [] });
// reasons that make sense for goods going back to a supplier
const REASONS = ['quality', 'damaged', 'expired', 'wrong_product', 'wrong_qty', 'other'];

const fields = computed(() => [
  { k: 'supplierCode', label: { ar: 'المورد', en: 'Supplier' }, type: 'select', required: true, opts: supplierItems.value.map((s) => ({ v: s.code, l: `${s.nameAr} · ${s.code}` })) },
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', required: true, def: wh.current?.code, opts: wh.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` })) },
  { k: 'poNumber', label: { ar: 'أمر الشراء (اختياري)', en: 'Purchase order (optional)' }, type: 'select', ph: { ar: '— كل استلامات المورد —', en: '— every receipt of the supplier —' }, opts: receipts.value.pos.map((p) => ({ v: p.number, l: p.number })), hint: { ar: 'يحدد سعر وضريبة إشعار المدين', en: 'Sets the price and VAT of the debit note' } },
  { k: 'grnNumber', label: { ar: 'سند الاستلام (اختياري)', en: 'Goods receipt (optional)' }, type: 'select', ph: { ar: '—', en: '—' }, opts: (v) => receipts.value.grns.filter((g) => !v.poNumber || g.poNumber === v.poNumber).map((g) => ({ v: g.number, l: `${g.number} · ${g.poNumber}` })) },
  { k: 'reasonCode', label: { ar: 'سبب الإرجاع', en: 'Reason' }, type: 'select', required: true, def: 'quality', opts: REASONS.map((v) => ({ v, l: RETURN_REASON_LABELS[v] })) },
  { k: 'reference', label: { ar: 'مرجع المورد (اختياري)', en: 'Supplier reference (optional)' }, dir: 'ltr', ph: { ar: 'رقم المطالبة / الإشعار لدى المورد', en: 'Claim / RMA number at the supplier' } },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
  {
    k: 'lines', label: { ar: 'الأصناف من المخزون', en: 'Lines from stock' }, full: true, def: [],
    validate: (v) => {
      if (!(v || []).length) return t('اختر صفًّا واحدًا على الأقل من رصيد المورد', 'Pick at least one row of the supplier’s stock');
      if (v.some((r) => !(Number.isInteger(Number(r.qty)) && Number(r.qty) > 0))) return t('الكمية يجب أن تكون عددًا صحيحًا أكبر من صفر', 'Quantity must be a whole number above zero');
      if (v.some((r) => Number(r.qty) > r.available)) return t('كمية تتجاوز المتاح في موقعها', 'A quantity exceeds what is available in its bin');
      if (v.some((r) => r.unitPrice != null && r.unitPrice !== '' && Number(r.unitPrice) < 0)) return t('السعر لا يكون سالبًا', 'The price cannot be negative');
      return null;
    },
  },
]);

function submit(v) {
  const supplier = supplierItems.value.find((s) => s.code === v.supplierCode);
  return api.postIdempotent('/returns', {
    type: 'sup', source: supplier?.nameAr || v.supplierCode, supplierCode: v.supplierCode, warehouseCode: v.warehouseCode, reasonCode: v.reasonCode,
    reference: v.reference || undefined, notes: v.notes || undefined, poNumber: v.poNumber || undefined, grnNumber: v.grnNumber || undefined,
    lines: (v.lines || []).map((r) => ({ sku: r.sku, qty: Number(r.qty), fromBin: r.fromBin, batchNo: r.batchNo || undefined, unitPrice: r.unitPrice != null && r.unitPrice !== '' ? Number(r.unitPrice) : undefined })),
  });
}
const action = { success: (r) => r?.message || t(`أُنشئ مرتجع الشراء ${r?.number || ''} — بانتظار اعتماد المستودع`, `Purchase return ${r?.number || ''} created — awaiting warehouse approval`), invalidate: ['returns', 'dashboard'] };
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'مرتجع شراء جديد', en: 'New purchase return' }" :sub="{ ar: 'طلب ← اعتماد المستودع (حجز) ← شحن ← إشعار مدين', en: 'Request → warehouse approval (hold) → ship → debit note' }"
              :fields="fields" :submit="submit" :action="action" :width="620" @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-lines="{ value, set, values, error }">
      <ReturnStockPicker :model-value="value || []" :warehouse="values.warehouseCode || null" :supplier="values.supplierCode || null" needs-supplier :po="values.poNumber || null" :grn="values.grnNumber || null" priced :error="error"
                         @update:model-value="set" @receipts="receipts = $event" />
    </template>
    <template #after>
      <div class="hint teal">{{ t('الكمية تُسحب من موقعها الفعلي (رف / حجر / تالف) ولا يُنشأ رصيد في منطقة المرتجعات. عند اعتماد المستودع تُحجز، وعند الشحن تخرج من المخزون بتكلفتها ويصدر إشعار مدين على المورد بسعر الشراء وضريبة أمر الشراء — فينخفض مستحق المورد بإجمالي الإشعار.', 'The quantity is taken from its real bin (shelf / quarantine / damaged) — nothing is created in the returns area. Warehouse approval holds it; shipping takes it out at cost and issues a debit note at the purchase price with the VAT of the order — the payable to the supplier goes down by the note total.') }}</div>
    </template>
  </FormDrawer>
</template>
