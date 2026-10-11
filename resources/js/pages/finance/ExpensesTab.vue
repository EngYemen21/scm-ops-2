<script setup>
// Expense vouchers: list + form (account · amount · VAT · settlement · cost center / vehicle / trip) + void.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, NumberInput, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ActionDrawer from '@/pages/procurement/ActionDrawer.vue';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import ReasonDrawer from '@/pages/procurement/ReasonDrawer.vue';
import { qs } from '@/pages/procurement/shared';
import { PAYMENT_STATUS_LABELS, SELECT_PLACEHOLDER, SETTLEMENT_LABELS, SETTLEMENT_OPTIONS, pname, todayIso, useAccountOptions, useBankOptions, useCostCenterOptions, useSupplierOptions } from './shared';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const q = ref('');
const status = ref('');
const page = ref(1);
watch([q, status], () => { page.value = 1; });
const list = useList('/finance/expenses', () => ({ q: q.value, status: status.value, page: page.value, pageSize: 25 }));
const act = useAction({ invalidate: ['finance', 'transport'] });
const form = ref(false);
const voiding = ref(null);

const { options: accountOpts } = useAccountOptions((a) => a.type === 'expense');
const ccOpts = useCostCenterOptions();
const { options: bankOpts } = useBankOptions();
const { options: supplierOpts } = useSupplierOptions();
const vehicles = useList('/transport/vehicles', { pageSize: 300 }, { enabled: () => form.value });
const vehicleOpts = computed(() => (vehicles.data.value?.items || []).map((v) => ({ v: v.code, l: `${v.code} · ${v.plateAr}` })));
const blank = () => ({ date: todayIso(), account: '', amount: null, vatPct: 0, settlement: 'bank', bankAccount: 'BANK', supplier: '', costCenter: '', vehicle: '', trip: '', description: '', reference: '' });
const f = ref(blank());
watch(form, (o) => { if (o) { f.value = blank(); act.clearError(); } });
const ok = computed(() => !!f.value.account && Number(f.value.amount) > 0 && f.value.description.trim().length >= 3 && (f.value.settlement !== 'supplier' || f.value.supplier) && (f.value.settlement !== 'bank' || f.value.bankAccount));
async function submit() {
  const v = f.value;
  const body = { date: v.date, account: v.account, amount: Number(v.amount), vatPct: Number(v.vatPct) || 0, settlement: v.settlement, bankAccount: v.settlement === 'bank' ? v.bankAccount : undefined, supplier: v.settlement === 'supplier' ? v.supplier : undefined,
    costCenter: v.costCenter || undefined, vehicle: v.vehicle || undefined, trip: v.trip || undefined, description: v.description.trim(), reference: v.reference || undefined };
  const r = await act.run(() => api.postIdempotent('/finance/expenses', body), { success: (e) => t(`سُجل المصروف ${e.number}`, `Expense ${e.number} recorded`) });
  if (r) { form.value = false; list.refetch(); }
}
async function doVoid(reason) {
  const r = await act.run(() => api.postIdempotent(`/finance/expenses/${voiding.value.number}/void`, { reason }), { success: t('أُلغي السند وعُكس قيده', 'Voucher voided') });
  if (r) { voiding.value = null; list.refetch(); }
}
const STATUSES = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'posted', label: { ar: 'مرحَّلة', en: 'Posted' } }, { k: 'void', label: { ar: 'ملغاة', en: 'Void' } }];
const columns = [
  { key: 'number', header: { ar: 'السند', en: 'Voucher' }, width: '130px', kind: 'id' },
  { key: 'expenseDate', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.expenseDate) },
  { key: 'description', header: { ar: 'البيان', en: 'Description' }, width: 'minmax(180px,1.6fr)', kind: 'name' },
  { key: 'account', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(140px,1.2fr)', class: 'text-[10px]', value: (r) => `${r.account?.code} · ${pname(r.account)}` },
  { key: 'dims', header: { ar: 'مركز التكلفة / المركبة', en: 'Cost center / vehicle' }, width: '150px', class: 'text-[9.5px] text-muted', value: (r) => [r.costCenter?.code, r.vehicle?.code].filter(Boolean).join(' · ') || '—' },
  { key: 'settlement', header: { ar: 'التسوية', en: 'Settlement' }, width: '120px', class: 'text-[9.5px]', value: (r) => t(SETTLEMENT_LABELS[r.settlement]?.ar, SETTLEMENT_LABELS[r.settlement]?.en) },
  { key: 'total', header: { ar: 'الإجمالي', en: 'Total' }, width: '100px', kind: 'num', sortable: true, value: (r) => fmtMoney(r.total) },
  { key: 'status', header: '', width: '80px' },
  { key: 'act', header: '', width: '90px' },
];
</script>

