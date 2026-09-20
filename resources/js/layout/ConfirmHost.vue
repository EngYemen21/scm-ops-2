<script setup>
// Confirm dialog. Ask from anywhere: `if (await confirm({ title, sub, tone })) …` (stores/ui.js).
// With `input` (see `ask()` in stores/ui.js) it also collects a line of text — the app's replacement for window.prompt.
import { computed, nextTick, ref, watch } from 'vue';
import { useScrollLock } from '../composables/scrollLock';
import { bi, dir, t } from '../i18n';
import { confirmState, resolveConfirm } from '../stores/ui';

const tone = computed(() => (confirmState.value?.tone === 'dark' ? 'dark' : confirmState.value?.tone === 'primary' ? 'primary' : 'danger'));
const input = computed(() => confirmState.value?.input || null);
const text = ref('');
const field = ref(null);
useScrollLock(() => !!confirmState.value);
const blocked = computed(() => !!input.value?.required && !text.value.trim());

watch(confirmState, async (s) => {
  text.value = s?.input?.value || '';
  if (s?.input) { await nextTick(); field.value?.focus(); }
});
function ok() {
  if (blocked.value) return;
  resolveConfirm(input.value ? text.value : true);
}
</script>

<template>
  <div v-if="confirmState" class="modal-wrap z-[95]" :dir="dir" @keydown.esc="resolveConfirm(false)">
    <div class="confirm-box" role="alertdialog">
      <div class="text-[14px] font-extrabold">{{ bi(confirmState.title) }}</div>
      <div v-if="confirmState.sub" class="mt-1.5 text-[11px] leading-[1.7] text-muted">{{ bi(confirmState.sub) }}</div>
      <label v-if="input" class="field mt-3 block">
        <span v-if="input.label" class="field-l">{{ bi(input.label) }} <span v-if="input.required" class="text-bad">*</span></span>
        <textarea ref="field" v-model="text" class="inp min-h-[72px] w-full" rows="3" :placeholder="input.placeholder ? bi(input.placeholder) : ''" @keydown.ctrl.enter="ok" />
      </label>
      <div class="mt-[18px] flex gap-2">
        <button type="button" class="btn h-[42px] flex-1" :class="tone" :autofocus="!input" :disabled="blocked" @click="ok">{{ confirmState.okLabel ? bi(confirmState.okLabel) : t('تأكيد', 'Confirm') }}</button>
        <button type="button" class="btn soft h-[42px] w-[110px]" @click="resolveConfirm(false)">{{ confirmState.cancelLabel ? bi(confirmState.cancelLabel) : t('إلغاء', 'Cancel') }}</button>
      </div>
    </div>
  </div>
</template>
