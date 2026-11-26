<script setup>
// Typing a statement's lines by hand (POST /banking/statements/{id}/lines { lines: [...] }): date, signed amount,
// reference, description, counterparty name and account, value date — one row per line, all sent together. The running
// total of the typed lines and what the statement still needs to balance are shown in hundredths (exact). Field errors
// of the server arrive keyed `lines.<i>.<field>`. On a phone the header row goes and each line is a card.
//   <LinesEditor :statement="detail" @done="…" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, ErrorBanner, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';
import { centsStr, isMoney, money, signClass, toCents } from './banking';

const props = defineProps({
  statement: { type: Object, required: true },
});
const emit = defineEmits(['done']);

const act = useAction({ invalidate: ['banking'] });
let seq = 0;
const newLine = (date = '') => ({ key: ++seq, date, amount: '', reference: '', description: '', partnerName: '', partnerAccount: '', valueDate: '' });
const lines = ref([newLine()]);
const errors = ref({});
watch(() => props.statement.id, () => { lines.value = [newLine()]; errors.value = {}; act.clearError(); });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const cols = 'minmax(120px,0.9fr) minmax(100px,0.8fr) minmax(100px,0.9fr) minmax(140px,1.5fr) minmax(110px,1fr) minmax(110px,1fr) minmax(120px,0.9fr) 28px';
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
const upd = (i, patch) => { lines.value = lines.value.map((l, j) => (j === i ? { ...l, ...patch } : l)); };
const remove = (i) => { lines.value = lines.value.length > 1 ? lines.value.filter((_, j) => j !== i) : [newLine()]; };
/** A new row starts on the date of the row above it: a statement is typed day by day. */
const add = () => { lines.value = [...lines.value, newLine(lines.value[lines.value.length - 1]?.date || '')]; };

const filled = computed(() => lines.value.filter((l) => l.date || String(l.amount).trim() || l.reference || l.description));
const typed = computed(() => filled.value.reduce((s, l) => s + (isMoney(l.amount) ? toCents(l.amount) : 0), 0));
/** What the statement still needs: closing − (opening + net of its lines). */
const missing = computed(() => {
  const st = props.statement;
  if (st.closingBalance == null || st.openingBalance == null) return null;
  return toCents(st.closingBalance) - toCents(st.openingBalance) - toCents(st.totals?.net || '0');
});
const after = computed(() => (missing.value == null ? null : missing.value - typed.value));

function validate() {
  const errs = {};
  const st = props.statement;
  filled.value.forEach((l) => {
    const i = lines.value.indexOf(l);
    if (!l.date) errs[`lines.${i}.date`] = t('التاريخ إلزامي', 'Date required');
    else if (l.date < st.dateFrom || l.date > st.dateTo) errs[`lines.${i}.date`] = t('خارج فترة الكشف', 'Outside the period');
    if (!isMoney(l.amount) || toCents(l.amount) === 0) errs[`lines.${i}.amount`] = t('مبلغ غير صفري بخانتين عشريتين', 'Non-zero, two decimals at most');
  });
  if (!filled.value.length) errs.form = t('أدخل سطرًا واحدًا على الأقل', 'Type at least one line');
  errors.value = errs;
  return !Object.keys(errs).length;
}
async function submit() {
  if (!validate()) return;
  // only the filled rows are sent; their indexes are renumbered so the server's `lines.<i>` points at the right row
  const rows = filled.value;
  const payload = rows.map((l) => ({
    date: l.date, amount: String(l.amount).trim(), ...Object.fromEntries(['reference', 'description', 'partnerName', 'partnerAccount', 'valueDate'].map((k) => [k, String(l[k] || '').trim() || null]).filter(([, x]) => x !== null)),
  }));
  lines.value = rows;
  const r = await act.run(() => api.postIdempotent(`/banking/statements/${props.statement.id}/lines`, { lines: payload }), {
    success: (x) => t(`أُضيف ${x.lines.length} سطر`, `${x.lines.length} line(s) added`),
  });
  if (r) { lines.value = [newLine(rows[rows.length - 1]?.date || '')]; errors.value = {}; emit('done', r); }
}
</script>

