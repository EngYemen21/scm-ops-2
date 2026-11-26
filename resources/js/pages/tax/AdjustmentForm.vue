<script setup>
// A line of adjustment on a draft return — POST /tax/returns/{id}/adjustments { box, base, vat, reason, ledgerLineId }.
// Opened free (lines 1–5, 7–11, 14) or from an unexplained movement of a VAT account ("take it in"): then the movement
// is tied to the line (`ledgerLineId`) and its rest is proposed as the VAT.
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { SELECT, fmtDay, sar, textOrNull, useTaxLookups } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  returnId: { type: String, default: null },
  /** An item of `reconciliation.items` when the adjustment takes a ledger movement in. */
  item: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const f = reactive({ box: '', base: null, vat: null, reason: '', ledgerLineId: '' });
watch(() => props.open, (open) => {
  if (!open) return;
  const it = props.item;
  Object.assign(f, { box: it ? (it.side === 'output' ? '1' : '7') : '', base: null, vat: it ? Number(it.rest) : null, reason: it?.text || '', ledgerLineId: it?.journalLineId || '' });
  act.clearError();
}, { immediate: true });

const ok = computed(() => !!f.box && f.reason.trim().length >= 5 && (f.base != null || f.vat != null));

async function submit() {
  const body = { box: Number(f.box), base: f.base == null ? '0' : String(f.base), vat: f.vat == null ? '0' : String(f.vat), reason: f.reason.trim(), ledgerLineId: textOrNull(f.ledgerLineId) };
  const r = await act.run(() => api.postIdempotent(`/tax/returns/${encodeURIComponent(props.returnId)}/adjustments`, body), { success: t('أُضيف سطر التسوية', 'Adjustment added') });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <TaxFormDrawer :open="open" :title="item ? { ar: 'إدخال حركة بسطر تسوية', en: 'Take a movement in with an adjustment' } : { ar: 'سطر تسوية', en: 'Adjustment line' }"
                 :pending="act.pending.value" :error="act.error.value" :disabled="!ok"
                 :missing="{ ar: 'اختر البند، واكتب مبلغًا وسببًا (5 أحرف على الأقل)', en: 'Pick the line, type an amount and a reason (5 characters at least)' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="item" class="hint teal !mt-0 mb-3">
      <div class="font-extrabold"><span class="num">{{ fmtDay(item.date) }}</span> · <span class="num ltr">{{ item.journal }}</span> · <span class="num ltr">{{ item.account }}</span></div>
      <div dir="auto">{{ item.text || '—' }}</div>
      <div>{{ t('المبلغ', 'Amount') }} <b class="num">{{ sar(item.amount) }}</b> · {{ t('المتبقي', 'Rest') }} <b class="num">{{ sar(item.rest) }}</b></div>
    </div>
    <div class="form-grid">
      <SelectInput v-model="f.box" :label="{ ar: 'البند', en: 'Line' }" required :options="lk.adjustableBoxes.value" :placeholder="SELECT" full />
      <NumberInput v-model="f.base" :label="{ ar: 'المبلغ الخاضع (الأساس)', en: 'Base amount' }" :hint="{ ar: 'سالب للتخفيض', en: 'Negative to reduce' }" />
      <NumberInput v-model="f.vat" :label="{ ar: 'الضريبة', en: 'VAT' }" :hint="{ ar: 'سالب للتخفيض', en: 'Negative to reduce' }" />
      <TextInput v-if="!item" v-model="f.ledgerLineId" :label="{ ar: 'سطر القيد (اختياري)', en: 'Ledger line (optional)' }" dir="ltr" mono :hint="{ ar: 'معرّف سطر القيد الذي تأخذه هذه التسوية', en: 'The id of the journal line this adjustment takes' }" />
      <TextArea v-model="f.reason" :label="{ ar: 'السبب', en: 'Reason' }" required :rows="2" />
    </div>
  </TaxFormDrawer>
</template>
