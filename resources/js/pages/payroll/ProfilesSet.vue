<script setup>
// How each employee is paid: GET /payroll/profiles?department&structure&schedule&policy&method&status(all)&q&sort(code|name)&order&page
// → rows { employee, department, profileId, structure, schedule, policy { incomplete }, paymentMethod, notes, usesDefaults, source, locked }
// + defaults { structure, schedule, policy }. «تعديل» → PUT /payroll/profiles/{employee} { structureId?, scheduleId?, policyId?,
// paymentMethod?, notes? } — an empty choice means «the default». On a profile Odoo filled from the contract, the structure,
// schedule and policy are Odoo's; the payment method and notes are OPS's.
import { computed, ref } from 'vue';
import { api, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { FROM_ODOO, PAY_METHOD, optionsOf, pname, usePayrollLookups, usePayrollNav, useSearchParam } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const nav = usePayrollNav();
const lk = usePayrollLookups();
const { departmentOptions, structureOptions, scheduleOptions, policyOptions } = lk;
const { text, type, apply } = useSearchParam(nav);
const sort = computed(() => nav.sortOf(['code', 'name'], null));
const list = useList('/payroll/profiles', () => ({
  department: nav.get('department') || undefined, structure: nav.get('structure') || undefined, schedule: nav.get('schedule') || undefined, policy: nav.get('policy') || undefined,
  method: nav.get('method') || undefined, status: nav.get('status') === 'all' ? 'all' : undefined, q: nav.get('q') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const defaults = computed(() => list.data.value?.defaults || {});
const filtered = computed(() => ['q', 'department', 'structure', 'schedule', 'policy', 'method', 'status'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, department: null, structure: null, schedule: null, policy: null, method: null, status: null });
const columns = [
  { key: 'name', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(160px,1.4fr)', sortable: true },
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '92px', kind: 'id', sortable: true, value: (r) => r.employee?.code },
  { key: 'structure', header: { ar: 'هيكل الراتب', en: 'Structure' }, width: 'minmax(120px,1fr)' },
  { key: 'schedule', header: { ar: 'جدول الدوام', en: 'Schedule' }, width: 'minmax(120px,1fr)' },
  { key: 'policy', header: { ar: 'سياسة الحضور', en: 'Policy' }, width: 'minmax(120px,1fr)' },
  { key: 'method', header: { ar: 'الصرف', en: 'Paid by' }, width: '110px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];
/** The named choice, or «(default) name» when the profile leaves it to the default. */
const pick = (r, k) => (r.usesDefaults?.[k] ? `${t('افتراضي', 'default')}: ${defaults.value[k] ? pname(defaults.value[k]) : '—'}` : pname(r[k]));

const editing = ref(null);
const locked = computed(() => !!editing.value?.locked);
const ph = (k) => ({ ar: `— الافتراضي (${defaults.value[k] ? defaults.value[k].nameAr : 'لا شيء'}) —`, en: `— default (${defaults.value[k] ? defaults.value[k].nameEn || defaults.value[k].nameAr : 'none'}) —` });
const fields = computed(() => [
  { k: 'structureId', label: { ar: 'هيكل الراتب', en: 'Pay structure' }, type: 'select', full: true, opts: structureOptions.value, ph: ph('structure'), disabled: () => locked.value },
  { k: 'scheduleId', label: { ar: 'جدول الدوام', en: 'Work schedule' }, type: 'select', full: true, opts: scheduleOptions.value, ph: ph('schedule'), disabled: () => locked.value },
  { k: 'policyId', label: { ar: 'سياسة الحضور', en: 'Attendance policy' }, type: 'select', full: true, opts: policyOptions.value, ph: ph('policy'), disabled: () => locked.value },
  { k: 'paymentMethod', label: { ar: 'طريقة الصرف', en: 'Payment method' }, type: 'select', required: true, opts: optionsOf(PAY_METHOD) },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area', full: true },
]);
const initial = computed(() => {
  const r = editing.value;
  return r ? { structureId: r.usesDefaults?.structure ? '' : r.structure?.id || '', scheduleId: r.usesDefaults?.schedule ? '' : r.schedule?.id || '', policyId: r.policy?.id && !r.policy?.incomplete ? r.policy.id : '', paymentMethod: r.paymentMethod || 'bank', notes: r.notes || '' } : {};
});
function submit(v) {
  const body = { paymentMethod: v.paymentMethod, notes: v.notes || null };
  if (!locked.value) Object.assign(body, { structureId: v.structureId || null, scheduleId: v.scheduleId || null, policyId: v.policyId || null });
  return api.put(`/payroll/profiles/${encodeURIComponent(editing.value.employee.id)}`, body);
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="{ ar: 'بحث بالموظف…', en: 'Search employee…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('structure')" :options="structureOptions" :placeholder="{ ar: 'كل الهياكل', en: 'Every structure' }" @update:model-value="(v) => nav.setFilter('structure', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('schedule')" :options="scheduleOptions" :placeholder="{ ar: 'كل الجداول', en: 'Every schedule' }" @update:model-value="(v) => nav.setFilter('schedule', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('policy')" :options="policyOptions" :placeholder="{ ar: 'كل السياسات', en: 'Every policy' }" @update:model-value="(v) => nav.setFilter('policy', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('method')" :options="optionsOf(PAY_METHOD)" :placeholder="{ ar: 'كل طرق الصرف', en: 'Any method' }" @update:model-value="(v) => nav.setFilter('method', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('status')" :options="[['all', { ar: 'مع من ترك العمل', en: 'Including leavers' }]]" :placeholder="{ ar: 'على رأس العمل', en: 'At work' }" @update:model-value="(v) => nav.setFilter('status', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.employee?.id" :sort="sort" server-sort
               :empty-text="{ ar: 'لا موظفين مطابقين.', en: 'No matching employee.' }" @sort="nav.setSort" @page="nav.setPage">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="truncate text-[9.5px] text-muted" dir="auto">{{ row.department ? pname(row.department) : '—' }}</div></div></template>
      <template #cell-structure="{ row }"><span class="truncate text-[10.5px]" :class="row.usesDefaults?.structure ? 'text-muted' : ''" dir="auto">{{ pick(row, 'structure') }}</span></template>
      <template #cell-schedule="{ row }"><span class="truncate text-[10.5px]" :class="row.usesDefaults?.schedule ? 'text-muted' : ''" dir="auto">{{ pick(row, 'schedule') }}</span></template>
      <template #cell-policy="{ row }"><span class="truncate text-[10.5px]" :class="row.usesDefaults?.policy ? 'text-muted' : ''" dir="auto">{{ pick(row, 'policy') }}<template v-if="row.policy?.incomplete"> ({{ t('غير مكتملة', 'incomplete') }})</template></span></template>
      <template #cell-method="{ row }"><span class="inline-flex flex-wrap justify-center gap-1"><Chip small :map="PAY_METHOD" :k="row.paymentMethod" /><Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></span></template>
      <template #cell-act="{ row }"><Btn v-if="canEdit" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" /></template>
    </DataTable>
  </div>

  <FormDrawer :open="!!editing" :title="editing ? { ar: `ملف راتب ${editing.employee?.nameAr || ''}`, en: `Pay profile of ${editing.employee?.nameEn || editing.employee?.nameAr || ''}` } : null"
              :sub="locked ? { ar: 'ملأه أودو من العقد: الهيكل والجدول والسياسة هناك؛ طريقة الصرف والملاحظات هنا', en: 'Odoo filled it from the contract: structure, schedule and policy are there; the payment method and notes here' } : null"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: t('حُفظ ملف الراتب', 'Pay profile saved'), invalidate: ['payroll'] }" @close="editing = null" />
</template>
