<script setup>
// Add a vehicle (POST /transport/vehicles) or — with `vehicle` — edit it (PATCH /transport/vehicles/:code).
// Document expiry dates are mandatory: an expired document blocks assignment. Editing sends only what changed.
//   section 'docs' = "renew documents": the four expiry dates only.
// Odometer: a reading LOWER than the current one is a correction — the server refuses it without a written reason
// (VEHICLE_ODOMETER_LOWER), so the reason field appears as soon as the reading goes down.
// Cost card (optional): purchase cost, salvage value and useful life in km give the depreciation per km of the trip
// cost sheet; without them the fleet policy rate applies.
//   <VehicleForm :open="form === 'vehicle'" @close="form = null" />     <VehicleForm :open="!!edit" :vehicle="v" :section="edit" @close="edit = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer, SelectInput, TextInput } from '@/components';
import { useAuth } from '@/stores/auth';
import { useGpsStatus, useGpsUnits } from './gps';
import { fmtNum, t } from '@/i18n';
import { OWNERSHIP_LABELS } from '@/shared';
import { isoDay, numOrEmpty, patchBody } from './fleet';
import { VEHICLE_KIND_LABELS, optsOf } from './tms';
import { useWarehouseOptions } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The vehicle as GET /transport/vehicles/:code returns it; null = add a vehicle. */
  vehicle: { type: Object, default: null },
  /** 'all' | 'docs' (edit mode only). */
  section: { type: String, default: 'all' },
});
const emit = defineEmits(['close']);

const editing = computed(() => !!props.vehicle);
const whOpts = useWarehouseOptions();
const auth = useAuth();
const { configured: gpsConfigured } = useGpsStatus();
// the provider's units are asked for only while the form is open (the drawer keeps this form mounted on several pages)
const units = useGpsUnits(computed(() => props.open && gpsConfigured.value && auth.can('vehicle.manage')));
const unitOpts = computed(() => (units.data.value?.units || []).map((u) => [u.uid || u.name || u.id, `${u.name}${u.uid ? ` · ${u.uid}` : ''}${u.vehicle && u.vehicle !== props.vehicle?.code ? ` — ${t('مقترنة بـ', 'paired to')} ${u.vehicle}` : ''}`]));

