<script setup>
// Sales order — deep link /so/:number. Header, FEFO stepper, lines with reserved / allocated / picked / delivered and
// allocations per bin + batch, actions (allocate / fulfill / cancel by permission), traceability SO → FO → trip → POD →
// return, status history. Rendering is shared with the Sales page drawer (SoDetail).
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, PageHead, SectionCard } from '@/components';
import { fmtDate, t } from '@/i18n';
import SoDetail from './SoDetail.vue';
import { custName } from './shared';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const det = useGet(() => (number.value ? `/sales/orders/${encodeURIComponent(number.value)}` : null));
const so = computed(() => det.data.value);
const firstFo = computed(() => so.value?.fos?.[0] || null);
const sub = computed(() => (so.value
  ? `${custName(so.value.customer)} · ${so.value.warehouse.code} ${so.value.warehouse.nameAr} · ${t('أُنشئ', 'Created')} ${fmtDate(so.value.date)}${so.value.createdBy ? ` · ${so.value.createdBy}` : ''}`
  : t('أمر بيع — الأسطر والحجز والتخصيص FEFO والتتبع', 'Sales order — lines, reservation, FEFO allocation, traceability')));
</script>

<template>
  <PageHead :title="number" :sub="sub">
    <Btn tone="outline" :label="{ ar: '← أوامر البيع', en: '← Sales orders' }" @click="router.push({ path: '/sales', query: { tab: 'so' } })" />
    <Btn v-if="firstFo" tone="softBlue" :label="{ ar: `أمر التنفيذ ${firstFo.number}`, en: `Fulfillment ${firstFo.number}` }" @click="router.push(`/fo/${encodeURIComponent(firstFo.number)}`)" />
  </PageHead>
  <ErrorBanner :error="det.error.value" :closable="false" />
  <div v-if="det.isLoading.value && !so" class="skel min-h-[240px]" />
  <SectionCard v-if="so" class="mb-3.5"><div class="pt-3.5"><SoDetail :so="so" show-title @changed="det.refetch()" /></div></SectionCard>
</template>
