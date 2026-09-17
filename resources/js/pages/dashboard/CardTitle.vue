<script setup>
// Card section title (13/800, padding 13px 18px 9px) with optional controls on the end side.
//   <CardTitle>العنوان <template #right><Btn … /></template></CardTitle>
</script>

<template>
  <div class="flex items-center gap-2.5 px-[18px] pt-[13px] pb-[9px] text-[13px] font-extrabold">
    <span class="flex-1"><slot /></span>
    <slot name="right" />
  </div>
</template>
