<script setup>
// Lines picker of the new-transfer form: available balances of the source warehouse (GET /api/inventory/balances) →
// checked rows become transfer lines { key, sku, name, fromBin, batchNo, available, qty, toBin }.
import { computed, ref } from 'vue';
import { useList } from '@/api/client';
import { ErrorBanner, NumberInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtNum, t } from '@/i18n';
import { pname } from './shared';

const props = defineProps({
  /** Source warehouse code (the picker is disabled until one is chosen). */
  warehouse: { type: String, default: null },
  modelValue: { type: Array, default: () => [] },
  error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const q = ref('');
const bal = useList('/inventory/balances', () => ({ warehouse: props.warehouse || undefined, status: 'available', q: q.value.trim() || undefined, pageSize: 40 }), { enabled: () => !!props.warehouse });
const items = computed(() => bal.data.value?.items || []);

const keyOf = (b) => `${b.sku}|${b.bin}|${b.batch || ''}`;
const isSel = (b) => props.modelValue.some((l) => l.key === keyOf(b));
function toggle(b) {
  const k = keyOf(b);
  if (isSel(b)) emit('update:modelValue', props.modelValue.filter((l) => l.key !== k));
  else emit('update:modelValue', [...props.modelValue, { key: k, sku: b.sku, name: pname(b), fromBin: b.bin, batchNo: b.batch || undefined, available: b.available, qty: b.available, toBin: '' }]);
}
const set = (k, patch) => emit('update:modelValue', props.modelValue.map((l) => (l.key === k ? { ...l, ...patch } : l)));
const remove = (k) => emit('update:modelValue', props.modelValue.filter((x) => x.key !== k));
const GRID = 'grid grid-cols-[minmax(120px,1.4fr)_70px_70px_90px_32px] gap-1.5';
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
</script>

<template>
  <div>
    <div class="field-l">{{ t('الأصناف من رصيد المصدر', 'Lines from source stock') }} <span class="text-bad">*</span> <span class="num text-violet">{{ modelValue.length }}</span></div>
    <div v-if="!warehouse" class="hint amber !mt-0">{{ t('اختر مستودع المصدر أولًا', 'Pick the source warehouse first') }}</div>
    <template v-else>
      <TextInput scan v-model="q" small :placeholder="{ ar: 'بحث SKU / اسم / موقع / دفعة', en: 'Search SKU / name / bin / batch' }" />
      <div class="mt-1.5 max-h-[200px] overflow-y-auto rounded-[10px] border border-line">
        <div v-if="bal.isLoading.value" class="skel m-2 h-10" />
        <ErrorBanner :error="bal.error.value" :closable="false" class="m-2" />
        <div v-if="!bal.isLoading.value && items.length === 0" class="empty !p-3.5">{{ t('لا رصيد متاح في هذا المستودع', 'No available stock in this warehouse') }}</div>
        <label v-for="b in items" :key="keyOf(b)" class="row cursor-pointer border-b border-[#F7F6FA] px-2.5 py-1.5 text-[10.5px]">
          <input type="checkbox" :checked="isSel(b)" @change="toggle(b)">
          <span class="num min-w-20 text-violet">{{ b.sku }}</span>
          <span class="ellipsis flex-1 font-bold">{{ pname(b) }}</span>
          <span class="num text-[9px] text-muted">{{ b.bin }}{{ b.batch ? ` · ${b.batch}` : '' }}</span>
          <span class="num text-[10px] text-ok">{{ fmtNum(b.available) }}</span>
        </label>
      </div>
    </template>

    <div v-if="modelValue.length > 0" class="mt-2 overflow-hidden rounded-[10px] border border-line-2">
      <div :class="GRID" class="le-head bg-soft px-2.5 py-1.5 text-[9px] font-extrabold text-faint"><div>{{ t('الصنف · من موقع', 'Item · from bin') }}</div><div>{{ t('المتاح', 'Avail.') }}</div><div>{{ t('الكمية', 'Qty') }}</div><div>{{ t('إلى موقع', 'To bin') }}</div><div /></div>
      <div v-for="l in modelValue" :key="l.key" :class="GRID" class="le-row items-center border-t border-[#F7F6FA] px-2.5 py-[5px]">
        <div class="min-w-0"><div class="ellipsis text-[10.5px] font-extrabold">{{ l.name }}</div><div class="cell-sub num">{{ l.sku }} · {{ l.fromBin }}{{ l.batchNo ? ` · ${l.batchNo}` : '' }}</div></div>
        <div class="num text-[10px] text-ok" :data-label="t('المتاح', 'Available')">{{ fmtNum(l.available) }}</div>
        <NumberInput :label="cap('الكمية', 'Qty')" :model-value="l.qty" small :min="1" :max="l.available" :error="Number(l.qty) > l.available ? t('يتجاوز المتاح', 'Exceeds available') : null" @update:model-value="set(l.key, { qty: $event })" />
        <TextInput scan :label="cap('إلى موقع', 'To bin')" :model-value="l.toBin" small dir="ltr" mono :placeholder="{ ar: 'Bin *', en: 'Bin *' }" @update:model-value="set(l.key, { toBin: $event })" />
        <button type="button" class="x-btn" aria-label="remove" @click="remove(l.key)">✕</button>
      </div>
    </div>
    <div v-if="error" class="field-err mt-1">{{ error }}</div>
  </div>
</template>
