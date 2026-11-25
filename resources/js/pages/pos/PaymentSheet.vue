<script setup>
// The payment panel of a sale (a centred dialog; a bottom sheet on a phone): one or more payment rows of the register's
// methods, a reference where the method requires one, "exact" buttons, the live remaining amount and the change.
// The rules are the server's (SaleService::settle), shown here before the cashier presses Pay:
//   cash may exceed what is due — the change comes out of the cash; card, transfer and account may not exceed it;
//   "on account" needs a named customer; a method with `requiresReference` needs its reference.
//   <PaymentSheet :open :total="quote.total" :methods :named-customer :pending :error @pay="(payments) => …" @close />
import { computed, ref, watch } from 'vue';
import { Btn, Chip, ErrorBanner, Modal, NumberInput, SelectInput, TextInput } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { METHOD_KIND, cents, fmtCents, fromCents, nameOf } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** What is due (the quote's total, a decimal string). */
  total: { type: [String, Number], default: 0 },
  /** The register's methods: { id, code, nameAr, nameEn, kind, requiresReference }. */
  methods: { type: Array, default: () => [] },
  namedCustomer: { type: Boolean, default: false },
  customerLabel: { type: String, default: '' },
  pending: { type: Boolean, default: false },
  error: { type: null, default: null },
  /** A line shown above the buttons (e.g. a new operation key was made). */
  note: { type: String, default: null },
});
const emit = defineEmits(['close', 'pay', 'clear-error']);

const rows = ref([]);
const tried = ref(false);
const due = computed(() => cents(props.total));
const methodOf = (id) => props.methods.find((m) => m.id === id) || null;
const firstOf = (kind) => props.methods.find((m) => m.kind === kind) || null;
const newRow = (m, amount) => ({ key: Math.random().toString(36).slice(2), method: m ? m.id : '', amount, reference: '' });
function reset() {
  const m = firstOf('cash') || props.methods[0] || null;
  rows.value = [newRow(m, fromCents(due.value))];
  tried.value = false;
}
watch(() => props.open, (o) => { if (o) reset(); }, { immediate: true });
// the total changed under the open sheet (POS_TOTAL_CHANGED → re-quoted): a single row that paid the old total exactly follows it
watch(() => props.total, (now, before) => {
  if (props.open && rows.value.length === 1 && cents(rows.value[0].amount ?? 0) === cents(before)) rows.value[0].amount = fromCents(cents(now));
});

const options = computed(() => props.methods.map((m) => ({ v: m.id, l: { ar: `${m.nameAr} · ${METHOD_KIND[m.kind]?.ar || m.kind}`, en: `${m.nameEn || m.nameAr} · ${METHOD_KIND[m.kind]?.en || m.kind}` } })));
const amountC = (r) => cents(r.amount ?? 0);
const kindOf = (r) => methodOf(r.method)?.kind || null;
const paid = computed(() => rows.value.reduce((s, r) => s + amountC(r), 0));
const cash = computed(() => rows.value.filter((r) => kindOf(r) === 'cash').reduce((s, r) => s + amountC(r), 0));
const nonCash = computed(() => paid.value - cash.value);
const remaining = computed(() => Math.max(0, due.value - paid.value));
const change = computed(() => (nonCash.value <= due.value && paid.value > due.value ? paid.value - due.value : 0));
const hasAccount = computed(() => rows.value.some((r) => kindOf(r) === 'account' && amountC(r) > 0));

/** Per-row problems (shown once Pay was pressed, or at once for the hard rules). */
const rowError = (r) => {
  const m = methodOf(r.method);
  if (!m) return t('اختر طريقة الدفع', 'Choose the method');
  if (!(amountC(r) > 0)) return t('المبلغ أكبر من صفر', 'Amount above zero');
  if (m.requiresReference && !String(r.reference || '').trim()) return t('المرجع مطلوب (رقم العملية)', 'Reference required (transaction no.)');
  return null;
};
const problems = computed(() => {
  const out = [];
  if (nonCash.value > due.value) out.push(t('المدفوع بالشبكة / التحويل / الحساب أكبر من المستحق — الباقي يُعطى من النقد فقط', 'Card, transfer and account may not exceed what is due — change is given from cash only'));
  if (hasAccount.value && !props.namedCustomer) out.push(t('البيع على الحساب يحتاج عميلًا مسمّى — اختر العميل أولًا', 'A sale on account needs a named customer — choose the customer first'));
  if (paid.value < due.value) out.push(t(`المتبقي ${fmtMoney(fromCents(remaining.value))} — أكمل الدفع`, `${fmtMoney(fromCents(remaining.value))} still to pay`));
  return out;
});
const valid = computed(() => due.value > 0 && rows.value.length > 0 && rows.value.every((r) => !rowError(r)) && nonCash.value <= due.value && (!hasAccount.value || props.namedCustomer) && paid.value >= due.value);

