<script setup>
// Manual journal (maker): date, memo, lines with account · debit / credit · description · cost center · partner.
// Saved as a DRAFT; someone else with gl.approve posts it. POST /finance/journals.
//   <JournalForm :open @close @done />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { fmtMoney, t } from '@/i18n';
import ActionDrawer from '@/pages/procurement/ActionDrawer.vue';
import { SELECT_PLACEHOLDER, todayIso, useAccountOptions, useCostCenterOptions, useCustomerOptions, useSupplierOptions } from './shared';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'done']);
const act = useAction({ invalidate: ['finance'] });
const when = () => props.open;
const { options: accountOpts, accounts } = useAccountOptions(null, when);
const ccOpts = useCostCenterOptions(when);
const { options: customerOpts, customers } = useCustomerOptions(when);
const { options: supplierOpts, suppliers } = useSupplierOptions(when);

const blankLine = () => ({ account: '', debit: null, credit: null, description: '', costCenter: '', partnerType: '', partner: '' });
const f = ref({ date: todayIso(), memo: '' });
const lines = ref([blankLine(), blankLine()]);
watch(() => props.open, (o) => { if (o) { f.value = { date: todayIso(), memo: '' }; lines.value = [blankLine(), blankLine()]; act.clearError(); } });

const patch = (i, p) => { lines.value = lines.value.map((l, j) => (j === i ? { ...l, ...p } : l)); };
const remove = (i) => { lines.value = lines.value.filter((_, j) => j !== i); };
const sum = (k) => lines.value.reduce((a, l) => a + (Number(l[k]) || 0), 0);
const debit = computed(() => sum('debit'));
const credit = computed(() => sum('credit'));
const balanced = computed(() => Math.abs(debit.value - credit.value) < 0.005 && debit.value > 0);
const needsPartner = (code) => { const a = accounts.value.find((x) => x.code === code); return a?.subtype === 'receivable' ? 'customer' : a?.subtype === 'payable' ? 'supplier' : null; };
const ok = computed(() => f.value.memo.trim().length >= 3 && !!f.value.date && balanced.value && lines.value.filter((l) => l.account && (Number(l.debit) || Number(l.credit))).length >= 2
  && lines.value.every((l) => !needsPartner(l.account) || l.partner));

async function submit() {
  const body = { date: f.value.date, memo: f.value.memo.trim(), lines: lines.value.filter((l) => l.account && (Number(l.debit) || Number(l.credit))).map((l) => {
    const pt = needsPartner(l.account);
    const partnerId = pt === 'customer' ? customers.value.find((c) => c.code === l.partner)?.id : pt === 'supplier' ? suppliers.value.find((s) => s.code === l.partner)?.id : undefined;
    return { account: l.account, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0, description: l.description || undefined, costCenter: l.costCenter || undefined, partnerType: pt || undefined, partnerId };
  }) };
  const r = await act.run(() => api.postIdempotent('/finance/journals', body), { success: (j) => t(`حُفظت المسودة ${j.number} — بانتظار اعتماد شخص آخر`, `Draft ${j.number} saved — awaiting approval by someone else`) });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :width="720" :title="{ ar: 'قيد يدوي جديد', en: 'New manual journal' }" :sub="{ ar: 'يُحفظ كمسودة ويعتمده شخص آخر (صانع ≠ معتمد) — القيد المرحَّل لا يُعدَّل', en: 'Saved as a draft; approved by someone else (maker ≠ checker) — posted journals are immutable' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'حفظ المسودة', en: 'Save draft' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <DateInput v-model="f.date" :label="{ ar: 'تاريخ القيد', en: 'Journal date' }" required />
      <TextArea v-model="f.memo" :label="{ ar: 'البيان', en: 'Memo' }" required :rows="2" :full="false" />
    </div>
    <div class="mt-3.5 overflow-hidden rounded-xl border border-line-2">
      <div v-for="(l, i) in lines" :key="i" class="border-t border-line-2 px-2.5 py-2 first:border-t-0">
        <div class="grid grid-cols-[minmax(160px,2fr)_90px_90px_26px] items-end gap-1.5">
          <SelectInput small :model-value="l.account" :label="{ ar: 'الحساب', en: 'Account' }" :options="accountOpts" :placeholder="SELECT_PLACEHOLDER" @update:model-value="(v) => patch(i, { account: v })" />
          <NumberInput small :model-value="l.debit" :label="{ ar: 'مدين', en: 'Debit' }" :min="0" @update:model-value="(v) => patch(i, { debit: v, credit: v ? null : l.credit })" />
          <NumberInput small :model-value="l.credit" :label="{ ar: 'دائن', en: 'Credit' }" :min="0" @update:model-value="(v) => patch(i, { credit: v, debit: v ? null : l.debit })" />
          <button type="button" class="x-btn !h-6 !w-6 mb-1" aria-label="remove" :disabled="lines.length <= 2" @click="remove(i)">✕</button>
        </div>
        <div class="mt-1.5 grid grid-cols-[minmax(140px,2fr)_minmax(120px,1fr)_minmax(140px,1.4fr)] gap-1.5">
          <TextInput small :model-value="l.description" :placeholder="{ ar: 'وصف السطر', en: 'Line description' }" @update:model-value="(v) => patch(i, { description: v })" />
          <SelectInput small :model-value="l.costCenter" :options="ccOpts" :placeholder="{ ar: 'مركز تكلفة', en: 'Cost center' }" @update:model-value="(v) => patch(i, { costCenter: v })" />
          <SelectInput v-if="needsPartner(l.account) === 'customer'" small :model-value="l.partner" :options="customerOpts" :placeholder="{ ar: 'العميل (إلزامي لحساب التحكم)', en: 'Customer (required)' }" @update:model-value="(v) => patch(i, { partner: v })" />
          <SelectInput v-else-if="needsPartner(l.account) === 'supplier'" small :model-value="l.partner" :options="supplierOpts" :placeholder="{ ar: 'المورد (إلزامي لحساب التحكم)', en: 'Supplier (required)' }" @update:model-value="(v) => patch(i, { partner: v })" />
          <div v-else />
        </div>
      </div>
      <div class="row border-t border-line-2 px-2.5 py-2 text-[10.5px] font-extrabold">
        <Btn size="sm" tone="soft" :label="{ ar: '+ سطر', en: '+ Line' }" @click="lines = [...lines, blankLine()]" />
        <span class="grow" />
        <span>{{ t('مدين', 'Debit') }} <span class="num">{{ fmtMoney(debit) }}</span></span>
        <span>{{ t('دائن', 'Credit') }} <span class="num">{{ fmtMoney(credit) }}</span></span>
        <span :class="balanced ? 'text-ok' : 'text-bad'">{{ balanced ? t('متوازن ✓', 'Balanced ✓') : t(`فرق ${fmtMoney(Math.abs(debit - credit))}`, `Δ ${fmtMoney(Math.abs(debit - credit))}`) }}</span>
      </div>
    </div>
  </ActionDrawer>
</template>
