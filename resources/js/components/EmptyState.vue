<script setup>
// tone: 'inline' (table empty) · 'dashed' (dashed purple card) · 'success' (green dashed)
import { bi, t } from '../i18n';

defineProps({
  text: { type: [String, Object], default: null },
  tone: { type: String, default: 'inline' },
});
</script>

<template>
  <div class="empty" :class="{ dashed: tone === 'dashed', success: tone === 'success' }">
    <div v-if="$slots.icon" class="mb-2 flex justify-center"><slot name="icon" /></div>
    <div>{{ text ? bi(text) : t('لا نتائج مطابقة.', 'No matching results.') }}</div>
    <div v-if="$slots.action" class="mt-3"><slot name="action" /></div>
  </div>
</template>
