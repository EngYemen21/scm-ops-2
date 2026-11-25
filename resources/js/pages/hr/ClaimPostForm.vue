<script setup>
// Post an approved claim to the books: POST /hr/claims/{id}/post (expense.review + expense.create).
// Every line becomes ONE expense voucher of the finance section, charged to an expense account:
//   a line the company paid    leaves the bank account / cash box named here
//   a line the employee paid   is accrued (the company owes it back, paid later with «تسجيل السداد»), or —
//                              "reimbursed now" — is paid on the spot from the bank account / cash box named here
// A line's account comes from its category; finance may name another one here. The voucher is dated as its expense
// unless one accounting date is given (a closed period refuses a posting dated in it).
//   <ClaimPostForm :open :claim="detail" @close />
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, PillChoice, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import HrFormDrawer from './HrFormDrawer.vue';
import { PAID_BY, SELECT, fmtDay, money, pname, todayIso, useHrLookups } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  claim: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['hr', 'finance'] });
const lookups = useHrLookups(() => props.open);
const f = reactive({ dating: 'each', date: '', bankAccount: '', reimburse: 'later', reference: '', costCenter: '', accounts: {} });
watch(() => props.open, (open) => {
  if (!open || !props.claim) return;
  Object.assign(f, { dating: 'each', date: todayIso(), bankAccount: '', reimburse: 'later', reference: '', costCenter: '', accounts: Object.fromEntries(props.claim.lines.map((l) => [l.id, l.accountCode || ''])) });
  act.clearError();
}, { immediate: true });

const lines = computed(() => props.claim?.lines || []);
const due = computed(() => Number(props.claim?.employeeDue) || 0);
const companyPaid = computed(() => Number(props.claim?.companyPaid) || 0);
const now = computed(() => due.value > 0 && f.reimburse === 'now');
const needsBank = computed(() => companyPaid.value > 0 || now.value);
const missingAccounts = computed(() => lines.value.filter((l) => !f.accounts[l.id]).length);
const ok = computed(() => missingAccounts.value === 0 && (!needsBank.value || !!f.bankAccount) && (!now.value || f.reference.trim().length >= 2) && (f.dating === 'each' || (!!f.date && f.date <= todayIso())));
const missing = computed(() => (missingAccounts.value ? { ar: `حدِّد حساب المصروف لـ ${missingAccounts.value} سطرًا`, en: `Name the expense account of ${missingAccounts.value} line(s)` }
  : needsBank.value && !f.bankAccount ? { ar: 'حدِّد الحساب البنكي أو الصندوق', en: 'Name the bank account or cash box' }
    : now.value && f.reference.trim().length < 2 ? { ar: 'اكتب مرجع صرف التعويض', en: 'Write the reference of the reimbursement' } : { ar: 'حدِّد تاريخًا ليس في المستقبل', en: 'Give a date that is not in the future' }));

async function submit() {
  const body = { accounts: { ...f.accounts }, reimburse: now.value ? 'now' : 'later', ...(needsBank.value ? { bankAccount: f.bankAccount } : {}), ...(now.value ? { reference: f.reference.trim() } : {}),
    ...(f.dating === 'one' ? { date: f.date } : {}), ...(f.costCenter ? { costCenter: f.costCenter } : {}) };
  const done = await act.run(() => api.postIdempotent(`/hr/claims/${props.claim.id}/post`, body), { success: (c) => (c.state === 'paid' ? t('رُحِّلت المطالبة وأُقفلت', 'Posted and settled') : t('رُحِّلت المطالبة — بانتظار السداد', 'Posted — waiting for payment')) });
  if (done) emit('close');
}
</script>

