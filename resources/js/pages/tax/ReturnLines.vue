<script setup>
// The form of the return, lines 1–16 as the authority prints them. Lines 1–12 come from `lines[]` (6 and 12 are sums,
// in bold); 13–16 from `totals`. Every figure that documents stand behind opens them: `open({ box, column })` →
// GET /tax/returns/{id}/entries?box=… (box = 1–12, corrections, unclassified, outside).
import { computed } from 'vue';
import { DataTable } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { sar, signClass } from './tax';

const props = defineProps({
  ret: { type: Object, required: true },
  /** The credit brought forward may be edited (draft + vatreturn.manage). */
  canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['open', 'editCredit']);

const lines = computed(() => props.ret.lines || []);
const totals = computed(() => props.ret.totals || {});
const open = (box, column = null) => emit('open', { box: String(box), column });

const columns = [
  { key: 'box', header: { ar: 'البند', en: 'Line' }, width: '56px', kind: 'id' },
  { key: 'label', header: { ar: 'البيان', en: 'Description' }, width: 'minmax(220px,2fr)' },
  { key: 'amount', header: { ar: 'المبلغ (ر.س)', en: 'Amount (SAR)' }, width: '112px', kind: 'num' },
  { key: 'adjustment', header: { ar: 'التعديل (ر.س)', en: 'Adjustment (SAR)' }, width: '112px', kind: 'num' },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '112px', kind: 'num' },
  { key: 'vat', header: { ar: 'الضريبة', en: 'VAT' }, width: '112px', kind: 'num' },
  { key: 'count', header: { ar: 'مستندات', en: 'Docs' }, width: '64px', kind: 'num' },
];
const rowStyle = (r) => (r.sum ? { fontWeight: 800, background: '#F6F4FA' } : null);
</script>

<template>
  <DataTable :columns="columns" :rows="lines" :row-key="(r) => r.box" :row-style="rowStyle" :zebra="false" dense>
    <template #cell-box="{ row }"><span class="num font-extrabold">{{ row.box }}</span></template>
    <template #cell-label="{ row }"><span class="truncate text-[10.5px]" :class="row.sum ? 'font-extrabold' : ''" :title="bi(row)">{{ bi(row) }}</span></template>
    <template #cell-amount="{ row }"><button type="button" class="num underline decoration-dotted" :class="signClass(row.amount)" :title="t('المستندات خلف المبلغ', 'Documents behind the amount')" @click.stop="open(row.box, row.sum ? null : 'amount')">{{ sar(row.amount) }}</button></template>
    <template #cell-adjustment="{ row }"><button type="button" class="num underline decoration-dotted" :class="signClass(row.adjustment)" :title="t('المستندات خلف التعديل', 'Documents behind the adjustment')" @click.stop="open(row.box, row.sum ? null : 'adjustment')">{{ sar(row.adjustment) }}</button></template>
    <template #cell-total="{ row }"><button type="button" class="num underline decoration-dotted" :class="signClass(row.total)" @click.stop="open(row.box)">{{ sar(row.total) }}</button></template>
    <template #cell-vat="{ row }">
      <button v-if="row.hasVat" type="button" class="num underline decoration-dotted" :class="signClass(row.vat)" @click.stop="open(row.box)">{{ sar(row.vat) }}</button>
      <span v-else class="text-faint">—</span>
    </template>
    <template #cell-count="{ row }"><button type="button" class="num underline decoration-dotted" @click.stop="open(row.box)">{{ fmtNum(row.count || 0) }}</button></template>
  </DataTable>

  <!-- lines 13–16: computed by the form -->
  <div class="mt-2 grid gap-1.5">
    <div class="tile flex items-center gap-2">
      <span class="num w-7 font-extrabold">13</span><span class="flex-1 text-[10.5px] font-bold">{{ t('إجمالي ضريبة القيمة المضافة المستحقة عن الفترة الحالية', 'Total VAT due for current period') }}</span>
      <b class="num" :class="signClass(totals.vatDuePeriod)">{{ sar(totals.vatDuePeriod) }}</b>
    </div>
    <div class="tile flex items-center gap-2" :class="ret.corrections?.overLimit ? 'amber' : ''">
      <span class="num w-7 font-extrabold">14</span><span class="flex-1 text-[10.5px] font-bold">{{ t('تصحيحات من الفترات السابقة (بين ±5,000 ريال)', 'Corrections from previous period (between SAR ±5,000)') }}</span>
      <button type="button" class="num font-extrabold underline decoration-dotted" :class="signClass(totals.corrections)" @click="open('corrections')">{{ sar(totals.corrections) }}</button>
    </div>
    <div class="tile flex items-center gap-2">
      <span class="num w-7 font-extrabold">15</span><span class="flex-1 text-[10.5px] font-bold">{{ t('ضريبة القيمة المضافة المرحَّلة من الفترات السابقة', 'VAT credit carried forward from previous period(s)') }}</span>
      <b class="num">{{ sar(totals.creditCarried) }}</b>
      <button v-if="canEdit" type="button" class="pill !h-7" @click="emit('editCredit')">{{ t('تعديل', 'Edit') }}</button>
    </div>
    <div class="tile purple flex items-center gap-2">
      <span class="num w-7 font-extrabold">16</span><span class="flex-1 text-[11px] font-extrabold">{{ t('صافي الضريبة المستحقة (أو المستردة)', 'Net VAT due (or reclaimed)') }}</span>
      <b class="num text-[14px]" :class="signClass(totals.netDue)">{{ sar(totals.netDue) }}</b>
    </div>
  </div>

  <div class="mt-2 flex flex-wrap gap-2 text-[10px]">
    <button v-if="ret.unclassified" type="button" class="pill" :class="ret.unclassified.count ? '!border-[#F3C4C4] !text-bad' : ''" @click="open('unclassified')">
      {{ t('بلا تصنيف', 'Unclassified') }}: <b class="num">{{ fmtNum(ret.unclassified.count || 0) }}</b> · <span class="num">{{ sar(ret.unclassified.vat) }}</span>
    </button>
    <button v-if="ret.outside" type="button" class="pill" @click="open('outside')">
      {{ t('خارج الإقرار', 'Outside the return') }}: <b class="num">{{ fmtNum(ret.outside.count || 0) }}</b> · <span class="num">{{ sar(ret.outside.base) }}</span>
    </button>
    <button v-if="ret.corrections?.late" type="button" class="pill" @click="open('corrections')">
      {{ t('مستندات متأخرة', 'Late documents') }}: <b class="num">{{ fmtNum(ret.corrections.late.count || 0) }}</b>
      · {{ t('يدوي', 'manual') }} <span class="num">{{ sar(ret.corrections.manual) }}</span>
    </button>
  </div>
</template>
