<script setup>
// Sales › quotations tab: search + status pills, paged table, detail card for the selected row.
// Deep link: `/sales?tab=qt&q=QT-…` pre-fills the search and opens the quotation when it is the only hit.
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney } from '@/i18n';
import { QT_LABELS } from '@/shared';
import QuotationDetail from './QuotationDetail.vue';
import { custName } from './shared';

const props = defineProps({
  initialQ: { type: String, default: '' },
});
const emit = defineEmits(['open-so']);

const q = ref(props.initialQ);
const status = ref('');
const page = ref(1);
const sel = ref(null);
watch(() => props.initialQ, (v) => { q.value = v; page.value = 1; });

const list = useList('/sales/quotations', () => ({ q: q.value.trim() || undefined, status: status.value || undefined, page: page.value, pageSize: 25 }));
watch(() => [props.initialQ, list.data.value], () => {
  if (props.initialQ && list.data.value?.items.length === 1) sel.value = list.data.value.items[0].number;
}, { immediate: true });

const statusTabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.keys(QT_LABELS).map((k) => ({ k, label: { ar: QT_LABELS[k].ar, en: QT_LABELS[k].en } }))]);
const columns = [
  { key: 'number', header: { ar: 'العرض', en: 'Quote' }, width: '130px', kind: 'id' },
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(170px,1.4fr)' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '95px', kind: 'date', value: (r) => fmtDateOnly(r.date) },
  { key: 'validUntil', header: { ar: 'صالح حتى', en: 'Valid until' }, width: '95px', kind: 'date', value: (r) => fmtDateOnly(r.validUntil) },
  { key: 'lines', header: { ar: 'أسطر', en: 'Lines' }, width: '60px', kind: 'num' },
  { key: 'total', header: { ar: 'الإجمالي شامل الضريبة', en: 'Total incl. VAT' }, width: '120px', kind: 'num', value: (r) => fmtMoney(r.totals?.total) },
  { key: 'createdBy', header: { ar: 'المندوب', en: 'Rep' }, width: '110px', kind: 'muted' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
];
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث برقم العرض أو العميل…', en: 'Search quote / customer…' }" @update:model-value="page = 1" />
      <Tabs v-model="status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="page = 1" />
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="900" :row-key="(r) => r.number" :selected-key="sel"
                 @page="page = $event" @row-click="(r) => (sel = sel === r.number ? null : r.number)">
        <template #cell-customer="{ row }"><span class="cell-name">{{ custName(row.customer) }}</span></template>
        <template #cell-lines="{ row }"><span class="muted">{{ row.lines.length }}</span></template>
        <template #cell-status="{ row }"><Chip :map="QT_LABELS" :k="row.status" /></template>
      </DataTable>
    </div>

    <QuotationDetail v-if="sel" :number="sel" @close="sel = null" @select="(n) => (sel = n)" @open-so="(n) => emit('open-so', n)" />
  </div>
</template>
