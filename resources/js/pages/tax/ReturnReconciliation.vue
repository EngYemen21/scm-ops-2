<script setup>
// The return against the ledger (`reconciliation`): for output and input VAT the bridge from the balance of the VAT
// accounts to the VAT of the return (`holds` = both equations hold), and the movements on those accounts that no
// document stands behind (`items[]`). Each is explained (treatment + reason), taken in with an adjustment line tied to
// it (`ledgerLineId`), or its explanation withdrawn — POST /tax/returns/{id}/explanations[/{note}/withdraw].
import { computed } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { SIDE, TONE, fmtDay, labelOf, sar, signClass, useTaxLookups } from './tax';

const props = defineProps({
  ret: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['explain', 'takeIn']);

const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const rec = computed(() => props.ret.reconciliation || null);
const items = computed(() => rec.value?.items || []);
const BRIDGE = [
  ['ledger', { ar: 'رصيد حسابات الضريبة في الفترة', en: 'VAT accounts in the period' }],
  ['documents', { ar: 'منها: ضريبة المستندات', en: 'of which: VAT of documents' }],
  ['outside', { ar: '− حركات مفسَّرة خارج الإقرار', en: '− explained movements outside the return' }],
  ['asCorrections', { ar: '− مأخوذ تصحيحاتٍ (بند 14)', en: '− taken as corrections (line 14)' }],
  ['adjustedFromLedger', { ar: 'مأخوذ بسطور تسوية', en: 'taken by adjustment lines' }],
  ['unexplained', { ar: '− غير مفسَّر', en: '− unexplained' }],
  ['reverseCharge', { ar: '+ احتساب عكسي ليس في الدفاتر', en: '+ reverse charge not in the books' }],
  ['adjustments', { ar: '+ تسويات حرة', en: '+ free adjustments' }],
  ['return', { ar: '= ضريبة الإقرار', en: '= VAT of the return' }],
];
const HOLDS = { yes: { ar: 'متوازن', en: 'Holds', ...TONE.ok }, no: { ar: 'غير متوازن', en: 'Does not hold', ...TONE.bad } };

async function withdraw(item) {
  if (!item.note) return;
  if (!(await confirm({ title: { ar: 'سحب التفسير؟', en: 'Withdraw the explanation?' }, sub: { ar: 'تعود الحركة غير مفسَّرة ويُسجَّل السحب في سجل التدقيق.', en: 'The movement becomes unexplained again; the withdrawal is recorded in the audit trail.' }, tone: 'danger' }))) return;
  await act.run(() => api.postIdempotent(`/tax/returns/${encodeURIComponent(props.ret.id)}/explanations/${encodeURIComponent(item.note.id)}/withdraw`), { success: t('سُحب التفسير', 'Explanation withdrawn') });
}

const columns = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.date) },
  { key: 'journal', header: { ar: 'القيد', en: 'Journal' }, width: '104px', kind: 'id' },
  { key: 'account', header: { ar: 'الحساب / البيان', en: 'Account / text' }, width: 'minmax(180px,2fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '96px', kind: 'num' },
  { key: 'rest', header: { ar: 'المتبقي', en: 'Rest' }, width: '96px', kind: 'num' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: 'minmax(130px,1fr)', bare: true },
  { key: 'actions', header: '', width: '150px', bare: true },
];
</script>

