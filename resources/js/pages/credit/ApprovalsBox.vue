<script setup>
// The approval audit of a request (every line of every stage and round: who was asked, by role or by name, the
// deadline, the decision, the reminders and the escalation) and — only for reviewers, approvers and managers, when
// the API sends it — the notification log: channel, recipient, status. A notification «skipped» was not sent because
// no provider is configured for that channel (e-mail and WhatsApp until one is); nothing is shown as sent that was not.
//   <ApprovalsBox :request="detail" />
import { computed } from 'vue';
import { Chip, DataTable } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { nameOf, useCreditLookups } from './credit';
import { CHANNEL, LINE, NOTIFY, SKIPPED_HELP, pick } from './labels';

const props = defineProps({
  request: { type: Object, required: true },
});
const lk = useCreditLookups();
const channels = lk.channels;

const lines = computed(() => props.request.approvals || []);
const notes = computed(() => props.request.notifications);

const lineCols = [
  { key: 'stage', header: { ar: 'المرحلة', en: 'Stage' }, width: 'minmax(120px,1fr)' },
  { key: 'who', header: { ar: 'المعتمد', en: 'Approver' }, width: 'minmax(130px,1fr)' },
  { key: 'state', header: { ar: 'القرار', en: 'Decision' }, width: '112px', bare: true },
  { key: 'deadline', header: { ar: 'الموعد', en: 'Due' }, width: '96px', kind: 'date', value: (l) => (l.deadline ? fmtDate(l.deadline) : '—') },
  { key: 'decided', header: { ar: 'قرّر', en: 'Decided' }, width: 'minmax(130px,1fr)' },
];
const noteCols = [
  { key: 'createdAt', header: { ar: 'الوقت', en: 'When' }, width: '104px', kind: 'date', value: (n) => fmtDate(n.createdAt) },
  { key: 'channel', header: { ar: 'القناة', en: 'Channel' }, width: '104px', bare: true },
  { key: 'recipient', header: { ar: 'المستلم', en: 'Recipient' }, width: 'minmax(130px,1fr)' },
  { key: 'template', header: { ar: 'الرسالة', en: 'Message' }, width: 'minmax(110px,0.8fr)', value: (n) => n.template || '—' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: 'minmax(130px,1fr)', bare: true },
];
const chan = (k) => pick(CHANNEL, k);
</script>