<template>
  <div class="col !gap-1.5">
    <div class="le-head grid gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint" :style="{ gridTemplateColumns: cols }">
      <div>{{ t('التاريخ', 'Date') }} *</div><div>{{ t('المبلغ ±', 'Amount ±') }} *</div><div>{{ t('المرجع', 'Reference') }}</div><div>{{ t('الوصف', 'Description') }}</div>
      <div>{{ t('اسم الطرف', 'Counterparty') }}</div><div>{{ t('حساب الطرف', 'Account') }}</div><div>{{ t('تاريخ القيمة', 'Value date') }}</div><div />
    </div>
    <div v-for="(l, i) in lines" :key="l.key" class="le-row grid items-start gap-2 px-2.5 py-1" :style="{ gridTemplateColumns: cols }">
      <DateInput small :label="cap('التاريخ', 'Date')" :model-value="l.date" :min="statement.dateFrom" :max="statement.dateTo" :error="errors[`lines.${i}.date`]" @update:model-value="upd(i, { date: $event })" />
      <TextInput small dir="ltr" inputmode="decimal" placeholder="-150.00" :label="cap('المبلغ (+ وارد / − صادر)', 'Amount (+ in / − out)')" :model-value="l.amount" :error="errors[`lines.${i}.amount`]" :class="signClass(l.amount)" @update:model-value="upd(i, { amount: $event })" />
      <TextInput small dir="ltr" :label="cap('المرجع', 'Reference')" :model-value="l.reference" :error="errors[`lines.${i}.reference`]" @update:model-value="upd(i, { reference: $event })" />
      <TextInput small :label="cap('الوصف', 'Description')" :model-value="l.description" :error="errors[`lines.${i}.description`]" @update:model-value="upd(i, { description: $event })" />
      <TextInput small :label="cap('اسم الطرف', 'Counterparty')" :model-value="l.partnerName" :error="errors[`lines.${i}.partnerName`]" @update:model-value="upd(i, { partnerName: $event })" />
      <TextInput small dir="ltr" :label="cap('حساب الطرف', 'Counterparty account')" :model-value="l.partnerAccount" :error="errors[`lines.${i}.partnerAccount`]" @update:model-value="upd(i, { partnerAccount: $event })" />
      <DateInput small :label="cap('تاريخ القيمة', 'Value date')" :model-value="l.valueDate" :error="errors[`lines.${i}.valueDate`]" @update:model-value="upd(i, { valueDate: $event })" />
      <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف السطر', 'Remove line')" @click="remove(i)">✕</button>
    </div>
    <div v-if="errors.form" class="field-err">{{ errors.form }}</div>

    <div class="row mt-1 flex-wrap justify-between !gap-2.5">
      <Btn tone="softPurple" size="sm" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add" />
      <div class="rounded-[12px] bg-soft px-3 py-2 text-[10.5px]">
        <div>{{ t('مجموع السطور المدخلة', 'Typed lines total') }}: <b class="num ltr" :class="signClass(centsStr(typed))">{{ money(centsStr(typed)) }}</b> <span class="text-faint">({{ filled.length }})</span></div>
        <div v-if="missing !== null">{{ t('ما يحتاجه الكشف ليتوازن', 'What the statement still needs') }}: <b class="num ltr">{{ money(centsStr(missing)) }}</b></div>
        <div v-if="after !== null" :class="after === 0 ? 'font-extrabold text-ok' : 'text-warn'">
          {{ after === 0 ? t('✓ يتوازن الكشف بعد الحفظ', '✓ The statement balances once saved') : t(`يبقى فرق ${money(centsStr(after))} بعد الحفظ`, `A difference of ${money(centsStr(after))} remains once saved`) }}
        </div>
      </div>
    </div>
    <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
    <div class="row"><span class="grow" /><Btn tone="dark" :loading="act.pending.value" :disabled="!filled.length" :label="{ ar: 'حفظ السطور', en: 'Save the lines' }" @click="submit" /></div>
  </div>
</template>
