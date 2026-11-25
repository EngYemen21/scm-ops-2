<script setup>
// An adjustment line on a draft statement (commission.manage): an amount for or against the earner, with the reason a
// person gives — POST /commissions/statements/{number}/adjustments { amount (signed), reason }.
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Drawer, ErrorBanner, NumberInput, PillChoice, TextArea } from '@/components';
import { t } from '@/i18n';
import { API, money, periodLabel, personName, sar } from './commissions';

const props = defineProps({
  open: { type: Boolean, default: false },
  statement: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction({ invalidate: ['commissions'] });
const direction = ref('add');
const amount = ref(null);
const reason = ref('');
watch(() => props.open, (open) => { if (open) { direction.value = 'add'; amount.value = null; reason.value = ''; act.clearError(); } });

const signed = computed(() => (Number(amount.value) > 0 ? (direction.value === 'cut' ? -1 : 1) * Number(amount.value) : 0));
const after = computed(() => Number(props.statement?.total || 0) + signed.value);
const ok = computed(() => Number(amount.value) > 0 && reason.value.trim().length >= 5);
const DIRECTIONS = [['add', { ar: 'إضافة للمستحق', en: 'Add to the earner' }], ['cut', { ar: 'خصم منه', en: 'Deduct from him' }]];

async function submit() {
  const r = await act.run(() => api.postIdempotent(`${API}/statements/${encodeURIComponent(props.statement.number)}/adjustments`, { amount: signed.value, reason: reason.value.trim() }),
    { success: t('أُضيفت التسوية', 'Adjustment added') });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="480" :z-index="80" :title="{ ar: 'تسوية على الكشف', en: 'Adjustment on the statement' }"
          :sub="statement ? `${statement.number} · ${personName(statement.beneficiary)} · ${periodLabel(statement.period)}` : null" @close="emit('close')">
    <div class="form-grid">
      <PillChoice v-model="direction" full :options="DIRECTIONS" :label="{ ar: 'نوع التسوية', en: 'Kind of adjustment' }" />
      <NumberInput v-model="amount" required :min="0" :label="{ ar: 'المبلغ', en: 'Amount' }" :hint="{ ar: 'بالريال، موجب دائمًا — الاتجاه من الاختيار أعلاه', en: 'In SAR, always positive — the direction comes from the choice above' }" />
      <TextArea v-model="reason" required :rows="3" :label="{ ar: 'السبب', en: 'Reason' }" :hint="{ ar: '5 أحرف على الأقل — يظهر للمستحق في كشفه ويُسجَّل في سجل التدقيق', en: 'At least 5 characters — shown to the earner on his statement and recorded in the audit trail' }" />
    </div>
    <div v-if="statement && Number(amount) > 0" class="hint teal">
      {{ t('إجمالي الكشف بعد التسوية', 'The statement total after the adjustment') }}: <b class="num ltr">{{ money(after) }}</b> {{ sar() }}
      <span class="text-muted">({{ t('الآن', 'now') }} <span class="num ltr">{{ money(statement.total) }}</span>)</span>
    </div>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!ok" :label="{ ar: 'إضافة التسوية', en: 'Add the adjustment' }" @click="submit" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="!ok && !act.pending.value" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">{{ t('اكتب المبلغ وسبب التسوية (5 أحرف على الأقل) لتفعيل الزر', 'Type the amount and the reason (at least 5 characters) to enable the button') }}</div>
    </template>
  </Drawer>
</template>
