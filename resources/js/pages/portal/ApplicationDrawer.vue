<script setup>
// One onboarding application: the company, what it still lacks, who it would be in OPS, its papers, its timeline — and
// the review: send for review, ask for a correction, approve (the company becomes a customer), refuse, expire, reopen.
//   <ApplicationDrawer :id="idOrNull" @close="…" @changed="…" />
// GET /portal/applications/{id} → the application + { documents, events, checks, match, can }. The buttons follow
// `can` — what the server would accept now; an application that Odoo owns offers only notes and papers.
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Tabs, TextArea, Timeline } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ApplicationApprove from './ApplicationApprove.vue';
import ApplicationForm from './ApplicationForm.vue';
import ApplicationPapers from './ApplicationPapers.vue';
import { APPLICATION_STATUS, CONTACT_ROLES, CUSTOMER_BY, LEGAL_TYPES, ODOO_APPLICATION_STATE, OWNED_CHIP, customerLine, eventItem, labelOf, moment } from './portal';

const props = defineProps({
  /** Id (or OPS number) of the application; null closes the drawer. */
  id: { type: String, default: null },
});
const emit = defineEmits(['close', 'changed']);

const auth = useAuth();
const canManage = computed(() => auth.can('portal.manage'));
const base = computed(() => (props.id ? `/portal/applications/${encodeURIComponent(props.id)}` : null));
const q = useGet(base);
const a = computed(() => (props.id ? q.data.value || null : null));
const act = useAction({ invalidate: ['portal'] });
const events = computed(() => (a.value?.events || []).map(eventItem));
const address = computed(() => (a.value ? [[a.value.buildingNo, a.value.street].filter(Boolean).join(' '), a.value.district, a.value.city, a.value.region, a.value.postalCode].filter(Boolean).join('، ') : ''));

const pane = ref('data');
watch(() => props.id, () => { pane.value = 'data'; noteText.value = ''; });
const panes = computed(() => [
  { k: 'data', label: { ar: 'البيانات', en: 'Particulars' } },
  { k: 'papers', label: { ar: 'المستندات', en: 'Papers' }, badge: a.value?.documentsCount || null },
  { k: 'history', label: { ar: 'السجل والملاحظات', en: 'History & notes' } },
]);
function done() { q.refetch(); emit('changed'); }

