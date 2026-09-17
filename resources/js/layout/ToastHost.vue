<script setup>
// Global bottom toast. Trigger from anywhere with `toast.say(...)` (stores/ui.js).
import { toastMessage } from '../stores/ui';
</script>

<template>
  <div v-if="toastMessage" class="toast" role="status">{{ toastMessage }}</div>
</template>
