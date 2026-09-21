<script setup>
// Pick & Pack › pick lists tab: FO table (status / warehouse / search filters) and, for the selected FO, the pick list
// panel — tasks in sequence (scan bin → scan product → qty → confirm, short pick with reason), last server message,
// and the packing station once the order is picked. Deep link: `/picking?fo=FO-…` selects an order.
import { computed, ref, watch } from 'vue';
import { useGet, useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import { FO_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import PackRow from './PackRow.vue';
import PickTaskCard from './PickTaskCard.vue';
import { custName, pickProgress } from './shared';

const props = defineProps({
  initialFo: { type: String, default: '' },
});

const FO_FILTERS = [
  { k: 'active', label: { ar: 'النشطة', en: 'Active' } }, { k: 'alloc', label: { ar: 'بانتظار التجهيز', en: 'Awaiting picking' } }, { k: 'picking', label: { ar: 'قيد التجهيز', en: 'Picking' } },
  { k: 'picked', label: { ar: 'مُجهز', en: 'Picked' } }, { k: 'packed', label: { ar: 'معبأ', en: 'Packed' } }, { k: 'loaded', label: { ar: 'محمَّل', en: 'Loaded' } }, { k: 'onroute', label: { ar: 'في الطريق', en: 'On route' } }, { k: '', label: { ar: 'الكل', en: 'All' } },
];

const wh = useWarehouse();
const q = ref('');
const status = ref('active');
const page = ref(1);
const sel = ref(props.initialFo || null);
/** Result of the last confirmed pick (PickResult) — its server message is shown above the tasks. */
const last = ref(null);
watch(() => props.initialFo, (v) => { if (v) sel.value = v; });

const list = useList('/fulfillment/orders', () => ({ q: q.value.trim() || undefined, status: status.value || undefined, ...wh.whParams, page: page.value, pageSize: 25 }));
const det = useGet(() => (sel.value ? `/fulfillment/orders/${encodeURIComponent(sel.value)}` : null));
const d = computed(() => det.data.value);
const pl = computed(() => d.value?.pickLists?.[0] || null);
const tasks = computed(() => (d.value?.pickLists || []).flatMap((p) => p.tasks).sort((a, b) => a.seq - b.seq));
const doneTasks = computed(() => tasks.value.filter((x) => ['done', 'short'].includes(x.status)).length);

function onTaskChanged(r) { last.value = r || null; det.refetch(); }

const columns = [
  { key: 'number', header: { ar: 'أمر التنفيذ', en: 'Order' }, width: '110px', kind: 'id' },
  { key: 'customer', header: { ar: 'العميل (من منصة B2B)', en: 'Customer (from B2B)' }, width: 'minmax(190px,1.6fr)' },
  { key: 'zone', header: { ar: 'المنطقة', en: 'Zone' }, width: '130px' },
  { key: 'lines', header: { ar: 'أسطر', en: 'Lines' }, width: '70px', kind: 'num' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '70px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '190px' },
];
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput scan v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث برقم الأمر / العميل / أمر البيع…', en: 'Search FO / customer / SO…' }" @update:model-value="page = 1" />
      <Tabs v-model="status" :tabs="FO_FILTERS" variant="pill" class="!mb-0" @update:model-value="page = 1" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="760" :row-key="(r) => r.number" :selected-key="sel"
                 :empty-text="{ ar: 'لا أوامر تنفيذ — تُنشأ من تجميع الطلبات (جاهز للتجهيز) أو من أمر البيع', en: 'No fulfillment orders — created from consolidation (ready for picking) or from the sales order' }"
                 @page="page = $event" @row-click="(r) => (sel = sel === r.number ? null : r.number)">
        <template #cell-number="{ row }">
          <div><div class="cell-id">{{ row.number }}</div><div v-if="row.so?.priority === 'high'" class="font-sans text-[8px] font-extrabold text-bad">{{ t('عالية', 'High') }}</div></div>
        </template>
        <template #cell-customer="{ row }">
          <div><div class="cell-name">{{ custName(row.customer) }}</div><div class="cell-sub">{{ row.so?.number || '' }}{{ row.so?.dueDate ? ` · ${fmtDateOnly(row.so.dueDate)}` : '' }}{{ row.so?.window ? ` · ${row.so.window}` : '' }}</div></div>
        </template>
        <template #cell-zone="{ row }"><span class="text-[10px] font-bold text-sec">{{ row.customer.zone || row.zoneAr || '—' }}</span></template>
        <template #cell-lines="{ row }"><span class="text-muted">{{ row.lines.length }}<span class="text-[8.5px]"> · {{ pickProgress(row).done }}/{{ pickProgress(row).need }}</span></span></template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse.code }}</span></template>
        <template #cell-status="{ row }"><Chip :map="FO_LABELS" :k="row.status" /></template>
      </DataTable>
    </div>

    <div v-if="sel" class="card selected mt-3.5 px-5 py-[18px]">
      <ErrorBanner :error="det.error.value" :closable="false" />
      <div v-if="!d && !det.error.value" class="skel h-[120px]" />
      <template v-if="d">
        <div class="row wrap !gap-2.5">
          <div class="min-w-[200px] flex-1 text-[13.5px] font-extrabold">
            {{ t('قائمة التجهيز', 'Pick list') }} <span class="num-mixed">{{ pl?.number || '—' }}</span> —
            <RouterLink :to="`/fo/${encodeURIComponent(d.number)}`" class="num-mixed !text-violet">{{ d.number }}</RouterLink>
            <span class="muted text-[10.5px]"> · {{ custName(d.customer) }}</span>
          </div>
          <Chip :map="FO_LABELS" :k="d.status" />
          <div class="num ltr text-[11px] text-brand-dark">{{ doneTasks }} / {{ tasks.length }}</div>
          <button type="button" class="x-btn" aria-label="close" @click="sel = null">✕</button>
        </div>
        <div v-if="last" class="hint !mt-2.5" :class="last.orderDone ? 'green' : last.lineDone ? 'teal' : 'amber'">{{ last.message }}</div>
        <div class="col mt-[13px]">
          <div v-if="tasks.length === 0" class="empty !p-3">{{ d.status === 'alloc' ? t('لا مهام تجهيز بعد — تُنشأ عند إنشاء أمر التنفيذ', 'No pick tasks yet') : t('لا مهام', 'No tasks') }}</div>
          <PickTaskCard v-for="x in tasks" :key="x.id" :task="x" :fo-status="d.status" @changed="onTaskChanged" />
        </div>
        <div v-if="d.status === 'picked'" class="mt-3 rounded-[13px] border border-[#DCD2EE] bg-[#FCFBFE] px-[15px] py-3">
          <div class="mb-2 text-[11.5px] font-extrabold">{{ t('محطة التعبئة — تحقق من الأسطر ثم أدخل الكراتين والوزن والحجم', 'Packing station — verify lines then enter cartons, weight and volume') }}</div>
          <PackRow :key="d.number" :fo="d" standalone @changed="det.refetch()" />
        </div>
        <div class="hint amber">{{ t('Scan الموقع ثم المنتج (SKU أو باركود) ثم الكمية — يرفض النظام الموقع الخاطئ، المنتج الخاطئ، الكمية الزائدة (No Over-Picking)، الدفعة المنتهية أو المحجورة، والرصيد السالب. التجهيز الجزئي مسموح؛ «نقص» يقفل السطر بسبب ويفتح استثناء.', 'Scan the location, then the product (SKU or barcode), then the quantity — the system rejects wrong bin, wrong product, over-picking, expired/quarantined batches and negative stock. Partial picks are allowed; "Short" closes the line with a reason and raises an exception.') }}</div>
      </template>
    </div>
  </div>
</template>
