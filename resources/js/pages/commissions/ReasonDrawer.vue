<script setup>
// A reason prompt for the steps that need one (send back, void a payout, void an adjustment, exclude an invoice,
// archive a plan). The reason is recorded in the audit trail; the server enforces the same minimum length.
//   <ReasonDrawer :ask="asking" :pending="act.pending.value" :error="act.error.value" @submit="(reason) => …" @close="asking = null" @clear-error="act.clearError()" />
//   asking = { title: { ar, en }, sub?, okLabel?, label?, min?: 5, tone?: 'dark' | 'danger' | 'success', optional?: false }
import { computed, nextTick, ref, watch } from 'vue';
import { Btn, Drawer, ErrorBanner, TextArea } from '@/components';
import { t } from '@/i18n';

const props = defineProps({
  ask: { type: Object, default: null },
  pending: { type: Boolean, default: false },
  error: { type: null, default: null },
});
const emit = defineEmits(['close', 'submit', 'clearError']);

const reason = ref('');
const box = ref(null);
const min = computed(() => (props.ask?.optional ? 0 : props.ask?.min ?? 5));
const ok = computed(() => reason.value.trim().length >= min.value);
watch(() => props.ask, async (ask) => {
  if (!ask) return;
  reason.value = '';
  await nextTick();
  box.value?.querySelector('textarea')?.focus();
});
</script>

<template>
  <Drawer :open="!!ask" :title="ask ? ask.title : null" :sub="ask ? ask.sub : null" :width="440" :z-index="80" @close="emit('close')">
    <div v-if="ask" ref="box">
      <TextArea v-model="reason" :label="ask.label || { ar: 'السبب', en: 'Reason' }" :required="!ask.optional" :rows="3"
                :hint="ask.optional ? { ar: 'اختياري — يُسجَّل في سجل التدقيق', en: 'Optional — recorded in the audit trail' } : { ar: `${min} أحرف على الأقل — يُسجَّل في سجل التدقيق`, en: `At least ${min} characters — recorded in the audit trail` }" />
    </div>
    <template #footer>
      <ErrorBanner :error="error" @close="emit('clearError')" />
      <div class="flex gap-2">
        <Btn :tone="ask && ask.tone ? ask.tone : 'dark'" class="!h-11 flex-1 !text-[11.5px]" :loading="pending" :disabled="!ok" :label="ask && ask.okLabel ? ask.okLabel : { ar: 'تأكيد', en: 'Confirm' }" @click="emit('submit', reason.trim())" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="!ok && !pending" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">{{ t(`اكتب السبب (${min} أحرف على الأقل) لتفعيل الزر`, `Type the reason (at least ${min} characters) to enable the button`) }}</div>
    </template>
  </Drawer>
</template>
