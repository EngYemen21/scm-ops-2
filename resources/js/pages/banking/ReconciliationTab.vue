<script setup>
// «كشف التسوية» — the reconciliation statement of one bank at a date (GET /banking/reconciliation?bank=&date=), the
// paper an auditor asks for:
//   balance per bank statement                    balance per books
//   + deposits in transit                         + bank credits not booked
//   − outstanding payments                        − bank debits not booked
//   = adjusted bank balance                       = adjusted book balance          → difference, explained to zero
// Every figure is the server's (decimal strings, shown as they come). The open items are listed in collapsible tables;
// «طباعة» prints the paper alone (the shell, the tabs and the controls are `no-print`).
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, EmptyState, ErrorBanner, SelectInput } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { ENTRY_KIND, REC_STATUS, STATEMENT_STATUS, bankLabel, fmtDay, money, nameOf, signClass, toCents, todayIso, useBankingLookups, useBankingNav } from './banking';

const nav = useBankingNav();
const lk = useBankingLookups();
const bankCode = computed(() => nav.bank.value);
const date = computed(() => nav.get('date') || todayIso());
const q = useGet(() => (bankCode.value ? '/banking/reconciliation' : null), () => ({ bank: bankCode.value, date: date.value }));
const r = computed(() => (bankCode.value ? q.data.value || null : null));
const st = computed(() => r.value?.statement || null);
const explainedToZero = computed(() => r.value?.unexplained != null && toCents(r.value.unexplained) === 0);

/** The one honest sentence of each verdict. */
const verdict = computed(() => {
  const x = r.value;
  if (!x) return null;
  switch (x.status) {
    case 'reconciled': return { tone: 'green', text: t(`مطابَق في ${fmtDay(x.date)}: الرصيدان المعدّلان متساويان، وكل ما أظهره البنك مسجّل في الدفاتر.`, `Reconciled at ${fmtDay(x.date)}: the two adjusted balances are equal and everything the bank shows is in the books.`) };
    case 'open_items': return { tone: 'amber', text: t('الرصيدان المعدّلان متساويان، لكن في الكشف حركات لم تُسجَّل في الدفاتر بعد (رسوم، إيداعات…) — سجّلها أو سوّها ثم طابقها.', 'The two adjusted balances are equal, but the bank shows movements that are not in the books yet (charges, deposits…) — book or write them off, then match them.') };
    case 'difference': return { tone: 'red', text: t(`بين الرصيدين المعدّلين فرق ${money(x.difference)}${explainedToZero.value ? ' — وكله مفسَّر أدناه بأخطاء في الكشوف نفسها.' : ' — لم يُفسَّر كله.'}`, `The two adjusted balances differ by ${money(x.difference)}${explainedToZero.value ? ' — all of it explained below by faults of the statements themselves.' : ' — not all of it is explained.'}`) };
    default: return { tone: 'neutral', text: t('لا كشف بنكي لهذا الحساب بدأ حتى هذا التاريخ — يظهر جانب الدفاتر وحده. أضف الكشف أو استورده من «الكشوف».', 'No bank statement of this account has begun by this date — only the books’ side shows. Add or import the statement under “Statements”.') };
  }
});

