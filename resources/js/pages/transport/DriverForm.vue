<script setup>
// Add driver (POST /transport/drivers). The license must be valid for at least 30 days; a username (+ password of 8+
// characters) optionally creates the driver's login for the "My Trips" app.
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { daysTo, t } from '@/i18n';
import { SHIFT_LABELS, optsOf } from './tms';
import { useVehicleOpts } from './tmsComposables';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const vOpts = useVehicleOpts(() => props.open);
const fields = computed(() => [
  { k: 'code', label: { ar: 'رقم السائق', en: 'Driver code' }, required: true, dir: 'ltr' }, { k: 'nameAr', label: { ar: 'الاسم الكامل (عربي)', en: 'Full name (Arabic)' }, required: true }, { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, dir: 'ltr' },
  { k: 'employeeNo', label: { ar: 'الرقم الوظيفي', en: 'Employee no.' }, required: true, dir: 'ltr' }, { k: 'mobile', label: { ar: 'الجوال', en: 'Mobile' }, required: true, dir: 'ltr' }, { k: 'nationality', label: { ar: 'الجنسية', en: 'Nationality' } },
  { k: 'licenseNo', label: { ar: 'رقم الرخصة', en: 'License no.' }, required: true, dir: 'ltr' }, { k: 'licenseType', label: { ar: 'نوع الرخصة', en: 'License type' } },
  { k: 'licenseExpiry', label: { ar: 'انتهاء الرخصة', en: 'License expiry' }, type: 'date', required: true, validate: (v) => (v && daysTo(String(v)) < 30 ? t('الرخصة يجب أن تكون سارية 30 يومًا على الأقل', 'License must be valid for at least 30 days') : null) },
  { k: 'iqamaExpiry', label: { ar: 'انتهاء الإقامة / الهوية', en: 'Iqama / ID expiry' }, type: 'date', required: true }, { k: 'medicalExpiry', label: { ar: 'الشهادة الصحية (انتهاء)', en: 'Medical certificate expiry' }, type: 'date' },
  { k: 'shift', label: { ar: 'الوردية', en: 'Shift' }, type: 'select', def: 'am', opts: optsOf(SHIFT_LABELS) },
  { k: 'defaultVehicleCode', label: { ar: 'المركبة الافتراضية', en: 'Default vehicle' }, type: 'select', ph: { ar: '— بدون —', en: '— none —' }, opts: vOpts.value },
  { k: 'joinDate', label: { ar: 'تاريخ الالتحاق', en: 'Join date' }, type: 'date' },
  { k: 'username', label: { ar: 'اسم مستخدم للدخول (اختياري)', en: 'Login username (optional)' }, dir: 'ltr', hint: { ar: 'ينشئ حساب سائق لتطبيق «رحلاتي»', en: 'Creates a driver login for My Trips' } },
  { k: 'password', label: { ar: 'كلمة المرور (8+ أحرف)', en: 'Password (8+ chars)' }, type: 'password', visible: (v) => !!v.username, validate: (v, all) => (all.username && (!v || String(v).length < 8) ? t('8 أحرف على الأقل', 'At least 8 characters') : null) },
]);
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'إضافة سائق', en: 'Add driver' }" :fields="fields" :submit="(v) => api.postIdempotent('/transport/drivers', v)"
              :action="{ success: (r) => r?.message || t('أُضيف السائق', 'Driver added'), invalidate: ['transport'] }" @close="emit('close')" />
</template>
