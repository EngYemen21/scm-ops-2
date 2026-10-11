<script setup>
// Journals tab: status / kind pills, period + date filters, search → table → drawer. "+ قيد يدوي" opens the maker form.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import { qs } from '@/pages/procurement/shared';
import JournalDrawer from './JournalDrawer.vue';
import JournalForm from './JournalForm.vue';
import { JOURNAL_KIND_LABELS, JOURNAL_STATUS_LABELS } from './shared';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const sel = computed(() => qs(route.query.journal) || null);
const setSel = (n) => router.replace({ query: { ...route.query, journal: n || undefined } });

const q = ref('');
const status = ref('');
const kind = ref('');
const from = ref('');
const to = ref('');
const page = ref(1);
const form = ref(false);
watch([q, status, kind, from, to], () => { page.value = 1; });
const list = useList('/finance/journals', () => ({ q: q.value, status: status.value, kind: kind.value, from: from.value, to: to.value, page: page.value, pageSize: 25 }));

const STATUSES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'draft', label: { ar: 'مسودات', en: 'Drafts' } }, { k: 'posted', label: { ar: 'مرحَّلة', en: 'Posted' } }, { k: 'reversed', label: { ar: 'معكوسة', en: 'Reversed' } }];
const KINDS = [{ k: '', label: { ar: 'كل الأنواع', en: 'Any kind' } }, { k: 'auto', label: { ar: 'آلية', en: 'Automatic' } }, { k: 'manual', label: { ar: 'يدوية', en: 'Manual' } }, { k: 'reversal', label: { ar: 'عكسية', en: 'Reversals' } }, { k: 'opening', label: { ar: 'افتتاحية', en: 'Opening' } }];
const columns = [
  { key: 'number', header: { ar: 'القيد', en: 'Journal' }, width: '130px', kind: 'id' },
  { key: 'journalDate', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.journalDate) },
  { key: 'memo', header: { ar: 'البيان', en: 'Memo' }, width: 'minmax(220px,2fr)', kind: 'name' },
  { key: 'source', header: { ar: 'المصدر', en: 'Source' }, width: '150px', class: 'num text-[9.5px]', value: (r) => (r.sourceNumber ? `${r.sourceType} · ${r.sourceNumber}` : '—') },
  { key: 'total', header: { ar: 'المبلغ', en: 'Amount' }, width: '110px', kind: 'num', sortable: true, value: (r) => fmtMoney(r.totalDebit) },
  { key: 'lines', header: { ar: 'سطور', en: 'Lines' }, width: '60px', kind: 'num', value: (r) => fmtNum(r._count?.lines || 0) },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '90px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px' },
];
</script>

<template>
  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <Tabs v-model="kind" :tabs="KINDS" variant="pill" class="!mb-0" />
    <DateInput v-model="from" small class="w-[130px]" :placeholder="{ ar: 'من', en: 'From' }" />
    <DateInput v-model="to" small class="w-[130px]" :placeholder="{ ar: 'إلى', en: 'To' }" />
    <span class="grow" />
    <Btn v-if="auth.can('gl.post')" size="sm" tone="primary" :label="{ ar: '+ قيد يدوي', en: '+ Manual journal' }" @click="form = true" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا قيود مطابقة.', en: 'No matching journals.' }" @page="page = $event" @row-click="(r) => setSel(r.number)">
      <template #cell-kind="{ row }"><Chip :map="JOURNAL_KIND_LABELS" :k="row.kind" small /></template>
      <template #cell-status="{ row }"><Chip :map="JOURNAL_STATUS_LABELS" :k="row.status" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('القيود الآلية تُرحَّل مع حدثها التشغيلي في المعاملة نفسها (استلام، تحميل، تسليم، فاتورة، سداد…) ولا تُعدَّل؛ التصحيح بقيد عكسي مرتبط. القيد اليدوي يحفظه صانعه مسودة ويعتمده شخص آخر.', 'Automatic journals post with their operational event in the same transaction and are immutable; corrections are linked reversals. A manual journal is drafted by its maker and approved by someone else.') }}</div>

  <JournalDrawer :number="sel" @close="setSel(null)" @changed="list.refetch()" />
  <JournalForm :open="form" @close="form = false" @done="list.refetch()" />
</template>
