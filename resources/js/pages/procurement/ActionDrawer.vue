<script setup>
// Generic action drawer for the custom forms of this domain (forms with line editors): body slot + footer with
// error banner, submit / cancel and the audit note.
//   <ActionDrawer :open :title="{ ar, en }" :sub :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
//                 :submit-label="{ ar, en }" @submit="save" @close="…" @clear-error="act.clearError()"> fields… </ActionDrawer>
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
  pending: { type: Boolean, default: false },
  error: { type: null, default: null },
  width: { type: Number, default: 520 },
  disabled: { type: Boolean, default: false },
  /** Footer note; omit for the default audit note, pass `null` to hide it. */
  note: { type: [String, Object], default: undefined },
});
const emit = defineEmits(['close', 'submit', 'clearError']);

const auth = useAuth();
const userName = computed(() => (auth.user ? pname(auth.user) : ''));
</script>

<template>
  <Drawer :open="open" :title="title" :sub="sub" :width="width" :z-index="70" @close="emit('close')">
    <slot />
    <template #footer>
      <div>
        <ErrorBanner :error="error" @close="emit('clearError')" />
        <div class="flex gap-2">
          <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="pending" :disabled="disabled" :label="submitLabel" @click="emit('submit')" />
          <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
        </div>
        <div v-if="note !== null" class="mt-2 text-[9px] leading-[1.7] text-faint">
          {{ note ? bi(note) : t(`* حقول إلزامية · يُسجل الإجراء في Audit Trail باسم ${userName}`, `* required fields · action is recorded in the Audit Trail as ${userName}`) }}
        </div>
      </div>
    </template>
  </Drawer>
</template>
