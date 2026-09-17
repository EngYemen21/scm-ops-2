<script setup>
// Shown for a route whose page file has not been built yet (see PAGE_FILES in router/routes.js).
import { t } from '../i18n';
import Icon from '../layout/Icon.vue';
</script>

<template>
  <div class="empty dashed mx-auto my-10 max-w-[520px]">
    <div class="mb-2 flex justify-center"><Icon name="wrench" /></div>
    <div class="text-[13px] font-extrabold text-violet">{{ t('هذه الصفحة قيد البناء', 'This page is being built') }}</div>
    <div class="mt-1.5">{{ t('الواجهة البرمجية جاهزة، والشاشة تُنقل حاليًا.', 'The API is ready; the screen is being ported.') }}</div>
  </div>
</template>
