<script setup>
// Payroll runs: GET /payroll/runs?state&year&department&source&q&sort(period|number|net|state|created)&order&page
// → rows { id, number, name, period, department, state, slipCount, gross, deductions, reimbursements, net, employerCost,
// skippedCount, warningCount, source, locked, can } + totals { standing, net, gross } (the standing runs of the filter).
// «+ مسير» (payroll.manage): POST /payroll/runs { period: YYYY-MM, department?, name? } — computed at once as a draft.
import { computed, ref } from 'vue';
import { api, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { FROM_ODOO, RUN_STATE, fmtPeriod, isPeriod, monthIso, money, optionsOf, pname, usePayrollLookups, usePayrollNav, usePayrollPerms, useSearchParam, yearOptions } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const { departmentOptions } = usePayrollLookups();
const { text, type, apply } = useSearchParam(nav);
const STATES = Object.keys(RUN_STATE);
const state = computed(() => (nav.get('state') && nav.get('state').split(',').every((s) => STATES.includes(s)) ? nav.get('state') : ''));
const sort = computed(() => nav.sortOf(['period', 'number', 'net', 'state', 'created'], { key: 'period', order: 'desc' }));
const list = useList('/payroll/runs', () => ({
  state: state.value || undefined, year: /^\d{4}$/.test(nav.get('year')) ? nav.get('year') : undefined, department: nav.get('department') || undefined,
  source: nav.get('source') || undefined, q: nav.get('q') || undefined, sort: sort.value.key, order: sort.value.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'state', 'year', 'department', 'source'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, state: null, year: null, department: null, source: null });
const columns = [
  { key: 'number', header: { ar: 'المسير', en: 'Run' }, width: 'minmax(170px,1.5fr)', sortable: true },
  { key: 'period', header: { ar: 'الشهر', en: 'Month' }, width: '82px', kind: 'num', sortable: true, value: (r) => fmtPeriod(r.period) },
  { key: 'slipCount', header: { ar: 'القسائم', en: 'Slips' }, width: '62px', kind: 'num' },
  { key: 'gross', header: { ar: 'الإجمالي', en: 'Gross' }, width: '104px', kind: 'num', value: (r) => money(r.gross) },
  { key: 'deductions', header: { ar: 'الاستقطاعات', en: 'Deductions' }, width: '100px', kind: 'num', value: (r) => money(r.deductions) },
  { key: 'net', header: { ar: 'الصافي', en: 'Net' }, width: '108px', kind: 'num', sortable: true, value: (r) => money(r.net) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '170px', sortable: true, bare: true },
];

const creating = ref(false);
const fields = computed(() => [
  { k: 'period', label: { ar: 'الشهر (YYYY-MM)', en: 'Month (YYYY-MM)' }, required: true, dir: 'ltr', ph: '2026-11', validate: (v) => (isPeriod(v) ? null : t('الشهر بالصيغة YYYY-MM', 'Write the month as YYYY-MM')) },
  { k: 'department', label: { ar: 'القسم', en: 'Department' }, type: 'select', opts: departmentOptions.value, ph: { ar: '— كل الأقسام —', en: '— every department —' } },
  { k: 'name', label: { ar: 'الاسم (اختياري)', en: 'Name (optional)' }, full: true, ph: { ar: 'رواتب الشهر', en: 'Salaries of the month' } },
]);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="{ ar: 'بحث بالرقم أو الاسم أو الشهر…', en: 'Search number, name or month…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[170px]" :model-value="state" :options="[['draft,review', { ar: 'مفتوحة', en: 'Open' }], ['approved,posted', { ar: 'معتمدة لم تُصرف', en: 'Approved, not paid' }], ...optionsOf(RUN_STATE)]" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
    <SelectInput small class="w-[100px]" :model-value="nav.get('year')" :options="yearOptions()" :placeholder="{ ar: 'كل السنوات', en: 'Every year' }" @update:model-value="(v) => nav.setFilter('year', v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('source')" :options="[['odoo', FROM_ODOO], ['ops', { ar: 'أُنشئت هنا', en: 'Made here' }]]" :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
    <span class="grow" />
    <Btn v-if="perms.payrollManage" size="sm" tone="primary" :label="{ ar: '+ مسير رواتب', en: '+ Pay run' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('run') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا مسيرات مطابقة.', en: 'No matching run.' } : { ar: 'لا مسيرات بعد — «+ مسير رواتب» يحسب شهرًا كاملًا.', en: 'No run yet — “+ Pay run” computes a whole month.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('run', r.number)">
      <template #cell-number="{ row }">
        <div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ row.name || row.number }}</div><div class="cell-sub">{{ row.number }}<template v-if="row.department"> · {{ pname(row.department) }}</template></div></div>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="RUN_STATE" :k="row.state" />
          <span v-if="row.warningCount" class="text-[9px] font-bold text-warn" :title="t('تنبيهات', 'Warnings')">⚠ {{ row.warningCount }}</span>
          <span v-if="row.skippedCount" class="text-[9px] font-bold text-muted" :title="t('عقود مستبعدة', 'Contracts left out')">⊘ {{ row.skippedCount }}</span>
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template v-if="list.data.value?.totals" #footer>
        <span class="me-3">{{ t('المسيرات القائمة في المطابق', 'Standing runs of the matches') }}: <b class="num">{{ list.data.value.totals.standing }}</b> · {{ t('الإجمالي', 'gross') }} <b class="num">{{ money(list.data.value.totals.gross) }}</b> · {{ t('الصافي', 'net') }} <b class="num">{{ money(list.data.value.totals.net) }}</b></span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('المسار: مسودة (يُعاد حسابها متى شئت) ← إرسال للاعتماد ← اعتماد من شخص آخر ← ترحيل القيد ← الصرف. المسير المعتمد لا يتغيّر: يُعكس، أو يُصحَّح بتسوية في المسير التالي. لا يُصرف شهر لعقد مرتين.', 'The path: draft (computed again at will) → submitted → approved by someone else → posted to the books → paid. An approved run never changes: it is reversed, or corrected by an adjustment in the next run. A month is never paid twice to one contract.') }}</div>

  <FormDrawer :open="creating" :title="{ ar: 'مسير رواتب جديد', en: 'New pay run' }" :sub="{ ar: 'يُحسب مسودةً فور إنشائه من العقود والكشوف المعتمدة والإجازات والتسويات', en: 'Computed as a draft at once from the contracts, the approved sheets, the leaves and the adjustments' }"
              :fields="fields" :initial="{ period: monthIso() }" :submit="(v) => api.postIdempotent('/payroll/runs', v)" :submit-label="{ ar: 'إنشاء وحساب', en: 'Make and compute' }"
              :action="{ success: (r) => t(`أُنشئ المسير ${r.number} (${r.slipCount} قسيمة)`, `Run ${r.number} made (${r.slipCount} payslips)`), invalidate: ['payroll'] }" @close="creating = false" @done="(r) => nav.open('run', r.number)" />
</template>
