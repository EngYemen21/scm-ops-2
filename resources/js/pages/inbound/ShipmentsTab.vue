<script setup>
// Receiving › inbound shipments: search + status pills, paged table, selected shipment panel and its putaway tasks.
// `sel` and `status` live in the query string of the page (?sel=SHP-…&status=open).
import { computed, ref, watch } from 'vue';
import { useGet, useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { SHIPMENT_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import PutawayList from './PutawayList.vue';
import ShipmentPanel from './ShipmentPanel.vue';
import { pn } from './shared';

const props = defineProps({
  sel: { type: String, default: null },
  status: { type: String, default: 'open' },
});
const emit = defineEmits(['update:sel', 'update:status']);

const wh = useWarehouse();
const q = ref('');
const page = ref(1);
watch([q, () => props.status, () => wh.whParams.warehouse], () => { page.value = 1; });

const list = useList('/inbound/shipments', () => ({ q: q.value, status: props.status === 'all' ? undefined : props.status, page: page.value, pageSize: 25, ...wh.whParams }), { refetchInterval: 30_000 });
const detail = useGet(() => (props.sel ? `/inbound/shipments/${encodeURIComponent(props.sel)}` : null));
const shipment = computed(() => (props.sel ? detail.data.value : null));

const columns = [
  { key: 'number', header: { ar: 'الشحنة', en: 'Shipment' }, width: '140px', kind: 'id' },
  { key: 'po', header: { ar: 'أمر الشراء', en: 'PO' }, width: '140px' },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(160px,1.3fr)' },
  { key: 'wh', header: { ar: 'المستودع', en: 'Warehouse' }, width: '110px' },
  { key: 'lines', header: { ar: 'الأسطر', en: 'Lines' }, width: '90px', kind: 'num', value: (r) => `${r.lines.length} · ${fmtNum(r.totals.open)} ${t('متبقٍ', 'open')}` },
  { key: 'eta', header: 'ETA', width: '100px', kind: 'date', value: (r) => fmtDateOnly(r.eta) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '160px' },
];
const STATUSES = [
  { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'expected', label: { ar: 'متوقعة', en: 'Expected' } }, { k: 'arrived', label: { ar: 'وصلت', en: 'Arrived' } }, { k: 'inspecting', label: { ar: 'قيد الفحص', en: 'Inspecting' } },
  { k: 'putaway', label: { ar: 'بانتظار Putaway', en: 'Awaiting putaway' } }, { k: 'done', label: { ar: 'مكتملة', en: 'Done' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
];
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="q" small type="search" class="!w-[240px]" :placeholder="{ ar: 'بحث: شحنة / PO / مورد', en: 'Search: shipment / PO / supplier' }" />
    <Tabs :model-value="status" :tabs="STATUSES" variant="pill" class="!mb-0" @update:model-value="emit('update:status', $event)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="sel" :min-width="800" :empty-text="{ ar: 'لا شحنات مطابقة.', en: 'No matching shipments.' }"
               @page="page = $event" @row-click="(r) => emit('update:sel', r.number === sel ? null : r.number)">
      <template #cell-number="{ row }"><span class="text-ink">{{ row.number }}</span></template>
      <template #cell-po="{ row }"><RouterLink :to="`/po/${encodeURIComponent(row.po.number)}`" class="num text-[10px] !text-violet" @click.stop>{{ row.po.number }}</RouterLink></template>
      <template #cell-supplier="{ row }"><span class="cell-name">{{ pn(row.supplier) }}</span></template>
      <template #cell-wh="{ row }"><span class="text-[10px] font-bold">{{ row.warehouse.code }} · {{ pn(row.warehouse) }}</span></template>
      <template #cell-status="{ row }">
        <div class="row !gap-1"><Chip :map="SHIPMENT_LABELS" :k="row.status" /><Chip v-if="row.locked" small fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'بانتظار اعتماد PO', en: 'Awaiting PO approval' }" /></div>
      </template>
    </DataTable>
  </div>

  <ErrorBanner :error="sel ? detail.error.value : null" :closable="false" class="mt-3" />
  <template v-if="shipment">
    <ShipmentPanel :shipment="shipment" closable @changed="detail.refetch()" @grn-posted="detail.refetch()" @close="emit('update:sel', null)" />
    <PutawayList v-if="['putaway', 'done'].includes(shipment.status)" :params="{ grn: shipment.grns[0]?.number, status: shipment.status === 'done' ? 'done' : 'open' }">
      <template #title>{{ t('مهام التخزين لهذه الشحنة', 'Putaway tasks for this shipment') }} <span class="num muted text-[10px]">{{ shipment.grns.map((g) => g.number).join(' · ') }}</span></template>
    </PutawayList>
  </template>
</template>
