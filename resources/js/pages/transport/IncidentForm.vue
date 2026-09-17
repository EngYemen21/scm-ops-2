<script setup>
// Record a driver incident / violation (POST /transport/drivers/:code/incidents). When opened from a driver drawer the driver is fixed.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { INCIDENT_TYPE_LABELS, optsOf, todayIso } from './tms';
import { useDriverOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  driverCode: { type: String, default: undefined },
});
const emit = defineEmits(['close']);

const dOpts = useDriverOpts(() => props.open);
const fields = computed(() => [
  { k: 'driverCode', label: { ar: 'السائق', en: 'Driver' }, type: 'select', required: true, opts: dOpts.value, def: props.driverCode, disabled: !!props.driverCode },
  { k: 'type', label: { ar: 'النوع', en: 'Type' }, type: 'select', required: true, def: 'speed', opts: optsOf(INCIDENT_TYPE_LABELS) },
  { k: 'date', label: { ar: 'التاريخ', en: 'Date' }, type: 'date', required: true, def: todayIso() }, { k: 'desc', label: { ar: 'الوصف', en: 'Description' }, type: 'area', required: true },
  { k: 'severity', label: { ar: 'الخطورة', en: 'Severity' }, type: 'select', def: 'low', opts: [['low', { ar: 'منخفضة', en: 'Low' }], ['med', { ar: 'متوسطة', en: 'Medium' }], ['high', { ar: 'عالية', en: 'High' }]] },
]);
function submit(v) {
  const { driverCode, ...body } = v;
  return api.postIdempotent(`/transport/drivers/${encodeURIComponent(String(driverCode))}/incidents`, body);
}
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'تسجيل حادثة / مخالفة', en: 'Record incident / violation' }" :fields="fields" :submit="submit"
              :action="{ success: (r) => r?.message || t('سُجلت الحادثة', 'Incident recorded'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
