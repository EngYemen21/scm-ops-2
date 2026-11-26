<script setup>
// The leave ledger. A balance is never a stored number: it is the sum of the lines of one employee, one type and one
// year (granted +, taken −, restored +, adjusted ±). Here:
//   the balances of a chosen employee            GET /payroll/leave-balances?employee&year
//   the ledger lines                             GET /payroll/leave-entries?employee&type&year&kind&q&sort(days|year)&page
//   «منح / تسوية» grant or correct days          POST /payroll/leave-allocations { employee, type, year, days, kind, note }
//   «الرصيد السنوي» the yearly allowance          POST /payroll/leave-allocations/yearly { year, type? } — once per person, type, year
//   «مطابقة الدفتر» proof against the leaves      GET /payroll/leave-ledger/reconcile → { ok, checked, problems }
// The last three belong to leave.approve / payroll.manage (reconcile also payroll.approve).
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SectionCard, SelectInput } from '@/components';
import { t } from '@/i18n';
import BalanceCards from './BalanceCards.vue';
import { ENTRY_KIND, FROM_ODOO, days, fmtAt, optionsOf, pname, usePayrollLookups, usePayrollNav, usePayrollPerms, yearOptions } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const lk = usePayrollLookups();
const { employeeOptions, leaveTypeOptions } = lk;
const year = computed(() => (/^\d{4}$/.test(nav.get('year')) ? nav.get('year') : String(lk.year.value)));
const employee = computed(() => nav.get('employee'));
const canGrant = computed(() => perms.value.leaveOfficer);
const canReconcile = computed(() => perms.value.leaveOfficer || perms.value.payrollApprove);

