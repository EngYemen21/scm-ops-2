<script setup>
// Tasks as a list (GET /workspace/tasks): «مهامي» — what is given to the caller, whatever the project — or every task
// he may see, of one project or of all. Search, filters, sort and paging live in the address (state, project, stage,
// assignee, tag, priority, due, q, sort, order, page); a late task is marked in red; a row opens the task (`task=`).
//   <TaskList mine :work="work" />        <TaskList :work="work" />
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, fmtNum, nm, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import OdooMark from './OdooMark.vue';
import TagChips from './TagChips.vue';
import { API, ARCHIVED_CHIP, PAGE_SIZE, PRIORITY, PRIORITY_OPTIONS, TASK_STATUS, TONE, arr, deadlineInfo, personName, useSearchBox, useWsQuery } from './workspace';

const props = defineProps({
  /** Only the tasks given to the caller, across projects. */
  mine: { type: Boolean, default: false },
  /** GET tasks/lookups: projects with their stages, tags, the people tasks are given to. */
  work: { type: Object, default: null },
});

const auth = useAuth();
const qs = useWsQuery();
const search = useSearchBox(qs);

// ---- the filters of the address
const STATES = ['open', 'done', 'cancelled', 'archived', 'all', 'board'];
const DUES = ['overdue', 'today', 'week', 'none'];
const state = computed(() => (STATES.includes(qs.get('state')) ? qs.get('state') : 'open'));
const due = computed(() => (DUES.includes(qs.get('due')) ? qs.get('due') : ''));
const project = computed(() => (props.mine ? '' : qs.get('project')));
const chosen = computed(() => arr(props.work?.projects).find((p) => p.id === project.value) || null);
const stage = computed(() => (chosen.value ? qs.get('stage') : ''));
const FILTERS = computed(() => (props.mine ? ['state', 'tag', 'priority', 'due', 'q'] : ['state', 'project', 'stage', 'assignee', 'tag', 'priority', 'due', 'q']));
const filtered = computed(() => FILTERS.value.some((k) => qs.get(k) !== ''));
const params = computed(() => ({
  scope: props.mine ? 'mine' : 'all', state: state.value, project: project.value || undefined, stage: stage.value || undefined,
  assignee: props.mine ? undefined : qs.get('assignee') || undefined, tag: qs.get('tag') || undefined, priority: qs.get('priority') || undefined, due: due.value || undefined,
  q: qs.get('q') || undefined, sort: qs.get('sort') || undefined, order: qs.get('sort') ? (qs.get('order') === 'asc' ? 'asc' : 'desc') : undefined, page: qs.page(), pageSize: PAGE_SIZE,
}));
const list = useList(`${API}/tasks`, params);

