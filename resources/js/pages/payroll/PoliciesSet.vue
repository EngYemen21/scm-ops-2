<script setup>
// Attendance policies: GET /payroll/policies → { items: [{ id, nameAr, nameEn, lateGraceMinutes, earlyGraceMinutes, isDefault,
// incomplete, active, notes, employees, late[], early[], absence[], overtime[], source, locked }] } — how actual lateness, early
// leave, absence and overtime become the figures a sheet counts. «+ سياسة» / «تعديل» → PolicyForm.
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import PolicyForm from './PolicyForm.vue';
import { DEFAULT_CHIP, FROM_ODOO, INACTIVE_CHIP, TONE, pname } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const q = useGet('/payroll/policies');
const search = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  return (q.data.value?.items || []).filter((r) => !s || `${r.nameAr} ${r.nameEn}`.toLowerCase().includes(s));
});
const INCOMPLETE = { ar: 'غير مكتملة', en: 'Incomplete', ...TONE.warn };
const columns = [
  { key: 'name', header: { ar: 'السياسة', en: 'Policy' }, width: 'minmax(170px,1.5fr)', sortable: true, sortValue: (r) => pname(r) },
  { key: 'grace', header: { ar: 'السماح (تأخير/مبكر)', en: 'Grace (late/early)' }, width: '120px', kind: 'num', value: (r) => `${r.lateGraceMinutes} / ${r.earlyGraceMinutes}` },
  { key: 'rules', header: { ar: 'القواعد', en: 'Rules' }, width: 'minmax(170px,1.4fr)' },
  { key: 'employees', header: { ar: 'موظفون', en: 'Employees' }, width: '74px', kind: 'num', sortable: true },
  { key: 'state', header: { ar: '', en: '' }, width: '150px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];
const editing = ref(null);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالاسم…', en: 'Search name…' }" />
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ سياسة حضور', en: '+ Policy' }" @click="editing = {}" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :loading="q.isLoading.value" :page-size="25" :row-key="(r) => r.id" :empty-text="{ ar: 'لا سياسات حضور — بلا سياسة يُحتسب الوقت الفعلي كما هو.', en: 'No attendance policy — without one the actual time counts as it is.' }">
      <template #cell-name="{ row }"><div class="cell-name truncate" dir="auto">{{ pname(row) }}</div></template>
      <template #cell-rules="{ row }">
        <span class="text-[10px] text-muted">{{ t('تأخير', 'late') }} <span class="num">{{ row.late.length }}</span> · {{ t('مبكر', 'early') }} <span class="num">{{ row.early.length }}</span> · {{ t('غياب', 'absence') }} <span class="num">{{ row.absence.length }}</span> · {{ t('إضافي', 'overtime') }} <span class="num">{{ row.overtime.length }}</span></span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="row.isDefault" small :label="DEFAULT_CHIP" :fg="DEFAULT_CHIP.fg" :bg="DEFAULT_CHIP.bg" />
          <Chip v-if="row.incomplete" small :label="INCOMPLETE" :fg="INCOMPLETE.fg" :bg="INCOMPLETE.bg" />
          <Chip v-if="!row.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }"><Btn v-if="canEdit" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('سياسة «غير مكتملة» جاءت قواعدها من أودو بشكل لا يُقرأ كاملًا: تُعرض ولا تُطبَّق حتى تُكتب قواعدها هنا. السماح بالدقائق قبل أن يُحتسب تأخير أو خروج مبكر.', 'An “incomplete” policy came from Odoo with rules that cannot be read in full: it is shown and not applied until its rules are written here. Grace is the minutes before lateness or early leave counts.') }}</div>

  <PolicyForm :policy="editing" @close="editing = null" />
</template>
