<script setup>
// Line-item editor: product picker + qty (+ unit price and running total when `with-price`).
//   <LinesEditor v-model="lines" with-price />          lines: LineDraft[] = [{ sku, name, qty, price, tracksExpiry }]
import { computed } from 'vue';
import { NumberInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtMoney, t } from '@/i18n';
import ProductPicker from './ProductPicker.vue';
import { pname } from './shared';

const props = defineProps({
  modelValue: { type: Array, required: true },
  withPrice: { type: Boolean, default: false },
  currencyLabel: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);

const lineTotal = (l) => (Number(l.qty) || 0) * (Number(l.price) || 0);
const total = computed(() => props.modelValue.reduce((a, l) => a + lineTotal(l), 0));
const gridCols = computed(() => (props.withPrice ? 'grid-cols-[minmax(120px,1.5fr)_80px_90px_80px_26px]' : 'grid-cols-[minmax(120px,1.5fr)_90px_26px]'));

const patch = (i, p) => emit('update:modelValue', props.modelValue.map((l, j) => (j === i ? { ...l, ...p } : l)));
const remove = (i) => emit('update:modelValue', props.modelValue.filter((_, j) => j !== i));
function add(p) {
  if (props.modelValue.some((l) => l.sku === p.sku)) return;
  emit('update:modelValue', [...props.modelValue, { sku: p.sku, name: pname(p), qty: null, price: p.purchasePrice != null ? Number(p.purchasePrice) : null, tracksExpiry: p.tracksExpiry }]);
}
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
</script>

<template>
  <div class="col">
    <ProductPicker @pick="add" />
    <div v-if="modelValue.length === 0" class="empty dashed !rounded-xl !p-4">{{ t('لا بنود بعد — أضف منتجًا واحدًا على الأقل', 'No lines yet — add at least one product') }}</div>
    <div v-else class="overflow-hidden rounded-xl border border-line-2">
      <div class="le-head grid gap-1.5 bg-soft px-2.5 py-[7px] text-[9.5px] font-extrabold text-muted" :class="gridCols">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('الكمية', 'Qty') }}</div>
        <template v-if="withPrice"><div>{{ t('سعر الوحدة', 'Unit price') }}</div><div>{{ t('الإجمالي', 'Total') }}</div></template>
        <div />
      </div>
      <div v-for="(l, i) in modelValue" :key="l.sku" class="le-row grid items-center gap-1.5 border-t border-line-2 px-2.5 py-[7px]" :class="gridCols">
        <div class="min-w-0"><div class="ellipsis text-[10.5px] font-extrabold">{{ l.name || l.sku }}</div><div class="num ltr text-start text-[8.5px] text-faint">{{ l.sku }}</div></div>
        <NumberInput small :label="cap('الكمية', 'Qty')" :model-value="l.qty" :min="1" :placeholder="{ ar: 'كمية', en: 'qty' }" @update:model-value="(v) => patch(i, { qty: v })" />
        <template v-if="withPrice">
          <NumberInput small :label="cap('سعر الوحدة', 'Unit price')" :model-value="l.price ?? null" :min="0" :placeholder="{ ar: 'سعر', en: 'price' }" @update:model-value="(v) => patch(i, { price: v })" />
          <div class="num ltr text-start text-[11px]" :data-label="t('الإجمالي', 'Total')">{{ fmtMoney(lineTotal(l)) }}</div>
        </template>
        <button type="button" class="x-btn !h-6 !w-6" aria-label="remove" @click="remove(i)">✕</button>
      </div>
      <div v-if="withPrice" class="flex justify-end gap-2 border-t border-line-2 px-2.5 py-2 text-[11px] font-extrabold">
        {{ t('الإجمالي', 'Total') }}: <span class="num ltr">{{ fmtMoney(total) }}</span><span v-if="currencyLabel" class="muted font-normal">{{ t('ر.س', 'SAR') }}</span>
      </div>
    </div>
  </div>
</template>
