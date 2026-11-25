<script setup>
// Projects and tasks, four views of the same work (the address says which: view=mine|projects|board|list):
//   mine      the open tasks given to the caller, whatever the project (the default)
//   projects  the projects he may see, with how their tasks stand
//   board     one project (project=<id>) as columns of its stages — cards move between them
//   list      every task he may see, of one project or of all, with every filter
// A task opens in a drawer (`task=<number>`), a project's details too (`prj=<id>`). Anybody who opens the section
// creates tasks — personal ones, or in a project he works in; project.manage creates projects and keeps the tags.
// GET /workspace/tasks/lookups (projects with their stages, tags, assignees) · GET /workspace/summary (the counters).
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, KpiCard, KpiGrid, SelectInput, Tabs } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import ProjectDrawer from './ProjectDrawer.vue';
import ProjectForm from './ProjectForm.vue';
import ProjectsList from './ProjectsList.vue';
import TagsManager from './TagsManager.vue';
import TaskBoard from './TaskBoard.vue';
import TaskDrawer from './TaskDrawer.vue';
import TaskForm from './TaskForm.vue';
import TaskList from './TaskList.vue';
import { API, arr, useLookups, useSummary, useWsQuery } from './workspace';

const auth = useAuth();
const qs = useWsQuery();
const summary = useSummary();
const lookups = useLookups();
const work = useGet(`${API}/tasks/lookups`, undefined, { staleTime: 60_000 });

// ---- which view, of which project
const VIEWS = ['mine', 'projects', 'board', 'list'];
const view = computed(() => (VIEWS.includes(qs.get('view')) ? qs.get('view') : 'mine'));
/** The project of the board on screen (`none` — the personal tasks — is a filter of the list, not a board). */
const boardProject = computed(() => (qs.get('project') && qs.get('project') !== 'none' ? qs.get('project') : ''));
/** A fresh address of this tab: the filters of the view that is left do not follow. */
const go = (query = {}) => qs.replace({ tab: 'tasks', ...query });
function setView(k) {
  if (k === view.value) return;
  const project = (k === 'board' && boardProject.value) || (k === 'list' && qs.get('project')) || undefined;
  go({ view: k === 'mine' ? undefined : k, project });
}
const toBoard = (id) => go({ view: 'board', project: id || undefined });
const VIEW_TABS = [
  { k: 'mine', label: { ar: 'مهامي', en: 'My tasks' } }, { k: 'projects', label: { ar: 'المشاريع', en: 'Projects' } },
  { k: 'board', label: { ar: 'لوحة المشروع', en: 'Project board' } }, { k: 'list', label: { ar: 'كل المهام', en: 'All tasks' } },
];
const projects = computed(() => arr(work.data.value?.projects));
const projectOptions = computed(() => projects.value.filter((p) => !p.archived || p.id === boardProject.value).map((p) => ({ v: p.id, l: `${p.name ?? ''} · ${p.number ?? ''}` })));
const current = computed(() => projects.value.find((p) => p.id === boardProject.value) || null);

// ---- the counters on top: each one is a ready-made view
const counts = computed(() => summary.data.value?.tasks || null);
const manages = computed(() => summary.data.value?.me?.manages?.projects ?? auth.can('project.manage'));
const plainMine = computed(() => view.value === 'mine' && !['state', 'tag', 'priority', 'q'].some((k) => qs.get(k) !== ''));
const isDue = (due) => plainMine.value && qs.get('due') === due;

// ---- what is open
/** { task: the task being edited | null, preset: { projectId, stageId } | null } while the task form is open */
const taskForm = ref(null);
/** { project: the project being edited | null } while the project form is open */
const projectForm = ref(null);
const tagsOpen = ref(false);
function newTask(stageId = '') {
  const inProject = (view.value === 'board' || view.value === 'list') && current.value?.canWork && !current.value.archived && current.value.status !== 'done';
  taskForm.value = { task: null, preset: inProject ? { projectId: current.value.id, stageId } : null };
}
function onTaskSaved(task) { if (!taskForm.value?.task && task?.number) qs.open('task', task.number); }
function onProjectSaved(project) { if (!projectForm.value?.project && project?.id) toBoard(project.id); }
</script>