const sort = computed(() => nav.sortOf(['days', 'year'], null));
const entries = useList('/payroll/leave-entries', () => ({
  employee: employee.value || undefined, year: year.value, type: nav.get('type') || undefined, kind: nav.get('kind') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const columns = [
  { key: 'createdAt', header: { ar: 'التاريخ', en: 'Date' }, width: '120px', kind: 'date', value: (r) => fmtAt(r.createdAt) },
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(140px,1.2fr)' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: 'minmax(110px,1fr)', value: (r) => pname(r.type) },
  { key: 'year', header: { ar: 'السنة', en: 'Year' }, width: '64px', kind: 'num', sortable: true },
  { key: 'kind', header: { ar: 'الحركة', en: 'Entry' }, width: '110px', bare: true },
  { key: 'days', header: { ar: 'الأيام', en: 'Days' }, width: '70px', kind: 'num', sortable: true },
  { key: 'note', header: { ar: 'البيان', en: 'Note' }, width: 'minmax(140px,1.2fr)' },
];

// ---- grant / adjust, the yearly allowance
const granting = ref(false);
const yearly = ref(false);
const grantFields = computed(() => [
  { k: 'employee', label: { ar: 'الموظف', en: 'Employee' }, type: 'select', required: true, full: true, opts: employeeOptions.value },
  { k: 'type', label: { ar: 'نوع الإجازة', en: 'Leave type' }, type: 'select', required: true, opts: leaveTypeOptions.value },
  { k: 'year', label: { ar: 'سنة الرصيد', en: 'Allowance year' }, type: 'num', required: true, min: 2000, max: 2100 },
  { k: 'kind', label: { ar: 'الحركة', en: 'Entry' }, type: 'select', required: true, opts: [['allocation', { ar: 'منح أيام (+)', en: 'Grant days (+)' }], ['adjustment', { ar: 'تسوية (+ أو −) بسبب', en: 'Adjustment (+ or −) with a reason' }]] },
  { k: 'days', label: { ar: 'عدد الأيام', en: 'Days' }, type: 'num', required: true, min: -366, max: 366, step: 0.5,
    validate: (v, all) => (Number(v) === 0 ? t('لا يكون صفرًا', 'Cannot be zero') : all.kind === 'allocation' && Number(v) < 0 ? t('المنح يكون موجبًا — للخصم اختر «تسوية»', 'A grant is positive — use an adjustment to take days away') : null) },
  { k: 'note', label: { ar: 'البيان / السبب', en: 'Note / reason' }, type: 'area', full: true,
    validate: (v, all) => (all.kind === 'adjustment' && String(v || '').trim().length < 5 ? t('سبب التسوية 5 أحرف على الأقل', 'An adjustment needs a reason of at least 5 characters') : null) },
]);
const grantInitial = computed(() => ({ employee: employee.value || '', year: Number(year.value), kind: 'allocation', type: nav.get('type') || '' }));
const grant = (v) => api.postIdempotent('/payroll/leave-allocations', { employee: v.employee, type: v.type, year: Number(v.year), days: Number(v.days), kind: v.kind, note: v.note || null });
const yearlyFields = computed(() => [
  { k: 'year', label: { ar: 'السنة', en: 'Year' }, type: 'num', required: true, min: 2000, max: 2100 },
  { k: 'type', label: { ar: 'نوع واحد (اختياري)', en: 'One type (optional)' }, type: 'select', opts: leaveTypeOptions.value, ph: { ar: '— كل الأنواع ذات الرصيد السنوي —', en: '— every type with a yearly allowance —' } },
]);
const grantYear = (v) => api.postIdempotent('/payroll/leave-allocations/yearly', { year: Number(v.year), ...(v.type ? { type: v.type } : {}) });

// ---- reconcile (a read, pressed on purpose)
const rec = useAction({ invalidate: false });
const proof = ref(null);
async function reconcile() {
  const r = await rec.run(() => api.get('/payroll/leave-ledger/reconcile'), { success: false });
  if (r) proof.value = r;
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <SelectInput small class="w-[220px]" :model-value="employee" :options="employeeOptions" :placeholder="{ ar: '— اختر موظفًا لرؤية أرصدته —', en: '— pick an employee for the balances —' }" @update:model-value="(v) => nav.setFilter('employee', v)" />
    <SelectInput small class="w-[96px]" :model-value="year" :options="yearOptions(lk.year.value)" @update:model-value="(v) => nav.setFilter('year', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('type')" :options="leaveTypeOptions" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" @update:model-value="(v) => nav.setFilter('type', v)" />
    <SelectInput small class="w-[140px]" :model-value="nav.get('kind')" :options="optionsOf(ENTRY_KIND)" :placeholder="{ ar: 'كل الحركات', en: 'Every entry' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
    <span class="grow" />
    <Btn v-if="canReconcile" size="sm" tone="soft" :loading="rec.pending.value" :label="{ ar: 'مطابقة الدفتر', en: 'Reconcile the ledger' }" @click="reconcile" />
    <Btn v-if="canGrant" size="sm" tone="softPurple" :label="{ ar: 'الرصيد السنوي', en: 'Yearly allowance' }" @click="yearly = true" />
    <Btn v-if="canGrant" size="sm" tone="primary" :label="{ ar: '+ منح / تسوية', en: '+ Grant / adjust' }" @click="granting = true" />
  </div>
  <ErrorBanner :error="rec.error.value" @close="rec.clearError()" />

  <div v-if="proof" class="banner mb-3" :class="proof.ok ? 'green' : 'amber'">
    <div class="min-w-0 flex-1">
      <b>{{ proof.ok ? t('الدفتر مطابق للإجازات ✓', 'The ledger matches the leaves ✓') : t('في الدفتر فروق عن الإجازات', 'The ledger differs from the leaves') }}</b>
      — {{ t(`فُحص ${proof.checked} طلبًا`, `${proof.checked} request(s) checked`) }}
      <ul v-if="proof.problems.length" class="mt-1 list-disc ps-5 text-[10.5px]">
        <li v-for="(p, i) in proof.problems" :key="i"><span v-if="p.leave" class="num ltr inline-block">{{ p.leave }}</span> <span class="ltr inline-block">{{ p.problem }}</span></li>
      </ul>
    </div>
    <Btn size="sm" tone="ghost" :label="{ ar: 'إخفاء', en: 'Hide' }" @click="proof = null" />
  </div>

  <SectionCard v-if="employee" class="mb-3" :title="{ ar: 'الأرصدة', en: 'Balances' }">
    <BalanceCards :employee="employee" :year="Number(year)" fixed-year />
  </SectionCard>

  <ErrorBanner :error="entries.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="entries.data.value" :loading="entries.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="{ ar: 'لا حركات في الدفتر لهذا الاختيار.', en: 'No ledger entry for this choice.' }" @sort="nav.setSort" @page="nav.setPage">
      <template #cell-employee="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="cell-sub">{{ row.employee?.code }}</div></div></template>
      <template #cell-kind="{ row }"><span class="inline-flex flex-wrap justify-center gap-1"><Chip small :map="ENTRY_KIND" :k="row.kind" /><Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></span></template>
      <template #cell-days="{ row }"><span class="num ltr font-bold" :class="row.days < 0 ? 'text-bad' : 'text-ok'">{{ row.days > 0 ? '+' : '' }}{{ days(row.days) }}</span></template>
      <template #cell-note="{ row }">
        <div class="min-w-0">
          <button v-if="row.leave?.number" type="button" class="cell-id" @click.stop="nav.open('leave', row.leave.number)">{{ row.leave.number }}</button>
          <div v-else class="truncate text-[10.5px]" dir="auto" :title="row.note || ''">{{ row.note || '—' }}</div>
          <div class="truncate text-[9px] text-muted" dir="auto">{{ row.createdBy || '' }}</div>
        </div>
      </template>
      <template v-if="entries.data.value?.totals" #footer>
        <span class="me-3">{{ t('صافي أيام المطابق', 'Net days of the matches') }}: <b class="num ltr inline-block">{{ days(entries.data.value.totals.days) }}</b></span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الرصيد مجموع حركات الدفتر ولا يُخزَّن رقمًا: المنح يزيده، والإجازة المعتمدة تأخذ أيامها، وإلغاؤها يعيدها، والتسوية تصحّحه بسبب مكتوب. لا تُحذف حركة أبدًا.', 'A balance is the sum of the ledger lines, never a stored number: a grant adds, an approved leave takes its days, cancelling it gives them back, an adjustment corrects with a written reason. No line is ever removed.') }}</div>

  <FormDrawer :open="granting" :title="{ ar: 'منح أيام أو تسوية رصيد', en: 'Grant days or adjust a balance' }" :fields="grantFields" :initial="grantInitial" :submit="grant"
              :action="{ success: t('سُجِّلت الحركة في الدفتر', 'Entry recorded in the ledger'), invalidate: ['payroll'] }" @close="granting = false" @done="(r, v) => nav.setFilters({ employee: v.employee, year: String(v.year) })" />
  <FormDrawer :open="yearly" :title="{ ar: 'منح الرصيد السنوي', en: 'Grant the yearly allowance' }" :sub="{ ar: 'لكل موظف على رأس العمل ولكل نوع له رصيد سنوي — مرة واحدة لكل سنة مهما أُعيد', en: 'To everyone at work, for every type with a yearly allowance — once per year, however often it is pressed' }"
              :fields="yearlyFields" :initial="{ year: Number(year) }" :submit="grantYear" :submit-label="{ ar: 'منح', en: 'Grant' }"
              :action="{ success: (r) => t(`أُضيف ${r.created} رصيدًا لسنة ${r.year} (كان ${r.existing} ممنوحًا من قبل)`, `${r.created} allowance(s) added for ${r.year} (${r.existing} were already granted)`), invalidate: ['payroll'] }" @close="yearly = false" />
</template>
