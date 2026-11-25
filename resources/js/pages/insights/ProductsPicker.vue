<script setup>
// Several products, chosen by search (GET /products?q, SKU / name / barcode, up to 8 hits; a scanned code that matches
// exactly one product is added at once). v-model = the chosen products `[{ id, sku, nameAr, nameEn }]`.
//   <ProductsPicker v-model="products" :label="{ ar, en }" />
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { Field, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { nameOf } from './insights';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  label: { type: [String, Object], default: null },
  hint: { type: [String, Object], default: null },
});
const emit = defineEmits(['update:modelValue']);

const q = ref('');
const open = ref(false);
const scanned = ref(false);
const term = computed(() => q.value.trim());
const list = useList('/products', () => ({ q: term.value, pageSize: 8, active: 'true' }), { enabled: () => open.value && term.value.length >= 2 });
const chosenIds = computed(() => new Set(props.modelValue.map((p) => String(p.id))));
const hits = computed(() => (list.data.value?.items || []).filter((p) => !chosenIds.value.has(String(p.id))));

function onType(v) { q.value = v; open.value = true; scanned.value = false; }
function onEnter() { scanned.value = true; open.value = true; }
watch(() => list.data.value, (d) => { const items = d?.items || []; if (scanned.value && !list.isFetching.value && items.length === 1) { scanned.value = false; add(items[0]); } });
function add(p) {
  if (!chosenIds.value.has(String(p.id))) emit('update:modelValue', [...props.modelValue, { id: p.id, sku: p.sku, nameAr: p.nameAr, nameEn: p.nameEn }]);
  q.value = ''; open.value = false;
}
const remove = (id) => emit('update:modelValue', props.modelValue.filter((p) => String(p.id) !== String(id)));
</script>

<template>
  <Field full :label="label" :hint="hint">
    <div class="relative">
      <TextInput scan small mono :model-value="q" :placeholder="{ ar: 'أضف منتجًا: SKU / اسم / باركود', en: 'Add a product: SKU / name / barcode' }" @update:model-value="onType" @enter="onEnter" />
      <template v-if="open && term.length >= 2">
        <div class="fixed inset-0 z-[5]" @click="open = false" />
        <div class="popover !z-[6] start-0 top-full mt-1 max-h-60 w-[min(380px,90vw)] !overflow-y-auto !rounded-xl">
          <div v-if="list.isFetching.value && !hits.length" class="empty !p-3">{{ t('جارٍ البحث…', 'Searching…') }}</div>
          <div v-else-if="!hits.length" class="empty !p-3">{{ t('لا منتجات مطابقة', 'No matching products') }}</div>
          <div v-for="p in hits" :key="p.id" class="flex cursor-pointer items-center gap-2.5 border-t border-[#F7F6FA] px-3 py-2 hover:bg-soft" @click="add(p)">
            <span class="cell-id min-w-[90px]">{{ p.sku }}</span>
            <span class="flex-1 text-[11px] font-extrabold" dir="auto">{{ nameOf(p) }}</span>
            <span v-if="p.brand" class="text-[9px] text-violet" dir="auto">{{ p.brand }}</span>
          </div>
        </div>
      </template>
    </div>
    <div class="mt-1.5 max-h-[200px] overflow-auto rounded-[12px] border border-line-2">
      <div v-if="!modelValue.length" class="empty !p-3">{{ t('لا منتجات مسماة.', 'No named product.') }}</div>
      <div v-for="p in modelValue" :key="p.id" class="row !gap-2 border-b border-line-2 px-3 py-1.5 last:border-b-0">
        <span class="cell-id min-w-[90px]">{{ p.sku }}</span>
        <span class="min-w-0 flex-1 truncate text-[10.5px]" dir="auto">{{ nameOf(p) }}</span>
        <button type="button" class="text-[11px] font-extrabold text-bad" :aria-label="t('إزالة', 'Remove')" @click="remove(p.id)">✕</button>
      </div>
    </div>
    <div class="mt-1 text-[9px] text-faint">{{ t('عدد المنتجات المسماة', 'Named products') }}: <span class="num">{{ fmtNum(modelValue.length) }}</span></div>
  </Field>
</template>
