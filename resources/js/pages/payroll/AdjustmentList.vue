<script setup>
// One-off additions and deductions of a month — a bonus, a penalty, a loan instalment — and the way an approved run is
// corrected (into the next month, with its reason). GET /payroll/adjustments?state&employee&period&component&kind&q&sort(amount|period|state)&order&page
// → rows { id, employee, period, component, kind addition|deduction, amount, reason, state, payslipId, cancelledBy, cancelReason, createdBy, createdAt, can }.
// «+ تسوية» (payroll.manage): POST /payroll/adjustments { employee, period, component, amount > 0, reason ≥ 5 }.
// «إلغاء» while it waits: POST /payroll/adjustments/{id}/cancel { reason }.
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { ask } from '@/stores/ui';
import { ADJ_KIND, ADJ_STATE, FROM_ODOO, fmtAt, fmtPeriod, isPeriod, monthIso, money, optionsOf, pname, usePayrollLookups, usePayrollNav, usePayrollPerms, useSearchParam } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const { employeeOptions, componentOptions } = usePayrollLookups();
const { text, type, apply } = useSearchParam(nav);
const state = computed(() => (Object.keys(ADJ_STATE).includes(nav.get('state')) ? nav.get('state') : ''));
const kind = computed(() => (['allowance', 'deduction'].includes(nav.get('kind')) ? nav.get('kind') : ''));
const sort = computed(() => nav.sortOf(['amount', 'period', 'state'], null));
const list = useList('/payroll/adjustments', () => ({
  state: state.value || undefined, kind: kind.value || undefined, employee: nav.get('employee') || undefined, period: isPeriod(nav.get('period')) ? nav.get('period') : undefined,
  component: nav.get('component') || undefined, q: nav.get('q') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'state', 'kind', 'employee', 'period', 'component'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, state: null, kind: null, employee: null, period: null, component: null });
const columns = [
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(150px,1.3fr)' },
  { key: 'period', header: { ar: 'الشهر', en: 'Month' }, width: '82px', kind: 'num', sortable: true, value: (r) => fmtPeriod(r.period) },
  { key: 'component', header: { ar: 'البند', en: 'Component' }, width: 'minmax(130px,1fr)' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: '100px', kind: 'num', sortable: true },
  { key: 'reason', header: { ar: 'السبب', en: 'Reason' }, width: 'minmax(150px,1.4fr)' },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '140px', sortable: true, bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '84px', bare: true },
];

