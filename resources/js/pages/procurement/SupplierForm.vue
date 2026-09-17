<script setup>
// Supplier create / edit — CR must be 10 digits, VAT number 15 digits. POST /suppliers or PATCH /suppliers/:id.
//   <SupplierForm :open :supplier="supplierOrNull" @close @done="(supplier) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import { DASH_PLACEHOLDER, SUPPLIER_CATEGORIES, SUPPLIER_CATEGORY_OPTIONS, TERMS_OPTIONS } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Supplier to edit; null = create. */
  supplier: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const blank = () => ({ nameAr: '', nameEn: '', category: '', cr: '', vat: '', contact: '', email: '', leadDays: 5, terms: 'آجل 30 يوم', iban: '', minOrder: 0, notes: '' });
const f = ref(blank());
watch([() => props.open, () => props.supplier], () => {
  if (!props.open) return;
  const s = props.supplier;
  f.value = s ? {
    nameAr: s.nameAr, nameEn: s.nameEn || '',
    category: Object.keys(SUPPLIER_CATEGORIES).find((k) => SUPPLIER_CATEGORIES[k][0] === s.category || SUPPLIER_CATEGORIES[k][1] === s.categoryEn) || '',
    cr: s.cr || '', vat: s.vat || '', contact: s.contact || '', email: s.email || '', leadDays: s.leadDays, terms: s.terms || '', iban: s.iban || '', minOrder: s.minOrder != null ? Number(s.minOrder) : 0, notes: s.notes || '',
  } : blank();
  act.clearError();
}, { immediate: true });

const digits = (v) => v.replace(/\s/g, '');
const crOk = computed(() => /^\d{10}$/.test(digits(f.value.cr)));
const vatOk = computed(() => /^\d{15}$/.test(digits(f.value.vat)));
const ok = computed(() => f.value.nameAr.trim().length > 0 && crOk.value && vatOk.value && f.value.contact.trim().length > 0);

async function submit() {
  const v = f.value;
  const s = props.supplier;
  const body = { nameAr: v.nameAr, nameEn: v.nameEn || undefined, category: v.category || undefined, cr: digits(v.cr), vat: digits(v.vat), contact: v.contact, email: v.email || undefined, leadDays: Number(v.leadDays) || 0, terms: v.terms || undefined, iban: v.iban || undefined, minOrder: Number(v.minOrder) || 0, notes: v.notes || undefined };
  const r = await act.run(() => (s ? api.patch(`/suppliers/${s.id}`, body) : api.postIdempotent('/suppliers', body)), {
    success: (x) => (s ? t(`حُدّث المورد ${x.nameAr}`, `Supplier ${x.nameEn} updated`) : t(`أُضيف المورد ${x.nameAr} (${x.code})`, `Supplier ${x.nameEn} added (${x.code})`)),
    invalidate: ['suppliers', 'procurement'],
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :title="supplier ? { ar: `تعديل المورد — ${supplier.nameAr}`, en: `Edit supplier — ${supplier.nameEn}` } : { ar: 'إضافة مورد', en: 'Add supplier' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="supplier ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إضافة المورد', en: 'Add supplier' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.nameAr" :label="{ ar: 'اسم المورد (عربي)', en: 'Supplier name (Arabic)' }" required />
      <TextInput v-model="f.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" />
      <TextInput v-model="f.cr" :label="{ ar: 'السجل التجاري (10 أرقام)', en: 'CR (10 digits)' }" required dir="ltr" inputmode="numeric" maxlength="12" :error="f.cr && !crOk ? t('السجل التجاري يجب أن يكون 10 أرقام', 'CR must be 10 digits') : null" />
      <TextInput v-model="f.vat" :label="{ ar: 'الرقم الضريبي (15 رقمًا)', en: 'VAT number (15 digits)' }" required dir="ltr" inputmode="numeric" maxlength="17" :error="f.vat && !vatOk ? t('الرقم الضريبي يجب أن يكون 15 رقمًا', 'VAT number must be 15 digits') : null" />
      <SelectInput v-model="f.category" :label="{ ar: 'الفئة', en: 'Category' }" :options="SUPPLIER_CATEGORY_OPTIONS" :placeholder="DASH_PLACEHOLDER" />
      <TextInput v-model="f.contact" :label="{ ar: 'مسؤول التواصل / الجوال', en: 'Contact / mobile' }" required />
      <TextInput v-model="f.email" :label="{ ar: 'البريد الإلكتروني', en: 'Email' }" type="email" dir="ltr" />
      <NumberInput v-model="f.leadDays" :label="{ ar: 'مهلة التوريد (أيام)', en: 'Lead time (days)' }" required :min="0" />
      <SelectInput v-model="f.terms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS_OPTIONS" :placeholder="DASH_PLACEHOLDER" />
      <TextInput v-model="f.iban" label="IBAN" dir="ltr" mono />
      <NumberInput v-model="f.minOrder" :label="{ ar: 'حد أدنى للطلب ر.س', en: 'Minimum order SAR' }" :min="0" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" :full="false" />
    </div>
  </ActionDrawer>
</template>
