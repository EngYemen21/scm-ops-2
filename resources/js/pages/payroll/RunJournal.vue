<script setup>
// What posting a run writes, read before posting: GET /payroll/runs/{id}/journal → { run, period, debit, credit, balanced,
// missing: [{ key, nameAr, nameEn }], lines: [{ account { code, nameAr, nameEn, usable }, debit, credit, description }] }.
// A posting key without an account is a refusal at posting time: the warning links to the posting map in the settings.
// Below: the journals the books already hold for the run (`run.journals`).
//   <RunJournal :run="run" />
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Chip, DataTable, ErrorBanner } from '@/components';
import { num, t } from '@/i18n';
import { TONE, fmtDay, money, pname } from './payroll';

const props = defineProps({
  run: { type: Object, required: true },
});

const q = useGet(() => `/payroll/runs/${encodeURIComponent(props.run.id)}/journal`);
const j = computed(() => q.data.value || null);
const columns = [
  { key: 'account', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(170px,1.3fr)' },
  { key: 'description', header: { ar: 'البيان', en: 'Description' }, width: 'minmax(170px,1.5fr)' },
  { key: 'debit', header: { ar: 'مدين', en: 'Debit' }, width: '104px', kind: 'num', value: (l) => (num(l.debit) ? money(l.debit) : '—') },
  { key: 'credit', header: { ar: 'دائن', en: 'Credit' }, width: '104px', kind: 'num', value: (l) => (num(l.credit) ? money(l.credit) : '—') },
];
const JOURNAL_KIND = { posted: { ar: 'مرحَّل', en: 'Posted', ...TONE.ok }, reversed: { ar: 'معكوس', en: 'Reversed', ...TONE.bad }, draft: { ar: 'مسودة', en: 'Draft', ...TONE.neutral } };
</script>

<template>
  <div>
    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-[160px]" />
    <template v-if="j">
      <div v-if="j.missing.length" class="banner amber mb-2.5">
        <div>
          <b>{{ t('بنود توجيه بلا حساب — الترحيل سيُرفض:', 'Posting keys without an account — posting will be refused:') }}</b>
          {{ j.missing.map((m) => pname(m)).join('، ') }}.
          <RouterLink :to="{ path: '/payroll', query: { tab: 'settings', set: 'accounts' } }" class="font-bold underline">{{ t('اربطها في «التوجيه المحاسبي»', 'Map them in “Posting map”') }}</RouterLink>
        </div>
      </div>
      <div class="row wrap mb-2 text-[11px]">
        <span>{{ t('مدين', 'Debit') }} <b class="num">{{ money(j.debit) }}</b></span>
        <span>{{ t('دائن', 'Credit') }} <b class="num">{{ money(j.credit) }}</b></span>
        <Chip small :label="j.balanced ? { ar: 'متوازن ✓', en: 'Balanced ✓' } : { ar: 'غير متوازن', en: 'Not balanced' }" :fg="j.balanced ? TONE.ok.fg : TONE.bad.fg" :bg="j.balanced ? TONE.ok.bg : TONE.bad.bg" />
      </div>
      <div class="card">
        <DataTable :columns="columns" :rows="j.lines" :row-key="(l) => `${l.account.code}|${l.description}`" :empty-text="{ ar: 'لا سطور — المسير بلا قسائم.', en: 'No line — the run has no payslip.' }">
          <template #cell-account="{ row }">
            <div class="min-w-0"><div class="num ltr text-[11px] font-bold">{{ row.account.code }}</div><div class="truncate text-[10px]" :class="row.account.usable ? 'text-muted' : 'font-bold text-bad'" dir="auto">{{ row.account.nameAr ? pname(row.account) : t('غير موجود في الدليل', 'Not in the chart') }}<template v-if="row.account.nameAr && !row.account.usable"> · {{ t('لا يقبل الترحيل', 'not postable') }}</template></div></div>
          </template>
          <template #cell-description="{ row }"><span class="truncate text-[10.5px]" dir="auto" :title="row.description">{{ row.description }}</span></template>
        </DataTable>
      </div>
    </template>

    <div v-if="run.journals?.length" class="mt-3">
      <div class="card-title mb-1.5">{{ t('القيود في الدفاتر', 'Journals in the books') }}</div>
      <div class="col !gap-1.5">
        <div v-for="jr in run.journals" :key="jr.id" class="row wrap rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
          <span class="cell-id">{{ jr.number }}</span><span class="cell-date">{{ fmtDay(jr.date) }}</span><b class="num">{{ money(jr.amount) }}</b>
          <span class="min-w-0 flex-1 truncate text-muted" dir="auto">{{ jr.memo }}</span>
          <Chip small :map="JOURNAL_KIND" :k="jr.status" />
        </div>
      </div>
    </div>
  </div>
</template>
