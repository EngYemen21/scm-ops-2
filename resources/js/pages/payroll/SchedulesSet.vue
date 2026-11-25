<script setup>
// Work schedules: GET /payroll/schedules → { items: [{ id, nameAr, nameEn, timezone, hoursPerDay, isDefault, active, notes,
// employees, workingDays[], weeklyMinutes, days: [{ id, weekday 0=Mon..6=Sun, kind work|break, name, from, to }], source, locked }] }.
// «+ جدول» / «تعديل» → ScheduleForm (POST /payroll/schedules · PATCH /payroll/schedules/{id}). The list is small and whole:
// search and sort happen here.
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import ScheduleForm from './ScheduleForm.vue';
import { DEFAULT_CHIP, FROM_ODOO, INACTIVE_CHIP, hm, pname, weekdayName } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/schedules');
const search = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  return (q.data.value?.items || []).filter((r) => !s || `${r.nameAr} ${r.nameEn} ${r.timezone}`.toLowerCase().includes(s));
});
const columns = [
  { key: 'name', header: { ar: 'الجدول', en: 'Schedule' }, width: 'minmax(170px,1.4fr)', sortable: true, sortValue: (r) => pname(r) },
  { key: 'days', header: { ar: 'أيام العمل', en: 'Working days' }, width: 'minmax(170px,1.4fr)', value: (r) => r.workingDays.map(weekdayName).join('، ') || '—' },
  { key: 'hoursPerDay', header: { ar: 'ساعات اليوم', en: 'Hours/day' }, width: '78px', kind: 'num', sortable: true },
  { key: 'weekly', header: { ar: 'أسبوعيًا', en: 'Weekly' }, width: '74px', kind: 'num', sortable: true, sortValue: (r) => r.weeklyMinutes, value: (r) => hm(r.weeklyMinutes) },
  { key: 'employees', header: { ar: 'موظفون', en: 'Employees' }, width: '74px', kind: 'num', sortable: true },
  { key: 'state', header: { ar: '', en: '' }, width: '150px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];
/** null = closed · {} = new · a row = edit */
const editing = ref(null);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالاسم أو المنطقة الزمنية…', en: 'Search name or time zone…' }" />
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ جدول دوام', en: '+ Schedule' }" @click="editing = {}" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :loading="q.isLoading.value" :page-size="25" :row-key="(r) => r.id" :empty-text="search ? { ar: 'لا جداول مطابقة.', en: 'No matching schedule.' } : { ar: 'لا جداول دوام بعد.', en: 'No schedule yet.' }">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ pname(row) }}</div><div class="cell-sub">{{ row.timezone }}</div></div></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="row.isDefault" small :label="DEFAULT_CHIP" :fg="DEFAULT_CHIP.fg" :bg="DEFAULT_CHIP.bg" />
          <Chip v-if="!row.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }"><Btn v-if="canEdit" size="sm" tone="ghost" :label="row.locked ? { ar: 'ملاحظات', en: 'Notes' } : { ar: 'تعديل', en: 'Edit' }" @click="editing = row" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الأيام: 0 = الاثنين … 6 = الأحد. تغيير الجدول يسري من الآن: الكشوف والقسائم المحسوبة تحتفظ بما حُسبت به. على جدول من أودو تبقى هنا الملاحظات واختيار الافتراضي فقط.', 'Days: 0 = Monday … 6 = Sunday. A change applies from now on: sheets and payslips already made keep what they were made with. On a schedule from Odoo only the notes and the default choice stay here.') }}</div>

  <ScheduleForm :schedule="editing" @close="editing = null" />
</template>
