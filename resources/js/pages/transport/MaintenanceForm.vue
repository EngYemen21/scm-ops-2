<script setup>
// New maintenance order (POST /transport/maintenance) or — with `order` — edit an OPEN one (PATCH /transport/maintenance/:number).
// `block: yes` takes the vehicle out of service now (state machine on the server). A closed order has posted its cost
// and a cancelled one is final: the server refuses to edit either (MAINT_NOT_OPEN).
//   <MaintenanceForm :open="form === 'maint'" @close="form = null" />      <MaintenanceForm :open="!!editing" :order="editing" @close="editing = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { isoDay, numOrEmpty, patchBody } from './fleet';
import { MAINT_KIND_LABELS, YES_NO, optsOf, todayIso } from './tms';
import { useVehicleOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  vehicleCode: { type: String, default: undefined },
  /** A maintenance list row to edit (open orders only); null = a new order. */
  order: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const editing = computed(() => !!props.order);
const vOpts = useVehicleOpts(() => props.open && !editing.value);
const PLAN = [
  { k: 'kind', label: { ar: 'نوع الصيانة', en: 'Maintenance type' }, type: 'select', def: 'corrective', opts: optsOf(MAINT_KIND_LABELS) },
  { k: 'desc', label: { ar: 'الوصف', en: 'Description' }, type: 'area', required: true }, { k: 'shop', label: { ar: 'الورشة', en: 'Workshop' }, required: true }, { k: 'startDate', label: { ar: 'تاريخ البدء', en: 'Start date' }, type: 'date', required: true, def: todayIso() },
  { k: 'odometer', label: { ar: 'قراءة العداد', en: 'Odometer' }, type: 'num', min: 0 }, { k: 'cost', label: { ar: 'التكلفة التقديرية ر.س', en: 'Estimated cost SAR' }, type: 'num', def: 0, min: 0 }, { k: 'downDays', label: { ar: 'التوقف المتوقع (أيام)', en: 'Expected downtime (days)' }, type: 'num', def: 1, min: 0 },
  { k: 'nextKm', label: { ar: 'الصيانة التالية عند كم', en: 'Next maintenance at km' }, type: 'num', min: 0 },
];
const fields = computed(() => (editing.value ? PLAN : [
  { k: 'vehicleCode', label: { ar: 'المركبة', en: 'Vehicle' }, type: 'select', required: true, opts: vOpts.value, def: props.vehicleCode },
  ...PLAN,
  { k: 'block', label: { ar: 'إخراج المركبة للصيانة الآن؟', en: 'Take the vehicle out now?' }, type: 'select', def: 'yes', opts: YES_NO },
]));
const KEYS = ['kind', 'desc', 'shop', 'startDate', 'odometer', 'cost', 'downDays', 'nextKm'];
const initial = computed(() => {
  const m = props.order;
  return m ? { kind: m.kind, desc: m.descAr || '', shop: m.shop || undefined, startDate: isoDay(m.startDate), odometer: m.odometer ?? undefined, cost: numOrEmpty(m.cost) ?? 0, downDays: m.downDays ?? undefined, nextKm: m.nextKm ?? undefined } : null;
});
const title = computed(() => (editing.value ? { ar: `تعديل أمر الصيانة ${props.order.number}`, en: `Edit maintenance order ${props.order.number}` } : { ar: 'أمر صيانة جديد', en: 'New maintenance order' }));
const sub = computed(() => (editing.value ? { ar: `${props.order.vehicle?.code || ''} — الأمر مفتوح: التكلفة النهائية تُدخل وتُرحَّل عند الإقفال`, en: `${props.order.vehicle?.code || ''} — open order: the final cost is entered and posted at close` } : null));
const submit = (v) => (editing.value
  ? api.patch(`/transport/maintenance/${props.order.number}`, patchBody(v, initial.value, KEYS, ['odometer', 'nextKm']))
  : api.postIdempotent('/transport/maintenance', { ...v, block: v.block === 'yes' }));
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: (r) => t(r?.message, r?.messageEn || r?.message) || t('حُفظ أمر الصيانة', 'Maintenance order saved'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
