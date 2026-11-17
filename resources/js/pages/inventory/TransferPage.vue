<script setup>
// Transfer detail (deep link /trf/:number): stepper, meta + carrier, lines, timeline / movements / exceptions, and the
// actions: submit → approve (the quantity is held at the source) → pick → ship (carrier) → receive — as many times as
// goods arrive, with the receiving bin chosen here — → resolve the variance when something never arrives → close.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KV, Modal, NumberInput, PageHead, SectionCard, Stepper, TextArea, TextInput } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { MOVEMENT_LABELS, TRANSFER_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm, showLabel } from '@/stores/ui';
import Hint from './Hint.vue';
import ProductCell from './ProductCell.vue';
import StatusTimeline from './StatusTimeline.vue';
import TransferShipModal from './TransferShipModal.vue';
import TransferVarianceModal from './TransferVarianceModal.vue';
import { fmtSigned, signColor } from './shared';

/**
 * @typedef {{ id: string, lineNo: number, qty: number, receivedQty: number|null, varianceQty: number, remainingQty: number, heldQty?: number, batchNo: string|null,
 *   product: { sku: string, nameAr: string, nameEn: string, storageClass: string }, batch: { batchNo: string, expiryDate: string|null }|null,
 *   fromBin: { code: string }, toBin: { code: string }|null, suggestedToBin?: { code: string, reasonAr: string, reasonEn: string }|null }} TransferLine
 * @typedef {{ id: string, number: string, status: string, fromWarehouse: { code: string, nameAr: string, nameEn: string }, toWarehouse: { code: string, nameAr: string, nameEn: string },
 *   reasonAr: string, reasonEn: string, notes: string|null, requestedBy: string|null, date: string, eta: string|null, approvedAt: string|null, shippedAt: string|null,
 *   receivedAt: string|null, closedAt: string|null, lines: TransferLine[], totalQty: number, receivedQty: number, varianceQty: number, inTransitQty: number, heldQty: number, allowed: string[],
 *   carrier: string|null, waybill: string|null, vehicle: { code: string, plateAr: string }|null, driver: { code: string, nameAr: string, nameEn: string|null }|null, trip: { number: string, status: string }|null,
 *   variance: { openQty: number, qty: number, resolution: 'loss'|'returned'|null, note: string|null, journal: { id: string, number: string }|null, openValue?: number, lossValue?: number },
 *   policy: { requireCarrier: boolean },
 *   history: { id: string, toStatus: string, username: string|null, note: string|null, at: string }[],
 *   movements: { number: string, type: string, qty: number, dstBinId: string|null }[],
 *   exceptions: { id: string, number: string, kind: string, status: string, textAr: string, textEn?: string }[] }} Transfer
 */
const STEPS = ['draft', 'requested', 'approved', 'picking', 'ready', 'transit', 'received', 'done'];
/** status → the single forward action and the permission it needs */
const NEXT = {
  draft: { action: 'submit', perm: 'inventory.transfer' }, requested: { action: 'approve', perm: 'inventory.transfer.approve' }, approved: { action: 'start-picking', perm: 'inventory.transfer' },
  picking: { action: 'picking-done', perm: 'inventory.transfer' }, ready: { action: 'ship', perm: 'inventory.transfer' }, transit: { action: 'receive', perm: 'inventory.transfer' },
  partial: { action: 'receive', perm: 'inventory.transfer' }, received: { action: 'close', perm: 'inventory.transfer' },
};
const CONFIRM_SUBS = {
  submit: ['يُرسل للاعتماد (مدير المستودع)', 'Sent for approval (warehouse manager)'],
  approve: ['تُحجز كميات كل سطر في موقع مصدره لهذا التحويل — يُرفض الاعتماد إن لم يغطِّها الموقع', 'Every line is held in its source bin for this transfer — the approval is refused if the bin cannot cover it'],
  close: ['إقفال نهائي للتحويل', 'Final close'],
};
const RESOLUTION_LABELS = { loss: { ar: 'خسارة مخزون', en: 'Stock loss' }, returned: { ar: 'أُعيد إلى المصدر', en: 'Returned to the source' } };

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));

