<script setup>
// Customer receipt: customer · amount · date · method · bank/cash account · reference, allocated to open invoices
// (defaults to the oldest first). POST /finance/receipts.
//   <ReceiptForm :open :initial="{ customer?, invoice?, amount? }" @close @done />
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, t } from '@/i18n';
import ActionDrawer from '@/pages/procurement/ActionDrawer.vue';
import { METHOD_OPTIONS, SELECT_PLACEHOLDER, todayIso, useBankOptions, useCustomerOptions } from './shared';

const props = defineProps({ open: { type: Boolean, default: false }, initial: { type: Object, default: null } });
const emit = defineEmits(['close', 'done']);
const act = useAction({ invalidate: ['finance'] });
const { options: customerOpts } = useCustomerOptions();
const { options: bankOpts, banks } = useBankOptions();

const f = ref({ customer: '', amount: null, date: todayIso(), method: 'bank', bankAccount: 'BANK', reference: '', notes: '' });
/** invoice number → allocated amount */
const alloc = ref({});
watch(() => props.open, (o) => {
  if (!o) return;
  const i = props.initial || {};
  f.value = { customer: i.customer || '', amount: i.amount ?? null, date: todayIso(), method: 'bank', bankAccount: 'BANK', reference: '', notes: '' };
  alloc.value = i.invoice && i.amount ? { [i.invoice]: Number(i.amount) } : {};
  act.clearError();
});
watch(() => f.value.bankAccount, (code) => { const b = banks.value.find((x) => x.code === code); if (b && f.value.method === (b.kind === 'cash' ? 'bank' : 'cash')) f.value.method = b.kind === 'cash' ? 'cash' : 'bank'; });

const open = useList('/finance/invoices', () => ({ customer: f.value.customer, status: 'issued,partially_paid', kind: 'invoice', pageSize: 200, sort: 'dueDate', order: 'asc' }), { enabled: () => !!f.value.customer });
const invoices = computed(() => open.data.value?.items || []);
const allocated = computed(() => Object.values(alloc.value).reduce((a, v) => a + (Number(v) || 0), 0));
const ok = computed(() => !!f.value.customer && Number(f.value.amount) > 0 && allocated.value <= Number(f.value.amount) + 0.005 && invoices.value.every((i) => (Number(alloc.value[i.number]) || 0) <= Number(i.balance) + 0.005));
/** Spread the amount over the open invoices, oldest due first. */
function autoAllocate() {
  let left = Number(f.value.amount) || 0;
  const a = {};
  for (const i of invoices.value) { const x = Math.min(left, Number(i.balance)); if (x > 0) { a[i.number] = Math.round(x * 100) / 100; left -= x; } }
  alloc.value = a;
}
async function submit() {
  const body = { customer: f.value.customer, amount: Number(f.value.amount), date: f.value.date, method: f.value.method, bankAccount: f.value.bankAccount, reference: f.value.reference || undefined, notes: f.value.notes || undefined,
    allocations: Object.entries(alloc.value).filter(([, v]) => Number(v) > 0).map(([invoice, amount]) => ({ invoice, amount: Number(amount) })) };
  const r = await act.run(() => api.postIdempotent('/finance/receipts', body), { success: (p) => t(`سُجل سند القبض ${p.number}`, `Receipt ${p.number} recorded`) });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :width="600" :title="{ ar: 'سند قبض من عميل', en: 'Customer receipt' }" :sub="{ ar: 'يُرحَّل: بنك/صندوق مدين · ذمم العميل دائن، ويُوزَّع على الفواتير المفتوحة', en: 'Posts bank/cash ↔ receivables and settles the open invoices' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'تسجيل القبض', en: 'Record receipt' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.customer" :label="{ ar: 'العميل', en: 'Customer' }" required :options="customerOpts" :placeholder="SELECT_PLACEHOLDER" />
      <NumberInput v-model="f.amount" :label="{ ar: 'المبلغ المقبوض ر.س', en: 'Amount received SAR' }" required :min="0" />
      <DateInput v-model="f.date" :label="{ ar: 'تاريخ القبض', en: 'Date' }" required />
      <SelectInput v-model="f.bankAccount" :label="{ ar: 'إلى حساب', en: 'Into account' }" required :options="bankOpts" />
      <SelectInput v-model="f.method" :label="{ ar: 'طريقة القبض', en: 'Method' }" :options="METHOD_OPTIONS" />
      <TextInput v-model="f.reference" :label="{ ar: 'المرجع (إيصال / حوالة)', en: 'Reference' }" dir="ltr" />
      <TextInput v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" full />
    </div>
    <div class="mt-3.5">
      <div class="row mb-1.5"><div class="field-l">{{ t('التوزيع على الفواتير المفتوحة', 'Allocation to open invoices') }}</div><span class="grow" /><button type="button" class="text-[10px] font-extrabold text-violet" :disabled="!invoices.length" @click="autoAllocate">{{ t('توزيع تلقائي (الأقدم أولًا)', 'Auto-allocate (oldest first)') }}</button></div>
      <div v-if="!f.customer" class="empty dashed !rounded-xl !p-3">{{ t('اختر العميل لعرض فواتيره المفتوحة', 'Pick a customer to see open invoices') }}</div>
      <div v-else-if="invoices.length === 0" class="empty dashed !rounded-xl !p-3">{{ t('لا فواتير مفتوحة — يبقى المبلغ رصيدًا دائنًا على حساب العميل', 'No open invoices — the amount stays as a credit on the customer account') }}</div>
      <div v-else class="overflow-hidden rounded-xl border border-line-2">
        <div v-for="i in invoices" :key="i.number" class="grid grid-cols-[minmax(120px,1.4fr)_90px_90px_110px] items-center gap-1.5 border-t border-line-2 px-2.5 py-[6px] first:border-t-0 text-[10.5px]">
          <div><div class="num font-extrabold">{{ i.number }}</div><div class="text-[8.5px] text-faint">{{ t('استحقاق', 'due') }} {{ fmtDateOnly(i.dueDate) }}</div></div>
          <div class="num text-muted">{{ fmtMoney(i.total) }}</div>
          <div class="num" :class="i.overdue ? 'text-bad' : ''">{{ fmtMoney(i.balance) }}</div>
          <NumberInput small :model-value="alloc[i.number] ?? null" :min="0" :max="Number(i.balance)" :placeholder="{ ar: 'مبلغ', en: 'amount' }" @update:model-value="(v) => (alloc = { ...alloc, [i.number]: v })" />
        </div>
        <div class="row border-t border-line-2 px-2.5 py-2 text-[10.5px] font-extrabold"><span class="grow" />{{ t('الموزَّع', 'Allocated') }}: <span class="num">{{ fmtMoney(allocated) }}</span> / <span class="num">{{ fmtMoney(f.amount || 0) }}</span></div>
      </div>
    </div>
  </ActionDrawer>
</template>
