<script setup>
// Receiving / GRN / Putaway: expected inbound list + scan box, shipment panel, GRN log, putaway tasks.
// Deep links: /receiving?tab=ship|grn|putaway &sel=SHP-… &status=open|expected|… &grn=GRN-…
import { computed, nextTick, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useList } from '@/api/client';
import { ErrorBanner, KpiCard, KpiGrid, PageHead, ScanInput, Tabs } from '@/components';
import { t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import GrnTab from './GrnTab.vue';
import PutawayTab from './PutawayTab.vue';
import ShipmentsTab from './ShipmentsTab.vue';
import { queryOf } from './shared';

const route = useRoute();
const router = useRouter();
const wh = useWarehouse();

const TAB_KEYS = ['ship', 'grn', 'putaway'];
const tab = computed(() => (TAB_KEYS.includes(queryOf(route, 'tab')) ? queryOf(route, 'tab') : 'ship'));
/** Patch the query string (null removes a key); `replace` keeps the history clean like the reference. */
function setParams(patch) {
  const query = { ...route.query };
  Object.entries(patch).forEach(([k, v]) => { if (v) query[k] = v; else delete query[k]; });
  router.replace({ query });
}

// ---- KPI counters (pageSize 1 → only `total` is used) ----
const open = useList('/inbound/shipments', () => ({ status: 'open', pageSize: 1, ...wh.whParams }));
const put = useList('/inbound/putaway', () => ({ status: 'open', pageSize: 1, ...wh.whParams }));
const expected = useList('/inbound/shipments', () => ({ status: 'expected', pageSize: 1, ...wh.whParams }));
const inspecting = useList('/inbound/shipments', () => ({ status: 'inspecting', pageSize: 1, ...wh.whParams }));

const tabs = computed(() => [
  { k: 'ship', label: { ar: 'الشحنات الواردة', en: 'Inbound shipments' }, badge: open.data.value?.total },
  { k: 'grn', label: { ar: 'سجل GRN', en: 'GRN log' } },
  { k: 'putaway', label: { ar: 'مهام Putaway', en: 'Putaway' }, badge: put.data.value?.total },
]);

// ---- scan a PO / shipment number → open its shipment ----
const scanErr = ref(null);
const scanning = ref(false);
const scanBox = ref(null);
async function scan(code) {
  scanning.value = true; scanErr.value = null;
  try {
    const s = await api.get(`/inbound/shipments/scan/${encodeURIComponent(code)}`);
    setParams({ tab: 'ship', sel: s.number });
  } catch (e) { scanErr.value = e; } finally {
    scanning.value = false;
    nextTick(() => scanBox.value?.focus()); // ready for the next scan
  }
}
</script>

<template>
  <PageHead :sub="t('موعد → وصول → فحص → GRN → Putaway — التالف لا يدخل المخزون أبدًا', 'Appointment → arrival → inspection → GRN → putaway — damaged goods never enter stock')">
    <div class="w-[260px]"><ScanInput ref="scanBox" small :disabled="scanning" :placeholder="{ ar: 'امسح / اكتب رقم PO أو الشحنة', en: 'Scan / type PO or shipment number' }" @submit="scan" /></div>
  </PageHead>
  <ErrorBanner :error="scanErr" @close="scanErr = null" />

  <KpiGrid compact>
    <KpiCard compact clickable :value="open.data.value?.total" :label="{ ar: 'شحنات مفتوحة', en: 'Open shipments' }" color="#0d7f93" :loading="!open.data.value" @click="setParams({ tab: 'ship', status: 'open' })" />
    <KpiCard compact clickable :value="expected.data.value?.total" :label="{ ar: 'متوقعة — بانتظار الوصول', en: 'Expected' }" color="#55506a" :loading="!expected.data.value" @click="setParams({ tab: 'ship', status: 'expected' })" />
    <KpiCard compact clickable :value="inspecting.data.value?.total" :label="{ ar: 'قيد الفحص', en: 'Inspecting' }" color="#b26a16" :loading="!inspecting.data.value" @click="setParams({ tab: 'ship', status: 'inspecting' })" />
    <KpiCard compact clickable :value="put.data.value?.total" :label="{ ar: 'مهام Putaway مفتوحة', en: 'Open putaway tasks' }" color="#654e92" :active="tab === 'putaway'" :loading="!put.data.value" @click="setParams({ tab: 'putaway' })" />
  </KpiGrid>

  <Tabs :model-value="tab" :tabs="tabs" @update:model-value="setParams({ tab: $event })" />
  <div class="mt-3">
    <ShipmentsTab v-if="tab === 'ship'" :sel="queryOf(route, 'sel')" :status="queryOf(route, 'status') || 'open'" @update:sel="setParams({ sel: $event })" @update:status="setParams({ status: $event })" />
    <GrnTab v-else-if="tab === 'grn'" :sel="queryOf(route, 'grn')" @update:sel="setParams({ grn: $event })" />
    <PutawayTab v-else :grn="queryOf(route, 'grn')" @clear-grn="setParams({ grn: null })" />
  </div>
</template>
