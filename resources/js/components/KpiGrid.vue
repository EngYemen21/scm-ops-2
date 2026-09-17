<script setup>
defineProps({ compact: { type: Boolean, default: false } });
</script>

<template>
  <div class="kpi-grid" :class="{ compact }"><slot /></div>
</template>