const q = useGet(() => (number.value ? `/inventory/transfers/${encodeURIComponent(number.value)}` : null));
/** @type {import('vue').ComputedRef<Transfer|undefined>} */
const tr = computed(() => q.data.value);
const act = useAction({ invalidate: ['inventory', 'dashboard', 'exceptions'] });

/** What arrives NOW per line while goods are on the road: { [lineNo]: number | null } (defaults to all that is still on the road). */
const rcv = ref({});
/** Receiving bin per line: { [lineNo]: code } — the line's own bin, else the bin the server suggests. */
const bins = ref({});
watch(() => (tr.value ? `${tr.value.id}|${tr.value.status}|${tr.value.receivedQty}` : ''), () => {
  if (!tr.value) return;
  rcv.value = Object.fromEntries(tr.value.lines.map((l) => [l.lineNo, l.remainingQty]));
  bins.value = Object.fromEntries(tr.value.lines.map((l) => [l.lineNo, l.toBin?.code || l.suggestedToBin?.code || '']));
}, { immediate: true });
const setRcv = (lineNo, v) => { rcv.value = { ...rcv.value, [lineNo]: v }; };
const setBin = (lineNo, v) => { bins.value = { ...bins.value, [lineNo]: v }; };
const sameBin = (a, b) => String(a || '').trim().toUpperCase() === String(b || '').trim().toUpperCase();
/** Reject / cancel dialog: { kind: 'reject' | 'cancel', note } | null */
const reject = ref(null);
const shipOpen = ref(false);
const varianceOpen = ref(false);

const s = computed(() => tr.value?.status || '');
const next = computed(() => NEXT[s.value]);
const canNext = computed(() => !!next.value && auth.can(next.value.perm) && (tr.value?.allowed.length || 0) > 0);
const canReject = computed(() => s.value === 'requested' && auth.can('inventory.transfer.approve'));
const canCancel = computed(() => ['draft', 'requested', 'approved', 'picking', 'ready'].includes(s.value) && auth.can('inventory.transfer'));
const canRcv = computed(() => ['transit', 'partial'].includes(s.value) && auth.can('inventory.transfer'));
const held = computed(() => ['approved', 'picking', 'ready'].includes(s.value));
const failed = computed(() => s.value === 'rejected' || s.value === 'cancelled');
const stepIdx = computed(() => {
  if (failed.value) { // the step the transfer had reached before it was rejected / cancelled
    const last = [...(tr.value?.history || [])].reverse().find((h) => h.toStatus !== s.value)?.toStatus || 'requested';
    return Math.max(1, STEPS.indexOf(last));
  }
  return s.value === 'partial' ? 6 : Math.max(0, STEPS.indexOf(s.value));
});
const steps = STEPS.map((k) => ({ label: { ar: TRANSFER_LABELS[k].ar.split(' — ')[0], en: TRANSFER_LABELS[k].en } }));
const wname = (w) => `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}`;
const nextLabel = computed(() => {
  if (s.value === 'partial') return { ar: 'استلام ما وصل', en: 'Receive what arrived' }; // closing waits for the variance
  const a = TRANSFER_LABELS[s.value]?.act;
  return a ? { ar: a[0], en: a[1] } : next.value?.action;
});

/** Why the receipt cannot be sent yet (null = it can): quantities within what is on the road, a bin for what arrives. */
const rcvIssue = computed(() => {
  const x = tr.value;
  if (!x || !canRcv.value) return null;
  let total = 0;
  for (const l of x.lines) {
    const n = Number(rcv.value[l.lineNo] ?? 0);
    if (!Number.isInteger(n) || n < 0 || n > l.remainingQty) return t(`سطر ${l.lineNo}: الواصل الآن خارج النطاق (0 – ${l.remainingQty})`, `Line ${l.lineNo}: arriving now is out of range (0 – ${l.remainingQty})`);
    if (n > 0 && !String(bins.value[l.lineNo] || '').trim()) return t(`سطر ${l.lineNo}: حدّد موقع الاستلام في ${x.toWarehouse.code}`, `Line ${l.lineNo}: choose the receiving bin in ${x.toWarehouse.code}`);
    total += n;
  }
  if (s.value === 'partial' && total === 0) return t('أدخل ما وصل الآن — وإن لم يعد شيء في الطريق فسوِّ الفرق', 'Enter what arrived now — if nothing more is coming, resolve the variance');
  return null;
});
const nextIssue = computed(() => (next.value?.action === 'receive' ? rcvIssue.value : null));
/** The variance can be resolved only by whoever approves transfers; everyone else is told so. */
const canResolve = computed(() => s.value === 'partial' && auth.can('inventory.transfer.approve'));
const resolveIssue = computed(() => (s.value === 'partial' && !canResolve.value ? t('تسوية الفرق تحتاج صلاحية اعتماد التحويلات (مدير المستودع)', 'Resolving the variance needs the transfer-approval permission (warehouse manager)') : null));

