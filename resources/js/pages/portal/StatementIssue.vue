<script setup>
// A statement of account from the OPS receivables: pick the customer (or one of its branches) and the period, look at
// what it would say — nothing is stored — then issue it: numbered and kept line by line, as the customer is given it.
//   <StatementIssue :open="open" @close="…" @done="(statement) => …" />
// GET  /portal/statements/preview?customer&branch&from&to → { openingBalance, totalDebit, totalCredit, closingBalance, linesCount, counts,
//        lines: [first 500], more, books: { closing, agrees }, existing: number | null }
// POST /portal/statements { customer, branch?, from, to, note? } → the issued statement.
// A branch statement needs the branch to be tied to its delivery site (that is what attributes orders and invoices to
// it); a branch that is not says so. `books` compares a customer statement with the general ledger on its last day.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, Drawer, ErrorBanner, SelectInput, TextArea } from '@/components';
import { fmtNum, t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import { LINE_KIND, day, monthStartIso, money, todayIso } from './portal';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** A customer code to start from (the list is filtered by one). */
  customer: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const f = ref({ customer: '', branch: '', from: monthStartIso(), to: todayIso(), note: '' });
const act = useAction({ invalidate: ['portal'] });
watch(() => props.open, (o) => { if (o) { f.value = { customer: props.customer && !props.customer.startsWith('ref:') ? props.customer : '', branch: '', from: monthStartIso(), to: todayIso(), note: '' }; act.clearError(); } }, { immediate: true });

const branches = useList('/portal/branches', () => ({ customer: f.value.customer, pageSize: 200, sort: 'sequence', order: 'asc' }), { enabled: () => props.open && !!f.value.customer });
const branchOptions = computed(() => (branches.data.value?.items || []).map((b) => ({ v: b.id, l: `${b.name}${b.site ? '' : t(' — بلا موقع تسليم', ' — no delivery site')}`, disabled: !b.site })));
const periodOk = computed(() => !!f.value.from && !!f.value.to && f.value.from <= f.value.to);
const ready = computed(() => props.open && !!f.value.customer && periodOk.value);

const preview = useGet(() => (ready.value ? '/portal/statements/preview' : null), () => ({ customer: f.value.customer, branch: f.value.branch || undefined, from: f.value.from, to: f.value.to }), { staleTime: 0 });
const p = computed(() => (ready.value ? preview.data.value || null : null));

const columns = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', value: (l) => day(l.date) },
  { key: 'kind', header: { ar: 'النوع', en: 'Type' }, width: '98px', bare: true },
  { key: 'reference', header: { ar: 'المرجع', en: 'Reference' }, width: '140px', kind: 'id' },
  { key: 'description', header: { ar: 'البيان', en: 'Description' }, width: 'minmax(140px,1.6fr)' },
  { key: 'debit', header: { ar: 'مدين', en: 'Debit' }, width: '90px', kind: 'num' },
  { key: 'credit', header: { ar: 'دائن', en: 'Credit' }, width: '90px', kind: 'num' },
  { key: 'balance', header: { ar: 'الرصيد', en: 'Balance' }, width: '98px', kind: 'num' },
];

