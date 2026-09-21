<script setup>
// Sales › sales orders tab: search + stage filters, paged table scoped by the warehouse selector; a row opens the SO drawer.
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, t } from '@/i18n';
import { SO_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import { custName } from './shared';

const props = defineProps({
  initialQ: { type: String, default: '' },
});
const emit = defineEmits(['open-so']);

/** Filter pill → query params (`waiting=allocation` is a server-side filter, the rest are plain statuses). */
const SO_FILTERS = [
  { k: 'all', ar: 'الكل', en: 'All', params: {} },
  { k: 'waiting', ar: 'بانتظار التخصيص', en: 'Waiting allocation', params: { waiting: 'allocation' } },
  { k: 'allocated', ar: 'مخصص', en: 'Allocated', params: { status: 'allocated' } },
  { k: 'preparing', ar: 'قيد التحضير', en: 'Preparing', params: { status: 'preparing' } },
  { k: 'picking', ar: 'قيد التجهيز', en: 'Picking', params: { status: 'picking' } },
  { k: 'packed', ar: 'معبأ', en: 'Packed', params: { status: 'packed' } },
  { k: 'outfordel', ar: 'في الطريق', en: 'Out for delivery', params: { status: 'outfordel' } },
  { k: 'delivered', ar: 'مسلّم', en: 'Delivered', params: { status: 'delivered' } },
  { k: 'cancelled', ar: 'ملغى', en: 'Cancelled', params: { status: 'cancelled' } },
];
const filterTabs = SO_FILTERS.map((x) => ({ k: x.k, label: { ar: x.ar, en: x.en } }));

const wh = useWarehouse();
const q = ref(props.initialQ);
const filter = ref('all');
const page = ref(1);
watch(() => props.initialQ, (v) => { q.value = v; page.value = 1; });

const active = computed(() => SO_FILTERS.find((x) => x.k === filter.value) || SO_FILTERS[0]);
const list = useList('/sales/orders', () => ({ q: q.value.trim() || undefined, ...active.value.params, ...wh.whParams, page: page.value, pageSize: 25 }));

const columns = [
  { key: 'number', header: { ar: 'أمر البيع', en: 'Sales order' }, width: '130px' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(170px,1.4fr)' },
  { key: 'due', header: { ar: 'التسليم', en: 'Delivery' }, width: '150px', kind: 'date', value: (r) => `${fmtDateOnly(r.dueDate)}${r.window ? ` · ${r.window}` : ''}` },
  { key: 'wh', header: { ar: 'المستودع', en: 'Warehouse' }, width: '90px' },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '110px', kind: 'num', value: (r) => fmtMoney(r.totals?.total) },
  { key: 'oc', header: { ar: 'دفعة التجميع', en: 'Consolidation' }, width: '120px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
];
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput scan v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث برقم الأمر أو العميل…', en: 'Search order / customer…' }" @update:model-value="page = 1" />
      <Tabs v-model="filter" :tabs="filterTabs" variant="pill" class="!mb-0" @update:model-value="page = 1" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="960" :row-key="(r) => r.number" @page="page = $event" @row-click="(r) => emit('open-so', r.number)">
        <template #cell-number="{ row }">
          <div><div class="cell-id">{{ row.number }}</div><div v-if="row.priority === 'high'" class="text-[8px] font-extrabold text-bad">{{ t('عالية', 'High') }}</div></div>
        </template>
        <template #cell-customer="{ row }">
          <div><div class="cell-name">{{ custName(row.customer) }}</div><div class="cell-sub !font-sans [direction:inherit]">{{ row.customer.zone || '' }}</div></div>
        </template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse.code }}</span></template>
        <template #cell-oc="{ row }">
          <RouterLink v-if="row.consolidation" :to="{ path: '/consol', query: { q: row.consolidation.number } }" class="num text-[9.5px] !text-violet" @click.stop>{{ row.consolidation.number }}</RouterLink>
          <span v-else class="num text-[9.5px] text-faint">{{ ['confirmed', 'allocated'].includes(row.status) ? t('بانتظار التجميع', 'Awaiting') : '—' }}</span>
        </template>
        <template #cell-status="{ row }"><Chip :map="SO_LABELS" :k="row.status" /></template>
      </DataTable>
    </div>
    <div class="hint">{{ t('اضغط على الأمر لعرض الأسطر (محجوز / مخصص / مصروف) والتخصيص حسب الموقع والدفعة FEFO، وإجراءات التخصيص والتنفيذ والإلغاء، وروابط التتبع.', 'Click an order to see lines (reserved / allocated / picked), FEFO allocations by bin and batch, actions and traceability links.') }}</div>
  </div>
</template>
