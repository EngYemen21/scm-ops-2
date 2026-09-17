<script setup>
// Report a vehicle breakdown (POST /transport/vehicles/:code/breakdown). When opened from a vehicle drawer the vehicle is fixed.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useVehicleOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicleCode: { type: String, default: undefined },
});
const emit = defineEmits(['close']);

const vOpts = useVehicleOpts(() => props.open);
const TYPES = [['engine', { ar: 'محرك', en: 'Engine' }], ['tire', { ar: 'إطار', en: 'Tire' }], ['elec', { ar: 'كهرباء', en: 'Electrical' }], ['ac', { ar: 'تكييف / تبريد', en: 'AC / Refrigeration' }], ['accident', { ar: 'حادث', en: 'Accident' }], ['other', { ar: 'أخرى', en: 'Other' }]];
const fields = computed(() => [
  { k: 'vehicleCode', label: { ar: 'المركبة', en: 'Vehicle' }, type: 'select', required: true, opts: vOpts.value, def: props.vehicleCode, disabled: !!props.vehicleCode },
  { k: 'type', label: { ar: 'نوع العطل', en: 'Breakdown type' }, type: 'select', def: 'other', opts: TYPES },
  { k: 'location', label: { ar: 'الموقع الحالي', en: 'Current location' }, required: true }, { k: 'desc', label: { ar: 'الوصف', en: 'Description' }, type: 'area', required: true },
  { k: 'canMove', label: { ar: 'هل تتحرك المركبة؟', en: 'Can the vehicle move?' }, type: 'select', def: 'no', opts: [['no', { ar: 'لا', en: 'No' }], ['yes', { ar: 'نعم', en: 'Yes' }]] },
]);
const submit = (v) => api.postIdempotent(`/transport/vehicles/${encodeURIComponent(String(v.vehicleCode))}/breakdown`, { ...v, canMove: v.canMove === 'yes' });
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'إبلاغ عن عطل', en: 'Report breakdown' }" :fields="fields" :submit="submit"
              :action="{ success: (r) => r?.message || t('سُجل العطل', 'Breakdown recorded'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