const STATE_TABS = computed(() => [
  { k: 'open', label: { ar: 'مفتوحة', en: 'Open' } }, { k: 'done', label: { ar: 'مكتملة', en: 'Done' } }, { k: 'cancelled', label: { ar: 'ملغاة', en: 'Cancelled' } },
  { k: 'archived', label: { ar: 'مؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'كل الحالات', en: 'Every state' } },
  // what a board shows (open and finished); offered only when a board sent the reader here
  ...(state.value === 'board' ? [{ k: 'board', label: { ar: 'ما على اللوحة', en: 'On the board' } }] : []),
]);
const DUE_OPTIONS = [
  { v: 'overdue', l: { ar: 'متأخرة', en: 'Overdue' } }, { v: 'today', l: { ar: 'موعدها اليوم', en: 'Due today' } },
  { v: 'week', l: { ar: 'خلال أسبوع', en: 'Within a week' } }, { v: 'none', l: { ar: 'بلا موعد', en: 'No deadline' } },
];
const projectOptions = computed(() => [{ v: 'none', l: { ar: 'مهام شخصية (بلا مشروع)', en: 'Personal tasks (no project)' } }, ...arr(props.work?.projects).map((p) => ({ v: p.id, l: String(p.name ?? p.number) }))]);
const stageOptions = computed(() => arr(chosen.value?.stages).map((s) => ({ v: s.id, l: personName(s) })));
const assigneeOptions = computed(() => [
  { v: 'me', l: { ar: 'أنا', en: 'Me' } }, { v: 'none', l: { ar: 'غير مسندة', en: 'Unassigned' } },
  ...arr(props.work?.assignees).filter((p) => p.key && p.id !== auth.user?.id).map((p) => ({ v: String(p.key), l: p.external ? `${personName(p)} · Odoo` : personName(p) })),
]);
const tagOptions = computed(() => arr(props.work?.tags).filter((x) => x.active !== false || x.id === qs.get('tag')).map((x) => ({ v: x.id, l: String(x.name ?? '') })));

// ---- the table
const columns = computed(() => [
  { key: 'number', header: { ar: 'الرقم', en: 'Number' }, width: '128px', kind: 'id', sortable: true },
  { key: 'title', header: { ar: 'المهمة', en: 'Task' }, width: 'minmax(220px,2.2fr)', sortable: true },
  { key: 'stage', header: { ar: 'المرحلة', en: 'Stage' }, width: '132px', bare: true },
  { key: 'priority', header: { ar: 'الأولوية', en: 'Priority' }, width: '84px', sortable: true, bare: true },
  { key: 'assignees', header: { ar: 'المسؤولون', en: 'Assignees' }, width: 'minmax(120px,1fr)', hidden: props.mine },
  { key: 'deadline', header: { ar: 'الموعد', en: 'Deadline' }, width: '156px', sortable: true },
  { key: 'checklist', header: { ar: 'قائمة التحقق', en: 'Checklist' }, width: '84px' },
  { key: 'createdAt', header: { ar: 'أُنشئت', en: 'Created' }, width: '96px', kind: 'date', sortable: true, value: (r) => fmtDate(r.createdAt, { time: false }) },
]);
const late = (r) => r.status === 'open' && !r.archived && !!r.overdue;
const rowStyle = (r) => (late(r) ? { background: '#FFF8F8', borderInlineStart: '3px solid #b23b3b' } : null);
const deadlineOf = (r) => deadlineInfo(r.deadline, r.status !== 'open');
const people = (r) => arr(r.assignees).map(personName).join('، ');
const emptyText = computed(() => (filtered.value ? { ar: 'لا مهام تطابق هذا الفلتر.', en: 'No task matches this filter.' }
  : props.mine ? { ar: 'لا مهام مفتوحة مسندة إليك.', en: 'No open task is assigned to you.' } : { ar: 'لا مهام مفتوحة.', en: 'No open tasks.' }));
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالرقم أو العنوان…', en: 'Search number or title…' }" />
    <Tabs :model-value="state" :tabs="STATE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => qs.patch({ state: k === 'open' ? '' : k })" />
    <template v-if="!mine">
      <SelectInput small class="w-[170px]" :model-value="project" :options="projectOptions" :placeholder="{ ar: 'كل المشاريع', en: 'Every project' }" :aria-label="t('المشروع', 'Project')" @update:model-value="(v) => qs.patch({ project: v, stage: '' })" />
      <SelectInput v-if="chosen" small class="w-[150px]" :model-value="stage" :options="stageOptions" :placeholder="{ ar: 'كل المراحل', en: 'Every stage' }" :aria-label="t('المرحلة', 'Stage')" @update:model-value="(v) => qs.patch({ stage: v })" />
      <SelectInput small class="w-[160px]" :model-value="qs.get('assignee')" :options="assigneeOptions" :placeholder="{ ar: 'كل المسؤولين', en: 'Every assignee' }" :aria-label="t('المسؤول', 'Assignee')" @update:model-value="(v) => qs.patch({ assignee: v })" />
    </template>
    <SelectInput small class="w-[140px]" :model-value="qs.get('tag')" :options="tagOptions" :placeholder="{ ar: 'كل الوسوم', en: 'Every tag' }" :aria-label="t('الوسم', 'Tag')" @update:model-value="(v) => qs.patch({ tag: v })" />
    <SelectInput small class="w-[130px]" :model-value="qs.get('priority')" :options="PRIORITY_OPTIONS" :placeholder="{ ar: 'كل الأولويات', en: 'Every priority' }" :aria-label="t('الأولوية', 'Priority')" @update:model-value="(v) => qs.patch({ priority: v })" />
    <SelectInput small class="w-[140px]" :model-value="due" :options="DUE_OPTIONS" :placeholder="{ ar: 'كل المواعيد', en: 'Any deadline' }" :aria-label="t('الموعد', 'Deadline')" @update:model-value="(v) => qs.patch({ due: v })" />
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="qs.patch(Object.fromEntries(FILTERS.map((k) => [k, ''])))" />
  </div>

  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="qs.get('task') || null" :row-style="rowStyle"
               server-sort :sort="qs.sort()" :empty-text="emptyText" @sort="qs.setSort" @page="(p) => qs.patch({ page: p })" @row-click="(r) => qs.open('task', r.number)">
      <template #cell-title="{ row }">
        <div class="cell-name break-words" :class="{ 'text-muted line-through': row.status !== 'open' }" dir="auto">{{ row.title }}</div>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[9.5px] text-muted">
          <span dir="auto">{{ row.project ? row.project.name : t('مهمة شخصية', 'Personal task') }}</span>
          <span v-if="row.commentCount" class="num">· {{ fmtNum(row.commentCount) }} {{ t('تعليق', 'comments') }}</span>
          <TagChips :tags="row.tags" />
          <OdooMark :row="row" />
        </div>
      </template>
      <template #cell-stage="{ row }">
        <span class="inline-flex flex-wrap items-center gap-1">
          <Chip v-if="row.status !== 'open'" :map="TASK_STATUS" :k="row.status" small />
          <Chip v-else-if="row.stage" small :fg="TONE.neutral.fg" :bg="TONE.neutral.bg">{{ nm(row.stage) }}</Chip>
          <span v-else class="text-faint">—</span>
          <Chip v-if="row.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        </span>
      </template>
      <template #cell-priority="{ row }"><Chip :map="PRIORITY" :k="String(row.priority ?? 0)" small /></template>
      <template #cell-assignees="{ row }"><span v-if="people(row)" dir="auto">{{ people(row) }}</span><span v-else class="text-faint">{{ t('غير مسندة', 'Unassigned') }}</span></template>
      <template #cell-deadline="{ row }">
        <span v-if="deadlineOf(row)" class="text-[10px] font-extrabold" :class="late(row) ? 'text-bad' : deadlineOf(row).today ? 'text-warn' : 'text-sec'">{{ deadlineOf(row).text }}</span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-checklist="{ row }"><span v-if="row.checklist?.total" class="num">{{ fmtNum(row.checklist.done) }}/{{ fmtNum(row.checklist.total) }}</span><span v-else class="text-faint">—</span></template>
    </DataTable>
  </div>
</template>
