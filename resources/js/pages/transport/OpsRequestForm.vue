<script setup>
// Driver operational request (POST /transport/ops-requests) — fuel / maintenance / tire / toll / parking / emergency …
// Used by the driver app (with the current trip + vehicle) and by dispatchers from the fleet page.
// Rules: fuel / toll / parking need an amount; any amount needs a receipt (photo → attachment reference, Integration Pending).
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import FileToBase64 from './FileToBase64.vue';
import { OPREQ_TYPE_LABELS, optsOf } from './tms';

const props = defineProps({
  open: { type: Boolean, default: false },
  tripNumber: { type: String, default: undefined },
  vehicleCode: { type: String, default: undefined },
});
const emit = defineEmits(['close']);

const fields = [
  { k: 'type', label: { ar: 'نوع الطلب', en: 'Request type' }, type: 'select', required: true, opts: optsOf(OPREQ_TYPE_LABELS) },
  { k: 'amount', label: { ar: 'المبلغ ر.س', en: 'Amount SAR' }, type: 'num', def: 0, min: 0, validate: (v, all) => (['fuel', 'toll', 'parking'].includes(String(all.type)) && !(Number(v) > 0) ? t('هذا النوع يتطلب مبلغًا', 'This type requires an amount') : null) },
  { k: 'desc', label: { ar: 'الوصف', en: 'Description' }, type: 'area', required: true }, { k: 'location', label: { ar: 'الموقع الحالي', en: 'Current location' } },
  { k: 'attachment', label: { ar: 'إيصال / صورة', en: 'Receipt / photo' }, full: true, validate: (v, all) => (Number(all.amount) > 0 && !v ? t('المبلغ يتطلب إيصالًا', 'An amount requires a receipt') : null) },
];
const submit = (v) => api.postIdempotent('/transport/ops-requests', { ...v, tripNumber: props.tripNumber, vehicleCode: props.vehicleCode });
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'طلب تشغيلي — سائق', en: 'Operational request — driver' }" :fields="fields" :submit="submit"
              :action="{ success: (r) => r?.message || t('أُرسل الطلب', 'Request submitted'), invalidate: ['transport'] }" @close="emit('close')">
    <template #field-attachment="{ value, set, error }">
      <FileToBase64 :model-value="value" :label="t('إيصال / صورة', 'Receipt / photo')" :error="error" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
