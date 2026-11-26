<script setup>
// The summary: what is pending (draft or waiting for approval), approved (owed, not paid yet) and paid — in total,
// month by month and earner by earner. GET /commissions/summary?from=&to=&source= (a user who only opens the section
// gets his own figures). The period and the source live in the query string; a month or an earner opens its statements.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, DataTable, ErrorBanner, KpiCard, KpiGrid, SectionCard, SelectInput } from '@/components';
import { t } from '@/i18n';
import { API, money, periodLabel, personName, sar, useCommissions } from './commissions';

const { nav, seesAll, periods } = useCommissions();
const scope = computed(() => ({ from: nav.get('from'), to: nav.get('to'), source: nav.get('source') }));
const q = useGet(`${API}/summary`, () => scope.value);
const d = computed(() => q.data.value || null);
const SOURCES = [['ops', { ar: 'كشوف العمليات', en: 'Made in OPS' }], ['odoo', { ar: 'تاريخ أودو', en: 'Odoo’s history' }]];
const filtered = computed(() => ['from', 'to', 'source'].some((k) => !!nav.get(k)));

const amount = (bucket) => (r) => (Number(r[bucket].amount) === 0 ? '—' : money(r[bucket].amount));
const monthColumns = [
  { key: 'period', header: { ar: 'الشهر', en: 'Month' }, width: 'minmax(110px,1.2fr)', kind: 'name', value: (r) => periodLabel(r.period) },
  { key: 'count', header: { ar: 'كشوف', en: 'Statements' }, width: '70px', kind: 'num', value: (r) => String(r.count) },
  { key: 'pending', header: { ar: 'قيد الإعداد والاعتماد', en: 'Pending' }, width: '130px', kind: 'num', value: amount('pending') },
  { key: 'approved', header: { ar: 'بانتظار الصرف', en: 'To be paid' }, width: '120px', kind: 'num', value: amount('approved') },
  { key: 'paid', header: { ar: 'مصروف', en: 'Paid' }, width: '120px', kind: 'num', value: amount('paid') },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '120px', kind: 'num' },
];
const personColumns = [
  { key: 'beneficiary', header: { ar: 'المستحق له', en: 'Earner' }, width: 'minmax(150px,1.5fr)', kind: 'name' },
  { key: 'count', header: { ar: 'كشوف', en: 'Statements' }, width: '70px', kind: 'num', value: (r) => String(r.count) },
  { key: 'base', header: { ar: 'أساس العمولة', en: 'Base' }, width: '120px', kind: 'num', value: (r) => money(r.base) },
  { key: 'pending', header: { ar: 'قيد الإعداد والاعتماد', en: 'Pending' }, width: '130px', kind: 'num', value: amount('pending') },
  { key: 'approved', header: { ar: 'بانتظار الصرف', en: 'To be paid' }, width: '120px', kind: 'num', value: amount('approved') },
  { key: 'paid', header: { ar: 'مصروف', en: 'Paid' }, width: '120px', kind: 'num', value: amount('paid') },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '120px', kind: 'num' },
];
const openMonth = (r) => nav.setTab('statements', { from: r.period, to: r.period, ...(nav.get('source') ? { source: nav.get('source') } : {}) });
const openPerson = (r) => nav.setTab('statements', { who: r.beneficiary.key, ...Object.fromEntries(Object.entries(scope.value).filter(([, v]) => v)) });
</script>

