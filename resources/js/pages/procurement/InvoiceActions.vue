<script setup>
// Supplier invoice actions by state and permission:
//   matched → approve (invoice.approve) · disputed → approve with a written decision (invoice.approve) ·
//   approved → record payment with reference (invoice.pay) · matched|disputed|approved → cancel with reason (invoice.register).
//   <InvoiceActions :invoice="row" size="md" @changed="(inv) => …" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import ReasonDrawer from './ReasonDrawer.vue';

const props = defineProps({
  /** Invoice row — { number, status, total, supplier, variances? } */
  invoice: { type: Object, required: true },
  size: { type: String, default: 'sm' },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const act = useAction({ invalidate: ['procurement'] });
/** null | 'decision' | 'pay' | 'cancel' */
const prompt = ref(null);
const S = computed(() => props.invoice.status);

async function run(path, body, success) {
  const r = await act.run(() => api.postIdempotent(`/procurement/invoices/${props.invoice.number}/${path}`, body), { success });
  if (r) { emit('changed', r); prompt.value = null; }
  return r;
}
async function approve() {
  const inv = props.invoice;
  if (inv.status === 'disputed') { prompt.value = 'decision'; return; }
  const yes = await confirm({ title: { ar: `اعتماد الفاتورة ${inv.number} للصرف؟`, en: `Approve ${inv.number} for payment?` }, sub: { ar: `${fmtMoney(inv.total)} ر.س — مطابقة لأمر الشراء والاستلام`, en: `${fmtMoney(inv.total)} SAR — matched to PO and receipts` }, tone: 'dark', okLabel: { ar: 'اعتماد', en: 'Approve' } });
  if (yes) void run('approve', {}, t(`اعتُمدت ${inv.number} للصرف`, `${inv.number} approved for payment`));
}
function withText(text) {
  const n = props.invoice.number;
  if (prompt.value === 'decision') return run('approve', { note: text }, t(`اعتُمدت ${n} مع قرار الفروقات`, `${n} approved with the variance decision`));
  if (prompt.value === 'pay') return run('pay', { reference: text }, t(`سُجل سداد ${n}`, `${n} paid`));
  return run('cancel', { reason: text }, t(`أُلغيت ${n}`, `${n} cancelled`));
}
const PROMPTS = {
  decision: { title: { ar: 'قرار اعتماد فاتورة مختلف عليها', en: 'Decision on a disputed invoice' }, sub: { ar: 'اكتب القرار وسببه (قبول الفرق / إشعار دائن متفق عليه…) — يُسجَّل في Audit Trail', en: 'Write the decision and its reason (variance accepted / agreed credit note…) — recorded in the audit trail' }, label: { ar: 'القرار', en: 'Decision' }, ok: { ar: 'اعتماد مع القرار', en: 'Approve with decision' } },
  pay: { title: { ar: 'تسجيل السداد', en: 'Record payment' }, sub: { ar: 'مرجع التحويل / الشيك إلزامي', en: 'Transfer / cheque reference is required' }, label: { ar: 'مرجع السداد', en: 'Payment reference' }, ok: { ar: 'تسجيل السداد', en: 'Record payment' } },
  cancel: { title: { ar: 'إلغاء الفاتورة', en: 'Cancel invoice' }, sub: { ar: 'الفاتورة المسدَّدة لا تُلغى', en: 'A paid invoice cannot be cancelled' }, label: { ar: 'السبب', en: 'Reason' }, ok: { ar: 'إلغاء الفاتورة', en: 'Cancel invoice' } },
};
function closePrompt() { prompt.value = null; act.clearError(); }
</script>

<template>
  <div class="row wrap justify-end !gap-[5px]">
    <Btn v-if="['matched', 'disputed'].includes(S) && auth.can('invoice.approve')" tone="success" :size="size" :loading="act.pending.value" :label="S === 'disputed' ? { ar: 'قرار واعتماد', en: 'Decide & approve' } : { ar: 'اعتماد للصرف', en: 'Approve' }" @click="approve" />
    <Btn v-if="S === 'approved' && auth.can('invoice.pay')" tone="blue" :size="size" :label="{ ar: 'تسجيل السداد', en: 'Record payment' }" @click="prompt = 'pay'" />
    <Btn v-if="['matched', 'disputed', 'approved'].includes(S) && auth.can('invoice.register')" tone="dangerOutline" :size="size" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="prompt = 'cancel'" />

    <Teleport v-if="prompt !== null" to="body">
      <ReasonDrawer open :title="PROMPTS[prompt].title" :sub="PROMPTS[prompt].sub" :label="PROMPTS[prompt].label" :ok-label="PROMPTS[prompt].ok" :min="prompt === 'pay' ? 1 : 5"
                    :pending="act.pending.value" :error="act.error.value" @submit="withText" @close="closePrompt" @clear-error="act.clearError()" />
    </Teleport>
  </div>
</template>
