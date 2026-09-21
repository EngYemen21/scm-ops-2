<script setup>
// Create warehouse: 3-letter code, names, city, type, area, docks, temperature zones, hours, open date.
//   <WarehouseForm :open="form === 'wh'" @close="form = null" @done="(warehouse) => setSel(warehouse.code)" />
import { api } from '@/api/client';
import { FormDrawer, LocationField } from '@/components';
import { t } from '@/i18n';
import { serverMsg } from './_shared';
import { INV, TEMP_LABELS, WH_TYPE_LABELS, opts } from './_whForms';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'done']);

const fields = [
  { k: 'code', label: { ar: 'الرمز (3 أحرف)', en: 'Code (3 letters)' }, required: true, dir: 'ltr', ph: 'RYD', validate: (x) => (/^[A-Za-z]{3}$/.test(String(x || '')) ? null : t('الرمز 3 أحرف لاتينية', 'Code must be 3 Latin letters')) },
  { k: 'city', label: { ar: 'المدينة', en: 'City' }, required: true },
  { k: 'nameAr', label: { ar: 'اسم المستودع (عربي)', en: 'Name (Arabic)' }, required: true }, { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, dir: 'ltr' },
  { k: 'type', label: { ar: 'النوع', en: 'Type' }, type: 'select', opts: opts(WH_TYPE_LABELS), def: 'dc' }, { k: 'areaM2', label: { ar: 'المساحة م²', en: 'Area m²' }, type: 'num', min: 1, required: true, def: 2500 },
  { k: 'docks', label: { ar: 'عدد أرصفة التحميل', en: 'Loading docks' }, type: 'num', min: 0, def: 4 }, { k: 'tempZones', label: { ar: 'مناطق الحرارة المتاحة', en: 'Temperature zones' }, type: 'select', opts: opts(TEMP_LABELS), def: 'all' },
  { k: 'hours', label: { ar: 'ساعات التشغيل', en: 'Operating hours' }, ph: '06:00 – 22:00', dir: 'ltr' }, { k: 'openDate', label: { ar: 'تاريخ التشغيل', en: 'Open date' }, type: 'date' },
  { k: 'location', label: { ar: 'الموقع على الخريطة', en: 'Map location' }, full: true },
];
const submit = ({ location, ...v }) => api.postIdempotent('/warehouses', { ...v, code: String(v.code).toUpperCase(), ...(location ? { lat: location.lat, lng: location.lng } : {}) });
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'إنشاء مستودع', en: 'Create warehouse' }" :sub="{ ar: 'الرمز فريد · بعد الإنشاء أضف المناطق والمواقع ثم عيّن الفريق', en: 'Unique code · then add zones, bins and staff' }" :fields="fields" :submit="submit"
              :action="{ success: (d) => serverMsg(d, { ar: 'أُنشئ المستودع', en: 'Warehouse created' }), invalidate: INV }" @close="emit('close')" @done="emit('done', $event)">
    <template #field-location="{ value, set, values }">
      <LocationField :model-value="value || null" :seed="values.city || ''" :hint="{ ar: 'نقطة انطلاق الرحلات وعودتها — تُستخدم لرسم المسار وحساب المسافة', en: 'Where trips start and return — used for routes and distances' }" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
