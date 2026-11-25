<script setup>
// The lines of a request that asks for things: a product of the system (picked by name or code) or a free description,
// with a quantity and its unit. Field errors arrive keyed `items.<i>.<field>` (the form's own checks and the server's).
//   <ItemsEditor v-model:items="form.items" :errors="errors" />
// A line: { key, product: { id, sku, nameAr, nameEn } | null, description, quantity: number | null, uom }
import { Btn, NumberInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { nm, t } from '@/i18n';
import LookupPicker from './LookupPicker.vue';

const props = defineProps({
  items: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:items']);

let seq = 0;
const blank = () => ({ key: `n${Date.now()}-${seq++}`, product: null, description: '', quantity: 1, uom: '' });
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
const upd = (i, patch) => emit('update:items', props.items.map((l, j) => (j === i ? { ...l, ...patch } : l)));
const remove = (i) => emit('update:items', props.items.filter((_, j) => j !== i));
const add = () => emit('update:items', [...props.items, blank()]);
/** Picking a product names the line after it, unless the line already says something else. */
const onPick = (i, line, product) => upd(i, { product, description: product && !line.description.trim() ? nm(product) : line.description });
const COLS = 'minmax(150px,1.1fr) minmax(150px,1.3fr) 84px 84px 28px';
</script>

<template>
  <div class="col !gap-1.5">
    <div v-if="items.length" class="le-head grid gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint" :style="{ gridTemplateColumns: COLS }">
      <div>{{ t('صنف من النظام (اختياري)', 'Product of the system (optional)') }}</div><div>{{ t('الوصف', 'Description') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('الوحدة', 'Unit') }}</div><div />
    </div>
    <div v-for="(l, i) in items" :key="l.key" class="le-row grid items-start gap-2 px-2.5 py-1" :style="{ gridTemplateColumns: COLS }">
      <LookupPicker small :model-value="l.product" path="/approvals/products" :label="cap('صنف من النظام (اختياري)', 'Product (optional)')" :placeholder="{ ar: 'ابحث باسم الصنف أو رمزه…', en: 'Search by name or code…' }"
                    :error="errors[`items.${i}.product`] || null" @update:model-value="(p) => onPick(i, l, p)" />
      <TextInput small :label="cap('الوصف', 'Description')" :model-value="l.description" :error="errors[`items.${i}.description`] || null" :placeholder="{ ar: 'ما المطلوب؟', en: 'What is asked for?' }" @update:model-value="upd(i, { description: $event })" />
      <NumberInput small :label="cap('الكمية', 'Qty')" :model-value="l.quantity" :min="0" :error="errors[`items.${i}.quantity`] || null" @update:model-value="upd(i, { quantity: $event })" />
      <TextInput small :label="cap('الوحدة', 'Unit')" :model-value="l.uom" :placeholder="{ ar: 'حبة، كرتون…', en: 'pcs, carton…' }" @update:model-value="upd(i, { uom: $event })" />
      <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف البند', 'Remove line')" :title="t('حذف البند', 'Remove line')" @click="remove(i)">✕</button>
    </div>
    <div><Btn tone="softPurple" size="sm" :label="{ ar: '+ بند', en: '+ Line' }" @click="add" /></div>
  </div>
</template>