async function move(action, body, success) {
  const r = await act.run(() => api.postIdempotent(`${base.value}/${action}`, body), { success });
  if (r) done();
}
async function submit() {
  if (!(await confirm({ title: { ar: `تقديم الطلب ${a.value.number} للمراجعة؟`, en: `Send application ${a.value.number} for review?` }, okLabel: { ar: 'تقديم', en: 'Send' }, tone: 'dark' }))) return;
  await move('submit', {}, t('قُدّم الطلب للمراجعة', 'Sent for review'));
}
async function requestCorrection() {
  const note = await ask({ title: { ar: 'ما الذي يجب على العميل تصحيحه؟', en: 'What must the customer correct?' }, sub: { ar: 'يعود الطلب إلى العميل ليكمله، ثم يُقدَّم من جديد.', en: 'The application goes back to the customer to complete, then it is sent again.' },
    label: { ar: 'المطلوب تصحيحه', en: 'What to correct' }, required: true, okLabel: { ar: 'طلب التصحيح', en: 'Request the correction' } });
  if (note === null) return;
  await move('request-correction', { note }, t('طُلب التصحيح', 'Correction requested'));
}
async function reject() {
  const reason = await ask({ title: { ar: `رفض الطلب ${a.value.number}؟`, en: `Reject application ${a.value.number}?` }, sub: { ar: 'يُبلَّغ العميل بالسبب. يمكن إعادة فتح الطلب لاحقًا.', en: 'The customer is given the reason. The application can be reopened later.' },
    label: { ar: 'سبب الرفض', en: 'Reason' }, required: true, okLabel: { ar: 'رفض', en: 'Reject' }, tone: 'danger' });
  if (reason === null) return;
  await move('reject', { reason }, t('رُفض الطلب', 'Application rejected'));
}
async function expire() {
  const reason = await ask({ title: { ar: `إنهاء صلاحية الطلب ${a.value.number}؟`, en: `Expire application ${a.value.number}?` }, sub: { ar: 'للطلب الذي لم يكمله العميل. يمكن إعادة فتحه لاحقًا.', en: 'For an application the customer never completed. It can be reopened later.' },
    label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إنهاء الصلاحية', en: 'Expire' }, tone: 'danger' });
  if (reason === null) return;
  await move('expire', reason ? { reason } : {}, t('انتهت صلاحية الطلب', 'Application expired'));
}
async function reopen() {
  if (!(await confirm({ title: { ar: `إعادة فتح الطلب ${a.value.number}؟`, en: `Reopen application ${a.value.number}?` }, sub: { ar: 'يعود مسودة، وما تقرّر سابقًا يبقى في سجله.', en: 'It becomes a draft again; what was decided before stays on its history.' }, okLabel: { ar: 'إعادة الفتح', en: 'Reopen' }, tone: 'dark' }))) return;
  await move('reopen', {}, t('أُعيد فتح الطلب', 'Application reopened'));
}

const noteText = ref('');
async function addNote() {
  const r = await act.run(() => api.postIdempotent(`${base.value}/notes`, { text: noteText.value.trim() }), { success: t('أُضيفت الملاحظة', 'Note added') });
  if (r) { noteText.value = ''; done(); }
}

const editing = ref(false);
const approving = ref(false);
/** The review offers something now (an approved application, or one Odoo owns, offers nothing but notes and papers). */
const hasActions = computed(() => !!a.value && ['approve', 'submit', 'requestCorrection', 'edit', 'reject', 'expire', 'reopen'].some((k) => a.value.can[k]));
</script>

<template>
  <Drawer :open="!!id" :width="700" @close="emit('close')">
    <template #title><span dir="auto">{{ a ? a.companyNameAr || a.companyNameEn || a.number : t('طلب فتح حساب', 'Onboarding application') }}</span></template>
    <template v-if="a" #sub><span class="num ltr">{{ a.number }}</span><template v-if="a.externalRef"> · <span class="num ltr">Odoo #{{ a.externalRef }}</span></template> · <span class="num ltr">{{ moment(a.createdAt) }}</span></template>
    <template v-if="a" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="APPLICATION_STATUS" :k="a.status" />
        <Chip v-if="a.sourceSystem === 'odoo'" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="a">
      <div v-if="a.owned" class="hint purple !mt-0 mb-3">
        {{ t('هذا الطلب يُدار من أودو: بياناته وقراره هناك، ويصل ما يتغيّر إلى هنا تلقائيًا. تُضاف هنا الملاحظات والمستندات.', 'This application is managed in Odoo: its particulars and its decision are made there and arrive here by themselves. Notes and papers are added here.') }}
        <template v-if="a.sourceState"> {{ t('حالته في أودو:', 'Its state in Odoo:') }} <b>{{ labelOf(ODOO_APPLICATION_STATE, a.sourceState) }}</b>.</template>
      </div>
      <div v-if="a.status === 'correction' && a.correctionNote" class="hint amber !mt-0 mb-3"><b>{{ t('المطلوب من العميل تصحيحه:', 'What the customer was asked to correct:') }}</b> {{ a.correctionNote }}</div>
      <div v-if="a.status === 'rejected' && a.rejectionReason" class="hint amber !mt-0 mb-3"><b>{{ t('سبب الرفض:', 'Rejected because:') }}</b> {{ a.rejectionReason }}</div>
      <div v-if="a.customer" class="hint green !mt-0 mb-3">
        {{ t('العميل في نظام العمليات:', 'The customer in OPS:') }}
        <RouterLink v-if="a.customer.linked" :to="`/sales?tab=cust&q=${encodeURIComponent(a.customer.code)}`" class="font-extrabold" dir="auto">{{ customerLine(a.customer) }}</RouterLink>
        <b v-else dir="auto">{{ customerLine(a.customer) }} — {{ t('في أودو فقط حتى الآن', 'in Odoo only so far') }}</b>
        <template v-if="a.customerBy"> · {{ labelOf(CUSTOMER_BY, a.customerBy) }}</template>
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-3" />

      <template v-if="pane === 'data'">
        <!-- what it still lacks: the blocking ones stop a submission or an approval, the others are for the reviewer's eye -->
        <div v-if="a.match" class="mb-3 flex flex-wrap gap-1.5">
          <span v-for="c in a.checks" :key="c.key" class="rounded-[8px] px-2 py-[3px] text-[10px] font-bold" :class="c.ok ? 'bg-[#e6f9ec] text-ok' : c.blocking ? 'bg-[#fdecec] text-bad' : 'bg-[#fbf0dd] text-warn'">{{ c.ok ? '✓' : '✕' }} {{ t(c.ar, c.en) }}</span>
        </div>
        <div v-if="a.match" class="mb-3 rounded-xl border border-line-2 px-3 py-2 text-[10.5px] leading-[1.9]">
          <template v-if="a.match.needs === 'nothing'">{{ t('عند الاعتماد يُربط بالعميل', 'On approval it is linked to customer') }} <b dir="auto">{{ customerLine(a.match.customer) }}</b> <span class="text-muted">({{ labelOf(CUSTOMER_BY, a.match.by) }})</span> — {{ t('لا يُنشأ عميل جديد.', 'no new customer is made.') }}</template>
          <template v-else-if="a.match.needs === 'conflict'"><span class="font-extrabold text-warn">{{ t('الرقم الضريبي والسجل التجاري يدلان على عميلين مختلفين — يحدّد المراجع أيهما عند الاعتماد.', 'The VAT number and the registration name two different customers — the reviewer says which on approval.') }}</span></template>
          <template v-else-if="a.match.candidates.length">{{ t(`لا رقم يدل على عميل موجود. ${a.match.candidates.length} عميل قد يكون أحدهم صاحب الطلب — يُعرضون عند الاعتماد.`, `No number names an existing customer. ${a.match.candidates.length} customer(s) may be the applicant — they are shown on approval.`) }}</template>
          <template v-else>{{ t('لا عميل موجود يحمل رقمها أو اسمها: عند الاعتماد يُنشأ عميل جديد بعد تأكيد المراجع.', 'No existing customer carries its numbers or its name: on approval a new customer is made once the reviewer confirms.') }}</template>
        </div>

        <div class="kv-grid">
          <KV :k="{ ar: 'اسم المنشأة', en: 'Company' }" :v="a.companyNameAr" />
          <KV :k="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" :v="a.companyNameEn" ltr />
          <KV :k="{ ar: 'الكيان القانوني', en: 'Legal entity' }" :v="a.legalEntityType ? labelOf(LEGAL_TYPES, a.legalEntityType) : null" />
          <KV :k="{ ar: 'السجل التجاري', en: 'Commercial registration' }" :v="a.cr" ltr />
          <KV :k="{ ar: 'الرقم الضريبي', en: 'VAT number' }" :v="a.vatNo" ltr />
          <KV :k="{ ar: 'الرقم الوطني الموحد', en: 'Unified number' }" :v="a.unifiedNo" ltr />
          <KV :k="{ ar: 'المالك', en: 'Owner' }" :v="a.ownerName" />
          <KV :k="{ ar: 'رقم هوية المالك', en: 'Owner’s identity no.' }">
            <span v-if="a.ownerNationalId" class="num ltr">{{ a.ownerNationalId }}</span><span v-else class="text-faint">—</span>
            <div v-if="a.ownerNationalIdMasked" class="text-[9px] font-normal text-faint">{{ t('يظهر كاملًا لمن يبتّ في الطلبات', 'shown in full to who decides applications') }}</div>
          </KV>
          <KV :k="{ ar: 'المدير المسؤول', en: 'Manager' }" :v="a.managerName" />
          <KV :k="{ ar: 'الجوال', en: 'Mobile' }" :v="a.mobile" ltr />
          <KV :k="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" :v="a.email" ltr />
          <KV :k="{ ar: 'الشروط والأحكام', en: 'Terms' }">
            <span v-if="a.termsAccepted" class="text-ok">{{ t('وافق العميل', 'Accepted') }}</span><span v-else class="text-warn">{{ t('لم يوافق بعد', 'Not accepted yet') }}</span>
            <div v-if="a.termsAcceptedAt" class="num ltr text-[9px] font-normal text-faint">{{ moment(a.termsAcceptedAt) }}<template v-if="a.termsVersion"> · {{ a.termsVersion }}</template></div>
          </KV>
          <KV class="col-span-full" :k="{ ar: 'العنوان الوطني', en: 'National address' }" :v="address || null" />
        </div>

        <template v-if="a.contacts.length">
          <div class="card-title mb-1.5 mt-4">{{ t('مسؤولو المنشأة', 'The company’s people') }}</div>
          <div class="overflow-hidden rounded-xl border border-line-2">
            <div v-for="(c, i) in a.contacts" :key="i" class="flex flex-wrap items-center gap-2 border-t border-line-2 px-3 py-2 text-[10.5px] first:border-t-0">
              <span class="w-[110px] flex-none font-extrabold text-violet">{{ labelOf(CONTACT_ROLES, c.role) }}</span>
              <span class="min-w-0 flex-1 truncate font-bold" dir="auto">{{ c.name }}</span>
              <span v-if="c.mobile" class="num ltr text-muted">{{ c.mobile }}</span><span v-if="c.email" class="num ltr text-muted">{{ c.email }}</span>
            </div>
          </div>
        </template>
      </template>

      <ApplicationPapers v-else-if="pane === 'papers'" :application="a" @changed="done" />

      <template v-else>
        <div v-if="canManage" class="mb-3">
          <TextArea v-model="noteText" :rows="2" :label="{ ar: 'ملاحظة على الطلب', en: 'A note on the application' }" :placeholder="{ ar: 'ما جرى مع العميل، ما ينتظره الطلب…', en: 'What was agreed with the customer, what the application waits for…' }" />
          <div class="mt-1.5"><Btn size="sm" tone="softPurple" :disabled="!noteText.trim()" :loading="act.pending.value" :label="{ ar: 'إضافة الملاحظة', en: 'Add the note' }" @click="addNote" /></div>
        </div>
        <Timeline :items="events" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }" />
      </template>
    </template>

    <template v-if="a && canManage && hasActions" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="a.can.approve" tone="success" :label="{ ar: 'اعتماد — يصبح عميلًا', en: 'Approve — becomes a customer' }" @click="approving = true" />
        <Btn v-if="a.can.submit" tone="primary" :loading="act.pending.value" :label="{ ar: 'تقديم للمراجعة', en: 'Send for review' }" @click="submit" />
        <Btn v-if="a.can.requestCorrection" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'طلب تصحيح', en: 'Request a correction' }" @click="requestCorrection" />
        <Btn v-if="a.can.edit" tone="dark" :label="{ ar: 'تعديل البيانات', en: 'Edit' }" @click="editing = true" />
        <Btn v-if="a.can.reject" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
        <Btn v-if="a.can.expire" tone="soft" :loading="act.pending.value" :label="{ ar: 'إنهاء الصلاحية', en: 'Expire' }" @click="expire" />
        <Btn v-if="a.can.reopen" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'إعادة فتح', en: 'Reopen' }" @click="reopen" />
      </div>
    </template>
  </Drawer>

  <ApplicationForm :open="editing" :application="a" @close="editing = false" @done="() => { editing = false; done(); }" />
  <ApplicationApprove :application="approving ? a : null" @close="approving = false" @done="done" />
</template>
