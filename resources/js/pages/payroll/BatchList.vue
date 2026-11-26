<script setup>
// Batches of attendance sheets (a department, or everyone, for one period):
//   GET /payroll/sheet-batches?state&department&q&sort(number|state)&order&page → rows { id, number, name, department,
//   dateFrom, dateTo, state, sheetCount, sheetsByState, next, source, locked }
//   draft ──generate──▶ generated ──submit──▶ submitted ──close──▶ closed
// «+ دفعة» (attendance.manage): POST /payroll/sheet-batches { department?, dateFrom, dateTo, name? }. A row opens the batch.
import { computed, ref } from 'vue';
import { api, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { BATCH_STATE, FROM_ODOO, fmtDay, optionsOf, pname, todayIso, usePayrollLookups, usePayrollNav, usePayrollPerms, useSearchParam } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const { departmentOptions } = usePayrollLookups();
const { text, type, apply } = useSearchParam(nav);
const state = computed(() => (Object.keys(BATCH_STATE).includes(nav.get('state')) ? nav.get('state') : ''));
const sort = computed(() => nav.sortOf(['number', 'state'], null));
const list = useList('/payroll/sheet-batches', () => ({
  state: state.value || undefined, department: nav.get('department') || undefined, q: nav.get('q') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const columns = [
  { key: 'number', header: { ar: 'الدفعة', en: 'Batch' }, width: '116px', kind: 'id', sortable: true },
  { key: 'name', header: { ar: 'الاسم والقسم', en: 'Name & department' }, width: 'minmax(160px,1.5fr)' },
  { key: 'dates', header: { ar: 'المدة', en: 'Dates' }, width: '170px', kind: 'date', value: (r) => `${fmtDay(r.dateFrom)} – ${fmtDay(r.dateTo)}` },
  { key: 'sheets', header: { ar: 'الكشوف', en: 'Sheets' }, width: '150px', bare: true },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '160px', sortable: true, bare: true },
];

const creating = ref(false);
const fields = computed(() => [
  { k: 'name', label: { ar: 'اسم الدفعة (اختياري)', en: 'Name (optional)' }, full: true },
  { k: 'department', label: { ar: 'القسم', en: 'Department' }, type: 'select', full: true, opts: departmentOptions.value, ph: { ar: '— كل الأقسام —', en: '— every department —' } },
  { k: 'dateFrom', label: { ar: 'من', en: 'From' }, type: 'date', required: true },
  { k: 'dateTo', label: { ar: 'إلى', en: 'To' }, type: 'date', required: true, validate: (v, all) => (all.dateFrom && v < all.dateFrom ? t('النهاية لا تسبق البداية', 'The end cannot be before the start') : null) },
]);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[190px]" :model-value="text" :placeholder="{ ar: 'بحث بالرقم أو الاسم…', en: 'Search number or name…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[170px]" :model-value="state" :options="optionsOf(BATCH_STATE)" :placeholder="{ ar: 'كل الحالات', en: 'Every state' }" @update:model-value="(v) => nav.setFilter('state', v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <span class="grow" />
    <Btn v-if="perms.attendanceManage" size="sm" tone="primary" :label="{ ar: '+ دفعة كشوف', en: '+ Sheet batch' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('batch') || null" :sort="sort" server-sort
               :empty-text="{ ar: 'لا دفعات بعد — الدفعة تُنشئ كشوف قسم كامل لمدة واحدة.', en: 'No batch yet — a batch makes the sheets of a whole department for one period.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('batch', r.number)">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="cell-name truncate" dir="auto">{{ row.name || '—' }}</div><div class="truncate text-[9.5px] text-muted" dir="auto">{{ row.department ? pname(row.department) : t('كل الأقسام', 'Every department') }}</div></div></template>
      <template #cell-sheets="{ row }">
        <span class="text-[10px]"><b class="num">{{ row.sheetCount }}</b> · <span class="text-muted">{{ t('مسودة', 'draft') }} <span class="num">{{ row.sheetsByState.draft }}</span> · {{ t('مؤكَّد', 'conf.') }} <span class="num">{{ row.sheetsByState.confirmed }}</span> · {{ t('معتمد', 'appr.') }} <span class="num">{{ row.sheetsByState.approved }}</span></span></span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1"><Chip small :map="BATCH_STATE" :k="row.state" /><Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></span>
      </template>
    </DataTable>
  </div>

  <FormDrawer :open="creating" :title="{ ar: 'دفعة كشوف حضور', en: 'Sheet batch' }" :sub="{ ar: 'تُنشأ بعدها كشوف كل من له عقد سارٍ في المدة ولا كشف له فيها', en: 'Then it makes a sheet for everyone with a contract in the period and no sheet in it yet' }"
              :fields="fields" :initial="{ dateFrom: `${todayIso().slice(0, 8)}01`, dateTo: todayIso() }" :submit="(v) => api.postIdempotent('/payroll/sheet-batches', v)" :submit-label="{ ar: 'إنشاء الدفعة', en: 'Make the batch' }"
              :action="{ success: (b) => t(`أُنشئت الدفعة ${b.number}`, `Batch ${b.number} made`), invalidate: ['payroll'] }" @close="creating = false" @done="(b) => nav.open('batch', b.number)" />
</template>