const DOCS = [
  { k: 'regExpiry', label: { ar: 'انتهاء الاستمارة', en: 'Registration expiry' }, type: 'date', required: true }, { k: 'insuranceExpiry', label: { ar: 'انتهاء التأمين', en: 'Insurance expiry' }, type: 'date', required: true },
  { k: 'inspectionExpiry', label: { ar: 'انتهاء الفحص الدوري', en: 'Inspection expiry' }, type: 'date', required: true },
];
const COST_CARD = [
  { k: 'purchaseCost', label: { ar: 'تكلفة الشراء ر.س', en: 'Purchase cost SAR' }, type: 'num', min: 0, hint: { ar: 'اختياري — لإهلاك تكلفة الرحلة', en: 'Optional — for trip-cost depreciation' } },
  { k: 'salvageValue', label: { ar: 'قيمة الخردة ر.س', en: 'Salvage value SAR' }, type: 'num', min: 0, validate: (v, all) => (Number(v) > Number(all.purchaseCost || 0) && all.purchaseCost !== undefined && all.purchaseCost !== '' ? t('لا تتجاوز تكلفة الشراء', 'Cannot exceed the purchase cost') : null) },
  { k: 'lifeKm', label: { ar: 'العمر الافتراضي (كم)', en: 'Useful life (km)' }, type: 'num', min: 1, hint: { ar: 'الإهلاك لكل كم = (الشراء − الخردة) ÷ العمر', en: 'Depreciation per km = (purchase − salvage) ÷ life' } },
];
const lowered = (values) => editing.value && values.odometer !== undefined && values.odometer !== '' && Number(values.odometer) < Number(props.vehicle.odometer);
const fields = computed(() => {
  const opCard = { k: 'opCardExpiry', label: { ar: 'انتهاء كرت التشغيل', en: 'Operating card expiry' }, type: 'date', required: editing.value };
  if (editing.value && props.section === 'docs') return [...DOCS, opCard];
  return [
    { k: 'code', label: { ar: 'رقم المركبة', en: 'Vehicle code' }, required: true, dir: 'ltr', disabled: editing.value }, { k: 'plateAr', label: { ar: 'اللوحة (عربي)', en: 'Plate (Arabic)' }, required: true }, { k: 'plateEn', label: { ar: 'اللوحة (إنجليزي)', en: 'Plate (English)' }, dir: 'ltr' },
    { k: 'vin', label: { ar: 'رقم الهيكل VIN', en: 'VIN' }, required: true, dir: 'ltr' }, { k: 'brand', label: { ar: 'الماركة', en: 'Brand' }, required: true }, { k: 'model', label: { ar: 'الموديل', en: 'Model' } }, { k: 'year', label: { ar: 'السنة', en: 'Year' }, type: 'num', min: 1990, max: 2100 },
    { k: 'kind', label: { ar: 'نوع الصندوق', en: 'Box type' }, type: 'select', def: 'dry', opts: optsOf(VEHICLE_KIND_LABELS) },
    { k: 'ownership', label: { ar: 'نوع الملكية', en: 'Ownership' }, type: 'select', def: 'owned', opts: optsOf(OWNERSHIP_LABELS) },
    { k: 'maxKg', label: { ar: 'الحمولة القصوى كجم', en: 'Max load kg' }, type: 'num', required: true, def: 3500, min: 1 }, { k: 'maxCbm', label: { ar: 'الحجم الأقصى م³', en: 'Max volume m³' }, type: 'num', required: true, def: 18, min: 0.1 }, { k: 'pallets', label: { ar: 'سعة الطبليات', en: 'Pallet capacity' }, type: 'num', def: 8, min: 1 },
    { k: 'warehouseCode', label: { ar: 'المستودع الأساسي', en: 'Home warehouse' }, type: 'select', opts: whOpts.value },
    { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', def: 0, min: 0, required: editing.value, hint: editing.value ? { ar: `الحالية ${fmtNum(props.vehicle.odometer)} كم — قراءة أقل تحتاج سببًا`, en: `Current ${fmtNum(props.vehicle.odometer)} km — a lower reading needs a reason` } : undefined },
    { k: 'odometerReason', label: { ar: 'سبب تصحيح العداد للأدنى', en: 'Reason for lowering the odometer' }, type: 'area', required: true, visible: lowered, validate: (v) => (String(v || '').trim().length < 3 ? t('اكتب السبب (3 أحرف على الأقل)', 'Give a reason (at least 3 characters)') : null) },
    { k: 'fuelType', label: { ar: 'نوع الوقود / سعة الخزان', en: 'Fuel type / tank' } },
    ...(editing.value ? [
      { k: 'avgKmL', label: { ar: 'متوسط الاستهلاك كم/ل', en: 'Average km/L' }, type: 'num', min: 0.1, required: true, hint: { ar: 'مرجع تنبيه انحراف الوقود', en: 'Reference of the fuel-anomaly alert' } },
      { k: 'nextMaintKm', label: { ar: 'الصيانة القادمة عند كم', en: 'Next maintenance at km' }, type: 'num', min: 0 }, { k: 'nextMaintDate', label: { ar: 'الصيانة القادمة بتاريخ', en: 'Next maintenance date' }, type: 'date' },
    ] : []),
    ...DOCS, opCard,
    { k: 'gpsDeviceId', label: { ar: 'معرّف جهاز GPS / المزود', en: 'GPS device / provider' }, dir: 'ltr', hint: { ar: 'التتبع الحي: Integration Pending', en: 'Live tracking: Integration Pending' } },
    ...COST_CARD,
  ];
});

const KEYS = ['plateAr', 'plateEn', 'vin', 'brand', 'model', 'year', 'kind', 'ownership', 'maxKg', 'maxCbm', 'pallets', 'warehouseCode', 'odometer', 'fuelType', 'avgKmL', 'nextMaintKm', 'nextMaintDate',
  'regExpiry', 'insuranceExpiry', 'inspectionExpiry', 'opCardExpiry', 'gpsDeviceId', 'purchaseCost', 'salvageValue', 'lifeKm'];
const NULLABLE = ['plateEn', 'model', 'year', 'warehouseCode', 'fuelType', 'gpsDeviceId', 'purchaseCost', 'salvageValue', 'lifeKm'];
const initial = computed(() => {
  const x = props.vehicle;
  if (!x) return null;
  return {
    code: x.code, plateAr: x.plateAr, plateEn: x.plateEn || undefined, vin: x.vin, brand: x.brand, model: x.model || undefined, year: x.year ?? undefined, kind: x.kind, ownership: x.ownership,
    maxKg: x.maxKg, maxCbm: x.maxCbm, pallets: x.pallets, warehouseCode: x.warehouse?.code || undefined, odometer: x.odometer, fuelType: x.fuelType || undefined, avgKmL: x.avgKmL ?? undefined,
    nextMaintKm: x.nextMaintKm ?? undefined, nextMaintDate: isoDay(x.nextMaintDate) || undefined,
    regExpiry: isoDay(x.regExpiry), insuranceExpiry: isoDay(x.insuranceExpiry), inspectionExpiry: isoDay(x.inspectionExpiry), opCardExpiry: isoDay(x.opCardExpiry) || undefined,
    gpsDeviceId: x.gpsDeviceId || undefined, purchaseCost: numOrEmpty(x.purchaseCost), salvageValue: numOrEmpty(x.salvageValue), lifeKm: x.lifeKm ?? undefined,
  };
});
const title = computed(() => {
  if (!editing.value) return { ar: 'إضافة مركبة', en: 'Add vehicle' };
  return props.section === 'docs' ? { ar: `تجديد وثائق ${props.vehicle.code}`, en: `Renew documents of ${props.vehicle.code}` } : { ar: `تعديل المركبة ${props.vehicle.code}`, en: `Edit vehicle ${props.vehicle.code}` };
});
const sub = computed(() => (editing.value && props.section === 'docs' ? { ar: 'أي وثيقة منتهية تمنع إسناد المركبة — يُغلق تنبيهها فور حفظ التاريخ الجديد', en: 'Any expired document blocks assignment — its alert clears as soon as the new date is saved' } : null));
function submit(v) {
  if (!editing.value) return api.postIdempotent('/transport/vehicles', v);
  const shown = fields.value.map((f) => f.k); // "renew documents" shows four fields: the others are not touched (nor nulled)
  const body = patchBody(v, initial.value, KEYS.filter((k) => shown.includes(k)), NULLABLE);
  if (body.odometer !== undefined && Number(body.odometer) < Number(props.vehicle.odometer)) body.odometerReason = v.odometerReason;
  return api.patch(`/transport/vehicles/${encodeURIComponent(props.vehicle.code)}`, body);
}
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: (r) => r?.message || (editing ? t('حُفظت بيانات المركبة', 'Vehicle saved') : t('أُضيفت المركبة', 'Vehicle added')), invalidate: ['transport'] }" @close="emit('close')">
    <template #field-gpsDeviceId="{ value, set }">
      <SelectInput v-if="gpsConfigured && unitOpts.length" :model-value="value || ''" :options="unitOpts" :label="{ ar: 'وحدة التتبع (Wialon)', en: 'Tracking unit (Wialon)' }" :placeholder="{ ar: '— بلا تتبع —', en: '— none —' }" :hint="{ ar: 'تُقرأ مواقع المركبة من هذه الوحدة', en: 'The vehicle position is read from this unit' }" @update:model-value="set" />
      <TextInput v-else :model-value="value || ''" dir="ltr" :label="{ ar: 'معرّف جهاز GPS (IMEI / اسم الوحدة)', en: 'GPS device (IMEI / unit name)' }" :hint="gpsConfigured ? { ar: 'المزود لم يُرجع وحدات — اكتب IMEI الوحدة أو اسمها', en: 'No units from the provider — type the unit IMEI or name' } : { ar: 'يُستخدم عند ربط مزود التتبع', en: 'Used once a tracking provider is connected' }" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
