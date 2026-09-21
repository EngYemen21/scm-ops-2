<script setup>
// Receiving › inbound shipments: search + status pills, paged table with the NEXT ACTION of every shipment in its row.
// A shipment opens in a drawer (a full screen on a phone) that holds all of its actions, lines and putaway tasks.
// `sel` and `status` live in the query string of the page (?sel=SHP-…&status=open).
import { computed, ref, watch } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { SHIPMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import PutawayList from './PutawayList.vue';
import ShipmentPanel from './ShipmentPanel.vue';
import { pn, useShipmentActions } from './shared';

const props = defineProps({
  sel: { type: String, default: null },
  status: { type: String, default: 'open' },
});
const emit = defineEmits(['update:sel', 'update:status']);

const auth = useAuth();
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
  { key: 'act', header: { ar: 'الإجراء التالي', en: 'Next action' }, width: '210px', align: 'end', bare: true },
];
const { act, inspect } = useShipmentActions(() => { list.refetch(); detail.refetch(); });
const open = (n) => emit('update:sel', n);
/** The one thing to do next on a shipment — shown as the row's button. */
function nextOf(r) {
  if (r.status === 'expected') return r.locked ? { label: { ar: 'بانتظار اعتماد PO', en: 'Awaiting PO approval' }, tone: 'soft', disabled: true } : auth.can('shipment.receive') ? { label: { ar: 'تسجيل الوصول', en: 'Register arrival' }, tone: 'primary' } : null;
  if (r.status === 'arrived') return auth.can('shipment.receive') ? { label: { ar: 'بدء الفحص', en: 'Start inspection' }, tone: 'primary', run: () => inspect(r.number) } : null;
  if (r.status === 'inspecting') return auth.can('grn.post') ? { label: { ar: 'إدخال الكميات وإصدار GRN', en: 'Enter quantities & post GRN' }, tone: 'dark' } : null;
  if (r.status === 'putaway') return { label: { ar: 'مهام التخزين', en: 'Putaway tasks' }, tone: 'softPurple' };
  return null;
}
async function runNext(r) { const n = nextOf(r); if (n?.run) await n.run(); open(r.number); }

const STATUSES = [
  { k: 'open', label: { ar: 'المفتوحة', en: 'Open' } }, { k: 'expected', label: { ar: 'متوقعة', en: 'Expected' } }, { k: 'arrived', label: { ar: 'وصلت', en: 'Arrived' } }, { k: 'inspecting', label: { ar: 'قيد الفحص', en: 'Inspecting' } },
  { k: 'putaway', label: { ar: 'بانتظار Putaway', en: 'Awaiting putaway' } }, { k: 'done', label: { ar: 'مكتملة', en: 'Done' } }, { k: 'cancelled', label: { ar: 'ملغاة', en: 'Cancelled' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
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
      <template #cell-act="{ row }">
        <span class="row justify-end !gap-1.5">
          <Btn v-if="nextOf(row)" size="sm" :tone="nextOf(row).tone" :disabled="nextOf(row).disabled" :loading="act.pending.value && sel === row.number" :label="nextOf(row).label" @click.stop="runNext(row)" />
          <Btn size="sm" tone="soft" :label="{ ar: 'فتح', en: 'Open' }" @click.stop="open(row.number)" />
        </span>
      </template>
    </DataTable>
  </div>

  <Drawer :open="!!sel" :width="1080" dark :title="sel" :sub="shipment ? `${pn(shipment.supplier)} · ${shipment.po.number}` : null" @close="emit('update:sel', null)">
    <template #headExtra><RouterLink v-if="sel" :to="`/shipments/${encodeURIComponent(sel)}`" class="btn soft sm">{{ t('صفحة الشحنة', 'Full page') }}</RouterLink></template>
    <ErrorBanner :error="sel ? detail.error.value : null" :closable="false" />
    <div v-if="sel && !shipment && detail.isLoading.value" class="skel min-h-[260px]" />
    <template v-if="shipment">
      <div class="-mt-3.5"><ShipmentPanel :shipment="shipment" @changed="detail.refetch(); list.refetch()" @grn-posted="detail.refetch(); list.refetch()" @open="open" /></div>
      <PutawayList v-if="['putaway', 'done'].includes(shipment.status)" :params="{ grn: shipment.grns[0]?.number, status: shipment.status === 'done' ? 'done' : 'open' }">
        <template #title>{{ t('مهام التخزين لهذه الشحنة', 'Putaway tasks for this shipment') }} <span class="num muted text-[10px]">{{ shipment.grns.map((g) => g.number).join(' · ') }}</span></template>
      </PutawayList>
    </template>
  </Drawer>
</template>
