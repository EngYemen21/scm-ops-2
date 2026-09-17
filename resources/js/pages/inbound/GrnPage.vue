<script setup>
// GRN — deep link /grn/:number: lines with QC, exceptions, putaway tasks, inventory movements, links to PO / shipment.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, PageHead } from '@/components';
import { fmtDate, t } from '@/i18n';
import GrnDetail from './GrnDetail.vue';
import { pn } from './shared';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const q = useGet(() => (number.value ? `/inbound/grns/${encodeURIComponent(number.value)}` : null));
const g = computed(() => q.data.value);
const sub = computed(() => (g.value ? `${pn(g.value.supplier)} · ${g.value.warehouse.code} · ${t('أُصدر', 'Posted')} ${fmtDate(g.value.postedAt)}${g.value.postedBy ? ` · ${g.value.postedBy}` : ''}` : null));
</script>

<template>
  <PageHead :sub="sub">
    <Btn tone="outline" :label="{ ar: '← سجل GRN', en: '← GRN log' }" @click="router.push('/receiving?tab=grn')" />
    <Btn v-if="g" tone="softPurple" :label="{ ar: `أمر الشراء ${g.po.number}`, en: `PO ${g.po.number}` }" @click="router.push(`/po/${encodeURIComponent(g.po.number)}`)" />
    <Btn v-if="g?.shipment" tone="softBlue" :label="{ ar: `الشحنة ${g.shipment.number}`, en: `Shipment ${g.shipment.number}` }" @click="router.push(`/shipments/${encodeURIComponent(g.shipment.number)}`)" />
  </PageHead>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="skel min-h-[240px]" />
  <GrnDetail v-if="g" :grn="g" />
</template>
