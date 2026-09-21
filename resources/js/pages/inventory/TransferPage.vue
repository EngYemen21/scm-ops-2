<script setup>
// Transfer detail (deep link /trf/:number): stepper, meta, lines with received-qty entry while in transit,
// timeline / movements / exceptions, and the submit → approve → pick → ship → receive → close actions.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KV, Modal, NumberInput, PageHead, SectionCard, Stepper, TextArea } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { MOVEMENT_LABELS, TRANSFER_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm, showLabel } from '@/stores/ui';
import Hint from './Hint.vue';
import ProductCell from './ProductCell.vue';
import StatusTimeline from './StatusTimeline.vue';
import { fmtSigned, signColor } from './shared';

/**
 * @typedef {{ id: string, lineNo: number, qty: number, receivedQty: number|null, batchNo: string|null, product: { sku: string, nameAr: string, nameEn: string, storageClass: string },
 *   batch: { batchNo: string, expiryDate: string|null }|null, fromBin: { code: string }, toBin: { code: string }|null }} TransferLine
 * @typedef {{ id: string, number: string, status: string, fromWarehouse: { code: string, nameAr: string, nameEn: string }, toWarehouse: { code: string, nameAr: string, nameEn: string },
 *   reasonAr: string, reasonEn: string, notes: string|null, requestedBy: string|null, date: string, eta: string|null, approvedAt: string|null, shippedAt: string|null,
 *   receivedAt: string|null, closedAt: string|null, lines: TransferLine[], totalQty: number, receivedQty: number, allowed: string[],
 *   history: { id: string, toStatus: string, username: string|null, note: string|null, at: string }[],
 *   movements: { number: string, type: string, qty: number, dstBinId: string|null }[],
 *   exceptions: { id: string, number: string, kind: string, status: string, textAr: string, textEn?: string }[] }} Transfer
 */
const STEPS = ['draft', 'requested', 'approved', 'picking', 'ready', 'transit', 'received', 'done'];
/** status → the single forward action and the permission it needs */
const NEXT = {
  draft: { action: 'submit', perm: 'inventory.transfer' }, requested: { action: 'approve', perm: 'inventory.transfer.approve' }, approved: { action: 'start-picking', perm: 'inventory.transfer' },
  picking: { action: 'picking-done', perm: 'inventory.transfer' }, ready: { action: 'ship', perm: 'inventory.transfer' }, transit: { action: 'receive', perm: 'inventory.transfer' },
  received: { action: 'close', perm: 'inventory.transfer' }, partial: { action: 'close', perm: 'inventory.transfer' },
};
const CONFIRM_SUBS = {
  submit: ['يُرسل للاعتماد (مدير المستودع)', 'Sent for approval (warehouse manager)'], approve: ['يصبح جاهزًا للتجهيز في المصدر', 'Becomes ready for picking at the source'],
  ship: ['تُصرف الكميات من مواقع المصدر كحركات transit — لا رصيد سالب', 'Quantities leave the source bins as transit movements — no negative stock'], close: ['إقفال نهائي للتحويل', 'Final close'],
};

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));

const q = useGet(() => (number.value ? `/inventory/transfers/${encodeURIComponent(number.value)}` : null));
/** @type {import('vue').ComputedRef<Transfer|undefined>} */
const tr = computed(() => q.data.value);
const act = useAction({ invalidate: ['inventory', 'dashboard', 'exceptions'] });

/** Received quantities being entered while in transit: { [lineNo]: number | null } (defaults to the full qty). */
const rcv = ref({});
watch(() => (tr.value ? `${tr.value.id}|${tr.value.status}` : ''), () => { if (tr.value) rcv.value = Object.fromEntries(tr.value.lines.map((l) => [l.lineNo, l.receivedQty ?? l.qty])); }, { immediate: true });
const setRcv = (lineNo, v) => { rcv.value = { ...rcv.value, [lineNo]: v }; };
/** Reject / cancel dialog: { kind: 'reject' | 'cancel', note } | null */
const reject = ref(null);

const s = computed(() => tr.value?.status || '');
const next = computed(() => NEXT[s.value]);
const canNext = computed(() => !!next.value && auth.can(next.value.perm) && (tr.value?.allowed.length || 0) > 0);
const canReject = computed(() => s.value === 'requested' && auth.can('inventory.transfer.approve'));
const canCancel = computed(() => ['draft', 'requested', 'approved', 'picking', 'ready'].includes(s.value) && auth.can('inventory.transfer'));
const canRcv = computed(() => s.value === 'transit' && auth.can('inventory.transfer'));
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
const nextLabel = computed(() => { const a = TRANSFER_LABELS[s.value]?.act; return a ? { ar: a[0], en: a[1] } : next.value?.action; });