<template>
  <HrFormDrawer :open="open" :width="640" :title="{ ar: 'ترحيل إلى الدفاتر', en: 'Post to the books' }" :sub="claim ? `${claim.number} · ${money(claim.total)} ${claim.currency}` : null"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="missing" :submit-label="{ ar: 'ترحيل', en: 'Post' }" submit-tone="primary"
                :note="{ ar: 'يُنشأ سند مصروف لكل سطر في قسم المالية بقيده المحاسبي؛ عكس الترحيل يلغي السندات ويعكس قيودها', en: 'One expense voucher per line is created in the finance section with its journal; reversing the posting voids the vouchers and reverses their journals' }"
                @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="card-title mb-1.5">{{ t('حساب كل سطر', 'The account of each line') }}</div>
    <div class="col mb-4 !gap-2">
      <div v-for="l in lines" :key="l.id" class="rounded-[12px] border border-line bg-white p-2.5">
        <div class="row wrap mb-1.5 text-[10.5px]">
          <span class="cell-date">{{ fmtDay(l.date) }}</span><b>{{ l.category ? pname(l.category) : '—' }}</b><span class="min-w-0 flex-1 truncate text-sec" dir="auto">{{ l.description }}</span>
          <span class="chip sm" :style="{ color: PAID_BY[l.paidBy]?.fg, background: PAID_BY[l.paidBy]?.bg }">{{ t(PAID_BY[l.paidBy]?.ar, PAID_BY[l.paidBy]?.en) }}</span>
          <b class="num">{{ money(l.total) }}</b>
        </div>
        <SelectInput small :model-value="f.accounts[l.id] || ''" :options="lookups.accountOptions.value" :placeholder="SELECT" :error="f.accounts[l.id] ? null : t('اختر حساب المصروف', 'Pick the expense account')"
                     @update:model-value="(v) => (f.accounts[l.id] = v)" />
      </div>
    </div>

    <div class="form-grid">
      <PillChoice v-if="due > 0" v-model="f.reimburse" full :label="{ ar: `ما دفعه الموظف (${money(due)})`, en: `What the employee paid (${money(due)})` }"
                  :options="[['later', { ar: 'يُسجَّل مستحقًا ويُسدَّد لاحقًا', en: 'Accrue it, pay later' }], ['now', { ar: 'يُصرف الآن من الصندوق / البنك', en: 'Reimburse now from cash / bank' }]]" />
      <SelectInput v-if="needsBank" v-model="f.bankAccount" required :label="{ ar: 'الحساب البنكي / الصندوق', en: 'Bank account / cash box' }" :options="lookups.bankOptions.value" :placeholder="SELECT"
                   :hint="companyPaid > 0 ? { ar: `خرج منه ما دفعته الشركة (${money(companyPaid)})${now ? ' ويُصرف منه التعويض' : ''}`, en: `What the company paid (${money(companyPaid)}) left from it${now ? ', and the reimbursement is paid from it' : ''}` } : { ar: 'يُصرف منه التعويض', en: 'The reimbursement is paid from it' }" />
      <TextInput v-if="now" v-model="f.reference" required :label="{ ar: 'مرجع الصرف (سند / حوالة)', en: 'Payment reference' }" dir="ltr" />
      <PillChoice v-model="f.dating" full :label="{ ar: 'تاريخ السندات', en: 'Date of the vouchers' }" :options="[['each', { ar: 'كل سند بتاريخ مصروفه', en: 'Each voucher on its expense date' }], ['one', { ar: 'تاريخ واحد', en: 'One date' }]]" />
      <DateInput v-if="f.dating === 'one'" v-model="f.date" required :label="{ ar: 'التاريخ المحاسبي', en: 'Accounting date' }" :max="todayIso()" :hint="{ ar: 'يلزم إن كانت فترة المصروف مغلقة', en: 'Needed when the period of the expense is closed' }" />
      <SelectInput v-model="f.costCenter" :label="{ ar: 'مركز التكلفة', en: 'Cost center' }" :options="lookups.costCenterOptions.value" :placeholder="{ ar: '— بلا مركز تكلفة —', en: '— none —' }" />
    </div>
  </HrFormDrawer>
</template>
