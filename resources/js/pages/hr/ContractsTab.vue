<script setup>
// Employment contracts (hr.private — the tab exists only with it): GET /hr/contracts?q=&state=&ending=&source=&sort=&order=&page=.
// State, "ending soon", search, sort and page live in the query string. A row opens the contract (`contract=<number>`).
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import ContractForm from './ContractForm.vue';
import { ANY, CONTRACT_STATE, CONTRACT_TYPE, FROM_ODOO, STAGE, fmtDay, money, pname, useHrNav, useSearchParam } from './shared';

const nav = useHrNav();
const { page } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);

const state = computed(() => (Object.keys(CONTRACT_STATE).includes(nav.get('state')) ? nav.get('state') : ''));
const ending = computed(() => (nav.get('ending') ? '60' : ''));
const sort = computed(() => (['start', 'end', 'number'].includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : { key: 'start', order: 'desc' }));
const list = useList('/hr/contracts', () => ({ q: nav.get('q'), state: state.value || undefined, ending: ending.value || undefined, sort: sort.value.key, order: sort.value.order, page: page.value, pageSize: 25 }));

const stateTabs = [{ k: '', label: ANY }, ...Object.entries(CONTRACT_STATE).map(([k, l]) => ({ k, label: l }))];
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const columns = [
  { key: 'number', header: { ar: 'العقد', en: 'Contract' }, width: '124px', kind: 'id', sortable: true },
  { key: 'employee', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(180px,2fr)' },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: 'minmax(110px,1fr)', value: (r) => r.typeLabel || bi(CONTRACT_TYPE[r.type] || r.type) },
  { key: 'start', header: { ar: 'البداية', en: 'Start' }, width: '92px', kind: 'date', sortable: true, value: (r) => fmtDay(r.startDate) },
  { key: 'end', header: { ar: 'النهاية', en: 'End' }, width: '150px', sortable: true },
  { key: 'package', header: { ar: 'إجمالي الشهر', en: 'Monthly package' }, width: '112px', kind: 'num', value: (r) => money(r.package) },
  { key: 'state', header: { ar: 'الحالة', en: 'State' }, width: '104px', bare: true },
];
const creating = ref(false);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: 'بحث برقم العقد أو الموظف…', en: 'Search contract number or employee…' }" @update:model-value="typeQ" @enter="applyQ" />
    <Tabs :model-value="state" :tabs="stateTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k)" />
    <button type="button" class="pill" :class="{ active: !!ending }" @click="nav.setFilter('ending', ending ? null : '60')">{{ t('تنتهي خلال 60 يومًا', 'Ending within 60 days') }}</button>
    <span class="grow" />
    <Btn size="sm" tone="primary" :label="{ ar: '+ عقد', en: '+ Contract' }" @click="creating = true" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.number" :selected-key="nav.get('contract') || null" :sort="sort" server-sort
               :empty-text="nav.get('q') || state || ending ? { ar: 'لا عقود مطابقة.', en: 'No matching contract.' } : { ar: 'لا عقود بعد.', en: 'No contracts yet.' }"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openContract(r.number)">
      <template #cell-employee="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.employee ? pname(row.employee) : '—' }}</div>
          <div class="truncate text-[9.5px] text-muted"><span class="num ltr">{{ row.employee?.code }}</span><template v-if="row.jobTitle"> · {{ row.jobTitle }}</template></div>
        </div>
      </template>
      <template #cell-end="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <span v-if="row.endDate" class="cell-date">{{ fmtDay(row.endDate) }}</span>
          <span v-else class="text-[9.5px] text-faint">{{ t('غير محدد المدة', 'Open-ended') }}</span>
          <Chip v-if="row.stage" small :map="STAGE" :k="row.stage" />
        </span>
      </template>
      <template #cell-state="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :map="CONTRACT_STATE" :k="row.state" />
          <Chip v-if="row.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('العقود جزء من ملفات الأشخاص: هذا التبويب لا يظهر إلا لمن يملك صلاحية الملفات الشخصية، والواجهة البرمجية لا تعيد عقدًا لغيره. للموظف عقد سارٍ واحد؛ العقد الذي انتهى تاريخه يبقى ساريًا حتى يُجدَّد أو يُنهى، ويظهر في «وثائق مستحقة».', 'Contracts are people’s files: this tab appears only for holders of the personal-files permission, and the API returns no contract to anyone else. An employee has one running contract; a contract whose end date passed stays running until it is renewed or ended, and shows under “Documents due”.') }}</div>

  <ContractForm :open="creating" @close="creating = false" @done="(c) => nav.openContract(c.number)" />
</template>
