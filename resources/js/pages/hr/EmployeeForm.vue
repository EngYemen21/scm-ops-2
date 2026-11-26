<script setup>
// Add an employee, or change the work part of one (hr.manage): POST /hr/employees · PATCH /hr/employees/{id}.
// An edit sends only what changed. An employee that came from Odoo keeps Odoo's values while the section follows Odoo:
// those fields are shown locked (`employee.ownedFields`), and what OPS adds — the login, the driver record, notes —
// stays editable. The personal file is another form (PrivateFileForm) behind another permission.
//   <EmployeeForm :open :employee="card | null" @close @done="(employee) => …" />
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import HrFormDrawer from './HrFormDrawer.vue';
import { EMPLOYEE_STATUS, SELECT, changedOnly, optionsOf, pname, useHrLookups } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The employee card to edit; null adds a new employee. */
  employee: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['hr'] });
const lookups = useHrLookups(() => props.open);
const FIELDS = ['nameAr', 'nameEn', 'employeeNo', 'departmentId', 'jobId', 'jobTitle', 'managerId', 'workEmail', 'workPhone', 'workMobile', 'workLocation', 'startDate', 'status', 'userId', 'driverId', 'notes'];
const f = reactive({});
/** The values the form opened with, in the shape it sends them. */
const initial = (e) => ({
  nameAr: e?.nameAr || '', nameEn: e?.nameEn || '', employeeNo: e?.employeeNo || '', departmentId: e?.department?.id || '', jobId: e?.job?.id || '', jobTitle: e?.jobTitle || '',
  managerId: e?.manager?.id || '', workEmail: e?.workEmail || '', workPhone: e?.workPhone || '', workMobile: e?.workMobile || '', workLocation: e?.workLocation || '',
  startDate: e?.startDate || '', status: e?.status || 'active', userId: e?.user?.id || '', driverId: e?.driver?.id || '', notes: e?.notes || '',
});
let before = initial(null);
watch(() => props.open, (open) => { if (open) { before = initial(props.employee); Object.assign(f, before); act.clearError(); } }, { immediate: true });

const owned = computed(() => new Set(props.employee?.ownedFields || []));
const locked = (field) => owned.value.has(field);
const managerOptions = computed(() => lookups.employees.value.filter((e) => e.id !== props.employee?.id && e.status === 'active').map((e) => [e.id, `${pname(e)} · ${e.code}`]));
/** A login or a driver record that another employee holds is listed, and cannot be picked. */
const userOptions = computed(() => lookups.users.value.map((u) => ({ v: u.id, l: `${u.username} · ${pname(u)}`, disabled: !!u.employeeId && u.employeeId !== props.employee?.id })));
const driverOptions = computed(() => lookups.drivers.value.map((d) => ({ v: d.id, l: `${d.code} · ${pname(d)}`, disabled: !!d.employeeId && d.employeeId !== props.employee?.id })));
const ok = computed(() => String(f.nameAr || '').trim().length >= 2);

async function submit() {
  const now = Object.fromEntries(FIELDS.map((k) => [k, typeof f[k] === 'string' ? f[k].trim() : f[k]]));
  let saved;
  if (props.employee) {
    const body = changedOnly(before, now);
    if (!Object.keys(body).length) { emit('close'); return; }
    saved = await act.run(() => api.patch(`/hr/employees/${props.employee.id}`, body), { success: t('حُفظت بيانات الموظف', 'Employee saved') });
  } else {
    const body = Object.fromEntries(Object.entries(now).filter(([k, v]) => v !== '' && v != null && k !== 'status'));
    saved = await act.run(() => api.postIdempotent('/hr/employees', body), { success: (e) => t(`أُضيف الموظف ${e.code}`, `Employee ${e.code} added`) });
  }
  if (saved) { emit('done', saved); emit('close'); }
}
</script>

