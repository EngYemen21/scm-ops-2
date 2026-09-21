<script setup>
import ConfirmHost from './layout/ConfirmHost.vue';
import LabelHost from './layout/LabelHost.vue';
import ToastHost from './layout/ToastHost.vue';
</script>

<template>
  <RouterView />
  <LabelHost />
  <ConfirmHost />
  <ToastHost />
</template>
