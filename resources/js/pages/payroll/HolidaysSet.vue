<script setup>
// Public holidays: GET /payroll/holidays?year&from&to&active(1 default | 0 | all)&q&sort=date&order&page
// → rows { id, nameAr, nameEn, dateFrom, dateTo, days, scope company|department|employee, scopeIds, active, notes, source, locked }.
// «+ عطلة» / «تعديل» → HolidayForm (POST /payroll/holidays · PATCH /payroll/holidays/{id}). A holiday from Odoo is changed there.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import HolidayForm from './HolidayForm.vue';
import { FROM_ODOO, HOLIDAY_SCOPE, INACTIVE_CHIP, fmtDay, own, pname, usePayrollNav, useSearchParam, yearOptions } from './payroll';

defineProps({
  canEdit: { type: Boolean, default: false },
});

const nav = usePayrollNav();
const { text, type, apply } = useSearchParam(nav);
const active = computed(() => (['0', 'all'].includes(nav.get('active')) ? nav.get('active') : ''));
const sort = computed(() => (nav.get('sort') === 'date' ? { key: 'date', order: nav.get('order') === 'desc' ? 'desc' : 'asc' } : { key: 'date', order: 'asc' }));
const list = useList('/payroll/holidays', () => ({
  year: /^\d{4}$/.test(nav.get('year')) ? nav.get('year') : String(new Date().getFullYear()), active: active.value || undefined, q: nav.get('q') || undefined,
  sort: 'date', order: sort.value.order, page: nav.page.value, pageSize: 50,
}));
const columns = [
  { key: 'name', header: { ar: 'العطلة', en: 'Holiday' }, width: 'minmax(180px,1.5fr)' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Dates' }, width: '176px', kind: 'date', sortable: true, value: (r) => (r.dateTo !== r.dateFrom ? `${fmtDay(r.dateFrom)} – ${fmtDay(r.dateTo)}` : fmtDay(r.dateFrom)) },
  { key: 'days', header: { ar: 'الأيام', en: 'Days' }, width: '60px', kind: 'num' },
  { key: 'scope', header: { ar: 'تشمل', en: 'For' }, width: '150px', value: (r) => `${bi(own(HOLIDAY_SCOPE, r.scope) || HOLIDAY_SCOPE.company)}${r.scope !== 'company' ? ` (${r.scopeIds.length})` : ''}` },
  { key: 'state', header: { ar: '', en: '' }, width: '130px', bare: true },
  { key: 'act', header: { ar: '', en: '' }, width: '76px', bare: true },
];
const editing = ref(null);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[200px]" :model-value="text" :placeholder="{ ar: 'بحث بالاسم…', en: 'Search name…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[100px]" :model-value="nav.get('year') || String(new Date().getFullYear())" :options="yearOptions()" @update:model-value="(v) => nav.setFilter('year', v)" />
    <SelectInput small class="w-[150px]" :model-value="active" :options="[['0', { ar: 'الموقوفة', en: 'Inactive' }], ['all', { ar: 'الكل', en: 'All' }]]" :placeholder="{ ar: 'المفعَّلة', en: 'Active' }" @update:model-value="(v) => nav.setFilter('active', v)" />
    <span class="grow" />
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ عطلة رسمية', en: '+ Public holiday' }" @click="editing = {}" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="{ ar: 'لا عطل رسمية في هذه السنة.', en: 'No public holiday this year.' }" @sort="nav.setSort" @page="nav.setPage">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ pname(row) }}</div><div v-if="row.notes" class="truncate text-[9.5px] text-muted" dir="auto">{{ row.notes }}</div></div></template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="!row.active" small :label="INACTIVE_CHIP" :fg="INACTIVE_CHIP.fg" :bg="INACTIVE_CHIP.bg" />
          <Chip v-if="row.locked" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
      <template #cell-act="{ row }"><Btn v-if="canEdit && !row.locked" size="sm" tone="ghost" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('العطلة الرسمية لا تُحسب يوم عمل: لا غياب فيها، ولا تُخصم من رصيد الإجازة، والعمل فيها إضافي بحسب سياسة الحضور.', 'A public holiday is not a working day: no absence on it, it is not taken from a leave balance, and work on it is overtime under the attendance policy.') }}</div>

  <HolidayForm :holiday="editing" @close="editing = null" />
</template>
