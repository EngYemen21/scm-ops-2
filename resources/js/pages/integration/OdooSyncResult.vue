<script setup>
// What one «مزامنة الآن» found — the answer of POST /integration/odoo/sync:
//   { ms, calls, models, pulled, created, updated, deleted, errors, error, pending: { toInspect, firstCopy, windows } }
// Green when the run went through, red with Odoo's text when it could not start reading at all (`error`).
//   <OdooSyncResult :result="sync.result.value" @close="sync.clear()" />
import { t } from '@/i18n';
import { fmtSecs, pendingLine, runLine } from './odoo';

defineProps({
  result: { type: Object, default: null },
});
const emit = defineEmits(['close']);
</script>

<template>
  <div v-if="result" class="banner" :class="result.error ? 'red' : 'green'">
    <div class="min-w-0 flex-1">
      <template v-if="result.error">
        <div>{{ t('لم تستطع المزامنة القراءة من أودو', 'The run could not read from Odoo') }}</div>
        <div class="ltr num-mixed break-words font-normal">{{ result.error }}</div>
      </template>
      <div v-else>{{ t('انتهت المزامنة في', 'The run finished in') }} <span class="num ltr">{{ fmtSecs(result.ms) }}</span> — {{ runLine(result) }}</div>
      <div v-if="pendingLine(result.pending)" class="font-normal">{{ t('متروك للدورات القادمة', 'Left for the next runs') }} — {{ pendingLine(result.pending) }}</div>
    </div>
    <button type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[12px] font-extrabold text-inherit" :aria-label="t('إخفاء', 'Dismiss')" @click="emit('close')">✕</button>
  </div>
</template>
