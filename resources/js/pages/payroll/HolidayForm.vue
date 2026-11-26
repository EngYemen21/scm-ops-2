<script setup>
// A public holiday — new (POST /payroll/holidays) or edited (PATCH /payroll/holidays/{id}):
// { nameAr, nameEn?, dateFrom, dateTo?, scope company|department|employee, scopeIds[], active?, notes? } — at most 60 days.
//   <HolidayForm :holiday="null | {} | row" @close />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, Field, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { HOLIDAY_SCOPE, optionsOf, pname, usePayrollLookups } from './payroll';

const props = defineProps({
  holiday: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['payroll'] });
const lk = usePayrollLookups(() => !!props.holiday);
const { departments, employees } = lk;
const isNew = computed(() => !!props.holiday && !props.holiday.id);
const f = reactive({ nameAr: '', nameEn: '', dateFrom: '', dateTo: '', scope: 'company', scopeIds: [], active: true, notes: '' });
const errors = ref({});
watch(() => props.holiday, (h) => {
  if (!h) return;
  Object.assign(f, { nameAr: h.nameAr || '', nameEn: h.nameEn || '', dateFrom: h.dateFrom || '', dateTo: h.dateTo || '', scope: h.scope || 'company', scopeIds: [...(h.scopeIds || [])], active: h.active !== false, notes: h.notes || '' });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });
watch(() => f.scope, (s, old) => { if (old && s !== old) f.scopeIds = []; });
watch(() => f.dateFrom, (v) => { if (v && (!f.dateTo || f.dateTo < v)) f.dateTo = v; });

const toggle = (id) => { f.scopeIds = f.scopeIds.includes(id) ? f.scopeIds.filter((x) => x !== id) : [...f.scopeIds, id]; };
const pickEmployee = ref('');
watch(pickEmployee, (id) => { if (id && !f.scopeIds.includes(id)) f.scopeIds = [...f.scopeIds, id]; if (id) pickEmployee.value = ''; });
const employeeName = (id) => { const e = employees.value.find((x) => x.id === id); return e ? `${pname(e)} · ${e.code}` : id; };
const employeeOptions = computed(() => employees.value.filter((e) => !f.scopeIds.includes(e.id)).map((e) => [e.id, `${pname(e)} · ${e.code}`]));

const ok = computed(() => f.nameAr.trim().length >= 2 && !!f.dateFrom && (!f.dateTo || f.dateTo >= f.dateFrom) && (f.scope === 'company' || f.scopeIds.length > 0));
async function save() {
  if (!ok.value) return;
  const body = { nameAr: f.nameAr.trim(), nameEn: f.nameEn.trim() || null, dateFrom: f.dateFrom, dateTo: f.dateTo || f.dateFrom, scope: f.scope, scopeIds: f.scope === 'company' ? [] : f.scopeIds, active: f.active, notes: f.notes.trim() || null };
  const r = await act.run(() => (isNew.value ? api.postIdempotent('/payroll/holidays', body) : api.patch(`/payroll/holidays/${encodeURIComponent(props.holiday.id)}`, body)), { success: t('حُفظت العطلة', 'Holiday saved') });
  if (r) emit('close');
}
</script>

<template>
  <FormShell :open="!!holiday" :width="560" :title="isNew ? { ar: 'عطلة رسمية جديدة', en: 'New public holiday' } : { ar: 'تعديل العطلة', en: 'Edit the holiday' }"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="{ ar: 'الاسم والتاريخ، ومن تشملهم العطلة إن لم تكن للشركة كلها', en: 'The name and dates, and whom it is for when not the whole company' }"
             @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.nameAr" required :label="{ ar: 'الاسم بالعربية', en: 'Name (Arabic)' }" :placeholder="{ ar: 'مثال: إجازة اليوم الوطني', en: 'e.g. National day' }" :error="errors.nameAr" />
      <TextInput v-model="f.nameEn" dir="ltr" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" :error="errors.nameEn" />
      <DateInput v-model="f.dateFrom" required :label="{ ar: 'من', en: 'From' }" :error="errors.dateFrom" />
      <DateInput v-model="f.dateTo" :min="f.dateFrom || null" :label="{ ar: 'إلى', en: 'To' }" :error="errors.dateTo" />
      <PillChoice v-model="f.scope" full :options="optionsOf(HOLIDAY_SCOPE)" :label="{ ar: 'تشمل', en: 'For' }" :error="errors.scope" />
      <Field v-if="f.scope === 'department'" full :label="{ ar: 'الأقسام', en: 'Departments' }" :error="errors.scopeIds">
        <div class="row wrap !gap-1.5">
          <button v-for="d in departments" :key="d.id" type="button" class="pill" :class="{ active: f.scopeIds.includes(d.id) }" :aria-pressed="f.scopeIds.includes(d.id)" @click="toggle(d.id)">{{ pname(d) }}</button>
        </div>
      </Field>
      <Field v-if="f.scope === 'employee'" full :label="{ ar: 'الموظفون', en: 'Employees' }" :error="errors.scopeIds">
        <SelectInput v-model="pickEmployee" small :options="employeeOptions" :placeholder="{ ar: '+ أضِف موظفًا', en: '+ Add an employee' }" />
        <div class="row wrap mt-1.5 !gap-1.5">
          <span v-for="id in f.scopeIds" :key="id" class="pill active inline-flex items-center gap-1">{{ employeeName(id) }}<button type="button" class="font-bold" :aria-label="t('إزالة', 'Remove')" @click="toggle(id)">×</button></span>
        </div>
      </Field>
      <label class="field flex-row items-center gap-2 text-[11px] font-bold"><input v-model="f.active" type="checkbox" class="h-4 w-4">{{ t('مفعَّلة', 'Active') }}</label>
      <TextArea v-model="f.notes" :rows="2" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.notes" />
    </div>
  </FormShell>
</template>
