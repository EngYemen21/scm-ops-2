<script setup>
// Product picker: search-as-you-type against /products (SKU / name / barcode), a popover with up to 8 hits; a scanned
// barcode that matches exactly one product is picked without another tap.
//   <ProductPick small :value="line.sku" @pick="(product, sku) => …" />
// `pick` fires with `(null, text)` while typing and with `(product, sku)` when a hit is chosen.
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { TextInput } from '@/components';
import { t } from '@/i18n';
import { nameOf } from './portal';

const props = defineProps({
  value: { type: String, default: '' },
  label: { type: [String, Object], default: null },
  small: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  error: { type: String, default: null },
});
const emit = defineEmits(['pick']);

const q = ref(props.value);
const open = ref(false);
const scanned = ref(false);
watch(() => props.value, (v) => { if (v !== q.value) q.value = v; });

const term = computed(() => String(q.value || '').trim());
const list = useList('/products', () => ({ q: term.value, pageSize: 8, active: 'true' }), { enabled: () => open.value && term.value.length >= 2 });
const hits = computed(() => list.data.value?.items || []);

function onType(v) { q.value = v; open.value = true; scanned.value = false; emit('pick', null, v); }
function onEnter() { scanned.value = true; open.value = true; }
function choose(p) { q.value = p.sku; open.value = false; scanned.value = false; emit('pick', p, p.sku); }
watch(hits, (h) => { if (scanned.value && !list.isFetching.value && h.length === 1) choose(h[0]); });
</script>

<template>
  <div class="relative">
    <TextInput scan mono :model-value="q" :label="label" :small="small" :disabled="disabled" :error="error" :placeholder="{ ar: 'SKU / اسم المنتج / باركود', en: 'SKU / name / barcode' }" autocomplete="off"
               @update:model-value="onType" @enter="onEnter" />
    <template v-if="open && term.length >= 2">
      <div class="fixed inset-0 z-[5]" @click="open = false" />
      <div class="popover !z-[6] start-0 top-full mt-1 max-h-60 w-[min(360px,90vw)] !overflow-y-auto !rounded-xl !shadow-[0_12px_30px_rgba(30,33,48,.15)]">
        <div v-if="list.isFetching.value && hits.length === 0" class="empty !p-3">{{ t('جارٍ البحث…', 'Searching…') }}</div>
        <div v-else-if="hits.length === 0" class="empty !p-3">{{ t('لا منتجات مطابقة', 'No matching products') }}</div>
        <template v-else>
          <div v-for="p in hits" :key="p.sku" class="flex cursor-pointer items-center gap-2.5 border-t border-[#F7F6FA] px-3 py-2 hover:bg-soft" @click="choose(p)">
            <span class="cell-id min-w-[90px]">{{ p.sku }}</span>
            <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold" dir="auto">{{ nameOf(p) }}</span>
            <span v-if="p.baseUom?.code" class="text-[9px] text-faint">{{ p.baseUom.code }}</span>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
