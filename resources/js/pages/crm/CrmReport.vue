<script setup>
// The funnel: the open pipeline stage by stage (count, expected revenue, revenue weighted by the chance), what was won
// and lost, the win rate, the same per salesperson and why opportunities were lost.
// GET /crm/report/funnel?team&owner&from&to → { period, stages, totals: { open, won, lost, winRate }, bySalesperson, lostReasons }.
// Two clocks, as the server counts them: the open pipeline is a picture of now; won and lost are counted by the day
// they were closed inside the period (no period = all of them).   /crm?view=report&from=2026-01-01&to=2026-03-31&owner=me
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, DataTable, DateInput, ErrorBanner, KpiCard, KpiGrid, SectionCard, SelectInput } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import { money, nameOf, pct, useCrmLookups, useCrmNav } from './crm';

const nav = useCrmNav();
const { teamOptions, ownerOptions } = useCrmLookups();
const owners = computed(() => [{ v: 'me', l: { ar: 'أنا', en: 'Me' } }, ...ownerOptions.value]);
const report = useGet('/crm/report/funnel', () => ({ team: nav.get('team') || undefined, owner: nav.get('owner') || undefined, from: nav.get('from') || undefined, to: nav.get('to') || undefined }));
const r = computed(() => report.data.value || null);
const filtered = computed(() => ['team', 'owner', 'from', 'to'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ team: null, owner: null, from: null, to: null });

/** The widest bar of the funnel is the fullest stage. */
const maxCount = computed(() => Math.max(1, ...(r.value?.stages || []).map((s) => s.count)));
const maxLost = computed(() => Math.max(1, ...(r.value?.lostReasons || []).map((x) => x.count)));
const width = (n, max) => `${Math.max(n ? 4 : 0, Math.round((n / max) * 100))}%`;
const periodText = computed(() => {
  const p = r.value?.period;
  if (!p || (!p.from && !p.to)) return t('كل ما أُغلق حتى الآن', 'everything closed so far');
  return t(`ما أُغلق ${p.from ? `من ${p.from}` : ''} ${p.to ? `إلى ${p.to}` : ''}`, `closed ${p.from ? `from ${p.from}` : ''} ${p.to ? `to ${p.to}` : ''}`);
});

const people = computed(() => (r.value?.bySalesperson || []).map((p, i) => ({ ...p, key: p.owner?.key || `none-${i}` })));
const columns = [
  { key: 'owner', header: { ar: 'المندوب', en: 'Salesperson' }, width: 'minmax(150px,1.4fr)' },
  { key: 'open', header: { ar: 'مفتوحة', en: 'Open' }, width: '76px', kind: 'num', sortable: true },
  { key: 'openRevenue', header: { ar: 'إيرادها المتوقع', en: 'Expected revenue' }, width: '120px', kind: 'num', sortable: true, value: (x) => fmtMoney(x.openRevenue) },
  { key: 'weighted', header: { ar: 'مرجَّح', en: 'Weighted' }, width: '110px', kind: 'num', sortable: true, value: (x) => fmtMoney(x.weighted) },
  { key: 'won', header: { ar: 'مكسوبة', en: 'Won' }, width: '76px', kind: 'num', sortable: true },
  { key: 'wonRevenue', header: { ar: 'إيراد المكسوبة', en: 'Won revenue' }, width: '120px', kind: 'num', sortable: true, value: (x) => fmtMoney(x.wonRevenue) },
  { key: 'lost', header: { ar: 'خاسرة', en: 'Lost' }, width: '76px', kind: 'num', sortable: true },
  { key: 'winRate', header: { ar: 'نسبة الكسب', en: 'Win rate' }, width: '90px', kind: 'num', sortable: true, value: (x) => pct(x.winRate) },
];
</script>

