<script setup>
// The thread of a ticket (replies and internal notes) or of a task (comments), oldest first, with what happened to
// the record told in words between them (`event` + `data`) — and the box that adds to the thread:
//   ticket → POST /workspace/tickets/{id}/messages { kind: reply | note, body, files }
//   task   → POST /workspace/tasks/{id}/comments   { body, files }
// A body or at least one file. Replies and comments stay possible on a record Odoo owns: they are OPS's own.
//   <Conversation thread="ticket" :thread-id="d.id" :messages="d.messages" :can-reply="d.can.reply" :can-note="d.can.note" :lookups="lookups" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Tabs, TextArea } from '@/components';
import { bi, fmtDate, fmtNum, isBi, t } from '@/i18n';
import FileList from './FileList.vue';
import FilePicker from './FilePicker.vue';
import PersonName from './PersonName.vue';
import { API, EVENT_LABEL, INVALIDATE, MESSAGE_KIND, ODOO_CHIP, arr, personName, toUploads } from './workspace';

const props = defineProps({
  /** ticket | task */
  thread: { type: String, required: true },
  /** Id of the ticket / the task the thread belongs to. */
  threadId: { type: String, default: null },
  /** [Message], oldest first. */
  messages: { type: Array, default: () => [] },
  canReply: { type: Boolean, default: false },
  canNote: { type: Boolean, default: false },
  canComment: { type: Boolean, default: false },
  /** The answer of GET lookups (the rules of an upload). */
  lookups: { type: Object, default: null },
});

const rows = computed(() => arr(props.messages).filter((m) => m && typeof m === 'object'));
/** How many lines people wrote (the events are not counted). */
const written = computed(() => rows.value.filter((m) => m.kind !== 'event').length);

// ---- what happened, in words
const say = (v) => (v == null || v === '' ? '' : isBi(v) ? bi(v) : typeof v === 'object' ? '' : String(v));
function eventText(m) {
  const label = EVENT_LABEL[m.event];
  const base = label ? t(label.ar, label.en) : m.event || t('حدث', 'Event');
  const from = say(m.data?.from);
  const to = say(m.data?.to);
  const move = from && to ? t(`من «${from}» إلى «${to}»`, `from “${from}” to “${to}”`) : to ? t(`إلى «${to}»`, `to “${to}”`) : from ? t(`من «${from}»`, `from “${from}”`) : '';
  return [base, move].filter(Boolean).join(' ');
}
const eventReason = (m) => say(m.data?.reason);
const eventFields = (m) => (Array.isArray(m.data?.fields) ? m.data.fields.map(say).filter(Boolean).join('، ') : '');
const eventDot = (m) => EVENT_LABEL[m.event]?.color || '#a8a4b8';
const byWhom = (m) => (m.author ? personName(m.author) : m.origin === 'odoo' ? t('أودو', 'Odoo') : t('النظام', 'System'));
const bubble = (m) => (m.kind === 'note' ? 'border-[#F0DEB8] bg-[#FFFBF2]' : m.kind === 'reply' ? 'border-[#B5E7F0] bg-[#F5FCFD]' : 'border-line bg-white');

// ---- the box that adds to the thread
const kinds = computed(() => (props.thread === 'ticket' ? [props.canReply && 'reply', props.canNote && 'note'] : [props.canComment && 'comment']).filter(Boolean));
const kind = ref('');
watch(kinds, (list) => { if (!list.includes(kind.value)) kind.value = list[0] || ''; }, { immediate: true });
const kindTabs = computed(() => kinds.value.map((k) => ({ k, label: MESSAGE_KIND[k] })));

const body = ref('');
const files = ref([]);
/** The picker is still reading files from the disk. */
const picking = ref(false);
const act = useAction();
watch(() => props.threadId, () => { body.value = ''; files.value = []; act.clearError(); });

const ok = computed(() => !!props.threadId && !!kind.value && !picking.value && (body.value.trim() !== '' || files.value.length > 0));
const placeholder = computed(() => ({
  reply: { ar: 'اكتب الرد على صاحب الطلب…', en: 'Write the reply to the requester…' },
  note: { ar: 'ملاحظة داخلية لفريق العمل…', en: 'An internal note for the team…' },
  comment: { ar: 'اكتب تعليقًا…', en: 'Write a comment…' },
}[kind.value] || null));
const sendLabel = computed(() => ({
  reply: { ar: 'إرسال الرد', en: 'Post reply' }, note: { ar: 'حفظ الملاحظة', en: 'Save note' }, comment: { ar: 'إضافة التعليق', en: 'Add comment' },
}[kind.value] || { ar: 'إرسال', en: 'Post' }));

