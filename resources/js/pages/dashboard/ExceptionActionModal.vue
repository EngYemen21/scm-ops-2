<script setup>
// Note modal for `POST /exceptions/:number/ack|resolve {note}`. Shows the server's message; invalidates every
// query that shows exceptions. Open when both `mode` and `number` are set (see `useExceptionActions` in shared.js).
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, TextArea } from '@/components';
import { t } from '@/i18n';

const props = defineProps({
  /** 'ack' | 'resolve' | null */
  mode: { type: String, default: null },
  number: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const note = ref('');
const open = computed(() => !!props.mode && !!props.number);
// A note typed for one exception never leaks into the next one.
watch(() => [props.mode, props.number], () => { note.value = ''; act.clearError(); });
const title = computed(() => (props.mode === 'ack' ? t('استلام الاستثناء', 'Acknowledge exception') : t('إغلاق الاستثناء', 'Resolve exception')));

function close() { act.clearError(); emit('close'); }
async function submit() {
  const { mode, number } = props;
  if (!number || !mode) return;
  const text = note.value.trim();
  const r = await act.run(() => api.postIdempotent(`/exceptions/${encodeURIComponent(number)}/${mode}`, text ? { note: text } : {}), {
    success: (d) => d?.message || (mode === 'ack' ? t('تم استلام الاستثناء — قيد المعالجة', 'Acknowledged') : t('تم إغلاق الاستثناء ✓', 'Resolved ✓')),
    invalidate: ['exceptions', 'tower', 'dashboard', 'activity', 'notifications'],
  });
  if (r !== undefined) { note.value = ''; emit('done'); emit('close'); }
}
</script>

<template>
  <Modal :open="open" :title="title" :sub="number" :width="460" @close="close">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <TextArea v-model="note" :rows="4" :label="mode === 'resolve' ? { ar: 'القرار / الحل', en: 'Resolution' } : { ar: 'ملاحظة', en: 'Note' }" :placeholder="{ ar: 'اختياري — يُسجل في سجل الاستثناء', en: 'Optional — recorded on the exception' }" />
    <template #footer>
      <div class="flex gap-2">
        <Btn :tone="mode === 'resolve' ? 'success' : 'dark'" class="!h-[42px] flex-1" :loading="act.pending.value" :label="mode === 'ack' ? { ar: 'استلام', en: 'Acknowledge' } : { ar: 'إغلاق', en: 'Resolve' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="close" />
      </div>
    </template>
  </Modal>
</template>
