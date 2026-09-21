<script setup>
// Form control for a map location ({ lat, lng } | null): shows the coordinates and opens the map picker.
//   <LocationField v-model="loc" :label="{ ar: 'الموقع على الخريطة', en: 'Map location' }" :seed="`${city} ${zone}`" />
import { ref } from 'vue';
import { hasPoint } from '@/composables/mapbox';
import { bi, t } from '@/i18n';
import Btn from './Btn.vue';
import LocationPicker from './LocationPicker.vue';

defineProps({
  modelValue: { type: Object, default: null },
  label: { type: [String, Object], default: () => ({ ar: 'الموقع على الخريطة', en: 'Map location' }) },
  hint: { type: [String, Object], default: null },
  seed: { type: String, default: '' },
  title: { type: [String, Object], default: undefined },
});
const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const pick = (p) => { emit('update:modelValue', p); open.value = false; };
</script>

<template>
  <label class="field-l">{{ bi(label) }}</label>
  <div class="row wrap !gap-2">
    <div class="inp flex min-w-[150px] flex-1 items-center">
      <bdi v-if="hasPoint(modelValue)" dir="ltr" class="num text-[11px] font-bold">{{ modelValue.lat.toFixed(5) }}, {{ modelValue.lng.toFixed(5) }}</bdi>
      <span v-else class="text-[11px] text-faint">{{ t('غير محدَّد', 'Not set') }}</span>
    </div>
    <Btn tone="softPurple" :label="hasPoint(modelValue) ? { ar: 'تعديل على الخريطة', en: 'Edit on map' } : { ar: 'تحديد على الخريطة', en: 'Pick on map' }" @click="open = true" />
  </div>
  <div v-if="hint" class="field-hint">{{ bi(hint) }}</div>
  <LocationPicker :open="open" :value="modelValue" :seed="seed" :title="title" @close="open = false" @pick="pick" />
</template>
