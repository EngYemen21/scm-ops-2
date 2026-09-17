<script setup>
// Card with an optional head.  <SectionCard :title="{ ar, en }" :count="12" :padded="false"> … <template #actions>…</template></SectionCard>
// Tables usually pass :padded="false".
import { bi, isBi } from '../i18n';

defineProps({
  title: { type: [String, Object], default: null },
  sub: { type: [String, Object], default: null },
  count: { type: [Number, String], default: null },
  small: { type: Boolean, default: false },
  selected: { type: Boolean, default: false },
  padded: { type: Boolean, default: true },
});
</script>

<template>
  <div class="card" :class="{ sm: small, selected }">
    <div v-if="title || sub || $slots.title || $slots.actions" class="card-head">
      <div class="card-title">
        <slot name="title">{{ isBi(title) ? bi(title) : title }}</slot>
        <div v-if="sub" class="card-sub">{{ isBi(sub) ? bi(sub) : sub }}</div>
      </div>
      <span v-if="count != null" class="card-count num">{{ count }}</span>
      <slot name="actions" />
    </div>
    <div :class="{ 'card-body': padded }"><slot /></div>
  </div>
</template>
