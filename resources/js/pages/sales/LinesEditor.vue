<script setup>
// Multi-line editor of the quotation / sales-order forms: product picker, qty, unit price, optional discount %, live net
// per line and totals incl. VAT. Field errors arrive keyed as `lines.<i>.<field>` (client validation + server details).
//   <LinesEditor v-model:lines="lines" discount :errors="errors" />
import { computed } from 'vue';
import { Btn, NumberInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtMoney, num, t } from '@/i18n';
import ProductPicker from './ProductPicker.vue';
import TotalsBox from './TotalsBox.vue';
import { lineNet, linesTotals, newLine } from './shared';

const props = defineProps({
  /** LineDraft[] — see shared.js */
  lines: { type: Array, required: true },
  discount: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:lines']);

const cols = computed(() => (props.discount ? 'minmax(170px,1.5fr) 80px 95px 70px 95px 28px' : 'minmax(170px,1.5fr) 80px 95px 95px 28px'));
const totals = computed(() => linesTotals(props.lines));
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);


const upd = (i, patch) => emit('update:lines', props.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
const remove = (i) => emit('update:lines', props.lines.length > 1 ? props.lines.filter((_, j) => j !== i) : [newLine()]);
const add = () => emit('update:lines', [...props.lines, newLine()]);
/** Picking a product fills name / UoM and, when the line has no price yet, the product's selling price. */
function onPick(i, l, p, sku) {
  const listPrice = p ? p.sellPrice ?? p.price : null;
  upd(i, { sku, name: p?.nameAr, uom: p?.baseUom?.code, price: p && l.price == null && listPrice != null ? num(listPrice) : l.price });
}
</script>

<template>
  <div class="col !gap-1.5">
    <div class="le-head grid gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint" :style="{ gridTemplateColumns: cols }">
      <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('سعر الوحدة', 'Unit price') }}</div><div v-if="discount">{{ t('خصم %', 'Disc %') }}</div><div>{{ t('الصافي', 'Net') }}</div><div />
    </div>
    <div v-for="(l, i) in lines" :key="l.key" class="le-row grid items-start gap-2 px-2.5 py-1" :style="{ gridTemplateColumns: cols }">
      <div>
        <ProductPicker small :value="l.sku" @pick="(p, sku) => onPick(i, l, p, sku)" />
        <div v-if="l.name" class="faint mt-0.5 text-[8.5px]">{{ l.name }}{{ l.uom ? ` · ${l.uom}` : '' }}</div>
        <div v-if="errors[`lines.${i}.sku`]" class="field-err">{{ errors[`lines.${i}.sku`] }}</div>
      </div>
      <NumberInput small :label="cap('الكمية', 'Qty')" :model-value="l.qty" :min="1" :error="errors[`lines.${i}.qty`]" @update:model-value="upd(i, { qty: $event })" />
      <NumberInput small :label="cap('سعر الوحدة', 'Unit price')" :model-value="l.price" :min="0" :error="errors[`lines.${i}.price`]" @update:model-value="upd(i, { price: $event })" />
      <NumberInput v-if="discount" small :label="cap('خصم %', 'Disc %')" :model-value="l.discPct" :min="0" :max="100" :error="errors[`lines.${i}.discPct`]" @update:model-value="upd(i, { discPct: $event })" />
      <div class="num pt-2 text-[11.5px]" :data-label="t('الصافي', 'Net')">{{ fmtMoney(lineNet(l)) }}</div>
      <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" aria-label="remove" @click="remove(i)">✕</button>
    </div>
    <div class="row mt-1 flex-wrap justify-between !gap-2.5">
      <Btn tone="softPurple" size="sm" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add" />
      <TotalsBox :totals="totals" />
    </div>
  </div>
</template>