<template>
  <div class="col !gap-4">
    <section>
      <div class="field-l mb-1.5">{{ t('سجل الاعتمادات', 'Approval audit') }}</div>
      <div class="card">
        <DataTable :columns="lineCols" :rows="lines" :page-size="20" :row-key="(l) => l.id" :empty-text="{ ar: 'لم يُطلب اعتماد أحد بعد.', en: 'Nobody has been asked to approve yet.' }">
          <template #cell-stage="{ row }">
            <div class="min-w-0">
              <div class="cell-name truncate" dir="auto">{{ row.stage ? nameOf(row.stage) : '—' }}</div>
              <div class="cell-sub">{{ t('جولة', 'round') }} <span class="num">{{ row.round }}</span><template v-if="row.origin === 'odoo'"> · {{ t('من أودو', 'from Odoo') }}</template></div>
            </div>
          </template>
          <template #cell-who="{ row }">
            <div class="min-w-0 text-[10.5px]">
              <div class="truncate text-ink" dir="auto">{{ row.person ? nameOf(row.person) : row.role ? nameOf(row.role) : t('أي معتمد', 'Any approver') }}</div>
              <div class="cell-sub truncate" dir="auto">{{ row.personal ? t('بالاسم', 'by name') : row.role ? `${t('دور', 'role')}: ${nameOf(row.role)}` : '' }}</div>
            </div>
          </template>
          <template #cell-state="{ row }">
            <span class="inline-flex flex-col items-center gap-0.5">
              <Chip small :label="pick(LINE, row.state)" :fg="pick(LINE, row.state).fg" :bg="pick(LINE, row.state).bg" />
              <span v-if="row.reminderCount" class="text-[8.5px] text-warn">{{ t('تذكيرات', 'reminders') }} <span class="num">{{ row.reminderCount }}</span></span>
            </span>
          </template>
          <template #cell-decided="{ row }">
            <div class="min-w-0 text-[10px]">
              <template v-if="row.decidedAt"><span dir="auto">{{ row.decidedBy ? nameOf(row.decidedBy) : '—' }}</span> · <span class="num ltr inline-block">{{ fmtDate(row.decidedAt) }}</span></template>
              <span v-else class="text-faint">—</span>
              <div v-if="row.note" class="whitespace-pre-wrap text-[9.5px] text-muted [overflow-wrap:anywhere]" dir="auto">{{ row.note }}</div>
              <div v-if="row.escalatedAt" class="text-[9px] font-bold text-bad">{{ t('صُعّدت', 'escalated') }} <span class="num ltr inline-block">{{ fmtDate(row.escalatedAt) }}</span><template v-if="row.escalatedTo"> → <span dir="auto">{{ nameOf(row.escalatedTo) }}</span></template></div>
            </div>
          </template>
        </DataTable>
      </div>
    </section>

    <section v-if="notes">
      <div class="field-l mb-1.5">{{ t('سجل الإشعارات', 'Notification log') }}</div>
      <div class="mb-2 row wrap !gap-1.5 text-[9.5px] text-muted">
        <span>{{ t('القنوات', 'Channels') }}:</span>
        <Chip small :label="CHANNEL.in_app" :fg="CHANNEL.in_app.fg" :bg="CHANNEL.in_app.bg" />
        <Chip small :label="channels.email ? CHANNEL.email : { ar: 'البريد: غير مُعدّ', en: 'E-mail: not configured' }" :fg="channels.email ? CHANNEL.email.fg : '#55506a'" :bg="channels.email ? CHANNEL.email.bg : '#F1EFF6'" />
        <Chip small :label="channels.whatsapp ? CHANNEL.whatsapp : { ar: 'واتساب: غير مُعدّ', en: 'WhatsApp: not configured' }" :fg="channels.whatsapp ? CHANNEL.whatsapp.fg : '#55506a'" :bg="channels.whatsapp ? CHANNEL.whatsapp.bg : '#F1EFF6'" />
      </div>
      <div class="card">
        <DataTable :columns="noteCols" :rows="notes" :page-size="20" :row-key="(n) => n.id" :empty-text="{ ar: 'لا إشعارات لهذا الطلب.', en: 'No notification for this request.' }">
          <template #cell-channel="{ row }"><Chip small :label="chan(row.channel)" :fg="chan(row.channel).fg" :bg="chan(row.channel).bg" /></template>
          <template #cell-recipient="{ row }">
            <div class="min-w-0 text-[10px]">
              <div class="truncate" dir="auto">{{ row.recipient ? nameOf(row.recipient) : '—' }}</div>
              <div v-if="row.to" class="cell-sub num ltr truncate">{{ row.to }}</div>
            </div>
          </template>
          <template #cell-status="{ row }">
            <span class="inline-flex flex-col items-center gap-0.5 text-center">
              <Chip small :label="pick(NOTIFY, row.status)" :fg="pick(NOTIFY, row.status).fg" :bg="pick(NOTIFY, row.status).bg" />
              <span v-if="row.status === 'skipped'" class="text-[8.5px] text-faint">{{ bi(SKIPPED_HELP) }}</span>
              <span v-else-if="row.status === 'sent' && row.sentAt" class="num ltr text-[8.5px] text-faint">{{ fmtDate(row.sentAt) }}</span>
              <span v-if="row.error" class="text-[8.5px] text-bad" dir="auto">{{ row.error }}</span>
            </span>
          </template>
        </DataTable>
      </div>
    </section>
  </div>
</template>
