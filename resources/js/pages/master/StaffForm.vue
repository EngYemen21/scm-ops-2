<script setup>
// Assign a worker / supervisor to a warehouse. Server rules: a forklift operator needs a licence, HZ zones need a
// chemical-safety permit.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { serverMsg, today } from './_shared';
import { SHIFT_LABELS, STAFF_ROLE_LABELS, STAFF_ZONES_LABELS, opts, whOpts } from './_whForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('./_whForms').Warehouse[]} */
  warehouses: { type: Array, default: () => [] },
  warehouseCode: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const initial = computed(() => ({ warehouseCode: props.warehouseCode || '', fromDate: today() }));
const fields = computed(() => [
  { k: 'who', label: { ar: 'الموظف', en: 'Employee' }, required: true, full: true, ph: { ar: 'الاسم أو الرقم الوظيفي', en: 'Name or employee no.' } },
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts(props.warehouses), required: true },
  { k: 'role', label: { ar: 'الدور داخل المستودع', en: 'Role' }, type: 'select', opts: opts(STAFF_ROLE_LABELS), required: true, def: 'picker' },
  { k: 'zones', label: { ar: 'المناطق المسموحة', en: 'Allowed zones' }, type: 'select', opts: opts(STAFF_ZONES_LABELS), def: 'all' }, { k: 'shift', label: { ar: 'الوردية', en: 'Shift' }, type: 'select', opts: opts(SHIFT_LABELS), def: 'am' },
  { k: 'fromDate', label: { ar: 'من تاريخ', en: 'From date' }, type: 'date', required: true },
  { k: 'cert', label: { ar: 'شهادات (رافعة / سلامة غذائية / كيماويات)', en: 'Certificates (forklift / food safety / hazmat)' }, full: true, hint: { ar: 'مشغّل الرافعة يحتاج «رخصة رافعة» · دخول HZ يحتاج «تصريح سلامة كيماويات»', en: 'Forklift needs "forklift licence" · HZ needs "chemical safety" permit' } },
]);

function submit(v) {
  const { warehouseCode: wc, ...body } = v;
  return api.postIdempotent(`/warehouses/${encodeURIComponent(String(wc))}/staff`, body);
}
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'تعيين عامل / مشرف لمستودع', en: 'Assign worker / supervisor' }" :sub="{ ar: 'مهام الـ Scan تُوجَّه له حسب مناطقه وورديته', en: 'Scan tasks are routed by zones and shift' }"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: (d) => serverMsg(d, { ar: 'تم التعيين', en: 'Assigned' }), invalidate: ['warehouses'] }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