/** The row's amount that settles the rest: what is due minus the other rows. */
function exact(r) {
  const others = paid.value - amountC(r);
  r.amount = fromCents(Math.max(0, due.value - others));
}
/** Quick cash notes: the next round amounts above what is due. */
const quick = computed(() => {
  const d = fromCents(due.value);
  const set = new Set();
  for (const step of [5, 10, 50, 100, 500]) { const v = Math.ceil(d / step) * step; if (v > d) set.add(v); }
  return [...set].sort((a, b) => a - b).slice(0, 4);
});
function quickCash(v) {
  const m = firstOf('cash');
  if (!m) return;
  const others = rows.value.filter((r) => kindOf(r) !== 'cash');
  rows.value = [...others, newRow(m, Math.max(0, v - fromCents(others.reduce((s, r) => s + amountC(r), 0))))];
}
function addRow() {
  const used = new Set(rows.value.map((r) => r.method));
  const m = props.methods.find((x) => !used.has(x.id)) || props.methods[0] || null;
  rows.value.push(newRow(m, fromCents(remaining.value)));
}
const removeRow = (i) => { rows.value.splice(i, 1); };
function pay() {
  tried.value = true;
  if (!valid.value || props.pending) return;
  emit('pay', rows.value.map((r) => ({ method: r.method, amount: fromCents(amountC(r)), reference: String(r.reference || '').trim() || undefined })));
}
/** The row the server named in its refusal (`details.payment`, 1-based). */
const badRow = computed(() => Number(props.error?.details?.payment || 0) - 1);
</script>

<template>
  <Modal :open="open" :title="{ ar: 'الدفع', en: 'Payment' }" :sub="customerLabel || null" :width="560" @close="emit('close')">
    <div class="mb-3 flex items-end justify-between rounded-xl bg-night px-4 py-3 text-white">
      <div>
        <div class="text-[10.5px] opacity-75">{{ t('المستحق', 'Due') }}</div>
        <div class="num ltr text-[24px] font-extrabold leading-none">{{ fmtCents(due) }}</div>
      </div>
      <div class="text-end">
        <div class="text-[10.5px] opacity-75">{{ change > 0 ? t('الباقي للعميل', 'Change due') : t('المتبقي', 'Remaining') }}</div>
        <div class="num ltr text-[20px] font-extrabold leading-none" :class="change > 0 ? 'text-[#7ee2a0]' : remaining > 0 ? 'text-[#ffc27a]' : ''">{{ fmtCents(change > 0 ? change : remaining) }}</div>
      </div>
    </div>

    <div v-if="!methods.length" class="banner red mb-3">{{ t('لا طرق دفع على هذا الصندوق — يضيفها المدير من «الإعداد».', 'This register has no payment method — a manager adds them under "Setup".') }}</div>

    <div v-for="(r, i) in rows" :key="r.key" class="mb-2 rounded-xl border p-2.5" :class="(tried && rowError(r)) || badRow === i ? 'border-bad' : 'border-line'">
      <div class="flex flex-wrap items-end gap-2">
        <SelectInput v-model="r.method" small field-class="!mb-0 min-w-[170px] flex-1" :options="options" :label="{ ar: 'الطريقة', en: 'Method' }" />
        <NumberInput v-model="r.amount" small field-class="!mb-0 w-[130px]" :min="0" :label="{ ar: 'المبلغ', en: 'Amount' }" placeholder="0.00" />
        <Btn tone="softBlue" size="sm" :label="{ ar: 'المبلغ بالضبط', en: 'Exact' }" @click="exact(r)" />
        <Btn v-if="rows.length > 1" tone="softRed" size="sm" :label="{ ar: 'حذف', en: 'Remove' }" @click="removeRow(i)" />
      </div>
      <TextInput v-if="methodOf(r.method)?.requiresReference" v-model="r.reference" small dir="ltr" field-class="!mb-0 mt-2" :label="{ ar: 'المرجع (رقم العملية)', en: 'Reference (transaction no.)' }" required
                 :error="tried && !String(r.reference || '').trim() ? t('مطلوب', 'Required') : null" />
      <div class="mt-1.5 flex items-center gap-2">
        <Chip v-if="kindOf(r)" small :map="METHOD_KIND" :k="kindOf(r)" />
        <span v-if="tried && rowError(r)" class="text-[10.5px] font-bold text-bad">{{ rowError(r) }}</span>
      </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center gap-1.5">
      <Btn tone="soft" size="sm" :disabled="!methods.length || rows.length >= 10" :label="{ ar: '+ دفعة أخرى (تقسيم)', en: '+ Split payment' }" @click="addRow" />
      <template v-if="firstOf('cash')">
        <span class="ms-2 text-[10.5px] text-muted">{{ t('نقد سريع:', 'Quick cash:') }}</span>
        <button v-for="v in quick" :key="v" type="button" class="pill num" @click="quickCash(v)">{{ fmtMoney(v) }}</button>
      </template>
    </div>

    <ul v-if="tried && problems.length" class="mb-2 list-disc ps-5 text-[11px] font-bold text-bad">
      <li v-for="p in problems" :key="p">{{ p }}</li>
    </ul>
    <div v-else-if="nonCash > due" class="mb-2 text-[11px] font-bold text-bad">{{ problems[0] }}</div>

    <template #footer>
      <ErrorBanner :error="error" @close="emit('clear-error')" />
      <div v-if="note" class="hint !mt-0 mb-2">{{ note }}</div>
      <div class="flex gap-2">
        <Btn tone="success" class="!h-12 flex-1 !text-[13px]" :loading="pending" :disabled="!methods.length" @click="pay">
          {{ t('تأكيد الدفع', 'Confirm payment') }} · <span class="num ltr">{{ fmtCents(paid) }}</span>
        </Btn>
        <Btn tone="soft" class="!h-12 w-[110px]" :label="{ ar: 'رجوع', en: 'Back' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
