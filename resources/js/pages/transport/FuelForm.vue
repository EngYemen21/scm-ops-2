<script setup>
// Record a fuel fill (POST /transport/fuel). Client check: price per liter above 4 SAR is rejected as a typo; the server
// computes km/L and flags anomalies.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { YES_NO, todayIso } from './tms';
import { useDriverOpts, useVehicleOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicleCode: { type: String, default: undefined },
});
const emit = defineEmits(['close']);

const vOpts = useVehicleOpts(() => props.open);
const dOpts = useDriverOpts(() => props.open);
const fields = computed(() => [
  { k: 'vehicleCode', label: { ar: 'المركبة', en: 'Vehicle' }, type: 'select', required: true, opts: vOpts.value, def: props.vehicleCode }, { k: 'driverCode', label: { ar: 'السائق', en: 'Driver' }, type: 'select', opts: dOpts.value },
  { k: 'date', label: { ar: 'التاريخ', en: 'Date' }, type: 'date', required: true, def: todayIso() }, { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', required: true, min: 0 },
  { k: 'liters', label: { ar: 'اللترات', en: 'Liters' }, type: 'num', required: true, min: 0.1 },
  { k: 'cost', label: { ar: 'التكلفة ر.س', en: 'Cost SAR' }, type: 'num', required: true, min: 0.1, validate: (v, all) => (Number(v) && Number(all.liters) && Number(v) / Number(all.liters) > 4 ? t('سعر اللتر يتجاوز 4 ر.س — تحقق', 'Price per liter exceeds 4 SAR — check') : null) },
  { k: 'station', label: { ar: 'المحطة', en: 'Station' } }, { k: 'full', label: { ar: 'تعبئة كاملة؟', en: 'Full tank?' }, type: 'select', def: 'yes', opts: YES_NO }, { k: 'receipt', label: { ar: 'رقم الإيصال / مرفق', en: 'Receipt no. / attachment' }, dir: 'ltr' },
]);
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'تسجيل تعبئة وقود', en: 'Record fuel' }" :fields="fields" :submit="(v) => api.postIdempotent('/transport/fuel', { ...v, full: v.full !== 'no' })"
              :action="{ success: (r) => r?.message || t('سُجلت التعبئة', 'Fuel recorded'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
