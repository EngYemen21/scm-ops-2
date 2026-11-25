<script setup>
// A task is created (POST /workspace/tasks) or corrected (PATCH /workspace/tasks/{id}). A new task goes into a
// project the caller works in — into one of its stages — or stays personal: seen by whoever made it and whoever it is
// given to. Its project and stage are not part of an edit (a task moves through its board); the checklist of an
// existing task is kept from the task itself.
//   <TaskForm :open :task="editing" :preset="{ projectId, stageId }" :work="work" :lookups="lookups" @close @done="(task) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FilePicker from './FilePicker.vue';
import PeoplePicker from './PeoplePicker.vue';
import RecordLinkField from './RecordLinkField.vue';
import TagPicker from './TagPicker.vue';
import WsFormDrawer from './WsFormDrawer.vue';
import { API, INVALIDATE, PRIORITY_OPTIONS, arr, personName, toUploads } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The task being edited (the answer of GET tasks/{id}); null = a new task. */
  task: { type: Object, default: null },
  /** Where a new task starts: { projectId, stageId } (the board it was asked from). */
  preset: { type: Object, default: null },
  /** GET tasks/lookups: projects with their stages, tags. */
  work: { type: Object, default: null },
  /** GET lookups: users, record kinds, the rules of an upload. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction();
const editing = computed(() => !!props.task);

/** The stage a new task of this project starts in: its first open one. */
function firstStage(projectId) {
  const list = arr(arr(props.work?.projects).find((p) => p.id === projectId)?.stages).filter((s) => s.active !== false);
  return (list.find((s) => !s.isClosing) || list[0])?.id || '';
}
function blank() {
  const projectId = props.preset?.projectId || '';
  return { title: '', description: '', projectId, stageId: props.preset?.stageId || (projectId ? firstStage(projectId) : ''), assigneeKeys: auth.user?.id ? [String(auth.user.id)] : [],
    deadline: '', priority: '0', tagIds: [], checklist: '', refType: '', refNumber: '', files: [] };
}
function fromTask(x) {
  return { title: x.title || '', description: x.description || '', projectId: x.project?.id || '', stageId: x.stage?.id || '', assigneeKeys: arr(x.assignees).map((p) => String(p.key)),
    deadline: x.deadline || '', priority: String(x.priority ?? 0), tagIds: arr(x.tags).map((tag) => String(tag.id)), checklist: '', refType: x.ref?.type || '', refNumber: x.ref?.number || '', files: [] };
}
const f = ref(props.task ? fromTask(props.task) : blank());
/** The picker is still reading files from the disk. */
const picking = ref(false);
watch(() => props.open, (now) => {
  if (!now) return;
  f.value = props.task ? fromTask(props.task) : blank();
  act.clearError();
});

// ---- where a new task goes
const projects = computed(() => arr(props.work?.projects).filter((p) => (p.canWork && !p.archived && p.status !== 'done') || p.id === f.value.projectId));
const projectOptions = computed(() => projects.value.map((p) => ({ v: p.id, l: `${p.name ?? ''} · ${p.number ?? ''}` })));
const project = computed(() => arr(props.work?.projects).find((p) => p.id === f.value.projectId) || null);
const stages = computed(() => arr(project.value?.stages).filter((s) => s.active !== false || s.id === f.value.stageId));
const stageOptions = computed(() => stages.value.map((s) => ({ v: s.id, l: s.isClosing ? `${personName(s)} ✓` : personName(s) })));
/** A project was picked: the task starts in its first open stage. */
function onProject(id) {
  f.value.projectId = id;
  f.value.stageId = id ? firstStage(id) : '';
}
// the projects arrive after the form opened from a board: fill the first stage in
watch(() => props.work, () => { if (props.open && !props.task && f.value.projectId && !f.value.stageId) f.value.stageId = firstStage(f.value.projectId); });

const tags = computed(() => arr(props.work?.tags));
const lines = computed(() => f.value.checklist.split(/\r?\n/).map((s) => s.trim()).filter(Boolean));
const halfLink = computed(() => !!f.value.refType !== !!f.value.refNumber.trim());
const missing = computed(() => {
  if (!f.value.title.trim()) return { ar: 'اكتب عنوان المهمة.', en: 'Give the task a title.' };
  if (halfLink.value) return { ar: 'أكمل ربط السجل (النوع والرقم معًا) أو أفرغه.', en: 'Complete the record link (kind and number together) or clear it.' };
  if (picking.value) return { ar: 'جارٍ قراءة الملفات…', en: 'Reading the files…' };
  return null;
});