<template>
  <div v-if="rec">
    <div class="mb-2 flex flex-wrap items-center gap-2">
      <Chip :map="HOLDS" :k="rec.balanced ? 'yes' : 'no'" />
      <span class="text-[10.5px] text-sec">{{ t('غير مفسَّر إجمالًا', 'Unexplained in all') }}: <b class="num" :class="Number(rec.unexplained) ? 'text-bad' : ''">{{ sar(rec.unexplained) }}</b></span>
    </div>
    <div class="mb-3 grid gap-3 md:grid-cols-2">
      <div v-for="side in ['output', 'input']" :key="side" class="tile">
        <div class="mb-1 flex items-center gap-2">
          <b class="flex-1 text-[11px]">{{ labelOf(SIDE, side) }}</b>
          <Chip small :map="HOLDS" :k="rec[side]?.holds ? 'yes' : 'no'" />
        </div>
        <div v-for="[k, label] in BRIDGE" :key="k" class="flex items-center gap-2 border-b border-line py-1 text-[10px] last:border-0" :class="k === 'return' ? 'font-extrabold' : ''">
          <span class="flex-1">{{ t(label.ar, label.en) }}</span>
          <span class="num" :class="[signClass(rec[side]?.[k]), k === 'unexplained' && Number(rec[side]?.[k]) ? 'font-extrabold text-bad' : '']">{{ sar(rec[side]?.[k]) }}</span>
        </div>
      </div>
    </div>

    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="card-title mb-1">{{ t('حركات حسابات الضريبة بلا مستند', 'VAT-account movements without a document') }}</div>
    <DataTable :columns="columns" :rows="items" :row-key="(r) => r.journalLineId" :page-size="20" :empty-text="{ ar: 'لا حركات بلا مستند في هذه الفترة', en: 'No movement without a document in this period' }">
      <template #cell-journal="{ row }"><span class="num ltr text-[10px]">{{ row.journal || '—' }}</span></template>
      <template #cell-account="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[10.5px] font-bold"><span class="num ltr">{{ row.account }}</span> · {{ t(row.accountNameAr, row.accountNameEn || row.accountNameAr) }}</div>
          <div class="truncate text-[9.5px] text-muted" dir="auto">{{ labelOf(SIDE, row.side) }}<template v-if="row.text"> · {{ row.text }}</template><template v-if="row.late"> · {{ t('من فترة سابقة', 'from an earlier period') }} <span class="num">{{ row.lateOfPeriod }}</span></template></div>
        </div>
      </template>
      <template #cell-amount="{ row }"><span class="num" :class="signClass(row.amount)">{{ sar(row.amount) }}</span></template>
      <template #cell-rest="{ row }"><span class="num" :class="Number(row.rest) && !row.explained ? 'font-extrabold text-bad' : ''">{{ sar(row.rest) }}</span></template>
      <template #cell-state="{ row }">
        <div class="min-w-0 text-center">
          <Chip small :label="row.explained ? { ar: 'مفسَّرة', en: 'Explained' } : { ar: 'غير مفسَّرة', en: 'Unexplained' }" :fg="row.explained ? TONE.ok.fg : TONE.bad.fg" :bg="row.explained ? TONE.ok.bg : TONE.bad.bg" />
          <div v-if="row.note" class="mt-0.5 truncate text-[9px] text-muted" :title="row.note.reason">{{ labelOf(lk.treatmentMap.value, row.note.treatment) }} · {{ row.note.reason }}</div>
          <div v-if="Number(row.adjusted)" class="text-[9px] text-muted">{{ t('مأخوذ بتسوية', 'adjusted') }} <span class="num">{{ sar(row.adjusted) }}</span></div>
        </div>
      </template>
      <template #cell-actions="{ row }">
        <div v-if="canEdit" class="flex flex-wrap justify-center gap-1">
          <template v-if="!row.note">
            <Btn size="sm" tone="softBlue" :label="{ ar: 'تفسير', en: 'Explain' }" @click.stop="emit('explain', row)" />
            <Btn v-if="Number(row.rest)" size="sm" tone="softPurple" :label="{ ar: 'إدخال بتسوية', en: 'Take in' }" @click.stop="emit('takeIn', row)" />
          </template>
          <Btn v-else size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'سحب التفسير', en: 'Withdraw' }" @click.stop="withdraw(row)" />
        </div>
        <span v-else class="text-faint">—</span>
      </template>
    </DataTable>
  </div>
  <div v-else class="text-[10.5px] text-muted">{{ t('لم يُحتسب الإقرار بعد', 'The return has not been computed yet') }}</div>
</template>
