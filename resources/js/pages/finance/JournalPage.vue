<script setup>
// Journal deep link /jv/:number — the drawer's content as a page (QR / links from documents and reports).
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Btn, PageHead } from '@/components';
import { t } from '@/i18n';
import JournalDrawer from './JournalDrawer.vue';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
</script>

<template>
  <PageHead :sub="number">
    <Btn tone="outline" :label="{ ar: '← القيود', en: '← Journals' }" @click="router.push('/finance?tab=journals')" />
  </PageHead>
  <div class="hint">{{ t('تفاصيل القيد تُعرض في اللوحة الجانبية؛ إغلاقها يعود إلى قائمة القيود.', 'The journal opens in the side panel; closing it returns to the journals list.') }}</div>
  <JournalDrawer :number="number" @close="router.push('/finance?tab=journals')" @changed="() => {}" />
</template>
