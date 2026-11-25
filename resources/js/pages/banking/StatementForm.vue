<script setup>
// The form of a statement — add (POST /banking/statements) or edit an OPEN one (PATCH /banking/statements/{id}): the
// bank account (fixed once made), a title, the period and the bank's two balances. An edit sends only what changed.
// The first statement of a bank account keeps its opening balance as a line of its own, to be matched like any other.
//   <StatementForm :open="open" :statement="statementOrNull" :bank="defaultBankCode" @close="…" @done="(s) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { centsStr, isMoney, toCents, useBankingLookups } from './banking';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The statement to edit (GET /banking/statements/{id}); null = a new one. */
  statement: { type: Object, default: null },
  /** Code of the bank a new statement starts on (the page's selected bank). */
  bank: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['banking'] });
const lk = useBankingLookups();
const edit = computed(() => !!props.statement);
const activeBanks = computed(() => lk.bankOptions.value.filter((o) => lk.findBank(o.v)?.active || o.v === v.bankAccount));

const blank = () => ({ bankAccount: '', name: '', dateFrom: '', dateTo: '', openingBalance: '', closingBalance: '', notes: '' });
const fromStatement = (s) => ({ bankAccount: s.bank?.code || '', name: s.name || '', dateFrom: s.dateFrom || '', dateTo: s.dateTo || '', openingBalance: s.openingBalance ?? '', closingBalance: s.closingBalance ?? '', notes: s.notes || '' });
const v = reactive(blank());
let initial = blank();
const errors = ref({});

watch(() => [props.open, props.statement], () => {
  if (!props.open) return;
  initial = props.statement ? fromStatement(props.statement) : { ...blank(), bankAccount: lk.findBank(props.bank)?.code || '' };
  Object.assign(v, { ...initial });
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

/** The movements the two balances call for: closing − opening, shown while typing (hundredths, exact). */
const expectedNet = computed(() => (isMoney(v.openingBalance) && isMoney(v.closingBalance) ? centsStr(toCents(v.closingBalance) - toCents(v.openingBalance)) : null));

function validate() {
  const errs = {};
  const need = t('هذا الحقل إلزامي', 'Required');
  if (!edit.value && !v.bankAccount) errs.bankAccount = need;
  if (!v.dateFrom) errs.dateFrom = need;
  if (!v.dateTo) errs.dateTo = need;
  if (v.dateFrom && v.dateTo && v.dateFrom > v.dateTo) errs.dateTo = t('نهاية الفترة قبل بدايتها', 'The period ends before it starts');
  for (const k of ['openingBalance', 'closingBalance']) {
    if (String(v[k]).trim() === '') errs[k] = need;
    else if (!isMoney(v[k])) errs[k] = t('مبلغ بخانتين عشريتين على الأكثر، والسالب بإشارة −', 'An amount with at most two decimals, negative with −');
  }
  errors.value = errs;
  return !Object.keys(errs).length;
}
function body() {
  const now = { name: v.name.trim() || null, dateFrom: v.dateFrom, dateTo: v.dateTo, openingBalance: String(v.openingBalance).trim(), closingBalance: String(v.closingBalance).trim(), notes: v.notes.trim() || null };
  if (!edit.value) return { bankAccount: v.bankAccount, ...Object.fromEntries(Object.entries(now).filter(([, x]) => x !== null)) };
  const was = { name: initial.name.trim() || null, dateFrom: initial.dateFrom, dateTo: initial.dateTo, openingBalance: String(initial.openingBalance), closingBalance: String(initial.closingBalance), notes: initial.notes.trim() || null };
  const out = {};
  for (const k of Object.keys(now)) {
    const same = k.endsWith('Balance') ? toCents(now[k]) === toCents(was[k]) : now[k] === was[k];
    if (!same) out[k] = now[k];
  }
  return out;
}
async function submit() {
  if (!validate()) return;
  const payload = body();
  if (edit.value && !Object.keys(payload).length) { emit('close'); return; }
  const r = await act.run(() => (edit.value ? api.patch(`/banking/statements/${props.statement.id}`, payload) : api.postIdempotent('/banking/statements', payload)), {
    success: (s) => (edit.value ? t(`حُفظ الكشف ${s.number}`, `Statement ${s.number} saved`) : t(`أُضيف الكشف ${s.number}`, `Statement ${s.number} added`)),
  });
  if (r) emit('done', r);
}
const title = computed(() => (edit.value ? { ar: `تعديل الكشف ${props.statement.number}`, en: `Edit statement ${props.statement.number}` } : { ar: 'كشف بنكي جديد', en: 'New bank statement' }));
</script>

<template>
  <Drawer :open="open" :width="560" :z-index="72" :title="title" :sub="{ ar: 'الأرصدة كما في كشف البنك نفسه — الافتتاحي + الحركات = الختامي', en: 'The balances as the bank’s own statement shows them — opening + lines = closing' }" @close="emit('close')">
    <div class="form-grid">
      <SelectInput v-if="!edit" v-model="v.bankAccount" full required :label="{ ar: 'الحساب البنكي / الصندوق', en: 'Bank account / cash box' }" :options="activeBanks" :placeholder="{ ar: '— اختر —', en: '— choose —' }" :error="errors.bankAccount" />
      <TextInput v-model="v.name" full :label="{ ar: 'عنوان الكشف (اختياري)', en: 'Title (optional)' }" :placeholder="{ ar: 'مثال: كشف أكتوبر 2026', en: 'e.g. October 2026 statement' }" :error="errors.name" />
      <DateInput v-model="v.dateFrom" required :label="{ ar: 'من تاريخ', en: 'From' }" :max="v.dateTo || null" :error="errors.dateFrom" />
      <DateInput v-model="v.dateTo" required :label="{ ar: 'إلى تاريخ', en: 'To' }" :min="v.dateFrom || null" :error="errors.dateTo" />
      <TextInput v-model="v.openingBalance" required dir="ltr" inputmode="decimal" :label="{ ar: 'الرصيد الافتتاحي', en: 'Opening balance' }" placeholder="0.00" :error="errors.openingBalance" />
      <TextInput v-model="v.closingBalance" required dir="ltr" inputmode="decimal" :label="{ ar: 'الرصيد الختامي', en: 'Closing balance' }" placeholder="0.00" :error="errors.closingBalance" />
    </div>
    <div v-if="expectedNet !== null" class="hint !mt-2">{{ t('صافي الحركات الذي يتطلبه الرصيدان', 'Net movements the two balances call for') }}: <b class="num ltr">{{ expectedNet }}</b></div>
    <TextArea v-model="v.notes" field-class="mt-3.5" :rows="3" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.notes" />

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="edit ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إضافة الكشف', en: 'Add statement' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t(`* حقل إلزامي · يُسجل الإجراء في سجل التدقيق باسم ${auth.user?.nameAr || ''}`, `* required · recorded in the audit trail as ${auth.user?.nameEn || ''}`) }}</div>
    </template>
  </Drawer>
</template>
