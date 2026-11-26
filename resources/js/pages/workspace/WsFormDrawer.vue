<script setup>
// The shell of the section's hand-built forms: a drawer whose footer carries the server's refusal, the save and
// cancel buttons and — while the save button is disabled — what is still missing.
//   <WsFormDrawer :open :title="{ ar, en }" :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :why="missing"
//                 :submit-label="{ ar, en }" @submit="save" @close="…" @clear-error="act.clearError()"> fields… </WsFormDrawer>
import { computed } from 'vue';
import { Btn, Drawer, ErrorBanner } from '@/components';
import { bi, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  submitLabel: { type: [String, Object], default: () => ({ ar: 'حفظ', en: 'Save' }) },
  pending: { type: Boolean, default: false },
  error: { type: null, default: null },
  width: { type: Number, default: 560 },
  disabled: { type: Boolean, default: false },
  /** What is missing while the button is disabled (default: the required fields). */
  why: { type: [String, Object], default: null },
});
const emit = defineEmits(['close', 'submit', 'clearError']);

const auth = useAuth();
const userName = computed(() => (auth.user ? (lang.value === 'ar' ? auth.user.nameAr : auth.user.nameEn) : ''));
</script>

<template>
  <Drawer :open="open" :title="title" :sub="sub" :width="width" :z-index="70" @close="emit('close')">
    <slot />
    <template #footer>
      <ErrorBanner :error="error" @close="emit('clearError')" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="pending" :disabled="disabled" :label="submitLabel" @click="emit('submit')" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <!-- a disabled button with no reason looks broken: say what is missing -->
      <div v-if="disabled && !pending" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">
        {{ why ? bi(why) : t('أكمل الحقول الإلزامية (*) لتفعيل الزر', 'Fill in the required fields (*) to enable this button') }}
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t(`* حقول إلزامية · يُسجل الإجراء في Audit Trail باسم ${userName}`, `* required fields · the action is recorded in the audit trail as ${userName}`) }}</div>
    </template>
  </Drawer>
</template>