const post = (action, body, success) => act.run(() => api.postIdempotent(`/inventory/transfers/${tr.value.id}/${action}`, body), { success });
async function doNext() {
  const x = tr.value; const nx = next.value;
  if (!x || !nx) return;
  const a = TRANSFER_LABELS[s.value]?.act;
  const label = a ? t(a[0], a[1]) : nx.action;
  if (nx.action === 'receive') {
    const qtyOf = (lineNo) => x.lines.find((l) => l.lineNo === lineNo).qty;
    const lines = x.lines.map((l) => ({ lineNo: l.lineNo, receivedQty: rcv.value[l.lineNo] ?? l.qty }));
    const bad = lines.find((l) => l.receivedQty < 0 || l.receivedQty > qtyOf(l.lineNo));
    if (bad) { await confirm({ title: t(`سطر ${bad.lineNo}: المستلم خارج النطاق (0 – المرسل)`, `Line ${bad.lineNo}: received qty out of range (0 – shipped)`), okLabel: t('حسنًا', 'OK'), tone: 'dark' }); return; }
    const short = lines.filter((l) => l.receivedQty < qtyOf(l.lineNo));
    const sub = short.length
      ? t(`${short.length} سطر بكمية ناقصة — سيُسجل التحويل «مستلم جزئيًا» ويُفتح استثناء فروقات`, `${short.length} line(s) short — transfer becomes “partially received” and a variance exception is raised`)
      : t('الكميات تُقيد في مواقع الوجهة كحركات trf', 'Quantities land in the destination bins as trf movements');
    if (await confirm({ title: t('تأكيد الاستلام في الوجهة؟', 'Confirm receipt at destination?'), sub, tone: short.length ? 'danger' : 'primary', okLabel: label })) {
      await post('receive', { lines }, short.length ? { ar: 'استُلم التحويل جزئيًا — أُنشئ استثناء فروقات', en: 'Received with variance — exception raised' } : { ar: 'استُلم التحويل في الوجهة', en: 'Transfer received' });
    }
    return;
  }
  const sub = CONFIRM_SUBS[nx.action] ? t(CONFIRM_SUBS[nx.action][0], CONFIRM_SUBS[nx.action][1]) : undefined;
  if (await confirm({ title: t(`${label}؟`, `${label}?`), sub, tone: 'primary', okLabel: label })) await post(nx.action, undefined, { ar: `تم: ${label}`, en: `Done: ${label}` });
}
async function doReject() {
  const r = reject.value;
  if (!r) return;
  const ok = await post(r.kind, { note: r.note || undefined }, r.kind === 'reject' ? { ar: 'رُفض التحويل', en: 'Transfer rejected' } : { ar: 'أُلغي التحويل', en: 'Transfer cancelled' });
  if (ok !== undefined) reject.value = null;
}

