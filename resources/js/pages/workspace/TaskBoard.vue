<script setup>
// The board of one project (GET /workspace/projects/{id}/board): one column per stage, the open and the finished
// tasks as cards, the late ones marked. A card moves to another stage through its own small menu
// (POST /workspace/tasks/{id}/move) — entering a closing stage finishes the task. The filters of the address
// (q, assignee, tag, priority) narrow every column. On a phone the columns stand under each other; on a wide screen
// they scroll sideways inside their own strip, never the page.
//   <TaskBoard :project-id="qs.get('project')" :work="work" @add="(stageId) => …" />
import { computed } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import BoardColumn from './BoardColumn.vue';
import { API, INVALIDATE, PRIORITY_OPTIONS, arr, personName, useSearchBox, useWsQuery } from './workspace';

const props = defineProps({
  projectId: { type: String, default: '' },
  /** GET tasks/lookups: the tags and the people tasks are given to (the filters). */
  work: { type: Object, default: null },
});
const emit = defineEmits(['add']);

const auth = useAuth();
const qs = useWsQuery();
const search = useSearchBox(qs);
const filters = computed(() => ({ q: qs.get('q') || undefined, assignee: qs.get('assignee') || undefined, tag: qs.get('tag') || undefined, priority: qs.get('priority') || undefined }));
const filtered = computed(() => Object.values(filters.value).some((v) => v !== undefined));
const board = useList(() => (props.projectId ? `${API}/projects/${encodeURIComponent(props.projectId)}/board` : null), () => ({ ...filters.value, per: 20 }));
const data = computed(() => (props.projectId ? board.data.value || null : null));
const columns = computed(() => arr(data.value?.stages));
const loose = computed(() => data.value?.unstaged || null);
const stages = computed(() => columns.value.filter((s) => s.active !== false));
const canWork = computed(() => !!data.value?.project?.canWork);

const assigneeOptions = computed(() => [
  { v: 'me', l: { ar: 'أنا', en: 'Me' } }, { v: 'none', l: { ar: 'غير مسندة', en: 'Unassigned' } },
  ...arr(props.work?.assignees).filter((p) => p.key && p.id !== auth.user?.id).map((p) => ({ v: String(p.key), l: p.external ? `${personName(p)} · Odoo` : personName(p) })),
]);
const tagOptions = computed(() => arr(props.work?.tags).filter((x) => x.active !== false || x.id === qs.get('tag')).map((x) => ({ v: x.id, l: String(x.name ?? '') })));

const act = useAction();
const move = (card, stageId) => act.run(() => api.postIdempotent(`${API}/tasks/${card.id}/move`, { stageId }), { success: t('نُقلت المهمة', 'Task moved'), invalidate: INVALIDATE });
/** The rest of a column that does not fit the board: the list view of the same project (and stage). */
const toList = (stageId) => qs.replace({ tab: 'tasks', view: 'list', project: props.projectId, state: 'board', stage: stageId || undefined });
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث في مهام المشروع…', en: 'Search the project tasks…' }" />
    <SelectInput small class="w-[160px]" :model-value="qs.get('assignee')" :options="assigneeOptions" :placeholder="{ ar: 'كل المسؤولين', en: 'Every assignee' }" :aria-label="t('المسؤول', 'Assignee')" @update:model-value="(v) => qs.patch({ assignee: v })" />
    <SelectInput small class="w-[140px]" :model-value="qs.get('tag')" :options="tagOptions" :placeholder="{ ar: 'كل الوسوم', en: 'Every tag' }" :aria-label="t('الوسم', 'Tag')" @update:model-value="(v) => qs.patch({ tag: v })" />
    <SelectInput small class="w-[130px]" :model-value="qs.get('priority')" :options="PRIORITY_OPTIONS" :placeholder="{ ar: 'كل الأولويات', en: 'Every priority' }" :aria-label="t('الأولوية', 'Priority')" @update:model-value="(v) => qs.patch({ priority: v })" />
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="qs.patch({ q: '', assignee: '', tag: '', priority: '' })" />
  </div>
  <ErrorBanner :error="board.error.value" :closable="false" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />

  <div v-if="!data && board.isLoading.value" class="flex flex-col gap-3 md:flex-row">
    <div v-for="i in 3" :key="i" class="skel h-[220px] md:w-[292px] md:flex-none" />
  </div>
  <EmptyState v-else-if="data && !columns.length && !loose?.count" tone="dashed" :text="{ ar: 'لا مراحل لهذا المشروع بعد — أضف مراحله من «تفاصيل المشروع».', en: 'This project has no stages yet — add them from “Project details”.' }" />
  <div v-else-if="data" class="flex flex-col gap-3 md:flex-row md:items-start md:overflow-x-auto md:pb-2" :style="{ opacity: board.isFetching.value && board.isPlaceholderData.value ? 0.6 : 1 }">
    <BoardColumn v-for="column in columns" :key="column.id" :column="column" :project-id="projectId" :filters="filters" :stages="stages" :can-work="canWork" :busy="act.pending.value"
                 @open="(number) => qs.open('task', number)" @move="move" @add="(stageId) => emit('add', stageId)" @list="toList" />
    <BoardColumn v-if="loose && loose.count" :column="loose" :project-id="projectId" :filters="filters" :stages="stages" :can-work="canWork" unstaged :busy="act.pending.value"
                 @open="(number) => qs.open('task', number)" @move="move" @list="toList" />
  </div>
</template>
