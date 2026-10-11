<script setup>
// Register a supplier invoice against a PO (three-way match on the server). POST /procurement/invoices.
// Lines default to the quantity accepted on GRNs minus what was already invoiced, at the PO price.
//   <InvoiceForm :open :po="poDetail" @close @done="(invoice) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, TextArea, TextInput } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import { pname, plusDays, todayIso } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Po detail (lines with receivedQty/damagedQty/rejectedQty, invoices[], discount, shippingCost, vatPct). */
  po: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);
const act = useAction();

const f = ref({ supplierInvoiceNo: '', invoiceDate: todayIso(), dueDate: '', discount: 0, shippingCost: 0, vatPct: 15, notes: '' });
/** lineNo → { qty, price } */
const rows = ref({});
watch(() => props.open, (open) => {
  if (!open || !props.po) return;
  const po = props.po;
  f.value = { supplierInvoiceNo: '', invoiceDate: todayIso(), dueDate: plusDays(30), discount: Number(po.discount) || 0, shippingCost: Number(po.shippingCost) || 0, vatPct: po.vatPct != null ? Number(po.vatPct) : 15, notes: '' };
  const r = {};
  (po.lines || []).forEach((l) => { r[l.lineNo] = { qty: billable(l), price: Number(l.price) }; });
  rows.value = r;
  act.clearError();
}, { immediate: true });

/** Accepted on GRNs minus what earlier (non-cancelled) invoices already cover; falls back to the PO-line counters. */
const billable = (l) => (l.acceptedQty != null ? Math.max(0, (l.acceptedQty || 0) - (l.invoicedQty || 0)) : Math.max(0, (l.receivedQty || 0) - (l.damagedQty || 0) - (l.rejectedQty || 0)));
const lines = computed(() => props.po?.lines || []);
const setRow = (lineNo, patch) => { rows.value = { ...rows.value, [lineNo]: { ...rows.value[lineNo], ...patch } }; };
const included = computed(() => lines.value.filter((l) => Number(rows.value[l.lineNo]?.qty) > 0));
const subtotal = computed(() => included.value.reduce((a, l) => a + Number(rows.value[l.lineNo].qty) * (Number(rows.value[l.lineNo].price) || 0), 0));
const total = computed(() => (subtotal.value - (Number(f.value.discount) || 0) + (Number(f.value.shippingCost) || 0)) * (1 + (Number(f.value.vatPct) || 0) / 100));
const ok = computed(() => f.value.supplierInvoiceNo.trim().length > 0 && !!f.value.invoiceDate && included.value.length > 0 && included.value.every((l) => Number(rows.value[l.lineNo].price) >= 0));