const act = useAction({ invalidate: ['payroll'] });
async function cancel(a) {
  const reason = await ask({ title: { ar: 'إلغاء التسوية', en: 'Cancel the adjustment' }, sub: `${pname(a.employee)} · ${fmtPeriod(a.period)} · ${pname(a.component)}`, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, okLabel: { ar: 'إلغاء التسوية', en: 'Cancel it' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/payroll/adjustments/${encodeURIComponent(a.id)}/cancel`, { reason }), { success: t('أُلغيت التسوية', 'Adjustment cancelled') });
}

const creating = ref(false);
const fields = computed(() => [
  { k: 'employee', label: { ar: 'الموظف', en: 'Employee' }, type: 'select', required: true, full: true, opts: employeeOptions.value },
  { k: 'period', label: { ar: 'الشهر (YYYY-MM)', en: 'Month (YYYY-MM)' }, required: true, dir: 'ltr', validate: (v) => (isPeriod(v) ? null : t('الشهر بالصيغة YYYY-MM', 'Write the month as YYYY-MM')) },
  { k: 'component', label: { ar: 'البند (إضافة أو خصم)', en: 'Component (addition or deduction)' }, type: 'select', required: true, opts: componentOptions.value },
  { k: 'amount', label: { ar: 'المبلغ', en: 'Amount' }, type: 'num', required: true, min: 0.01, step: 1, validate: (v) => (Number(v) > 0 ? null : t('المبلغ أكبر من صفر — البند يحدد الإضافة أو الخصم', 'The amount is positive — the component says whether it adds or deducts')) },
  { k: 'reason', label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, type: 'area', required: true, full: true, validate: (v) => (String(v || '').trim().length >= 5 ? null : t('5 أحرف على الأقل', 'At least 5 characters')) },
]);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="{ ar: 'بحث بالموظف أو السبب…', en: 'Search employee or reason…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[150px]" :model-value="state" :options="optionsOf(ADJ_STATE)" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
    <SelectInput small class="w-[120px]" :model-value="kind" :options="[['allowance', ADJ_KIND.addition], ['deduction', ADJ_KIND.deduction]]" :placeholder="{ ar: 'إضافة وخصم', en: 'Both kinds' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
    <SelectInput small class="w-[180px]" :model-value="nav.get('employee')" :options="employeeOptions" :placeholder="{ ar: 'كل الموظفين', en: 'Every employee' }" @update:model-value="(v) => nav.setFilter('employee', v)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('component')" :options="componentOptions" :placeholder="{ ar: 'كل البنود', en: 'Every component' }" @update:model-value="(v) => nav.setFilter('component', v)" />
    <TextInput small type="month" class="w-[150px]" :model-value="nav.get('period')" :aria-label="t('الشهر', 'Month')" @update:model-value="(v) => nav.setFilter('period', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
    <span class="grow" />
    <Btn v-if="perms.payrollManage" size="sm" tone="primary" :label="{ ar: '+ تسوية', en: '+ Adjustment' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value || act.error.value" :closable="!list.error.value" @close="act.clearError()" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا تسويات مطابقة.', en: 'No matching adjustment.' } : { ar: 'لا تسويات بعد.', en: 'No adjustment yet.' }" @sort="nav.setSort" @page="nav.setPage">
      <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }} · {{ fmtAt(row.createdAt) }}</div></div></template>
      <template #cell-component="{ row }"><span class="inline-flex min-w-0 items-center gap-1"><Chip small :map="ADJ_KIND" :k="row.kind" /><span class="truncate text-[10.5px]" dir="auto">{{ pname(row.component) }}</span></span></template>
      <template #cell-amount="{ row }"><span class="num font-bold" :class="row.kind === 'deduction' ? 'text-bad' : 'text-ok'">{{ row.kind === 'deduction' ? '−' : '+' }}{{ money(row.amount) }}</span></template>
      <template #cell-reason="{ row }">
        <div class="min-w-0"><div class="truncate text-[10.5px]" dir="auto" :title="row.reason">{{ row.reason }}</div><div v-if="row.state === 'cancelled'" class="truncate text-[9px] text-muted" dir="auto">{{ row.cancelledBy }} — {{ row.cancelReason }}</div></div>
      </template>
      <template #cell-state="{ row }"><span class="inline-flex flex-wrap items-center justify-center gap-1"><Chip small :map="ADJ_STATE" :k="row.state" /><Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></span></template>
      <template #cell-act="{ row }"><Btn v-if="row.can?.cancel" size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="cancel(row)" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('التسوية تنتظر حتى تُعتمد القسيمة التي تحملها، ثم تصير «دخلت قسيمة معتمدة» ولا تُلغى — يُعكس أثرها بتسوية مقابلة في الشهر التالي. شهر اعتُمد راتب الموظف فيه لا يقبل تسوية جديدة.', 'An adjustment waits until the payslip that carries it is approved; then it is applied and cannot be cancelled — undo it with an opposite adjustment the next month. A month whose pay is approved for the employee takes no new adjustment.') }}</div>

  <FormDrawer :open="creating" :title="{ ar: 'تسوية لشهر', en: 'Adjustment for a month' }" :fields="fields" :initial="{ period: monthIso() }"
              :submit="(v) => api.postIdempotent('/payroll/adjustments', { ...v, amount: Number(v.amount) })"
              :action="{ success: t('سُجِّلت التسوية — يحملها مسير شهرها', 'Adjustment recorded — the run of its month carries it'), invalidate: ['payroll'] }" @close="creating = false" />
</template>
