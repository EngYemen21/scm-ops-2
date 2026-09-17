<script setup>
// Dashed cyan hint box; `tone` = teal / amber / purple / green variants.   <Hint tone="teal">…</Hint>
defineProps({ tone: { type: String, default: null } });
</script>

<template>
  <div class="hint" :class="tone"><slot /></div>
</template>
