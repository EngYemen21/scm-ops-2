<script setup>
// New return form (POST /api/returns). The type decides the path:
//   customer / delivery — goods that arrive: lines typed by SKU; a customer return may name the delivery it returns
//                         (customer invoice or fulfilment order) — that is what earns it a credit note at receipt;
//   supplier / damaged  — goods taken from stock: lines picked from the balance rows (bin, batch, available); a supplier
//                         return may name the purchase order / goods receipt and carries the price claimed.
import { computed, ref } from 'vue';
import { api, useList } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import ReturnLinesEditor from './ReturnLinesEditor.vue';
import ReturnStockPicker from './ReturnStockPicker.vue';
import { RETURN_REASON_LABELS, RETURN_TYPE_LABELS, useWhOpts } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Pre-selects the return type (the active tab of the list). */
  initialType: { type: String, default: null },
  /** A customer return opened from a delivered order: `{ customerCode, foNumber }`. */
  prefill: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const wh = useWarehouse();
const whOpts = useWhOpts();
const customers = useList('/customers', { pageSize: 200 }, { enabled: () => props.open });
const suppliers = useList('/suppliers', { pageSize: 200 }, { enabled: () => props.open });
/** POs / GRNs of the chosen supplier, handed over by the stock picker. */
const receipts = ref({ pos: [], grns: [] });

const fromStock = (v) => v.type === 'sup' || v.type === 'dmg';
const validLines = (v) => (v || []).filter((r) => r.sku?.trim());
const fields = computed(() => [
  { k: 'type', label: { ar: 'نوع المرتجع', en: 'Return type' }, type: 'select', required: true, def: props.prefill ? 'cust' : props.initialType && RETURN_TYPE_LABELS[props.initialType] ? props.initialType : 'cust', opts: Object.entries(RETURN_TYPE_LABELS).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } })) },
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', required: true, opts: whOpts.value, def: wh.current?.code },
  { k: 'source', label: { ar: 'المصدر (اسم العميل / المورد / الرحلة)', en: 'Source (customer / supplier / trip)' }, required: true },
  { k: 'reference', label: { ar: 'المرجع (اختياري)', en: 'Reference (optional)' }, dir: 'ltr' },
  { k: 'customerCode', label: { ar: 'العميل', en: 'Customer' }, type: 'select', def: props.prefill?.customerCode || undefined, visible: (v) => !fromStock(v), ph: { ar: '— اختياري —', en: '— optional —' }, opts: (customers.data.value?.items || []).map((c) => ({ v: c.code, l: `${c.nameAr} · ${c.code}` })) },
  { k: 'invoiceNumber', label: { ar: 'فاتورة العميل المُرجَع منها', en: 'Customer invoice returned from' }, dir: 'ltr', visible: (v) => v.type === 'cust', ph: 'CI-…', hint: { ar: 'اذكرها (أو أمر التجهيز) ليصدر إشعار دائن عند الاستلام', en: 'Name it (or the fulfilment order) to get a credit note at receipt' } },
  { k: 'foNumber', label: { ar: 'أو أمر التجهيز المُسلَّم', en: 'or the delivered fulfilment order' }, dir: 'ltr', def: props.prefill?.foNumber || undefined, visible: (v) => v.type === 'cust', ph: 'FO-…', hint: { ar: 'بدون مرجع تسليم: يُستلم بمتوسط التكلفة ولا يصدر إشعار دائن', en: 'Without a delivery reference: received at average cost, no credit note' } },
  { k: 'supplierCode', label: { ar: 'المورد', en: 'Supplier' }, type: 'select', visible: (v) => v.type === 'sup', required: true, opts: (suppliers.data.value?.items || []).map((s) => ({ v: s.code, l: `${s.nameAr} · ${s.code}` })) },
  { k: 'poNumber', label: { ar: 'أمر الشراء (اختياري)', en: 'Purchase order (optional)' }, type: 'select', visible: (v) => v.type === 'sup', ph: { ar: '— كل استلامات المورد —', en: '— all of the supplier’s receipts —' }, opts: receipts.value.pos.map((p) => ({ v: p.number, l: p.number })), hint: { ar: 'يحدد سعر وضريبة إشعار المدين', en: 'Sets the price and VAT of the debit note' } },
  { k: 'grnNumber', label: { ar: 'سند الاستلام (اختياري)', en: 'Goods receipt (optional)' }, type: 'select', visible: (v) => v.type === 'sup', ph: { ar: '—', en: '—' }, opts: (v) => receipts.value.grns.filter((g) => !v.poNumber || g.poNumber === v.poNumber).map((g) => ({ v: g.number, l: `${g.number} · ${g.poNumber}` })) },
  { k: 'reasonCode', label: { ar: 'كود السبب', en: 'Reason code' }, type: 'select', required: true, def: 'damaged', opts: Object.entries(RETURN_REASON_LABELS).map(([v, l]) => ({ v, l })) },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
  {
    k: 'lines', label: { ar: 'الأصناف', en: 'Lines' }, full: true, def: [], visible: (v) => !fromStock(v),
    validate: (v) => {
      const rows = validLines(v);
      if (!rows.length) return t('أضف صنفًا واحدًا على الأقل', 'Add at least one line');
      if (rows.some((r) => !(Number(r.qty) > 0))) return t('الكمية يجب أن تكون أكبر من صفر', 'Quantity must be greater than zero');
      return null;
    },
  },
  {
    k: 'stockLines', label: { ar: 'الأصناف من المخزون', en: 'Lines from stock' }, full: true, def: [], visible: (v) => fromStock(v),
    validate: (v) => {
      if (!(v || []).length) return t('اختر صفًّا واحدًا على الأقل من رصيد المستودع', 'Pick at least one row from the warehouse stock');
      if (v.some((r) => !(Number.isInteger(Number(r.qty)) && Number(r.qty) > 0))) return t('الكمية يجب أن تكون عددًا صحيحًا أكبر من صفر', 'Quantity must be a whole number above zero');
      if (v.some((r) => Number(r.qty) > r.available)) return t('كمية تتجاوز المتاح في موقعها', 'A quantity exceeds what is available in its bin');
      return null;
    },
  },
]);

