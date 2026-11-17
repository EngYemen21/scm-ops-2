<script setup>
// Final decision of a return in `inspect`, per line: one row per received line, a line can be split over several
// decisions ("+ قرار آخر") whose quantities add up to what was received. Per row: quantity, decision pills, and what
// that decision needs — bin (restock only; empty = automatic placement by storage class), batch (+ expiry for a new
// batch) when the product tracks expiry, supplier for a return to the supplier.
// POST /api/returns/:number/decide { lines: [{ lineNo, parts: [{ qty, decision, binCode?, batchNo?, expiryDate?, supplierCode?, note? }] }] }
// — one stock movement per part; closes the return. Mount with v-if so every opening starts from a clean state.
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DateInput, ErrorBanner, Modal, NumberInput, SelectInput, TextInput } from '@/components';
import { bi, fmtDateOnly, fmtNum, t } from '@/i18n';
import { RETURN_DECISION_LABELS } from '@/shared';
import { DECISION_EFFECT, DECISION_STYLE, RETURN_CONDITION_LABELS, labelOf, pname } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const act = useAction();
// the supplier list decides whether "return to supplier" can be offered on a line that has no default supplier
const suppliers = useList('/suppliers', { pageSize: 200 });
const supplierItems = computed(() => suppliers.data.value?.items || []);
const requireBatch = computed(() => props.ret.flow?.requireBatchOnRestock !== false);

let seq = 0;
const newPart = (line, qty, decision = 'restock') => ({ id: ++seq, qty, decision, binCode: '', batchNo: '', expiryDate: '', supplierCode: line.suggestedSupplier?.code || '', note: '' });
const arrived = (l) => l.pendingQty ?? l.receivedQty ?? l.qty;
const lines = ref((props.ret.lines || []).filter((l) => arrived(l) > 0).map((l) => {
  const line = {
    lineNo: l.lineNo, name: pname(l.product), sku: l.product?.sku, received: arrived(l), condition: l.condition, inspectNote: l.inspectNote,
    batchNo: l.batchNo || l.batch?.batchNo || null, expiryDate: l.expiryDate || l.batch?.expiryDate || null,
    tracksExpiry: !!l.product?.tracksExpiry, needsBatch: !!l.needsBatch, batchOptions: l.batchOptions || [], suggestedSupplier: l.suggestedSupplier || null, parts: [],
  };
  line.parts.push(newPart(line, line.received));
  return line;
}));
const notReceived = computed(() => (props.ret.lines || []).filter((l) => arrived(l) === 0));

/** "Return to supplier" is offered only when a supplier can actually be named: a default one, or the list to choose from. */
const supAllowed = (line) => !!line.suggestedSupplier || supplierItems.value.length > 0;
const decisionsOf = (line) => Object.keys(RETURN_DECISION_LABELS).filter((k) => k !== 'sup' || supAllowed(line));
const supplierOpts = (line) => {
  const opts = supplierItems.value.map((s) => ({ v: s.code, l: `${s.nameAr} · ${s.code}` }));
  const d = line.suggestedSupplier;
  if (d && !opts.some((o) => o.v === d.code)) opts.unshift({ v: d.code, l: `${d.nameAr} · ${d.code}` });
  return opts;
};
const sumOf = (line) => line.parts.reduce((a, p) => a + (Number(p.qty) || 0), 0);
const leftOf = (line) => line.received - sumOf(line);
/** "+ قرار آخر": the new part takes what is still undecided; when everything is decided it takes one unit off the largest part. */
function split(line) {
  let qty = leftOf(line);
  if (qty <= 0) {
    const big = line.parts.reduce((a, p) => (!a || p.qty > a.qty ? p : a), null);
    if (!big || !(big.qty >= 2)) return;
    big.qty -= 1;
    qty = 1;
  }
  line.parts.push(newPart(line, qty, 'qtn'));
}
const removePart = (line, id) => { line.parts = line.parts.filter((p) => p.id !== id); };
/** One decision for the whole return: every line becomes a single part of its full quantity. */
function applyAll(k) { lines.value.forEach((line) => { if (k !== 'sup' || supAllowed(line)) line.parts = [newPart(line, line.received, k)]; }); }
const knownBatch = (line, p) => !!p.batchNo.trim() && line.batchOptions.some((b) => b.batchNo === p.batchNo.trim());
/** A batch field is shown where the goods stay in the warehouse and the product is tracked by expiry. */
const showBatch = (line, p) => line.tracksExpiry && ['restock', 'qtn', 'dmg'].includes(p.decision) && (!line.batchNo || !!p.batchNo);
const batchRequired = (line, p) => p.decision === 'restock' && line.needsBatch && requireBatch.value;

