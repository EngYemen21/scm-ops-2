<script setup>
// The shell of every form of the section: a drawer with the fields in the body and, in the footer, the error banner,
// submit / cancel and one line that says what is missing or that the act is recorded.
//   <HrFormDrawer :open :title="{ ar, en }" :sub :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
//                 :submit-label="{ ar, en }" @submit="save" @close="…" @clear-error="act.clearError()"> fields… </HrFormDrawer>
import { computed } from 'vue';
import { Btn, Drawer, ErrorBanner } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { pname } from './shared';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  submitLabel: { type: [String, Object], default: () => ({ ar: 'حفظ', en: 'Save' }) },
  submitTone: { type: String, default: 'dark' },
  pending: { type: Boolean, default: false },
  error: { type: null, default: null },
  width: { type: Number, default: 560 },
  disabled: { type: Boolean, default: false },
  /** What is still missing, shown under a disabled button; the default names the required fields. */
  missing: { type: [String, Object], default: null },
  /** Footer note; omit for the audit note, pass `false` to hide it. */
  note: { type: [String, Object, Boolean], default: null },
  /** A second action beside the main one (e.g. "save and submit"). */
  secondLabel: { type: [String, Object], default: null },
});
const emit = defineEmits(['close', 'submit', 'second', 'clearError']);

const auth = useAuth();
const userName = computed(() => (auth.user ? pname(auth.user) : ''));
</script>

<template>
  <Drawer :open="open" :title="title" :sub="sub" :width="width" :z-index="70" @close="emit('close')">
    <slot />
    <template #footer>
      <div>
        <ErrorBanner :error="error" @close="emit('clearError')" />
        <div class="flex flex-wrap gap-2">
          <Btn :tone="submitTone" class="!h-11 min-w-[140px] flex-1 !text-[11.5px]" :loading="pending" :disabled="disabled" :label="submitLabel" @click="emit('submit')" />
          <Btn v-if="secondLabel" tone="primary" class="!h-11 min-w-[140px] flex-1 !text-[11.5px]" :loading="pending" :disabled="disabled" :label="secondLabel" @click="emit('second')" />
          <Btn tone="soft" class="!h-11 !w-[96px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
        </div>
        <!-- a disabled button with no reason looks broken: say what is missing -->
        <div v-if="disabled && !pending" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">
          {{ missing ? bi(missing) : t('أكمل الحقول الإلزامية (*) لتفعيل الزر', 'Fill in the required fields (*) to enable the button') }}
        </div>
        <div v-if="note !== false" class="mt-2 text-[9px] leading-[1.7] text-faint">
          {{ note ? bi(note) : t(`* حقول إلزامية · يُسجَّل الإجراء في سجل التدقيق باسم ${userName}`, `* required fields · the action is recorded in the audit trail as ${userName}`) }}
        </div>
      </div>
    </template>
  </Drawer>
</template>
