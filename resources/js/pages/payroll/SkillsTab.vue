<script setup>
// «المهارات» / Skills — payroll.manage / payroll.approve:
//   GET /payroll/skills/employees?department&type&skill&level&has(skills|resume|none)&q&sort(code)&order&page
//   → rows { employee, department, jobTitle, skills: [{ skill, level { progress } }], resumeLines }
// A row opens the employee's skills and résumé (`emp=<id>`); «دليل المهارات» keeps the catalogue (payroll.manage).
import { computed, ref } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import SkillsCatalog from './SkillsCatalog.vue';
import { TONE, pname, usePayrollLookups, usePayrollNav, usePayrollPerms, useSearchParam } from './payroll';

const nav = usePayrollNav();
const perms = usePayrollPerms();
const { departmentOptions } = usePayrollLookups();
const { text, type: typeText, apply } = useSearchParam(nav);
const catalog = useGet('/payroll/skills/catalog', undefined, { staleTime: 60_000 });
const types = computed(() => catalog.data.value?.types || []);
const typeOptions = computed(() => types.value.map((x) => [x.id, pname(x)]));
const skillOptions = computed(() => (nav.get('type') ? types.value.filter((x) => x.id === nav.get('type')) : types.value).flatMap((x) => x.skills.map((s) => [s.id, pname(s)])));
const HAS = [['skills', { ar: 'لهم مهارات', en: 'With skills' }], ['resume', { ar: 'لهم سيرة', en: 'With a résumé' }], ['none', { ar: 'بلا مهارات ولا سيرة', en: 'With neither' }]];

const sort = computed(() => nav.sortOf(['code', 'name'], null));
const list = useList('/payroll/skills/employees', () => ({
  department: nav.get('department') || undefined, type: nav.get('type') || undefined, skill: nav.get('skill') || undefined, has: nav.get('has') || undefined, q: nav.get('q') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const filtered = computed(() => ['q', 'department', 'type', 'skill', 'has'].some((k) => !!nav.get(k)));
const clear = () => nav.setFilters({ q: null, department: null, type: null, skill: null, has: null });
const columns = [
  { key: 'name', header: { ar: 'الموظف', en: 'Employee' }, width: 'minmax(170px,1.3fr)', sortable: true },
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '96px', kind: 'id', sortable: true, value: (r) => r.employee?.code },
  { key: 'skills', header: { ar: 'المهارات', en: 'Skills' }, width: 'minmax(240px,2.2fr)', bare: true },
  { key: 'resumeLines', header: { ar: 'سطور السيرة', en: 'Résumé lines' }, width: '84px', kind: 'num' },
];
const catalogOpen = ref(false);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[210px]" :model-value="text" :placeholder="{ ar: 'بحث بالموظف أو المهارة…', en: 'Search employee or skill…' }" @update:model-value="typeText" @enter="apply" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('type')" :options="typeOptions" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" @update:model-value="(v) => nav.setFilters({ type: v, skill: null })" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('skill')" :options="skillOptions" :placeholder="{ ar: 'كل المهارات', en: 'Every skill' }" @update:model-value="(v) => nav.setFilter('skill', v)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('has')" :options="HAS" :placeholder="{ ar: 'الجميع', en: 'Everyone' }" @update:model-value="(v) => nav.setFilter('has', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
    <span class="grow" />
    <Btn v-if="perms.payrollManage" size="sm" tone="soft" :label="{ ar: 'دليل المهارات', en: 'Skills catalogue' }" @click="catalogOpen = true" />
  </div>
  <ErrorBanner :error="list.error.value || catalog.error.value" :closable="false" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.employee?.id" :selected-key="nav.get('emp') || null" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا موظفين مطابقين.', en: 'No matching employee.' } : { ar: 'لا موظفين على رأس العمل.', en: 'No employee at work.' }"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => nav.open('emp', r.employee?.id)">
      <template #cell-name="{ row }"><div class="min-w-0"><div class="truncate text-[11px] font-bold" dir="auto">{{ pname(row.employee) }}</div><div class="truncate text-[9.5px] text-muted" dir="auto">{{ [row.jobTitle, row.department ? pname(row.department) : ''].filter(Boolean).join(' · ') || '—' }}</div></div></template>
      <template #cell-skills="{ row }">
        <span v-if="row.skills.length" class="inline-flex flex-wrap gap-1">
          <Chip v-for="(s, i) in row.skills.slice(0, 6)" :key="i" small :label="s.level ? `${pname(s.skill)} · ${pname(s.level)}` : pname(s.skill)" :fg="TONE.violet.fg" :bg="TONE.violet.bg" />
          <span v-if="row.skills.length > 6" class="text-[9.5px] text-muted">+{{ row.skills.length - 6 }}</span>
        </span>
        <span v-else class="text-[10px] text-faint">{{ t('لا مهارات', 'No skill') }}</span>
      </template>
    </DataTable>
  </div>

  <SkillsCatalog :open="catalogOpen" @close="catalogOpen = false" />
</template>
