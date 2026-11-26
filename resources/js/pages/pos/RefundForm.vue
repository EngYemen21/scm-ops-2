<script setup>
// Refunding a sale, recorded in the user's own open session: per line a quantity up to what is still refundable, a
// reason (≥ 5 characters) and the money paid back — which must equal the refund total EXACTLY.
// There is no refund quote on the server, so the total shown here is an ESTIMATE: line net × qty / sold qty (2 decimals)
// + VAT at the order's rate. The server computes the exact figure (the last units take what is left of the line to the
// halala) and, when the payments do not match it, answers POS_REFUND_PAYMENTS with the exact total — offered here as a
// one-click correction.
//   POST /pos/orders/{id}/refund { session, clientKey, reason, lines: [{ lineId, qty }], payments: [{ method, amount, reference? }] }
// Other refusals: POS_REFUND_EXCEEDS · POS_REFUND_NEEDS_MANAGER · POS_REFUND_ACCOUNT · POS_CASH_SHORT · POS_HISTORY_ONLY.
import { computed, ref, watch } from 'vue';
import { api, useAction, uuid } from '@/api/client';
import { Btn, Drawer, ErrorBanner, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { fmtMoney, num, t } from '@/i18n';
import { METHOD_KIND, cents, errCode, fmtCents, fromCents, nameOf, usePosLookups } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = usePosLookups();
const act = useAction();
const session = lk.session;
const register = computed(() => (session.value ? lk.registerById(session.value.registerId) : null));
const methods = computed(() => lk.methodsOf(register.value));
const methodOf = (id) => methods.value.find((m) => m.id === id) || null;
const methodOptions = computed(() => methods.value.map((m) => ({ v: m.id, l: { ar: `${m.nameAr} · ${METHOD_KIND[m.kind]?.ar || m.kind}`, en: `${m.nameEn || m.nameAr} · ${METHOD_KIND[m.kind]?.en || m.kind}` } })));

const qty = ref({});
const reason = ref('');
const payments = ref([]);
const clientKey = ref(uuid());
const tried = ref(false);
/** The exact total the server named in a POS_REFUND_PAYMENTS refusal. */
const serverTotal = ref(null);
const newRow = (m, amount) => ({ key: Math.random().toString(36).slice(2), method: m ? m.id : '', amount, reference: '' });
watch(() => props.open, (o) => {
  if (!o) return;
  qty.value = {}; reason.value = ''; tried.value = false; serverTotal.value = null; clientKey.value = uuid(); act.clearError();
  const cash = methods.value.find((m) => m.kind === 'cash') || methods.value[0] || null;
  payments.value = [newRow(cash, null)];
});

const lines = computed(() => (props.order?.lines || []).filter((l) => (l.refundableQty ?? 0) > 0));
const setQty = (l, v) => { qty.value = { ...qty.value, [l.id]: Math.max(0, Math.min(l.refundableQty, Math.floor(num(v)) || 0)) }; serverTotal.value = null; };
const all = () => { qty.value = Object.fromEntries(lines.value.map((l) => [l.id, l.refundableQty])); serverTotal.value = null; };
const chosen = computed(() => lines.value.filter((l) => (qty.value[l.id] || 0) > 0).map((l) => ({ line: l, q: qty.value[l.id] })));
/** The estimate, in halalas. */
const estNet = computed(() => chosen.value.reduce((s, { line, q }) => s + Math.round((cents(line.lineTotal) * q) / Math.max(1, num(line.qty))), 0));
const estVat = computed(() => Math.round((estNet.value * num(props.order?.vatPct)) / 100));
const estTotal = computed(() => estNet.value + estVat.value);
const target = computed(() => (serverTotal.value != null ? serverTotal.value : estTotal.value));
const paid = computed(() => payments.value.reduce((s, p) => s + cents(p.amount ?? 0), 0));

const rowError = (p) => {
  const m = methodOf(p.method);
  if (!m) return t('اختر الطريقة', 'Choose the method');
  if (!(cents(p.amount ?? 0) > 0)) return t('المبلغ أكبر من صفر', 'Amount above zero');
  if (m.requiresReference && !String(p.reference || '').trim()) return t('المرجع مطلوب', 'Reference required');
  return null;
};
const errors = computed(() => ({
  lines: !chosen.value.length ? t('حدد كمية سطر واحد على الأقل', 'Choose a quantity on at least one line') : null,
  reason: reason.value.trim().length < 5 ? t('السبب 5 أحرف على الأقل', 'At least 5 characters') : null,
  payments: !payments.value.length || payments.value.some(rowError) ? t('أكمل المبالغ المردودة', 'Complete the money paid back') : null,
}));
function exact(p) { const others = paid.value - cents(p.amount ?? 0); p.amount = fromCents(Math.max(0, target.value - others)); }
function addRow() { const used = new Set(payments.value.map((p) => p.method)); payments.value.push(newRow(methods.value.find((m) => !used.has(m.id)) || methods.value[0] || null, fromCents(Math.max(0, target.value - paid.value)))); }
/** Puts the server's exact total on the payments: the difference goes to the last row. */
function applyServerTotal() {
  if (serverTotal.value == null || !payments.value.length) return;
  const last = payments.value[payments.value.length - 1];
  exact(last);
}

async function submit() {
  tried.value = true;
  if (!session.value || Object.values(errors.value).some(Boolean)) return;
  const body = {
    session: session.value.id, clientKey: clientKey.value, reason: reason.value.trim(),
    lines: chosen.value.map(({ line, q }) => ({ lineId: line.id, qty: q })),
    payments: payments.value.map((p) => ({ method: p.method, amount: fromCents(cents(p.amount)), reference: String(p.reference || '').trim() || undefined })),
  };
  const r = await act.run(() => api.postIdempotent(`/pos/orders/${encodeURIComponent(props.order.id)}/refund`, body), { success: (x) => t(`سُجّل المرتجع ${x.number}`, `Refund ${x.number} recorded`), invalidate: ['pos'] });
  if (r) { emit('done', r); return; }
  const e = act.error.value;
  if (errCode(e) === 'POS_REFUND_PAYMENTS' && e.details?.total != null) serverTotal.value = cents(e.details.total);
  if (errCode(e) === 'POS_KEY_REUSED') clientKey.value = uuid();
}
const badLine = computed(() => (errCode(act.error.value) === 'POS_REFUND_EXCEEDS' ? act.error.value.details?.lineId : null));
const badPay = computed(() => Number(act.error.value?.details?.payment || 0) - 1);
</script>

<template>
  <Drawer :open="open" :title="{ ar: 'استرجاع', en: 'Refund' }" :sub="order ? order.number : null" :width="640" :z-index="72" @close="emit('close')">
    <div v-if="!session" class="banner amber">{{ t('لا جلسة مفتوحة لك — افتح جلستك من تبويب «البيع»، فالمرتجع يُسجَّل في جلستك ويُرد المال من درجها.', 'You have no open session — open yours in the Sell tab: the refund is recorded in your session and paid from its drawer.') }}</div>
    <template v-else-if="order">
      <div class="hint !mt-0 mb-3">{{ t('يُسجَّل في جلستك', 'Recorded in your session') }} <b class="num ltr">{{ session.number }}</b>{{ register ? ` · ${register.code} · ${nameOf(register)}` : '' }}</div>

      <div class="mb-1.5 flex items-center">
        <div class="card-title flex-1">{{ t('الكميات المرتجعة', 'Quantities returned') }}</div>
        <Btn tone="ghost" size="sm" :label="{ ar: 'إرجاع الكل', en: 'Return all' }" @click="all" />
      </div>
      <div v-for="l in lines" :key="l.id" class="mb-1.5 flex flex-wrap items-center gap-2 rounded-xl border p-2" :class="badLine === l.id ? 'border-bad bg-bad-soft' : 'border-line'">
        <div class="min-w-0 flex-1">
          <div class="truncate text-[11.5px] font-extrabold text-ink" dir="auto">{{ nameOf({ nameAr: l.name, nameEn: l.nameEn }) }}</div>
          <div class="text-[9.5px] text-muted"><span class="num ltr">{{ l.sku }}</span> · {{ t('بيع', 'sold') }} <span class="num">{{ l.qty }}</span> · {{ t('يُرجع حتى', 'up to') }} <span class="num">{{ l.refundableQty }}</span> · {{ t('صافي السطر', 'line net') }} <span class="num">{{ fmtMoney(num(l.lineTotal)) }}</span></div>
        </div>
        <NumberInput small center class="!w-[72px]" field-class="!mb-0" :model-value="qty[l.id] || null" :min="0" :max="l.refundableQty" :step="1" placeholder="0" @update:model-value="(v) => setQty(l, v)" />
      </div>
      <div v-if="tried && errors.lines" class="field-err mb-2">{{ errors.lines }}</div>

      <div class="my-3 rounded-xl bg-soft p-3 text-[11.5px]">
        <div class="flex justify-between"><span class="text-sec">{{ t('الصافي', 'Net') }}</span><span class="num ltr">{{ fmtCents(estNet) }}</span></div>
        <div class="flex justify-between"><span class="text-sec">{{ t('الضريبة', 'VAT') }} <span class="num">{{ num(order.vatPct) }}%</span></span><span class="num ltr">{{ fmtCents(estVat) }}</span></div>
        <div class="mt-1 flex items-center justify-between font-extrabold">
          <span>{{ t('مبلغ الاسترجاع', 'Refund total') }} <span class="chip sm ms-1" style="color:#b26a16;background:#fbf0dd">{{ t('تقديري', 'estimate') }}</span></span>
          <span class="num ltr text-[14px]">{{ fmtCents(estTotal) }}</span>
        </div>
        <div v-if="serverTotal != null" class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white p-2 font-extrabold text-violet">
          <span>{{ t('المبلغ الدقيق من الخادم', 'Exact total from the server') }}: <span class="num ltr">{{ fmtCents(serverTotal) }}</span></span>
          <Btn tone="softPurple" size="sm" :label="{ ar: 'تطبيقه على المبالغ', en: 'Apply to payments' }" @click="applyServerTotal" />
        </div>
      </div>

      <div class="card-title mb-1.5">{{ t('المال المردود', 'Money paid back') }}</div>
      <div v-for="(p, i) in payments" :key="p.key" class="mb-2 rounded-xl border p-2" :class="badPay === i || (tried && rowError(p)) ? 'border-bad' : 'border-line'">
        <div class="flex flex-wrap items-end gap-2">
          <SelectInput v-model="p.method" small field-class="!mb-0 min-w-[160px] flex-1" :options="methodOptions" :label="{ ar: 'الطريقة', en: 'Method' }" />
          <NumberInput v-model="p.amount" small field-class="!mb-0 w-[120px]" :min="0" :label="{ ar: 'المبلغ', en: 'Amount' }" placeholder="0.00" />
          <Btn tone="softBlue" size="sm" :label="{ ar: 'الباقي', en: 'Rest' }" @click="exact(p)" />
          <Btn v-if="payments.length > 1" tone="softRed" size="sm" :label="{ ar: 'حذف', en: 'Remove' }" @click="payments.splice(i, 1)" />
        </div>
        <TextInput v-if="methodOf(p.method)?.requiresReference" v-model="p.reference" small dir="ltr" field-class="!mb-0 mt-2" :label="{ ar: 'المرجع', en: 'Reference' }" required />
        <div v-if="tried && rowError(p)" class="mt-1 text-[10.5px] font-bold text-bad">{{ rowError(p) }}</div>
      </div>
      <div class="mb-3 flex items-center gap-2">
        <Btn tone="soft" size="sm" :disabled="payments.length >= 10 || !methods.length" :label="{ ar: '+ طريقة أخرى', en: '+ Another method' }" @click="addRow" />
        <div class="grow" />
        <span class="text-[11px]" :class="paid === target ? 'text-ok' : 'text-warn'">{{ t('المردود', 'Paid back') }} <b class="num ltr">{{ fmtCents(paid) }}</b> / <span class="num ltr">{{ fmtCents(target) }}</span></span>
      </div>

      <TextArea v-model="reason" :rows="2" required :label="{ ar: 'سبب الاسترجاع', en: 'Reason' }" :error="tried ? errors.reason : null" :placeholder="{ ar: 'مثال: عبوة تالفة', en: 'e.g. damaged pack' }" />
    </template>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="danger" class="!h-11 flex-1" :disabled="!session" :loading="act.pending.value" :label="{ ar: 'تسجيل الاسترجاع', en: 'Record refund' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