async function submit() {
  const v = f.value;
  const body = {
    po: props.po.number, supplierInvoiceNo: v.supplierInvoiceNo.trim(), invoiceDate: v.invoiceDate, dueDate: v.dueDate || undefined, discount: Number(v.discount) || 0, shippingCost: Number(v.shippingCost) || 0, vatPct: Number(v.vatPct) || 0, notes: v.notes || undefined,
    lines: included.value.map((l) => ({ lineNo: l.lineNo, qty: Number(rows.value[l.lineNo].qty), price: Number(rows.value[l.lineNo].price) })),
  };
  const r = await act.run(() => api.postIdempotent('/procurement/invoices', body), {
    success: (inv) => (inv.status === 'disputed' ? t(`سُجلت ${inv.number} — مختلف عليها (${inv.variances?.length || 0} فرق) وتحتاج قرار المالية`, `${inv.number} registered — disputed (${inv.variances?.length || 0} variance(s))`) : t(`سُجلت ${inv.number} — مطابقة، بانتظار اعتماد الصرف`, `${inv.number} registered — matched, awaiting approval`)),
    invalidate: ['procurement'],
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open && !!po" :width="620" :title="{ ar: `تسجيل فاتورة مورد — ${po?.number || ''}`, en: `Register supplier invoice — ${po?.number || ''}` }"
                :sub="{ ar: 'تُطابق آليًا مع أمر الشراء وإشعارات الاستلام (كمية ≤ المقبول · السعر ضمن التسامح · الخصم والشحن)', en: 'Matched automatically against the PO and the GRNs (qty ≤ accepted · price within tolerance · charges)' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'تسجيل الفاتورة', en: 'Register invoice' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.supplierInvoiceNo" :label="{ ar: 'رقم فاتورة المورد', en: 'Supplier invoice no.' }" required dir="ltr" />
      <DateInput v-model="f.invoiceDate" :label="{ ar: 'تاريخ الفاتورة', en: 'Invoice date' }" required />
      <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ الاستحقاق', en: 'Due date' }" :hint="{ ar: 'فارغ = يُحسب من شروط دفع المورد', en: 'Blank = derived from the supplier terms' }" />
      <NumberInput v-model="f.vatPct" :label="{ ar: 'ضريبة القيمة المضافة %', en: 'VAT %' }" :min="0" :max="100" />
      <NumberInput v-model="f.discount" :label="{ ar: 'خصم على الفاتورة ر.س', en: 'Invoice discount SAR' }" :min="0" />
      <NumberInput v-model="f.shippingCost" :label="{ ar: 'شحن ر.س', en: 'Shipping SAR' }" :min="0" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" full />
    </div>
    <div class="mt-3.5 overflow-hidden rounded-xl border border-line-2">
      <div class="grid grid-cols-[minmax(120px,1.5fr)_70px_80px_90px] gap-1.5 bg-soft px-2.5 py-[7px] text-[9.5px] font-extrabold text-muted">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('متاح للفوترة', 'Billable') }}</div><div>{{ t('كمية الفاتورة', 'Invoiced qty') }}</div><div>{{ t('سعر الوحدة', 'Unit price') }}</div>
      </div>
      <div v-for="l in lines" :key="l.id" class="grid grid-cols-[minmax(120px,1.5fr)_70px_80px_90px] items-center gap-1.5 border-t border-line-2 px-2.5 py-[7px]">
        <div class="min-w-0"><div class="ellipsis text-[10.5px] font-extrabold">{{ pname(l.product) }}</div><div class="num ltr text-[8.5px] text-faint">{{ l.product.sku }} · {{ t('سعر الأمر', 'PO price') }} {{ fmtMoney(l.price) }}</div></div>
        <div class="num text-[11px]" :title="t('المقبول في إشعارات الاستلام − المفوتَر سابقًا', 'accepted on GRNs − already invoiced')">{{ fmtNum(billable(l)) }}<div v-if="l.invoicedQty" class="text-[8px] text-faint">{{ t('فُوتر', 'invoiced') }} {{ fmtNum(l.invoicedQty) }}</div></div>
        <NumberInput small :model-value="rows[l.lineNo]?.qty" :min="0" @update:model-value="(v) => setRow(l.lineNo, { qty: v })" />
        <NumberInput small :model-value="rows[l.lineNo]?.price" :min="0" @update:model-value="(v) => setRow(l.lineNo, { price: v })" />
      </div>
      <div class="flex flex-wrap justify-end gap-3 border-t border-line-2 px-2.5 py-2 text-[10.5px] font-extrabold">
        <span>{{ t('الأصناف', 'Goods') }}: <span class="num ltr">{{ fmtMoney(subtotal) }}</span></span>
        <span>{{ t('الإجمالي شامل الضريبة', 'Total incl. VAT') }}: <span class="num ltr">{{ fmtMoney(total) }}</span> <span class="muted font-normal">{{ t('ر.س', 'SAR') }}</span></span>
      </div>
    </div>
    <div v-if="po?.invoices?.length" class="hint !mt-3">{{ t('فواتير سابقة على هذا الأمر', 'Earlier invoices on this PO') }}: {{ po.invoices.map((i) => `${i.number} (${i.status})`).join(' · ') }}</div>
  </ActionDrawer>
</template>
