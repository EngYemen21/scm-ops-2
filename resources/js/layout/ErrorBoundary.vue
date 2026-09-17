<script setup>
// Catches render / lifecycle errors of the page inside it, so a bug in one page shows a message instead of a blank
// screen. Resets automatically when `resetKey` changes (route change).
import { onErrorCaptured, ref, watch } from 'vue';
import { t } from '../i18n';
import { toast } from '../stores/ui';

const props = defineProps({ resetKey: { type: String, default: '' } });
const error = ref(null);

// Event-handler errors leave the rendered page intact, so they only get a toast; the page is replaced by the error
// panel for render / setup / lifecycle errors, where what is on screen can no longer be trusted.
// `info` is a phrase in development and an error-reference link (#runtime-5 native, -6 component handler) in production.
const isHandlerError = (info) => /event handler|#runtime-(5|6)$/.test(String(info));

onErrorCaptured((err, _instance, info) => {
  console.error('[ui-crash]', info, err);
  if (isHandlerError(info)) toast.say(t(`تعذر تنفيذ الإجراء: ${err?.message || err}`, `Action failed: ${err?.message || err}`), 5000);
  else error.value = err;
  return false; // handled here
});
watch(() => props.resetKey, () => { error.value = null; });
const reload = () => window.location.reload(); // `window` is not reachable from a template
</script>

<template>
  <div v-if="error" class="banner red !block m-3.5" role="alert">
    <div class="mb-1 font-extrabold">{{ t('حدث خطأ أثناء عرض هذه الصفحة', 'Something went wrong while rendering this page') }}</div>
    <div class="ltr break-all text-start font-mono text-[10.5px] font-normal opacity-85">{{ String(error?.message || error) }}</div>
    <div class="mt-2 flex gap-2">
      <button type="button" class="btn primary sm" @click="error = null">{{ t('إعادة المحاولة', 'Retry') }}</button>
      <button type="button" class="btn soft sm" @click="reload">{{ t('تحديث الصفحة', 'Reload') }}</button>
    </div>
  </div>
  <slot v-else />
</template>