const lineCols = [
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.4fr)' },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '100px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num' },
  { key: 'from', header: { ar: 'من موقع', en: 'From bin' }, width: '110px' },
  { key: 'to', header: { ar: 'إلى موقع', en: 'To bin' }, width: '110px' },
  { key: 'rcv', header: { ar: 'المستلم', en: 'Received' }, width: '110px' },
  { key: 'var', header: { ar: 'الفرق', en: 'Variance' }, width: '80px', kind: 'num' },
];
/** Received − shipped for a line (null while nothing was received / entered). */
function lineVar(l) {
  const r = canRcv.value ? rcv.value[l.lineNo] : l.receivedQty;
  return r == null ? null : r - l.qty;
}
const lineRowStyle = (l) => (l.receivedQty != null && l.receivedQty < l.qty ? { background: '#FFF8F8' } : null);
const excText = (x) => (lang.value === 'ar' ? x.textAr : x.textEn || x.textAr);
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
        <Btn v-if="canNext" tone="primary" :loading="act.pending.value" :label="nextLabel" @click="doNext" />
      </div>
      <div class="mt-4"><Stepper :steps="steps" :current="stepIdx" :failed="failed" :max-width="760" /></div>

      <div class="kv-grid mt-3.5">
        <KV :k="{ ar: 'الطالب', en: 'Requested by' }" :v="tr.requestedBy || '—'" />
        <KV :k="{ ar: 'تاريخ الطلب', en: 'Request date' }"><span class="num">{{ fmtDateOnly(tr.date) }}</span></KV>
        <KV :k="{ ar: 'الوصول المتوقع', en: 'ETA' }"><span class="num">{{ fmtDateOnly(tr.eta) }}</span></KV>
        <KV :k="{ ar: 'السبب', en: 'Reason' }" :v="lang === 'ar' ? tr.reasonAr : tr.reasonEn" />
        <KV :k="{ ar: 'ملاحظات', en: 'Notes' }" :v="tr.notes || '—'" />
        <KV :k="{ ar: 'الأسطر · الكمية', en: 'Lines · qty' }"><span class="num">{{ tr.lines.length }} · {{ fmtNum(tr.totalQty) }}{{ tr.receivedQty ? ` / ${t('مستلم', 'received')} ${fmtNum(tr.receivedQty)}` : '' }}</span></KV>
        <KV :k="{ ar: 'اعتُمد', en: 'Approved' }"><span class="num">{{ fmtDate(tr.approvedAt) }}</span></KV>
        <KV :k="{ ar: 'شُحن', en: 'Shipped' }"><span class="num">{{ fmtDate(tr.shippedAt) }}</span></KV>
        <KV :k="{ ar: 'استُلم', en: 'Received' }"><span class="num">{{ fmtDate(tr.receivedAt) }}</span></KV>
        <KV :k="{ ar: 'أُقفل', en: 'Closed' }"><span class="num">{{ fmtDate(tr.closedAt) }}</span></KV>
      </div>

      <div class="mb-2 mt-4 text-[12px] font-extrabold">{{ t('الأصناف', 'Lines') }} <span class="card-count num">{{ tr.lines.length }}</span></div>
      <DataTable :columns="lineCols" :rows="tr.lines" dense :min-width="820" :row-key="(l) => l.id" :row-style="lineRowStyle">
        <template #cell-product="{ row }"><ProductCell :p="row.product">{{ row.product.sku }} · {{ row.product.storageClass }}</ProductCell></template>
        <template #cell-batch="{ row }"><div><span class="cell-id !text-[9.5px]">{{ row.batchNo || row.batch?.batchNo || '—' }}</span><div v-if="row.batch?.expiryDate" class="cell-sub">{{ fmtDateOnly(row.batch.expiryDate) }}</div></div></template>
        <template #cell-from="{ row }"><span class="num ltr inline-block text-[9.5px] text-brand-dark">{{ tr.fromWarehouse.code }}/{{ row.fromBin.code }}</span></template>
        <template #cell-to="{ row }"><span class="num ltr inline-block text-[9.5px] text-brand-dark">{{ tr.toWarehouse.code }}/{{ row.toBin?.code || '—' }}</span></template>
        <template #cell-rcv="{ row }">
          <NumberInput v-if="canRcv" small center class="!h-[30px] !w-20" :model-value="rcv[row.lineNo]" :min="0" :max="row.qty" :step="1" @update:model-value="setRcv(row.lineNo, $event)" />
          <span v-else class="cell-num">{{ row.receivedQty == null ? '—' : fmtNum(row.receivedQty) }}</span>
        </template>
        <template #cell-var="{ row }">
          <span v-if="lineVar(row) == null" class="muted">—</span>
          <span v-else :style="{ color: lineVar(row) === 0 ? '#1d7a3e' : signColor(lineVar(row)) }">{{ lineVar(row) === 0 ? '0 ✓' : fmtSigned(lineVar(row)) }}</span>
        </template>
      </DataTable>
      <Hint v-if="s === 'transit'" tone="teal">{{ t('في العبور — الكميات خرجت من المصدر ولم تدخل الوجهة بعد (غير متاحة للبيع في أي منهما). أدخل المستلم لكل سطر (افتراضيًا الكمية كاملة)؛ أي نقص يُنشئ استثناء فروقات لمدير المستودع.', 'In transit — quantities left the source and have not reached the destination yet (sellable in neither). Enter received per line (defaults to full qty); any shortfall raises a variance exception for the warehouse manager.') }}</Hint>
      <Hint v-if="s === 'ready'" tone="amber">{{ t('الشحن يفحص المتاح في كل موقع مصدر أولًا ثم يصرف الكل أو لا شيء — لا رصيد سالب.', 'Shipping checks availability at every source bin first, then deducts all-or-nothing — never negative.') }}</Hint>
      <Hint v-if="s === 'partial'" tone="amber">{{ t('استُلم جزئيًا — الفروقات موثقة في استثناء؛ الإقفال بالفروقات يُنهي التحويل كما هو.', 'Partially received — variances are documented in an exception; closing with variance finalises as-is.') }}</Hint>

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
    <Hint>{{ t('مسودة ← بانتظار الاعتماد ← معتمد ← قيد التجهيز ← جاهز للشحن ← In Transit ← مستلم / مستلم جزئيًا ← مكتمل. الرفض والإلغاء ممكنان قبل الشحن فقط؛ بعده يُنشأ تحويل عكسي. كل انتقال موثق في Audit Trail باسم المستخدم.', 'Draft → requested → approved → picking → ready → in transit → received / partial → done. Reject and cancel are allowed only before shipping; afterwards create a reverse transfer. Every transition is audited under the user’s name.') }}</Hint>
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
