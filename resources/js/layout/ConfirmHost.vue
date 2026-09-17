<script setup>
// Confirm dialog. Ask from anywhere: `if (await confirm({ title, sub, tone })) …` (stores/ui.js).
import { computed } from 'vue';
import { bi, dir, t } from '../i18n';
import { confirmState, resolveConfirm } from '../stores/ui';

const tone = computed(() => (confirmState.value?.tone === 'dark' ? 'dark' : confirmState.value?.tone === 'primary' ? 'primary' : 'danger'));
</script>

<template>
  <div v-if="confirmState" class="modal-wrap z-[95]" :dir="dir">
    <div class="confirm-box" role="alertdialog">
      <div class="text-[14px] font-extrabold">{{ bi(confirmState.title) }}</div>
      <div v-if="confirmState.sub" class="mt-1.5 text-[11px] leading-[1.7] text-muted">{{ bi(confirmState.sub) }}</div>
      <div class="mt-[18px] flex gap-2">
        <button type="button" class="btn h-[42px] flex-1" :class="tone" autofocus @click="resolveConfirm(true)">{{ confirmState.okLabel ? bi(confirmState.okLabel) : t('تأكيد', 'Confirm') }}</button>
        <button type="button" class="btn soft h-[42px] w-[110px]" @click="resolveConfirm(false)">{{ confirmState.cancelLabel ? bi(confirmState.cancelLabel) : t('إلغاء', 'Cancel') }}</button>
      </div>
    </div>
  </div>
</template>
