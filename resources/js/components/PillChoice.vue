<script setup>
// Pill-group choice inside forms (storage class, category …). Options as in SelectInput.
import Field from './Field.vue';
import { optL, optV } from './options';

defineProps({
  modelValue: { type: String, default: null },
  options: { type: Array, required: true },
  label: { type: [String, Object], default: null },
  required: Boolean, error: { type: String, default: null }, hint: { type: [String, Object], default: null }, full: Boolean,
  purple: Boolean,
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
  <Field :label="label" :required="required" :error="error" :hint="hint" :full="full">
    <div class="row wrap !gap-1.5">
      <button v-for="o in options" :key="optV(o)" type="button" class="pill" :class="{ purple, active: optV(o) === modelValue }" @click="emit('update:modelValue', optV(o))">{{ optL(o) }}</button>
    </div>
  </Field>
</template>
