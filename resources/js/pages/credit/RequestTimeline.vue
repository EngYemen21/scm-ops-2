<script setup>
// The timeline of a request, newest first: every event the server recorded (created, sent, stages, decisions, papers,
// terms, signature, reminders, escalations, apply) with who and when, and the comment box (`can.comment`) —
// POST /credit/requests/{id}/comments { text }. Comments stay open on a request managed in Odoo.
//   <RequestTimeline :request="detail" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, TextArea, Timeline } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { money, nameOf } from './credit';
import { ENVELOPE, STATE, eventLabel, pick } from './labels';

const props = defineProps({
  request: { type: Object, required: true },
});

const act = useAction({ invalidate: ['credit'] });
const text = ref('');
watch(() => props.request.id, () => { text.value = ''; });
async function addComment() {
  const v = text.value.trim();
  if (!v) return;
  const r = await act.run(() => api.postIdempotent(`/credit/requests/${props.request.id}/comments`, { text: v }), { success: t('أُضيف التعليق', 'Comment added') });
  if (r) text.value = '';
}

const slotName = (slot) => { const d = (props.request.documents || []).find((x) => x.slot === slot); return d ? t(d.labelAr, d.labelEn || d.labelAr) : slot; };
const stateName = (s) => (s ? bi(pick(STATE, s)) : '');
/** One line about what an event changed, from its `data` (names of fields and codes only — never personal values). */
function detail(e) {
  const d = e.data || {};
  switch (e.kind) {
    case 'created': return d.customer ? `${t('العميل', 'Customer')} ${d.customer}` : '';
    case 'submitted': return d.again ? t(`أُعيد إرساله (جولة ${d.round})`, `Sent again (round ${d.round})`) : '';
    case 'stage': return [e.stage ? nameOf(e.stage) : d.stage, d.deadline ? `${t('الموعد', 'due')} ${fmtDate(d.deadline)}` : ''].filter(Boolean).join(' · ');
    case 'withdrawn': case 'reopened': case 'cancelled': return d.from ? `${t('كان', 'was')}: ${stateName(d.from)}` : '';
    case 'document_added': case 'document_removed': return d.slot ? slotName(d.slot) : '';
    case 'terms_accepted': return d.version ? `${t('الإصدار', 'Version')} ${d.version}` : '';
    case 'envelope': return d.status ? `${d.from ? `${bi(pick(ENVELOPE, d.from))} → ` : ''}${bi(pick(ENVELOPE, d.status))}` : '';
    case 'reminder': return d.count ? `${t('التذكير رقم', 'Reminder no.')} ${d.count}${d.overdue ? ` · ${t('متأخر', 'overdue')}` : ''}` : '';
    case 'escalated': return d.to ? `${t('إلى', 'to')} ${d.to}` : '';
    case 'applied': return d.creditLimit && typeof d.creditLimit === 'object' ? `${t('الحد', 'Limit')} ${money(d.creditLimit.from)} → ${money(d.creditLimit.to)}` : '';
    case 'apply_blocked': return d.code ? `${t(d.messageAr || d.code, d.messageEn || d.messageAr || d.code)}` : '';
    case 'approvers_refreshed': return d.lines != null ? `${t('سطور الاعتماد', 'Approval lines')}: ${d.lines}` : '';
    case 'edited': case 'assessed': return Array.isArray(d.fields) && d.fields.length ? `${t('الحقول', 'Fields')}: ${d.fields.length}` : '';
    default: return '';
  }
}
const items = computed(() => [...(props.request.events || [])].reverse().map((e) => {
  const l = eventLabel(e.kind);
  const stageWord = e.stage && e.kind !== 'stage' ? nameOf(e.stage) : '';
  return { at: e.at, by: e.by ? nameOf(e.by) : t('النظام', 'System'), label: { ar: l.ar, en: l.en }, color: l.fg, text: detail(e), stage: stageWord, note: e.note, kind: e.kind };
}));
</script>

<template>
  <div>
    <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
    <div v-if="request.can?.comment" class="mb-3">
      <TextArea v-model="text" :rows="2" :placeholder="{ ar: 'اكتب تعليقًا على الطلب…', en: 'Write a comment on the request…' }" />
      <div class="row mt-1.5"><span class="grow" /><Btn size="sm" tone="dark" :loading="act.pending.value" :disabled="!text.trim()" :label="{ ar: 'إضافة التعليق', en: 'Add the comment' }" @click="addComment" /></div>
    </div>
    <Timeline :items="items" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }">
      <template #chip="{ item }"><span v-if="item.stage" class="text-[8.5px] font-bold text-violet" dir="auto">{{ item.stage }}</span></template>
      <template #note="{ item }">
        <div v-if="item.text" dir="auto">{{ item.text }}</div>
        <div v-if="item.note" class="whitespace-pre-wrap text-ink [overflow-wrap:anywhere]" dir="auto">{{ item.note }}</div>
      </template>
    </Timeline>
  </div>
</template>
