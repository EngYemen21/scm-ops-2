<script setup>
// Add a driver (POST /transport/drivers) or — with `driver` — edit him (PATCH /transport/drivers/:code).
// A new driver's license must be valid for at least 30 days; a username (+ password of 8+ characters) optionally
// creates the driver's login for the "My Trips" app. Editing sends only what changed; section 'docs' = "renew
// documents": the three expiry dates only.
// What the documents do (the server decides — fleet.driverLicenseMinDays): a license with fewer than 30 days left and
// an expired iqama / ID stop assignment (the driver is blocked, and unblocked again when the document is renewed);
// the medical certificate only raises a warning.
// Hourly cost (optional): the driver's time in the trip cost sheet; empty = the fleet policy rate.
//   <DriverForm :open="form === 'driver'" @close="form = null" />     <DriverForm :open="!!edit" :driver="d" :section="edit" @close="edit = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { daysTo, t } from '@/i18n';
import { isoDay, numOrEmpty, patchBody } from './fleet';
import { SHIFT_LABELS, optsOf } from './tms';
import { useVehicleOpts } from './tmsComposables';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The driver as GET /transport/drivers/:code returns it; null = add a driver. */
  driver: { type: Object, default: null },
  /** 'all' | 'docs' (edit mode only). */
  section: { type: String, default: 'all' },
});
const emit = defineEmits(['close']);

const editing = computed(() => !!props.driver);
const vOpts = useVehicleOpts(() => props.open);
const licenseHint = { ar: 'يتوقف الإسناد إذا بقي أقل من الحد الأدنى في سياسة الأسطول (30 يومًا افتراضيًا)', en: 'Assignment stops under the fleet policy minimum (30 days by default)' };
const DOCS = computed(() => [
  { k: 'licenseExpiry', label: { ar: 'انتهاء الرخصة', en: 'License expiry' }, type: 'date', required: true, hint: licenseHint,
    validate: (v) => (!editing.value && v && daysTo(String(v)) < 30 ? t('الرخصة يجب أن تكون سارية 30 يومًا على الأقل', 'License must be valid for at least 30 days') : null) },
  { k: 'iqamaExpiry', label: { ar: 'انتهاء الإقامة / الهوية', en: 'Iqama / ID expiry' }, type: 'date', required: true, hint: { ar: 'انتهاؤها يوقف الإسناد', en: 'Its expiry stops assignment' } },
  { k: 'medicalExpiry', label: { ar: 'الشهادة الصحية (انتهاء)', en: 'Medical certificate expiry' }, type: 'date', hint: { ar: 'تنبيه فقط — لا توقف الإسناد', en: 'Warning only — does not stop assignment' } },
]);
const fields = computed(() => {
  if (editing.value && props.section === 'docs') return DOCS.value;
  return [
    { k: 'code', label: { ar: 'رقم السائق', en: 'Driver code' }, required: true, dir: 'ltr', disabled: editing.value }, { k: 'nameAr', label: { ar: 'الاسم الكامل (عربي)', en: 'Full name (Arabic)' }, required: true }, { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, dir: 'ltr' },
    { k: 'employeeNo', label: { ar: 'الرقم الوظيفي', en: 'Employee no.' }, required: true, dir: 'ltr' }, { k: 'mobile', label: { ar: 'الجوال', en: 'Mobile' }, required: true, dir: 'ltr' }, { k: 'nationality', label: { ar: 'الجنسية', en: 'Nationality' } },
    { k: 'licenseNo', label: { ar: 'رقم الرخصة', en: 'License no.' }, required: true, dir: 'ltr' }, { k: 'licenseType', label: { ar: 'نوع الرخصة', en: 'License type' } },
    ...DOCS.value,
    { k: 'shift', label: { ar: 'الوردية', en: 'Shift' }, type: 'select', def: 'am', opts: optsOf(SHIFT_LABELS) },
    { k: 'defaultVehicleCode', label: { ar: 'المركبة الافتراضية', en: 'Default vehicle' }, type: 'select', ph: { ar: '— بدون —', en: '— none —' }, opts: vOpts.value },
    { k: 'joinDate', label: { ar: 'تاريخ الالتحاق', en: 'Join date' }, type: 'date' },
    { k: 'hourlyCost', label: { ar: 'تكلفة الساعة ر.س', en: 'Hourly cost SAR' }, type: 'num', min: 0, hint: { ar: 'اختياري — لتكلفة الرحلة؛ الفارغ = سياسة الأسطول', en: 'Optional — for trip costing; empty = fleet policy' } },
    ...(editing.value ? [] : [
      { k: 'username', label: { ar: 'اسم مستخدم للدخول (اختياري)', en: 'Login username (optional)' }, dir: 'ltr', hint: { ar: 'ينشئ حساب سائق لتطبيق «رحلاتي»', en: 'Creates a driver login for My Trips' } },
      { k: 'password', label: { ar: 'كلمة المرور (8+ أحرف)', en: 'Password (8+ chars)' }, type: 'password', visible: (v) => !!v.username, validate: (v, all) => (all.username && (!v || String(v).length < 8) ? t('8 أحرف على الأقل', 'At least 8 characters') : null) },
    ]),
  ];
});

