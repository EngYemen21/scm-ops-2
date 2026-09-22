<script setup>
// Add vehicle (POST /transport/vehicles). Document expiry dates are mandatory: expired documents block assignment.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer, SelectInput, TextInput } from '@/components';
import { useAuth } from '@/stores/auth';
import { useGpsStatus, useGpsUnits } from './gps';
import { t } from '@/i18n';
import { OWNERSHIP_LABELS } from '@/shared';
import { VEHICLE_KIND_LABELS, optsOf } from './tms';
import { useWarehouseOptions } from './tmsComposables';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const whOpts = useWarehouseOptions();
const auth = useAuth();
const { configured: gpsConfigured } = useGpsStatus();
const units = useGpsUnits(computed(() => gpsConfigured.value && auth.can('vehicle.manage')));
const unitOpts = computed(() => (units.data.value?.units || []).map((u) => [u.uid || u.name || u.id, `${u.name}${u.uid ? ` · ${u.uid}` : ''}${u.vehicle ? ` — ${t('مقترنة بـ', 'paired to')} ${u.vehicle}` : ''}`]));
const fields = computed(() => [
  { k: 'code', label: { ar: 'رقم المركبة', en: 'Vehicle code' }, required: true, dir: 'ltr' }, { k: 'plateAr', label: { ar: 'اللوحة (عربي)', en: 'Plate (Arabic)' }, required: true }, { k: 'plateEn', label: { ar: 'اللوحة (إنجليزي)', en: 'Plate (English)' }, dir: 'ltr' },
  { k: 'vin', label: { ar: 'رقم الهيكل VIN', en: 'VIN' }, required: true, dir: 'ltr' }, { k: 'brand', label: { ar: 'الماركة', en: 'Brand' }, required: true }, { k: 'model', label: { ar: 'الموديل', en: 'Model' } }, { k: 'year', label: { ar: 'السنة', en: 'Year' }, type: 'num', min: 1990, max: 2100 },
  { k: 'kind', label: { ar: 'نوع الصندوق', en: 'Box type' }, type: 'select', def: 'dry', opts: optsOf(VEHICLE_KIND_LABELS) },
  { k: 'ownership', label: { ar: 'نوع الملكية', en: 'Ownership' }, type: 'select', def: 'owned', opts: optsOf(OWNERSHIP_LABELS) },
  { k: 'maxKg', label: { ar: 'الحمولة القصوى كجم', en: 'Max load kg' }, type: 'num', required: true, def: 3500, min: 1 }, { k: 'maxCbm', label: { ar: 'الحجم الأقصى م³', en: 'Max volume m³' }, type: 'num', required: true, def: 18, min: 0.1 }, { k: 'pallets', label: { ar: 'سعة الطبليات', en: 'Pallet capacity' }, type: 'num', def: 8, min: 1 },
  { k: 'warehouseCode', label: { ar: 'المستودع الأساسي', en: 'Home warehouse' }, type: 'select', opts: whOpts.value }, { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', def: 0, min: 0 }, { k: 'fuelType', label: { ar: 'نوع الوقود / سعة الخزان', en: 'Fuel type / tank' } },
  { k: 'regExpiry', label: { ar: 'انتهاء الاستمارة', en: 'Registration expiry' }, type: 'date', required: true }, { k: 'insuranceExpiry', label: { ar: 'انتهاء التأمين', en: 'Insurance expiry' }, type: 'date', required: true }, { k: 'inspectionExpiry', label: { ar: 'انتهاء الفحص الدوري', en: 'Inspection expiry' }, type: 'date', required: true },
  { k: 'opCardExpiry', label: { ar: 'انتهاء كرت التشغيل', en: 'Operating card expiry' }, type: 'date' }, { k: 'gpsDeviceId', label: { ar: 'معرّف جهاز GPS / المزود', en: 'GPS device / provider' }, dir: 'ltr', hint: { ar: 'التتبع الحي: Integration Pending', en: 'Live tracking: Integration Pending' } },
]);
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'إضافة مركبة', en: 'Add vehicle' }" :fields="fields" :submit="(v) => api.postIdempotent('/transport/vehicles', v)"
              :action="{ success: (r) => r?.message || t('أُضيفت المركبة', 'Vehicle added'), invalidate: ['transport'] }" @close="emit('close')">
    <template #field-gpsDeviceId="{ value, set }">
      <SelectInput v-if="gpsConfigured && unitOpts.length" :model-value="value || ''" :options="unitOpts" :label="{ ar: 'وحدة التتبع (Wialon)', en: 'Tracking unit (Wialon)' }" :placeholder="{ ar: '— بلا تتبع —', en: '— none —' }" :hint="{ ar: 'تُقرأ مواقع المركبة من هذه الوحدة', en: 'The vehicle position is read from this unit' }" @update:model-value="set" />
      <TextInput v-else :model-value="value || ''" dir="ltr" :label="{ ar: 'معرّف جهاز GPS (IMEI / اسم الوحدة)', en: 'GPS device (IMEI / unit name)' }" :hint="gpsConfigured ? { ar: 'المزود لم يُرجع وحدات — اكتب IMEI الوحدة أو اسمها', en: 'No units from the provider — type the unit IMEI or name' } : { ar: 'يُستخدم عند ربط مزود التتبع', en: 'Used once a tracking provider is connected' }" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
