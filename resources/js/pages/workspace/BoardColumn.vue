<script setup>
// One column of a project's board: a stage with its count and its first cards, as GET projects/{id}/board gives
// them. «عرض الباقي» reads the rest of the column through GET /workspace/tasks?project=&stage=&state=board (the
// board's own filters included). The tasks that stand in no stage form a column of their own (`unstaged`); what does
// not fit there is read in the list view.
//   <BoardColumn :column="stage" :project-id="id" :filters="filters" :stages="stages" can-work @open @move @add @list />
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { fmtNum, nm, t } from '@/i18n';
import BoardCard from './BoardCard.vue';
import { API, TONE, arr } from './workspace';

const props = defineProps({
  /** TaskStage + { count, items, more } — or the `unstaged` block { count, items, more }. */
  column: { type: Object, required: true },
  projectId: { type: String, required: true },
  /** q / assignee / tag / priority of the board on screen. */
  filters: { type: Object, default: null },
  /** Every active stage of the project: where a card may go. */
  stages: { type: Array, default: () => [] },
  canWork: { type: Boolean, default: false },
  unstaged: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['open', 'move', 'add', 'list']);

/** The most cards one column asks for; a column that holds more sends the reader to the list. */
const REST = 200;
const expanded = ref(false);
// (a text key: the column object is a new one after every refresh, and that alone must not fold it again)
watch(() => `${props.projectId}:${props.column.id || ''}`, () => { expanded.value = false; });
const rest = useList(`${API}/tasks`, () => ({ ...(props.filters || {}), project: props.projectId, stage: props.column.id, state: 'board', sort: 'priority', order: 'desc', pageSize: REST }),
  { enabled: () => expanded.value && !props.unstaged && !!props.column.id });
const whole = computed(() => (expanded.value && rest.data.value ? rest.data.value : null));
const cards = computed(() => arr(whole.value ? whole.value.items : props.column.items));
const total = computed(() => (whole.value ? whole.value.total : props.column.count) || 0);
const hidden = computed(() => Math.max(0, total.value - cards.value.length));
const CLOSING = { ar: 'إنجاز', en: 'Done stage', ...TONE.ok };

const targets = (card) => props.stages.filter((s) => s.id !== card.stage?.id);
const movable = (card) => props.canWork && !card.owned && !card.archived && card.status !== 'cancelled';
</script>

<template>
  <section class="flex min-w-0 flex-col rounded-2xl border border-line bg-soft md:w-[292px] md:flex-none">
    <header class="flex items-center gap-2 px-3 pb-2 pt-3">
      <div class="min-w-0 flex-1 truncate text-[12px] font-extrabold" dir="auto">{{ unstaged ? t('بلا مرحلة', 'No stage') : nm(column) }}</div>
      <Chip v-if="!unstaged && column.isClosing" small :label="CLOSING" :fg="CLOSING.fg" :bg="CLOSING.bg" />
      <span class="badge soft">{{ fmtNum(total) }}</span>
      <button v-if="canWork && !unstaged && !column.isClosing && column.active !== false" type="button" class="x-btn !h-7 !w-7 !text-[13px]" :title="t('مهمة جديدة في هذه المرحلة', 'New task in this stage')" :aria-label="t('مهمة جديدة في هذه المرحلة', 'New task in this stage')" @click="emit('add', column.id)">+</button>
    </header>
    <div class="flex flex-col gap-2 px-2.5 pb-2.5">
      <ErrorBanner :error="rest.error.value" :closable="false" class="!mb-0" />
      <BoardCard v-for="card in cards" :key="card.id" :card="card" :stages="targets(card)" :movable="movable(card)" :busy="busy" @open="emit('open', $event)" @move="emit('move', card, $event)" />
      <div v-if="!cards.length" class="rounded-xl border border-dashed border-line px-3 py-4 text-center text-[10.5px] text-faint">{{ t('لا مهام هنا.', 'No tasks here.') }}</div>
      <template v-if="hidden > 0">
        <Btn v-if="!unstaged && !expanded" tone="soft" size="sm" block :label="{ ar: `عرض الباقي (${fmtNum(hidden)})`, en: `Show the rest (${fmtNum(hidden)})` }" @click="expanded = true" />
        <Btn v-else-if="!unstaged && rest.isFetching.value" tone="soft" size="sm" block loading />
        <Btn v-else tone="ghost" size="sm" block :label="{ ar: `${fmtNum(hidden)} مهمة أخرى — افتحها في القائمة`, en: `${fmtNum(hidden)} more — open them in the list` }" @click="emit('list', unstaged ? '' : column.id)" />
      </template>
    </div>
  </section>
</template>
