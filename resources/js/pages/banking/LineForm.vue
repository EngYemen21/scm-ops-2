<script setup>
// Edit one unmatched line of an open statement (PATCH /banking/lines/{id}) — only what changed is sent. The amount is
// typed as the bank shows it: positive for money in, negative for money out, never zero.
//   <LineForm :line="lineOrNull" :statement="detail" @close="…" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import { fmtDay, isMoney, toCents } from './banking';

const props = defineProps({
  /** The line to edit (a row of GET /banking/lines); null closes the form. */
  line: { type: Object, default: null },
  statement: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['banking'] });
const KEYS = ['date', 'amount', 'reference', 'description', 'partnerName', 'partnerAccount', 'valueDate'];
const v = reactive(Object.fromEntries(KEYS.map((k) => [k, ''])));
let initial = {};
const errors = ref({});
watch(() => props.line, (l) => {
  if (!l) return;
  initial = Object.fromEntries(KEYS.map((k) => [k, l[k] ?? '']));
  Object.assign(v, initial);
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const sent = (k, x) => (k === 'amount' ? String(x).trim() : String(x ?? '').trim() || null);
async function submit() {
  const errs = {};
  if (!v.date) errs.date = t('هذا الحقل إلزامي', 'Required');
  else if (v.date < props.statement.dateFrom || v.date > props.statement.dateTo) errs.date = t('خارج فترة الكشف', 'Outside the statement period');
  if (!isMoney(v.amount) || toCents(v.amount) === 0) errs.amount = t('مبلغ غير صفري بخانتين عشريتين على الأكثر', 'A non-zero amount with at most two decimals');
  if (v.partnerAccount !== initial.partnerAccount && String(v.partnerAccount).includes('•')) errs.partnerAccount = t('الرقم مخفي — اكتبه كاملًا أو اتركه كما هو', 'The number is masked — type it in full or leave it');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = {};
  for (const k of KEYS) {
    const same = k === 'amount' ? toCents(v[k]) === toCents(initial[k]) : sent(k, v[k]) === sent(k, initial[k]);
    if (!same) body[k] = sent(k, v[k]);
  }
  if (!Object.keys(body).length) { emit('close'); return; }
  const r = await act.run(() => api.patch(`/banking/lines/${props.line.id}`, body), { success: t('حُفظ السطر', 'Line saved') });
  if (r) emit('close');
}
const title = computed(() => (props.line ? { ar: `تعديل السطر ${props.line.lineNo}`, en: `Edit line ${props.line.lineNo}` } : null));
</script>

<template>
  <Drawer :open="!!line" :width="520" :z-index="74" :title="title" :sub="{ ar: `داخل فترة الكشف ${fmtDay(statement.dateFrom)} — ${fmtDay(statement.dateTo)}`, en: `Inside the statement period ${fmtDay(statement.dateFrom)} — ${fmtDay(statement.dateTo)}` }" @close="emit('close')">
    <div class="form-grid">
      <DateInput v-model="v.date" required :min="statement.dateFrom" :max="statement.dateTo" :label="{ ar: 'تاريخ العملية', en: 'Transaction date' }" :error="errors.date" />
      <TextInput v-model="v.amount" required dir="ltr" inputmode="decimal" :label="{ ar: 'المبلغ (+ وارد / − صادر)', en: 'Amount (+ in / − out)' }" :error="errors.amount" />
      <TextInput v-model="v.reference" dir="ltr" :label="{ ar: 'المرجع', en: 'Reference' }" :error="errors.reference" />
      <DateInput v-model="v.valueDate" :label="{ ar: 'تاريخ القيمة', en: 'Value date' }" :error="errors.valueDate" />
      <TextInput v-model="v.description" full :label="{ ar: 'الوصف', en: 'Description' }" :error="errors.description" />
      <TextInput v-model="v.partnerName" :label="{ ar: 'اسم الطرف', en: 'Counterparty name' }" :error="errors.partnerName" />
      <TextInput v-model="v.partnerAccount" dir="ltr" :label="{ ar: 'حساب الطرف', en: 'Counterparty account' }" :error="errors.partnerAccount" />
    </div>
    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'حفظ السطر', en: 'Save line' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
