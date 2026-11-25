<script setup>
// Links a ticket, a task or a document to another record of OPS by what people call it: its kind and its number
// (a sales order SO-…, a purchase order, a trip, a customer code, a product SKU…). The pair is looked up while it is
// typed (GET /workspace/records?type=&number=) so the form says at once whether such a record exists; the server
// checks again on save. Both parts or neither: half a link is not sent.
//   <RecordLinkField v-model:type="f.refType" v-model:number="f.refNumber" :types="lookups.recordTypes" />
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useGet } from '@/api/client';
import { ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { entityPath } from '@/router/routes';
import { API, arr } from './workspace';

const props = defineProps({
  type: { type: String, default: '' },
  number: { type: String, default: '' },
  /** lookups.recordTypes: [{ key, ar, en }] */
  types: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:type', 'update:number']);

const options = computed(() => arr(props.types).map((x) => ({ v: x.key, l: { ar: x.ar, en: x.en } })));
const half = computed(() => !!props.type !== !!String(props.number || '').trim());

/** The pair that is looked up: set a moment after the typing stops. */
const probe = ref(null);
let timer = null;
watch(() => [props.type, String(props.number || '').trim()], ([type, number]) => {
  clearTimeout(timer);
  if (!type || !number) { probe.value = null; return; }
  timer = setTimeout(() => { probe.value = { type, number }; }, 400);
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

const found = useGet(() => (probe.value ? `${API}/records` : null), () => probe.value);
const answer = computed(() => (probe.value && found.data.value && found.data.value.number === probe.value.number && found.data.value.type === probe.value.type ? found.data.value : null));
const link = computed(() => (answer.value?.found ? entityPath(answer.value.type, answer.value.number) : null));
</script>

<template>
  <div class="field full">
    <div class="field-l">{{ t('ربط بسجل في النظام', 'Link to an OPS record') }}</div>
    <div class="form-grid">
      <SelectInput :model-value="type" :options="options" :placeholder="{ ar: '— بلا ربط —', en: '— no link —' }" :disabled="disabled" @update:model-value="emit('update:type', $event)" />
      <TextInput :model-value="number" dir="ltr" mono scan :disabled="disabled || !type" :placeholder="{ ar: 'رقم السجل أو رمزه', en: 'Number or code' }" @update:model-value="emit('update:number', $event)" />
    </div>
    <div v-if="half" class="field-err">{{ t('اختر نوع السجل واكتب رقمه معًا، أو اتركهما فارغين.', 'Pick the kind of record and type its number together, or leave both empty.') }}</div>
    <ErrorBanner v-else-if="found.error.value" :error="found.error.value" :closable="false" class="!mb-0 mt-1" />
    <div v-else-if="probe && found.isFetching.value && !answer" class="field-hint">{{ t('جارٍ التحقق…', 'Checking…') }}</div>
    <div v-else-if="answer && answer.found" class="text-[10px] font-bold leading-[1.7] text-ok">
      ✓ {{ t(answer.labelAr, answer.labelEn) }} <span class="num ltr inline-block">{{ answer.number }}</span><span v-if="answer.title" class="font-normal text-sec" dir="auto"> — {{ answer.title }}</span>
      <RouterLink v-if="link" :to="link" target="_blank" class="ms-1 font-extrabold">{{ t('فتح', 'Open') }}</RouterLink>
    </div>
    <div v-else-if="answer" class="field-err">{{ t('لا يوجد سجل بهذا النوع والرقم — راجع الرقم قبل الحفظ.', 'No record of this kind has that number — check it before saving.') }}</div>
  </div>
</template>
