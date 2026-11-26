<script setup>
// The two things a person types on a draft return: the credit brought forward (line 15) and the notes —
// PATCH /tax/returns/{id} { creditCarried, notes } (only what changed).
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, TextArea } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { changedOnly, textOrNull } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  ret: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['tax'] });
const f = reactive({ creditCarried: 0, notes: '' });
let before = {};
watch(() => props.open, (open) => {
  if (!open || !props.ret) return;
  before = { creditCarried: String(Number(props.ret.creditCarried || 0).toFixed(2)), notes: props.ret.notes || null };
  Object.assign(f, { creditCarried: Number(props.ret.creditCarried || 0), notes: props.ret.notes || '' });
  act.clearError();
}, { immediate: true });
const ok = computed(() => f.creditCarried != null && f.creditCarried >= 0);

async function submit() {
  const body = changedOnly(before, { creditCarried: Number(f.creditCarried).toFixed(2), notes: textOrNull(f.notes) });
  if (!Object.keys(body).length) { emit('close'); return; }
  const r = await act.run(() => api.patch(`/tax/returns/${encodeURIComponent(props.ret.id)}`, body), { success: t('حُفظ الإقرار', 'Return saved') });
  if (r) emit('close');
}
</script>

<template>
  <TaxFormDrawer :open="open && !!ret" :title="{ ar: 'الرصيد المرحَّل والملاحظات', en: 'Credit carried and notes' }" :sub="ret?.periodCode" :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                 :missing="{ ar: 'الرصيد المرحَّل لا يكون سالبًا', en: 'The credit carried cannot be negative' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <NumberInput v-model="f.creditCarried" :label="{ ar: 'ضريبة مرحَّلة من فترات سابقة (بند 15)', en: 'VAT credit carried forward (line 15)' }" :min="0" full />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="3" />
    </div>
    <div class="hint !mt-3">{{ t('يُعاد احتساب صافي المستحق (بند 16) فور الحفظ.', 'The net due (line 16) is recomputed on save.') }}</div>
  </TaxFormDrawer>
</template>
