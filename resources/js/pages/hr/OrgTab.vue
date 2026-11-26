<script setup>
// The company as a picture: the department tree with each department's manager and head count, the people of the
// department picked (`dept=<id>` in the query string), and the list of job positions.
//   GET /hr/org  → { items: [department + depth, headcount, total], unassigned, employees: [active people] }
//   GET /hr/jobs → job positions with how many people hold each
// Adding and changing departments and positions needs hr.manage (POST / PATCH /hr/departments, /hr/jobs). A department
// or position that came from Odoo keeps Odoo's values while the section follows Odoo; nothing is deleted — one that is
// no longer used is archived, and only once nobody is in it.
import { computed, ref } from 'vue';
import { api, useGet, useList } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { FROM_ODOO, pname, useHrLookups, useHrNav } from './shared';

const auth = useAuth();
const nav = useHrNav();
const canWrite = computed(() => auth.can('hr.manage'));
const org = useGet('/hr/org');
const jobs = useList('/hr/jobs', { pageSize: 300 });
const departments = computed(() => org.data.value?.items || []);
const people = computed(() => org.data.value?.employees || []);
const picked = computed(() => { const id = nav.get('dept'); return id === 'none' || departments.value.some((d) => d.id === id) ? id : departments.value[0]?.id || ''; });
const pickedDepartment = computed(() => departments.value.find((d) => d.id === picked.value) || null);
/** The people of the department on screen: its manager first, then by name. */
const members = computed(() => {
  const managerId = pickedDepartment.value?.manager?.id;
  return people.value.filter((e) => (picked.value === 'none' ? !e.departmentId : e.departmentId === picked.value)).sort((a, b) => (a.id === managerId ? -1 : b.id === managerId ? 1 : 0));
});
const byId = computed(() => Object.fromEntries(people.value.map((e) => [e.id, e])));

