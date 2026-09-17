<script setup>
// Returns & Transfers: tabs all returns · customer · supplier · delivery · damaged · warehouse transfers.
// Returns: KPIs, filters, cards → drawer (ReturnDetail); transfers: KPIs, filters, table → /trf/:number.
// Deep links: /returns?tab=all|cust|sup|del|dmg|trf &q=… &rtn=RTN-…
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Btn, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ReturnDrawer from './ReturnDrawer.vue';
import ReturnForm from './ReturnForm.vue';
import ReturnsTab from './ReturnsTab.vue';
import TransferForm from './TransferForm.vue';
import TransfersTab from './TransfersTab.vue';
import { queryOf } from './shared';

const TABS = [
  { k: 'all', label: { ar: 'كل المرتجعات', en: 'All returns' } }, { k: 'cust', label: { ar: 'مرتجعات العملاء', en: 'Customer returns' } }, { k: 'sup', label: { ar: 'إرجاع للموردين', en: 'Supplier returns' } },
  { k: 'del', label: { ar: 'مرتجعات التوصيل', en: 'Delivery returns' } }, { k: 'dmg', label: { ar: 'الأصناف التالفة', en: 'Damaged items' } }, { k: 'trf', label: { ar: 'التحويلات بين المستودعات', en: 'Warehouse transfers' } },
];

const route = useRoute();
const router = useRouter();
const auth = useAuth();

const tab = computed(() => { const k = queryOf(route, 'tab'); return TABS.some((x) => x.k === k) ? k : 'all'; });
const initialQ = computed(() => queryOf(route, 'q') || '');
const openRtn = computed(() => queryOf(route, 'rtn'));
/** Switching tabs drops the deep-link params (`q`, `rtn`). */
function setTab(k) {
  const query = { ...route.query, tab: k };
  delete query.q; delete query.rtn;
  router.replace({ query });
}
function setOpenRtn(n) {
  const query = { ...route.query };
  if (n) query.rtn = n; else delete query.rtn;
  router.replace({ query });
}

/** 'return' | 'transfer' | null */
const form = ref(null);
const onReturnCreated = (r) => { if (r?.number) setOpenRtn(r.number); };
const onTransferCreated = (r) => { if (r?.number) router.push(`/trf/${encodeURIComponent(r.number)}`); else setTab('trf'); };
</script>

<template>
  <div>
    <PageHead :sub="t('طلب ← اعتماد ← استلام ← فحص ← قرار (للمخزون / حجر / تالف / للمورد)', 'Request → approve → receive → inspect → decide (restock / quarantine / damaged / supplier)')">
      <Btn v-if="auth.can('inventory.transfer')" :label="{ ar: '+ تحويل بين مستودعات', en: '+ Transfer' }" @click="form = 'transfer'" />
      <Btn v-if="auth.can('return.create')" tone="primary" :label="{ ar: '+ مرتجع', en: '+ Return' }" @click="form = 'return'" />
    </PageHead>
    <Tabs :model-value="tab" :tabs="TABS" @update:model-value="setTab" />

    <ReturnsTab v-if="tab !== 'trf'" :type="tab === 'all' ? '' : tab" :initial-q="initialQ" @open="setOpenRtn" />
    <TransfersTab v-else :initial-q="initialQ" />

    <ReturnDrawer :number="openRtn" @close="setOpenRtn(null)" />
    <ReturnForm :open="form === 'return'" :initial-type="tab !== 'trf' && tab !== 'all' ? tab : null" @close="form = null" @done="onReturnCreated" />
    <TransferForm :open="form === 'transfer'" @close="form = null" @done="onTransferCreated" />
  </div>
</template>
