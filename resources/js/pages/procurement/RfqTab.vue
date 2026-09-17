<script setup>
// RFQ tab: list of RFQs; clicking one opens its quotation comparison underneath (deep link `?tab=rfq&rfq=RFQ-…`).
// Emits `quote` with a SupQuoteForm `initial` ({ rfqNumber }).
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import FilterBar from './FilterBar.vue';
import RfqComparison from './RfqComparison.vue';
import { RFQ_LABELS, linesSummary, qs } from './shared';

const emit = defineEmits(['quote']);
const route = useRoute();
const router = useRouter();

/** Selected RFQ number lives in the query string. */
const sel = computed(() => qs(route.query.rfq));
const setSel = (n) => router.replace({ query: { ...route.query, rfq: n || undefined } });

const q = ref('');
const status = ref('');
const page = ref(1);
watch([q, status], () => { page.value = 1; });
const list = useList('/procurement/rfq', () => ({ q: q.value, status: status.value, page: page.value, pageSize: 20 }));
const selectedKey = computed(() => list.data.value?.items.find((r) => r.number === sel.value)?.id ?? null);

const STATUSES = [
  { k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'open,quoted,compared', label: { ar: 'مفتوحة', en: 'Open' } },
  { k: 'awarded', label: { ar: 'مُرسّاة', en: 'Awarded' } }, { k: 'cancelled', label: { ar: 'ملغاة', en: 'Cancelled' } },
];
const columns = [
  { key: 'number', header: 'RFQ', width: '120px', kind: 'id' },
  { key: 'pr', header: 'PR', width: '100px', ltr: true, class: 'num text-[10px] text-violet', value: (r) => r.pr?.number || '—' },
  { key: 'lines', header: { ar: 'الأصناف', en: 'Items' }, width: 'minmax(180px,1.4fr)', kind: 'name', value: (r) => linesSummary(r.lines) },
  { key: 'inv', header: { ar: 'مدعوون / عروض', en: 'Invited / quotes' }, width: '110px', kind: 'num', value: (r) => `${r._count?.suppliers ?? 0} / ${r._count?.quotations ?? 0}` },
  { key: 'closeDate', header: { ar: 'الإقفال', en: 'Closes' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.closeDate) },
  { key: 'wh', header: { ar: 'التوريد', en: 'Delivery' }, width: '80px', value: (r) => r.warehouse?.code || '—' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px' },
];
</script>

<template>
  <FilterBar v-model="q"><Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" /></FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :selected-key="selectedKey" :min-width="900" :empty-text="{ ar: 'لا طلبات عروض مطابقة.', en: 'No matching RFQs.' }"
               @page="page = $event" @row-click="(r) => setSel(r.number === sel ? '' : r.number)">
      <template #cell-status="{ row }"><Chip :map="RFQ_LABELS" :k="row.status" /></template>
    </DataTable>
  </div>

  <RfqComparison v-if="sel" :number="sel" @close="setSel('')" @quote="emit('quote', $event)" />

  <div class="hint">{{ t('سجّل عروض الموردين (مع المرفق) على RFQ مفتوح؛ عند وجود عرضين أو أكثر تظهر المقارنة والتوصية. الترسية على غير الموصى به مسموحة وتُسجَّل في Audit.', 'Record supplier quotations (with attachment) on an open RFQ; with two or more quotes the comparison and recommendation appear. Awarding a non-recommended quote is allowed and audited.') }}</div>
</template>