/** Pill colours of a decision option (selected = filled with the decision colour). */
function pillStyle(k, on) {
  const s = DECISION_STYLE[k] || { fg: '#55506a', bg: '#F1EFF6' };
  return { border: `1.5px solid ${on ? s.fg : '#E9E6F0'}`, background: on ? (k === 'dispose' ? s.bg : s.fg) : '#fff', color: on ? '#fff' : '#55506a' };
}

/** First reason the decision cannot be posted ({ar,en}), or null. */
const blocker = computed(() => {
  for (const line of lines.value) {
    const n = line.lineNo;
    if (!line.parts.length) return { ar: `السطر ${n}: أضف قرارًا`, en: `Line ${n}: add a decision` };
    for (const p of line.parts) {
      if (!(Number.isInteger(p.qty) && p.qty >= 1)) return { ar: `السطر ${n}: كمية كل قرار عدد صحيح أكبر من صفر`, en: `Line ${n}: every decision needs a whole quantity above zero` };
      if (batchRequired(line, p) && !p.batchNo.trim()) return { ar: `السطر ${n}: المنتج متتبَّع بالصلاحية — أدخل رقم الدفعة لإعادته للمخزون`, en: `Line ${n}: the product tracks expiry — enter the batch to restock it` };
      if (p.decision === 'sup' && !p.supplierCode) return { ar: `السطر ${n}: اختر المورد الذي تُرجَع إليه الكمية`, en: `Line ${n}: choose the supplier the quantity goes back to` };
    }
    const sum = sumOf(line);
    if (sum !== line.received) return { ar: `السطر ${n}: مجموع القرارات ${fmtNum(sum)} ولا يساوي المستلم ${fmtNum(line.received)}`, en: `Line ${n}: decisions add up to ${fmtNum(sum)}, received is ${fmtNum(line.received)}` };
  }
  return lines.value.length ? null : { ar: 'لا كمية مستلمة لاتخاذ قرار بشأنها', en: 'Nothing was received to decide on' };
});
const single = computed(() => { const ks = new Set(lines.value.flatMap((l) => l.parts.map((p) => p.decision))); return ks.size === 1 ? [...ks][0] : null; });
const submitLabel = computed(() => (single.value ? RETURN_DECISION_LABELS[single.value] : { ar: 'تسجيل القرارات', en: 'Record decisions' }));