<template>
  <div class="row wrap mb-2.5">
    <DateInput small field-class="w-[150px]" :model-value="nav.get('from')" :label="{ ar: 'أُغلقت من', en: 'Closed from' }" @update:model-value="(v) => nav.patch({ from: v })" />
    <DateInput small field-class="w-[150px]" :model-value="nav.get('to')" :label="{ ar: 'إلى', en: 'To' }" @update:model-value="(v) => nav.patch({ to: v })" />
    <SelectInput v-if="teamOptions.length > 1" small field-class="w-[150px]" :label="{ ar: 'الفريق', en: 'Team' }" :model-value="nav.get('team')" :options="teamOptions" :placeholder="{ ar: 'كل الفرق', en: 'Every team' }" @update:model-value="(v) => nav.patch({ team: v })" />
    <SelectInput small field-class="w-[180px]" :label="{ ar: 'المندوب', en: 'Salesperson' }" :model-value="nav.get('owner')" :options="owners" :placeholder="{ ar: 'كل المندوبين', en: 'Every salesperson' }" @update:model-value="(v) => nav.patch({ owner: v })" />
    <Btn v-if="filtered" size="sm" tone="ghost" class="self-end" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="report.error.value" :closable="false" />
  <div v-if="report.isLoading.value" class="skel h-[220px]" />

  <template v-if="r">
    <KpiGrid compact>
      <KpiCard :value="r.totals.open.count" :label="{ ar: 'فرص مفتوحة الآن', en: 'Open now' }" color="#3C79F5" />
      <KpiCard :value="fmtMoney(r.totals.open.revenue)" :label="{ ar: 'إيرادها المتوقع (ر.س)', en: 'Their expected revenue (SAR)' }" />
      <KpiCard :value="fmtMoney(r.totals.open.weighted)" :label="{ ar: 'مرجَّحًا باحتمال الكسب', en: 'Weighted by the chance' }" color="#654e92" />
      <KpiCard :value="r.totals.won.count" :label="{ ar: 'مكسوبة', en: 'Won' }" :sub="`${fmtMoney(r.totals.won.revenue)} ${t('ر.س', 'SAR')}`" color="#1d7a3e" />
      <KpiCard :value="r.totals.lost.count" :label="{ ar: 'خاسرة', en: 'Lost' }" color="#b23b3b" />
      <KpiCard :value="r.totals.winRate == null ? '—' : pct(r.totals.winRate)" :label="{ ar: 'نسبة الكسب', en: 'Win rate' }" :sub="{ ar: 'المكسوبة ÷ (المكسوبة + الخاسرة)', en: 'won ÷ (won + lost)' }" color="#0d7f93" />
    </KpiGrid>
    <div class="mb-3 text-[9.5px] text-faint">{{ t('المفتوحة: الوضع الآن. المكسوبة والخاسرة ونسبة الكسب:', 'Open: as it stands now. Won, lost and the win rate:') }} {{ periodText }}.</div>

    <div class="grid-eq">
      <SectionCard :title="{ ar: 'المسار حسب المرحلة', en: 'The pipeline by stage' }">
        <div v-if="!r.stages.length" class="empty !p-3">{{ t('لا مراحل بعد.', 'No stage yet.') }}</div>
        <div v-for="s in r.stages" :key="s.id" class="border-t border-line-2 py-2 first:border-t-0">
          <div class="flex items-center gap-2">
            <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold" :class="s.isWon ? 'text-ok' : 'text-ink'" dir="auto">{{ nameOf(s) }}<span v-if="s.isWon" class="text-[9px] font-normal text-faint"> · {{ t('كُسبت في الفترة', 'won in the period') }}</span></span>
            <span class="num text-[11.5px]">{{ fmtNum(s.count) }}</span>
          </div>
          <div class="progress mt-1 !h-[7px]"><div :style="{ width: width(s.count, maxCount), background: s.isWon ? '#1d7a3e' : null }" /></div>
          <div class="mt-1 flex flex-wrap gap-x-3 text-[9.5px] text-muted">
            <span>{{ t('الإيراد المتوقع', 'Expected revenue') }} <b class="num ltr text-sec">{{ money(s.revenue) }}</b></span>
            <span v-if="!s.isWon">{{ t('مرجَّح', 'Weighted') }} <b class="num ltr text-sec">{{ money(s.weighted) }}</b></span>
          </div>
        </div>
      </SectionCard>

      <SectionCard :title="{ ar: 'أسباب الخسارة', en: 'Why opportunities were lost' }">
        <div v-if="!r.lostReasons.length" class="empty !p-3">{{ t('لا فرص خاسرة في هذه الفترة.', 'No lost opportunity in this period.') }}</div>
        <div v-for="(x, i) in r.lostReasons" :key="x.reason ? x.reason.id : `none-${i}`" class="border-t border-line-2 py-2 first:border-t-0">
          <div class="flex items-center gap-2">
            <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink" dir="auto">{{ x.reason ? nameOf(x.reason) : t('بلا سبب مسجَّل', 'No reason recorded') }}</span>
            <span class="num text-[11.5px]">{{ fmtNum(x.count) }}</span>
          </div>
          <div class="progress mt-1 !h-[7px]"><div :style="{ width: width(x.count, maxLost), background: '#b23b3b' }" /></div>
          <div v-if="x.revenue" class="mt-1 text-[9.5px] text-muted">{{ t('إيراد متوقع ضاع', 'Expected revenue lost') }} <b class="num ltr text-sec">{{ money(x.revenue) }}</b></div>
        </div>
      </SectionCard>
    </div>

    <SectionCard class="mt-3.5" :title="{ ar: 'حسب المندوب', en: 'By salesperson' }" :padded="false">
      <DataTable :columns="columns" :rows="people" :page-size="25" :row-key="(x) => x.key" :empty-text="{ ar: 'لا فرص بعد.', en: 'No opportunity yet.' }" @row-click="(x) => x.owner && nav.go('list', { owner: x.owner.key, state: 'all' })">
        <template #cell-owner="{ row }">
          <span class="cell-name" dir="auto">{{ row.owner ? nameOf(row.owner) : t('بلا مندوب', 'Unassigned') }}</span><span v-if="row.owner?.external" class="text-[8.5px] text-faint"> · {{ t('من أودو', 'from Odoo') }}</span>
        </template>
      </DataTable>
    </SectionCard>
  </template>
</template>
