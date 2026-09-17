<script setup>
// New warehouse transfer (POST /api/inventory/transfers): source → destination, dates, reason, submit-for-approval flag
// and the lines picked from the available stock of the source warehouse.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import TransferLinesPicker from './TransferLinesPicker.vue';
import { TRANSFER_REASON_LABELS, useWhOpts } from './shared';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'done']);

const wh = useWarehouse();
const whOpts = useWhOpts();
const today = new Date().toISOString().slice(0, 10);

const fields = computed(() => [
  { k: 'fromWarehouseCode', label: { ar: 'من مستودع (المصدر)', en: 'From warehouse (source)' }, type: 'select', required: true, opts: whOpts.value, def: wh.current?.code },
  { k: 'toWarehouseCode', label: { ar: 'إلى مستودع (الوجهة)', en: 'To warehouse (destination)' }, type: 'select', required: true, opts: (v) => whOpts.value.filter((o) => o.v !== v.fromWarehouseCode), validate: (v, all) => (v && v === all.fromWarehouseCode ? t('المصدر والوجهة لا يمكن أن يتطابقا', 'Source and destination cannot match') : null) },
  { k: 'date', label: { ar: 'تاريخ الطلب', en: 'Request date' }, type: 'date', required: true, def: today },
  { k: 'eta', label: { ar: 'الوصول المتوقع', en: 'ETA' }, type: 'date' },
  { k: 'reasonCode', label: { ar: 'السبب', en: 'Reason' }, type: 'select', def: 'shortage', opts: Object.entries(TRANSFER_REASON_LABELS).map(([v, l]) => ({ v, l })) },
  { k: 'submit', label: { ar: 'إرسال الطلب للاعتماد مباشرة؟', en: 'Submit for approval now?' }, type: 'select', def: 'yes', opts: [{ v: 'yes', l: { ar: 'نعم — بانتظار الاعتماد', en: 'Yes — request approval' } }, { v: 'no', l: { ar: 'لا — حفظ كمسودة', en: 'No — save as draft' } }] },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area', full: true },
  {
    k: 'lines', label: { ar: 'الأصناف', en: 'Lines' }, full: true, required: true, def: [],
    validate: (v) => {
      const rows = v || [];
      if (!rows.length) return t('اختر صنفًا واحدًا على الأقل', 'Pick at least one line');
      if (rows.some((r) => !(Number(r.qty) > 0) || Number(r.qty) > r.available)) return t('تحقق من الكميات (أكبر من صفر وضمن المتاح)', 'Check quantities (> 0 and within available)');
      if (rows.some((r) => !r.toBin?.trim())) return t('موقع الوجهة مطلوب لكل سطر', 'Destination bin is required per line');
      return null;
    },
  },
]);

const submit = (v) => api.postIdempotent('/inventory/transfers', {
  fromWarehouseCode: v.fromWarehouseCode, toWarehouseCode: v.toWarehouseCode, date: v.date, eta: v.eta || undefined, reasonCode: v.reasonCode, notes: v.notes || undefined, submit: v.submit !== 'no',
  lines: (v.lines || []).map((l) => ({ sku: l.sku, qty: Number(l.qty), batchNo: l.batchNo || undefined, fromBin: l.fromBin, toBin: String(l.toBin).trim() })),
});
const action = { success: (r) => r?.message || t(`أُنشئ التحويل ${r?.number || ''}`, `Transfer ${r?.number || ''} created`), invalidate: ['inventory', 'dashboard'] };
</script>

<template>
  <FormDrawer :open="open" :width="640" :title="{ ar: 'تحويل بين مستودعات', en: 'Warehouse transfer' }" :sub="{ ar: 'مسودة ← بانتظار الاعتماد ← تجهيز ← شحن ← استلام ← إقفال', en: 'Draft → approval → picking → ship → receive → close' }" :fields="fields" :submit="submit" :action="action"
              @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-lines="{ value, set, values, error }"><TransferLinesPicker :warehouse="values.fromWarehouseCode" :model-value="value || []" :error="error" @update:model-value="set" /></template>
  </FormDrawer>
</template>
