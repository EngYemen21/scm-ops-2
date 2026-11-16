<script setup>
// Record a fuel fill (POST /transport/fuel) or — with `record` — correct a posted one (PATCH /transport/fuel/:number).
// Client check: price per liter above 4 SAR is rejected as a typo; the server computes km/L and flags anomalies.
// A correction sends only what changed plus its mandatory reason; the server re-measures this fill and the next one,
// reverses and re-posts the journal when the amount / day / trip changed, and rebuilds the trips' cost sheets.
//   <FuelForm :open="form === 'fuel'" @close="form = null" />            <FuelForm :open="!!fixing" :record="fixing" @close="fixing = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { isoDay, numOrEmpty, patchBody } from './fleet';
import FuelTripField from './FuelTripField.vue';
import { YES_NO, todayIso } from './tms';
import { useDriverOpts, useVehicleOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicleCode: { type: String, default: undefined },
  /** A fuel list row (GET /transport/fuel) to correct; null = a new fill. */
  record: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const editing = computed(() => !!props.record);
const vOpts = useVehicleOpts(() => props.open && !editing.value);
const dOpts = useDriverOpts(() => props.open && !editing.value);
const priceCheck = (v, all) => (Number(v) && Number(all.liters) && Number(v) / Number(all.liters) > 4 ? t('سعر اللتر يتجاوز 4 ر.س — تحقق', 'Price per liter exceeds 4 SAR — check') : null);
const TRIP = { k: 'tripNumber', label: { ar: 'الرحلة (اختياري)', en: 'Trip (optional)' } };
const fields = computed(() => (editing.value ? [
  { k: 'date', label: { ar: 'التاريخ', en: 'Date' }, type: 'date', required: true },
  { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', required: true, min: 0, hint: { ar: 'لا تقل عن قراءة التعبئة السابقة ولا تزيد على التالية', en: 'Not below the previous fill nor above the next one' } },
  { k: 'liters', label: { ar: 'اللترات', en: 'Liters' }, type: 'num', min: 0.1 },
  { k: 'cost', label: { ar: 'التكلفة ر.س', en: 'Cost SAR' }, type: 'num', required: true, min: 0.1, validate: priceCheck },
  { k: 'station', label: { ar: 'المحطة', en: 'Station' } }, TRIP,
  { k: 'reason', label: { ar: 'سبب التصحيح', en: 'Correction reason' }, type: 'area', required: true, validate: (v) => (String(v || '').trim().length < 3 ? t('اكتب السبب (3 أحرف على الأقل)', 'Give a reason (at least 3 characters)') : null) },
] : [
  { k: 'vehicleCode', label: { ar: 'المركبة', en: 'Vehicle' }, type: 'select', required: true, opts: vOpts.value, def: props.vehicleCode }, { k: 'driverCode', label: { ar: 'السائق', en: 'Driver' }, type: 'select', opts: dOpts.value },
  { k: 'date', label: { ar: 'التاريخ', en: 'Date' }, type: 'date', required: true, def: todayIso() }, { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', required: true, min: 0 },
  { k: 'liters', label: { ar: 'اللترات', en: 'Liters' }, type: 'num', required: true, min: 0.1 },
  { k: 'cost', label: { ar: 'التكلفة ر.س', en: 'Cost SAR' }, type: 'num', required: true, min: 0.1, validate: priceCheck },
  { k: 'station', label: { ar: 'المحطة', en: 'Station' } }, TRIP, { k: 'full', label: { ar: 'تعبئة كاملة؟', en: 'Full tank?' }, type: 'select', def: 'yes', opts: YES_NO }, { k: 'receipt', label: { ar: 'رقم الإيصال / مرفق', en: 'Receipt no. / attachment' }, dir: 'ltr' },
]));
const initial = computed(() => {
  const r = props.record;
  return r ? { date: isoDay(r.date), odometer: r.odometer, liters: numOrEmpty(r.liters) || undefined, cost: numOrEmpty(r.cost), station: r.station || undefined, tripNumber: r.tripNumber || undefined } : null;
});
const title = computed(() => (editing.value ? { ar: `تصحيح سجل الوقود ${props.record.number}`, en: `Correct fuel record ${props.record.number}` } : { ar: 'تسجيل تعبئة وقود', en: 'Record fuel' }));
const sub = computed(() => (editing.value ? { ar: `${props.record.vehicle?.code || ''} — يُعكس القيد ويُعاد ترحيله عند تغيير المبلغ أو اليوم أو الرحلة`, en: `${props.record.vehicle?.code || ''} — the journal is reversed and re-posted when the amount, day or trip changes` } : null));
const note = computed(() => (editing.value ? { ar: '* إلزامي · لا يُحذف السجل: يُحفظ التصحيح وسببه في سجل التدقيق ويُعاد قياس كم/ل لهذه التعبئة وللتي تليها', en: '* required · nothing is deleted: the correction and its reason go to the audit trail, and km/L is re-measured for this fill and the next' } : null));
const submit = (v) => (editing.value
  ? api.patch(`/transport/fuel/${props.record.number}`, { ...patchBody(v, initial.value, ['date', 'odometer', 'liters', 'cost', 'station', 'tripNumber'], ['station', 'tripNumber']), reason: v.reason })
  : api.postIdempotent('/transport/fuel', { ...v, full: v.full !== 'no' }));
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit" :note="note" :submit-label="editing ? { ar: 'حفظ التصحيح', en: 'Save correction' } : null"
              :action="{ success: (r) => t(r?.message, r?.messageEn || r?.message) || t('سُجلت التعبئة', 'Fuel recorded'), invalidate: ['transport', 'finance'] }" @close="emit('close')">
    <template #field-tripNumber="{ value, set, values }">
      <FuelTripField :vehicle-code="(editing ? record.vehicle?.code : values.vehicleCode) || ''" :model-value="value || ''" :current="record?.tripNumber || ''" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
