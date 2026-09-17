<script setup>
// New maintenance order (POST /transport/maintenance). `block: yes` takes the vehicle out of service now (state machine on the server).
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { MAINT_KIND_LABELS, YES_NO, optsOf, todayIso } from './tms';
import { useVehicleOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicleCode: { type: String, default: undefined },
});
const emit = defineEmits(['close']);

const vOpts = useVehicleOpts(() => props.open);
const fields = computed(() => [
  { k: 'vehicleCode', label: { ar: 'المركبة', en: 'Vehicle' }, type: 'select', required: true, opts: vOpts.value, def: props.vehicleCode },
  { k: 'kind', label: { ar: 'نوع الصيانة', en: 'Maintenance type' }, type: 'select', def: 'corrective', opts: optsOf(MAINT_KIND_LABELS) },
  { k: 'desc', label: { ar: 'الوصف', en: 'Description' }, type: 'area', required: true }, { k: 'shop', label: { ar: 'الورشة', en: 'Workshop' }, required: true }, { k: 'startDate', label: { ar: 'تاريخ البدء', en: 'Start date' }, type: 'date', required: true, def: todayIso() },
  { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', min: 0 }, { k: 'cost', label: { ar: 'التكلفة التقديرية ر.س', en: 'Estimated cost SAR' }, type: 'num', def: 0, min: 0 }, { k: 'downDays', label: { ar: 'التوقف المتوقع (أيام)', en: 'Expected downtime (days)' }, type: 'num', def: 1, min: 0 },
  { k: 'nextKm', label: { ar: 'الصيانة التالية عند كم', en: 'Next maintenance at km' }, type: 'num', min: 0 }, { k: 'block', label: { ar: 'إخراج المركبة للصيانة الآن؟', en: 'Take the vehicle out now?' }, type: 'select', def: 'yes', opts: YES_NO },
]);
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'أمر صيانة جديد', en: 'New maintenance order' }" :fields="fields" :submit="(v) => api.postIdempotent('/transport/maintenance', { ...v, block: v.block === 'yes' })"
              :action="{ success: (r) => r?.message || t('أُنشئ أمر الصيانة', 'Maintenance order created'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