<template>
  <KpiGrid compact>
    <KpiCard :value="counts?.mine" :label="{ ar: 'مهامي المفتوحة', en: 'My open tasks' }" color="#654e92" clickable :active="isDue('')" :loading="summary.isLoading.value" @click="go()" />
    <KpiCard :value="counts?.overdue" :label="{ ar: 'متأخرة عليّ', en: 'Mine, overdue' }" color="#b23b3b" clickable :active="isDue('overdue')" :loading="summary.isLoading.value" @click="go({ due: 'overdue' })" />
    <KpiCard :value="counts?.dueToday" :label="{ ar: 'موعدها اليوم', en: 'Mine, due today' }" color="#b26a16" clickable :active="isDue('today')" :loading="summary.isLoading.value" @click="go({ due: 'today' })" />
    <KpiCard :value="counts?.open" :label="{ ar: 'كل المهام المفتوحة', en: 'All open tasks' }" clickable :active="view === 'list'" :loading="summary.isLoading.value" @click="go({ view: 'list' })" />
  </KpiGrid>

  <div class="row wrap mb-3">
    <Tabs :model-value="view" :tabs="VIEW_TABS" variant="sm" class="!mb-0" @update:model-value="setView" />
    <span class="grow" />
    <Btn v-if="manages" tone="outline" size="sm" :label="{ ar: 'الوسوم', en: 'Tags' }" @click="tagsOpen = true" />
    <Btn v-if="manages" tone="outline" size="sm" :label="{ ar: '+ مشروع', en: '+ Project' }" @click="projectForm = { project: null }" />
    <Btn tone="primary" size="sm" :label="{ ar: '+ مهمة', en: '+ Task' }" @click="newTask()" />
  </div>
  <ErrorBanner :error="work.error.value" :closable="false" />

  <TaskList v-if="view === 'mine'" mine :work="work.data.value" />
  <ProjectsList v-else-if="view === 'projects'" @board="toBoard" @details="(id) => qs.open('prj', id)" />
  <template v-else-if="view === 'board'">
    <div class="row wrap mb-2.5">
      <SelectInput small class="w-[280px]" :model-value="boardProject" :options="projectOptions" :placeholder="{ ar: '— اختر مشروعًا —', en: '— pick a project —' }" :aria-label="t('المشروع', 'Project')" @update:model-value="toBoard" />
      <template v-if="boardProject">
        <Btn tone="outline" size="sm" :label="{ ar: 'تفاصيل المشروع', en: 'Project details' }" @click="qs.open('prj', boardProject)" />
        <Btn tone="ghost" size="sm" :label="{ ar: 'عرض كقائمة', en: 'Show as a list' }" @click="go({ view: 'list', project: boardProject })" />
      </template>
    </div>
    <TaskBoard v-if="boardProject" :project-id="boardProject" :work="work.data.value" @add="newTask" />
    <EmptyState v-else tone="dashed" :text="projects.length ? { ar: 'اختر مشروعًا لعرض لوحته.', en: 'Pick a project to see its board.' } : { ar: 'لا مشاريع تراها بعد.', en: 'There is no project you can see yet.' }" />
  </template>
  <TaskList v-else :work="work.data.value" />

  <TaskDrawer :number="qs.get('task')" :work="work.data.value" :lookups="lookups.data.value" @close="qs.close('task')" @edit="(task) => (taskForm = { task, preset: null })" @board="toBoard" />
  <ProjectDrawer :project-id="qs.get('prj')" :lookups="lookups.data.value" @close="qs.close('prj')" @edit="(project) => (projectForm = { project })" @board="toBoard" />
  <TaskForm :open="!!taskForm" :task="taskForm ? taskForm.task : null" :preset="taskForm ? taskForm.preset : null" :work="work.data.value" :lookups="lookups.data.value" @close="taskForm = null" @done="onTaskSaved" />
  <ProjectForm :open="!!projectForm" :project="projectForm ? projectForm.project : null" :lookups="lookups.data.value" @close="projectForm = null" @done="onProjectSaved" />
  <TagsManager :open="tagsOpen" :tags="work.data.value?.tags" @close="tagsOpen = false" />
</template>
