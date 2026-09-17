<script setup>
// Close a maintenance order and return the vehicle to service → POST /transport/maintenance/:number/close.
//   <MaintenanceCloseModal :order="closing" @close="closing = null" />
import { ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, ErrorBanner, Modal, NumberInput, TextArea } from '@/components';
import { lang, t } from '@/i18n';
import { todayIso } from './tms';

const props = defineProps({ order: { type: Object, default: null } });
const emit = defineEmits(['close']);

const act = useAction();
const endDate = ref(todayIso());
const cost = ref(null);
const parts = ref(0);
const labor = ref(0);
const nextKm = ref(null);
const note = ref('');
// A fresh form for every order.
watch(() => props.order?.number, () => { endDate.value = todayIso(); cost.value = null; parts.value = 0; labor.value = 0; nextKm.value = null; note.value = ''; act.clearError(); });

async function submit() {
  const body = { endDate: endDate.value, cost: cost.value ?? undefined, parts: parts.value ?? 0, labor: labor.value ?? 0, nextKm: nextKm.value ?? undefined, note: note.value || undefined };
  const r = await act.run(() => api.postIdempotent(`/transport/maintenance/${props.order.number}/close`, body), { success: (x) => x?.message || t('أُقفل أمر الصيانة', 'Maintenance order closed'), invalidate: ['transport'] });
  if (r !== undefined) emit('close');
}
</script>

<template>
  <Modal :open="!!order" :width="480" @close="emit('close')">
    <template #title>
      {{ t('إقفال أمر الصيانة', 'Close maintenance order') }}
      <div v-if="order" class="drawer-sub"><span class="num">{{ order.number }}</span> · <span class="num">{{ order.vehicle?.code }}</span> · {{ (lang === 'en' && order.descEn) || order.descAr || '' }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="order" class="form-grid">
      <DateInput v-model="endDate" :label="{ ar: 'تاريخ الإنهاء', en: 'End date' }" required />
      <NumberInput v-model="cost" :label="{ ar: 'التكلفة الفعلية ر.س', en: 'Actual cost SAR' }" :min="0" :placeholder="String(order.cost ?? '')" />
      <NumberInput v-model="parts" :label="{ ar: 'قطع الغيار', en: 'Parts' }" :min="0" />
      <NumberInput v-model="labor" :label="{ ar: 'العمالة', en: 'Labor' }" :min="0" />
      <NumberInput v-model="nextKm" :label="{ ar: 'الصيانة التالية عند كم', en: 'Next maintenance at km' }" :min="0" />
      <TextArea v-model="note" :label="{ ar: 'ملاحظة', en: 'Note' }" full />
    </div>
    <template #footer>
      <div class="row">
        <Btn tone="success" class="!h-[42px] flex-1" :loading="act.pending.value" :label="{ ar: 'إقفال وإعادة المركبة للخدمة', en: 'Close & return vehicle to service' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
