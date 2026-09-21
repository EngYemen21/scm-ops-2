<script setup>
// Product search box with a dropdown of hits (name / SKU / barcode). Enter picks the first hit, a click picks that row.
//   <ProductPicker @pick="(product) => …" />
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { TextInput } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { pname } from './shared';

defineProps({
  label: { type: [String, Object], default: () => ({ ar: 'إضافة منتج — ابحث بالاسم / SKU / الباركود', en: 'Add product — search name / SKU / barcode' }) },
});
const emit = defineEmits(['pick']);

const q = ref('');
const open = ref(false);
const searching = computed(() => q.value.trim().length >= 1);
const list = useList('/products', () => ({ q: q.value, pageSize: 12, active: 'true' }), { enabled: searching });
const hits = computed(() => (searching.value ? list.data.value?.items || [] : []));

function onType(v) { q.value = v; open.value = true; }
function pick(p) { emit('pick', p); q.value = ''; open.value = false; }
/** Enter — typed or fired by a scan — picks the first hit; when the hits are still loading, the pick waits for them. */
const pending = ref(false);
function pickFirst() { if (hits.value[0] && !list.isFetching.value) pick(hits.value[0]); else pending.value = true; }
watch(hits, (h) => { if (pending.value && !list.isFetching.value && h.length) { pending.value = false; if (h.length === 1) pick(h[0]); } });
</script>

<template>
  <div class="relative">
    <TextInput scan :model-value="q" :label="label" :placeholder="{ ar: 'اكتب للبحث…', en: 'Type to search…' }" @update:model-value="onType" @enter="pickFirst" />
    <div v-if="open && q.trim()" class="absolute inset-x-0 top-full z-[5] max-h-[240px] overflow-y-auto rounded-xl border border-line bg-white shadow-[0_12px_30px_rgba(30,33,48,.12)]">
      <div v-if="list.isFetching.value && hits.length === 0" class="empty !p-3">{{ t('جارٍ البحث…', 'Searching…') }}</div>
      <div v-else-if="hits.length === 0" class="empty !p-3">{{ t('لا نتائج مطابقة.', 'No matching results.') }}</div>
      <template v-else>
        <div v-for="p in hits" :key="p.id" class="gs-row" @mousedown.prevent="pick(p)">
          <span class="num min-w-[90px] text-[10px] text-violet">{{ p.sku }}</span>
          <span class="ellipsis flex-1 text-[11px] font-extrabold">{{ pname(p) }}</span>
          <span v-if="p.purchasePrice != null" class="num muted text-[10px]">{{ fmtMoney(p.purchasePrice) }}</span>
        </div>
      </template>
    </div>
  </div>
</template>