async function save() {
  if (missing.value) return;
  const v = f.value;
  let done;
  if (editing.value) {
    // an edit says everything the form holds: what was emptied is cleared
    const body = { title: v.title.trim(), description: v.description.trim() || null, assigneeKeys: v.assigneeKeys, deadline: v.deadline || null, priority: Number(v.priority) || 0,
      tagIds: v.tagIds, refType: v.refType || null, refNumber: v.refType ? v.refNumber.trim() : null };
    done = await act.run(() => api.patch(`${API}/tasks/${props.task.id}`, body), { success: t('حُفظت المهمة', 'Task saved'), invalidate: INVALIDATE });
  } else {
    const body = { title: v.title.trim(), description: v.description.trim() || undefined, projectId: v.projectId || undefined, stageId: v.projectId ? v.stageId || undefined : undefined,
      assigneeKeys: v.assigneeKeys, deadline: v.deadline || undefined, priority: Number(v.priority) || 0, tagIds: v.tagIds, ...(lines.value.length ? { checklist: lines.value } : {}),
      refType: v.refType || undefined, refNumber: v.refType ? v.refNumber.trim() : undefined, ...(v.files.length ? { files: toUploads(v.files) } : {}) };
    done = await act.run(() => api.postIdempotent(`${API}/tasks`, body), { success: (x) => t(`أُنشئت المهمة ${x?.number || ''}`, `Task ${x?.number || ''} created`), invalidate: INVALIDATE });
  }
  if (done) { emit('done', done); emit('close'); }
}
</script>

<template>
  <WsFormDrawer :open="open" :title="editing ? { ar: `تعديل المهمة ${task.number}`, en: `Edit task ${task.number}` } : { ar: 'مهمة جديدة', en: 'New task' }"
                :submit-label="editing ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إنشاء المهمة', en: 'Create the task' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!!missing" :why="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.title" :label="{ ar: 'العنوان', en: 'Title' }" required full maxlength="250" />
      <template v-if="!editing">
        <SelectInput :model-value="f.projectId" :label="{ ar: 'المشروع', en: 'Project' }" :options="projectOptions" :placeholder="{ ar: 'مهمة شخصية (بلا مشروع)', en: 'A personal task (no project)' }" @update:model-value="onProject"
                     :hint="f.projectId ? null : { ar: 'المهمة الشخصية يراها منشئها ومن أُسندت إليه فقط.', en: 'A personal task is seen by its creator and its assignees only.' }" />
        <SelectInput v-if="f.projectId" v-model="f.stageId" :label="{ ar: 'المرحلة', en: 'Stage' }" :options="stageOptions" :placeholder="{ ar: '— أول مرحلة —', en: '— first stage —' }" />
      </template>
      <div v-else class="field full"><div class="field-hint">{{ task.project ? t(`المشروع: ${task.project.name} — تنتقل المهمة بين المراحل من لوحتها.`, `Project: ${task.project.name} — a task moves between stages from its board.`) : t('مهمة شخصية (بلا مشروع).', 'A personal task (no project).') }}</div></div>
      <PeoplePicker v-model="f.assigneeKeys" :users="lookups?.users" :known="task?.assignees" :label="{ ar: 'المسؤولون', en: 'Assignees' }" :empty-text="{ ar: 'غير مسندة لأحد.', en: 'Not given to anybody.' }" />
      <DateInput v-model="f.deadline" :label="{ ar: 'الموعد النهائي', en: 'Deadline' }" />
      <PillChoice v-model="f.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITY_OPTIONS" />
      <TagPicker v-model="f.tagIds" :tags="tags" :label="{ ar: 'الوسوم', en: 'Tags' }" />
      <TextArea v-model="f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="4" maxlength="20000" />
      <TextArea v-if="!editing" v-model="f.checklist" :label="{ ar: 'قائمة التحقق', en: 'Checklist' }" :rows="3" :placeholder="{ ar: 'سطر لكل بند…', en: 'One line per item…' }"
                :hint="lines.length ? { ar: `${lines.length} بند`, en: `${lines.length} item(s)` } : { ar: 'اختياري — يمكن إضافة البنود لاحقًا من المهمة نفسها.', en: 'Optional — lines can be added later from the task itself.' }" />
      <RecordLinkField v-model:type="f.refType" v-model:number="f.refNumber" :types="lookups?.recordTypes" />
      <FilePicker v-if="!editing" v-model="f.files" :lookups="lookups" :label="{ ar: 'مرفقات', en: 'Attachments' }" @busy="picking = $event" />
    </div>
  </WsFormDrawer>
</template>
