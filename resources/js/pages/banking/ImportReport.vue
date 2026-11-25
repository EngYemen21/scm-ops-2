<script setup>
// Step 3 of the import: the answer of POST /banking/statements/{id}/import — a dry run or the import itself. Every row
// of the file is named: taken (with the line number it got), duplicate (and where that line already is), or refused
// (and why). The counts, the total taken and the breaks of the bank's running balance head the report.
//   <ImportReport :result="response" />
import { computed, ref } from 'vue';
import { Chip, DataTable, Tabs } from '@/components';
import { bi, t } from '@/i18n';
import { IMPORT_ROW, fmtDay, money, signClass } from './banking';

const props = defineProps({
  result: { type: Object, required: true },
});

const filter = ref('');
const rows = computed(() => (props.result.lines || []).filter((r) => !filter.value || r.status === filter.value));
const tabs = computed(() => [
  { k: '', label: { ar: 'الكل', en: 'All' }, badge: props.result.total },
  { k: 'taken', label: { ar: 'أُخذ', en: 'Taken' }, badge: props.result.taken },
  { k: 'duplicate', label: { ar: 'مكرر', en: 'Duplicate' }, badge: props.result.duplicates },
  { k: 'refused', label: { ar: 'مرفوض', en: 'Refused' }, badge: props.result.refused },
]);
const columns = [
  { key: 'row', header: { ar: 'الصف', en: 'Row' }, width: '56px', kind: 'num' },
  { key: 'status', header: { ar: 'النتيجة', en: 'Result' }, width: '92px', bare: true },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
  { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: 'minmax(110px,1fr)' },
  { key: 'detail', header: { ar: 'التفصيل', en: 'Detail' }, width: 'minmax(180px,1.6fr)' },
];
</script>

<template>
  <div :class="result.dryRun ? 'banner teal' : 'banner green'" class="mb-3">
    <div class="font-extrabold">{{ result.dryRun ? t('تجربة بلا حفظ — هذا ما سيحدث عند الاستيراد، ولم يُحفظ شيء.', 'Dry run — this is what the import would do; nothing was saved.') : t('تم الاستيراد.', 'Imported.') }}</div>
    <div v-if="result.profile" class="text-[10px]">{{ t(`حُفظ ربط الأعمدة «${result.profile.name}»${result.profile.isDefault ? ' وصار الافتراضي لهذا الحساب' : ''}.`, `The column mapping “${result.profile.name}” was kept${result.profile.isDefault ? ' as this account’s default' : ''}.`) }}</div>
  </div>
  <div class="grid grid-cols-5 gap-2 max-[640px]:grid-cols-2">
    <div class="tile"><div class="tile-v">{{ result.total }}</div><div class="tile-l">{{ t('صفوف الملف', 'File rows') }}</div></div>
    <div class="tile green"><div class="tile-v">{{ result.taken }}</div><div class="tile-l">{{ result.dryRun ? t('سيُؤخذ', 'Would be taken') : t('أُخذ', 'Taken') }}</div></div>
    <div class="tile amber"><div class="tile-v">{{ result.duplicates }}</div><div class="tile-l">{{ t('مكرر (موجود مسبقًا)', 'Duplicate (already held)') }}</div></div>
    <div class="tile red"><div class="tile-v">{{ result.refused }}</div><div class="tile-l">{{ t('مرفوض', 'Refused') }}</div></div>
    <div class="tile purple"><div class="tile-v" :class="signClass(result.takenTotal)">{{ money(result.takenTotal) }}</div><div class="tile-l">{{ t('مجموع المأخوذ', 'Total taken') }}</div></div>
  </div>
  <div v-if="result.balanceBreaks > 0" class="hint amber">{{ t(`الرصيد الجاري في الملف ينقطع ${result.balanceBreaks} مرة — قد تكون في الملف صفوف ناقصة.`, `The file’s running balance breaks ${result.balanceBreaks} time(s) — rows may be missing from the file.`) }}</div>
  <div v-if="!result.dryRun && result.statement?.totals" class="hint !mt-2">
    {{ t('الكشف الآن', 'The statement now') }}: {{ t('السطور', 'lines') }} <b class="num">{{ result.statement.totals.lines }}</b> ·
    {{ t('الفرق', 'difference') }} <b class="num ltr" :class="result.statement.totals.balanced ? 'text-ok' : 'text-bad'">{{ result.statement.totals.balanced ? '✓ 0' : money(result.statement.totals.difference) }}</b>
  </div>

  <Tabs v-model="filter" :tabs="tabs" variant="pill" class="!mb-2 mt-3" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :page-size="50" :row-key="(r) => String(r.row)" dense :empty-text="{ ar: 'لا صفوف في هذه القائمة.', en: 'No row in this list.' }">
      <template #cell-status="{ row }"><Chip small :map="IMPORT_ROW" :k="row.status" /></template>
      <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.date) }}</span></template>
      <template #cell-amount="{ row }"><span class="num ltr" :class="signClass(row.amount)">{{ money(row.amount) }}</span></template>
      <template #cell-reference="{ row }"><span class="truncate text-[10px]" dir="auto">{{ row.reference || '—' }}</span></template>
      <template #cell-detail="{ row }">
        <span v-if="row.status === 'taken'" class="text-[10px] text-ok">{{ result.dryRun ? t('سيصير السطر', 'Would become line') : t('صار السطر', 'Became line') }} <b class="num">#{{ row.lineNo }}</b></span>
        <span v-else-if="row.status === 'duplicate'" class="text-[10px] text-warn">
          {{ t('موجود مسبقًا', 'Already held') }}<template v-if="row.existing?.statement"> — <span class="num ltr">{{ row.existing.statement }}</span> #<span class="num">{{ row.existing.lineNo }}</span></template>
        </span>
        <span v-else class="text-[10px] text-bad" dir="auto" :title="row.code">{{ row.reason ? bi(row.reason) : row.code }}</span>
      </template>
    </DataTable>
  </div>
</template>
