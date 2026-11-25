<script setup>
// One new-product request: what is asked for, who asked and why, its pictures, whether the product already exists,
// where it stands — and its story from the first draft to the product it became, with what this user may do now.
//   <RequestDrawer :number="numberOrNull" @close="…" @edit="…" />
// GET /product-requests/{number} answers `can` (what the server will accept from this user now): the buttons follow
// it, so no button ends in a refusal. A request that came from Odoo and is still managed there (`owned`) is shown,
// takes comments and pictures, and says where its decision is taken; the same record with its whole conversation is
// one link away in the Odoo archive.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, TextArea, Timeline } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import ApproveDialog from './ApproveDialog.vue';
import DuplicatesPanel from './DuplicatesPanel.vue';
import PicturesStrip from './PicturesStrip.vue';
import ProductDialog from './ProductDialog.vue';
import {
  API, EVENTS, EXISTING_CHIP, FIELDS, LINKS, SOURCE_CHIP, STATUS, STORAGE, TONE, TYPES, ageText, ageTone, dimsText, eventLines, fmtAt, fmtMeasure, fmtPrice, odooApprovals, own, personName, productName,
  refName, sendForReview, useSection,
} from './preq';

const props = defineProps({
  /** The request's number (or id); null closes the drawer. */
  number: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit']);

const auth = useAuth();
const section = useSection();
const q = useGet(() => (props.number ? `${API}/${encodeURIComponent(props.number)}` : null));
const r = computed(() => (props.number ? q.data.value || null : null));
// every action invalidates the section: the lists, the board, the counts of the page head and this request are read again
const act = useAction({ invalidate: ['product-requests'] });
const path = (suffix) => `${API}/${r.value.id}/${suffix}`;
const post = (suffix, body, success) => act.run(() => api.postIdempotent(path(suffix), body), { success });

const waiting = computed(() => !!r.value && ['submitted', 'in_review'].includes(r.value.status));
const reviewing = computed(() => !!r.value && (r.value.can.approve || r.value.can.sendBack || r.value.can.reject || r.value.can.startReview));
const fromOdoo = computed(() => r.value?.sourceSystem === 'odoo');
const meta = computed(() => (fromOdoo.value ? r.value.sourceMeta || {} : {}));
const approvals = computed(() => odooApprovals(meta.value.approvals));
/** The same request in the archive of everything Odoo holds: its conversation there and every field as Odoo has it. */
const archiveLink = computed(() => (r.value?.archive && auth.can('page.odoo') ? { path: '/odoo', query: { kind: r.value.archive.kind, doc: `${r.value.archive.kind}:${r.value.archive.odooId}` } } : null));
const productLink = computed(() => (r.value?.productSku ? { path: `/product/${encodeURIComponent(r.value.productSku)}` } : null));
const missing = computed(() => (r.value?.missing || []).map((k) => bi(own(FIELDS, k) || k)));

// ---- is it already in the catalogue? asked for a request that is still open
const dupQ = useGet(() => (r.value && r.value.requestType === 'new' && ['draft', 'submitted', 'in_review', 'returned'].includes(r.value.status) ? `${API}/duplicates` : null), () => ({ request: r.value?.id }), { staleTime: 30_000 });
const found = computed(() => (r.value ? dupQ.data.value || null : null));

// ---- its author
const submit = () => sendForReview(act, r.value.id);
async function cancel() {
  const mine = r.value.mine;
  const draft = r.value.status === 'draft';
  const reason = await ask({
    title: mine ? { ar: `سحب الطلب ${r.value.number}؟`, en: `Withdraw request ${r.value.number}?` } : { ar: `إلغاء الطلب ${r.value.number}؟`, en: `Cancel request ${r.value.number}?` },
    sub: draft ? null : { ar: 'يبقى الطلب في السجل ملغى، ويُبلَّغ الطرف الآخر.', en: 'The request stays on record as cancelled, and the other side is told.' },
    label: mine ? { ar: 'السبب (اختياري)', en: 'Reason (optional)' } : { ar: 'سبب الإلغاء', en: 'Reason' }, required: !mine,
    okLabel: mine ? { ar: 'سحب الطلب', en: 'Withdraw' } : { ar: 'إلغاء الطلب', en: 'Cancel the request' }, cancelLabel: { ar: 'تراجع', en: 'Back' }, tone: 'danger',
  });
  if (reason === null) return;
  await post('cancel', reason ? { reason } : {}, t('أُلغي الطلب', 'The request was cancelled'));
}

// ---- the reviewers
const startReview = () => post('start-review', {}, t('الطلب الآن قيد مراجعتك', 'The request is now in your review'));
async function sendBack() {
  const reason = await ask({
    title: { ar: `إعادة الطلب ${r.value.number} للتصحيح؟`, en: `Send request ${r.value.number} back?` }, sub: { ar: 'يعود الطلب إلى صاحبه مع ما كتبته، ويرسله من جديد بعد التصحيح.', en: 'The request goes back to its author with what you write, and he sends it again once corrected.' },
    label: { ar: 'ما المطلوب تصحيحه؟', en: 'What has to be corrected?' }, required: true, okLabel: { ar: 'إعادة للتصحيح', en: 'Send back' }, tone: 'dark',
  });
  if (reason === null) return;
  await post('send-back', { reason }, t('أُعيد الطلب إلى صاحبه', 'The request was sent back to its author'));
}
/** Which dialog is open over the drawer: 'approve' | 'reject' | 'link' | null. */
const dialog = ref(null);

// ---- comments
const comment = ref('');
async function addComment() {
  const text = comment.value.trim();
  if (!text) return;
  if (await post('comments', { text }, t('أُضيفت الملاحظة', 'Comment added'))) comment.value = '';
}
watch(() => props.number, () => { comment.value = ''; dialog.value = null; act.clearError(); });

const timeline = computed(() => (r.value?.events || []).map((e) => {
  const label = own(EVENTS, e.kind) || { ar: e.kind, en: e.kind };
  return { at: e.at, by: e.by, label, color: label.color, note: e.note, lines: eventLines(e, section.stateLabel), fromOdoo: e.origin === 'odoo', kind: e.kind };
}));
const age = computed(() => (r.value ? ageText(r.value) : ''));
</script>

<template>
  <Drawer :open="!!number" :width="760" @close="emit('close')">
    <template #title>
      <span class="num ltr inline-block">{{ r ? r.number : number }}</span>
      <span v-if="r && r.sourceNumber && r.sourceNumber !== r.number" class="num ltr ms-2 inline-block text-[10px] font-bold text-faint" :title="t('رقمه في أودو', 'Its reference in Odoo')">{{ r.sourceNumber }}</span>
    </template>
    <template #sub><span dir="auto">{{ r ? productName(r) : '' }}</span></template>
    <template v-if="r" #headExtra>
      <Chip v-if="fromOdoo" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
      <Chip :map="STATUS" :k="r.status" />
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
    <div v-if="q.isLoading.value && !r" class="skel h-[240px]" />

    <template v-if="r">
      <!-- where the request stands for THIS user, and the one thing he can do about it -->
      <div v-if="reviewing" class="next-step amber mb-3">
        <div>
          <div class="text-[12px] font-extrabold">{{ r.status === 'in_review' ? t('الطلب قيد المراجعة — قرارك', 'The request is in review — your decision') : t('الطلب بانتظار المراجعة', 'The request waits for review') }}<span v-if="age" class="ms-2 rounded-full px-1.5 py-px text-[9.5px]" :style="{ color: ageTone(r.ageDays).fg, background: ageTone(r.ageDays).bg }">{{ age }}</span></div>
          <div class="mt-0.5 text-[10.5px]">{{ t(`من ${personName(r.requestedBy)}`, `From ${personName(r.requestedBy)}`) }}<template v-if="r.reviewer && r.status === 'in_review'"> · {{ t(`يراجعه ${personName(r.reviewer)}`, `reviewed by ${personName(r.reviewer)}`) }}</template><template v-if="missing.length"> · {{ t('ينقص قبل إنشاء الصنف:', 'needed before the product is created:') }} {{ missing.join('، ') }}</template></div>
          <div v-if="r.selfApprovalBlocked" class="mt-1 text-[10px] font-bold">{{ t('أنت من رفع هذا الطلب: يعتمده مراجع آخر (فصل المهام).', 'You raised this request: another reviewer approves it (segregation of duties).') }}</div>
        </div>
        <div class="next-actions row">
          <Btn v-if="r.can.startReview" tone="softBlue" :loading="act.pending.value" :label="{ ar: 'بدء المراجعة', en: 'Start the review' }" @click="startReview" />
          <Btn v-if="r.can.approve" tone="success" :disabled="act.pending.value" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="dialog = 'approve'" />
          <Btn v-if="r.can.sendBack" tone="softAmber" :disabled="act.pending.value" :label="{ ar: 'إعادة للتصحيح', en: 'Send back' }" @click="sendBack" />
          <Btn v-if="r.can.reject" tone="dangerOutline" :disabled="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="dialog = 'reject'" />
        </div>
      </div>
      <div v-else-if="r.status === 'returned'" class="next-step purple mb-3">
        <div>
          <div class="text-[12px] font-extrabold">{{ r.mine ? t('أُعيد إليك الطلب للتصحيح', 'The request was sent back to you') : t('الطلب عند صاحبه للتصحيح', 'The request is back with its author') }}<span v-if="age" class="ms-2 text-[9.5px] font-bold">{{ age }}</span></div>
          <div v-if="r.returnReason" class="mt-0.5 whitespace-pre-line text-[10.5px] leading-[1.8] [text-align:match-parent]" dir="auto">«{{ r.returnReason }}»<template v-if="r.reviewer"> — {{ personName(r.reviewer) }}</template></div>
        </div>
        <div v-if="r.can.edit || r.can.submit" class="next-actions row">
          <Btn v-if="r.can.edit" tone="dark" :disabled="act.pending.value" :label="{ ar: 'تصحيح الطلب', en: 'Correct it' }" @click="emit('edit')" />
          <Btn v-if="r.can.submit" tone="primary" :loading="act.pending.value" :label="{ ar: 'إعادة الإرسال', en: 'Send again' }" @click="submit" />
        </div>
      </div>
      <div v-else-if="r.status === 'draft' && r.can.submit" class="next-step neutral mb-3">
        <div>
          <div class="text-[12px] font-extrabold">{{ t('مسودة — لم تُرسل للمراجعة بعد', 'A draft — not sent for review yet') }}</div>
          <div class="mt-0.5 text-[10.5px]">{{ t('لا يراها غيرك وغير المراجعين حتى ترسلها.', 'Only you and the reviewers see it until you send it.') }}</div>
        </div>
        <div class="next-actions row">
          <Btn tone="dark" :disabled="act.pending.value" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit')" />
          <Btn tone="primary" :loading="act.pending.value" :disabled="!!(found && found.blocked)" :label="{ ar: 'إرسال للمراجعة', en: 'Send for review' }" @click="submit" />
        </div>
      </div>
      <div v-else-if="r.owned && waiting" class="next-step purple mb-3">
        <div>
          <div class="text-[12px] font-extrabold">{{ t('قرار هذا الطلب يُتخذ في أودو', 'This request is decided in Odoo') }}<span v-if="r.sourceState" class="ms-2 text-[10px] font-bold">— {{ bi(r.sourceState) }}</span></div>
          <div class="mt-0.5 text-[10.5px] leading-[1.8]">{{ t('يُعرض هنا كما هو ويتحدّث تلقائيًا. يمكنك إضافة ملاحظة أو صورة؛ التعديل والاعتماد في أودو إلى أن يُسلَّم القسم.', 'It is shown here as it is and follows Odoo by itself. You can add a comment or a picture; it is edited and approved in Odoo until the section is handed over.') }}</div>
        </div>
      </div>
      <div v-else-if="waiting" class="next-step neutral mb-3">
        <div>
          <div class="text-[12px] font-extrabold">{{ r.status === 'in_review' ? t('قيد المراجعة', 'In review') : t('بانتظار المراجعة', 'Waiting for review') }}<span v-if="age" class="ms-2 rounded-full px-1.5 py-px text-[9.5px]" :style="{ color: ageTone(r.ageDays).fg, background: ageTone(r.ageDays).bg }">{{ age }}</span></div>
          <div class="mt-0.5 text-[10.5px]">{{ r.reviewer && r.status === 'in_review' ? t(`يراجعه ${personName(r.reviewer)}`, `Reviewed by ${personName(r.reviewer)}`) : t('لم يأخذه مراجع بعد.', 'No reviewer has taken it yet.') }}</div>
        </div>
      </div>
      <div v-if="r.status === 'approved'" class="next-step green mb-3">
        <div>
          <div class="text-[12px] font-extrabold">
            <template v-if="r.productSku">{{ r.requestType === 'existing' ? t('الصنف المطلوب في الكتالوج:', 'The requested product in the catalogue:') : t('أصبح صنفًا في الكتالوج:', 'It is a product of the catalogue:') }}
              <RouterLink v-if="productLink" :to="productLink" class="num ltr underline">{{ r.productSku }}</RouterLink></template>
            <template v-else>{{ t('معتمد في أودو — لم يُربط بصنف في الكتالوج بعد', 'Approved in Odoo — not linked to a catalogue product yet') }}</template>
          </div>
          <div class="mt-0.5 text-[10.5px] leading-[1.8]">
            <template v-if="r.productLink">{{ bi(own(LINKS, r.productLink) || '') }}</template>
            <template v-else-if="r.sourceProductRef">{{ t(`صنف أودو رقم ${r.sourceProductRef} لم يصبح صنفًا في نظام العمليات بعد — يُربط تلقائيًا حين يُحمَّل.`, `Odoo product #${r.sourceProductRef} is not an OPS product yet — the link is made by itself once it is loaded.`) }}</template>
            <template v-else-if="meta.productLookup === 'ambiguous'">{{ t('أكثر من صنف في أودو يحمل هذا الاسم وأُنشئ في اللحظة نفسها — حدّد الصنف يدويًا.', 'More than one Odoo product carries this name and was made at the same moment — say which one it is.') }}</template>
            <template v-else-if="fromOdoo">{{ t('لا يوجد في أودو صنف بهذا الاسم أُنشئ عند اعتماد الطلب (ربما غُيّر اسمه) — حدّد الصنف يدويًا إن عُرف.', 'No Odoo product of this name was made when the request was approved (it may have been renamed) — say which product it is, if known.') }}</template>
            <template v-if="r.decidedBy"> · {{ t(`اعتمده ${personName(r.decidedBy)}`, `approved by ${personName(r.decidedBy)}`) }}</template><template v-if="r.decidedAt"> · <span class="num ltr">{{ fmtAt(r.decidedAt) }}</span></template>
          </div>
        </div>
        <div v-if="r.can.linkProduct" class="next-actions row">
          <Btn tone="soft" :label="r.productSku ? { ar: 'تغيير الصنف المرتبط', en: 'Change the linked product' } : { ar: 'ربط بصنف', en: 'Link a product' }" @click="dialog = 'link'" />
        </div>
      </div>
      <div v-if="r.status === 'rejected'" class="banner red">
        <div class="min-w-0 flex-1">
          <div>{{ t('رُفض الطلب', 'The request was rejected') }}<template v-if="r.decidedBy"> — {{ personName(r.decidedBy) }}</template><template v-if="r.decidedAt"> · <span class="num ltr">{{ fmtAt(r.decidedAt) }}</span></template></div>
          <div class="whitespace-pre-line font-bold [text-align:match-parent]" dir="auto">{{ r.rejectReason || (fromOdoo ? t('لم يُسجَّل سبب للرفض في أودو.', 'Odoo recorded no reason.') : '—') }}</div>
          <div v-if="r.productSku && productLink" class="mt-0.5">{{ t('الصنف الموجود في الكتالوج:', 'The product the catalogue has:') }} <RouterLink :to="productLink" class="num ltr underline">{{ r.productSku }}</RouterLink></div>
        </div>
      </div>
      <div v-if="r.status === 'cancelled'" class="banner neutral">
        <div>{{ t('أُلغي هذا الطلب', 'This request was cancelled') }}<template v-if="r.cancelReason"> — <span dir="auto">{{ r.cancelReason }}</span></template></div>
      </div>

      <!-- pictures -->
      <div class="field-l mb-1.5">{{ t('صور الصنف', 'Pictures') }}</div>
      <PicturesStrip :request-id="r.id" :pictures="r.pictures" :can-add="r.can.addPicture" :limit="section.limits.value.pictures" :max-bytes="section.limits.value.pictureBytes" />

      <!-- what is asked for -->
      <div class="kv-grid mb-3 mt-4">
        <KV :k="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" class="col-span-full"><span dir="auto">{{ r.nameAr }}</span></KV>
        <KV v-if="r.nameEn" :k="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" class="col-span-full"><span dir="ltr" class="[unicode-bidi:plaintext]">{{ r.nameEn }}</span></KV>
        <KV v-if="r.requestType === 'existing'" :k="{ ar: 'نوع الطلب', en: 'Kind' }"><Chip small :label="EXISTING_CHIP" :fg="EXISTING_CHIP.fg" :bg="EXISTING_CHIP.bg" :title="bi(TYPES.existing)" /></KV>
        <KV :k="{ ar: 'العلامة التجارية', en: 'Brand' }" :v="r.brand || '—'" />
        <KV :k="{ ar: 'التصنيف', en: 'Category' }">{{ refName(r.category, r.categoryName) || '—' }}<span v-if="!r.category && r.categoryName" class="ms-1 text-[9px] font-normal text-faint">{{ t('(كما كُتب — غير مرتبط)', '(as written — not linked)') }}</span></KV>
        <KV :k="{ ar: 'المورد', en: 'Supplier' }">{{ refName(r.supplier, r.supplierName) || '—' }}<span v-if="!r.supplier && r.supplierName" class="ms-1 text-[9px] font-normal text-faint">{{ t('(غير مسجَّل)', '(not registered)') }}</span></KV>
        <KV :k="{ ar: 'الباركود', en: 'Barcode' }" :v="r.barcode || '—'" ltr />
        <KV v-if="r.proposedSku" :k="{ ar: 'الرمز المقترح', en: 'Proposed code' }" :v="r.proposedSku" ltr />
        <KV :k="{ ar: 'سعر التكلفة المقترح', en: 'Proposed cost' }" :v="fmtPrice(r.costPrice, r.currency)" ltr />
        <KV :k="{ ar: 'سعر البيع المقترح', en: 'Proposed selling price' }" :v="fmtPrice(r.salePrice, r.currency)" ltr />
        <KV :k="{ ar: 'وحدة المخزون', en: 'Stock unit' }" :v="refName(r.uom, r.uomName) || '—'" />
        <KV :k="{ ar: 'العبوة', en: 'Pack' }" ltr>{{ r.packText || '—' }}<template v-if="r.packQty"> · {{ fmtMeasure(r.packQty) }} {{ refName(r.packUom) }}</template></KV>
        <KV :k="{ ar: 'شرط التخزين', en: 'Storage' }"><Chip v-if="r.storageClass" small :map="STORAGE" :k="r.storageClass" /><template v-else>—</template></KV>
        <KV :k="{ ar: 'الصلاحية', en: 'Expiry' }">{{ r.tracksExpiry ? t('يُتتبَّع تاريخ الانتهاء', 'Expiry dates tracked') : t('لا يُتتبَّع', 'Not tracked') }}<template v-if="r.shelfLifeDays"> · <span class="num ltr">{{ fmtMeasure(r.shelfLifeDays) }}</span> {{ t('يوم', 'days') }}</template></KV>
        <KV :k="{ ar: 'الوزن كجم', en: 'Weight kg' }" :v="fmtMeasure(r.weightKg)" ltr />
        <KV :k="{ ar: 'الأبعاد سم', en: 'Dimensions cm' }" :v="dimsText(r) || '—'" ltr />
        <KV :k="{ ar: 'صاحب الطلب', en: 'Requested by' }">{{ personName(r.requestedBy) }}<span v-if="r.requestedBy && r.requestedBy.external" class="ms-1 text-[9px] font-normal text-faint">{{ t('(من أودو — بلا مستخدم هنا)', '(of Odoo — no user here)') }}</span></KV>
        <KV v-if="r.customer || r.customerName" :k="{ ar: 'العميل الذي طلبه', en: 'The customer who asked' }">{{ refName(r.customer, r.customerName) }}<span v-if="r.customer && r.customer.code" class="num ltr ms-1 text-[9px] font-normal text-faint">{{ r.customer.code }}</span></KV>
        <KV :k="{ ar: 'رُفع', en: 'Raised' }" :v="fmtAt(r.createdAt)" ltr />
        <KV v-if="r.submittedAt" :k="{ ar: 'أُرسل للمراجعة', en: 'Sent for review' }" :v="fmtAt(r.submittedAt)" ltr />
        <KV v-if="r.returnsCount" :k="{ ar: 'مرات الإعادة للتصحيح', en: 'Times sent back' }" :v="r.returnsCount" ltr />
      </div>
      <template v-if="r.reason">
        <div class="field-l mb-1">{{ t('سبب الطلب', 'Why it is needed') }}</div>
        <div class="mb-3 whitespace-pre-line rounded-xl bg-soft px-3.5 py-2.5 text-[11px] leading-[1.9] [text-align:match-parent]" dir="auto">{{ r.reason }}</div>
      </template>
      <div v-if="missing.length && r.can.edit && !reviewing" class="hint amber !mt-0 mb-3">{{ t('ينقص قبل إنشاء الصنف عند الاعتماد:', 'Still needed before the product can be created:') }} {{ missing.join('، ') }} — {{ t('أضفها إن عُرفت، أو يكملها المراجع.', 'add them if known, or the reviewer completes them.') }}</div>

      <!-- is it already in the catalogue? -->
      <div v-if="found && !found.clean" class="mb-3">
        <DuplicatesPanel :found="found" />
      </div>

      <!-- what Odoo holds for it -->
      <div v-if="fromOdoo" class="mb-3 rounded-xl border border-[#DCD2EE] bg-[#FAF8FD] px-3.5 py-2.5">
        <div class="row wrap">
          <div class="text-[11px] font-extrabold text-violet">{{ r.sourceModel === 'hub.product.request' ? t('طلب عميل من بوابة أودو', 'A customer request from the Odoo portal') : t('طلب من أودو', 'A request of Odoo') }}</div>
          <span class="num ltr text-[10px] text-muted">#{{ r.externalRef }}</span>
          <Chip v-if="r.sourceState" small :label="r.sourceState" :fg="TONE.violet.fg" :bg="TONE.violet.bg" :title="t('حالته في أودو', 'Its state in Odoo')" />
          <span v-if="r.sourceDeletedAt" class="text-[9.5px] font-bold text-bad">{{ t('حُذف من أودو', 'Deleted in Odoo') }}</span>
          <div class="grow" />
          <RouterLink v-if="archiveLink" :to="archiveLink" class="text-[10px] font-bold text-violet underline">{{ t('افتحه في أرشيف أودو (المحادثة وكل الحقول)', 'Open in the Odoo archive (conversation and every field)') }}</RouterLink>
        </div>
        <div v-if="approvals.length" class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-sec">
          <span class="font-bold">{{ t('اعتمد في أودو:', 'Approved in Odoo by:') }}</span>
          <span v-for="a in approvals" :key="a.step">{{ a.step }}: <b class="text-ink">{{ a.name }}</b></span>
        </div>
        <div v-if="meta.suggestedPrice || meta.approvedPrice || meta.branch || meta.pricelist" class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-sec">
          <span v-if="meta.suggestedPrice">{{ t('السعر الذي اقترحه العميل', 'Price the customer suggested') }}: <b class="num ltr text-ink">{{ meta.suggestedPrice }}</b></span>
          <span v-if="meta.approvedPrice">{{ t('السعر المعتمد', 'Approved price') }}: <b class="num ltr text-ink">{{ meta.approvedPrice }}</b></span>
          <span v-if="meta.branch">{{ t('الفرع', 'Branch') }}: <b class="text-ink">{{ meta.branch }}</b></span>
          <span v-if="meta.pricelist">{{ t('قائمة الأسعار', 'Price list') }}: <b class="text-ink">{{ meta.pricelist }}</b></span>
        </div>
        <div v-if="meta.note" class="mt-1 whitespace-pre-line text-[10px] text-sec [text-align:match-parent]" dir="auto">{{ meta.note }}</div>
      </div>

      <!-- its story -->
      <div class="field-l mb-1.5 mt-4">{{ t('السجل والملاحظات', 'History and comments') }}</div>
      <div v-if="r.can.comment" class="mb-2">
        <TextArea v-model="comment" :rows="2" :placeholder="{ ar: 'اكتب ملاحظة يراها صاحب الطلب والمراجعون…', en: 'Write a comment the requester and the reviewers will see…' }" />
        <div class="mt-1.5 flex justify-end"><Btn tone="soft" size="sm" :disabled="!comment.trim()" :loading="act.pending.value" :label="{ ar: 'إضافة ملاحظة', en: 'Add comment' }" @click="addComment" /></div>
      </div>
      <Timeline :items="timeline" :empty-text="{ ar: 'لا أحداث بعد.', en: 'Nothing yet.' }">
        <template #chip="{ item }"><Chip v-if="item.fromOdoo" small :label="{ ar: 'في أودو', en: 'In Odoo' }" :fg="TONE.violet.fg" :bg="TONE.violet.bg" /></template>
        <template #note="{ item }">
          <div v-for="(line, i) in item.lines" :key="i" dir="auto" class="[text-align:match-parent]">{{ line }}</div>
          <div v-if="item.note" dir="auto" class="whitespace-pre-line [text-align:match-parent]" :class="item.kind === 'comment' ? 'rounded-lg bg-soft px-2.5 py-1.5 text-[10.5px] text-ink' : 'font-bold'">{{ item.note }}</div>
        </template>
      </Timeline>
    </template>

    <template v-if="r && (r.can.cancel || (r.can.edit && reviewing))" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="r.can.edit && reviewing" tone="dark" :disabled="act.pending.value" :label="{ ar: 'تصحيح القيم', en: 'Correct the values' }" @click="emit('edit')" />
        <div class="grow" />
        <Btn v-if="r.can.cancel" tone="dangerOutline" :disabled="act.pending.value" :label="r.mine ? { ar: 'سحب الطلب', en: 'Withdraw the request' } : { ar: 'إلغاء الطلب', en: 'Cancel the request' }" @click="cancel" />
      </div>
    </template>
  </Drawer>

  <ApproveDialog :request="dialog === 'approve' ? r : null" @close="dialog = null" />
  <ProductDialog :request="dialog === 'reject' || dialog === 'link' ? r : null" :mode="dialog === 'link' ? 'link' : 'reject'" @close="dialog = null" />
</template>
