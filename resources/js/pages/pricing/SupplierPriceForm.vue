<script setup>
// One supplier price — new (POST /pricing/supplier-prices) or changed (PATCH /pricing/supplier-prices/{id}): the supplier,
// the product, the price and its discount, from which quantity, the lead time, the period, and the supplier's own code
// and name of the product. The server refuses a second active price of the same supplier, product and minimum quantity
// whose period overlaps (409 SUPPLIER_PRICE_DUPLICATE): a new period is added by ending the old one first.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { num, t } from '@/i18n';
import PickRecord from './PickRecord.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The price being changed; null = a new one. */
  row: { type: Object, default: null },
  /** What a new price starts with (the filters of the list). */
  supplier: { type: String, default: '' },
  product: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const fields = [
  { k: 'supplier', label: { ar: 'المورد', en: 'Supplier' }, required: true },
  { k: 'product', label: { ar: 'المنتج', en: 'Product' }, required: true },
  { k: 'price', label: { ar: 'سعر الوحدة', en: 'Unit price' }, type: 'num', required: true, min: 0, validate: (v) => (num(v) > 0 ? null : t('أكبر من صفر', 'Greater than zero')) },
  { k: 'discountPct', label: { ar: 'خصم المورد %', en: 'Supplier discount %' }, type: 'num', min: 0, max: 99.99, hint: { ar: 'يُطرح من السعر: الصافي هو ما يُقارَن', en: 'Taken off the price: the net is what is compared' } },
  { k: 'minQty', label: { ar: 'من كمية', en: 'From quantity' }, type: 'num', min: 0, hint: { ar: 'صفر = أي كمية', en: 'Zero = any quantity' } },
  { k: 'leadDays', label: { ar: 'مهلة التوريد (أيام)', en: 'Lead time (days)' }, type: 'num', min: 0, max: 3650, hint: { ar: 'فارغ = مهلة المورد العامة', en: "Empty = the supplier's own lead time" } },
  { k: 'validFrom', label: { ar: 'من تاريخ', en: 'From' }, type: 'date', validate: (v, all) => (v && all.validTo && v > all.validTo ? t('بعد تاريخ النهاية', 'After the end date') : null) },
  { k: 'validTo', label: { ar: 'إلى تاريخ', en: 'Until' }, type: 'date', hint: { ar: 'فارغ = دائم', en: 'Empty = open-ended' } },
  { k: 'supplierSku', label: { ar: 'رمز المنتج عند المورد', en: "The supplier's code of the product" }, dir: 'ltr' },
  { k: 'supplierProductName', label: { ar: 'اسم المنتج عند المورد', en: "The supplier's name of the product" } },
  { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area' },
];

const initial = computed(() => {
  const r = props.row;
  if (!r) return { supplier: props.supplier || '', product: props.product || '', minQty: 0 };
  return {
    supplier: r.supplier?.code || '', product: r.product?.sku || '', price: num(r.price), discountPct: num(r.discountPct) || null, minQty: num(r.minQty), leadDays: r.leadDays,
    validFrom: r.validFrom || '', validTo: r.validTo || '', supplierSku: r.supplierSku || '', supplierProductName: r.supplierProductName || '', note: r.note || '',
  };
});

function submit(v) {
  // an emptied field is sent as "none" (the form leaves empty fields out)
  const body = {
    supplier: v.supplier, product: v.product, price: v.price, discountPct: v.discountPct ?? 0, minQty: v.minQty ?? 0, leadDays: v.leadDays ?? null, validFrom: v.validFrom || null, validTo: v.validTo || null,
    supplierSku: v.supplierSku || null, supplierProductName: v.supplierProductName || null, note: v.note || null,
  };
  return props.row ? api.patch(`/pricing/supplier-prices/${encodeURIComponent(props.row.id)}`, body) : api.postIdempotent('/pricing/supplier-prices', body);
}
</script>

<template>
  <FormDrawer :open="open" :title="row ? { ar: 'تعديل سعر المورد', en: 'Edit the supplier price' } : { ar: 'سعر مورد جديد', en: 'New supplier price' }" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: row ? { ar: 'حُفظ السعر ✓', en: 'Price saved ✓' } : { ar: 'أُضيف السعر ✓', en: 'Price added ✓' }, invalidate: ['pricing'] }" @close="emit('close')" @done="(r) => emit('done', r)">
    <template #field-supplier="{ value, set }">
      <PickRecord kind="supplier" :model-value="value || ''" :label="{ ar: 'المورد', en: 'Supplier' }" required @update:model-value="set" />
    </template>
    <template #field-product="{ value, set }">
      <PickRecord kind="product" :model-value="value || ''" :label="{ ar: 'المنتج', en: 'Product' }" required @update:model-value="set" />
    </template>
    <template #after>
      <div class="hint">
        {{ t('لتسعير فترة جديدة: أنهِ مدة السعر الحالي بتاريخ، ثم أضف السعر الجديد من اليوم التالي — يبقى السعر القديم في السجل. لشرائح الكمية أضف سعرًا آخر بحد أدنى مختلف.',
          'To price a new period: give the current price an end date, then add the new price from the next day — the old price stays on record. For quantity tiers add another price with a different minimum quantity.') }}
      </div>
    </template>
  </FormDrawer>
</template>
