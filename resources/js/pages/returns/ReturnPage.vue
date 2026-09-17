<script setup>
// Return deep link (/rtn/:number): full-page return detail — stepper, meta, lines, receiving / inspection / decisions,
// movements, history and the approve / reject / receive / inspect / decide actions. Data: GET /api/returns/:number.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, PageHead } from '@/components';
import { bi, t } from '@/i18n';
import ReturnDetail from './ReturnDetail.vue';
import { RETURN_TYPE_LABELS, labelOf, sourceOf } from './shared';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const q = useGet(() => (number.value ? `/returns/${encodeURIComponent(number.value)}` : null));
const r = computed(() => q.data.value);
const sub = computed(() => (r.value ? `${bi(labelOf(RETURN_TYPE_LABELS, r.value.type))} · ${sourceOf(r.value)} · ${r.value.warehouse?.code || ''}` : t('مرتجع', 'Return')));
</script>

<template>
  <div>
    <PageHead :title="r?.number || number" :sub="sub">
      <Btn tone="ghost" :label="{ ar: 'كل المرتجعات', en: 'All returns' }" @click="router.push('/returns')" />
    </PageHead>
    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value && !r" class="skel h-40" />
    <div v-if="r" class="card px-5 py-[18px]"><ReturnDetail :ret="r" @done="q.refetch()" /></div>
  </div>
</template>
