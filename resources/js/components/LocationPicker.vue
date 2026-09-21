<script setup>
// Pick a point on the map: search a place (Saudi Arabia), tap the map or drag the pin, then confirm.
//   <LocationPicker :open="open" :value="{ lat, lng } | null" :title="{ ar, en }" @close="open = false" @pick="(p) => save(p)" />   (p = { lat, lng } | null = cleared)
import { ref, watch } from 'vue';
import { hasPoint, searchPlaces } from '@/composables/mapbox';
import { lang, t } from '@/i18n';
import Modal from '../layout/Modal.vue';
import Btn from './Btn.vue';
import MapView from './MapView.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  value: { type: Object, default: null },
  title: { type: [String, Object], default: () => ({ ar: 'تحديد الموقع على الخريطة', en: 'Pick the location on the map' }) },
  /** Text to pre-fill the search with (e.g. the customer's city / district). */
  seed: { type: String, default: '' },
});
const emit = defineEmits(['close', 'pick']);

const pin = ref(null);
const view = ref(null);
const q = ref('');
const results = ref([]);
const searching = ref(false);
const failed = ref(false);
let timer = null;
let aborter = null;

watch(() => props.open, (open) => {
  if (!open) return;
  pin.value = hasPoint(props.value) ? { lat: props.value.lat, lng: props.value.lng } : null;
  q.value = ''; results.value = []; failed.value = false;
});

function search() {
  clearTimeout(timer);
  aborter?.abort();
  const text = q.value.trim();
  if (text.length < 2) { results.value = []; searching.value = false; return; }
  searching.value = true;
  timer = setTimeout(async () => {
    aborter = new AbortController();
    try {
      results.value = await searchPlaces(text, { language: lang.value === 'en' ? 'en' : 'ar', proximity: pin.value, signal: aborter.signal });
      failed.value = false;
    } catch (e) {
      if (e?.name === 'AbortError') return;
      results.value = []; failed.value = true;
    }
    searching.value = false;
  }, 350);
}

function choose(r) {
  pin.value = { lat: +r.lat.toFixed(6), lng: +r.lng.toFixed(6) };
  results.value = []; q.value = r.name;
  view.value?.flyTo(pin.value);
}
function useSeed() { q.value = props.seed; search(); }
</script>

<template>
  <Modal :open="open" :title="title" :sub="{ ar: 'ابحث عن المكان أو اضغط على الخريطة — يمكنك سحب الدبوس لضبطه بدقة', en: 'Search a place or tap the map — drag the pin to fine-tune' }" :width="720" :z-index="90" @close="emit('close')">
    <div class="relative">
      <input v-model="q" type="search" class="inp w-full" :placeholder="t('ابحث: حي، شارع، معلم… (داخل السعودية)', 'Search: district, street, landmark… (Saudi Arabia)')" @input="search" @keydown.enter.prevent="results[0] && choose(results[0])">
      <button v-if="seed && !q" type="button" class="mt-1 cursor-pointer text-[10px] font-bold text-violet" @click="useSeed">{{ t('ابحث عن', 'Search for') }} «{{ seed }}»</button>
      <div v-if="results.length || searching || failed" class="map-results">
        <div v-if="searching && !results.length" class="px-3 py-2 text-[10.5px] text-faint">{{ t('جارٍ البحث…', 'Searching…') }}</div>
        <div v-else-if="failed" class="px-3 py-2 text-[10.5px] text-bad">{{ t('تعذّر البحث — اضغط على الخريطة مباشرة', 'Search failed — tap the map instead') }}</div>
        <button v-for="r in results" :key="r.id" type="button" class="map-result" @click="choose(r)">
          <span class="font-bold">{{ r.name }}</span><span class="text-faint"> · {{ r.place }}</span>
        </button>
      </div>
    </div>

    <div class="mt-2.5"><MapView v-if="open" ref="view" v-model:pin="pin" pickable height="min(52vh, 420px)" :fit-key="open ? 'pick' : ''" /></div>

    <div class="mt-2 text-[10.5px]">
      <template v-if="pin"><span class="text-faint">{{ t('الإحداثيات', 'Coordinates') }}:</span> <bdi dir="ltr" class="num font-bold">{{ pin.lat.toFixed(5) }}, {{ pin.lng.toFixed(5) }}</bdi></template>
      <span v-else class="text-faint">{{ t('لم يُحدَّد موقع بعد', 'No location picked yet') }}</span>
    </div>

    <template #footer>
      <Btn v-if="hasPoint(value)" tone="soft" :label="{ ar: 'إزالة الموقع', en: 'Clear location' }" @click="emit('pick', null)" />
      <span class="flex-1" />
      <Btn tone="soft" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      <Btn tone="primary" :disabled="!pin" :label="{ ar: 'اعتماد الموقع', en: 'Use this location' }" @click="emit('pick', pin)" />
    </template>
  </Modal>
</template>
