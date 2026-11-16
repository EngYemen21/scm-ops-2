<script setup>
// Receiving lines: one card per shipment line — the product and its facts (ordered · received before · open) on the
// first row, then a labelled, evenly sized field grid (accepted / damaged / rejected · batch · mfg · expiry · QC note)
// that wraps instead of scrolling sideways, and a running summary of what this receipt will do to the line.
// A line that arrives in more than one batch gets "+ another batch": every extra row is a quantity carved out of the
// accepted total with its own batch number and dates; what is left belongs to the first batch (the line's own fields).
// Read-only once the shipment is no longer being inspected.
//   <ReceiveLinesGrid v-model:drafts="drafts" :lines="shipment.lines" :editable="editable" :rejected-stays-open="shipment.policy?.rejectedStaysOpen" />
import { watch } from 'vue';
import { Chip, ScanButton } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { draftSum, emptyBatch, emptyDraft, firstBatchQty, parseQty, pn, splitProblem } from './shared';

const props = defineProps({
  lines: { type: Array, required: true },
  editable: { type: Boolean, default: false },
  /** `{ [lineNo]: ReceiveDraft }` */
  drafts: { type: Object, required: true },
  /** Policy `procurement.rejectedStaysOpen`: rejected goods are still owed by the supplier (default) or count as fulfilled. */
  rejectedStaysOpen: { type: Boolean, default: true },
});
const emit = defineEmits(['update:drafts']);

const draftOf = (l) => props.drafts[l.lineNo] || emptyDraft(l);
const over = (l) => draftSum(draftOf(l)) > l.openQty;
/** Still owed on the purchase order after this receipt: what did not come, plus what was rejected (it never entered). */
const remaining = (l) => Math.max(0, l.openQty - draftSum(draftOf(l))) + (props.rejectedStaysOpen ? Number(draftOf(l).rejectedQty) || 0 : 0);
const extraOf = (l) => draftOf(l).extra || [];
const splitErr = (l) => splitProblem(l, draftOf(l));
const needsBatch = (l) => l.product.tracksExpiry && !draftOf(l).batchNo;
const needsExpiry = (l) => l.product.tracksExpiry && !draftOf(l).expiryDate;
// Edits are merged into the LATEST drafts, not into the prop: two changes in the same tick (a scanner that fills batch
// and quantity at once, browser autofill) would otherwise each start from the old prop and the last one would win.
let latest = props.drafts;
watch(() => props.drafts, (v) => { latest = v; });
const set = (l, patch) => { latest = { ...latest, [l.lineNo]: { ...(latest[l.lineNo] || emptyDraft(l)), ...patch } }; emit('update:drafts', latest); };
// Digits only; the box is re-synced so letters never show.
function onQty(e, l, key) { const n = parseQty(e.target.value); e.target.value = n ?? ''; set(l, { [key]: n }); }
// extra batches of a line
const addBatch = (l) => set(l, { extra: [...extraOf(l), emptyBatch()] });
const removeBatch = (l, i) => set(l, { extra: extraOf(l).filter((_, k) => k !== i) });
const setBatch = (l, i, patch) => set(l, { extra: extraOf(l).map((b, k) => (k === i ? { ...b, ...patch } : b)) });
function onBatchQty(e, l, i) { const n = parseQty(e.target.value); e.target.value = n ?? ''; setBatch(l, i, { qty: n }); }
const suggestion = (l) => (lang.value === 'ar' ? l.suggestionAr : l.suggestionEn);
const QTY = 'inp sm num !h-9 w-full !text-[11.5px] !font-bold';
</script>

