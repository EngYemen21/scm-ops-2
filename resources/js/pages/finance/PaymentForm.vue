<script setup>
// Supplier payment: supplier · approved invoices to settle (partial amounts allowed) · bank/cash account · reference.
// POST /finance/payments.
//   <PaymentForm :open :initial="{ supplier?, invoice? }" @close @done />
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, t } from '@/i18n';
import ActionDrawer from '@/pages/procurement/ActionDrawer.vue';
import { METHOD_OPTIONS, SELECT_PLACEHOLDER, todayIso, useBankOptions, useSupplierOptions } from './shared';

const props = defineProps({ open: { type: Boolean, default: false }, initial: { type: Object, default: null } });
const emit = defineEmits(['close', 'done']);
const act = useAction({ invalidate: ['finance', 'procurement'] });
const when = () => props.open;
const { options: supplierOpts } = useSupplierOptions(when);
const { options: bankOpts } = useBankOptions(when);
const f = ref({ supplier: '', date: todayIso(), method: 'bank', bankAccount: 'BANK', reference: '', notes: '' });
const alloc = ref({});
watch(() => props.open, (o) => { if (!o) return; const i = props.initial || {}; f.value = { supplier: i.supplier || '', date: todayIso(), method: 'bank', bankAccount: 'BANK', reference: '', notes: '' }; alloc.value = {}; act.clearError(); });
const open = useList('/procurement/invoices', () => ({ supplier: f.value.supplier, status: 'approved', pageSize: 200, sort: 'dueDate', order: 'asc' }), { enabled: () => !!f.value.supplier });
const invoices = computed(() => (open.data.value?.items || []).map((i) => ({ ...i, balance: Math.round((Number(i.total) - Number(i.paidAmount || 0)) * 100) / 100 })));
const amount = computed(() => Object.values(alloc.value).reduce((a, v) => a + (Number(v) || 0), 0));
const ok = computed(() => !!f.value.supplier && amount.value > 0 && f.value.reference.trim().length > 0 && invoices.value.every((i) => (Number(alloc.value[i.number]) || 0) <= i.balance + 0.005));
function payAll() { const a = {}; for (const i of invoices.value) if (i.balance > 0) a[i.number] = i.balance; alloc.value = a; }
async function submit() {
  const body = { supplier: f.value.supplier, amount: amount.value, date: f.value.date, method: f.value.method, bankAccount: f.value.bankAccount, reference: f.value.reference.trim(), notes: f.value.notes || undefined,
    allocations: Object.entries(alloc.value).filter(([, v]) => Number(v) > 0).map(([invoice, x]) => ({ invoice, amount: Number(x) })) };
  const r = await act.run(() => api.postIdempotent('/finance/payments', body), { success: (p) => t(`سُجل سند الصرف ${p.number}`, `Payment ${p.number} recorded`) });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :width="600" :title="{ ar: 'سند صرف لمورد', en: 'Supplier payment' }" :sub="{ ar: 'يسدد فواتير معتمدة للصرف (كليًا أو جزئيًا) من حساب بنكي أو صندوق', en: 'Settles approved supplier invoices (fully or partially) from a bank account or cash box' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: `تسجيل السداد ${fmtMoney(amount)}`, en: `Record payment ${fmtMoney(amount)}` }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.supplier" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supplierOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.date" :label="{ ar: 'تاريخ السداد', en: 'Date' }" required />
      <SelectInput v-model="f.bankAccount" :label="{ ar: 'من حساب', en: 'From account' }" required :options="bankOpts" />
      <SelectInput v-model="f.method" :label="{ ar: 'طريقة السداد', en: 'Method' }" :options="METHOD_OPTIONS" />
      <TextInput v-model="f.reference" :label="{ ar: 'مرجع الحوالة / الشيك', en: 'Transfer / cheque reference' }" required dir="ltr" />
      <TextInput v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
    </div>
    <div class="mt-3.5">
      <div class="row mb-1.5"><div class="field-l">{{ t('الفواتير المعتمدة للصرف', 'Invoices approved for payment') }}</div><span class="grow" /><button type="button" class="text-[10px] font-extrabold text-violet" :disabled="!invoices.length" @click="payAll">{{ t('سداد الكل', 'Pay all') }}</button></div>
      <div v-if="!f.supplier" class="empty dashed !rounded-xl !p-3">{{ t('اختر المورد', 'Pick a supplier') }}</div>
      <div v-else-if="invoices.length === 0" class="empty dashed !rounded-xl !p-3">{{ t('لا فواتير معتمدة للصرف لهذا المورد', 'No approved invoices for this supplier') }}</div>
      <div v-else class="overflow-hidden rounded-xl border border-line-2">
        <div v-for="i in invoices" :key="i.number" class="grid grid-cols-[minmax(120px,1.4fr)_90px_90px_110px] items-center gap-1.5 border-t border-line-2 px-2.5 py-[6px] first:border-t-0 text-[10.5px]">
          <div><div class="num font-extrabold">{{ i.number }}</div><div class="text-[8.5px] text-faint">{{ i.supplierInvoiceNo }} · {{ t('استحقاق', 'due') }} {{ fmtDateOnly(i.dueDate) }}</div></div>
          <div class="num text-muted">{{ fmtMoney(i.total) }}</div>
          <div class="num" :class="i.overdue ? 'text-bad' : ''">{{ fmtMoney(i.balance) }}</div>
          <NumberInput small :model-value="alloc[i.number] ?? null" :min="0" :max="i.balance" :placeholder="{ ar: 'مبلغ', en: 'amount' }" @update:model-value="(v) => (alloc = { ...alloc, [i.number]: v })" />
        </div>
      </div>
    </div>
  </ActionDrawer>
</template>