const post = (action, body, success) => act.run(() => api.postIdempotent(`/inventory/transfers/${tr.value.id}/${action}`, body), { success });
async function doNext() {
  const x = tr.value; const nx = next.value;
  if (!x || !nx) return;
  if (nx.action === 'ship') { act.clearError(); shipOpen.value = true; return; }
  const label = t(nextLabel.value.ar, nextLabel.value.en);
  if (nx.action === 'receive') {
    if (rcvIssue.value) return;
    const lines = x.lines.filter((l) => l.remainingQty > 0).map((l) => ({ lineNo: l.lineNo, receivedQty: Number(rcv.value[l.lineNo] ?? 0), toBin: String(bins.value[l.lineNo] || '').trim() || undefined }));
    const now = lines.reduce((n, l) => n + l.receivedQty, 0);
    const left = x.lines.reduce((n, l) => n + l.remainingQty, 0) - now;
    const sub = left > 0
      ? t(`يُستلم ${now} ويبقى ${left} في الطريق — يبقى التحويل «مستلم جزئيًا» حتى يصل الباقي أو يُسوّى الفرق`, `${now} received, ${left} still on the road — the transfer stays “partially received” until the rest arrives or the variance is resolved`)
      : t('تُقيد الكميات في مواقع الاستلام كحركات trf بتكلفة خروجها من المصدر', 'Quantities land in the receiving bins as trf movements, at the cost they left the source at');
    if (await confirm({ title: t('تأكيد الاستلام في الوجهة؟', 'Confirm receipt at destination?'), sub, tone: left > 0 ? 'danger' : 'primary', okLabel: label })) {
      await post('receive', { lines }, left > 0 ? { ar: `استُلم ${now} — باقٍ في الطريق ${left}`, en: `${now} received — ${left} still on the road` } : { ar: 'استُلم التحويل كاملًا في الوجهة', en: 'Transfer fully received' });
    }
    return;
  }
  const sub = CONFIRM_SUBS[nx.action] ? t(CONFIRM_SUBS[nx.action][0], CONFIRM_SUBS[nx.action][1]) : undefined;
  if (await confirm({ title: t(`${label}؟`, `${label}?`), sub, tone: 'primary', okLabel: label })) await post(nx.action, undefined, { ar: `تم: ${label}`, en: `Done: ${label}` });
}
async function doReject() {
  const r = reject.value;
  if (!r) return;
  const ok = await post(r.kind, { note: r.note || undefined }, r.kind === 'reject' ? { ar: 'رُفض التحويل', en: 'Transfer rejected' } : { ar: 'أُلغي التحويل — فُكّ حجز كمياته', en: 'Transfer cancelled — its held quantities were released' });
  if (ok !== undefined) reject.value = null;
}

const lineCols = computed(() => [
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.4fr)' },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '100px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '70px', kind: 'num' },
  { key: 'from', header: { ar: 'من موقع', en: 'From bin' }, width: '110px' },
  { key: 'to', header: canRcv.value ? { ar: 'موقع الاستلام', en: 'Receiving bin' } : { ar: 'إلى موقع', en: 'To bin' }, width: canRcv.value ? '156px' : '110px' },
  { key: 'got', header: { ar: 'المستلم', en: 'Received' }, width: '76px', kind: 'num' },
  { key: 'rcv', header: { ar: 'يصل الآن', en: 'Arriving now' }, width: '100px', hidden: !canRcv.value },
  { key: 'var', header: { ar: 'الفرق', en: 'Variance' }, width: '90px', kind: 'num' },
]);
/** Shortfall of a line: what would still be on the road after the receipt being entered, else what never arrived (null = nothing to compare yet). */
function lineVar(l) {
  if (canRcv.value) return -(l.remainingQty - Number(rcv.value[l.lineNo] ?? 0));
  if (l.receivedQty == null) return null;
  return l.receivedQty - l.qty;
}
const lineRowStyle = (l) => ((l.receivedQty != null && l.receivedQty < l.qty) || l.varianceQty > 0 ? { background: '#FFF8F8' } : null);
const excText = (x) => (lang.value === 'ar' ? x.textAr : x.textEn || x.textAr);
const dname = (d) => (lang.value === 'en' && d.nameEn) || d.nameAr;
</script>

