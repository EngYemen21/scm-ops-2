<script setup>
// One project (`prj=<id>`): who manages it, who works its tasks, its dates, how its tasks stand — and what its
// manager (or whoever holds project.manage) keeps here, each part shown only where the project's `can.*` says so:
//   status   POST /workspace/projects/{id}/status { status, note? }        active ↔ on_hold → done, done → active
//   members  PUT  /workspace/projects/{id}/members { memberKeys }
//   stages   POST /workspace/projects/{id}/stages · PATCH /workspace/task-stages/{id} (name, order, closing flag, active)
//   archive  POST /workspace/projects/{id}/archive { reason? } · restore
// Its other details are corrected in the form (`edit`).
//   <ProjectDrawer :project-id="qs.get('prj')" :lookups="lookups" @close @edit="(project) => …" @board="(id) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, NumberInput, ProgressBar, TextInput } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import OdooMark from './OdooMark.vue';
import PeoplePicker from './PeoplePicker.vue';
import PersonName from './PersonName.vue';
import { API, ARCHIVED_CHIP, INVALIDATE, OWNED_NOTE, PROJECT_STATUS, TONE, VISIBILITY, arr, personName } from './workspace';

const props = defineProps({
  /** Id (or number) of the project on screen; empty = closed. */
  projectId: { type: String, default: '' },
  /** GET lookups: the users a project is made of. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'edit', 'board']);

const detail = useGet(() => (props.projectId ? `${API}/projects/${encodeURIComponent(props.projectId)}` : null));
const d = computed(() => (props.projectId ? detail.data.value || null : null));
const can = computed(() => d.value?.can || {});
const counts = computed(() => d.value?.counts || { open: 0, done: 0, overdue: 0, total: 0 });
const day = (v) => (v ? fmtDate(v, { time: false }) : '—');
const act = useAction();
const post = (step, body, success) => act.run(() => api.postIdempotent(`${API}/projects/${d.value.id}/${step}`, body), { success, invalidate: INVALIDATE });

// ---- status
const STEPS = {
  active: [{ to: 'on_hold', label: { ar: 'إيقاف مؤقت', en: 'Put on hold' }, tone: 'softAmber' }, { to: 'done', label: { ar: 'إنهاء المشروع', en: 'Finish the project' }, tone: 'success' }],
  on_hold: [{ to: 'active', label: { ar: 'استئناف المشروع', en: 'Resume the project' }, tone: 'softGreen' }, { to: 'done', label: { ar: 'إنهاء المشروع', en: 'Finish the project' }, tone: 'success' }],
  done: [{ to: 'active', label: { ar: 'إعادة فتح المشروع', en: 'Reopen the project' }, tone: 'softAmber' }],
};
const steps = computed(() => (can.value.status && d.value ? STEPS[d.value.status] || [] : []));
async function setStatus(step) {
  const note = await ask({ title: { ar: `${step.label.ar}: ${d.value.name}`, en: `${step.label.en}: ${d.value.name}` }, label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, tone: 'dark', okLabel: step.label });
  if (note === null) return;
  await post('status', { status: step.to, ...(note ? { note } : {}) }, t('تغيّرت حالة المشروع', 'Project status changed'));
}
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة المشروع ${d.value.number}؟`, en: `Archive project ${d.value.number}?` }, sub: { ar: 'يخرج المشروع من القوائم ويبقى بمهامه وسجله؛ يُستعاد متى شئت.', en: 'The project leaves the lists and keeps its tasks and history; it can be restored at any time.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } });
  if (reason === null) return;
  await post('archive', reason ? { reason } : {}, t('أُرشف المشروع', 'Project archived'));
}
async function restore() {
  if (!(await confirm({ title: { ar: `استعادة المشروع ${d.value.number} من الأرشيف؟`, en: `Restore project ${d.value.number} from the archive?` }, tone: 'dark' }))) return;
  await post('restore', undefined, t('استُعيد المشروع', 'Project restored'));
}

// ---- members
const memberKeys = ref(null);
function editMembers() { memberKeys.value = arr(d.value.members).map((p) => String(p.key)); }
async function saveMembers() {
  const done = await act.run(() => api.put(`${API}/projects/${d.value.id}/members`, { memberKeys: memberKeys.value }), { success: t('حُفظ أعضاء المشروع', 'Members saved'), invalidate: INVALIDATE });
  if (done) memberKeys.value = null;
}

// ---- stages (the columns of the board)
const stages = computed(() => [...arr(d.value?.stages)].sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0)));
/** { id | null, nameAr, nameEn, sequence, isClosing, active } while a stage is added or edited */
const stageForm = ref(null);
function editStage(s = null) {
  act.clearError();
  stageForm.value = s ? { id: s.id, nameAr: s.nameAr || '', nameEn: s.nameEn || '', sequence: s.sequence ?? 0, isClosing: !!s.isClosing, active: s.active !== false }
    : { id: null, nameAr: '', nameEn: '', sequence: ((stages.value[stages.value.length - 1]?.sequence ?? 0) + 10), isClosing: false, active: true };
}
async function saveStage() {
  const s = stageForm.value;
  if (!s || !s.nameAr.trim()) return;
  const body = { nameAr: s.nameAr.trim(), nameEn: s.nameEn.trim() || undefined, sequence: Number(s.sequence) || 0, isClosing: !!s.isClosing, ...(s.id ? { active: !!s.active } : {}) };
  const done = await act.run(() => (s.id ? api.patch(`${API}/task-stages/${s.id}`, body) : api.postIdempotent(`${API}/projects/${d.value.id}/stages`, body)), { success: t('حُفظت المرحلة', 'Stage saved'), invalidate: INVALIDATE });
  if (done) stageForm.value = null;
}
/** One place up or down: the stages are numbered again in their new order (only the ones whose number changes are written). */
async function shift(index, by) {
  const order = [...stages.value];
  const other = index + by;
  if (other < 0 || other >= order.length) return;
  [order[index], order[other]] = [order[other], order[index]];
  await act.run(async () => {
    for (const [i, s] of order.entries()) {
      const sequence = (i + 1) * 10;
      if (s.sequence !== sequence) await api.patch(`${API}/task-stages/${s.id}`, { sequence });
    }
    return true;
  }, { success: t('أُعيد ترتيب المراحل', 'Stages reordered'), invalidate: INVALIDATE });
}
const CLOSING = { ar: 'مرحلة إنجاز', en: 'Done stage', ...TONE.ok };
const OFF = { ar: 'موقوفة', en: 'Put away', ...TONE.off };

watch(() => props.projectId, () => { memberKeys.value = null; stageForm.value = null; act.clearError(); });
</script>

<template>
  <Drawer :open="!!projectId" :width="640" :sub="d ? d.name : null" @close="emit('close')">
    <template #title><span class="num ltr inline-block text-violet">{{ d ? d.number : t('مشروع', 'Project') }}</span></template>

    <ErrorBanner :error="detail.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!d && !detail.error.value" class="skel h-[180px]" />
    <template v-else-if="d">
      <div v-if="d.owned" class="banner teal">{{ t(OWNED_NOTE.ar, OWNED_NOTE.en) }}</div>

      <div class="row wrap mb-3">
        <Chip :map="PROJECT_STATUS" :k="d.status" />
        <Chip v-if="d.archived" :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <OdooMark :row="d" />
        <span class="grow" />
        <Btn tone="softPurple" size="sm" :label="{ ar: 'فتح اللوحة', en: 'Open the board' }" @click="emit('board', d.id)" />
      </div>

      <div v-if="steps.length || can.edit || can.archive || can.restore" class="tile mb-3.5 row wrap">
        <Btn v-for="step in steps" :key="step.to" :tone="step.tone" size="sm" :loading="act.pending.value" :label="step.label" @click="setStatus(step)" />
        <span class="grow" />
        <Btn v-if="can.edit" tone="outline" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', d)" />
        <Btn v-if="can.archive" tone="dangerOutline" size="sm" :disabled="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
        <Btn v-if="can.restore" tone="softBlue" size="sm" :loading="act.pending.value" :label="{ ar: 'استعادة من الأرشيف', en: 'Restore' }" @click="restore" />
      </div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'المدير', en: 'Manager' }"><PersonName :person="d.manager" :empty="{ ar: 'بلا مدير', en: 'No manager' }" /></KV>
        <KV :k="{ ar: 'من يراه', en: 'Who sees it' }" :v="VISIBILITY[d.visibility] ? t(VISIBILITY[d.visibility].ar, VISIBILITY[d.visibility].en) : '—'" />
        <KV :k="{ ar: 'البداية', en: 'Start' }" :v="day(d.startDate)" ltr />
        <KV :k="{ ar: 'النهاية', en: 'End' }" :v="day(d.endDate)" ltr />
        <KV :k="{ ar: 'أُنشئ', en: 'Created' }"><span class="num ltr inline-block">{{ fmtDate(d.createdAt) }}</span><span v-if="d.createdBy" class="text-muted"> · {{ d.createdBy }}</span></KV>
      </div>

      <div v-if="d.description" class="mb-4 whitespace-pre-wrap break-words text-[11.5px] leading-[1.9] text-ink [text-align:match-parent]" dir="auto">{{ d.description }}</div>
      <div v-if="d.archived" class="hint amber !mt-0 mb-4">
        {{ t('مؤرشف', 'Archived') }}<span v-if="d.archivedAt"> · <span class="num ltr inline-block">{{ fmtDate(d.archivedAt) }}</span></span><span v-if="d.archivedBy"> · {{ d.archivedBy }}</span>
        <div v-if="d.archiveReason" dir="auto">{{ d.archiveReason }}</div>
      </div>

      <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="tile"><div class="tile-v">{{ fmtNum(counts.open) }}</div><div class="tile-l">{{ t('مهام مفتوحة', 'Open tasks') }}</div></div>
        <div class="tile" :class="{ red: counts.overdue > 0 }"><div class="tile-v">{{ fmtNum(counts.overdue) }}</div><div class="tile-l">{{ t('متأخرة', 'Late') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ fmtNum(counts.done) }}</div><div class="tile-l">{{ t('مكتملة', 'Done') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtNum(counts.total) }}</div><div class="tile-l">{{ t('كل المهام', 'All tasks') }}</div></div>
      </div>
      <ProgressBar v-if="counts.total" :pct="(counts.done / counts.total) * 100" :label="{ ar: 'نسبة الإنجاز', en: 'Progress' }" show-pct class="mb-4" />

      <!-- members -->
      <div class="row mb-1.5">
        <div class="card-title">{{ t('الأعضاء', 'Members') }}<span class="card-count num ms-2">{{ fmtNum(arr(d.members).length) }}</span></div>
        <Btn v-if="can.members && memberKeys === null" tone="outline" size="sm" :label="{ ar: 'تعديل الأعضاء', en: 'Edit members' }" @click="editMembers" />
      </div>
      <template v-if="memberKeys !== null">
        <PeoplePicker v-model="memberKeys" :users="lookups?.users" :known="d.members" :empty-text="{ ar: 'لا أعضاء.', en: 'No members.' }" :disabled="act.pending.value" />
        <div class="row mb-4 mt-2">
          <Btn tone="dark" size="sm" :loading="act.pending.value" :label="{ ar: 'حفظ الأعضاء', en: 'Save members' }" @click="saveMembers" />
          <Btn tone="soft" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="memberKeys = null" />
        </div>
      </template>
      <div v-else class="mb-4">
        <div v-if="arr(d.members).length" class="flex flex-wrap gap-1.5">
          <span v-for="p in d.members" :key="p.key" class="rounded-full border border-line bg-soft px-2.5 py-1 text-[10.5px] font-bold"><PersonName :person="p" /></span>
        </div>
        <div v-else class="text-[10.5px] text-faint">{{ t('لا أعضاء — يعمل على مهامه مديره ومن يحمل صلاحية إدارة المشاريع.', 'No members — its manager and the holders of project.manage work its tasks.') }}</div>
      </div>

      <!-- stages -->
      <div class="row mb-1.5">
        <div class="card-title">{{ t('مراحل اللوحة', 'Stages of the board') }}<span class="card-count num ms-2">{{ fmtNum(stages.length) }}</span></div>
        <Btn v-if="can.stages && !stageForm" tone="outline" size="sm" :label="{ ar: '+ مرحلة', en: '+ Stage' }" @click="editStage()" />
      </div>
      <div v-if="stages.length" class="overflow-hidden rounded-xl border border-line-2">
        <div v-for="(s, i) in stages" :key="s.id" class="flex flex-wrap items-center gap-2 border-t border-line-2 px-3 py-2 first:border-t-0">
          <span class="num w-6 flex-none text-center text-[10px] text-muted">{{ i + 1 }}</span>
          <div class="min-w-0 flex-1">
            <span class="text-[11px] font-extrabold" :class="{ 'text-muted': s.active === false }" dir="auto">{{ personName(s) }}</span>
          </div>
          <Chip v-if="s.isClosing" small :label="CLOSING" :fg="CLOSING.fg" :bg="CLOSING.bg" />
          <Chip v-if="s.active === false" small :label="OFF" :fg="OFF.fg" :bg="OFF.bg" />
          <OdooMark :row="s" />
          <template v-if="can.stages && !s.owned">
            <button type="button" class="x-btn !h-7 !w-7 !text-[10px]" :disabled="i === 0 || act.pending.value" :aria-label="t('تقديم المرحلة', 'Move the stage up')" @click="shift(i, -1)">▲</button>
            <button type="button" class="x-btn !h-7 !w-7 !text-[10px]" :disabled="i === stages.length - 1 || act.pending.value" :aria-label="t('تأخير المرحلة', 'Move the stage down')" @click="shift(i, 1)">▼</button>
            <Btn tone="ghost" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editStage(s)" />
          </template>
        </div>
      </div>
      <div v-else class="empty !p-3.5">{{ t('لا مراحل بعد.', 'No stages yet.') }}</div>

      <div v-if="stageForm" class="mt-2 rounded-xl border border-line bg-soft p-3">
        <div class="mb-2 text-[11px] font-extrabold">{{ stageForm.id ? t('تعديل المرحلة', 'Edit the stage') : t('مرحلة جديدة', 'New stage') }}</div>
        <div class="form-grid">
          <TextInput v-model="stageForm.nameAr" :label="{ ar: 'الاسم بالعربية', en: 'Arabic name' }" required maxlength="120" @enter="saveStage" />
          <TextInput v-model="stageForm.nameEn" :label="{ ar: 'الاسم بالإنجليزية', en: 'English name' }" dir="ltr" maxlength="120" @enter="saveStage" />
          <NumberInput v-model="stageForm.sequence" :label="{ ar: 'الترتيب', en: 'Order' }" :min="0" :max="100000" />
          <label class="field row cursor-pointer !gap-2 self-end pb-2.5 text-[10.5px] font-extrabold"><input v-model="stageForm.isClosing" type="checkbox">{{ t('مرحلة إنجاز — المهمة التي تدخلها تُعد مكتملة', 'A done stage — a task that enters it is finished') }}</label>
          <label v-if="stageForm.id" class="field row cursor-pointer !gap-2 self-end pb-2.5 text-[10.5px] font-extrabold"><input v-model="stageForm.active" type="checkbox">{{ t('فعّالة (تظهر عمودًا في اللوحة)', 'Active (a column of the board)') }}</label>
        </div>
        <div class="row mt-2.5">
          <Btn tone="dark" size="sm" :loading="act.pending.value" :disabled="!stageForm.nameAr.trim()" :label="{ ar: 'حفظ المرحلة', en: 'Save stage' }" @click="saveStage" />
          <Btn tone="soft" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="stageForm = null" />
        </div>
      </div>
      <div class="hint">{{ t('المهمة التي تدخل «مرحلة إنجاز» تُعد مكتملة. لا تُحذف مرحلة: التي لم تعد مستعملة تُوقَف فتختفي من اللوحة وتبقى مهامها.', 'A task that enters a “done stage” is finished. A stage is never removed: one that is no longer used is put away — it leaves the board and its tasks stay.') }}</div>
    </template>
  </Drawer>
</template>
