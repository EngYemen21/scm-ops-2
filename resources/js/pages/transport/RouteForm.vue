<script setup>
// Create (POST /transport/routes) or edit (PATCH /transport/routes/:code) a saved route — a template for new trips.
//   <RouteForm :open="form === 'route'" :route="editRoute" @close="form = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { TEMP_LABELS, optsOf } from './tms';
import { useWarehouseOptions } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Route record when editing; null = create. */
  route: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const whOpts = useWarehouseOptions();
const fields = computed(() => [
  { k: 'name', label: { ar: 'اسم المسار', en: 'Route name' }, required: true }, { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts.value },
  { k: 'zones', label: { ar: 'المناطق / الأحياء', en: 'Zones / districts' }, required: true }, { k: 'days', label: { ar: 'أيام التشغيل', en: 'Operating days' }, def: 'الأحد – الخميس' }, { k: 'window', label: { ar: 'نافذة التوصيل', en: 'Delivery window' }, def: '08:00 – 14:00', dir: 'ltr' },
  { k: 'tempNeed', label: { ar: 'متطلب الحرارة', en: 'Temperature need' }, type: 'select', def: 'dry', opts: optsOf(TEMP_LABELS) },
]);
const initial = computed(() => { const r = props.route; return r ? { name: r.name, warehouseCode: r.warehouse?.code, zones: r.zones, days: r.days, window: r.window, tempNeed: r.tempNeed } : null; });
const title = computed(() => (props.route ? { ar: `تعديل المسار ${props.route.code}`, en: `Edit route ${props.route.code}` } : { ar: 'إنشاء مسار', en: 'Create route' }));
const submit = (v) => (props.route ? api.patch(`/transport/routes/${props.route.code}`, v) : api.postIdempotent('/transport/routes', v));
</script>

<template>
  <FormDrawer :open="open" :title="title" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: (r) => r?.message || t('حُفظ المسار', 'Route saved'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