<template>
  <!-- No `title`: the shell's default for /trf/:number is already "تحويل · <number>" with the number in Quicksand. -->
  <PageHead :sub="tr ? t(`${wname(tr.fromWarehouse)} ← ${wname(tr.toWarehouse)}`, `${wname(tr.fromWarehouse)} → ${wname(tr.toWarehouse)}`) : null">
    <Btn tone="soft" size="sm" :label="{ ar: '← كل التحويلات', en: '← All transfers' }" @click="router.push('/returns?tab=trf')" />
    <Btn v-if="tr" tone="soft" size="sm" :label="{ ar: 'ملصق التحويل', en: 'Transfer label' }" @click="showLabel({ type: 'code128', text: tr.number, title: tr.number, sub: `${tr.fromWarehouse?.code || ''} → ${tr.toWarehouse?.code || ''}` })" />
  </PageHead>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <ErrorBanner v-if="!reject" :error="act.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value" class="skel h-[120px]" />

  <template v-if="tr">
    <SectionCard selected>
      <div class="row wrap !gap-2.5">
        <div class="num text-[15px] font-extrabold">{{ tr.number }}</div>
        <div class="ltr text-[11px] font-extrabold text-brand-dark">{{ tr.fromWarehouse.code }} → {{ tr.toWarehouse.code }}</div>
        <Chip :map="TRANSFER_LABELS" :k="tr.status" />
        <div class="flex-1" />
        <Btn v-if="canReject" tone="dangerOutline" size="sm" :label="{ ar: 'رفض', en: 'Reject' }" @click="reject = { kind: 'reject', note: '' }" />
        <Btn v-if="canCancel" tone="soft" size="sm" :label="{ ar: 'إلغاء التحويل', en: 'Cancel' }" @click="reject = { kind: 'cancel', note: '' }" />
        <Btn v-if="s === 'partial'" tone="softAmber" :disabled="!canResolve" :title="resolveIssue || undefined" :label="{ ar: 'تسوية الفرق', en: 'Resolve variance' }" @click="varianceOpen = true" />
        <Btn v-if="canNext" tone="primary" :loading="act.pending.value" :disabled="!!nextIssue" :title="nextIssue || undefined" :label="nextLabel" @click="doNext" />
      </div>
      <div v-if="canNext && nextIssue" class="field-err mt-1.5 text-end">{{ nextIssue }}</div>
      <div v-if="resolveIssue" class="mt-1.5 text-end text-[10.5px] font-bold text-muted">{{ resolveIssue }}</div>
      <div class="mt-4"><Stepper :steps="steps" :current="stepIdx" :failed="failed" :max-width="760" /></div>

      <div class="kv-grid mt-3.5">
        <KV :k="{ ar: 'الطالب', en: 'Requested by' }" :v="tr.requestedBy || '—'" />
        <KV :k="{ ar: 'تاريخ الطلب', en: 'Request date' }"><span class="num">{{ fmtDateOnly(tr.date) }}</span></KV>
        <KV :k="{ ar: 'الوصول المتوقع', en: 'ETA' }"><span class="num">{{ fmtDateOnly(tr.eta) }}</span></KV>
        <KV :k="{ ar: 'السبب', en: 'Reason' }" :v="lang === 'ar' ? tr.reasonAr : tr.reasonEn" />
        <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="tr.notes || '—'" />
        <KV :k="{ ar: 'الأسطر · الكمية', en: 'Lines · qty' }">
          <span class="num">{{ tr.lines.length }} · {{ fmtNum(tr.totalQty) }}{{ tr.receivedQty ? ` / ${t('مستلم', 'received')} ${fmtNum(tr.receivedQty)}` : '' }}{{ tr.inTransitQty ? ` / ${t('في الطريق', 'on the road')} ${fmtNum(tr.inTransitQty)}` : '' }}{{ tr.varianceQty ? ` / ${t('فرق', 'variance')} ${fmtNum(tr.varianceQty)}` : '' }}</span>
        </KV>
        <KV v-if="held" :k="{ ar: 'محجوز بالمصدر', en: 'Held at source' }"><span class="num">{{ fmtNum(tr.heldQty) }}</span></KV>
        <KV :k="{ ar: 'اعتُمد', en: 'Approved' }"><span class="num">{{ fmtDate(tr.approvedAt) }}</span></KV>
        <KV :k="{ ar: 'شُحن', en: 'Shipped' }"><span class="num">{{ fmtDate(tr.shippedAt) }}</span></KV>
        <KV :k="{ ar: 'استُلم', en: 'Received' }"><span class="num">{{ fmtDate(tr.receivedAt) }}</span></KV>
        <KV :k="{ ar: 'أُقفل', en: 'Closed' }"><span class="num">{{ fmtDate(tr.closedAt) }}</span></KV>
        <template v-if="tr.shippedAt">
          <KV :k="{ ar: 'المركبة', en: 'Vehicle' }"><span v-if="tr.vehicle" class="num ltr">{{ tr.vehicle.code }} · {{ tr.vehicle.plateAr }}</span><span v-else class="muted">—</span></KV>
          <KV :k="{ ar: 'السائق', en: 'Driver' }"><span v-if="tr.driver">{{ dname(tr.driver) }} · <span class="num">{{ tr.driver.code }}</span></span><span v-else class="muted">—</span></KV>
          <KV :k="{ ar: 'الناقل', en: 'Carrier' }" :v="tr.carrier || '—'" />
          <KV :k="{ ar: 'البوليصة', en: 'Waybill' }"><span v-if="tr.waybill" class="num ltr">{{ tr.waybill }}</span><span v-else class="muted">—</span></KV>
          <KV v-if="tr.trip" :k="{ ar: 'الرحلة', en: 'Trip' }"><RouterLink :to="`/trip/${encodeURIComponent(tr.trip.number)}`" class="num ltr">{{ tr.trip.number }}</RouterLink></KV>
        </template>
        <template v-if="tr.variance?.resolution">
          <KV :k="{ ar: 'تسوية الفرق', en: 'Variance resolution' }">
            <Chip small :fg="tr.variance.resolution === 'loss' ? '#b23b3b' : '#0d7f93'" :bg="tr.variance.resolution === 'loss' ? '#fdecec' : '#d9f4f9'">{{ t(RESOLUTION_LABELS[tr.variance.resolution].ar, RESOLUTION_LABELS[tr.variance.resolution].en) }} · <span class="num">{{ fmtNum(tr.variance.qty) }}</span></Chip>
          </KV>
          <KV :k="{ ar: 'سبب التسوية', en: 'Resolution note' }" :v="tr.variance.note || '—'" />
          <KV v-if="tr.variance.journal" :k="{ ar: 'قيد الخسارة', en: 'Loss journal' }">
            <RouterLink v-if="auth.can('gl.view')" :to="`/jv/${encodeURIComponent(tr.variance.journal.number)}`" class="num ltr">{{ tr.variance.journal.number }}</RouterLink>
            <span v-else class="num ltr">{{ tr.variance.journal.number }}</span>
          </KV>
        </template>
      </div>

      <div class="mb-2 mt-4 text-[12px] font-extrabold">{{ t('الأصناف', 'Lines') }} <span class="card-count num">{{ tr.lines.length }}</span></div>
      <DataTable :columns="lineCols" :rows="tr.lines" dense :row-key="(l) => l.id" :row-style="lineRowStyle">
        <template #cell-product="{ row }"><ProductCell :p="row.product">{{ row.product.sku }} · {{ row.product.storageClass }}</ProductCell></template>
        <template #cell-batch="{ row }"><div><span class="cell-id !text-[9.5px]">{{ row.batchNo || row.batch?.batchNo || '—' }}</span><div v-if="row.batch?.expiryDate" class="cell-sub">{{ fmtDateOnly(row.batch.expiryDate) }}</div></div></template>
        <template #cell-from="{ row }"><span class="num ltr inline-block text-[9.5px] text-brand-dark">{{ tr.fromWarehouse.code }}/{{ row.fromBin.code }}</span></template>
        <template #cell-to="{ row }">
          <div v-if="canRcv && row.remainingQty > 0" class="min-w-0">
            <TextInput scan small dir="ltr" mono :model-value="bins[row.lineNo]" :placeholder="{ ar: 'موقع الاستلام', en: 'Receiving bin' }" @update:model-value="setBin(row.lineNo, $event)" />
            <template v-if="row.suggestedToBin">
              <div v-if="sameBin(bins[row.lineNo], row.suggestedToBin.code)" class="cell-sub">{{ t(`مقترح: ${row.suggestedToBin.reasonAr}`, `Suggested: ${row.suggestedToBin.reasonEn}`) }}</div>
              <button v-else type="button" class="cell-sub cursor-pointer border-0 bg-transparent p-0 font-[inherit] text-violet underline" @click="setBin(row.lineNo, row.suggestedToBin.code)">{{ t('المقترح:', 'Suggested:') }} <span class="num ltr">{{ row.suggestedToBin.code }}</span></button>
            </template>
          </div>
          <span v-else class="num ltr inline-block text-[9.5px] text-brand-dark">{{ tr.toWarehouse.code }}/{{ row.toBin?.code || '—' }}</span>
        </template>
        <template #cell-got="{ row }"><span class="cell-num">{{ row.receivedQty == null ? '—' : fmtNum(row.receivedQty) }}</span></template>
        <template #cell-rcv="{ row }">
          <NumberInput v-if="row.remainingQty > 0" small center class="!h-[30px] !w-20" :model-value="rcv[row.lineNo]" :min="0" :max="row.remainingQty" :step="1" @update:model-value="setRcv(row.lineNo, $event)" />
          <span v-else class="muted">—</span>
        </template>
        <template #cell-var="{ row }">
          <span v-if="lineVar(row) == null" class="muted">—</span>
          <span v-else :style="{ color: lineVar(row) === 0 ? '#1d7a3e' : signColor(lineVar(row)) }">{{ lineVar(row) === 0 ? '0 ✓' : fmtSigned(lineVar(row)) }}</span>
        </template>
      </DataTable>
      <Hint v-if="held" tone="teal">{{ t('معتمد — كميات هذا التحويل محجوزة في مواقع المصدر: لا يأخذها طلب بيع ولا نقل يدوي ولا تحويل آخر. إلغاء التحويل يفكّ الحجز.', 'Approved — the quantities of this transfer are held in their source bins: no sales order, manual move or other transfer can take them. Cancelling the transfer releases them.') }}</Hint>
      <Hint v-if="s === 'ready'" tone="amber">
        {{ t('الشحن يصرف الكميات المحجوزة كلها أو لا شيء، ويحفظ تكلفة خروجها ليُقيَّم بها الاستلام. الرصيد الذي وُضع في الحجر أو جُمّد بجرد بعد الاعتماد لا يُشحن.', 'Shipping moves the held quantities all-or-nothing and keeps the cost they left at for the receipt. Stock quarantined or frozen by a count after the approval does not ship.') }}
        <template v-if="tr.policy?.requireCarrier"> {{ t('سياسة النظام: يلزم مركبة وسائق أو ناقل مسمّى.', 'System policy: a vehicle with its driver, or a named carrier, is required.') }}</template>
      </Hint>
      <Hint v-if="s === 'transit'" tone="teal">{{ t('في العبور — الكميات خرجت من المصدر ولم تدخل الوجهة بعد (غير متاحة للبيع في أي منهما). أدخل لكل سطر ما وصل الآن وموقع استلامه (المقترح: الموقع الذي يحوي المنتج، وإلا أول موقع شاغر من فئته). ما لم يصل يبقى في الطريق ويُستلم لاحقًا، ويُفتح له استثناء لمدير المستودع.', 'In transit — quantities left the source and have not reached the destination yet (sellable in neither). Enter per line what arrived now and its receiving bin (suggested: the bin that holds the product, else the first free bin of its class). What did not arrive stays on the road to be received later, and raises an exception for the warehouse manager.') }}</Hint>
      <Hint v-if="s === 'partial'" tone="amber">{{ t(`مستلم جزئيًا — ${tr.inTransitQty} وحدة ما زالت في الطريق: استلم ما يصل لاحقًا، أو سوِّ الفرق — «أُعيد إلى المصدر» يعيده لرصيد المصدر، «خسارة مخزون» تُرحَّل بقيد محاسبي. لا يُقفل التحويل قبل ذلك.`, `Partially received — ${tr.inTransitQty} unit(s) are still on the road: receive them when they arrive, or resolve the variance — “returned to the source” puts it back in the source stock, “stock loss” posts a journal. The transfer cannot close before that.`) }}</Hint>

      <div v-if="tr.exceptions?.length" class="row wrap mt-3 !gap-1.5">
        <RouterLink v-for="x in tr.exceptions" :key="x.id" :to="`/exc/${encodeURIComponent(x.number)}`" class="no-underline">
          <Chip small :fg="x.status === 'resolved' ? '#1d7a3e' : '#b23b3b'" :bg="x.status === 'resolved' ? '#e6f9ec' : '#fdecec'">⚠&nbsp;<span class="num">{{ x.number }}</span>&nbsp;· {{ excText(x) }}</Chip>
        </RouterLink>
      </div>

      <div class="mt-4 text-[12px] font-extrabold">{{ t('الخط الزمني وسجل الموافقات', 'Timeline & approvals') }}</div>
      <StatusTimeline :history="tr.history || []" :map="TRANSFER_LABELS" :empty-text="{ ar: 'لا أحداث بعد', en: 'No events yet' }" />

      <template v-if="tr.movements?.length">
        <div class="mb-2 mt-3 text-[12px] font-extrabold">
          {{ t('حركات المخزون', 'Stock movements') }} <span class="card-count num">{{ tr.movements.length }}</span>
          <Btn tone="ghost" size="sm" :label="{ ar: 'عرض في السجل ←', en: 'Open in ledger →' }" @click="router.push(`/ledger?referenceNumber=${encodeURIComponent(tr.number)}`)" />
        </div>
        <div class="row wrap !gap-1.5">
          <Chip v-for="m in tr.movements" :key="m.number" small class="cursor-pointer" :map="MOVEMENT_LABELS" :k="m.type" @click="router.push(`/ledger?q=${encodeURIComponent(m.number)}`)"><span class="num ltr">{{ m.number }} · {{ m.dstBinId ? '+' : '−' }}{{ fmtNum(m.qty) }}</span></Chip>
        </div>
      </template>
    </SectionCard>
    <Hint>{{ t('مسودة ← بانتظار الاعتماد ← معتمد (تُحجز الكميات) ← قيد التجهيز ← جاهز للشحن ← In Transit ← مستلم جزئيًا (حتى يصل الباقي أو يُسوّى الفرق) ← مستلم ← مكتمل. الرفض والإلغاء ممكنان قبل الشحن فقط؛ بعده يُنشأ تحويل عكسي. كل انتقال موثق في Audit Trail باسم المستخدم.', 'Draft → requested → approved (quantities held) → picking → ready → in transit → partially received (until the rest arrives or the variance is resolved) → received → done. Reject and cancel are allowed only before shipping; afterwards create a reverse transfer. Every transition is audited under the user’s name.') }}</Hint>

    <TransferShipModal v-if="shipOpen" :transfer="tr" @close="shipOpen = false" />
    <TransferVarianceModal v-if="varianceOpen" :transfer="tr" @close="varianceOpen = false" />
  </template>

  <Modal :open="!!reject" :width="460" :title="reject?.kind === 'reject' ? { ar: 'رفض التحويل', en: 'Reject transfer' } : { ar: 'إلغاء التحويل', en: 'Cancel transfer' }" @close="reject = null">
    <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
    <TextArea v-if="reject" v-model="reject.note" autofocus :label="{ ar: 'السبب / ملاحظة (تُسجل في Audit)', en: 'Reason / note (audited)' }" />
    <template #footer>
      <div class="flex gap-2">
        <Btn tone="danger" class="!h-[42px] flex-1" :loading="act.pending.value" :label="reject?.kind === 'reject' ? { ar: 'تأكيد الرفض', en: 'Confirm reject' } : { ar: 'تأكيد الإلغاء', en: 'Confirm cancel' }" @click="doReject" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'رجوع', en: 'Back' }" @click="reject = null" />
      </div>
    </template>
  </Modal>
</template>
