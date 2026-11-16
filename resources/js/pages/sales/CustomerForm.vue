<script setup>
// Customer add / edit form. Rules: a credit-terms customer needs a credit limit greater than zero; credit terms are not
// tightened under what the customer owes (the server refuses cash terms / a zero limit / a lower limit while the exposure
// is above it — CUSTOMER_HAS_BALANCE, CUSTOMER_LIMIT_BELOW_BALANCE). What a limit of 0 means is a policy
// (`sales.zeroCreditLimitUnlimited`), so the field says it as the server applies it.
//   <CustomerForm :open="open" :customer="customerOrNull" @close="…" @done="(customer) => …" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer, LocationField } from '@/components';
import { num, t } from '@/i18n';
import { CASH_TERMS, CITIES, PRICE_LISTS, TERMS, usePolicy } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Customer record to edit; null = add. */
  customer: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const edit = computed(() => !!props.customer);
const policy = usePolicy();
/** What a credit limit of 0 means right now: `{ ar, en }` (generic until the policy has loaded). */
const zeroMeans = computed(() => {
  if (!policy.value) return { ar: '0 = بلا حد ائتماني', en: '0 = no credit limit' };
  return policy.value.zeroCreditLimitUnlimited
    ? { ar: '0 = غير مقيَّد: لا يُفحص الائتمان', en: '0 = not limited: no credit check' }
    : { ar: '0 = بلا ائتمان: يُرفض الطلب ما دام عليه مبلغ غير مسدد', en: '0 = no credit: orders refused while anything is unpaid' };
});
const fields = computed(() => [
  { k: 'nameAr', label: { ar: 'اسم العميل', en: 'Customer name (AR)' }, required: true },
  { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }, dir: 'ltr' },
  { k: 'city', label: { ar: 'المدينة', en: 'City' }, type: 'select', opts: CITIES },
  { k: 'zone', label: { ar: 'المنطقة / الحي', en: 'Zone / district' }, required: true },
  { k: 'contact', label: { ar: 'جهة الاتصال · الجوال', en: 'Contact · mobile' }, required: true },
  { k: 'cr', label: { ar: 'السجل التجاري', en: 'CR number' }, required: !edit.value, dir: 'ltr' },
  { k: 'vat', label: { ar: 'الرقم الضريبي', en: 'VAT number' }, dir: 'ltr' },
  { k: 'terms', label: { ar: 'شروط الدفع', en: 'Payment terms' }, type: 'select', opts: TERMS, def: CASH_TERMS },
  {
    k: 'creditLimit', label: { ar: `الحد الائتماني ر.س (${zeroMeans.value.ar})`, en: `Credit limit SAR (${zeroMeans.value.en})` }, type: 'num', min: 0, def: 0,
    validate: (value, all) => ((all.terms || CASH_TERMS) !== CASH_TERMS && !(num(value) > 0) ? t('العميل الآجل يحتاج حدًا ائتمانيًا أكبر من صفر', 'Credit-terms customers need a credit limit greater than zero') : null),
  },
  { k: 'priceList', label: { ar: 'قائمة الأسعار', en: 'Price list' }, type: 'select', opts: PRICE_LISTS },
  { k: 'address', label: { ar: 'العنوان', en: 'Address' }, type: 'area' },
  { k: 'location', label: { ar: 'الموقع على الخريطة', en: 'Map location' }, full: true },
]);
const initial = computed(() => {
  const c = props.customer;
  return c ? { nameAr: c.nameAr, nameEn: c.nameEn, city: c.city || '', zone: c.zone || '', contact: c.contact || '', terms: c.terms || CASH_TERMS, creditLimit: num(c.creditLimit), priceList: c.priceList || '', address: c.address || '', location: c.lat != null && c.lng != null ? { lat: c.lat, lng: c.lng } : null } : null;
});
const title = computed(() => (edit.value ? { ar: `تعديل العميل ${props.customer.code}`, en: `Edit customer ${props.customer.code}` } : { ar: 'إضافة عميل', en: 'Add customer' }));

function submit(values) {
  // the map pin travels as lat / lng; on edit an emptied pin is sent as nulls so it is really cleared
  const { location, ...rest } = values;
  const body = { ...rest, creditLimit: num(values.creditLimit), ...(location ? { lat: location.lat, lng: location.lng } : edit.value ? { lat: null, lng: null } : {}) };
  return edit.value ? api.patch(`/customers/${props.customer.id}`, body) : api.postIdempotent('/customers', body);
}
const action = { success: (c) => c?.messageAr || t('تم حفظ العميل', 'Customer saved'), invalidate: ['customers', 'master'] };
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="{ ar: `العميل الآجل يحتاج حدًا ائتمانيًا أكبر من صفر · ${zeroMeans.ar} · لا يُشدَّد الائتمان تحت ما على العميل من التزام`, en: `Credit-terms customers need a credit limit > 0 · ${zeroMeans.en} · credit is not tightened under what the customer owes` }"
              :fields="fields" :initial="initial" :submit="submit" :action="action" @close="emit('close')" @done="(c) => emit('done', c)">
    <template #field-location="{ value, set, values }">
      <LocationField :model-value="value || null" :seed="[values.zone, values.city].filter(Boolean).join(' ')" :hint="{ ar: 'يُستخدم لرسم مسار الرحلة وحساب المسافة وترتيب المحطات', en: 'Used for the trip route, distance and stop ordering' }" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
