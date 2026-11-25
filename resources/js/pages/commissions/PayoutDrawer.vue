<script setup>
// Releasing the payout of an approved statement (commission.approve): POST /commissions/statements/{number}/payout.
// It posts ONE expense voucher of the finance module — Dr the commission expense account / Cr the bank account or cash
// box — so the form asks for exactly what that voucher needs: the expense account, where the money leaves from, the
// day and the reference of the transfer. GET /commissions/payout-options lists the accounts and remembers the last pair.
// A statement that totals zero is closed with nothing to pay: no voucher, nothing to fill in.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { API, SELECT_PLACEHOLDER, money, periodLabel, personName, recordName, sar } from './commissions';

const props = defineProps({
  open: { type: Boolean, default: false },
  statement: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['commissions', 'finance'] });
const optionsQ = useGet(`${API}/payout-options`, undefined, { enabled: () => props.open, staleTime: 0 });
const options = computed(() => optionsQ.data.value || { accounts: [], banks: [], defaults: {}, costCenter: null });
const today = () => new Date().toISOString().slice(0, 10);
const f = ref({ account: '', bankAccount: '', date: today(), reference: '' });
/** The pair the last payout used is offered — when the form opens and when the choices arrive — never over what the person already picked. */
function offer() {
  const o = options.value.defaults || {};
  if (!f.value.account && o.account) f.value.account = o.account;
  if (!f.value.bankAccount && o.bankAccount) f.value.bankAccount = o.bankAccount;
}
watch(() => props.open, (open) => { if (open) { f.value = { account: '', bankAccount: '', date: today(), reference: '' }; act.clearError(); offer(); } });
watch(options, () => { if (props.open) offer(); });

const zero = computed(() => Math.abs(Number(props.statement?.total || 0)) < 0.005);
const accountOptions = computed(() => options.value.accounts.map((a) => ({ v: a.code, l: `${a.code} · ${recordName(a)}` })));
const bankOptions = computed(() => options.value.banks.map((b) => ({ v: b.code, l: `${recordName(b)}${b.kind === 'cash' ? ` — ${t('صندوق', 'cash box')}` : ''}` })));
const ok = computed(() => zero.value || (!!f.value.account && !!f.value.bankAccount && !!f.value.date && f.value.reference.trim().length > 0));

async function submit() {
  const s = props.statement;
  const body = zero.value ? {} : { account: f.value.account, bankAccount: f.value.bankAccount, date: f.value.date, reference: f.value.reference.trim() };
  const r = await act.run(() => api.postIdempotent(`${API}/statements/${encodeURIComponent(s.number)}/payout`, body), {
    success: (x) => (x.payout?.expense ? t(`صُرف الكشف ${x.number} بسند المصروف ${x.payout.expense.number}`, `Statement ${x.number} paid with expense voucher ${x.payout.expense.number}`) : t(`أُقفل الكشف ${x.number} بلا صرف`, `Statement ${x.number} closed with nothing to pay`)),
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="520" :z-index="80" :title="zero ? { ar: 'إقفال كشف بلا صرف', en: 'Close a statement with nothing to pay' } : { ar: 'صرف كشف العمولة', en: 'Pay out the statement' }"
          :sub="statement ? `${statement.number} · ${personName(statement.beneficiary)} · ${periodLabel(statement.period)}` : null" @close="emit('close')">
    <template v-if="statement">
      <div class="tile purple mb-3"><div class="tile-v">{{ money(statement.total) }} <span class="text-[10px] font-bold text-muted">{{ sar() }}</span></div><div class="tile-l">{{ t('المبلغ المستحق صرفه', 'Amount to pay out') }}</div></div>
      <div v-if="zero" class="hint amber !mt-0">{{ t('إجمالي هذا الكشف صفر: يُقفل «مصروفًا» بلا سند مصروف ولا حركة في الدفاتر.', 'This statement totals zero: it is closed as “paid” with no expense voucher and nothing posted to the books.') }}</div>
      <template v-else>
        <ErrorBanner :error="optionsQ.error.value" :closable="false" />
        <div class="form-grid">
          <SelectInput v-model="f.account" full required :label="{ ar: 'حساب المصروف الذي تُحمَّل عليه العمولة', en: 'Expense account the commission is charged to' }" :options="accountOptions" :placeholder="SELECT_PLACEHOLDER"
                       :hint="{ ar: 'من دليل الحسابات — يُعرض آخر حساب استُخدم لصرف العمولات', en: 'From the chart of accounts — the account the last payout used is offered' }" />
          <SelectInput v-model="f.bankAccount" full required :label="{ ar: 'يُصرف من', en: 'Paid from' }" :options="bankOptions" :placeholder="SELECT_PLACEHOLDER" />
          <DateInput v-model="f.date" required :max="today()" :label="{ ar: 'تاريخ الصرف', en: 'Payout date' }" />
          <TextInput v-model="f.reference" required dir="ltr" :label="{ ar: 'مرجع الحوالة / الشيك', en: 'Transfer / cheque reference' }" @enter="ok && submit()" />
        </div>
        <div class="hint">
          {{ t('يُرحَّل سند مصروف واحد في المالية: مدين حساب المصروف المختار', 'One expense voucher is posted in finance: debit the chosen expense account') }}<template v-if="options.costCenter"> ({{ t('مركز التكلفة', 'cost center') }} <span class="num ltr">{{ options.costCenter }}</span>)</template>{{ t(' / دائن الحساب الذي يُصرف منه. الحوالة نفسها تتم في البنك — هذا قيدها المحاسبي. يمكن إلغاء الصرف لاحقًا بسبب، فيُعكس السند.', ' / credit the account it is paid from. The transfer itself happens at the bank — this is its accounting record. The payout can be voided later with a reason, which reverses the voucher.') }}
        </div>
      </template>
    </template>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="success" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!ok" :label="zero ? { ar: 'إقفال الكشف', en: 'Close the statement' } : { ar: 'ترحيل السند وصرف الكشف', en: 'Post the voucher and pay' }" @click="submit" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="!ok && !act.pending.value" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">{{ t('أكمل الحقول الإلزامية (*) لتفعيل الزر', 'Fill in the required fields (*) to enable the button') }}</div>
    </template>
  </Drawer>
</template>
