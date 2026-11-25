<script setup>
// The staff directory: who works here, in which department, under whom, and how to reach them at work.
// GET /hr/employees?q=&status=&department=&sub=1&source=&sort=&order=&page= — search, filters, sort and page live in the
// query string. A row opens the employee's card (`emp=<code>`); «+ موظف» needs hr.manage. Nothing of the personal file
// is here: the directory API does not carry it.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import EmployeeForm from './EmployeeForm.vue';
import { ANY, EMPLOYEE_STATUS, FROM_ODOO, fmtDay, pname, useHrLookups, useHrNav, useSearchParam } from './shared';

const auth = useAuth();
const nav = useHrNav();
const { page } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const lookups = useHrLookups();

const status = computed(() => (['left', 'all'].includes(nav.get('status')) ? nav.get('status') : ''));
const department = computed(() => nav.get('department'));
const source = computed(() => (['odoo', 'ops'].includes(nav.get('source')) ? nav.get('source') : ''));
const sort = computed(() => ({ key: ['code', 'startDate'].includes(nav.get('sort')) ? nav.get('sort') : 'name', order: nav.get('order') === 'desc' ? 'desc' : 'asc' }));
const list = useList('/hr/employees', () => ({
  q: nav.get('q'), status: status.value === 'left' ? 'left,inactive' : status.value || undefined, department: department.value || undefined, sub: department.value && department.value !== 'none' ? 1 : undefined,
  source: source.value || undefined, sort: sort.value.key, order: sort.value.order, page: page.value, pageSize: 25,
}));

const statusTabs = [{ k: '', label: { ar: 'على رأس العمل', en: 'Active' } }, { k: 'left', label: { ar: 'تركوا العمل', en: 'Left' } }, { k: 'all', label: ANY }];
const departmentOptions = computed(() => [...lookups.departmentOptions.value, ['none', { ar: '— بلا قسم —', en: '— no department —' }]]);
const sourceOptions = [['odoo', { ar: 'من أودو', en: 'From Odoo' }], ['ops', { ar: 'أُنشئ هنا', en: 'Created here' }]];
const setSort = (s) => nav.patch({ sort: s.key === 'name' ? null : s.key, order: s.order === 'desc' ? 'desc' : null, page: null });

const columns = [
  { key: 'name', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(210px,2fr)', sortable: true },
  { key: 'code', header: { ar: 'الرقم', en: 'Code' }, width: '124px', kind: 'id', sortable: true },
  { key: 'department', header: { ar: 'القسم', en: 'Department' }, width: 'minmax(120px,1fr)', value: (r) => (r.department ? pname(r.department) : '—') },
  { key: 'manager', header: { ar: 'المدير المباشر', en: 'Manager' }, width: 'minmax(120px,1fr)', value: (r) => (r.manager ? pname(r.manager) : '—') },
  { key: 'contact', header: { ar: 'التواصل في العمل', en: 'Work contact' }, width: 'minmax(170px,1.2fr)' },
  { key: 'startDate', header: { ar: 'المباشرة', en: 'Started' }, width: '92px', kind: 'date', sortable: true, value: (r) => fmtDay(r.startDate) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
];

const creating = ref(false);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: 'بحث بالاسم، الرقم، البريد أو الجوال…', en: 'Search name, code, e-mail or mobile…' }" @update:model-value="typeQ" @enter="applyQ" />
    <Tabs :model-value="status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('status', k)" />
    <SelectInput small class="w-[190px]" :model-value="department" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'All departments' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <SelectInput small class="w-[140px]" :model-value="source" :options="sourceOptions" :placeholder="{ ar: 'كل المصادر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <span class="grow" />
    <Btn v-if="auth.can('hr.manage')" size="sm" tone="primary" :label="{ ar: '+ موظف', en: '+ Employee' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.code" :selected-key="nav.get('emp') || null" :sort="sort" server-sort
               :empty-text="nav.get('q') || department || source || status ? { ar: 'لا موظفين مطابقين لهذا البحث.', en: 'No employee matches this search.' } : { ar: 'لا موظفين بعد — أضف أول موظف، أو حمِّل الموظفين من نسخة أودو.', en: 'No employees yet — add the first one, or load them from the Odoo copy.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openEmployee(r.code)">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ pname(row) }}</div>
          <div class="truncate text-[9.5px] text-muted" dir="auto">{{ row.jobTitle || (row.job ? pname(row.job) : '') || '—' }}</div>
        </div>
      </template>
      <template #cell-contact="{ row }">
        <div class="min-w-0 text-[10px]">
          <div v-if="row.workEmail" class="num truncate ltr" :title="row.workEmail">{{ row.workEmail }}</div>
          <div v-if="row.workMobile || row.workPhone" class="num ltr text-muted">{{ row.workMobile || row.workPhone }}</div>
          <span v-if="!row.workEmail && !row.workMobile && !row.workPhone" class="text-faint">—</span>
        </div>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="EMPLOYEE_STATUS" :k="row.status" />
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الدليل يعرض بيانات العمل فقط. الملف الشخصي (الهوية، العائلة، العنوان، الحساب البنكي) والعقود تظهر داخل بطاقة الموظف لمن يملك صلاحية الملفات الشخصية فقط.', 'The directory shows work details only. The personal file (identity, family, address, bank account) and the contracts appear inside the employee card, only for holders of the personal-files permission.') }}</div>

  <EmployeeForm :open="creating" @close="creating = false" @done="(e) => nav.openEmployee(e.code)" />
</template>