const KEYS = ['nameAr', 'nameEn', 'employeeNo', 'mobile', 'nationality', 'licenseNo', 'licenseType', 'licenseExpiry', 'iqamaExpiry', 'medicalExpiry', 'shift', 'defaultVehicleCode', 'joinDate', 'hourlyCost'];
const NULLABLE = ['nameEn', 'nationality', 'licenseType', 'medicalExpiry', 'defaultVehicleCode', 'hourlyCost'];
const SHIFT_KEY = { AM: 'am', PM: 'pm', Flex: 'flex' };
const initial = computed(() => {
  const x = props.driver;
  if (!x) return null;
  return {
    code: x.code, nameAr: x.nameAr, nameEn: x.nameEn || undefined, employeeNo: x.employeeNo, mobile: x.mobile, nationality: x.nationality || undefined, licenseNo: x.licenseNo, licenseType: x.licenseType || undefined,
    licenseExpiry: isoDay(x.licenseExpiry), iqamaExpiry: isoDay(x.iqamaExpiry), medicalExpiry: isoDay(x.medicalExpiry) || undefined, shift: SHIFT_KEY[x.shiftEn] || undefined,
    defaultVehicleCode: x.defaultVehicle?.code || undefined, joinDate: isoDay(x.joinDate) || undefined, hourlyCost: numOrEmpty(x.hourlyCost),
  };
});
const title = computed(() => {
  if (!editing.value) return { ar: 'إضافة سائق', en: 'Add driver' };
  return props.section === 'docs' ? { ar: `تجديد وثائق ${props.driver.nameAr}`, en: `Renew documents of ${props.driver.nameEn || props.driver.code}` } : { ar: `تعديل السائق ${props.driver.nameAr}`, en: `Edit driver ${props.driver.nameEn || props.driver.code}` };
});
const sub = computed(() => (editing.value && props.section === 'docs' ? { ar: 'الرخصة التي بقي لها أقل من الحد الأدنى (سياسة الأسطول، 30 يومًا افتراضيًا) والإقامة المنتهية توقفان الإسناد، ويُرفع الإيقاف تلقائيًا عند التجديد — الشهادة الصحية تنبيه فقط', en: 'A license under the fleet policy minimum (30 days by default) and an expired iqama stop assignment, and the block lifts when they are renewed — the medical certificate only warns' } : null));
function submit(v) {
  if (!editing.value) return api.postIdempotent('/transport/drivers', v);
  const shown = fields.value.map((f) => f.k); // "renew documents" shows three fields: the others are not touched (nor nulled)
  return api.patch(`/transport/drivers/${encodeURIComponent(props.driver.code)}`, patchBody(v, initial.value, KEYS.filter((k) => shown.includes(k)), NULLABLE));
}
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: (r) => r?.message || (editing ? t('حُفظت بيانات السائق', 'Driver saved') : t('أُضيف السائق', 'Driver added')), invalidate: ['transport'] }" @close="emit('close')" />
</template>
