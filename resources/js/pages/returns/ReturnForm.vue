<script setup>
// New return form (POST /api/returns): type, warehouse, source, reference, customer | supplier, reason code, notes, lines.
import { computed } from 'vue';
import { api, useList } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import ReturnLinesEditor from './ReturnLinesEditor.vue';
import { RETURN_REASON_LABELS, RETURN_TYPE_LABELS, useWhOpts } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Pre-selects the return type (the active tab of the list). */
  initialType: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const wh = useWarehouse();
const whOpts = useWhOpts();
const customers = useList('/customers', { pageSize: 200 }, { enabled: () => props.open });
const suppliers = useList('/suppliers', { pageSize: 200 }, { enabled: () => props.open });

const validLines = (v) => (v || []).filter((r) => r.sku?.trim());
const fields = computed(() => [
  { k: 'type', label: { ar: 'نوع المرتجع', en: 'Return type' }, type: 'select', required: true, def: props.initialType && RETURN_TYPE_LABELS[props.initialType] ? props.initialType : 'cust', opts: Object.entries(RETURN_TYPE_LABELS).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } })) },
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', required: true, opts: whOpts.value, def: wh.current?.code },
  { k: 'source', label: { ar: 'المصدر (اسم العميل / المورد / الرحلة)', en: 'Source (customer / supplier / trip)' }, required: true },
  { k: 'reference', label: { ar: 'المرجع (فاتورة / أمر بيع / أمر شراء)', en: 'Reference (invoice / SO / PO)' }, dir: 'ltr' },
  { k: 'customerCode', label: { ar: 'العميل', en: 'Customer' }, type: 'select', visible: (v) => v.type !== 'sup', ph: { ar: '— اختياري —', en: '— optional —' }, opts: (customers.data.value?.items || []).map((c) => ({ v: c.code, l: `${c.nameAr} · ${c.code}` })) },
  { k: 'supplierCode', label: { ar: 'المورد', en: 'Supplier' }, type: 'select', visible: (v) => v.type === 'sup', required: true, opts: (suppliers.data.value?.items || []).map((s) => ({ v: s.code, l: `${s.nameAr} · ${s.code}` })) },
  { k: 'reasonCode', label: { ar: 'كود السبب', en: 'Reason code' }, type: 'select', required: true, def: 'damaged', opts: Object.entries(RETURN_REASON_LABELS).map(([v, l]) => ({ v, l })) },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
  {
    k: 'lines', label: { ar: 'الأصناف', en: 'Lines' }, full: true, required: true, def: [],
    validate: (v) => {
      const rows = validLines(v);
      if (!rows.length) return t('أضف صنفًا واحدًا على الأقل', 'Add at least one line');
      if (rows.some((r) => !(Number(r.qty) > 0))) return t('الكمية يجب أن تكون أكبر من صفر', 'Quantity must be greater than zero');
      return null;
    },
  },
]);

function submit(v) {
  const lines = validLines(v.lines).map((r) => ({ sku: String(r.sku).trim(), qty: Number(r.qty), batchNo: r.batchNo?.trim() || undefined }));
  const body = { ...v, lines };
  if (!body.customerCode) delete body.customerCode;
  if (!body.supplierCode) delete body.supplierCode;
  if (!body.reference) delete body.reference;
  return api.postIdempotent('/returns', body);
}
const action = { success: (r) => r?.message || t(`أُنشئ المرتجع ${r?.number || ''}`, `Return ${r?.number || ''} created`), invalidate: ['returns', 'dashboard'] };
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'مرتجع جديد', en: 'New return' }" :sub="{ ar: 'طلب ← اعتماد ← استلام ← فحص ← قرار', en: 'Request → approve → receive → inspect → decide' }" :fields="fields" :submit="submit" :action="action"
              @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-lines="{ value, set, error }"><ReturnLinesEditor :model-value="value || []" :error="error" @update:model-value="set" /></template>
  </FormDrawer>
</template>