async function issue() {
  const r = await act.run(() => api.postIdempotent('/portal/statements', { customer: f.value.customer, ...(f.value.branch ? { branch: f.value.branch } : {}), from: f.value.from, to: f.value.to, ...(f.value.note.trim() ? { note: f.value.note.trim() } : {}) }),
    { success: (s) => t(`صدر كشف الحساب ${s.number}`, `Statement ${s.number} issued`) });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="820" :z-index="70" :title="{ ar: 'كشف حساب من ذمم نظام العمليات', en: 'A statement from the OPS receivables' }" :sub="{ ar: 'معاينة لا تحفظ شيئًا، ثم إصدار يحفظ الكشف كما يُسلَّم للعميل', en: 'A preview that stores nothing, then an issue that keeps the statement as the customer is given it' }" @close="emit('close')">
    <div class="form-grid mb-3">
      <CustomerPicker :value="f.customer" :label="{ ar: 'العميل', en: 'Customer' }" required inactive @pick="(c) => (f = { ...f, customer: c ? c.code : '', branch: '' })" />
      <SelectInput v-model="f.branch" :label="{ ar: 'الفرع', en: 'Branch' }" :options="branchOptions" :placeholder="{ ar: '— العميل كله —', en: '— the whole customer —' }" :disabled="!f.customer"
                   :hint="{ ar: 'كشف الفرع = فواتير الطلبات المسلَّمة لموقعه وما سُدّد منها', en: 'A branch statement = the invoices of the orders delivered to its site, and what was paid of them' }" />
      <DateInput v-model="f.from" :label="{ ar: 'من تاريخ', en: 'From' }" required :max="f.to || null" />
      <DateInput v-model="f.to" :label="{ ar: 'إلى تاريخ', en: 'To' }" required :min="f.from || null" :error="f.from && f.to && f.from > f.to ? t('يجب ألا يسبق البداية', 'Must not be before the start') : null" />
    </div>

    <ErrorBanner :error="preview.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="!f.customer" class="empty dashed !rounded-xl !p-4">{{ t('اختر العميل والفترة لمعاينة كشفه.', 'Pick the customer and the period to preview its statement.') }}</div>
    <div v-else-if="preview.isFetching.value && !p" class="skel h-[180px]" />
    <template v-else-if="p">
      <div v-if="p.existing" class="hint amber !mt-0 mb-3">{{ t(`الكشف ${p.existing} صادر للعميل نفسه عن الفترة نفسها. لا يصدر كشف ثانٍ قبل إلغاء الأول.`, `Statement ${p.existing} already stands for the same customer and period. A second one is not issued before the first is voided.`) }}</div>
      <div v-if="p.books.agrees === false" class="hint amber !mt-0 mb-3">{{ t(`رصيد العميل في الأستاذ العام في آخر الفترة ${money(p.books.closing)} — يختلف عن الكشف لأن في الدفاتر حركة ليست فاتورة ولا سند قبض (قيد افتتاحي أو يدوي). راجعها مع المالية قبل إصدار الكشف.`, `The customer’s balance in the general ledger on the last day is ${money(p.books.closing)} — it differs from the statement because the books hold an entry that is neither an invoice nor a receipt (an opening or a manual journal). Check it with finance before issuing.`) }}</div>
      <div v-else-if="p.books.agrees" class="mb-3 text-[10px] font-bold text-ok">✓ {{ t('يطابق رصيد العميل في الأستاذ العام في آخر الفترة.', 'Agrees with the customer’s balance in the general ledger on the last day.') }}</div>

      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ money(p.openingBalance) }}</div><div class="tile-l">{{ t('رصيد افتتاحي', 'Opening balance') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(p.totalDebit) }}</div><div class="tile-l">{{ t('مدين', 'Debit') }} · {{ fmtNum(p.counts.invoice) }} {{ t('فاتورة', 'invoice(s)') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(p.totalCredit) }}</div><div class="tile-l">{{ t('دائن', 'Credit') }} · {{ fmtNum(p.counts.payment) }} {{ t('سداد', 'payment(s)') }}</div></div>
        <div class="tile" :class="Number(p.closingBalance) > 0 ? 'amber' : 'green'"><div class="tile-v">{{ money(p.closingBalance) }}</div><div class="tile-l">{{ t('رصيد ختامي', 'Closing balance') }} {{ p.currency }}</div></div>
      </div>
      <div class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="columns" :rows="p.lines" :row-key="(l, i) => i" :page-size="50" dense :empty-text="{ ar: 'لا حركات في هذه الفترة — الكشف رصيد افتتاحي فقط.', en: 'No movement in this period — the statement is its opening balance only.' }">
          <template #cell-kind="{ row }"><Chip small :map="LINE_KIND" :k="row.kind" /></template>
          <template #cell-description="{ row }"><span class="truncate" dir="auto" :title="row.description">{{ row.description || '—' }}</span></template>
          <template #cell-debit="{ row }"><span v-if="Number(row.debit)" class="num">{{ money(row.debit) }}</span><span v-else class="text-faint">—</span></template>
          <template #cell-credit="{ row }"><span v-if="Number(row.credit)" class="num text-ok">{{ money(row.credit) }}</span><span v-else class="text-faint">—</span></template>
          <template #cell-balance="{ row }"><span class="num font-extrabold">{{ money(row.balance) }}</span></template>
        </DataTable>
      </div>
      <div v-if="p.more" class="mt-1 text-[9.5px] text-faint">{{ t(`تُعرض أول 500 حركة من ${fmtNum(p.linesCount)}؛ الأرصدة والمجاميع تشمل الفترة كلها، والكشف الصادر يحمل كل الحركات.`, `The first 500 of ${fmtNum(p.linesCount)} lines are shown; the balances and totals cover the whole period, and the issued statement carries every line.`) }}</div>
      <div class="mt-3"><TextArea v-model="f.note" :rows="2" :label="{ ar: 'ملاحظة على الكشف (اختياري)', en: 'A note on the statement (optional)' }" /></div>
    </template>

    <template #footer>
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!p || !!p.existing" :label="{ ar: 'إصدار الكشف', en: 'Issue the statement' }" @click="issue" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('الكشف الصادر نسخة ثابتة: لا يتغيّر بتغيّر الحساب بعده، ويُلغى بسبب إن لزم. يُسجَّل في Audit Trail.', 'An issued statement is a fixed copy: it does not move when the account does afterwards, and is voided with a reason if need be. Recorded in the audit trail.') }}</div>
    </template>
  </Drawer>
</template>
