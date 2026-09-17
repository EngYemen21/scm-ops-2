<script setup>
// "Supplier score below 65" override: checkbox + mandatory reason. Lives inside a `.form-grid` (spans the full row).
//   <ScoreOverrideField v-model:checked="f.overrideSupplierScore" v-model:reason="f.overrideReason" :label="t('…', '…')" />
import { TextInput } from '@/components';

defineProps({
  checked: { type: Boolean, default: false },
  reason: { type: String, default: '' },
  /** Checkbox text (already in the UI language). */
  label: { type: String, required: true },
  reasonLabel: { type: [String, Object], default: () => ({ ar: 'سبب الاستثناء', en: 'Reason' }) },
});
const emit = defineEmits(['update:checked', 'update:reason']);
</script>

<template>
  <div class="field full">
    <label class="row cursor-pointer !gap-2 text-[10.5px] font-extrabold">
      <input type="checkbox" :checked="checked" @change="emit('update:checked', $event.target.checked)">{{ label }}
    </label>
    <TextInput v-if="checked" :model-value="reason" :label="reasonLabel" required @update:model-value="emit('update:reason', $event)" />
  </div>
</template>
