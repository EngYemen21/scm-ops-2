<script setup>
// Receiving lines grid (desktop): per-line accepted / damaged / rejected quantities, batch, mfg / expiry dates, QC note.
// Read-only once the shipment is no longer being inspected.
//   <ReceiveLinesGrid v-model:drafts="drafts" :lines="shipment.lines" :editable="editable" />
import { watch } from 'vue';
import { ScanButton } from '@/components';
import { fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { draftSum, emptyDraft, parseQty, pn } from './shared';

const props = defineProps({
  lines: { type: Array, required: true },
  editable: { type: Boolean, default: false },
  /** `{ [lineNo]: ReceiveDraft }` */
  drafts: { type: Object, required: true },
});
const emit = defineEmits(['update:drafts']);

const COLS = 'minmax(170px,1.5fr) 80px 90px 90px 80px 80px 110px 110px 120px minmax(120px,1fr)';
const draftOf = (l) => props.drafts[l.lineNo] || emptyDraft(l);
const over = (l) => draftSum(draftOf(l)) > l.openQty;
// Edits are merged into the LATEST drafts, not into the prop: two changes in the same tick (a scanner that fills batch
// and quantity at once, browser autofill) would otherwise each start from the old prop and the last one would win.
let latest = props.drafts;
watch(() => props.drafts, (v) => { latest = v; });
const set = (l, patch) => { latest = { ...latest, [l.lineNo]: { ...(latest[l.lineNo] || emptyDraft(l)), ...patch } }; emit('update:drafts', latest); };
// Digits only; the box is re-synced so letters never show.
function onQty(e, l, key) { const n = parseQty(e.target.value); e.target.value = n ?? ''; set(l, { [key]: n }); }
const suggestion = (l) => (lang.value === 'ar' ? l.suggestionAr : l.suggestionEn);
</script>

<template>
  <div class="gt-wrap">
    <div class="gt" :style="{ '--gt-cols': COLS, '--gt-min': '1080px' }">
      <div class="gt-head !static">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('مطلوب', 'Ordered') }}</div><div>{{ t('مستلم سابقًا', 'Prev. received') }}</div><div>{{ t('المتبقي', 'Open qty') }}</div>
        <div>{{ t('مستلم سليم', 'Accepted') }}</div><div>{{ t('تالف', 'Damaged') }}</div><div>{{ t('مرفوض QC', 'Rejected') }}</div><div>{{ t('الدفعة', 'Batch') }}</div>
        <div>{{ t('الإنتاج / الانتهاء', 'Mfg / expiry') }}</div><div>{{ t('موقع مقترح · ملاحظة QC', 'Suggested bin · QC note') }}</div>
      </div>

      <div v-for="l in lines" :key="l.id" class="gt-row recv-row !items-start" :class="{ 'bg-[#FFF8F8]': over(l) }">
        <div class="gt-title">
          <div class="text-[11px] font-extrabold text-ink">{{ pn(l.product) }}</div>
          <div class="num mt-px text-[8px] text-faint">{{ l.product.sku }}{{ l.product.tracksExpiry ? ` · ${t('دفعة + صلاحية إلزامية', 'batch + expiry required')}` : '' }}</div>
        </div>
        <div :data-label="t('مطلوب', 'Ordered')" class="num text-[11.5px] text-muted">{{ fmtNum(l.orderedQty) }}</div>
        <div :data-label="t('مستلم سابقًا', 'Prev. received')" class="num text-[11px]">
          {{ fmtNum(l.previouslyReceived) }}
          <div v-if="l.damagedQty || l.rejectedQty" class="text-[8px] text-faint">{{ l.damagedQty ? `${l.damagedQty} ${t('تالف', 'dmg')}` : '' }} {{ l.rejectedQty ? `${l.rejectedQty} ${t('مرفوض', 'rej')}` : '' }}</div>
        </div>
        <div :data-label="t('المتبقي', 'Open')" class="num text-[11.5px] !font-extrabold" :class="l.openQty ? 'text-warn' : 'text-ok'">{{ fmtNum(l.openQty) }}</div>

        <template v-if="editable">
          <div :data-label="t('مستلم سليم', 'Accepted')">
            <input :value="draftOf(l).acceptedQty ?? ''" :placeholder="t('مستلم', 'accepted')" dir="ltr" inputmode="numeric" class="inp sm num !h-[30px] !w-[72px] !border-[#BFE8CC] !px-2 !text-[10.5px]" @input="onQty($event, l, 'acceptedQty')">
            <div v-if="over(l)" class="mt-0.5 text-[8px] text-bad">{{ t(`يتجاوز المتبقي (${l.openQty})`, `exceeds open (${l.openQty})`) }}</div>
          </div>
          <div :data-label="t('تالف', 'Damaged')"><input :value="draftOf(l).damagedQty ?? ''" :placeholder="t('تالف', 'damaged')" dir="ltr" inputmode="numeric" class="inp sm num !h-[30px] !w-[64px] !border-[#F3C4C4] !px-2 !text-[10.5px]" @input="onQty($event, l, 'damagedQty')"></div>
          <div :data-label="t('مرفوض QC', 'Rejected')"><input :value="draftOf(l).rejectedQty ?? ''" :placeholder="t('مرفوض', 'rejected')" dir="ltr" inputmode="numeric" class="inp sm num !h-[30px] !w-[64px] !border-[#F0DEB8] !px-2 !text-[10.5px]" @input="onQty($event, l, 'rejectedQty')"></div>
          <div class="row !gap-1" :data-label="t('الدفعة', 'Batch')"><ScanButton :title="{ ar: 'امسح رقم الدفعة', en: 'Scan the batch number' }" @detected="(code) => set(l, { batchNo: code })" /><input :value="draftOf(l).batchNo" :placeholder="t('الدفعة', 'Batch')" dir="ltr" class="inp sm num !h-[30px] !w-[100px]" :class="{ '!border-[#F0DEB8]': l.product.tracksExpiry && !draftOf(l).batchNo }" @input="set(l, { batchNo: $event.target.value })"></div>
          <div class="col !gap-1" :data-label="t('الإنتاج / الانتهاء', 'Mfg / expiry')">
            <input type="date" :value="draftOf(l).mfgDate" dir="ltr" class="inp sm num !h-7 !w-[112px] !text-[9.5px]" :title="t('تاريخ الإنتاج', 'Production date')" @input="set(l, { mfgDate: $event.target.value })">
            <input type="date" :value="draftOf(l).expiryDate" dir="ltr" class="inp sm num !h-7 !w-[112px] !text-[9.5px]" :class="{ '!border-[#F0DEB8]': l.product.tracksExpiry && !draftOf(l).expiryDate }" :title="t('تاريخ الانتهاء', 'Expiry date')" @input="set(l, { expiryDate: $event.target.value })">
          </div>
          <div class="col !gap-1">
            <div class="num text-[9.5px] text-[#0d7f93]"><template v-if="l.suggestedBin?.code">{{ l.suggestedBin.code }}</template><span v-else class="text-faint">{{ suggestion(l) }}</span></div>
            <input :value="draftOf(l).qcNote" :placeholder="t('ملاحظة QC', 'QC note')" class="inp sm !h-7 !text-[9.5px]" @input="set(l, { qcNote: $event.target.value })">
          </div>
        </template>

        <template v-else>
          <div :data-label="t('مستلم سليم', 'Accepted')" class="num text-[11.5px] text-ok">{{ fmtNum(l.acceptedQty) }}</div>
          <div :data-label="t('تالف', 'Damaged')" class="num text-[11.5px]" :class="l.damagedQty ? 'text-bad' : 'text-faint'">{{ fmtNum(l.damagedQty) }}</div>
          <div :data-label="t('مرفوض', 'Rejected')" class="num text-[11.5px]" :class="l.rejectedQty ? 'text-warn' : 'text-faint'">{{ fmtNum(l.rejectedQty) }}</div>
          <div :data-label="t('الدفعة', 'Batch')" class="num text-[9.5px] text-violet">{{ l.batchNo || '—' }}</div>
          <div :data-label="t('الإنتاج / الانتهاء', 'Mfg / expiry')" class="num ltr text-[9.5px] text-muted">{{ fmtDateOnly(l.mfgDate) }} / {{ fmtDateOnly(l.expiryDate) }}</div>
          <div class="num text-[9.5px] text-[#0d7f93]"><template v-if="l.suggestedBin?.code">{{ l.suggestedBin.code }}</template><span v-else class="!font-normal text-faint">{{ suggestion(l) }}</span></div>
        </template>
      </div>
    </div>
  </div>
</template>
