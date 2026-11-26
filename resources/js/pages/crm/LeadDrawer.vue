<script setup>
// One lead / opportunity: where it stands, who it is, what comes next and everything that happened to it.
// GET /crm/leads/{number} → the lead + `activities`, `events` (its timeline), `quotations` and `can` — what would be
// accepted now; a button is shown only for that (and only with crm.manage). The moves:
//   stage pills → POST …/move { stageId } (a won stage wins it) · «كسب» → …/win · «خسارة» → …/lose { reasonId, note }
//   «إعادة فتح» → …/reopen · note → …/notes { text } · to-dos → …/activities, /crm/activities/{id}/{done,cancel}
//   «تحويل إلى عميل» → ConvertDrawer · «عرض سعر» → LeadQuotation
// A lead of Odoo (`owned`) keeps only what is OPS's own: notes, to-dos, customer, quotations.
//   <LeadDrawer :number="numberOrNull" @close="…" @edit="(lead) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Tabs, TextArea, Timeline } from '@/components';
import { bi, fmtDate, fmtMoney, t } from '@/i18n';
import { QT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ActivityForm from './ActivityForm.vue';
import ConvertDrawer from './ConvertDrawer.vue';
import LeadLose from './LeadLose.vue';
import LeadQuotation from './LeadQuotation.vue';
import {
  ACTIVITY_STATUS, ACTIVITY_TYPE, ARCHIVED_CHIP, KIND_CHIP, LEAD_STATUS, MATCH_BY, OWNED_CHIP, OWNED_HELP, PRIORITY, activityTypeLabel, dueLabel, eventLabel, fmtDay, nameOf, pct, stars,
  tagColor, useCrmLookups,
} from './crm';

const props = defineProps({
  /** The lead's number (or id); null closes the drawer. */
  number: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit']);

const auth = useAuth();
const { activeStages } = useCrmLookups();
const canManage = computed(() => auth.can('crm.manage'));
const isOpen = computed(() => !!props.number);
const q = useGet(() => (props.number ? `/crm/leads/${encodeURIComponent(props.number)}` : null));
const lead = computed(() => (isOpen.value ? q.data.value || null : null));
const can = computed(() => (canManage.value && lead.value ? lead.value.can : {}));
const act = useAction({ invalidate: ['crm'] });

const pane = ref('details');
// another lead (or none): its own first tab, and nothing left open from the previous one
watch(() => props.number, () => { pane.value = 'details'; noteText.value = ''; todoForm.value = losing.value = converting.value = quoting.value = false; act.clearError(); });
const openTodos = computed(() => (lead.value?.activities || []).filter((a) => a.status === 'open').length);
const panes = computed(() => [
  { k: 'details', label: { ar: 'البيانات', en: 'Details' } },
  { k: 'activities', label: { ar: 'الأنشطة', en: 'To-dos' }, badge: openTodos.value },
  { k: 'timeline', label: { ar: 'السجل والملاحظات', en: 'Timeline & notes' } },
  { k: 'quotations', label: { ar: 'عروض الأسعار', en: 'Quotations' }, badge: lead.value?.quotations?.length || null },
]);

// ---- the stages of the board, the lead's own included even when it was archived since
const stagePills = computed(() => {
  const list = [...activeStages.value];
  const mine = lead.value?.stage;
  if (mine && !list.some((s) => s.id === mine.id)) list.unshift(mine);
  return list;
});
const url = (suffix = '') => `/crm/leads/${lead.value.id}${suffix}`;
async function moveTo(stage) {
  if (!can.value.move || stage.id === lead.value.stage?.id) return;
  if (stage.isWon && !(await confirm({ title: { ar: `تسجيل كسب الفرصة ${lead.value.number}؟`, en: `Mark ${lead.value.number} as won?` }, sub: lead.value.name, tone: 'primary', okLabel: { ar: 'كسب', en: 'Won' } }))) return;
  await act.run(() => api.postIdempotent(url('/move'), { stageId: stage.id }), { success: stage.isWon ? t('كُسبت الفرصة', 'Opportunity won') : t(`نُقلت إلى «${nameOf(stage)}»`, `Moved to "${nameOf(stage)}"`) });
}
async function win() {
  if (!(await confirm({ title: { ar: `تسجيل كسب الفرصة ${lead.value.number}؟`, en: `Mark ${lead.value.number} as won?` }, sub: lead.value.name, tone: 'primary', okLabel: { ar: 'كسب', en: 'Won' } }))) return;
  await act.run(() => api.postIdempotent(url('/win')), { success: t('كُسبت الفرصة', 'Opportunity won') });
}
/** A record that entered as a "lead" (Odoo's first step, before the pipeline) becomes an opportunity. */
async function qualify() {
  await act.run(() => api.patch(url(), { kind: 'opportunity' }), { success: t('صارت فرصة في المسار', 'It is now an opportunity of the pipeline') });
}
async function reopen() {
  const note = await ask({ title: { ar: `إعادة فتح الفرصة ${lead.value.number}`, en: `Reopen ${lead.value.number}` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إعادة فتح', en: 'Reopen' } });
  if (note === null) return;
  await act.run(() => api.postIdempotent(url('/reopen'), note ? { note } : undefined), { success: t('أُعيد فتح الفرصة', 'Opportunity reopened') });
}

// ---- notes
const noteText = ref('');
async function addNote() {
  const text = noteText.value.trim();
  if (!text) return;
  const r = await act.run(() => api.postIdempotent(url('/notes'), { text }), { success: t('أُضيفت الملاحظة', 'Note added') });
  if (r) noteText.value = '';
}

// ---- to-dos
const todoForm = ref(false);
const todoEditing = ref(null);
async function done(a) {
  const note = await ask({ title: { ar: `إنجاز: ${a.summary}`, en: `Done: ${a.summary}` }, label: { ar: 'ما الذي حدث؟ (اختياري)', en: 'What happened? (optional)' }, okLabel: { ar: 'تم', en: 'Done' }, tone: 'primary' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(`/crm/activities/${a.id}/done`, note ? { note } : undefined), { success: t('أُنجز النشاط', 'To-do done') });
}
async function cancelTodo(a) {
  const reason = await ask({ title: { ar: `إلغاء: ${a.summary}`, en: `Cancel: ${a.summary}` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إلغاء النشاط', en: 'Cancel the to-do' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/crm/activities/${a.id}/cancel`, reason ? { reason } : undefined), { success: t('أُلغي النشاط', 'To-do cancelled') });
}

// ---- customer and quotations
const losing = ref(false);
const converting = ref(false);
const quoting = ref(false);
async function unlink(qt) {
  if (!(await confirm({ title: { ar: `فك ربط عرض السعر ${qt.number} عن الفرصة؟`, en: `Unlink quotation ${qt.number}?` }, sub: { ar: 'العرض نفسه يبقى في المبيعات كما هو', en: 'The quotation itself stays on the sales screens' }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(url(`/quotations/${encodeURIComponent(qt.number)}/unlink`)), { success: t('فُك الربط', 'Unlinked') });
}

// ---- the timeline
function eventText(e) {
  const d = e.data || {};
  const from = e.fromStage ? nameOf(e.fromStage) : null;
  const to = e.toStage ? nameOf(e.toStage) : null;
  switch (e.kind) {
    case 'stage': return from && to ? t(`من «${from}» إلى «${to}»`, `from "${from}" to "${to}"`) : to ? `«${to}»` : '';
    case 'won': return from && to && from !== to ? t(`من «${from}» إلى «${to}»`, `from "${from}" to "${to}"`) : '';
    case 'lost': return [d.reasonAr || d.reasonEn ? t(`السبب: ${d.reasonAr || d.reasonEn}`, `Reason: ${d.reasonEn || d.reasonAr}`) : '', from ? t(`في مرحلة «${from}»`, `in stage "${from}"`) : ''].filter(Boolean).join(' · ');
    case 'reopened': return [d.from ? t(`كانت ${bi(LEAD_STATUS[d.from] || ARCHIVED_CHIP)}`, `was ${(LEAD_STATUS[d.from] || ARCHIVED_CHIP).en.toLowerCase()}`) : '', to ? t(`إلى «${to}»`, `into "${to}"`) : ''].filter(Boolean).join(' · ');
    case 'assigned': return t(`${d.from || 'بلا مندوب'} ← ${d.to || 'بلا مندوب'}`, `${d.from || 'unassigned'} → ${d.to || 'unassigned'}`);
    case 'converted': return `${t(d.customerNameAr, d.customerNameEn || d.customerNameAr) || ''} (${d.customerCode || ''}) — ${bi(MATCH_BY[d.by] || MATCH_BY.person)}`;
    case 'quotation': return `${d.number || ''} — ${d.action === 'created' ? t('أُنشئ', 'created') : d.action === 'unlinked' ? t('فُك ربطه', 'unlinked') : t('رُبط', 'linked')}`;
    case 'activity': return `${activityTypeLabel(d.type)}: ${d.summary || ''}`;
    default: return '';
  }
}
const timeline = computed(() => (lead.value?.events || []).map((e) => ({ at: e.at, by: e.by, label: eventLabel(e.kind), color: eventLabel(e.kind).fg, text: eventText(e), note: e.note, origin: e.origin })));
const address = computed(() => [lead.value?.street, lead.value?.street2, lead.value?.city, lead.value?.region, lead.value?.zip, lead.value?.country].filter(Boolean).join('، '));
const sourceLine = computed(() => [lead.value?.source, lead.value?.medium, lead.value?.campaign, lead.value?.referredBy].filter(Boolean).join(' · '));
const webHref = computed(() => { const w = lead.value?.website; return w && /^https?:\/\//i.test(w) ? w : null; });
</script>

<template>
  <Drawer :open="isOpen" :width="640" @close="emit('close')">
    <template #title><span dir="auto">{{ lead ? lead.name : number }}</span></template>
    <template #sub>
      <span v-if="lead"><span class="num ltr inline-block">{{ lead.number }}</span><template v-if="lead.externalRef"> · <span class="num ltr inline-block">Odoo #{{ lead.externalRef }}</span></template><template v-if="lead.companyName || lead.contactName"> · <span dir="auto">{{ lead.companyName || lead.contactName }}</span></template></span>
    </template>
    <template v-if="lead" #headExtra><Chip :map="LEAD_STATUS" :k="lead.status" /></template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="lead">
      <div v-if="lead.owned" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }}</div>
      <div v-if="lead.sourceDeletedAt" class="hint amber !mt-0 mb-3">{{ t('هذه الفرصة حُذفت من أودو — محفوظة هنا بسجلها.', 'This opportunity was deleted in Odoo — kept here with its history.') }}</div>

      <!-- where it stands -->
      <div class="row wrap !gap-1.5">
        <button v-for="s in stagePills" :key="s.id" type="button" class="pill" :class="{ active: s.id === lead.stage?.id, '!cursor-default': !can.move || s.id === lead.stage?.id }"
                :style="s.id === lead.stage?.id && lead.status === 'won' ? { background: '#1d7a3e', borderColor: '#1d7a3e' } : s.id === lead.stage?.id && lead.status === 'lost' ? { background: '#b23b3b', borderColor: '#b23b3b' } : null"
                :disabled="act.pending.value" :title="can.move && s.id !== lead.stage?.id ? t('نقل إلى هذه المرحلة', 'Move to this stage') : null" @click="moveTo(s)">
          {{ nameOf(s) }}<span v-if="s.isWon" class="text-[9px]">✓</span>
        </button>
      </div>
      <div class="row wrap mt-2.5 !gap-1.5">
        <Chip v-if="lead.archived" :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <Chip v-if="lead.kind === 'lead'" :label="KIND_CHIP" :fg="KIND_CHIP.fg" :bg="KIND_CHIP.bg" />
        <Chip v-if="lead.owned" :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
        <Chip v-for="tg in lead.tags" :key="tg.id" :label="tg.name" :fg="tagColor(tg.color).fg" :bg="tagColor(tg.color).bg" />
        <span v-if="lead.priority" class="text-[12px] text-warn" :title="bi(PRIORITY[lead.priority])">{{ stars(lead.priority) }}</span>
      </div>
      <div v-if="lead.status === 'lost'" class="hint amber">
        <b>{{ t('سبب الخسارة', 'Lost reason') }}:</b> {{ lead.lostReason ? nameOf(lead.lostReason) : t('لم يُسجَّل سبب', 'no reason recorded') }}<template v-if="lead.lostNote"> — <span dir="auto">{{ lead.lostNote }}</span></template>
      </div>

      <div class="mt-3 grid grid-cols-4 gap-2 max-[520px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ Number(lead.expectedRevenue) > 0 ? fmtMoney(lead.expectedRevenue) : '—' }}</div><div class="tile-l">{{ t('الإيراد المتوقع (ر.س)', 'Expected revenue (SAR)') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ pct(lead.probability) }}</div><div class="tile-l">{{ t('احتمال الكسب', 'Chance of winning') }}</div></div>
        <div class="tile" :class="lead.nextActivity?.overdue ? 'red' : lead.nextActivity?.today ? 'amber' : ''">
          <div class="tile-v !text-[13px]">{{ lead.nextActivity ? dueLabel(lead.nextActivity) : '—' }}</div>
          <div class="tile-l">{{ lead.nextActivity ? `${t('النشاط القادم', 'Next to-do')}: ${activityTypeLabel(lead.nextActivity.type)}${lead.nextActivity.overdue ? ` — ${t('متأخر', 'overdue')}` : ''}` : t('لا نشاط مخطط', 'Nothing planned') }}</div>
        </div>
        <div class="tile" :class="lead.customer ? 'green' : ''">
          <div class="tile-v !font-sans !text-[11.5px] !font-extrabold" style="direction: inherit">{{ lead.customer ? nameOf(lead.customer) : '—' }}</div>
          <div class="tile-l">{{ lead.customer ? `${t('العميل', 'Customer')} ${lead.customer.code}` : t('ليس عميلًا بعد', 'Not a customer yet') }}</div>
        </div>
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5 mt-4" />

      <!-- who it is and what it is worth -->
      <template v-if="pane === 'details'">
        <div class="kv-grid">
          <KV :k="{ ar: 'المنشأة', en: 'Company' }"><span dir="auto">{{ lead.companyName || '—' }}</span></KV>
          <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }"><span dir="auto">{{ [lead.contactName, lead.jobTitle].filter(Boolean).join(' — ') || '—' }}</span></KV>
          <KV :k="{ ar: 'الجوال', en: 'Mobile' }" ltr><a v-if="lead.mobile" :href="`tel:${lead.mobile}`">{{ lead.mobile }}</a><template v-else>—</template></KV>
          <KV :k="{ ar: 'الهاتف', en: 'Phone' }" ltr><a v-if="lead.phone" :href="`tel:${lead.phone}`">{{ lead.phone }}</a><template v-else>—</template></KV>
          <KV :k="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" ltr><a v-if="lead.email" :href="`mailto:${lead.email}`" class="break-all">{{ lead.email }}</a><template v-else>—</template></KV>
          <KV :k="{ ar: 'الموقع الإلكتروني', en: 'Website' }" ltr><a v-if="webHref" :href="webHref" target="_blank" rel="noopener noreferrer" class="break-all">{{ lead.website }}</a><span v-else class="break-all">{{ lead.website || '—' }}</span></KV>
          <KV :k="{ ar: 'العنوان', en: 'Address' }"><span dir="auto">{{ address || '—' }}</span></KV>
          <KV :k="{ ar: 'الرقم الضريبي', en: 'VAT number' }" :v="lead.vatNo || '—'" ltr />
          <KV :k="{ ar: 'العميل', en: 'Customer' }">
            <RouterLink v-if="lead.customer" :to="`/sales?tab=cust&q=${encodeURIComponent(lead.customer.code)}`">{{ nameOf(lead.customer) }} <span class="num ltr">({{ lead.customer.code }})</span></RouterLink>
            <template v-else>{{ t('ليس عميلًا بعد', 'Not a customer yet') }}</template>
          </KV>
          <KV :k="{ ar: 'المندوب', en: 'Salesperson' }"><span dir="auto">{{ lead.owner ? nameOf(lead.owner) : t('بلا مندوب', 'Unassigned') }}</span><span v-if="lead.owner?.external" class="text-[9px] font-normal text-faint"> · {{ t('بالاسم من أودو', 'by name, from Odoo') }}</span></KV>
          <KV :k="{ ar: 'فريق المبيعات', en: 'Sales team' }"><span dir="auto">{{ lead.team ? nameOf(lead.team) : '—' }}</span></KV>
          <KV :k="{ ar: 'الأولوية', en: 'Priority' }" :v="bi(PRIORITY[lead.priority] || PRIORITY[0])" />
          <KV :k="{ ar: 'المصدر', en: 'Source' }"><span dir="auto">{{ sourceLine || '—' }}</span></KV>
          <KV :k="{ ar: 'الإغلاق المتوقع', en: 'Expected closing' }" :v="fmtDay(lead.deadline)" ltr />
          <KV :k="{ ar: 'أُنشئت', en: 'Created' }"><span class="num ltr">{{ fmtDate(lead.createdAt) }}</span><span v-if="lead.createdBy" class="text-[9px] font-normal text-faint" dir="auto"> · {{ lead.createdBy }}</span></KV>
          <KV :k="{ ar: 'آخر انتقال بين المراحل', en: 'Last stage change' }" :v="fmtDate(lead.stageChangedAt)" ltr />
          <KV v-if="lead.closedAt" :k="lead.status === 'won' ? { ar: 'كُسبت في', en: 'Won on' } : { ar: 'أُغلقت في', en: 'Closed on' }" :v="fmtDate(lead.closedAt)" ltr />
          <KV v-if="lead.convertedAt" :k="{ ar: 'حُدِّد عميلها', en: 'Customer set' }"><span class="num ltr">{{ fmtDate(lead.convertedAt) }}</span><span class="text-[9px] font-normal text-faint"> · {{ lead.convertedBy }}</span></KV>
        </div>
        <div v-if="lead.description" class="mt-3.5">
          <div class="field-l mb-1">{{ t('ملاحظات الفرصة', 'Opportunity notes') }}</div>
          <div class="whitespace-pre-wrap rounded-[12px] bg-soft px-3 py-2.5 text-[11px] leading-[1.9] text-sec [overflow-wrap:anywhere]" dir="auto">{{ lead.description }}</div>
        </div>
      </template>

      <!-- to-dos -->
      <template v-else-if="pane === 'activities'">
        <div v-if="canManage" class="row mb-2"><span class="grow" /><Btn size="sm" tone="softPurple" :label="{ ar: '+ نشاط', en: '+ To-do' }" @click="todoEditing = null; todoForm = true" /></div>
        <div v-if="!lead.activities.length" class="empty !p-4">{{ t('لا أنشطة على هذه الفرصة بعد.', 'No to-do on this opportunity yet.') }}</div>
        <div class="col !gap-1.5">
          <div v-for="a in lead.activities" :key="a.id" class="rounded-[12px] border px-3 py-2" :class="a.overdue ? 'border-[#F3C4C4] bg-[#FFF8F8]' : 'border-line-2'">
            <div class="flex flex-wrap items-center gap-2">
              <Chip small :label="activityTypeLabel(a.type)" :fg="(ACTIVITY_TYPE[a.type] || {}).fg" :bg="(ACTIVITY_TYPE[a.type] || {}).bg" />
              <span class="min-w-0 flex-1 text-[11px] font-extrabold text-ink [overflow-wrap:anywhere]" :class="{ 'line-through opacity-60': a.status === 'cancelled' }" dir="auto">{{ a.summary }}</span>
              <span class="num text-[10px]" :class="a.overdue ? 'font-extrabold text-bad' : a.today ? 'font-extrabold text-warn' : 'text-muted'">{{ a.overdue ? t('متأخر', 'overdue') + ' · ' : '' }}{{ dueLabel(a) }}</span>
              <Chip v-if="a.status !== 'open'" small :map="ACTIVITY_STATUS" :k="a.status" />
            </div>
            <div class="mt-1 text-[9.5px] text-muted" dir="auto">{{ a.user ? nameOf(a.user) : '—' }}<template v-if="a.note"> · {{ a.note }}</template></div>
            <div v-if="a.status === 'done'" class="mt-1 text-[10px] text-ok" dir="auto">✓ {{ a.doneBy }} · <span class="num ltr">{{ fmtDate(a.doneAt) }}</span><template v-if="a.doneNote"> — {{ a.doneNote }}</template></div>
            <div v-else-if="a.status === 'cancelled'" class="mt-1 text-[10px] text-muted" dir="auto">{{ a.cancelledBy }} · <span class="num ltr">{{ fmtDate(a.cancelledAt) }}</span><template v-if="a.cancelReason"> — {{ a.cancelReason }}</template></div>
            <div v-else-if="canManage" class="row mt-1.5 !gap-1.5">
              <Btn size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'تم', en: 'Done' }" @click="done(a)" />
              <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click="todoEditing = a; todoForm = true" />
              <Btn size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="cancelTodo(a)" />
            </div>
          </div>
        </div>
      </template>

      <!-- what happened to it, newest first — and the notes people add -->
      <template v-else-if="pane === 'timeline'">
        <div v-if="canManage" class="mb-3">
          <TextArea v-model="noteText" :rows="2" :placeholder="{ ar: 'اكتب ملاحظة: ما قاله العميل، ما اتُّفق عليه…', en: 'Write a note: what the customer said, what was agreed…' }" />
          <div class="row mt-1.5"><span class="grow" /><Btn size="sm" tone="dark" :loading="act.pending.value" :disabled="!noteText.trim()" :label="{ ar: 'إضافة الملاحظة', en: 'Add the note' }" @click="addNote" /></div>
        </div>
        <Timeline :items="timeline" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }">
          <template #chip="{ item }"><span v-if="item.origin === 'odoo'" class="text-[8.5px] font-bold text-violet">{{ t('في أودو', 'in Odoo') }}</span></template>
          <template #note="{ item }">
            <div v-if="item.text" dir="auto">{{ item.text }}</div>
            <div v-if="item.note" class="whitespace-pre-wrap text-ink [overflow-wrap:anywhere]" dir="auto">{{ item.note }}</div>
          </template>
        </Timeline>
      </template>

      <!-- quotations that answer it -->
      <template v-else>
        <div v-if="!lead.customer" class="hint !mt-0">{{ lead.status === 'won'
          ? t('عرض السعر يصدر لعميل: حوّل الفرصة إلى عميل أولًا.', 'A quotation is made for a customer: convert the opportunity to a customer first.')
          : t('عرض السعر يصدر لعميل: اربط الفرصة بعميل قائم من «تعديل»، أو حوّلها إلى عميل بعد كسبها.', 'A quotation is made for a customer: link the opportunity to an existing customer in "Edit", or convert it once it is won.') }}</div>
        <div v-else-if="!lead.quotations.length" class="empty !p-4">{{ t('لا عروض أسعار لهذه الفرصة بعد.', 'No quotation for this opportunity yet.') }}</div>
        <div class="col !gap-1.5">
          <div v-for="qt in lead.quotations" :key="qt.id" class="row wrap rounded-[12px] border border-line-2 px-3 py-2">
            <RouterLink :to="`/sales?tab=qt&q=${encodeURIComponent(qt.number)}`" class="cell-id min-w-[110px]">{{ qt.number }}</RouterLink>
            <span class="cell-date">{{ fmtDay(qt.validUntil) }}</span>
            <span class="num ltr text-[11px]">{{ qt.totals ? fmtMoney(qt.totals.total) : '—' }}</span>
            <span v-if="qt.validity?.expired" class="text-[9px] font-extrabold text-bad">{{ t('انتهت الصلاحية', 'Validity passed') }}</span>
            <span v-else-if="qt.discount?.needsApproval" class="text-[9px] font-extrabold text-warn">{{ t('خصم بانتظار الاعتماد', 'Discount awaiting approval') }}</span>
            <span class="grow" />
            <Chip small :map="QT_LABELS" :k="qt.status" />
            <Btn v-if="canManage" size="sm" tone="ghost" :label="{ ar: 'فك الربط', en: 'Unlink' }" @click="unlink(qt)" />
          </div>
        </div>
        <div v-if="can.quote" class="row mt-2.5"><span class="grow" /><Btn size="sm" tone="softPurple" :label="{ ar: '+ عرض سعر', en: '+ Quotation' }" @click="quoting = true" /></div>
      </template>
    </template>

    <template v-if="lead && canManage" #footer>
      <div class="row wrap">
        <Btn v-if="can.win" tone="success" :loading="act.pending.value" :label="{ ar: 'كسب', en: 'Won' }" @click="win" />
        <Btn v-if="can.lose" tone="dangerOutline" :label="{ ar: 'خسارة', en: 'Lost' }" @click="losing = true" />
        <Btn v-if="can.reopen" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إعادة فتح', en: 'Reopen' }" @click="reopen" />
        <Btn v-if="can.edit && lead.kind === 'lead'" tone="softBlue" :loading="act.pending.value" :label="{ ar: 'تأهيل إلى فرصة', en: 'Qualify as opportunity' }" @click="qualify" />
        <Btn v-if="can.convert" tone="primary" :label="{ ar: 'تحويل إلى عميل', en: 'Convert to customer' }" @click="converting = true" />
        <Btn v-if="can.quote" tone="softPurple" :label="{ ar: '+ عرض سعر', en: '+ Quotation' }" @click="quoting = true" />
        <Btn tone="soft" :label="{ ar: '+ نشاط', en: '+ To-do' }" @click="todoEditing = null; todoForm = true" />
        <span class="grow" />
        <Btn v-if="can.edit" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', lead)" />
      </div>
    </template>
  </Drawer>

  <ActivityForm :open="todoForm" :lead="lead" :activity="todoEditing" @close="todoForm = false" />
  <LeadLose :lead="losing ? lead : null" @close="losing = false" />
  <ConvertDrawer :lead="converting ? lead : null" @close="converting = false" />
  <LeadQuotation :lead="quoting ? lead : null" @close="quoting = false" />
</template>
