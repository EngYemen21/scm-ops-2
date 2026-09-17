<script setup>
// Structured editor of `procurement.approvalTiers`: one row per tier — PO total limit (blank = no limit) and the
// approval steps (roles, in the order they were picked). v-model is the tier array the API stores:
//   [{ max: 5000, roles: ['proc'] }, { max: null, roles: ['proc', 'finance', 'gm'] }]
import { computed } from 'vue';
import { Btn, NumberInput } from '@/components';
import { t } from '@/i18n';
import PillMulti from './PillMulti.vue';
import { ROLE_OPTIONS, roleLabel } from './shared';

const props = defineProps({ modelValue: { type: Array, required: true } });
const emit = defineEmits(['update:modelValue']);

/** Known roles + any role key already stored in a tier (never silently dropped). */
const roleOpts = computed(() => {
  const known = new Set(ROLE_OPTIONS.map((o) => o.v));
  const extra = [...new Set(props.modelValue.flatMap((x) => (Array.isArray(x?.roles) ? x.roles : [])))].filter((r) => !known.has(r));
  return [...ROLE_OPTIONS, ...extra.map((r) => ({ v: r, l: r }))];
});

const patch = (i, change) => emit('update:modelValue', props.modelValue.map((x, j) => (j === i ? { ...x, ...change } : x)));
const remove = (i) => emit('update:modelValue', props.modelValue.filter((_, j) => j !== i));
const add = () => emit('update:modelValue', [...props.modelValue, { max: null, roles: [] }]);
</script>

<template>
  <div class="col min-w-0 flex-1">
    <div v-for="(tier, i) in modelValue" :key="i" class="tile">
      <div class="row wrap">
        <span class="text-[10px] font-extrabold text-violet">{{ t('شريحة', 'Tier') }} <span class="num">{{ i + 1 }}</span></span>
        <span class="text-[10px] text-muted">{{ t('إجمالي أمر الشراء حتى', 'PO total up to') }}</span>
        <NumberInput small class="w-[120px]" :model-value="tier.max" :min="0" :placeholder="{ ar: 'بلا حد', en: 'No limit' }" @update:model-value="(v) => patch(i, { max: v })" />
        <span class="text-[9px] text-faint">{{ t('ر.س', 'SAR') }}</span>
        <div class="grow" />
        <Btn v-if="modelValue.length > 1" tone="softRed" size="sm" :label="{ ar: 'حذف', en: 'Remove' }" @click="remove(i)" />
      </div>
      <div class="mt-2"><PillMulti small purple :model-value="tier.roles" :options="roleOpts" @update:model-value="(v) => patch(i, { roles: v })" /></div>
      <div class="mt-1.5 text-[9.5px] text-muted">
        {{ t('تسلسل الاعتماد', 'Approval chain') }}:
        <b v-if="tier.roles?.length" class="text-sec">{{ tier.roles.map((r) => roleLabel(r)).join(t(' ← ', ' → ')) }}</b>
        <span v-else class="text-bad">{{ t('اختر دورًا واحدًا على الأقل', 'pick at least one role') }}</span>
      </div>
    </div>
    <div><Btn tone="soft" size="sm" :label="{ ar: '+ شريحة', en: '+ Tier' }" @click="add" /></div>
  </div>
</template>
