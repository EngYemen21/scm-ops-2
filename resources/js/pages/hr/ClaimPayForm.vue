<script setup>
// Record that the reimbursement of a posted claim was paid: POST /hr/claims/{id}/pay (expense.review + expense.create).
// The claim keeps the reference, the date, the method and the account the money left from. Honest about the books:
// the finance section has no flow that settles an accrued expense yet, so this records the payment and posts nothing —
// the accrual is cleared by a journal in the finance section (the claim's card says so).
//   <ClaimPayForm :open :claim="detail" @close />
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, PillChoice, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import HrFormDrawer from './HrFormDrawer.vue';
import { claimOwner, money, todayIso, useHrLookups } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  claim: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['hr'] });
const lookups = useHrLookups(() => props.open);
const f = reactive({ reference: '', date: '', method: 'bank', bankAccount: '' });
watch(() => props.open, (open) => { if (open) { Object.assign(f, { reference: '', date: todayIso(), method: 'bank', bankAccount: '' }); act.clearError(); } }, { immediate: true });
const ok = computed(() => f.reference.trim().length >= 2 && !!f.date && f.date <= todayIso());

async function submit() {
  const done = await act.run(() => api.postIdempotent(`/hr/claims/${props.claim.id}/pay`, { reference: f.reference.trim(), date: f.date, method: f.method, ...(f.bankAccount ? { bankAccount: f.bankAccount } : {}) }),
    { success: t('سُجِّل السداد', 'Payment recorded') });
  if (done) emit('close');
}
</script>

<template>
  <HrFormDrawer :open="open" :width="480" :title="{ ar: 'تسجيل سداد المطالبة', en: 'Record the payment' }" :sub="claim ? `${claim.number} · ${claimOwner(claim)}` : null"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'تسجيل السداد', en: 'Record the payment' }" submit-tone="success"
                :missing="{ ar: 'اكتب مرجع السداد وتاريخًا ليس في المستقبل', en: 'Write the payment reference and a date that is not in the future' }"
                @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="claim" class="hint teal !mt-0 mb-3">{{ t('المبلغ المستحق للموظف', 'Amount due to the employee') }}: <b class="num">{{ money(claim.employeeDue) }}</b> {{ claim.currency }}</div>
    <div class="form-grid">
      <TextInput v-model="f.reference" required :label="{ ar: 'مرجع السداد (رقم الحوالة / السند)', en: 'Payment reference (transfer / voucher no.)' }" dir="ltr" full />
      <DateInput v-model="f.date" required :label="{ ar: 'تاريخ السداد', en: 'Payment date' }" :max="todayIso()" />
      <PillChoice v-model="f.method" :label="{ ar: 'الطريقة', en: 'Method' }" :options="[['bank', { ar: 'تحويل بنكي', en: 'Bank transfer' }], ['cash', { ar: 'نقدًا', en: 'Cash' }], ['cheque', { ar: 'شيك', en: 'Cheque' }]]" />
      <SelectInput v-model="f.bankAccount" :label="{ ar: 'من حساب / صندوق', en: 'From account / cash box' }" :options="lookups.bankOptions.value" :placeholder="{ ar: '— غير محدد —', en: '— not named —' }" full />
    </div>
    <div class="hint amber">{{ t('يُحفظ السداد على المطالبة بمرجعه. قيد إقفال المصروف المستحق في الدفاتر (مدين: مصروفات مستحقة / دائن: البنك) يُسجَّل من قسم المالية — لا يوجد بعد مسار سداد آلي للمصروفات المستحقة.', 'The payment is kept on the claim with its reference. The journal that clears the accrual in the books (debit accrued expenses / credit the bank) is made in the finance section — there is no automated settlement of accrued expenses yet.') }}</div>
  </HrFormDrawer>
</template>
