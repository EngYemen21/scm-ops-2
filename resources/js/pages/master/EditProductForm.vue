<script setup>
// Edit product — every changed field is audited; `reason` is mandatory (ProductUpdateSchema). Sends only the changed fields.
//   <EditProductForm :product="edit" :open="!!edit" @close="edit = null" @done="…" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { catOpts, lookupWhOpts, serverMsg, storageOpts, supOpts, uomOpts, useLookups } from './_shared';

const props = defineProps({
  /** @type {import('./_shared').Product} */
  product: { type: Object, default: null },
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);

// The drawer is keyed on the lookups (see template) so its selects are rebuilt — and show the record's current
// value — if the form was opened before the lookups arrived.
const lookups = useLookups();
const lk = computed(() => lookups.data.value);

const initial = computed(() => {
  const p = props.product;
  if (!p) return {};
  return {
    nameAr: p.nameAr, nameEn: p.nameEn, barcode: p.primaryBarcode || '', categoryCode: p.category?.code || '', brand: p.brand || '', uomCode: p.baseUom?.code || '',
    lengthCm: p.lengthCm ?? null, widthCm: p.widthCm ?? null, heightCm: p.heightCm ?? null, weightKg: p.weightKg ?? null, storageClass: p.storageClass, shelfLifeDays: p.shelfLifeDays ?? null,
    reorderMin: p.reorderMin ?? 0, reorderMax: p.reorderMax ?? null, unitsPerPallet: p.unitsPerPallet ?? null, purchasePrice: p.purchasePrice != null ? Number(p.purchasePrice) : null,
    homeWarehouseCode: p.homeWarehouse?.code || '', preferredSupplierCode: p.suppliers?.find((s) => s.preferred)?.supplier.code || '', reason: '',
  };
});

const fields = computed(() => [
  { k: 'nameAr', label: { ar: 'الاسم (عربي)', en: 'Name (Arabic)' }, required: true, full: true }, { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, dir: 'ltr' },
  { k: 'barcode', label: { ar: 'الباركود', en: 'Barcode' }, dir: 'ltr' }, { k: 'categoryCode', label: { ar: 'التصنيف', en: 'Category' }, type: 'select', opts: catOpts(lk.value) },
  { k: 'brand', label: { ar: 'العلامة', en: 'Brand' } }, { k: 'uomCode', label: { ar: 'الوحدة', en: 'UoM' }, type: 'select', opts: uomOpts(lk.value) },
  { k: 'lengthCm', label: { ar: 'الطول سم', en: 'Length cm' }, type: 'num', min: 0 }, { k: 'widthCm', label: { ar: 'العرض سم', en: 'Width cm' }, type: 'num', min: 0 }, { k: 'heightCm', label: { ar: 'الارتفاع سم', en: 'Height cm' }, type: 'num', min: 0 },
  { k: 'weightKg', label: { ar: 'الوزن كجم', en: 'Weight kg' }, type: 'num', min: 0 }, { k: 'unitsPerPallet', label: { ar: 'وحدات الطبلية', en: 'Units / pallet' }, type: 'num', min: 0 },
  { k: 'storageClass', label: { ar: 'شرط التخزين', en: 'Storage' }, type: 'select', opts: storageOpts }, { k: 'shelfLifeDays', label: { ar: 'العمر الافتراضي (يوم)', en: 'Shelf life (days)' }, type: 'num', min: 0 },
  { k: 'reorderMin', label: { ar: 'حد إعادة الطلب', en: 'Reorder point' }, type: 'num', min: 0 }, { k: 'reorderMax', label: { ar: 'الحد الأقصى', en: 'Reorder max' }, type: 'num', min: 0 },
  { k: 'purchasePrice', label: { ar: 'سعر الشراء ر.س', en: 'Purchase price SAR' }, type: 'num', min: 0 }, { k: 'homeWarehouseCode', label: { ar: 'المستودع الرئيسي', en: 'Home warehouse' }, type: 'select', opts: lookupWhOpts(lk.value) },
  { k: 'preferredSupplierCode', label: { ar: 'المورد المفضل', en: 'Preferred supplier' }, type: 'select', opts: supOpts(lk.value) },
  { k: 'reason', label: { ar: 'سبب التعديل', en: 'Edit reason' }, type: 'area', required: true, ph: { ar: 'يُسجل مع كل حقل متغير في Audit Trail', en: 'Recorded with every changed field in the audit trail' } },
]);

/** PATCH body = reason + the fields whose value differs from the opened record. */
function submit(v) {
  const body = { reason: v.reason };
  for (const f of fields.value) {
    if (f.k === 'reason') continue;
    const blank = f.type === 'num' ? null : '';
    const nv = v[f.k] ?? blank;
    const ov = initial.value[f.k] ?? blank;
    if (String(nv ?? '') !== String(ov ?? '')) body[f.k] = nv ?? (f.type === 'num' ? undefined : '');
  }
  return api.patch(`/products/${props.product.id}`, body);
}
</script>

<template>
  <FormDrawer :key="lk ? 'ready' : 'loading'" :open="open && !!product" :title="{ ar: `تعديل المنتج — ${product?.sku || ''}`, en: `Edit product — ${product?.sku || ''}` }" :sub="product?.nameAr" :fields="fields" :initial="initial"
              :submit="submit" :action="{ success: (d) => serverMsg(d, { ar: 'حُفظ التعديل', en: 'Saved' }), invalidate: ['products', 'inventory'] }"
              :note="{ ar: '* حقول إلزامية · كل تغيير يُسجل حقلًا بحقل في Audit Trail مع السبب', en: '* required · every change is audited field by field with the reason' }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
