<script setup>
// Status chip. Resolves label + colours from a label map:  <Chip :map="SO_LABELS" :k="so.status" />
// or explicit:  <Chip :label="{ ar, en }" fg="#1d7a3e" bg="#e6f9ec" small dot />
import { computed } from 'vue';
import { bi, isBi, lang } from '../i18n';
import { chipStyle } from './chip';

const props = defineProps({
  map: { type: Object, default: null },
  k: { type: String, default: null },
  label: { type: [String, Object], default: null },
  fg: { type: String, default: null },
  bg: { type: String, default: null },
  small: { type: Boolean, default: false },
  dot: { type: Boolean, default: false },
});

const resolved = computed(() => chipStyle(props.map, props.k, lang.value));
const color = computed(() => props.fg || resolved.value.fg);
</script>

<template>
  <span class="chip" :class="{ sm: small }" :style="{ color, background: bg || resolved.bg }">
    <span v-if="dot" class="me-[5px] h-1.5 w-1.5 flex-none rounded-full" :style="{ background: color }" />
    <slot>{{ label == null ? resolved.label : isBi(label) ? bi(label) : label }}</slot>
  </span>
</template>
