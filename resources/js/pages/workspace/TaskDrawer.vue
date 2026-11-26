<script setup>
// One task (deep link `task=<number>`): what is to be done, in which project and stage, who it is given to, its
// deadline, its checklist, its files and its comments — and what may be done to it now. The buttons follow the
// task's own `can.*` flags (the server decides; a task Odoo owns only takes comments):
//   POST /workspace/tasks/{id}/move { stageId } · done · reopen · cancel { reason } · archive { reason? } · restore ·
//   checklist { text } · PATCH checklist/{item} { text?, done? } · POST checklist/{item}/remove.
//   <TaskDrawer :number="qs.get('task')" :work="work" :lookups="lookups" @close @edit="(task) => …" @board="(projectId) => …" />
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, ProgressBar, TextInput } from '@/components';
import { fmtDate, fmtNum, nm, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import Conversation from './Conversation.vue';
import FileList from './FileList.vue';
import OdooMark from './OdooMark.vue';
import PersonName from './PersonName.vue';
import TagChips from './TagChips.vue';
import { API, ARCHIVED_CHIP, INVALIDATE, OWNED_NOTE, PRIORITY, TASK_STATUS, TONE, arr, deadlineInfo, refLabel, refPath } from './workspace';

const props = defineProps({
  /** Number (or id) of the task on screen; empty = closed. */
  number: { type: String, default: '' },
  /** GET tasks/lookups: the stages of the task's project. */
  work: { type: Object, default: null },
  /** GET lookups: the rules of an upload. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'edit', 'board']);

const detail = useGet(() => (props.number ? `${API}/tasks/${encodeURIComponent(props.number)}` : null));
const d = computed(() => (props.number ? detail.data.value || null : null));
const can = computed(() => d.value?.can || {});
const open = computed(() => d.value?.status === 'open');
const anyAction = computed(() => ['edit', 'move', 'done', 'reopen', 'cancel', 'archive', 'restore'].some((k) => can.value[k]));
const deadline = computed(() => deadlineInfo(d.value?.deadline, !open.value));
const LATE = { ar: 'متأخرة', en: 'Overdue', fg: '#fff', bg: '#b23b3b' };
const stages = computed(() => arr(arr(props.work?.projects).find((p) => p.id === d.value?.project?.id)?.stages).filter((s) => s.active !== false && s.id !== d.value?.stage?.id));

// ---- what is done to it
const act = useAction();
const url = (step) => `${API}/tasks/${d.value.id}/${step}`;
const post = (step, body, success) => act.run(() => api.postIdempotent(url(step), body), { success, invalidate: INVALIDATE });
function onMove(e) {
  const stageId = e.target.value;
  e.target.value = '';
  if (stageId) post('move', { stageId }, t('نُقلت المهمة', 'Task moved'));
}
const finish = () => post('done', undefined, t('أُنجزت المهمة', 'Task done'));
const reopen = () => post('reopen', undefined, t('أُعيد فتح المهمة', 'Task reopened'));
async function cancel() {
  const reason = await ask({ title: { ar: `إلغاء المهمة ${d.value.number}؟`, en: `Cancel task ${d.value.number}?` }, sub: { ar: 'تبقى المهمة في السجل ملغاة، ويمكن إعادة فتحها.', en: 'The task stays on record as cancelled and can be reopened.' },
    label: { ar: 'سبب الإلغاء', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء المهمة', en: 'Cancel the task' }, cancelLabel: { ar: 'رجوع', en: 'Back' } });
  if (reason === null) return;
  await post('cancel', { reason }, t('أُلغيت المهمة', 'Task cancelled'));
}
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة المهمة ${d.value.number}؟`, en: `Archive task ${d.value.number}?` }, sub: { ar: 'تخرج من القوائم واللوحة وتبقى بسجلها؛ تُستعاد متى شئت.', en: 'It leaves the lists and the board and keeps its history; it can be restored at any time.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } });
  if (reason === null) return;
  await post('archive', reason ? { reason } : {}, t('أُرشفت المهمة', 'Task archived'));
}
async function restore() {
  if (!(await confirm({ title: { ar: `استعادة المهمة ${d.value.number} من الأرشيف؟`, en: `Restore task ${d.value.number} from the archive?` }, tone: 'dark' }))) return;
  await post('restore', undefined, t('استُعيدت المهمة', 'Task restored'));
}

// ---- the checklist
const items = computed(() => arr(d.value?.checklistItems));
const ticked = computed(() => items.value.filter((x) => x.done).length);
const newItem = ref('');
watch(() => props.number, () => { newItem.value = ''; act.clearError(); });
async function tick(item, e) {
  const done = await act.run(() => api.patch(url(`checklist/${item.id}`), { done: !!e.target.checked }), { success: false, invalidate: INVALIDATE });
  if (!done) e.target.checked = !!item.done; // refused: the box shows what the server holds
}
async function rename(item) {
  const text = await ask({ title: { ar: 'تعديل بند قائمة التحقق', en: 'Edit the checklist line' }, label: { ar: 'النص', en: 'Text' }, value: item.text, required: true, tone: 'dark', okLabel: { ar: 'حفظ', en: 'Save' } });
  if (text === null || text === item.text) return;
  await act.run(() => api.patch(url(`checklist/${item.id}`), { text }), { success: t('حُفظ البند', 'Line saved'), invalidate: INVALIDATE });
}
async function removeItem(item) {
  if (!(await confirm({ title: { ar: 'حذف هذا البند من قائمة التحقق؟', en: 'Remove this line from the checklist?' }, sub: item.text, tone: 'danger', okLabel: { ar: 'حذف البند', en: 'Remove' } }))) return;
  await post(`checklist/${item.id}/remove`, undefined, t('حُذف البند', 'Line removed'));
}
async function addItem() {
  const text = newItem.value.trim();
  if (!text || act.pending.value) return;
  const done = await post('checklist', { text }, false);
  if (done) newItem.value = '';
}
</script>

<template>
  <Drawer :open="!!number" :width="660" :sub="d ? d.title : null" @close="emit('close')">
    <template #title><span class="num ltr inline-block text-violet">{{ d ? d.number : number }}</span></template>

    <ErrorBanner :error="detail.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!d && !detail.error.value" class="skel h-[180px]" />
    <template v-else-if="d">
      <div v-if="d.owned" class="banner teal">{{ t(OWNED_NOTE.ar, OWNED_NOTE.en) }}</div>

      <div class="row wrap mb-3">
        <Chip :map="TASK_STATUS" :k="d.status" />
        <Chip v-if="d.stage" :fg="TONE.neutral.fg" :bg="TONE.neutral.bg">{{ nm(d.stage) }}</Chip>
        <Chip :map="PRIORITY" :k="String(d.priority ?? 0)" />
        <Chip v-if="open && d.overdue" :label="LATE" :fg="LATE.fg" :bg="LATE.bg" />
        <Chip v-if="d.archived" :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <OdooMark :row="d" />
      </div>

      <!-- what may be done now -->
      <div v-if="anyAction" class="tile mb-3.5 row wrap">
        <Btn v-if="can.done" tone="success" size="sm" :loading="act.pending.value" :label="{ ar: 'إنجاز المهمة ✓', en: 'Mark done ✓' }" @click="finish" />
        <select v-if="can.move && stages.length" class="inp sm !w-auto max-w-[220px]" :disabled="act.pending.value" :aria-label="t('نقل إلى مرحلة', 'Move to a stage')" @change="onMove">
          <option value="">{{ t('نقل إلى مرحلة…', 'Move to stage…') }}</option>
          <option v-for="s in stages" :key="s.id" :value="s.id">{{ nm(s) }}{{ s.isClosing ? ' ✓' : '' }}</option>
        </select>
        <Btn v-if="can.reopen" tone="softAmber" size="sm" :loading="act.pending.value" :label="{ ar: 'إعادة الفتح', en: 'Reopen' }" @click="reopen" />
        <span class="grow" />
        <Btn v-if="can.edit" tone="outline" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', d)" />
        <Btn v-if="can.cancel" tone="dangerOutline" size="sm" :disabled="act.pending.value" :label="{ ar: 'إلغاء المهمة', en: 'Cancel task' }" @click="cancel" />
        <Btn v-if="can.archive" tone="ghost" size="sm" class="!text-bad" :disabled="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
        <Btn v-if="can.restore" tone="softBlue" size="sm" :loading="act.pending.value" :label="{ ar: 'استعادة من الأرشيف', en: 'Restore' }" @click="restore" />
      </div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'المشروع', en: 'Project' }">
          <button v-if="d.project" type="button" class="cursor-pointer border-0 bg-transparent p-0 text-start font-extrabold text-brand-dark" @click="emit('board', d.project.id)"><span dir="auto">{{ d.project.name }}</span></button>
          <span v-else class="text-sec">{{ t('مهمة شخصية — يراها منشئها ومن أُسندت إليه', 'A personal task — seen by its creator and its assignees') }}</span>
        </KV>
        <KV :k="{ ar: 'المسؤولون', en: 'Assignees' }">
          <div v-if="arr(d.assignees).length" class="flex flex-col gap-0.5"><PersonName v-for="p in d.assignees" :key="p.key" :person="p" /></div>
          <span v-else class="text-faint">{{ t('غير مسندة', 'Unassigned') }}</span>
        </KV>
        <KV :k="{ ar: 'الموعد النهائي', en: 'Deadline' }">
          <span v-if="deadline" class="font-extrabold" :class="open && (d.overdue || deadline.late) ? 'text-bad' : deadline.today ? 'text-warn' : ''">{{ deadline.text }}</span>
          <span v-else class="text-faint">{{ t('بلا موعد', 'No deadline') }}</span>
        </KV>
        <KV :k="{ ar: 'أُنشئت', en: 'Created' }"><span class="num ltr inline-block">{{ fmtDate(d.createdAt) }}</span><span v-if="d.createdBy" class="text-muted"> · {{ d.createdBy }}</span></KV>
        <KV v-if="d.doneAt" :k="{ ar: 'أُنجزت', en: 'Done' }"><span class="num ltr inline-block">{{ fmtDate(d.doneAt) }}</span><span v-if="d.doneBy" class="text-muted"> · {{ d.doneBy }}</span></KV>
        <KV v-if="d.ref" :k="{ ar: 'سجل مرتبط', en: 'Linked record' }">
          <RouterLink v-if="refPath(d.ref)" :to="refPath(d.ref)" class="font-extrabold">{{ refLabel(d.ref) }}</RouterLink>
          <span v-else>{{ refLabel(d.ref) }}</span>
        </KV>
        <KV v-if="arr(d.tags).length" :k="{ ar: 'الوسوم', en: 'Tags' }"><TagChips :tags="d.tags" /></KV>
      </div>

      <div v-if="d.status === 'cancelled' && d.cancelReason" class="hint amber !mt-0 mb-4"><span class="font-extrabold">{{ t('سبب الإلغاء', 'Why it was cancelled') }}:</span> <span dir="auto">{{ d.cancelReason }}</span></div>
      <div v-if="d.archived" class="hint amber !mt-0 mb-4">
        {{ t('مؤرشفة', 'Archived') }}<span v-if="d.archivedAt"> · <span class="num ltr inline-block">{{ fmtDate(d.archivedAt) }}</span></span><span v-if="d.archivedBy"> · {{ d.archivedBy }}</span>
        <div v-if="d.archiveReason" dir="auto">{{ d.archiveReason }}</div>
      </div>

      <div class="card-title mb-1">{{ t('الوصف', 'Description') }}</div>
      <div v-if="d.description" class="mb-4 whitespace-pre-wrap break-words text-[11.5px] leading-[1.9] text-ink [text-align:match-parent]" dir="auto">{{ d.description }}</div>
      <div v-else class="empty !p-3.5">{{ t('لا وصف.', 'No description.') }}</div>

      <template v-if="items.length || can.checklist">
        <div class="card-title mb-1.5">{{ t('قائمة التحقق', 'Checklist') }}<span v-if="items.length" class="card-count num ms-2">{{ fmtNum(ticked) }}/{{ fmtNum(items.length) }}</span></div>
        <ProgressBar v-if="items.length" :pct="(ticked / items.length) * 100" :height="6" class="mb-1.5" />
        <div v-for="item in items" :key="item.id" class="flex items-start gap-2 border-t border-line-2 py-1.5 first:border-t-0">
          <input type="checkbox" class="mt-[3px] h-[15px] w-[15px] flex-none cursor-pointer accent-violet" :checked="item.done" :disabled="!can.checklist || act.pending.value" :aria-label="item.text" @change="tick(item, $event)">
          <div class="min-w-0 flex-1">
            <div class="break-words text-[11px] leading-[1.7]" :class="item.done ? 'text-muted line-through' : 'font-bold text-ink'" dir="auto">{{ item.text }}</div>
            <div v-if="item.done && (item.doneBy || item.doneAt)" class="cell-date" style="direction: inherit"><span v-if="item.doneBy" class="font-sans">{{ item.doneBy }} · </span><span class="ltr inline-block">{{ fmtDate(item.doneAt) }}</span></div>
          </div>
          <template v-if="can.checklist">
            <Btn tone="ghost" size="sm" :disabled="act.pending.value" :label="{ ar: 'تعديل', en: 'Edit' }" @click="rename(item)" />
            <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :disabled="act.pending.value" :aria-label="t('حذف البند', 'Remove the line')" @click="removeItem(item)">✕</button>
          </template>
        </div>
        <div v-if="!items.length" class="text-[10.5px] text-faint">{{ t('لا بنود بعد.', 'No lines yet.') }}</div>
        <div v-if="can.checklist" class="row mt-2">
          <TextInput v-model="newItem" small field-class="min-w-0 flex-1" :placeholder="{ ar: 'بند جديد…', en: 'A new line…' }" maxlength="500" @enter="addItem" />
          <Btn tone="soft" size="sm" :loading="act.pending.value" :disabled="!newItem.trim()" :label="{ ar: 'إضافة', en: 'Add' }" @click="addItem" />
        </div>
        <div class="mb-4" />
      </template>

      <template v-if="arr(d.files).length">
        <div class="card-title mb-1.5">{{ t('ملفات المهمة', 'Files of the task') }}</div>
        <FileList :files="d.files" class="mb-4" />
      </template>

      <Conversation thread="task" :thread-id="d.id" :messages="d.comments" :can-comment="!!can.comment" :lookups="lookups" />
    </template>
  </Drawer>
</template>