async function send() {
  if (!ok.value) return;
  const text = body.value.trim();
  const payload = { ...(text ? { body: text } : {}), ...(files.value.length ? { files: toUploads(files.value) } : {}) };
  const url = props.thread === 'ticket' ? `${API}/tickets/${props.threadId}/messages` : `${API}/tasks/${props.threadId}/comments`;
  if (props.thread === 'ticket') payload.kind = kind.value;
  const done = await act.run(() => api.postIdempotent(url, payload), { success: t('أُضيف إلى المحادثة', 'Added to the thread'), invalidate: INVALIDATE });
  if (done) { body.value = ''; files.value = []; }
}
</script>

<template>
  <div>
    <div class="card-title mb-1.5">
      {{ thread === 'ticket' ? t('المحادثة', 'Conversation') : t('التعليقات والسجل', 'Comments and history') }}
      <span v-if="written" class="card-count num ms-1">{{ fmtNum(written) }}</span>
    </div>

    <div v-if="!rows.length" class="empty !p-3.5">{{ thread === 'ticket' ? t('لا ردود ولا ملاحظات بعد.', 'No replies or notes yet.') : t('لا تعليقات بعد.', 'No comments yet.') }}</div>
    <div v-else class="flex flex-col gap-2">
      <template v-for="m in rows" :key="m.id">
        <!-- something that happened to the record -->
        <div v-if="m.kind === 'event'" class="flex items-start gap-2 px-1 text-[10px] leading-[1.7] text-sec">
          <span class="mt-[6px] h-2 w-2 flex-none rounded-full" :style="{ background: eventDot(m) }" />
          <div class="min-w-0 flex-1">
            <span class="font-extrabold">{{ eventText(m) }}</span>
            <span v-if="eventFields(m)" dir="auto"> ({{ eventFields(m) }})</span>
            <span v-if="eventReason(m)" dir="auto"> — {{ eventReason(m) }}</span>
            <div v-if="m.body" class="whitespace-pre-wrap break-words" dir="auto">{{ m.body }}</div>
            <div class="cell-date" style="direction: inherit"><span class="font-sans">{{ byWhom(m) }} · </span><span class="ltr inline-block">{{ fmtDate(m.at) }}</span></div>
          </div>
        </div>
        <!-- something somebody wrote -->
        <div v-else class="rounded-xl border px-3 py-2.5" :class="bubble(m)">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="min-w-0 text-[11px] font-extrabold text-ink"><PersonName :person="m.author" :empty="m.origin === 'odoo' ? { ar: 'أودو', en: 'Odoo' } : { ar: 'النظام', en: 'System' }" /></span>
            <Chip v-if="MESSAGE_KIND[m.kind]" small :label="MESSAGE_KIND[m.kind]" :fg="MESSAGE_KIND[m.kind].fg" :bg="MESSAGE_KIND[m.kind].bg" />
            <Chip v-if="m.origin === 'odoo'" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" :title="t('كُتب في أودو', 'Written in Odoo')" />
            <span class="grow" />
            <span class="cell-date">{{ fmtDate(m.at) }}</span>
          </div>
          <div v-if="m.body" class="mt-1.5 whitespace-pre-wrap break-words text-[11.5px] leading-[1.8] text-ink [text-align:match-parent]" dir="auto">{{ m.body }}</div>
          <FileList v-if="arr(m.files).length" :files="m.files" class="mt-2" />
        </div>
      </template>
    </div>

    <div v-if="kinds.length" class="mt-3 rounded-xl border border-line bg-soft p-3">
      <Tabs v-if="kinds.length > 1" v-model="kind" :tabs="kindTabs" variant="pill" class="!mb-2" />
      <TextArea v-model="body" :rows="3" :placeholder="placeholder" :disabled="act.pending.value" />
      <FilePicker v-model="files" :lookups="lookups" :disabled="act.pending.value" class="mt-2" @busy="picking = $event" />
      <ErrorBanner :error="act.error.value" class="mt-2 !mb-0" @close="act.clearError()" />
      <div class="row wrap mt-2">
        <Btn tone="dark" :loading="act.pending.value" :disabled="!ok" :label="sendLabel" @click="send" />
        <!-- (`min-w-0 flex-1`, not `grow`: on a phone a bare spacer of a wrapped row is hidden, and this is text) -->
        <span v-if="kind === 'reply'" class="field-hint min-w-0 flex-1">{{ t('الرد يُسجَّل على التذكرة ويراه صاحب الطلب إن كان من الموظفين — لا يُرسَل شيء إلى العميل من هنا.', 'The reply is recorded on the ticket and seen by the requester when he is a member of staff — nothing is sent to a customer from here.') }}</span>
        <span v-else-if="kind === 'note'" class="field-hint min-w-0 flex-1">{{ t('الملاحظة الداخلية لا تُحتسب ردًّا على صاحب الطلب.', 'An internal note does not count as an answer to the requester.') }}</span>
      </div>
    </div>
  </div>
</template>
