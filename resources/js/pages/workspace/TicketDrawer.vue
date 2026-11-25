<script setup>
// One ticket of the help desk (deep link `ticket=<number>`): what it is about, who asks, who works it, how it stands
// against the team's targets, its files and its conversation — and what may be done to it now. The buttons follow the
// ticket's own `can.*` flags (the server decides; a ticket Odoo owns only takes replies and notes):
//   POST /workspace/tickets/{id}/assign { assigneeId | null } · move { stageId } · close { resolution, stageId? } ·
//   reopen { note? } · archive { reason? } · restore.   Editing its details opens the form (`edit`).
//   <TicketDrawer :number="qs.get('ticket')" :desk="desk" :lookups="lookups" @close @edit="(ticket) => …" />
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { fmtDate, nm, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import Conversation from './Conversation.vue';
import FileList from './FileList.vue';
import OdooMark from './OdooMark.vue';
import PersonName from './PersonName.vue';
import { API, ARCHIVED_CHIP, INVALIDATE, OWNED_NOTE, PRIORITY, REQUESTER_KIND, TICKET_STATUS, TONE, arr, dueInfo, personName, refLabel, refPath } from './workspace';

const props = defineProps({
  /** Number (or id) of the ticket on screen; empty = closed. */
  number: { type: String, default: '' },
  /** GET tickets/lookups: the stages a ticket may move to. */
  desk: { type: Object, default: null },
  /** GET lookups: the users a ticket may be given to, the rules of an upload. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'edit']);

const auth = useAuth();
const detail = useGet(() => (props.number ? `${API}/tickets/${encodeURIComponent(props.number)}` : null));
const d = computed(() => (props.number ? detail.data.value || null : null));
const can = computed(() => d.value?.can || {});
const closed = computed(() => d.value?.status === 'closed');
const anyAction = computed(() => ['edit', 'assign', 'move', 'close', 'reopen', 'archive', 'restore'].some((k) => can.value[k]));

// ---- where it may go
const fits = (s) => !arr(s.teamIds).length || s.teamIds.includes(d.value?.team?.id);
const stagesOf = (closing) => arr(props.desk?.stages).filter((s) => s.active !== false && !!s.isClosing === closing && fits(s) && s.id !== d.value?.stage?.id);
const openStages = computed(() => stagesOf(false));
const closingStages = computed(() => stagesOf(true));
const users = computed(() => arr(props.lookups?.users).filter((u) => u.id !== d.value?.assignee?.id));

// ---- how it stands against the targets
const due = computed(() => dueInfo(d.value?.dueAt, closed.value));
const answer = computed(() => {
  const x = d.value;
  if (!x) return null;
  if (x.firstResponseAt) return { text: t(`تم الرد ${fmtDate(x.firstResponseAt)}`, `Answered ${fmtDate(x.firstResponseAt)}`), late: false, done: true };
  const info = dueInfo(x.firstResponseDueAt, closed.value);
  return info ? { ...info, late: info.late || !!x.responseOverdue, done: false } : null;
});
const LATE = { ar: 'متأخرة', en: 'Overdue', fg: '#fff', bg: '#b23b3b' };

// ---- what is done to it
const act = useAction();
const post = (step, body, success) => act.run(() => api.postIdempotent(`${API}/tickets/${d.value.id}/${step}`, body), { success, invalidate: INVALIDATE });
const assignTo = (id) => post('assign', { assigneeId: id }, id ? t('أُسندت التذكرة', 'Ticket assigned') : t('أُلغي إسناد التذكرة', 'Assignment cleared'));
/** A select that acts when a value is picked, then shows its caption again. */
function picked(e, run) {
  const value = e.target.value;
  e.target.value = '';
  if (value) run(value);
}
const moveTo = (stageId) => post('move', { stageId }, t('نُقلت التذكرة إلى المرحلة', 'Ticket moved'));
async function close(stage) {
  const resolution = await ask({
    title: { ar: `إغلاق التذكرة ${d.value.number}`, en: `Close ticket ${d.value.number}` },
    sub: stage ? { ar: `تنتقل إلى مرحلة «${stage.nameAr}». اكتب ما تم حتى يُعرف كيف انتهت.`, en: `It moves to “${stage.nameEn || stage.nameAr}”. Say what was done, so that anybody knows how it ended.` } : null,
    label: { ar: 'الحل', en: 'Resolution' }, required: true, tone: 'dark', okLabel: { ar: 'إغلاق التذكرة', en: 'Close the ticket' },
  });
  if (resolution === null) return;
  await post('close', { resolution, ...(stage ? { stageId: stage.id } : {}) }, t('أُغلقت التذكرة', 'Ticket closed'));
}
async function reopen() {
  const note = await ask({ title: { ar: `إعادة فتح التذكرة ${d.value.number}؟`, en: `Reopen ticket ${d.value.number}?` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'dark', okLabel: { ar: 'إعادة الفتح', en: 'Reopen' } });
  if (note === null) return;
  await post('reopen', note ? { note } : {}, t('أُعيد فتح التذكرة', 'Ticket reopened'));
}
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة التذكرة ${d.value.number}؟`, en: `Archive ticket ${d.value.number}?` }, sub: { ar: 'تبقى التذكرة بكل سجلها وتخرج من القوائم؛ تُستعاد متى شئت.', en: 'The ticket stays with all its history and leaves the lists; it can be restored at any time.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } });
  if (reason === null) return;
  await post('archive', reason ? { reason } : {}, t('أُرشفت التذكرة', 'Ticket archived'));
}
async function restore() {
  if (!(await confirm({ title: { ar: `استعادة التذكرة ${d.value.number} من الأرشيف؟`, en: `Restore ticket ${d.value.number} from the archive?` }, tone: 'dark' }))) return;
  await post('restore', undefined, t('استُعيدت التذكرة', 'Ticket restored'));
}
</script>

<template>
  <Drawer :open="!!number" :width="660" :sub="d ? d.subject : null" @close="emit('close')">
    <template #title><span class="num ltr inline-block text-violet">{{ d ? d.number : number }}</span></template>

    <ErrorBanner :error="detail.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!d && !detail.error.value" class="skel h-[180px]" />
    <template v-else-if="d">
      <div v-if="d.owned" class="banner teal">{{ t(OWNED_NOTE.ar, OWNED_NOTE.en) }}</div>

      <div class="row wrap mb-3">
        <Chip :map="TICKET_STATUS" :k="d.status" />
        <Chip v-if="d.stage" :fg="TONE.neutral.fg" :bg="TONE.neutral.bg">{{ nm(d.stage) }}</Chip>
        <Chip :map="PRIORITY" :k="String(d.priority ?? 0)" />
        <Chip v-if="d.type" :fg="TONE.violet.fg" :bg="TONE.violet.bg">{{ nm(d.type) }}</Chip>
        <Chip v-if="d.overdue || d.responseOverdue" :label="LATE" :fg="LATE.fg" :bg="LATE.bg" />
        <Chip v-if="d.archived" :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <OdooMark :row="d" />
      </div>

      <!-- what may be done now -->
      <div v-if="anyAction" class="tile mb-3.5 flex flex-col gap-2">
        <div v-if="can.assign" class="row wrap">
          <Btn v-if="auth.user && d.assignee?.id !== auth.user.id" tone="softPurple" size="sm" :loading="act.pending.value" :label="{ ar: 'أسندها إليّ', en: 'Assign to me' }" @click="assignTo(auth.user.id)" />
          <select v-if="users.length" class="inp sm !w-auto max-w-[220px]" :disabled="act.pending.value" :aria-label="t('إسناد إلى', 'Assign to')" @change="picked($event, assignTo)">
            <option value="">{{ t('إسناد إلى…', 'Assign to…') }}</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ personName(u) }}</option>
          </select>
          <Btn v-if="d.assignee" tone="ghost" size="sm" :disabled="act.pending.value" :label="{ ar: 'إلغاء الإسناد', en: 'Unassign' }" @click="assignTo(null)" />
        </div>
        <div class="row wrap">
          <select v-if="can.move && openStages.length" class="inp sm !w-auto max-w-[220px]" :disabled="act.pending.value" :aria-label="t('نقل إلى مرحلة', 'Move to a stage')" @change="picked($event, moveTo)">
            <option value="">{{ t('نقل إلى مرحلة…', 'Move to stage…') }}</option>
            <option v-for="s in openStages" :key="s.id" :value="s.id">{{ nm(s) }}</option>
          </select>
          <template v-if="can.close">
            <Btn v-for="s in closingStages" :key="s.id" tone="success" size="sm" :loading="act.pending.value" @click="close(s)">{{ t('إغلاق', 'Close') }}: {{ nm(s) }}</Btn>
            <Btn v-if="!closingStages.length" tone="success" size="sm" :loading="act.pending.value" :label="{ ar: 'إغلاق التذكرة', en: 'Close the ticket' }" @click="close(null)" />
          </template>
          <Btn v-if="can.reopen" tone="softAmber" size="sm" :loading="act.pending.value" :label="{ ar: 'إعادة الفتح', en: 'Reopen' }" @click="reopen" />
          <span class="grow" />
          <Btn v-if="can.edit" tone="outline" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', d)" />
          <Btn v-if="can.archive" tone="dangerOutline" size="sm" :disabled="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
          <Btn v-if="can.restore" tone="softBlue" size="sm" :loading="act.pending.value" :label="{ ar: 'استعادة من الأرشيف', en: 'Restore' }" @click="restore" />
        </div>
      </div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'الفريق', en: 'Team' }"><span dir="auto">{{ d.team ? nm(d.team) : '—' }}</span></KV>
        <KV :k="{ ar: 'المسؤول', en: 'Assignee' }"><PersonName :person="d.assignee" :empty="{ ar: 'غير مسندة', en: 'Unassigned' }" /></KV>
        <KV :k="{ ar: 'صاحب الطلب', en: 'Requester' }">
          <div class="flex flex-col gap-0.5">
            <span>
              <span class="text-muted">{{ REQUESTER_KIND[d.requester?.kind] ? t(REQUESTER_KIND[d.requester.kind].ar, REQUESTER_KIND[d.requester.kind].en) : '' }}</span>
              <template v-if="d.requester?.customer"> · <span class="cell-id">{{ d.requester.customer.code }}</span> <span dir="auto">{{ nm(d.requester.customer) }}</span></template>
              <template v-else-if="d.requester?.user"> · <PersonName :person="d.requester.user" /></template>
            </span>
            <span v-if="d.requester?.name && !d.requester?.user" dir="auto">{{ d.requester.name }}</span>
            <span v-if="d.requester?.email" class="num-mixed ltr text-[10px] text-sec">{{ d.requester.email }}</span>
            <span v-if="d.requester?.phone" class="num ltr text-[10px] text-sec">{{ d.requester.phone }}</span>
          </div>
        </KV>
        <KV :k="{ ar: 'فُتحت', en: 'Opened' }"><span class="num ltr inline-block">{{ fmtDate(d.createdAt) }}</span><span v-if="d.createdBy" class="text-muted"> · {{ d.createdBy }}</span></KV>
        <KV :k="{ ar: 'الاستحقاق', en: 'Due' }">
          <template v-if="d.dueAt"><span class="num ltr inline-block">{{ fmtDate(d.dueAt) }}</span><span v-if="due && !closed" class="ms-1 font-extrabold" :class="due.late ? 'text-bad' : 'text-ok'">{{ due.text }}</span></template>
          <span v-else class="text-faint">{{ t('بلا هدف زمني', 'No target') }}</span>
        </KV>
        <KV :k="{ ar: 'أول رد', en: 'First answer' }">
          <span v-if="answer" class="font-extrabold" :class="answer.late ? 'text-bad' : answer.done ? 'text-ok' : 'text-sec'">{{ answer.text }}</span>
          <span v-else class="text-faint">{{ t('بلا هدف زمني', 'No target') }}</span>
        </KV>
        <KV v-if="closed" :k="{ ar: 'أُغلقت', en: 'Closed' }"><span class="num ltr inline-block">{{ fmtDate(d.closedAt) }}</span><span v-if="d.closedBy" class="text-muted"> · {{ d.closedBy }}</span></KV>
        <KV v-if="d.ref" :k="{ ar: 'سجل مرتبط', en: 'Linked record' }">
          <RouterLink v-if="refPath(d.ref)" :to="refPath(d.ref)" class="font-extrabold">{{ refLabel(d.ref) }}</RouterLink>
          <span v-else>{{ refLabel(d.ref) }}</span>
        </KV>
        <KV v-if="d.sourceNumber" :k="{ ar: 'رقمها في أودو', en: 'Number in Odoo' }" :v="d.sourceNumber" ltr />
      </div>

      <div class="card-title mb-1">{{ t('الوصف', 'Description') }}</div>
      <div v-if="d.description" class="mb-4 whitespace-pre-wrap break-words text-[11.5px] leading-[1.9] text-ink [text-align:match-parent]" dir="auto">{{ d.description }}</div>
      <div v-else class="empty !p-3.5">{{ t('لا وصف.', 'No description.') }}</div>

      <div v-if="d.resolution" class="hint green !mt-0 mb-4">
        <div class="font-extrabold">{{ t('الحل', 'Resolution') }}</div>
        <div class="whitespace-pre-wrap break-words" dir="auto">{{ d.resolution }}</div>
      </div>
      <div v-if="d.archived" class="hint amber !mt-0 mb-4">
        {{ t('مؤرشفة', 'Archived') }}<span v-if="d.archivedAt"> · <span class="num ltr inline-block">{{ fmtDate(d.archivedAt) }}</span></span><span v-if="d.archivedBy"> · {{ d.archivedBy }}</span>
        <div v-if="d.archiveReason" dir="auto">{{ d.archiveReason }}</div>
      </div>

      <template v-if="arr(d.files).length">
        <div class="card-title mb-1.5">{{ t('ملفات التذكرة', 'Files of the ticket') }}</div>
        <FileList :files="d.files" class="mb-4" />
      </template>

      <Conversation thread="ticket" :thread-id="d.id" :messages="d.messages" :can-reply="!!can.reply" :can-note="!!can.note" :lookups="lookups" />
    </template>
  </Drawer>
</template>
