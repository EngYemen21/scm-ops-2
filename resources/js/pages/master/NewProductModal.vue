<script setup>
// New product modal — SKU, names, brand, price, barcode, UoM, dimensions / weight (all > 0), reorder point,
// storage pills and category pills.  <NewProductModal :open="open" @close="open = false" @done="(product) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, PillChoice, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { storageOpts, uomOpts, useLookups } from './_shared';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'done']);

const lookups = useLookups();
const act = useAction();
const blank = () => ({ storageClass: 'ambient', categoryCode: '' });
const v = ref(blank());
const errs = ref({});

function set(k, x) {
  v.value = { ...v.value, [k]: x };
  if (errs.value[k]) { const n = { ...errs.value }; delete n[k]; errs.value = n; }
}
watch(() => props.open, (open) => { if (open) { v.value = blank(); errs.value = {}; act.clearError(); } });

const uoms = computed(() => uomOpts(lookups.data.value));
const categoryOpts = computed(() => (lookups.data.value?.categories || []).filter((c) => c.active).map((c) => ({ v: c.code, l: c.pathAr })));

async function save() {
  const e = {};
  const s = v.value;
  const req = t('هذا الحقل إلزامي', 'Required');
  if (!s.sku?.trim()) e.sku = req;
  if (!s.nameAr?.trim()) e.nameAr = req;
  for (const k of ['weightKg', 'lengthCm', 'widthCm', 'heightCm']) if (!(Number(s[k]) > 0)) e[k] = t('يجب أن يكون أكبر من صفر', 'Must be > 0');
  errs.value = e;
  if (Object.keys(e).length) return;
  const body = {
    sku: s.sku.trim().toUpperCase(), nameAr: s.nameAr.trim(), nameEn: s.nameEn?.trim() || s.nameAr.trim(), brand: s.brand || undefined, purchasePrice: s.purchasePrice ?? undefined,
    storageClass: s.storageClass, categoryCode: s.categoryCode || undefined, uomCode: s.uomCode || undefined, weightKg: s.weightKg, lengthCm: s.lengthCm, widthCm: s.widthCm, heightCm: s.heightCm,
    barcode: s.barcode || undefined, reorderMin: s.reorderMin ?? 0,
  };
  const r = await act.run(() => api.postIdempotent('/products', body), { success: (d) => t(`أُضيف ${d.nameAr} إلى Master بالرمز ${d.sku}`, `${d.nameEn} added as ${d.sku}`), invalidate: ['products', 'master', 'categories'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Modal :open="open" :title="{ ar: 'إضافة منتج إلى Master', en: 'Add product to Master' }" :sub="{ ar: 'SKU فريد · الأبعاد والوزن تغذي اقتراح المواقع وتخطيط الحمولة', en: 'Unique SKU · dimensions and weight drive putaway and load planning' }" @close="emit('close')">
    <div class="form-grid">
      <TextInput :model-value="v.nameAr" :label="{ ar: 'اسم المنتج (عربي)', en: 'Product name (Arabic)' }" required full :placeholder="{ ar: 'مثال: زيت زيتون بكر 1 لتر', en: 'e.g. Extra virgin olive oil 1L' }" :error="errs.nameAr" autofocus @update:model-value="set('nameAr', $event)" />
      <TextInput :model-value="v.sku" :label="{ ar: 'SKU', en: 'SKU' }" required dir="ltr" mono placeholder="P01xxx" :error="errs.sku" @update:model-value="set('sku', $event)" />
      <TextInput :model-value="v.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" @update:model-value="set('nameEn', $event)" />
      <TextInput :model-value="v.brand" :label="{ ar: 'العلامة التجارية', en: 'Brand' }" @update:model-value="set('brand', $event)" />
      <NumberInput :model-value="v.purchasePrice" :label="{ ar: 'سعر الشراء ر.س', en: 'Purchase price SAR' }" :min="0" @update:model-value="set('purchasePrice', $event)" />
      <TextInput :model-value="v.barcode" :label="{ ar: 'الباركود', en: 'Barcode' }" dir="ltr" mono @update:model-value="set('barcode', $event)" />
      <SelectInput :model-value="v.uomCode" :label="{ ar: 'الوحدة', en: 'UoM' }" :options="uoms" :placeholder="{ ar: '—', en: '—' }" @update:model-value="set('uomCode', $event)" />
      <NumberInput :model-value="v.lengthCm" :label="{ ar: 'الطول سم', en: 'Length cm' }" :min="0" :error="errs.lengthCm" @update:model-value="set('lengthCm', $event)" />
      <NumberInput :model-value="v.widthCm" :label="{ ar: 'العرض سم', en: 'Width cm' }" :min="0" :error="errs.widthCm" @update:model-value="set('widthCm', $event)" />
      <NumberInput :model-value="v.heightCm" :label="{ ar: 'الارتفاع سم', en: 'Height cm' }" :min="0" :error="errs.heightCm" @update:model-value="set('heightCm', $event)" />
      <NumberInput :model-value="v.weightKg" :label="{ ar: 'الوزن كجم', en: 'Weight kg' }" :min="0" :error="errs.weightKg" @update:model-value="set('weightKg', $event)" />
      <NumberInput :model-value="v.reorderMin" :label="{ ar: 'حد إعادة الطلب', en: 'Reorder point' }" :min="0" @update:model-value="set('reorderMin', $event)" />
      <PillChoice :model-value="v.storageClass" :label="{ ar: 'شرط التخزين', en: 'Storage' }" full purple :options="storageOpts" @update:model-value="set('storageClass', $event)" />
      <PillChoice :model-value="v.categoryCode" :label="{ ar: 'التصنيف', en: 'Category' }" full :options="categoryOpts" @update:model-value="set('categoryCode', $event === v.categoryCode ? '' : $event)" />
    </div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-[9px]">
        <Btn tone="dark" size="lg" class="!h-[46px] flex-1" :loading="act.pending.value" :label="{ ar: 'حفظ في Master', en: 'Save to Master' }" @click="save" />
        <Btn tone="soft" size="lg" class="!h-[46px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
