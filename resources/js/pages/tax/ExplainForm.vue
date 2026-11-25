<script setup>
// The explanation of a VAT-account movement no document stands behind (a payment to the authority, an opening
// balance, …) — POST /tax/returns/{id}/explanations { journalLineId, treatment, reason }. Withdrawn, never removed.
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { PillChoice, TextArea } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { SIDE, fmtDay, labelOf, sar, useTaxLookups } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  returnId: { type: String, default: null },
  item: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const f = reactive({ treatment: '', reason: '' });
watch(() => props.open, (open) => { if (open) { Object.assign(f, { treatment: '', reason: '' }); act.clearError(); } }, { immediate: true });
const ok = computed(() => !!f.treatment && f.reason.trim().length >= 5);

async function submit() {
  const r = await act.run(() => api.postIdempotent(`/tax/returns/${encodeURIComponent(props.returnId)}/explanations`, { journalLineId: props.item.journalLineId, treatment: f.treatment, reason: f.reason.trim() }),
    { success: t('سُجّل التفسير', 'Explanation recorded') });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <TaxFormDrawer :open="open && !!item" :title="{ ar: 'تفسير حركة على حساب الضريبة', en: 'Explain a VAT-account movement' }" :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                 :missing="{ ar: 'اختر نوع التفسير واكتبه (5 أحرف على الأقل)', en: 'Pick what it is and explain it (5 characters at least)' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="item" class="hint teal !mt-0 mb-3">
      <div class="font-extrabold"><span class="num">{{ fmtDay(item.date) }}</span> · <span class="num ltr">{{ item.journal }}</span> · {{ labelOf(SIDE, item.side) }}</div>
      <div><span class="num ltr">{{ item.account }}</span> · <span dir="auto">{{ t(item.accountNameAr, item.accountNameEn || item.accountNameAr) }}</span></div>
      <div dir="auto">{{ item.text || '—' }}</div>
      <div>{{ t('المبلغ', 'Amount') }} <b class="num">{{ sar(item.amount) }}</b> · {{ t('المتبقي', 'Rest') }} <b class="num">{{ sar(item.rest) }}</b></div>
    </div>
    <div class="form-grid">
      <PillChoice v-model="f.treatment" :label="{ ar: 'ما هذه الحركة؟', en: 'What is this movement?' }" required :options="lk.treatments.value" full />
      <TextArea v-model="f.reason" :label="{ ar: 'التفسير', en: 'Explanation' }" required :rows="3" />
    </div>
  </TaxFormDrawer>
</template>
