<script setup>
// Large touch button of the worker receiving screen (48px, custom colours).
defineProps({
  bg: { type: String, required: true },
  fg: { type: String, required: true },
  label: { type: String, required: true },
  pending: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
</script>

<template>
  <button type="button" :disabled="disabled || pending" class="h-12 rounded-[13px] border-0 px-[22px] text-[13px] font-extrabold" :class="[disabled ? 'cursor-default' : 'cursor-pointer', { 'opacity-70': pending }]" :style="{ background: bg, color: fg }">
    <span v-if="pending" class="spinner" /><template v-else>{{ label }}</template>
  </button>
</template>