<template>
  <HrFormDrawer :open="open" :title="employee ? { ar: `تعديل ${employee.code}`, en: `Edit ${employee.code}` } : { ar: 'موظف جديد', en: 'New employee' }" :sub="employee ? pname(employee) : null"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="owned.size" class="banner teal">
      <div>{{ t('هذا الموظف مصدره أودو: الحقول المقفلة تُعدَّل في أودو وتصل هنا تلقائيًا. يبقى لك ربطه بمستخدم النظام وبسجل السائق، والملاحظات.', 'This employee comes from Odoo: the locked fields are changed in Odoo and arrive here by themselves. The login link, the driver record and the notes remain yours.') }}</div>
    </div>
    <div class="form-grid">
      <TextInput v-model="f.nameAr" :label="{ ar: 'الاسم بالعربية', en: 'Name (Arabic)' }" required :disabled="locked('nameAr')" />
      <TextInput v-model="f.nameEn" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" dir="ltr" :disabled="locked('nameEn')" />
      <TextInput v-model="f.employeeNo" :label="{ ar: 'الرقم الوظيفي', en: 'Staff number' }" dir="ltr" :disabled="locked('employeeNo')" />
      <DateInput v-model="f.startDate" :label="{ ar: 'تاريخ المباشرة', en: 'Start date' }" :disabled="locked('startDate')" />
      <SelectInput v-model="f.departmentId" :label="{ ar: 'القسم', en: 'Department' }" :options="lookups.departmentOptions.value" :placeholder="{ ar: '— بلا قسم —', en: '— none —' }" :disabled="locked('departmentId')" />
      <SelectInput v-model="f.managerId" :label="{ ar: 'المدير المباشر', en: 'Manager' }" :options="managerOptions" :placeholder="{ ar: '— بلا مدير —', en: '— none —' }" :disabled="locked('managerId')" />
      <SelectInput v-model="f.jobId" :label="{ ar: 'المسمى الوظيفي', en: 'Job position' }" :options="lookups.jobOptions.value" :placeholder="SELECT" :disabled="locked('jobId')" />
      <TextInput v-model="f.jobTitle" :label="{ ar: 'المسمى كما يُكتب', en: 'Title as written' }" :hint="{ ar: 'يُترك فارغًا ليتبع المسمى الوظيفي', en: 'Leave empty to follow the job position' }" :disabled="locked('jobTitle')" />
      <TextInput v-model="f.workEmail" :label="{ ar: 'بريد العمل', en: 'Work e-mail' }" dir="ltr" type="email" :disabled="locked('workEmail')" />
      <TextInput v-model="f.workMobile" :label="{ ar: 'جوال العمل', en: 'Work mobile' }" dir="ltr" :disabled="locked('workMobile')" />
      <TextInput v-model="f.workPhone" :label="{ ar: 'هاتف العمل / التحويلة', en: 'Work phone / extension' }" dir="ltr" :disabled="locked('workPhone')" />
      <TextInput v-model="f.workLocation" :label="{ ar: 'موقع العمل', en: 'Work location' }" :disabled="locked('workLocation')" />
      <SelectInput v-if="employee" v-model="f.status" :label="{ ar: 'الحالة', en: 'Status' }" :options="optionsOf(EMPLOYEE_STATUS)" :disabled="locked('status')" />
      <SelectInput v-model="f.userId" :label="{ ar: 'مستخدم النظام', en: 'OPS login' }" :options="userOptions" :placeholder="{ ar: '— بلا حساب —', en: '— no login —' }"
                   :hint="{ ar: 'يرى مطالباته ويقدّمها بهذا الحساب', en: 'Files and sees their own claims with this login' }" />
      <SelectInput v-model="f.driverId" :label="{ ar: 'سجل السائق', en: 'Driver record' }" :options="driverOptions" :placeholder="{ ar: '— ليس سائقًا —', en: '— not a driver —' }"
                   :hint="{ ar: 'يُربط تلقائيًا إن كان للحساب سجل سائق', en: 'Linked by itself when the login has a driver record' }" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" />
    </div>
  </HrFormDrawer>
</template>
