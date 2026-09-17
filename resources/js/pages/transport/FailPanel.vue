<script setup>
// "Unable to deliver" panel of an arrived stop: pick a reason (FAIL_REASON_LABELS) + optional details →
// POST /delivery/stops/:id/fail. The order goes back to the warehouse as a delivery return.
import { ref } from 'vue';
import { api, useAction } from '@/api/client';
import { ErrorBanner, TextArea } from '@/components';
import { bi, t } from '@/i18n';
import { FAIL_REASON_LABELS } from '@/shared';
import { BIG } from './driver';

const props = defineProps({ stop: { type: Object, required: true } });
const emit = defineEmits(['close']);

const act = useAction();
const reason = ref('');
const notes = ref('');
const fail = () => act.run(() => api.postIdempotent(`/delivery/stops/${props.stop.id}/fail`, { reason: reason.value, notes: notes.value.trim() || undefined }), { success: (r) => r?.message || t('سُجل تعذر التسليم — يُعاد الطلب للمستودع', 'Delivery failure recorded — order returns to the warehouse'), invalidate: ['delivery', 'transport', 'fulfillment', 'returns'] });
</script>

<template>
  <div class="mt-[11px] border-t border-line-2 pt-[11px]">
    <div class="row mb-1.5 justify-between"><b class="text-[11px] text-bad">{{ t('تعذر التسليم — اختر السبب', 'Unable to deliver — pick a reason') }}</b><button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button></div>
    <div class="row wrap !gap-1.5">
      <button v-for="(l, k) in FAIL_REASON_LABELS" :key="k" type="button" class="cursor-pointer rounded-full border-[1.5px] px-3 py-[7px] text-[10.5px] font-extrabold"
              :class="reason === k ? 'border-bad bg-bad text-white' : 'border-line bg-white text-sec'" @click="reason = k">{{ bi(l) }}</button>
    </div>
    <TextArea v-model="notes" :rows="2" class="mt-2" :placeholder="{ ar: 'تفاصيل (اختياري)', en: 'Details (optional)' }" />
    <ErrorBanner :error="act.error.value" class="mt-2" @close="act.clearError()" />
    <button type="button" :class="[BIG, { 'opacity-50': !reason }]" class="mt-2.5 h-[46px] w-full rounded-xl bg-bad text-[12.5px] text-white" :disabled="!reason || act.pending.value" @click="fail">{{ act.pending.value ? t('جارٍ الحفظ…', 'Saving…') : t('تأكيد الفشل', 'Confirm failure') }}</button>
  </div>
</template>
