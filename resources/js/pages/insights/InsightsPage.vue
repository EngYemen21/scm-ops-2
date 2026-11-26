<script setup>
// «العلامات والصلاحية والتحليلات» — brand owners and their franchisees (dashboards, alerts), expiry alert levels, the
// stock card of a product and the operations insights. The tabs follow GET /insights/me:
//   - a brand owner's user (outsider) sees ONLY his own brand: its dashboard, franchisees and alerts;
//   - brandowner.manage (manager) sees every brand, the alerts of all brands and the rules / history settings;
//   - anybody else of the section sees expiry, the stock card and the operations insights, but no brands.
// Everything is in the query string (keys listed at `useInsightsNav` in ./insights.js): /insights?tab=card&sku=P-100
import { computed, watch } from 'vue';
import { ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import AlertsTab from './AlertsTab.vue';
import BrandsTab from './BrandsTab.vue';
import ExpiryTab from './ExpiryTab.vue';
import OperationsTab from './OperationsTab.vue';
import StockCardTab from './StockCardTab.vue';
import { useInsightsMe, useInsightsNav } from './insights';

const nav = useInsightsNav();
const { me, query } = useInsightsMe();

const tabs = computed(() => {
  const m = me.value;
  if (!m) return [];
  const out = [];
  if (m.manager || m.outsider) out.push({ k: 'brands', label: m.outsider ? { ar: 'علامتي', en: 'My brand' } : { ar: 'العلامات', en: 'Brands' } });
  if (m.manager) out.push({ k: 'alerts', label: { ar: 'التنبيهات', en: 'Alerts' } });
  if (!m.outsider) {
    out.push({ k: 'expiry', label: { ar: 'الصلاحية', en: 'Expiry' } });
    out.push({ k: 'card', label: { ar: 'كرت الصنف', en: 'Stock card' } });
    out.push({ k: 'ops', label: { ar: 'التحليلات', en: 'Insights' } });
  }
  return out;
});
const tab = computed(() => {
  const want = nav.get('tab');
  return tabs.value.some((x) => x.k === want) ? want : tabs.value[0]?.k || null;
});
// a link to a tab this user does not have lands on his first tab
watch(() => [me.value, nav.get('tab')], () => { if (me.value && nav.get('tab') && tab.value && nav.get('tab') !== tab.value) nav.go(tab.value); });

const sub = computed(() => (me.value?.outsider
  ? t('لوحة علامتك: مشتريات فروعك من منتجاتك، الأهداف، والتنبيهات', 'Your brand dashboard: your franchisees\' purchases of your products, targets and alerts')
  : t('العلامات وفروعها · تنبيهات الصلاحية · كرت الصنف · مؤشرات العمليات', 'Brands and franchisees · expiry alerts · stock card · operations insights')));
</script>

<template>
  <PageHead :sub="sub" />
  <ErrorBanner :error="query.error.value" :closable="false" />
  <div v-if="!me && query.isLoading.value" class="skel h-[200px]" />
  <template v-else-if="me">
    <Tabs v-if="tabs.length > 1" :model-value="tab" :tabs="tabs" class="no-print" @update:model-value="(k) => nav.go(k)" />
    <BrandsTab v-if="tab === 'brands'" :me="me" />
    <AlertsTab v-else-if="tab === 'alerts'" :me="me" />
    <ExpiryTab v-else-if="tab === 'expiry'" :me="me" />
    <StockCardTab v-else-if="tab === 'card'" :me="me" />
    <OperationsTab v-else-if="tab === 'ops'" :me="me" />
    <div v-else class="empty dashed">{{ t('لا شيء في هذا القسم متاح لحسابك.', 'Nothing in this section is open to your account.') }}</div>
  </template>
</template>
