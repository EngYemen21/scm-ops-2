<script setup>
// A task as a card of a project's board: its title and number, how urgent it is, its deadline (in red once it is
// late), who it is given to, its tags and how far its checklist got. A click opens the task; the small menu moves it
// to another stage of the project (no dragging: the same step works on a phone and with a keyboard).
//   <BoardCard :card="task" :stages="otherStages" movable @open="(number) => …" @move="(stageId) => …" />
import { computed } from 'vue';
import { Chip } from '@/components';
import { fmtNum, nm, t } from '@/i18n';
import OdooMark from './OdooMark.vue';
import TagChips from './TagChips.vue';
import { PRIORITY, TASK_STATUS, arr, deadlineInfo, initials, personName } from './workspace';

const props = defineProps({
  /** TaskCard */
  card: { type: Object, required: true },
  /** The stages the card may be moved to. */
  stages: { type: Array, default: () => [] },
  movable: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['open', 'move']);

const open = computed(() => props.card.status === 'open');
const deadline = computed(() => deadlineInfo(props.card.deadline, !open.value));
const late = computed(() => open.value && (props.card.overdue || !!deadline.value?.late));
const people = computed(() => arr(props.card.assignees));
const list = computed(() => props.card.checklist || { done: 0, total: 0 });
function onMove(e) {
  const stageId = e.target.value;
  e.target.value = '';
  if (stageId) emit('move', stageId);
}
</script>

<template>
  <div class="cursor-pointer rounded-xl border border-line border-s-[3px] bg-white px-3 py-2.5 hover:bg-[#FCFBFE]" :class="late ? 'border-s-bad' : open ? 'border-s-brand' : 'border-s-line'"
       role="button" tabindex="0" :aria-label="card.title" @click="emit('open', card.number)" @keydown.enter.self="emit('open', card.number)">
    <div class="flex items-start gap-2">
      <div class="min-w-0 flex-1 break-words text-[11.5px] font-extrabold leading-[1.6]" :class="open ? 'text-ink' : 'text-muted line-through'" dir="auto">{{ card.title }}</div>
      <Chip v-if="card.priority > 0" :map="PRIORITY" :k="String(card.priority)" small />
    </div>
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[9.5px] text-muted">
      <span class="cell-id !text-[9.5px]">{{ card.number }}</span>
      <Chip v-if="!open" :map="TASK_STATUS" :k="card.status" small />
      <span v-if="deadline" class="font-extrabold" :class="late ? 'text-bad' : deadline.today ? 'text-warn' : 'text-sec'">{{ deadline.text }}</span>
      <span v-if="list.total" class="num" :title="t('قائمة التحقق', 'Checklist')">✓ {{ fmtNum(list.done) }}/{{ fmtNum(list.total) }}</span>
      <span v-if="card.commentCount" class="num">{{ fmtNum(card.commentCount) }} {{ t('تعليق', 'comments') }}</span>
      <OdooMark :row="card" />
    </div>
    <TagChips v-if="arr(card.tags).length" :tags="card.tags" class="mt-1.5" />
    <div class="mt-2 flex items-center gap-1.5">
      <span v-for="p in people.slice(0, 3)" :key="p.key" class="flex h-[22px] w-[22px] flex-none items-center justify-center rounded-full bg-violet-soft text-[8.5px] font-extrabold text-violet" :title="personName(p)">{{ initials(p) }}</span>
      <span v-if="people.length > 3" class="num text-[9px] text-muted">+{{ people.length - 3 }}</span>
      <span v-if="!people.length" class="text-[9.5px] text-faint">{{ t('غير مسندة', 'Unassigned') }}</span>
      <span class="grow" />
      <select v-if="movable && stages.length" class="inp sm !h-7 !w-auto max-w-[130px] !px-1.5 !text-[9.5px]" :disabled="busy" :aria-label="t('نقل المهمة إلى مرحلة', 'Move the task to a stage')" @click.stop @keydown.stop @change="onMove">
        <option value="">{{ t('نقل إلى…', 'Move to…') }}</option>
        <option v-for="s in stages" :key="s.id" :value="s.id">{{ nm(s) }}</option>
      </select>
    </div>
  </div>
</template>
