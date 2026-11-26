<script setup>
// One approval request: what is asked for, who asked, where it stands with each approver, its lines and files, and its
// story from the first draft to the last decision — with the actions this user may take right now.
//   <RequestDrawer :number="numberOrNull" @close="…" @edit="…" />
// GET /approvals/requests/{number} answers `can` (what the server will accept from this user now) and `decisionBlock`
// (why an approver cannot decide yet): the buttons follow them, so there is no button that ends in a refusal.
// A request that came from Odoo and is still managed there (`owned`) is shown, takes comments and files, and says where
// its decision is taken.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, SelectInput, TextArea, Timeline } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import AttachmentsBox from './AttachmentsBox.vue';
import {
  EVENT_LABELS, MODE_LABELS, ORIGIN_LABELS, PARTNER_TYPES, PAYMENT_METHODS, REQUEST_STATUS, SOURCE_CHIP, TONE, catName, eventDetail, fmtAmount, fmtAt, fmtDay, fmtQty, lineChip, own, personName,
  progressText, useLookups,
} from './approvals';

const props = defineProps({
  /** The request's number (or id); null closes the drawer. */
  number: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit']);

const q = useGet(() => (props.number ? `/approvals/requests/${encodeURIComponent(props.number)}` : null));
const r = computed(() => (props.number ? q.data.value || null : null));
// every action invalidates the section: the lists, the counts of the page head and this request are read again
const act = useAction({ invalidate: ['approvals'] });
const path = (suffix) => `/approvals/requests/${encodeURIComponent(r.value.number)}/${suffix}`;
const post = (suffix, body, success) => act.run(() => api.postIdempotent(path(suffix), body), { success });

const fields = computed(() => r.value?.category?.fields || {});
const uses = (field) => fields.value[field] && fields.value[field] !== 'no';
const activeLines = computed(() => (r.value?.approvers || []).filter((a) => a.status !== 'removed'));
const removedLines = computed(() => (r.value?.approvers || []).filter((a) => a.status === 'removed'));
const myTurn = computed(() => !!r.value?.can?.approve);
const odooMeta = computed(() => (r.value?.source === 'odoo' ? r.value.sourceMeta || {} : null));
const auth = useAuth();
/** The same request in the archive of everything Odoo holds: its conversation there and every field as Odoo has it. */
const archiveLink = computed(() => (odooMeta.value && r.value.externalRef && auth.can('page.odoo') ? { path: '/odoo', query: { kind: 'approval_request', doc: `approval_request:${r.value.externalRef}` } } : null));

// ---- the approver decides
async function approve() {
  const note = await ask({ title: { ar: `اعتماد الطلب ${r.value.number}؟`, en: `Approve request ${r.value.number}?` }, sub: r.value.title, label: { ar: 'تعليق (اختياري)', en: 'Comment (optional)' }, okLabel: { ar: 'اعتماد', en: 'Approve' }, tone: 'primary' });
  if (note === null) return;
  await post('approve', note ? { comment: note } : {}, t('سُجّل اعتمادك ✓', 'Your approval was recorded ✓'));
}
async function refuse() {
  const note = await ask({ title: { ar: `رفض الطلب ${r.value.number}؟`, en: `Refuse request ${r.value.number}?` }, sub: { ar: 'رفض واحد يرفض الطلب كله — يصل السبب إلى صاحب الطلب.', en: 'One refusal refuses the whole request — the reason reaches the requester.' }, label: { ar: 'سبب الرفض (3 أحرف على الأقل)', en: 'Reason (3 characters at least)' }, required: true, okLabel: { ar: 'رفض الطلب', en: 'Refuse' }, tone: 'danger' });
  if (note === null) return;
  await post('refuse', { comment: note }, t('سُجّل رفضك', 'Your refusal was recorded'));
}
async function withdraw() {
  const refused = r.value.my.lineStatus === 'refused';
  const note = await ask({ title: refused ? { ar: 'سحب رفضك؟', en: 'Take your refusal back?' } : { ar: 'سحب اعتمادك؟', en: 'Take your approval back?' }, sub: refused ? { ar: 'يعود الطلب إلى الانتظار وتُسأل عنه من جديد.', en: 'The request waits again and you are asked once more.' } : { ar: 'يعود قرارك مطلوبًا، ومن جاء دوره بعدك ينتظر.', en: 'Your decision is needed again, and whoever came after you waits.' }, label: { ar: 'ملاحظة (اختيارية)', en: 'Note (optional)' }, okLabel: { ar: 'سحب القرار', en: 'Take it back' }, tone: 'dark' });
  if (note === null) return;
  await post('withdraw', note ? { comment: note } : {}, t('سُحب قرارك', 'Your decision was taken back'));
}

// ---- the requester (or a manager) moves the request
const submit = () => post('submit', {}, t('أُرسل الطلب للاعتماد', 'The request was sent for approval'));
async function reopen() {
  const pending = r.value.status === 'pending';
  if (!(await confirm({ title: pending ? { ar: 'سحب الطلب للتعديل؟', en: 'Recall the request to change it?' } : { ar: 'إعادة الطلب إلى مسودة؟', en: 'Take the request back to a draft?' }, sub: { ar: 'تُمسح القرارات المسجلة حتى الآن (تبقى في السجل) ويُرسل الطلب من جديد بعد تعديله.', en: 'The decisions given so far are cleared (they stay in the history) and the request is sent again after the change.' }, okLabel: pending ? { ar: 'سحب للتعديل', en: 'Recall' } : { ar: 'إعادة إلى مسودة', en: 'Back to a draft' }, tone: 'dark' }))) return;
  await post('reopen', {}, t('عاد الطلب مسودة', 'The request is a draft again'));
}
async function cancel() {
  const draft = r.value.status === 'draft';
  const reason = await ask({ title: { ar: `إلغاء الطلب ${r.value.number}؟`, en: `Cancel request ${r.value.number}?` }, sub: draft ? null : { ar: 'يبقى الطلب في السجل ملغى، ويُبلَّغ من كان قراره مطلوبًا.', en: 'The request stays on record as cancelled, and whoever was asked is told.' }, label: draft ? { ar: 'السبب (اختياري)', en: 'Reason (optional)' } : { ar: 'سبب الإلغاء (3 أحرف على الأقل)', en: 'Reason (3 characters at least)' }, required: !draft, okLabel: { ar: 'إلغاء الطلب', en: 'Cancel the request' }, cancelLabel: { ar: 'تراجع', en: 'Back' }, tone: 'danger' });
  if (reason === null) return;
  await post('cancel', reason ? { reason } : {}, t('أُلغي الطلب', 'The request was cancelled'));
}

// ---- approvers of this request (the requester on his draft, a manager on a waiting request)
const lookups = useLookups(() => !!r.value?.can?.approvers);
const newApprover = ref('');
const newRequired = ref(false);
const userOptions = computed(() => lookups.userOptions.value.filter((o) => !activeLines.value.some((a) => a.user?.id === o.v)));
async function addApprover() {
  if (!newApprover.value) return;
  const done = await post('approvers', { user: newApprover.value, required: newRequired.value }, t('أُضيف المعتمد', 'Approver added'));
  if (done) { newApprover.value = ''; newRequired.value = false; }
}
async function removeApprover(line) {
  const reason = await ask({ title: { ar: `إزالة ${personName(line.user)} من معتمدي الطلب؟`, en: `Take ${personName(line.user)} off the approvers?` }, sub: r.value.status === 'pending' ? { ar: 'يبقى سطره في السجل ولا يُحتسب، ويُبلَّغ بذلك.', en: 'His line stays on record and no longer counts; he is told.' } : null, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إزالة', en: 'Remove' }, tone: 'danger' });
  if (reason === null) return;
  await post(`approvers/${encodeURIComponent(line.id)}/remove`, reason ? { reason } : {}, t('أُزيل المعتمد', 'Approver removed'));
}
/** A line can go while it is undecided; on a draft only the ones that do not come from the category. */
const removable = (line) => !!r.value?.can?.approvers && (r.value.status === 'draft' ? line.origin !== 'category' : ['new', 'waiting', 'pending'].includes(line.status));

// ---- comments
const comment = ref('');
async function addComment() {
  const text = comment.value.trim();
  if (!text) return;
  if (await post('comments', { text }, t('أُضيف التعليق', 'Comment added'))) comment.value = '';
}
watch(() => props.number, () => { comment.value = ''; newApprover.value = ''; newRequired.value = false; act.clearError(); });

const timeline = computed(() => [...(r.value?.timeline || [])].reverse().map((e) => {
  const label = own(EVENT_LABELS, e.kind) || { ar: e.kind, en: e.kind };
  return { at: e.at, by: e.by ? personName(e.by) : null, label, color: label.color, note: e.note, detail: eventDetail(e), fromOdoo: e.source === 'odoo', kind: e.kind };
}));
const itemColumns = [
  { key: 'lineNo', header: '#', width: '34px', kind: 'num' },
  { key: 'description', header: { ar: 'البند', en: 'Line' }, width: 'minmax(180px,1.6fr)', kind: 'name' },
  { key: 'quantity', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num', value: (i) => fmtQty(i.quantity) },
  { key: 'uom', header: { ar: 'الوحدة', en: 'Unit' }, width: '80px', value: (i) => i.uom || '—' },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'Warehouse' }, width: '120px', value: (i) => i.warehouse || '—' },
];
</script>

<template>
  <Drawer :open="!!number" :width="760" @close="emit('close')">
    <template #title><span class="num ltr inline-block">{{ r ? r.number : number }}</span></template>
    <template #sub><span dir="auto">{{ r ? r.title : '' }}</span></template>
    <template v-if="r" #headExtra>
      <Chip v-if="r.source === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
      <Chip :map="REQUEST_STATUS" :k="r.status" />
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
    <div v-if="q.isLoading.value && !r" class="skel h-[220px]" />

    <template v-if="r">
      <!-- where the request stands for THIS user, and the one thing he can do about it -->
      <div v-if="myTurn" class="next-step amber mb-3">
        <div>
          <div class="text-[12px] font-extrabold">{{ t('هذا الطلب بانتظار قرارك', 'This request is waiting for your decision') }}</div>
          <div class="mt-0.5 text-[10.5px]">{{ t(`من ${personName(r.requester)} — ${catName(r.category)}`, `From ${personName(r.requester)} — ${catName(r.category)}`) }}<template v-if="r.amount != null"> · <span class="num ltr">{{ fmtAmount(r.amount, r.currency) }}</span></template></div>
        </div>
        <div class="next-actions row">
          <Btn tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
          <Btn tone="dangerOutline" :disabled="act.pending.value" :label="{ ar: 'رفض', en: 'Refuse' }" @click="refuse" />
        </div>
      </div>
      <div v-else-if="r.decisionBlock" class="next-step mb-3" :class="r.owned ? 'purple' : 'neutral'">
        <div>
          <div class="text-[12px] font-extrabold">{{ r.owned ? t('قرار هذا الطلب يُتخذ في أودو', 'This request is decided in Odoo') : t('أنت من معتمدي هذا الطلب', 'You are an approver of this request') }}</div>
          <div class="mt-0.5 text-[10.5px] leading-[1.8]">{{ t(r.decisionBlock.messageAr, r.decisionBlock.messageEn) }}</div>
        </div>
      </div>
      <div v-else-if="r.owned" class="hint purple !mt-0 mb-3">
        {{ t('هذا الطلب مصدره أودو وما زال يُدار هناك: يُعرض هنا كما هو ويتحدّث تلقائيًا، ولا يُعدَّل من هنا. يمكنك إضافة تعليق أو مرفق.', 'This request comes from Odoo and is still managed there: it is shown here as it is and follows Odoo by itself; it is not changed here. You can add a comment or a file.') }}
      </div>
      <div v-if="r.status === 'cancelled'" class="banner neutral">
        <div>{{ t('أُلغي هذا الطلب', 'This request was cancelled') }}<template v-if="r.cancelledBy"> — {{ r.cancelledBy }}</template><template v-if="r.cancelledAt"> · <span class="num ltr">{{ fmtAt(r.cancelledAt) }}</span></template><template v-if="r.cancelReason"> · {{ r.cancelReason }}</template></div>
      </div>

      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'النوع', en: 'Category' }" :v="catName(r.category) || '—'" />
        <KV :k="{ ar: 'صاحب الطلب', en: 'Requester' }">{{ personName(r.requester) }}<span v-if="r.requester && !r.requester.linked" class="ms-1 text-[9px] font-normal text-faint">{{ t('(من أودو — بلا مستخدم هنا)', '(of Odoo — no user here)') }}</span></KV>
        <KV v-if="r.createdBy && r.createdBy.id !== r.requester?.id && personName(r.createdBy) !== personName(r.requester)" :k="{ ar: 'أدخله', en: 'Entered by' }" :v="personName(r.createdBy)" />
        <KV v-if="uses('amount') || r.amount != null" :k="{ ar: 'المبلغ', en: 'Amount' }" :v="fmtAmount(r.amount, r.currency)" ltr />
        <KV v-if="uses('date') || r.date" :k="{ ar: 'التاريخ', en: 'Date' }" :v="fmtDay(r.date)" ltr />
        <KV v-if="uses('period') || r.periodStart" :k="{ ar: 'الفترة', en: 'Period' }" ltr>{{ fmtAt(r.periodStart) }} — {{ fmtAt(r.periodEnd) }}</KV>
        <KV v-if="uses('partner') || r.partner" :k="{ ar: 'الجهة', en: 'Partner' }">
          <template v-if="r.partner">{{ r.partner.name }} <span class="text-[9px] font-normal text-faint">{{ bi(own(PARTNER_TYPES, r.partner.type) || '') }}<template v-if="r.partner.code"> · <span class="num ltr">{{ r.partner.code }}</span></template></span></template>
          <template v-else>—</template>
        </KV>
        <KV v-if="uses('reference') || r.reference" :k="{ ar: 'المرجع', en: 'Reference' }" :v="r.reference || '—'" ltr />
        <KV v-if="uses('quantity') || r.quantity != null" :k="{ ar: 'الكمية', en: 'Quantity' }" :v="fmtQty(r.quantity)" ltr />
        <KV v-if="uses('location') || r.location" :k="{ ar: 'المكان', en: 'Location' }" :v="r.location || '—'" />
        <KV v-if="uses('paymentMethod') || r.paymentMethod" :k="{ ar: 'طريقة الدفع', en: 'Payment method' }" :v="r.paymentMethod ? bi(own(PAYMENT_METHODS, r.paymentMethod) || r.paymentMethod) : '—'" />
        <KV :k="{ ar: 'أُنشئ', en: 'Created' }" :v="fmtAt(r.createdAt)" ltr />
        <KV v-if="r.submittedAt" :k="{ ar: 'أُرسل للاعتماد', en: 'Sent' }" :v="fmtAt(r.submittedAt)" ltr />
        <KV v-if="r.decidedAt" :k="r.status === 'refused' ? { ar: 'رُفض', en: 'Refused' } : { ar: 'اعتُمد', en: 'Approved' }" :v="fmtAt(r.decidedAt)" ltr />
        <KV v-if="odooMeta?.purchaseOrder" :k="{ ar: 'أمر الشراء في أودو', en: 'Purchase order in Odoo' }" :v="odooMeta.purchaseOrder.name" ltr />
        <KV v-if="odooMeta" :k="{ ar: 'رقمه في أودو', en: 'Id in Odoo' }" ltr>
          #{{ r.externalRef }}
          <RouterLink v-if="archiveLink" :to="archiveLink" class="ms-1.5 font-sans text-[9.5px] font-bold text-violet">{{ t('افتحه في أرشيف أودو', 'Open in the Odoo archive') }}</RouterLink>
        </KV>
      </div>
      <template v-if="r.reason">
        <div class="field-l mb-1">{{ t('السبب / التفاصيل', 'Reason / details') }}</div>
        <div class="mb-3 whitespace-pre-line rounded-xl bg-soft px-3.5 py-2.5 text-[11px] leading-[1.9] [text-align:match-parent]" dir="auto">{{ r.reason }}</div>
      </template>

      <!-- the approvers: who is asked, in which order, and what each has said -->
      <div class="row mb-1.5">
        <div class="field-l grow !mb-0">{{ t('المعتمدون', 'Approvers') }}</div>
        <span v-if="r.status !== 'draft'" class="text-[10px] text-muted">{{ t('اعتمد', 'Approved') }} <b class="num ltr">{{ progressText(r.progress) }}</b></span>
        <span class="text-[10px] text-muted">· {{ bi(own(MODE_LABELS, r.approvalMode) || r.approvalMode) }}</span>
      </div>
      <div v-if="!activeLines.length" class="empty !p-3.5">{{ r.status === 'draft' ? t('لا معتمد على هذه المسودة بعد — يُضاف معتمدو النوع عند الإرسال، أو أضف معتمدًا بنفسك.', 'No approver on this draft yet — the approvers of the category are added when it is sent, or add one yourself.') : t('لا معتمدين.', 'No approvers.') }}</div>
      <div v-for="(a, i) in activeLines" :key="a.id" class="row mb-1 rounded-[11px] border px-[12px] py-[8px]" :class="a.isMe ? 'border-[#DCD2EE] bg-[#FAF8FD]' : 'border-line-2'">
        <span class="num flex h-6 w-6 flex-none items-center justify-center rounded-full bg-soft text-[10px] font-bold text-muted">{{ i + 1 }}</span>
        <div class="min-w-0 flex-1">
          <div class="text-[11px] font-extrabold text-ink">
            {{ personName(a.user) }}<span v-if="a.isMe" class="ms-1 text-[9px] text-violet">{{ t('(أنت)', '(you)') }}</span>
            <span v-if="a.user && !a.user.linked" class="ms-1 text-[9px] font-normal text-faint">{{ t('من أودو — بلا مستخدم هنا', 'of Odoo — no user here') }}</span>
          </div>
          <div class="text-[9.5px] text-muted">
            <span v-if="a.required" class="font-bold text-bad">{{ t('اعتماده إلزامي', 'Required') }} · </span>{{ bi(own(ORIGIN_LABELS, a.origin) || '') }}<template v-if="a.decidedAt"> · <span class="num ltr">{{ fmtAt(a.decidedAt) }}</span></template>
          </div>
          <div v-if="a.comment" class="mt-0.5 whitespace-pre-line text-[10.5px] leading-[1.7] text-sec [text-align:match-parent]" dir="auto">«{{ a.comment }}»</div>
        </div>
        <Chip small :label="lineChip(a, r.status)" :fg="lineChip(a, r.status).fg" :bg="lineChip(a, r.status).bg" />
        <button v-if="removable(a)" type="button" class="x-btn !h-7 !w-7 !text-[11px]" :title="t('إزالة المعتمد', 'Remove approver')" :aria-label="t('إزالة المعتمد', 'Remove approver')" @click="removeApprover(a)">✕</button>
      </div>
      <div v-for="a in removedLines" :key="a.id" class="mb-1 px-1 text-[9.5px] text-faint"><span class="line-through">{{ personName(a.user) }}</span> — {{ bi(lineChip(a, r.status)) }}</div>
      <div v-if="r.can.approvers" class="row wrap mt-2 rounded-xl bg-soft px-3 py-2.5">
        <SelectInput v-model="newApprover" small field-class="min-w-[190px] flex-1" :options="userOptions" :placeholder="{ ar: '— أضف معتمدًا —', en: '— add an approver —' }" />
        <button type="button" class="pill" :class="{ active: newRequired }" :aria-pressed="newRequired" @click="newRequired = !newRequired">{{ t('اعتماده إلزامي', 'Required') }}</button>
        <Btn tone="softPurple" size="sm" :disabled="!newApprover" :loading="act.pending.value" :label="{ ar: 'إضافة', en: 'Add' }" @click="addApprover" />
      </div>

      <template v-if="r.items.length">
        <div class="field-l mb-1.5 mt-4">{{ t('البنود', 'Lines') }}</div>
        <div class="overflow-hidden rounded-xl border border-line-2">
          <DataTable :columns="itemColumns" :rows="r.items" :row-key="(i) => i.id" dense>
            <template #cell-description="{ row }">
              <div class="min-w-0">
                <div class="truncate text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto" :title="row.description">{{ row.description }}</div>
                <div v-if="row.product" class="num ltr text-[9.5px] text-muted">{{ row.product.sku }}</div>
              </div>
            </template>
          </DataTable>
        </div>
      </template>

      <div class="field-l mb-1.5 mt-4">{{ t('المرفقات', 'Files') }}<span v-if="fields.document === 'required'" class="ms-1 text-[9px] font-bold text-bad">{{ t('(هذا النوع يشترط مرفقًا)', '(this category requires one)') }}</span></div>
      <AttachmentsBox :number="r.number" :attachments="r.attachments" :can-attach="r.can.attach" />

      <div class="field-l mb-1.5 mt-4">{{ t('السجل والتعليقات', 'History and comments') }}</div>
      <div v-if="r.can.comment" class="mb-2">
        <TextArea v-model="comment" :rows="2" :placeholder="{ ar: 'اكتب تعليقًا يراه صاحب الطلب ومعتمدوه…', en: 'Write a comment the requester and the approvers will see…' }" />
        <div class="mt-1.5 flex justify-end"><Btn tone="soft" size="sm" :disabled="!comment.trim()" :loading="act.pending.value" :label="{ ar: 'إضافة تعليق', en: 'Add comment' }" @click="addComment" /></div>
      </div>
      <Timeline :items="timeline" :empty-text="{ ar: 'لا أحداث بعد.', en: 'Nothing yet.' }">
        <template #chip="{ item }"><Chip v-if="item.fromOdoo" small :label="{ ar: 'في أودو', en: 'In Odoo' }" :fg="TONE.violet.fg" :bg="TONE.violet.bg" /></template>
        <template #note="{ item }">
          <div v-if="item.detail" dir="auto" class="[text-align:match-parent]">{{ item.detail }}</div>
          <div v-if="item.note" dir="auto" class="whitespace-pre-line [text-align:match-parent]" :class="item.kind === 'comment' ? 'rounded-lg bg-soft px-2.5 py-1.5 text-[10.5px] text-ink' : ''">{{ item.note }}</div>
        </template>
      </Timeline>
    </template>

    <template v-if="r && (r.can.edit || r.can.submit || r.can.withdraw || r.can.reopen || r.can.cancel)" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="r.can.submit" tone="primary" :loading="act.pending.value" :label="{ ar: 'إرسال للاعتماد', en: 'Send for approval' }" @click="submit" />
        <Btn v-if="r.can.edit" tone="dark" :disabled="act.pending.value" :label="{ ar: 'تعديل المسودة', en: 'Edit the draft' }" @click="emit('edit')" />
        <Btn v-if="r.can.withdraw" tone="softAmber" :disabled="act.pending.value" :label="r.my.lineStatus === 'refused' ? { ar: 'سحب رفضي', en: 'Take my refusal back' } : { ar: 'سحب اعتمادي', en: 'Take my approval back' }" @click="withdraw" />
        <Btn v-if="r.can.reopen" tone="soft" :disabled="act.pending.value" :label="r.status === 'pending' ? { ar: 'سحب للتعديل', en: 'Recall to change' } : { ar: 'إعادة إلى مسودة', en: 'Back to a draft' }" @click="reopen" />
        <div class="grow" />
        <Btn v-if="r.can.cancel" tone="dangerOutline" :disabled="act.pending.value" :label="{ ar: 'إلغاء الطلب', en: 'Cancel the request' }" @click="cancel" />
      </div>
    </template>
  </Drawer>
</template>
