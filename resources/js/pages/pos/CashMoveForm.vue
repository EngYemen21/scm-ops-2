<script setup>
// Cash in / out of the drawer during a session: to or from a bank account or another cash box (never the drawer's own),
// with a reason of at least 5 characters. Posted to the books at once.
//   POST /pos/sessions/{id}/cash-moves { direction: in|out, amount, reason, bankAccount } → the session
// Refusals: POS_CASH_SHORT (taking out more than the drawer holds) · POS_CASH_MOVE_ACCOUNT · POS_NO_CASH_METHOD · POS_SESSION_CLOSED.
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Drawer, ErrorBanner, NumberInput, PillChoice, SelectInput, TextArea } from '@/components';
import { t } from '@/i18n';
import { money, usePosLookups } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The session (lookups.session shape: id, number, expectedCashNow). */
  session: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = usePosLookups();
const act = useAction();
const DIRS = [['in', { ar: 'إيداع في الدرج', en: 'Cash in' }], ['out', { ar: 'سحب من الدرج', en: 'Cash out' }]];
const direction = ref('out');
const amount = ref(null);
const bankAccount = ref('');
const reason = ref('');
const tried = ref(false);
watch(() => props.open, (o) => { if (o) { direction.value = 'out'; amount.value = null; bankAccount.value = ''; reason.value = ''; tried.value = false; act.clearError(); } });

const errors = computed(() => ({
  amount: !(amount.value > 0) ? t('أدخل مبلغًا أكبر من صفر', 'Enter an amount above zero') : null,
  bankAccount: !bankAccount.value ? t('اختر الحساب', 'Choose the account') : null,
  reason: reason.value.trim().length < 5 ? t('السبب 5 أحرف على الأقل', 'At least 5 characters') : null,
}));
const shown = (k) => (tried.value ? errors.value[k] : null);
async function submit() {
  tried.value = true;
  if (Object.values(errors.value).some(Boolean)) return;
  const r = await act.run(() => api.postIdempotent(`/pos/sessions/${encodeURIComponent(props.session.id)}/cash-moves`, { direction: direction.value, amount: amount.value, reason: reason.value.trim(), bankAccount: bankAccount.value }),
    { success: direction.value === 'in' ? t('سُجّل الإيداع', 'Cash in recorded') : t('سُجّل السحب', 'Cash out recorded'), invalidate: ['pos'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :title="{ ar: 'حركة نقدية', en: 'Cash in / out' }" :sub="session ? session.number : null" :width="460" :z-index="70" @close="emit('close')">
    <div v-if="session" class="hint !mt-0 mb-3">{{ t('النقد المتوقع في الدرج الآن', 'Cash expected in the drawer now') }}: <b class="num ltr">{{ money(session.expectedCashNow) }}</b></div>
    <div class="form-grid">
      <PillChoice v-model="direction" full :options="DIRS" :label="{ ar: 'الاتجاه', en: 'Direction' }" required />
      <NumberInput v-model="amount" :min="0" :label="{ ar: 'المبلغ', en: 'Amount' }" required :error="shown('amount')" placeholder="0.00" />
      <SelectInput v-model="bankAccount" :options="lk.bankOptions.value" :label="direction === 'in' ? { ar: 'من حساب', en: 'From account' } : { ar: 'إلى حساب', en: 'To account' }" required :error="shown('bankAccount')"
                   :placeholder="{ ar: '— اختر —', en: '— select —' }" :hint="{ ar: 'حساب بنكي أو صندوق نقد آخر غير هذا الدرج', en: 'A bank account or another cash box, not this drawer' }" />
      <TextArea v-model="reason" :rows="2" :label="{ ar: 'السبب', en: 'Reason' }" required :error="shown('reason')" :placeholder="{ ar: 'مثال: إيداع فائض النقد في البنك', en: 'e.g. depositing surplus cash at the bank' }" />
    </div>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1" :loading="act.pending.value" :label="{ ar: 'تسجيل الحركة', en: 'Record' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
