<script setup>
// Purple SKU pill (LTR, Quicksand). `clickable` only changes the cursor — listen with @click on the component.
defineProps({
  sku: { type: String, default: '' },
  clickable: { type: Boolean, default: false },
});
</script>

<template>
  <span class="whitespace-nowrap rounded-[7px] bg-violet-soft px-2 py-[3px] font-num text-[9px] font-bold text-violet" :class="{ 'cursor-pointer': clickable }"><bdi dir="ltr">{{ sku }}</bdi></span>
</template>
