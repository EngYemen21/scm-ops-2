<script setup>
// Reason prompt (reject / cancel): at least 3 characters, recorded in the audit trail.
//   <ReasonDrawer :open :title="{ ar, en }" :sub :ok-label :pending="act.pending.value" :error="act.error.value"
//                 @submit="(reason) => …" @close="…" @clear-error="act.clearError()" />
import { nextTick, ref, watch } from 'vue';
import { TextArea } from '@/components';
import ActionDrawer from './ActionDrawer.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  okLabel: { type: [String, Object], default: () => ({ ar: 'تأكيد', en: 'Confirm' }) },
  pending: { type: Boolean, default: false },
  error: { type: null, default: null },
});
const emit = defineEmits(['close', 'submit', 'clearError']);

const reason = ref('');
const box = ref(null);
watch(() => props.open, async (open) => {
  if (!open) return;
  reason.value = '';
  await nextTick();
  box.value?.querySelector('textarea')?.focus();
}, { immediate: true });
</script>

<template>
  <ActionDrawer :open="open" :title="title" :sub="sub" :width="440" :pending="pending" :error="error" :disabled="reason.trim().length < 3" :submit-label="okLabel"
                @submit="emit('submit', reason.trim())" @close="emit('close')" @clear-error="emit('clearError')">
    <div ref="box">
      <TextArea v-model="reason" :label="{ ar: 'السبب', en: 'Reason' }" required :rows="3" :hint="{ ar: '3 أحرف على الأقل — يُسجَّل في Audit Trail', en: 'At least 3 characters — recorded in the Audit Trail' }" />
    </div>
  </ActionDrawer>
</template>