<template>
  <div class="col !gap-2.5">
    <div v-for="l in lines" :key="l.id" class="rounded-xl border bg-white" :class="over(l) ? 'border-[#F3C4C4] bg-[#FFF8F8]' : 'border-line-2'">
      <!-- product + facts -->
      <div class="flex flex-wrap items-start gap-3 border-b border-[#F7F6FA] px-3.5 py-2.5">
        <div class="min-w-[180px] flex-1">
          <div class="text-[12px] font-extrabold text-ink">{{ pn(l.product) }}</div>
          <div class="row wrap mt-1 !gap-1.5">
            <span class="num text-[9px] text-faint">{{ l.product.sku }}</span>
            <Chip v-if="l.product.tracksExpiry" small fg="#654e92" bg="#efeaf8" :label="{ ar: 'دفعة + صلاحية إلزامية', en: 'Batch + expiry required' }" />
            <Chip v-if="l.suggestedBin?.code" small fg="#0d7f93" bg="#d9f4f9" :label="{ ar: `موقع مقترح ${l.suggestedBin.code}`, en: `Suggested bin ${l.suggestedBin.code}` }" />
            <span v-else class="text-[9px] text-faint">{{ suggestion(l) }}</span>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-1.5">
          <div class="rounded-lg bg-soft px-3 py-1.5 text-center"><div class="num text-[13px] font-bold text-muted">{{ fmtNum(l.orderedQty) }}</div><div class="text-[8.5px] font-extrabold text-faint">{{ t('مطلوب', 'Ordered') }}</div></div>
          <div class="rounded-lg bg-soft px-3 py-1.5 text-center">
            <div class="num text-[13px] font-bold">{{ fmtNum(l.previouslyReceived) }}</div>
            <div class="text-[8.5px] font-extrabold text-faint">{{ t('مستلم سابقًا', 'Received before') }}{{ l.damagedQty || l.rejectedQty ? ` · ${l.damagedQty ? l.damagedQty + ' ' + t('تالف', 'dmg') : ''}${l.rejectedQty ? ' ' + l.rejectedQty + ' ' + t('مرفوض', 'rej') : ''}` : '' }}</div>
          </div>
          <div class="rounded-lg px-3 py-1.5 text-center" :class="l.openQty ? 'bg-[#FBF0DD]' : 'bg-ok-soft'"><div class="num text-[13px] font-bold" :class="l.openQty ? 'text-warn' : 'text-ok'">{{ fmtNum(l.openQty) }}</div><div class="text-[8.5px] font-extrabold text-faint">{{ t('المتبقي', 'Open') }}</div></div>
        </div>
      </div>

      <!-- entry -->
      <div v-if="editable" class="px-3.5 py-3">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(138px,1fr))] gap-x-3 gap-y-2.5">
          <div class="field">
            <label class="field-l">{{ t('مستلم سليم', 'Accepted') }} <span class="text-bad">*</span></label>
            <input :value="draftOf(l).acceptedQty ?? ''" placeholder="0" dir="ltr" inputmode="numeric" :class="[QTY, '!border-[#BFE8CC] focus:!border-[#1d7a3e]']" @input="onQty($event, l, 'acceptedQty')">
            <div v-if="over(l)" class="field-err">{{ t(`المجموع يتجاوز المتبقي (${l.openQty})`, `Total exceeds the open quantity (${l.openQty})`) }}</div>
          </div>
          <div class="field">
            <label class="field-l">{{ t('تالف', 'Damaged') }}</label>
            <input :value="draftOf(l).damagedQty ?? ''" placeholder="0" dir="ltr" inputmode="numeric" :class="[QTY, '!border-[#F3C4C4] focus:!border-[#b23b3b]']" @input="onQty($event, l, 'damagedQty')">
          </div>
          <div class="field">
            <label class="field-l">{{ t('مرفوض QC', 'Rejected (QC)') }}</label>
            <input :value="draftOf(l).rejectedQty ?? ''" placeholder="0" dir="ltr" inputmode="numeric" :class="[QTY, '!border-[#F0DEB8] focus:!border-[#b26a16]']" @input="onQty($event, l, 'rejectedQty')">
          </div>
          <div class="field">
            <label class="field-l">{{ t('رقم الدفعة', 'Batch no.') }} <span v-if="l.product.tracksExpiry" class="text-bad">*</span></label>
            <div class="flex items-center gap-1">
              <input :value="draftOf(l).batchNo" :placeholder="t('الدفعة', 'Batch')" dir="ltr" class="inp sm num !h-9 min-w-0 flex-1 !text-[11px]" :class="{ '!border-[#F0DEB8]': needsBatch(l) }" @input="set(l, { batchNo: $event.target.value })">
              <ScanButton :title="{ ar: 'امسح رقم الدفعة', en: 'Scan the batch number' }" @detected="(code) => set(l, { batchNo: code })" />
            </div>
          </div>
          <div class="field">
            <label class="field-l">{{ t('تاريخ الإنتاج', 'Production date') }}</label>
            <input type="date" :value="draftOf(l).mfgDate" dir="ltr" class="inp sm num !h-9 w-full !text-[10.5px]" @input="set(l, { mfgDate: $event.target.value })">
          </div>
          <div class="field">
            <label class="field-l">{{ t('تاريخ الانتهاء', 'Expiry date') }} <span v-if="l.product.tracksExpiry" class="text-bad">*</span></label>
            <input type="date" :value="draftOf(l).expiryDate" dir="ltr" class="inp sm num !h-9 w-full !text-[10.5px]" :class="{ '!border-[#F0DEB8]': needsExpiry(l) }" @input="set(l, { expiryDate: $event.target.value })">
          </div>
          <div class="field" style="grid-column: span 2">
            <label class="field-l">{{ t('ملاحظة الفحص (QC)', 'QC note') }}</label>
            <input :value="draftOf(l).qcNote" :placeholder="t('اختياري — سبب التلف أو الرفض، رقم الصورة…', 'Optional — damage / rejection reason, photo ref…')" class="inp sm !h-9 w-full !text-[10.5px]" @input="set(l, { qcNote: $event.target.value })">
          </div>
        </div>

        <!-- more than one batch on this line -->
        <div v-if="extraOf(l).length" class="mt-2.5 rounded-lg border border-line-2 bg-soft px-2.5 py-2">
          <div class="row wrap !gap-x-3 text-[9.5px] font-extrabold text-muted">
            <span>{{ t('الدفعة الأولى', 'First batch') }} <span class="num text-violet">{{ draftOf(l).batchNo || '—' }}</span>:
              <span class="num" :class="firstBatchQty(draftOf(l)) > 0 ? 'text-ok' : 'text-bad'">{{ fmtNum(firstBatchQty(draftOf(l))) }}</span>
              {{ t('من المقبول', 'of the accepted') }} <span class="num">{{ fmtNum(Number(draftOf(l).acceptedQty) || 0) }}</span></span>
            <span class="font-normal text-faint">{{ t('التالف والمرفوض يُسجَّلان على الدفعة الأولى', 'Damaged and rejected are recorded on the first batch') }}</span>
          </div>
          <div v-for="(b, i) in extraOf(l)" :key="i" class="mt-2 grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] items-end gap-x-2.5 gap-y-2">
            <div class="field">
              <label class="field-l">{{ t(`كمية الدفعة ${i + 2}`, `Batch ${i + 2} qty`) }} <span class="text-bad">*</span></label>
              <input :value="b.qty ?? ''" placeholder="0" dir="ltr" inputmode="numeric" :class="[QTY, '!border-[#BFE8CC] focus:!border-[#1d7a3e]']" @input="onBatchQty($event, l, i)">
            </div>
            <div class="field">
              <label class="field-l">{{ t('رقم الدفعة', 'Batch no.') }} <span class="text-bad">*</span></label>
              <div class="flex items-center gap-1">
                <input :value="b.batchNo" :placeholder="t('الدفعة', 'Batch')" dir="ltr" class="inp sm num !h-9 min-w-0 flex-1 !text-[11px]" :class="{ '!border-[#F0DEB8]': !b.batchNo }" @input="setBatch(l, i, { batchNo: $event.target.value })">
                <ScanButton :title="{ ar: 'امسح رقم الدفعة', en: 'Scan the batch number' }" @detected="(code) => setBatch(l, i, { batchNo: code })" />
              </div>
            </div>
            <div class="field">
              <label class="field-l">{{ t('تاريخ الإنتاج', 'Production date') }}</label>
              <input type="date" :value="b.mfgDate" dir="ltr" class="inp sm num !h-9 w-full !text-[10.5px]" @input="setBatch(l, i, { mfgDate: $event.target.value })">
            </div>
            <div class="field">
              <label class="field-l">{{ t('تاريخ الانتهاء', 'Expiry date') }} <span v-if="l.product.tracksExpiry" class="text-bad">*</span></label>
              <input type="date" :value="b.expiryDate" dir="ltr" class="inp sm num !h-9 w-full !text-[10.5px]" :class="{ '!border-[#F0DEB8]': l.product.tracksExpiry && !b.expiryDate }" @input="setBatch(l, i, { expiryDate: $event.target.value })">
            </div>
            <button type="button" class="btn dangerOutline sm !h-9" @click="removeBatch(l, i)">{{ t('حذف الدفعة', 'Remove batch') }}</button>
          </div>
          <div v-if="splitErr(l)" class="field-err mt-1.5">{{ lang === 'ar' ? splitErr(l).ar : splitErr(l).en }}</div>
        </div>
        <div class="mt-2">
          <button type="button" class="btn soft sm" :disabled="!(Number(draftOf(l).acceptedQty) > 1)" :title="Number(draftOf(l).acceptedQty) > 1 ? null : t('أدخل الكمية المقبولة أولًا (أكثر من 1) لتقسيمها على دفعات', 'Enter the accepted quantity first (more than 1) to split it over batches')" @click="addBatch(l)">+ {{ t('دفعة أخرى', 'Another batch') }}</button>
          <span class="ms-2 text-[9px] text-faint">{{ t('عند وصول السطر بأكثر من دفعة: كل دفعة بكميتها ورقمها وتاريخها، ولكل دفعة مهمة تخزين مستقلة', 'When the line arrives in several batches: each with its quantity, number and dates — and its own putaway task') }}</span>
        </div>

        <div class="row wrap mt-2.5 !gap-x-4 !gap-y-1 text-[9.5px] font-bold text-muted">
          <span>{{ t('سيدخل المتاح', 'Into available stock') }}: <span class="num text-ok">{{ fmtNum(Number(draftOf(l).acceptedQty) || 0) }}</span></span>
          <span>{{ t('تالف (محجور)', 'Damaged (quarantined)') }}: <span class="num text-bad">{{ fmtNum(Number(draftOf(l).damagedQty) || 0) }}</span></span>
          <span>{{ rejectedStaysOpen ? t('مرفوض (يُعاد للمورد ويبقى مفتوحًا على أمر الشراء)', 'Rejected (back to the supplier, still owed on the PO)') : t('مرفوض (يُعاد للمورد)', 'Rejected (back to supplier)') }}: <span class="num text-warn">{{ fmtNum(Number(draftOf(l).rejectedQty) || 0) }}</span></span>
          <span class="grow" />
          <span>{{ t('يبقى على أمر الشراء بعد هذا الاستلام', 'Still open on the PO after this receipt') }}: <span class="num" :class="remaining(l) ? 'text-warn' : 'text-ok'">{{ fmtNum(remaining(l)) }}</span></span>
        </div>
      </div>

      <!-- read-only: nothing received yet → only what the ASN / PO already knows -->
      <div v-else-if="!(l.acceptedQty || l.damagedQty || l.rejectedQty)" class="row wrap px-3.5 py-2.5 !gap-x-5 !gap-y-1 text-[10px] text-muted">
        <span class="font-bold text-faint">{{ t('لم يُستلم شيء من هذا السطر بعد', 'Nothing received on this line yet') }}</span>
        <span v-if="l.batchNo">{{ t('الدفعة المعلنة', 'Declared batch') }}: <span class="num text-violet">{{ l.batchNo }}</span></span>
        <span v-if="l.expiryDate">{{ t('الانتهاء', 'Expiry') }}: <span class="num">{{ fmtDateOnly(l.expiryDate) }}</span></span>
      </div>
      <div v-else class="kv-grid px-3.5 py-2.5">
        <div class="kv"><div class="kv-k">{{ t('مستلم سليم', 'Accepted') }}</div><div class="kv-v num text-ok">{{ fmtNum(l.acceptedQty) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('تالف', 'Damaged') }}</div><div class="kv-v num" :class="l.damagedQty ? 'text-bad' : 'text-faint'">{{ fmtNum(l.damagedQty) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('مرفوض QC', 'Rejected') }}</div><div class="kv-v num" :class="l.rejectedQty ? 'text-warn' : 'text-faint'">{{ fmtNum(l.rejectedQty) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('الدفعة', 'Batch') }}</div><div class="kv-v num text-violet">{{ l.batchNo || '—' }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('الإنتاج / الانتهاء', 'Mfg / expiry') }}</div><div class="kv-v num ltr text-muted">{{ fmtDateOnly(l.mfgDate) }} / {{ fmtDateOnly(l.expiryDate) }}</div></div>
      </div>
    </div>
  </div>
</template>