<template>
  <FilterBar v-model="q">
    <Tabs v-model="status" :tabs="STATUSES" variant="pill" class="!mb-0" />
    <span class="grow" />
    <Btn v-if="auth.can('expense.create')" size="sm" tone="primary" :label="{ ar: '+ سند مصروف', en: '+ Expense' }" @click="form = true" />
  </FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1040" :empty-text="{ ar: 'لا مصروفات بعد.', en: 'No expenses yet.' }" @page="page = $event" @row-click="(r) => r.journal && router.push(`/jv/${encodeURIComponent(r.journal.number)}`)">
      <template #cell-status="{ row }"><Chip :map="PAYMENT_STATUS_LABELS" :k="row.status" small /></template>
      <template #cell-act="{ row }"><Btn v-if="row.status === 'posted' && auth.can('gl.approve')" size="sm" tone="dangerOutline" class="!h-7 !text-[9px]" :label="{ ar: 'إلغاء', en: 'Void' }" @click.stop="voiding = row" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الوقود وإقفال الصيانة يُرحَّلان من شاشة الأسطول تلقائيًا (مصروف مستحق حتى يُسدَّد). هنا المصروفات الأخرى: رسوم طرق ومواقف وخدمات وإيجارات — بمركز تكلفة ومركبة ورحلة عند الاقتضاء، فتظهر في تكلفة الرحلة والمركبة.', 'Fuel and maintenance post from the fleet screens automatically (accrued until paid). Other costs go here — tolls, parking, services, rent — with a cost center, vehicle and trip when relevant, so they appear in trip and vehicle costs.') }}</div>

  <ActionDrawer :open="form" :width="560" :title="{ ar: 'سند مصروف', en: 'Expense voucher' }" :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'ترحيل المصروف', en: 'Post expense' }" @submit="submit" @close="form = false" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.account" :label="{ ar: 'حساب المصروف', en: 'Expense account' }" required :options="accountOpts" :placeholder="SELECT_PLACEHOLDER" full />
      <TextInput v-model="f.description" :label="{ ar: 'البيان', en: 'Description' }" required full />
      <DateInput v-model="f.date" :label="{ ar: 'التاريخ', en: 'Date' }" required />
      <NumberInput v-model="f.amount" :label="{ ar: 'المبلغ قبل الضريبة', en: 'Amount before VAT' }" required :min="0" />
      <NumberInput v-model="f.vatPct" :label="{ ar: 'ضريبة %', en: 'VAT %' }" :min="0" :max="100" />
      <SelectInput v-model="f.settlement" :label="{ ar: 'التسوية', en: 'Settlement' }" :options="SETTLEMENT_OPTIONS" />
      <SelectInput v-if="f.settlement === 'bank'" v-model="f.bankAccount" :label="{ ar: 'من حساب', en: 'From account' }" required :options="bankOpts" />
      <SelectInput v-else-if="f.settlement === 'supplier'" v-model="f.supplier" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supplierOpts" :placeholder="SELECT_PLACEHOLDER" />
      <div v-else />
      <SelectInput v-model="f.costCenter" :label="{ ar: 'مركز التكلفة', en: 'Cost center' }" :options="ccOpts" :placeholder="{ ar: 'تلقائي من المركبة', en: 'From the vehicle' }" />
      <SelectInput v-model="f.vehicle" :label="{ ar: 'المركبة', en: 'Vehicle' }" :options="vehicleOpts" :placeholder="{ ar: '—', en: '—' }" />
      <TextInput v-model="f.trip" :label="{ ar: 'رقم الرحلة', en: 'Trip number' }" dir="ltr" placeholder="TRP-2026-0001" />
      <TextInput v-model="f.reference" :label="{ ar: 'المرجع / الإيصال', en: 'Reference / receipt' }" dir="ltr" />
    </div>
  </ActionDrawer>
  <Teleport v-if="voiding" to="body">
    <ReasonDrawer open :title="{ ar: `إلغاء ${voiding.number}`, en: `Void ${voiding.number}` }" :ok-label="{ ar: 'إلغاء السند', en: 'Void' }" :min="5" :pending="act.pending.value" :error="act.error.value" @submit="doVoid" @close="voiding = null" @clear-error="act.clearError()" />
  </Teleport>
</template>