function submit(v) {
  const stock = fromStock(v);
  const body = { type: v.type, source: v.source, warehouseCode: v.warehouseCode, reasonCode: v.reasonCode, reference: v.reference || undefined, notes: v.notes || undefined };
  if (stock) {
    if (v.type === 'sup') Object.assign(body, { supplierCode: v.supplierCode, poNumber: v.poNumber || undefined, grnNumber: v.grnNumber || undefined });
    body.lines = (v.stockLines || []).map((r) => ({ sku: r.sku, qty: Number(r.qty), fromBin: r.fromBin, batchNo: r.batchNo || undefined, unitPrice: v.type === 'sup' && r.unitPrice != null && r.unitPrice !== '' ? Number(r.unitPrice) : undefined }));
  } else {
    Object.assign(body, { customerCode: v.customerCode || undefined });
    if (v.type === 'cust') Object.assign(body, { invoiceNumber: v.invoiceNumber?.trim() || undefined, foNumber: v.foNumber?.trim() || undefined });
    body.lines = validLines(v.lines).map((r) => ({ sku: String(r.sku).trim(), qty: Number(r.qty), batchNo: r.batchNo?.trim() || undefined }));
  }
  return api.postIdempotent('/returns', body);
}
const action = { success: (r) => r?.message || t(`أُنشئ المرتجع ${r?.number || ''}`, `Return ${r?.number || ''} created`), invalidate: ['returns', 'dashboard'] };
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'مرتجع جديد', en: 'New return' }" :sub="{ ar: 'بضاعة تصل: طلب ← اعتماد ← استلام ← فحص ← قرار · من المخزون: طلب ← اعتماد ← شحن', en: 'Arriving goods: request → approve → receive → inspect → decide · from stock: request → approve → ship' }"
              :fields="fields" :submit="submit" :action="action" :width="600" @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-lines="{ value, set, error }"><ReturnLinesEditor :model-value="value || []" :error="error" @update:model-value="set" /></template>
    <template #field-stockLines="{ value, set, values, error }">
      <ReturnStockPicker :model-value="value || []" :warehouse="values.warehouseCode || null" :supplier="values.type === 'sup' ? values.supplierCode || null : null" :needs-supplier="values.type === 'sup'"
                         :po="values.type === 'sup' ? values.poNumber || null : null" :grn="values.type === 'sup' ? values.grnNumber || null : null" :priced="values.type === 'sup'" :error="error"
                         @update:model-value="set" @receipts="receipts = $event" />
    </template>
    <template #after="{ values }">
      <div v-if="values.type === 'sup'" class="hint teal">{{ t('إرجاع لمورد من المخزون: عند الاعتماد تُحجز الكمية في موقعها، وعند الشحن تخرج من الرصيد ويصدر إشعار مدين على المورد بسعر الشراء والضريبة. لا يُنشأ رصيد في منطقة المرتجعات.', 'Supplier return from stock: approval holds the quantity in its bin; shipping takes it out and issues a debit note at the purchase price + VAT. No stock is created in the returns area.') }}</div>
      <div v-else-if="values.type === 'dmg'" class="hint teal">{{ t('أصناف تالفة من موقعها: عند الاعتماد تُحجز الكمية، وعند التنفيذ تنتقل إلى منطقة التالف DMG-01 (تبقى في الرصيد خارج المتاح).', 'Damaged goods from their bin: approval holds the quantity; execution moves it to the damaged area DMG-01 (on hand, not available).') }}</div>
      <div v-else-if="values.type === 'cust'" class="hint teal">{{ t('مرتجع عميل: إن ذكرت فاتورته أو أمر تجهيزه يصدر إشعار دائن بالكمية المستلمة وتُعكس تكلفة المبيعات؛ وإلا يُستلم بمتوسط التكلفة بلا إشعار دائن.', 'Customer return: with its invoice or fulfilment order a credit note is issued for the received quantity and the cost of sales is reversed; otherwise it is received at average cost with no credit note.') }}</div>
    </template>
  </FormDrawer>
</template>