const entryCols = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date' },
  { key: 'doc', header: { ar: 'المستند', en: 'Document' }, width: 'minmax(130px,1fr)' },
  { key: 'party', header: { ar: 'الطرف / البيان', en: 'Party / memo' }, width: 'minmax(140px,1.4fr)' },
  { key: 'match', header: { ar: 'سُوّي لاحقًا', en: 'Cleared later' }, width: '110px' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
];
const lineCols = [
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date' },
  { key: 'line', header: { ar: 'السطر', en: 'Line' }, width: '110px' },
  { key: 'text', header: { ar: 'المرجع والوصف', en: 'Reference & description' }, width: 'minmax(160px,2fr)' },
  { key: 'match', header: { ar: 'سُوّي لاحقًا', en: 'Cleared later' }, width: '110px' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num' },
];
const groups = computed(() => (r.value ? [
  { k: 'transit', title: { ar: 'إيداعات في الطريق (في الدفاتر، لم تظهر في البنك)', en: 'Deposits in transit (booked, not at the bank yet)' }, part: r.value.outstanding.depositsInTransit, entries: true },
  { k: 'outstanding', title: { ar: 'مدفوعات معلّقة (في الدفاتر، لم تظهر في البنك)', en: 'Outstanding payments (booked, not at the bank yet)' }, part: r.value.outstanding.outstandingPayments, entries: true },
  { k: 'credits', title: { ar: 'دائن في البنك لم يُسجَّل في الدفاتر', en: 'Bank credits not booked' }, part: r.value.unbooked.credits, entries: false },
  { k: 'debits', title: { ar: 'مدين في البنك لم يُسجَّل في الدفاتر', en: 'Bank debits not booked' }, part: r.value.unbooked.debits, entries: false },
] : []));
function print() { window.print(); }
/** Which lists of open items are unfolded: a short list opens by itself; the printed paper always holds every list. */
const shown = ref({});
const isShown = (g) => shown.value[g.k] ?? (g.part.count > 0 && g.part.count <= 20);
const toggle = (g) => { shown.value = { ...shown.value, [g.k]: !isShown(g) }; };
</script>

<template>
  <div class="no-print row wrap mb-2.5">
    <SelectInput small class="w-[260px]" :model-value="bankCode" :options="lk.bankOptions.value" :placeholder="{ ar: '— اختر الحساب البنكي —', en: '— choose the bank account —' }" @update:model-value="(v) => nav.patch({ bank: v })" />
    <DateInput small class="w-[150px]" :model-value="date" @update:model-value="(v) => nav.patch({ date: v && v !== todayIso() ? v : null })" />
    <span class="grow" />
    <Btn v-if="r" size="sm" tone="softPurple" :label="{ ar: 'مساحة المطابقة', en: 'Reconcile workspace' }" @click="nav.go('reconcile', { bank: bankCode })" />
    <Btn v-if="r" size="sm" tone="dark" :label="{ ar: 'طباعة', en: 'Print' }" @click="print" />
  </div>

  <EmptyState v-if="!bankCode" tone="dashed" :text="{ ar: 'اختر حسابًا بنكيًا وتاريخًا لإعداد كشف التسوية.', en: 'Choose a bank account and a date to draw up the reconciliation statement.' }" />
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="bankCode && q.isLoading.value" class="skel h-[320px]" />

  <div v-if="r" class="card p-4">
    <!-- the head of the paper -->
    <div class="flex flex-wrap items-start gap-3 border-b border-line-2 pb-3">
      <div class="min-w-0 flex-1">
        <div class="text-[15px] font-extrabold text-ink">{{ t('كشف التسوية البنكية', 'Bank reconciliation statement') }}</div>
        <div class="text-[11px] text-sec"><span dir="auto">{{ bankLabel(r.bank) }}</span><template v-if="r.bank?.bankName"> · <span dir="auto">{{ r.bank.bankName }}</span></template><template v-if="r.bank?.iban"> · <span class="num ltr">{{ r.bank.iban }}</span></template></div>
        <div class="text-[11px] text-sec">{{ t('في تاريخ', 'As at') }} <b class="num ltr">{{ fmtDay(r.date) }}</b> · {{ t('العملة', 'Currency') }} <span class="num">{{ r.currency }}</span>
          <template v-if="r.book?.ledgerAccount"> · {{ t('حساب الأستاذ', 'Ledger account') }} <span class="num ltr">{{ r.book.ledgerAccount.code }}</span> <span dir="auto">{{ nameOf(r.book.ledgerAccount) }}</span></template></div>
      </div>
      <Chip :map="REC_STATUS" :k="r.status" />
    </div>
    <div v-if="verdict" class="mt-3" :class="verdict.tone === 'neutral' ? 'hint !mt-3' : `banner ${verdict.tone}`">{{ verdict.text }}</div>
    <div v-if="r.truncated" class="banner amber mt-2">{{ t('القوائم أدناه مقتطعة عند 5000 بند — المجاميع كاملة ودقيقة.', 'The lists below are cut at 5,000 items — the totals are complete and exact.') }}</div>
    <div v-if="r.book?.sharedWith?.length" class="hint amber">{{ t(`حساب الأستاذ مشترك مع ${r.book.sharedWith.join('، ')}.`, `The ledger account is shared with ${r.book.sharedWith.join(', ')}.`) }}</div>

    <!-- the two columns of the paper -->
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
      <div class="rounded-[14px] border border-line-2 p-3">
        <div class="card-title mb-2">{{ t('جانب البنك', 'Bank side') }}</div>
        <div class="row !gap-2 py-1 text-[11px]">
          <span class="flex-1">{{ t('الرصيد حسب كشف البنك', 'Balance per bank statement') }}</span>
          <b class="num ltr">{{ st ? money(st.balance) : '—' }}</b>
        </div>
        <div v-if="st" class="pb-1 text-[9.5px] text-muted">
          {{ st.source === 'running' ? t('رصيد جارٍ داخل الكشف: الافتتاحي + السطور حتى التاريخ', 'Running balance inside the statement: opening + lines up to the date') : t('الرصيد الختامي لآخر كشف انتهى قبل التاريخ', 'Closing balance of the last statement ended by the date') }}
          · <button type="button" class="cell-id !text-[9.5px]" @click="nav.openStatement(st.statement.id)">{{ st.statement.number }}</button>
          <Chip small :map="STATEMENT_STATUS" :k="st.statement.status" /> · {{ t('حتى', 'as of') }} <span class="num ltr">{{ fmtDay(st.asOf) }}</span>
          · {{ t(`${st.reconciledStatements} من ${st.statements} كشف مقفل`, `${st.reconciledStatements} of ${st.statements} statements locked`) }}
          <span v-if="st.behindDays > 0" class="font-bold text-warn"> · {{ t(`آخر كشف متأخر ${st.behindDays} يوم عن التاريخ`, `the last statement is ${st.behindDays} day(s) behind the date`) }}</span>
        </div>
        <div class="row !gap-2 py-1 text-[11px]"><span class="flex-1">+ {{ t('إيداعات في الطريق', 'Deposits in transit') }} <span class="num text-faint">({{ r.outstanding.depositsInTransit.count }})</span></span><b class="num ltr text-ok">{{ money(r.outstanding.depositsInTransit.total) }}</b></div>
        <div class="row !gap-2 py-1 text-[11px]"><span class="flex-1">− {{ t('مدفوعات معلّقة', 'Outstanding payments') }} <span class="num text-faint">({{ r.outstanding.outstandingPayments.count }})</span></span><b class="num ltr text-bad">{{ money(r.outstanding.outstandingPayments.total) }}</b></div>
        <div class="row mt-1 !gap-2 border-t-2 border-ink pt-1.5 text-[12px] font-extrabold"><span class="flex-1">= {{ t('رصيد البنك المعدّل', 'Adjusted bank balance') }}</span><span class="num ltr">{{ money(r.adjusted.bank) }}</span></div>
      </div>
      <div class="rounded-[14px] border border-line-2 p-3">
        <div class="card-title mb-2">{{ t('جانب الدفاتر', 'Books side') }}</div>
        <div class="row !gap-2 py-1 text-[11px]"><span class="flex-1">{{ t('الرصيد حسب الدفاتر', 'Balance per books') }}</span><b class="num ltr" :class="signClass(r.book.balance)">{{ money(r.book.balance) }}</b></div>
        <div class="row !gap-2 py-1 text-[11px]"><span class="flex-1">+ {{ t('دائن في البنك لم يُسجَّل', 'Bank credits not booked') }} <span class="num text-faint">({{ r.unbooked.credits.count }})</span></span><b class="num ltr text-ok">{{ money(r.unbooked.credits.total) }}</b></div>
        <div class="row !gap-2 py-1 text-[11px]"><span class="flex-1">− {{ t('مدين في البنك لم يُسجَّل', 'Bank debits not booked') }} <span class="num text-faint">({{ r.unbooked.debits.count }})</span></span><b class="num ltr text-bad">{{ money(r.unbooked.debits.total) }}</b></div>
        <div class="row mt-1 !gap-2 border-t-2 border-ink pt-1.5 text-[12px] font-extrabold"><span class="flex-1">= {{ t('رصيد الدفاتر المعدّل', 'Adjusted book balance') }}</span><span class="num ltr">{{ money(r.adjusted.book) }}</span></div>
      </div>
    </div>

    <!-- the difference, explained to zero -->
    <div v-if="r.status !== 'no_statement'" class="mt-4 rounded-[14px] border border-line-2 p-3">
      <div class="row !gap-2 text-[12px] font-extrabold"><span class="flex-1">{{ t('الفرق (البنك المعدّل − الدفاتر المعدّلة)', 'Difference (adjusted bank − adjusted books)') }}</span><span class="num ltr" :class="toCents(r.difference) === 0 ? 'text-ok' : 'text-bad'">{{ money(r.difference) }}</span></div>
      <div v-for="(e, i) in r.explained" :key="i" class="row !gap-2 py-1 text-[10.5px]">
        <span class="flex-1" dir="auto">− {{ t(e.ar, e.en) }}</span><b class="num ltr">{{ money(e.amount) }}</b>
      </div>
      <div class="row mt-1 !gap-2 border-t border-line-2 pt-1.5 text-[12px] font-extrabold" :class="explainedToZero ? 'text-ok' : 'text-bad'">
        <span class="flex-1">{{ explainedToZero ? t('✓ مفسَّر حتى الصفر', '✓ Explained to zero') : t('غير مفسَّر', 'Unexplained') }}</span><span class="num ltr">{{ money(r.unexplained) }}</span>
      </div>
    </div>

    <!-- the open items -->
    <div v-for="g in groups" :key="g.k" class="mt-3 rounded-[14px] border border-line-2">
      <button type="button" class="row w-full cursor-pointer !gap-2 px-3 py-2 text-start text-[11px] font-extrabold" :aria-expanded="isShown(g)" @click="toggle(g)">
        <span class="no-print w-3 text-faint">{{ isShown(g) ? '▾' : '▸' }}</span><span class="flex-1">{{ bi(g.title) }}</span><span class="num text-faint">{{ g.part.count }}</span><span class="num ltr">{{ money(g.part.total) }}</span>
      </button>
      <div class="border-t border-line-2" :class="isShown(g) ? '' : 'hidden print:block'">
        <DataTable v-if="g.entries" :columns="entryCols" :rows="g.part.items" :page-size="50" :row-key="(x) => x.id" dense :empty-text="{ ar: 'لا بنود.', en: 'No item.' }">
          <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.date) }}</span></template>
          <template #cell-doc="{ row }"><div class="min-w-0"><div class="row !gap-1"><span class="cell-id">{{ row.document?.number || row.journalNumber }}</span><Chip small :map="ENTRY_KIND" :k="row.kind" /></div><div v-if="row.reference" class="cell-sub">{{ row.reference }}</div></div></template>
          <template #cell-party="{ row }"><span class="truncate text-[10px]" dir="auto">{{ row.party ? nameOf(row.party) : row.description || row.memo || '—' }}</span></template>
          <template #cell-match="{ row }"><span v-if="row.match" class="text-[9.5px] text-sec"><span class="num ltr">{{ row.match.number }}</span> · <span class="num ltr">{{ fmtDay(row.match.clearedDate) }}</span></span><span v-else class="text-faint">—</span></template>
          <template #cell-amount="{ row }"><span class="num ltr" :class="signClass(row.amount)">{{ money(row.amount) }}</span></template>
        </DataTable>
        <DataTable v-else :columns="lineCols" :rows="g.part.items" :page-size="50" :row-key="(x) => x.id" dense :empty-text="{ ar: 'لا بنود.', en: 'No item.' }">
          <template #cell-date="{ row }"><span class="cell-date">{{ fmtDay(row.date) }}</span></template>
          <template #cell-line="{ row }"><span class="num ltr text-[10px]">{{ row.statementNumber }}#{{ row.lineNo }}</span></template>
          <template #cell-text="{ row }"><span class="truncate text-[10px]" dir="auto">{{ row.kind === 'opening' ? t('الرصيد الافتتاحي', 'Opening balance') : [row.reference, row.description, row.partnerName].filter(Boolean).join(' · ') || '—' }}</span></template>
          <template #cell-match="{ row }"><span v-if="row.match" class="text-[9.5px] text-sec"><span class="num ltr">{{ row.match.number }}</span> · <span class="num ltr">{{ fmtDay(row.match.clearedDate) }}</span></span><span v-else class="text-faint">—</span></template>
          <template #cell-amount="{ row }"><span class="num ltr" :class="signClass(row.amount)">{{ money(row.amount) }}</span></template>
        </DataTable>
      </div>
    </div>
    <div class="mt-3 text-[9px] text-faint">{{ t('أُعدّ في', 'Prepared') }} <span class="num ltr">{{ fmtDate(q.dataUpdatedAt.value) }}</span> · {{ t('من بيانات OPS في وقت الطلب', 'from OPS data at the time of the request') }}</div>
  </div>
</template>
