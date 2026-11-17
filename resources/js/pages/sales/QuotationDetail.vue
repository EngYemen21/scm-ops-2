<script setup>
// Quotation detail card under the quotations table: state-dependent buttons (send / approve / reject with confirm /
// approve discount / extend validity / convert / duplicate / print / open SO), key facts, lines, totals incl. VAT and the
// status history. Two rules decide whether it may reach the customer — both come from the server (`discount`, `validity`):
//  - a discount above the limit waits for a holder of `discount.approve`;
//  - past its validity date it is expired: extend it (it comes back as a draft) before anything else.
// A button that cannot work is shown disabled with its reason, never hidden behind a refusal.
//   <QuotationDetail :number="sel" @close="sel = null" @select="(n) => sel = n" @open-so="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DateInput, ErrorBanner, KV, Timeline } from '@/components';
import { fmtDate, fmtDateOnly, fmtMoney, fmtNum, num, t } from '@/i18n';
import { QT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ConvertModal from './ConvertModal.vue';
import TotalsBox from './TotalsBox.vue';
import { custName, historyItems, isoDatePlus, lineNetOf, prodName, uomCode } from './shared';

const props = defineProps({
  number: { type: String, required: true },
});
const emit = defineEmits(['close', 'select', 'open-so']);

const auth = useAuth();
const act = useAction();
const det = useGet(() => `/sales/quotations/${encodeURIComponent(props.number)}`);
const d = computed(() => det.data.value);
const history = computed(() => historyItems(d.value?.history, QT_LABELS));
const converting = ref(null);

const open = computed(() => ['draft', 'sent', 'approved'].includes(d.value?.status));
const expired = computed(() => !!d.value?.validity?.expired);
const disc = computed(() => d.value?.discount || null);
const needsDiscount = computed(() => !!disc.value?.needsApproval);
/** Why the quotation cannot go to the customer right now (`{ ar, en }`), or null. */
const blocked = computed(() => {
  if (!d.value) return null;
  if (expired.value) return { ar: `انتهت الصلاحية في ${fmtDateOnly(d.value.validUntil)} — مدّد الصلاحية أولًا`, en: `Expired on ${fmtDateOnly(d.value.validUntil)} — extend the validity first` };
  if (needsDiscount.value) return { ar: `خصم ${fmtNum(disc.value.linePct)}% فوق الحد ${fmtNum(disc.value.maxPct)}% — بانتظار اعتماد الخصم`, en: `A ${fmtNum(disc.value.linePct)}% discount is above the ${fmtNum(disc.value.maxPct)}% limit — waiting for discount approval` };
  return null;
});
const blockedText = computed(() => (blocked.value ? t(blocked.value.ar, blocked.value.en) : null));
const canExtend = computed(() => auth.can('sales.manage') && (open.value || d.value?.status === 'expired'));

// extend validity (inline date + confirm button)
const extending = ref(false);
const newDate = ref('');
watch(() => props.number, () => { extending.value = false; });
function startExtend() { newDate.value = isoDatePlus(14); extending.value = true; act.clearError(); }

async function run(path, success, body) {
  const r = await act.run(() => api.postIdempotent(path, body), { success, invalidate: ['sales'] });
  // a refusal can change the quotation too (a lapsed one becomes `expired`), so the card is re-read either way
  det.refetch();
  return r;
}
const send = () => run(`/sales/quotations/${d.value.number}/send`, t('أُرسل العرض للعميل', 'Sent to customer'));
const approve = () => run(`/sales/quotations/${d.value.number}/approve`, t('اعتُمد العرض — يمكن تحويله لأمر بيع', 'Approved — can be converted'));
async function reject() {
  const n = d.value.number;
  if (await confirm({ title: { ar: `رفض العرض ${n}؟`, en: `Reject ${n}?` } })) await run(`/sales/quotations/${n}/reject`, t('رُفض العرض', 'Rejected'), { reason: 'رفض العميل' });
}
async function approveDiscount() {
  const n = d.value.number;
  const note = await ask({
    title: { ar: `اعتماد خصم ${fmtNum(disc.value.linePct)}% على العرض ${n}؟`, en: `Approve the ${fmtNum(disc.value.linePct)}% discount on ${n}?` },
    sub: { ar: `الحد المسموح دون اعتماد ${fmtNum(disc.value.maxPct)}% — يُسجَّل الاعتماد باسمك في سجل التدقيق`, en: `The limit without approval is ${fmtNum(disc.value.maxPct)}% — the approval is recorded under your name in the audit trail` },
    label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, okLabel: { ar: 'اعتماد الخصم', en: 'Approve discount' }, tone: 'primary',
  });
  if (note === null) return;
  await run(`/sales/quotations/${n}/approve-discount`, t('اعتُمد الخصم — يمكن إرسال العرض للعميل', 'Discount approved — the quotation can be sent'), note ? { note } : undefined);
}
async function extend() {
  if (!newDate.value) return;
  const wasExpired = d.value.status === 'expired';
  const r = await run(`/sales/quotations/${d.value.number}/extend`, wasExpired ? t('مُدّدت الصلاحية — عاد العرض مسودة', 'Validity extended — the quotation is a draft again') : t('مُدّدت الصلاحية', 'Validity extended'), { validUntil: newDate.value });
  if (r) extending.value = false;
}
async function duplicate() {
  const r = await act.run(() => api.postIdempotent(`/sales/quotations/${d.value.number}/duplicate`), { success: (x) => t(`نُسخ العرض → ${x.number}`, `Duplicated → ${x.number}`), invalidate: ['sales'] });
  if (r) emit('select', r.number);
}
const print = () => window.print();
function onConverted(so) { det.refetch(); emit('open-so', so.number); }

const LINE_GRID = 'bgrid grid grid-cols-[minmax(180px,1.5fr)_90px_100px_80px_110px] gap-2';
</script>

<template>
  <div class="card selected mt-3.5 px-5 py-[18px]">
    <ErrorBanner :error="det.error.value" :closable="false" />
    <div v-if="!d && !det.error.value" class="skel h-[120px]" />
    <template v-if="d">
      <div class="row wrap !gap-2.5">
        <div class="num-mixed text-[15px] font-extrabold">{{ d.number }}</div>
        <div class="text-[12px] font-extrabold">{{ custName(d.customer) }}</div>
        <Chip :map="QT_LABELS" :k="d.status" />
        <Chip v-if="expired && d.status !== 'expired'" :label="{ ar: 'انتهت الصلاحية', en: 'Validity passed' }" fg="#b23b3b" bg="#fdecec" small />
        <Chip v-if="needsDiscount" :label="{ ar: 'خصم بانتظار الاعتماد', en: 'Discount awaiting approval' }" fg="#b26a16" bg="#fbf0dd" small />
        <Chip v-else-if="disc?.aboveMax && disc.approvedBy" :label="{ ar: `الخصم معتمد · ${disc.approvedBy}`, en: `Discount approved · ${disc.approvedBy}` }" fg="#1d7a3e" bg="#e6f9ec" small />
        <div class="grow" />
        <Btn v-if="auth.can('sales.manage') && d.status === 'draft'" tone="primary" size="sm" :loading="act.pending.value" :disabled="!!blocked" :title="blockedText" :label="{ ar: 'إرسال للعميل', en: 'Send' }" @click="send" />
        <Btn v-if="auth.can('sales.manage') && ['draft', 'sent'].includes(d.status)" tone="success" size="sm" :loading="act.pending.value" :disabled="!!blocked" :title="blockedText" :label="{ ar: 'اعتماد (وافق العميل)', en: 'Approve' }" @click="approve" />
        <Btn v-if="auth.can('discount.approve') && needsDiscount" tone="purple" size="sm" :loading="act.pending.value" :label="{ ar: 'اعتماد الخصم', en: 'Approve discount' }" @click="approveDiscount" />
        <Btn v-if="auth.can('sales.manage') && open" tone="softRed" size="sm" :loading="act.pending.value" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject" />
        <Btn v-if="auth.can('so.reserve') && d.status === 'approved'" tone="purple" size="sm" :disabled="!!blocked" :title="blockedText" :label="{ ar: 'تحويل لأمر بيع', en: 'Convert to SO' }" @click="converting = d" />
        <Btn v-if="canExtend && !extending" :tone="expired ? 'primary' : 'soft'" size="sm" :label="{ ar: 'تمديد الصلاحية', en: 'Extend validity' }" @click="startExtend" />
        <Btn v-if="auth.can('sales.manage')" tone="soft" size="sm" :loading="act.pending.value" :label="{ ar: 'نسخ', en: 'Duplicate' }" @click="duplicate" />
        <Btn tone="outline" size="sm" :label="{ ar: 'طباعة / PDF', en: 'Print / PDF' }" @click="print" />
        <Btn v-if="d.so" tone="softBlue" size="sm" :label="{ ar: `فتح أمر البيع ${d.so.number}`, en: `Open SO ${d.so.number}` }" @click="emit('open-so', d.so.number)" />
        <button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
      </div>
      <ErrorBanner class="mt-2.5" :error="act.error.value" @close="act.clearError()" />

      <!-- what stands between this quotation and the customer, and who can remove it -->
      <div v-if="blocked && (open || d.status === 'expired')" class="banner mt-2.5" :class="expired ? 'red' : 'amber'"><div class="min-w-0 flex-1 font-normal">
        <template v-if="expired">
          {{ t(`انتهت صلاحية هذا العرض في ${fmtDateOnly(d.validUntil)} — لا يُرسل ولا يُعتمد ولا يُحوَّل لأمر بيع.`, `This quotation expired on ${fmtDateOnly(d.validUntil)} — it cannot be sent, approved or converted.`) }}
          {{ auth.can('sales.manage') ? t('«تمديد الصلاحية» يعيده مسودة بتاريخ جديد.', '"Extend validity" brings it back as a draft with a new date.') : t('يمدّد صلاحيته مسؤول المبيعات (صلاحية إدارة المبيعات).', 'A sales user (sales.manage) can extend it.') }}
        </template>
        <template v-else>
          {{ t(`في العرض خصم ${fmtNum(disc.linePct)}% فوق الحد ${fmtNum(disc.maxPct)}% — لا يُرسل ولا يُعتمد قبل اعتماد الخصم.`, `The quotation carries a ${fmtNum(disc.linePct)}% discount above the ${fmtNum(disc.maxPct)}% limit — it cannot be sent or approved before the discount is approved.`) }}
          {{ auth.can('discount.approve') ? t('اضغط «اعتماد الخصم».', 'Press "Approve discount".') : t('يعتمده صاحب صلاحية «اعتماد الخصم» (discount.approve) — أُرسل له إشعار عند إنشاء العرض.', 'A holder of discount.approve approves it — they were notified when the quotation was created.') }}
        </template>
      </div></div>
      <div v-if="extending" class="row wrap mt-2.5 items-end !gap-2 rounded-xl border border-line-2 bg-soft px-3 py-2.5">
        <DateInput v-model="newDate" small :min="isoDatePlus(0)" :label="{ ar: 'صالح حتى (التاريخ الجديد)', en: 'Valid until (new date)' }" />
        <Btn tone="primary" size="sm" :loading="act.pending.value" :disabled="!newDate" :label="{ ar: 'حفظ التمديد', en: 'Save extension' }" @click="extend" />
        <Btn tone="soft" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="extending = false" />
        <span v-if="d.status === 'expired'" class="text-[9.5px] text-muted">{{ t('العرض المنتهي يعود مسودة: يُرسل ويُعتمد من جديد.', 'An expired quotation comes back as a draft: it is sent and approved again.') }}</span>
      </div>

      <div class="kv-grid mt-3.5">
        <KV :k="{ ar: 'التاريخ', en: 'Date' }" ltr><span class="num">{{ fmtDate(d.date) }}</span></KV>
        <KV :k="{ ar: 'صالح حتى', en: 'Valid until' }" ltr>
          <span class="num" :class="{ 'text-bad': expired }">{{ fmtDateOnly(d.validUntil) }}</span>
          <span v-if="expired" class="text-[9.5px] font-extrabold text-bad"> · {{ t('منتهٍ', 'expired') }}</span>
          <span v-else-if="d.validity?.daysLeft != null" class="text-[9.5px]" :class="d.validity.daysLeft <= 3 ? 'font-extrabold text-warn' : 'text-muted'"> · {{ d.validity.daysLeft === 0 ? t('ينتهي اليوم', 'ends today') : t(`باقٍ ${d.validity.daysLeft} يوم`, `${d.validity.daysLeft} days left`) }}</span>
        </KV>
        <KV :k="{ ar: 'شروط الدفع', en: 'Terms' }" :v="d.terms || '—'" />
        <KV :k="{ ar: 'شروط التوصيل', en: 'Delivery' }" :v="d.delivery || '—'" />
        <KV :k="{ ar: 'المندوب', en: 'Rep' }" :v="d.createdBy || '—'" />
        <KV :k="{ ar: 'جهة الاتصال', en: 'Contact' }" :v="d.customer.contact || '—'" />
        <KV :k="{ ar: 'أمر البيع المرتبط', en: 'Linked SO' }">
          <RouterLink v-if="d.so" :to="`/so/${encodeURIComponent(d.so.number)}`" class="cell-id">{{ d.so.number }}</RouterLink>
          <template v-else>—</template>
        </KV>
        <KV :k="{ ar: 'اعتماد الخصم', en: 'Discount approval' }">
          <span v-if="!disc?.aboveMax" class="text-muted">{{ t('غير مطلوب', 'Not needed') }}</span>
          <span v-else-if="disc.approvedBy" class="text-ok">{{ disc.approvedBy }}</span>
          <span v-else class="font-extrabold text-warn">{{ t('بانتظار الاعتماد', 'Awaiting approval') }}</span>
        </KV>
        <KV :k="{ ar: 'المرفقات', en: 'Attachments' }" :v="d.attachments?.length ? d.attachments.join('، ') : '—'" />
        <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="d.notes || '—'" />
      </div>

      <div class="mt-3.5 overflow-x-auto"><div class="min-w-[700px]">
        <div :class="LINE_GRID" class="rounded-[10px] bg-soft px-3.5 py-2 text-[9.5px] font-extrabold text-faint">
          <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('السعر', 'Price') }}</div><div>{{ t('خصم', 'Disc.') }}</div><div>{{ t('الصافي', 'Net') }}</div>
        </div>
        <div v-for="l in d.lines" :key="l.lineNo" :class="LINE_GRID" class="items-center border-b border-line-2 px-3.5 py-[9px]">
          <div><div class="text-[11px] font-extrabold">{{ prodName(l.product) }}</div><div class="cell-sub">{{ l.product.sku }}</div></div>
          <div class="num text-[11px]">{{ fmtNum(l.qty) }} <span class="font-sans text-[8.5px] text-faint">{{ uomCode(l.product.baseUom) }}</span></div>
          <div class="num text-[11px]">{{ fmtMoney(l.price) }}</div>
          <div class="num text-[10.5px]" :class="disc && num(l.discPct) > num(disc.maxPct) ? 'font-extrabold text-bad' : 'text-warn'">{{ num(l.discPct) > 0 ? `${l.discPct}%` : '—' }}</div>
          <div class="num text-[11.5px]">{{ fmtMoney(lineNetOf(l)) }}</div>
        </div>
      </div></div>
      <div class="mt-3 flex justify-end"><TotalsBox :totals="d.totals" wide /></div>

      <div class="mt-4 text-[12px] font-extrabold">{{ t('سجل الحالة', 'Status history') }}</div>
      <div class="mt-1.5"><Timeline :items="history" :empty-text="{ ar: 'لا سجل', en: 'No history' }" /></div>
    </template>

    <ConvertModal :qt="converting" @close="converting = null" @done="onConverted" @refused="det.refetch()" />
  </div>
</template>