// ---- forms (the shared FormDrawer; an empty field travels as null so that a value can be cleared)
const departmentForm = ref(null); // { row: department | null } while the form is open
const jobForm = ref(null);
const lookups = useHrLookups(() => !!departmentForm.value || !!jobForm.value);
const body = (fields, values) => Object.fromEntries(fields.map((f) => [f.k, values[f.k] === undefined || values[f.k] === '' ? null : values[f.k]]));
const activeOptions = [['1', { ar: 'مستخدَم', en: 'In use' }], ['0', { ar: 'مؤرشف', en: 'Archived' }]];
const departmentFields = computed(() => {
  const row = departmentForm.value?.row;
  const locked = !!row?.locked;
  return [
    { k: 'nameAr', label: { ar: 'اسم القسم بالعربية', en: 'Name (Arabic)' }, required: true, disabled: locked },
    { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr', disabled: locked },
    { k: 'parentId', label: { ar: 'يتبع القسم', en: 'Parent department' }, type: 'select', ph: { ar: '— قسم رئيسي —', en: '— top level —' }, disabled: locked, opts: lookups.departmentOptions.value.filter(([id]) => id !== row?.id) },
    { k: 'managerId', label: { ar: 'مدير القسم', en: 'Manager' }, type: 'select', ph: { ar: '— بلا مدير —', en: '— none —' }, disabled: locked, opts: lookups.employeeOptions.value },
    ...(row ? [{ k: 'active', label: { ar: 'الحالة', en: 'State' }, type: 'select', required: true, disabled: locked, opts: activeOptions }] : []),
    { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
  ];
});
const departmentInitial = computed(() => { const r = departmentForm.value?.row; return r ? { nameAr: r.nameAr, nameEn: r.nameEn, parentId: r.parentId || '', managerId: r.manager?.id || '', active: r.active ? '1' : '0', notes: r.notes || '' } : { parentId: picked.value && picked.value !== 'none' ? picked.value : '' }; });
function saveDepartment(values) {
  const row = departmentForm.value?.row;
  const b = body(departmentFields.value, values);
  if ('active' in b) b.active = b.active === '1';
  if (!row) return api.postIdempotent('/hr/departments', b);
  // a department that came from Odoo takes only what is OPS's own
  return api.patch(`/hr/departments/${row.id}`, row.locked ? { notes: b.notes } : b);
}
const jobFields = computed(() => {
  const row = jobForm.value?.row;
  const locked = !!row?.locked;
  return [
    { k: 'nameAr', label: { ar: 'المسمى بالعربية', en: 'Name (Arabic)' }, required: true, disabled: locked },
    { k: 'nameEn', label: { ar: 'المسمى بالإنجليزية', en: 'Name (English)' }, dir: 'ltr', disabled: locked },
    { k: 'departmentId', label: { ar: 'القسم', en: 'Department' }, type: 'select', ph: { ar: '— أي قسم —', en: '— any —' }, disabled: locked, opts: lookups.departmentOptions.value },
    ...(row ? [{ k: 'active', label: { ar: 'الحالة', en: 'State' }, type: 'select', required: true, disabled: locked, opts: activeOptions }] : []),
    { k: 'description', label: { ar: 'الوصف الوظيفي', en: 'Description' }, type: 'area', disabled: locked },
  ];
});
const jobInitial = computed(() => { const r = jobForm.value?.row; return r ? { nameAr: r.nameAr, nameEn: r.nameEn, departmentId: r.department?.id || '', active: r.active ? '1' : '0', description: r.description || '' } : {}; });
function saveJob(values) {
  const row = jobForm.value?.row;
  const b = body(jobFields.value, values);
  if ('active' in b) b.active = b.active === '1';
  return row ? api.patch(`/hr/jobs/${row.id}`, b) : api.postIdempotent('/hr/jobs', b);
}
</script>

<template>
  <ErrorBanner :error="org.error.value || jobs.error.value" :closable="false" />
  <div class="grid-2">
    <!-- ── the tree -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">{{ t('الأقسام', 'Departments') }}<span class="card-count num ms-2">{{ departments.length }}</span></div>
        <Btn v-if="canWrite" size="sm" tone="primary" :label="{ ar: '+ قسم', en: '+ Department' }" @click="departmentForm = { row: null }" />
      </div>
      <div v-if="org.isLoading.value" class="card-body"><div class="skel h-[140px]" /></div>
      <EmptyState v-else-if="!departments.length" :text="{ ar: 'لا أقسام بعد — أضف أول قسم، أو حمِّل الأقسام من نسخة أودو.', en: 'No departments yet — add the first one, or load them from the Odoo copy.' }" />
      <div v-else class="pb-2">
        <div v-for="d in departments" :key="d.id" class="flex cursor-pointer items-center gap-2 border-t border-line px-[14px] py-2" :class="{ 'bg-soft': d.id === picked }"
             role="button" :style="{ paddingInlineStart: `${14 + d.depth * 18}px` }" @click="nav.patch({ dept: d.id })">
          <span class="min-w-0 flex-1">
            <span class="cell-name">{{ pname(d) }}</span>
            <span class="block truncate text-[9.5px] text-muted">{{ d.manager ? `${t('المدير', 'Manager')}: ${pname(d.manager)}` : t('بلا مدير', 'No manager') }}</span>
          </span>
          <Chip v-if="d.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
          <span class="badge soft" :title="t('في القسم مباشرة · مع الأقسام التابعة', 'Directly in it · with its sub-departments')">{{ d.headcount }}<template v-if="d.total !== d.headcount"> / {{ d.total }}</template></span>
          <button v-if="canWrite" type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('تعديل القسم', 'Edit the department')" @click.stop="departmentForm = { row: d }">✎</button>
        </div>
        <div v-if="org.data.value?.unassigned" class="flex cursor-pointer items-center gap-2 border-t border-line px-[14px] py-2" :class="{ 'bg-soft': picked === 'none' }" role="button" @click="nav.patch({ dept: 'none' })">
          <span class="flex-1 text-[11px] font-bold text-sec">{{ t('موظفون بلا قسم', 'Employees without a department') }}</span>
          <span class="badge soft">{{ org.data.value.unassigned }}</span>
        </div>
      </div>
    </div>

    <!-- ── the people of the department on screen -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">{{ picked === 'none' ? t('بلا قسم', 'No department') : pickedDepartment ? pname(pickedDepartment) : t('الموظفون', 'People') }}<span class="card-count num ms-2">{{ members.length }}</span></div>
        <Btn v-if="picked" size="sm" tone="ghost" :label="{ ar: 'عرضهم في الدليل', en: 'Show in the directory' }" @click="nav.go('directory', { department: picked })" />
      </div>
      <EmptyState v-if="!members.length" :text="{ ar: 'لا موظفين في هذا القسم مباشرة.', en: 'No employee directly in this department.' }" />
      <div v-else class="pb-2">
        <button v-for="e in members" :key="e.id" type="button" class="m-list-row !min-h-[46px]" @click="nav.openEmployee(e.code)">
          <span class="min-w-0 flex-1">
            <span class="cell-name">{{ pname(e) }}</span><span v-if="pickedDepartment?.manager?.id === e.id" class="chip sm ms-1.5 bg-violet-soft text-violet">{{ t('مدير القسم', 'Manager') }}</span>
            <span class="block truncate text-[9.5px] text-muted">{{ e.jobTitle || '—' }}<template v-if="e.managerId && byId[e.managerId]"> · {{ t('يتبع', 'reports to') }} {{ pname(byId[e.managerId]) }}</template></span>
          </span>
          <span class="cell-id">{{ e.code }}</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ── job positions -->
  <div class="card mt-3.5">
    <div class="card-head">
      <div class="card-title">{{ t('المسميات الوظيفية', 'Job positions') }}<span class="card-count num ms-2">{{ jobs.data.value?.total ?? '' }}</span></div>
      <Btn v-if="canWrite" size="sm" tone="primary" :label="{ ar: '+ مسمى', en: '+ Position' }" @click="jobForm = { row: null }" />
    </div>
    <EmptyState v-if="jobs.data.value && !jobs.data.value.items.length" :text="{ ar: 'لا مسميات وظيفية بعد.', en: 'No job positions yet.' }" />
    <div v-else class="card-body flex flex-wrap gap-1.5">
      <div v-if="jobs.isLoading.value" class="skel h-8 w-full" />
      <component :is="canWrite ? 'button' : 'span'" v-for="j in jobs.data.value?.items || []" :key="j.id" :type="canWrite ? 'button' : null" class="pill !h-auto !py-1.5" :class="{ '!cursor-default': !canWrite }"
                 :title="j.description || null" @click="canWrite && (jobForm = { row: j })">
        <span>{{ pname(j) }}</span><span v-if="j.department" class="font-normal text-faint">· {{ pname(j.department) }}</span><span class="badge soft">{{ j.headcount }}</span>
      </component>
    </div>
  </div>

  <FormDrawer :open="!!departmentForm" :title="departmentForm?.row ? { ar: 'تعديل القسم', en: 'Edit the department' } : { ar: 'قسم جديد', en: 'New department' }"
              :sub="departmentForm?.row?.locked ? { ar: 'مصدره أودو: يُعدَّل هناك — تُحفظ هنا الملاحظات فقط', en: 'From Odoo: changed there — only the notes are kept here' } : null"
              :fields="departmentFields" :initial="departmentInitial" :submit="saveDepartment" :action="{ success: t('حُفظ القسم', 'Department saved'), invalidate: ['hr'] }" @close="departmentForm = null" />
  <FormDrawer :open="!!jobForm" :title="jobForm?.row ? { ar: 'تعديل المسمى الوظيفي', en: 'Edit the job position' } : { ar: 'مسمى وظيفي جديد', en: 'New job position' }"
              :sub="jobForm?.row?.locked ? { ar: 'مصدره أودو: يُعدَّل هناك', en: 'From Odoo: changed there' } : null"
              :fields="jobFields" :initial="jobInitial" :submit="saveJob" :action="{ success: t('حُفظ المسمى', 'Position saved'), invalidate: ['hr'] }" @close="jobForm = null" />
</template>