async function submit() {
  if (blocker.value) return;
  const body = { lines: lines.value.map((line) => ({ lineNo: line.lineNo, parts: line.parts.map((p) => {
    const inStock = ['restock', 'qtn', 'dmg'].includes(p.decision);
    return {
      qty: p.qty, decision: p.decision, binCode: (p.decision === 'restock' && p.binCode.trim()) || undefined,
      batchNo: (inStock && p.batchNo.trim()) || undefined, expiryDate: (inStock && p.batchNo.trim() && p.expiryDate) || undefined,
      supplierCode: (p.decision === 'sup' && p.supplierCode) || undefined, note: p.note.trim() || undefined,
    };
  }) })) };
  const r = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(props.ret.number)}/decide`, body), {
    success: (x) => x?.message || t('سُجل القرار وأُقفل المرتجع', 'Decision recorded — return closed'), invalidate: ['returns', 'inventory', 'dashboard', 'finance'],
  });
  if (r !== undefined) { emit('close'); emit('done'); }
}
</script>

<template>
  <Modal open :width="820" @close="emit('close')">
    <template #title>
      {{ t('القرار النهائي للمرتجع', 'Final return decision') }}
      <div class="drawer-sub"><span class="num">{{ ret.number }}</span> · <span class="num">{{ lines.length }}</span> {{ t('سطر مستلم — قرار لكل سطر', 'received lines — a decision per line') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />

    <div v-if="lines.length > 1" class="row wrap mb-3 !gap-1.5">
      <span class="text-[10px] font-extrabold text-muted">{{ t('قرار واحد لكل الأسطر:', 'One decision for all lines:') }}</span>
      <button v-for="(l, k) in RETURN_DECISION_LABELS" :key="k" type="button" class="cursor-pointer rounded-full px-2.5 py-[5px] font-[inherit] text-[10px] font-extrabold" :style="pillStyle(k, false)" @click="applyAll(k)">{{ bi(l) }}</button>
    </div>

    <div v-for="line in lines" :key="line.lineNo" class="mb-2.5 rounded-[10px] border border-line-2 px-3 py-2.5">
      <div class="row wrap">
        <span class="num text-[10px] text-faint">#{{ line.lineNo }}</span>
        <b class="text-[11.5px]">{{ line.name }}</b><span class="cell-sub num">{{ line.sku }}</span>
        <span v-if="line.batchNo" class="num text-[9.5px] text-muted">{{ t('دفعة', 'batch') }} {{ line.batchNo }}<template v-if="line.expiryDate"> · {{ fmtDateOnly(line.expiryDate) }}</template></span>
        <Chip v-if="line.condition" small fg="#55506a" bg="#F1EFF6" :label="labelOf(RETURN_CONDITION_LABELS, line.condition)" />
        <div class="grow" />
        <span class="text-[10.5px] font-extrabold" :class="leftOf(line) === 0 ? 'text-ok' : 'text-bad'">
          <span class="num">{{ fmtNum(sumOf(line)) }}</span> / <span class="num">{{ fmtNum(line.received) }}</span> {{ t('مستلم', 'received') }}
        </span>
      </div>
      <div v-if="line.inspectNote" class="muted mt-0.5 text-[10px]">{{ line.inspectNote }}</div>

      <div v-for="p in line.parts" :key="p.id" class="mt-2 border-t border-[#F7F6FA] pt-2">
        <div class="row wrap !gap-1.5">
          <NumberInput v-model="p.qty" small center field-class="!w-[76px]" :min="1" :max="line.received" :step="1" :label="{ ar: 'الكمية', en: 'Qty' }" />
          <div class="row wrap !gap-1.5 self-end pb-0.5">
            <button v-for="k in decisionsOf(line)" :key="k" type="button" class="cursor-pointer rounded-full px-2.5 py-[6px] font-[inherit] text-[10px] font-extrabold" :style="pillStyle(k, p.decision === k)" @click="p.decision = k">{{ bi(RETURN_DECISION_LABELS[k]) }}</button>
          </div>
          <div class="grow" />
          <button v-if="line.parts.length > 1" type="button" class="x-btn self-end" :aria-label="t('حذف القرار', 'Remove decision')" @click="removePart(line, p.id)">✕</button>
        </div>
        <div class="mt-1 text-[9.5px] text-muted">{{ bi(DECISION_EFFECT[p.decision]) }}</div>
        <div class="form-grid mt-1.5">
          <TextInput v-if="p.decision === 'restock'" v-model="p.binCode" scan small dir="ltr" mono :label="{ ar: 'موقع الإعادة للمخزون (Bin)', en: 'Restock bin' }" :placeholder="{ ar: 'فارغ = تلقائي', en: 'empty = automatic' }"
                     :hint="{ ar: 'اتركه فارغًا للتخزين التلقائي حسب فئة تخزين الصنف', en: 'Leave empty for automatic placement by the storage class of the item' }" />
          <template v-if="showBatch(line, p)">
            <TextInput v-model="p.batchNo" scan small dir="ltr" mono :required="batchRequired(line, p)" :label="{ ar: 'رقم الدفعة', en: 'Batch no.' }"
                       :hint="batchRequired(line, p) ? { ar: 'إلزامي: الصنف متتبَّع بالصلاحية ولا دفعة على السطر', en: 'Required: the item tracks expiry and the line has no batch' } : { ar: 'اختياري', en: 'optional' }" />
            <DateInput v-if="p.batchNo.trim() && !knownBatch(line, p)" v-model="p.expiryDate" small :label="{ ar: 'تاريخ الانتهاء', en: 'Expiry date' }" :hint="{ ar: 'مطلوب إن كانت الدفعة جديدة على الصنف', en: 'Required when the batch is new for this item' }" />
          </template>
          <SelectInput v-if="p.decision === 'sup'" v-model="p.supplierCode" small required :options="supplierOpts(line)" :label="{ ar: 'المورد', en: 'Supplier' }" :placeholder="{ ar: '— اختر المورد —', en: '— choose a supplier —' }"
                       :hint="line.suggestedSupplier ? { ar: `المقترح: ${line.suggestedSupplier.nameAr} (مورّد الدفعة / المورد المفضل)`, en: `Suggested: ${line.suggestedSupplier.nameEn || line.suggestedSupplier.nameAr}` } : null" />
          <TextInput v-model="p.note" small :label="{ ar: 'ملاحظة', en: 'Note' }" :placeholder="{ ar: 'اختياري', en: 'optional' }" />
        </div>
        <div v-if="showBatch(line, p) && line.batchOptions.length" class="row wrap mt-1 !gap-1">
          <span class="text-[9px] text-faint">{{ t('دفعات الصنف:', 'Batches:') }}</span>
          <button v-for="b in line.batchOptions" :key="b.batchNo" type="button" class="pill !px-2 !py-0.5 !text-[9px]" :class="{ active: p.batchNo === b.batchNo }" @click="p.batchNo = b.batchNo; p.expiryDate = ''">
            <span class="num">{{ b.batchNo }}</span><template v-if="b.expiryDate"> · <span class="num">{{ fmtDateOnly(b.expiryDate) }}</span></template>
          </button>
        </div>
      </div>
      <div class="row mt-2">
        <Btn size="sm" tone="soft" :disabled="line.received < 2" :label="{ ar: '+ قرار آخر', en: '+ Another decision' }" @click="split(line)" />
        <span v-if="leftOf(line) !== 0" class="text-[10px] font-bold text-bad">{{ leftOf(line) > 0 ? t(`باقٍ ${fmtNum(leftOf(line))} بلا قرار`, `${fmtNum(leftOf(line))} left undecided`) : t(`زائد ${fmtNum(-leftOf(line))} على المستلم`, `${fmtNum(-leftOf(line))} over the received quantity`) }}</span>
      </div>
    </div>

    <div v-if="notReceived.length" class="hint amber">{{ t(`${fmtNum(notReceived.length)} سطر لم يصل منه شيء — لا قرار عليه ويُقفل كغير مستلم.`, `${fmtNum(notReceived.length)} line(s) did not arrive — no decision, closed as not received.`) }}</div>
    <div class="hint teal mt-2.5">{{ t('كل قرار حركة مخزون من منطقة المرتجعات RET-01: للمخزون → موقع تخزين (فارغ = تلقائي حسب فئة التخزين) · حجر → QTN-01 · تالف → DMG-01 (يبقى في الرصيد خارج المتاح) · إعدام → يخرج من المخزون · للمورد → يخرج ويصدر به إشعار مدين.', 'Every decision is a stock movement out of the returns area RET-01: restock → a storage bin (empty = automatic by storage class) · quarantine → QTN-01 · damaged → DMG-01 (on hand, not available) · scrap → leaves stock · supplier → leaves stock with a debit note.') }}</div>

    <template #footer>
      <div v-if="blocker" class="mb-1.5 text-[10px] font-bold text-bad">{{ t(blocker.ar, blocker.en) }}</div>
      <div class="row">
        <Btn :tone="(single && DECISION_STYLE[single]?.tone) || 'primary'" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!blocker" @click="submit">{{ bi(submitLabel) }}</Btn>
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