<template>
  <div class="row wrap mb-2.5 !items-end">
    <SelectInput small field-class="w-[140px]" :label="{ ar: 'من شهر', en: 'From month' }" :model-value="nav.get('from')" :options="periods" :placeholder="{ ar: 'البداية', en: 'Start' }" @update:model-value="(v) => nav.set('from', v)" />
    <SelectInput small field-class="w-[140px]" :label="{ ar: 'إلى شهر', en: 'To month' }" :model-value="nav.get('to')" :options="periods" :placeholder="{ ar: 'الآن', en: 'Now' }" @update:model-value="(v) => nav.set('to', v)" />
    <SelectInput v-if="seesAll" small field-class="w-[150px]" :label="{ ar: 'المصدر', en: 'Source' }" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'الكل', en: 'All' }" @update:model-value="(v) => nav.set('source', v)" />
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="nav.patch({ from: null, to: null, source: null })" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />

  <KpiGrid compact class="!mb-3">
    <KpiCard compact :value="d ? money(d.totals.pending.amount) : null" :unit="sar()" :label="{ ar: 'قيد الإعداد والاعتماد', en: 'Pending' }" :sub="d ? t(`${d.statements.draft} مسودة · ${d.statements.submitted} بانتظار الاعتماد`, `${d.statements.draft} drafts · ${d.statements.submitted} awaiting approval`) : null" color="#b26a16" :loading="q.isLoading.value" />
    <KpiCard compact :value="d ? money(d.totals.approved.amount) : null" :unit="sar()" :label="{ ar: 'معتمد بانتظار الصرف', en: 'Approved, to be paid' }" :sub="d ? t(`${d.totals.approved.count} كشف`, `${d.totals.approved.count} statements`) : null" color="#3C79F5" :loading="q.isLoading.value" />
    <KpiCard compact :value="d ? money(d.totals.paid.amount) : null" :unit="sar()" :label="{ ar: 'مصروف', en: 'Paid' }" :sub="d ? t(`${d.totals.paid.count} كشف`, `${d.totals.paid.count} statements`) : null" color="#1d7a3e" :loading="q.isLoading.value" />
    <KpiCard compact :value="d ? money(d.totals.total) : null" :unit="sar()" :label="{ ar: 'الإجمالي', en: 'Total' }" :sub="d ? t(`${d.totals.count} كشف`, `${d.totals.count} statements`) : null" :loading="q.isLoading.value" />
  </KpiGrid>

  <div class="grid-eq">
    <SectionCard :title="{ ar: 'شهرًا بشهر', en: 'Month by month' }" :count="d ? d.byMonth.length : null" :padded="false">
      <DataTable :columns="monthColumns" :rows="d ? d.byMonth : []" :loading="q.isLoading.value" :row-key="(r) => r.period" :page-size="12" dense
                 :empty-text="{ ar: 'لا كشوف في هذه الفترة.', en: 'No statement in this period.' }" @row-click="openMonth">
        <template #cell-total="{ row }"><span class="num ltr font-bold">{{ money(row.total) }}</span></template>
      </DataTable>
    </SectionCard>
    <SectionCard :title="seesAll ? { ar: 'مستحقًا بمستحق', en: 'Earner by earner' } : { ar: 'مستحقاتي', en: 'My commissions' }" :count="d ? d.byBeneficiary.length : null" :padded="false">
      <DataTable :columns="personColumns" :rows="d ? d.byBeneficiary : []" :loading="q.isLoading.value" :row-key="(r) => r.beneficiary ? r.beneficiary.key : '—'" :page-size="12" dense
                 :empty-text="{ ar: 'لا كشوف في هذه الفترة.', en: 'No statement in this period.' }" @row-click="openPerson">
        <template #cell-beneficiary="{ row }">
          <span class="cell-name" dir="auto">{{ personName(row.beneficiary) }}</span>
          <span v-if="row.beneficiary && row.beneficiary.external" class="ms-1 text-[9px] text-muted">{{ t('بالاسم', 'by name') }}</span>
        </template>
        <template #cell-total="{ row }"><span class="num ltr font-bold">{{ money(row.total) }}</span></template>
      </DataTable>
    </SectionCard>
  </div>
  <div class="hint">{{ t('الأرقام هي إجماليات الكشوف (السطور + التسويات). «قيد الإعداد والاعتماد» قد يتغير حتى يُعتمد. اضغط شهرًا أو مستحقًا لترى كشوفه.', 'The figures are the statements’ totals (lines + adjustments). “Pending” may still change until it is approved. Click a month or an earner to see the statements.') }}</div>
</template>
