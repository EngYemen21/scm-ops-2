<script setup>
// Direct sales order drawer: customer (with what he owes against his credit limit), warehouse, delivery date / window /
// priority and the line editor with discount. The server checks the discount rule, the credit limit and availability
// (no overselling), then reserves FEFO in one transaction; its rejection message is shown as is.
//  - a discount above the policy limit is accepted only from a holder of `discount.approve` (anyone else goes through a
//    quotation and its discount approval);
//  - when stock is short the refusal lists what is available and offers "قبول مع تعليق الباقي" (ShortagePanel).
//   <SoForm :open="open" :initial-customer="code" :default-warehouse="wh" @close="…" @done="(so) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, SelectInput } from '@/components';
import { fmtMoney, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import LinesEditor from './LinesEditor.vue';
import ShortagePanel from './ShortagePanel.vue';
import { PRIORITIES, WINDOWS, customerOptions, isoDatePlus, linesBody, linesTotals, maxLineDiscount, newLine, useLookups, usePolicy, validateLines, warehouseOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initialCustomer: { type: String, default: null },
  defaultWarehouse: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const lookups = useLookups();
const policy = usePolicy();
const act = useAction();

const blank = () => ({ customerCode: props.initialCustomer || '', warehouseCode: props.defaultWarehouse || '', dueDate: isoDatePlus(1), window: '09:00–13:00', priority: 'normal' });
const v = reactive(blank());
const lines = ref([newLine()]);
const errors = ref({});

watch(() => [props.open, props.initialCustomer, props.defaultWarehouse], () => {
  if (!props.open) return;
  Object.assign(v, blank()); lines.value = [newLine()]; errors.value = {}; act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const customers = computed(() => customerOptions(lookups.data.value));
const warehouses = computed(() => warehouseOptions(lookups.data.value));

// what the chosen customer owes right now (unpaid invoices + open orders) against his limit — live, from the customer card
const card = useGet(() => (props.open && v.customerCode ? `/customers/${encodeURIComponent(v.customerCode)}` : null));
const credit = computed(() => (card.data.value?.code === v.customerCode ? card.data.value.credit : null));
const total = computed(() => linesTotals(lines.value, policy.value?.vatPct).total);
const creditHint = computed(() => {
  const c = credit.value;
  if (!c) return null;
  if (!(c.limit > 0)) {
    return c.limited
      ? { ar: `بلا حد ائتماني — يُرفض الطلب ما دام عليه مبلغ غير مسدد (المستحق ${fmtMoney(c.receivables)})`, en: `No credit limit — refused while anything is unpaid (receivables ${fmtMoney(c.receivables)})` }
      : { ar: `بلا حد ائتماني (غير مقيَّد) · المستحق ${fmtMoney(c.receivables)} · طلبات مفتوحة ${fmtMoney(c.openOrders)}`, en: `No credit limit (not limited) · receivables ${fmtMoney(c.receivables)} · open orders ${fmtMoney(c.openOrders)}` };
  }
  return { ar: `الحد ${fmtMoney(c.limit)} · المستحق ${fmtMoney(c.receivables)} · طلبات مفتوحة ${fmtMoney(c.openOrders)} · المتاح ${fmtMoney(c.available)}`, en: `Limit ${fmtMoney(c.limit)} · receivables ${fmtMoney(c.receivables)} · open orders ${fmtMoney(c.openOrders)} · available ${fmtMoney(c.available)}` };
});
/** The order would exceed the customer's available credit (the server decides — this only warns before the click). */
const overCredit = computed(() => credit.value && credit.value.limit > 0 && total.value != null && total.value > num(credit.value.available) + 0.005);

// discount rule: above the limit only a holder of discount.approve may place a direct order
const maxPct = computed(() => policy.value?.maxDiscountPct ?? null);
const aboveMax = computed(() => maxPct.value != null && maxLineDiscount(lines.value) > num(maxPct.value));
const mayApprove = computed(() => auth.can('discount.approve'));
const discountBlock = computed(() => (mayApprove.value ? null : {
  maxPct: maxPct.value,
  message: t(`خصم فوق ${maxPct.value}% يحتاج اعتماد الخصم — أنشئ عرض سعر واطلب اعتماده`, `Above ${maxPct.value}% needs discount approval — create a quotation and have it approved`),
}));

async function submit(allowBackorder = false) {
  const errs = { ...validateLines(lines.value, true, discountBlock.value) };
  if (!v.customerCode) errs.customerCode = t('هذا الحقل إلزامي', 'Required');
  if (!v.warehouseCode) errs.warehouseCode = t('هذا الحقل إلزامي', 'Required');
  if (!v.dueDate) errs.dueDate = t('هذا الحقل إلزامي', 'Required');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = { ...v, lines: linesBody(lines.value, true), ...(allowBackorder ? { allowBackorder: true } : {}) };
  const r = await act.run(() => api.postIdempotent('/sales/orders', body), {
    success: (so) => (so.backorders?.length
      ? t(`أُنشئ أمر البيع ${so.number} — حُجز المتاح والباقي في الطلب المعلّق ${so.backorders[0].number}`, `Sales order ${so.number} created — the rest is on backorder ${so.backorders[0].number}`)
      : so.status === 'confirmed'
        ? t(`أُنشئ أمر البيع ${so.number} — معلّق بانتظار المخزون`, `Sales order ${so.number} created — waiting for stock`)
        : t(`أُنشئ أمر البيع ${so.number} — حُجز المخزون FEFO`, `Sales order ${so.number} created and reserved`)),
    invalidate: ['sales', 'inventory', 'customers'],
    // a shortage is answered by the panel below (with its way out), not by a passing toast
    errorToast: false,
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="open" :width="680" :z-index="70" :title="{ ar: 'أمر بيع مباشر', en: 'Direct sales order' }"
          :sub="{ ar: 'فحص الخصم والحد الائتماني والمتاح (لا Overselling) — ثم حجز وتخصيص FEFO في معاملة واحدة', en: 'Discount, credit-limit and stock check, then FEFO reservation in one transaction' }" @close="emit('close')">
    <div class="form-grid">
      <SelectInput v-model="v.customerCode" :label="{ ar: 'العميل', en: 'Customer' }" required :options="customers" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors.customerCode" :hint="creditHint" />
      <SelectInput v-model="v.warehouseCode" :label="{ ar: 'المستودع', en: 'Warehouse' }" required :options="warehouses" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors.warehouseCode" />
      <DateInput v-model="v.dueDate" :label="{ ar: 'تاريخ التسليم', en: 'Delivery date' }" required :error="errors.dueDate" />
      <SelectInput v-model="v.window" :label="{ ar: 'نافذة التسليم', en: 'Delivery window' }" :options="WINDOWS" />
      <SelectInput v-model="v.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITIES" />
    </div>
    <div class="mt-3.5">
      <div class="field-l mb-1.5">{{ t('الأسطر', 'Lines') }} *</div>
      <LinesEditor v-model:lines="lines" discount :errors="errors" :customer="v.customerCode" />
    </div>
    <div v-if="overCredit" class="hint amber">{{ t(`إجمالي الطلب (${fmtMoney(total)}) أكبر من الائتمان المتاح للعميل (${fmtMoney(credit.available)}) — سيرفضه الخادم ما لم يُسدَّد مستحق أو يُرفع الحد.`, `The order total (${fmtMoney(total)}) exceeds the customer's available credit (${fmtMoney(credit.available)}) — the server will refuse it unless a receivable is paid or the limit raised.`) }}</div>
    <div v-if="aboveMax && mayApprove" class="hint purple">{{ t(`في الطلب خصم فوق الحد ${maxPct}% — سيُسجَّل الأمر باعتمادك للخصم (${auth.user?.username || ''}).`, `The order carries a discount above the ${maxPct}% limit — it will be recorded as approved by you (${auth.user?.username || ''}).`) }}</div>
    <div class="hint amber">{{ t('يُرفض الأمر إذا تجاوز الحد الائتماني. إذا كان المتاح أقل من المطلوب يعرض الخادم ما هو متاح، ويمكن قبول الطلب مع تعليق الباقي متى سمحت السياسة.', 'The order is refused when the credit limit is exceeded. When stock is short the server says what is available, and the order can be accepted with the rest on backorder when the policy allows it.') }}</div>
    <ShortagePanel :error="act.error.value" :loading="act.pending.value" @accept="submit(true)" />

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'تأكيد وحجز المخزون', en: 'Confirm & reserve' }" @click="submit(false)" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] text-faint">{{ t(`* حقول إلزامية · يُسجل الإجراء في Audit Trail باسم ${auth.user?.nameAr || ''}`, `* required · recorded in the Audit Trail as ${auth.user?.nameEn || ''}`) }}</div>
    </template>
  </Drawer>
</template>
