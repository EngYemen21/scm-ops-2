<script setup>
// Reason-only drawer used by activate / deactivate.
//   <ReasonForm :open="!!mode" :title="{ ar, en }" :submit="(reason) => api.postIdempotent(`/products/${id}/deactivate`, { reason })" @close="mode = null" />
import { FormDrawer } from '@/components';
import { serverMsg } from './_shared';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: [String, Object], required: true },
  sub: { type: [String, Object], default: null },
  /** `(reason: string) => Promise` — runs the mutation. */
  submit: { type: Function, required: true },
  /** Success toast when the server sends no message. */
  success: { type: [String, Object], default: null },
});
const emit = defineEmits(['close', 'done']);

const fields = [{ k: 'reason', label: { ar: 'السبب', en: 'Reason' }, type: 'area', required: true }];
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :width="420" :fields="fields" :submit="(v) => submit(String(v.reason))"
              :action="{ success: (d) => serverMsg(d, success || { ar: 'تم ✓', en: 'Done ✓' }), invalidate: ['products', 'inventory'] }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
