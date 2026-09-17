<script setup>
// Pick & Pack — tabs: pick lists (FO table + pick-list panel with scan → confirm / short pick), packing station
// (cartons / weight / volume → pack), outbound staging.
// Data: /api/fulfillment/orders[/:n], /api/fulfillment/pick-tasks/:id/confirm|short, /api/fulfillment/orders/:n/pack, /api/inventory/staging.
// Deep links: `/picking?tab=pick|pack|staging`, `/picking?fo=FO-…`.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import PackTab from './PackTab.vue';
import PickTab from './PickTab.vue';
import StagingTab from './StagingTab.vue';

const route = useRoute();
const router = useRouter();

const TABS = [{ k: 'pick', label: { ar: 'قوائم التجهيز', en: 'Pick lists' } }, { k: 'pack', label: { ar: 'محطة التعبئة', en: 'Packing station' } }, { k: 'staging', label: { ar: 'Staging الصادر', en: 'Outbound staging' } }];
const tab = computed(() => (['pick', 'pack', 'staging'].includes(route.query.tab) ? route.query.tab : 'pick'));
const initialFo = computed(() => (typeof route.query.fo === 'string' ? route.query.fo : ''));
function setTab(k) { router.replace({ query: { ...route.query, tab: k } }); }
</script>

<template>
  <div>
    <PageHead :sub="t('Scan الموقع ← المنتج ← الدفعة ← الكمية — FEFO مفروض من النظام', 'Scan location → product → batch → qty — FEFO enforced by the system')" />
    <Tabs :model-value="tab" :tabs="TABS" @update:model-value="setTab" />
    <PickTab v-if="tab === 'pick'" :initial-fo="initialFo" />
    <PackTab v-else-if="tab === 'pack'" />
    <StagingTab v-else />
  </div>
</template>
