<script setup>
// Enter an onboarding application for a company, or correct the particulars of one that is still being reviewed.
//   <ApplicationForm :open="open" :application="detailOrNull" @close="…" @done="(application) => …" />
// POST /portal/applications (a draft; it is sent for review from the application itself) · PATCH /portal/applications/{id}
// (in an edit an emptied field is sent as null: it is cleared). The two numbers a company is recognised by have a fixed
// length — the registration 10 digits, the VAT number 15 — and are checked here before the server checks them again.
import { computed } from 'vue';
import { api } from '@/api/client';
import { Btn, FormDrawer, SelectInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';
import { CONTACT_ROLE_OPTIONS, LEGAL_TYPE_OPTIONS } from './portal';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The application as GET /portal/applications/{id} gives it; null = a new one. */
  application: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const edit = computed(() => !!props.application);
/** The owner's identity number arrived masked: this reader does not decide applications, so the field is left alone. */
const idHidden = computed(() => !!props.application?.ownerNationalIdMasked);
const digits = (v) => String(v || '').replace(/\D+/g, '');
const exact = (n, ar, en) => (v) => (v && digits(v).length !== n ? t(`${ar} يجب أن يكون ${n} رقمًا`, `The ${en} must be ${n} digits`) : null);

const fields = computed(() => [
  { k: 'companyNameAr', label: { ar: 'اسم المنشأة بالعربية', en: 'Company name (Arabic)' }, required: true, full: true },
  { k: 'companyNameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr' },
  { k: 'legalEntityType', label: { ar: 'الكيان القانوني', en: 'Legal entity' }, type: 'select', opts: LEGAL_TYPE_OPTIONS, ph: { ar: '—', en: '—' } },
  { k: 'cr', label: { ar: 'السجل التجاري (10 أرقام)', en: 'Commercial registration (10 digits)' }, dir: 'ltr', validate: exact(10, 'السجل التجاري', 'commercial registration') },
  { k: 'vatNo', label: { ar: 'الرقم الضريبي (15 رقمًا)', en: 'VAT number (15 digits)' }, dir: 'ltr', validate: exact(15, 'الرقم الضريبي', 'VAT number') },
  { k: 'unifiedNo', label: { ar: 'الرقم الوطني الموحد', en: 'Unified national number' }, dir: 'ltr' },
  { k: 'language', label: { ar: 'لغة المراسلة', en: 'Language' }, type: 'select', opts: [['ar', { ar: 'العربية', en: 'Arabic' }], ['en', { ar: 'الإنجليزية', en: 'English' }]], def: 'ar' },
  { k: 'ownerName', label: { ar: 'اسم المالك', en: 'Owner’s name' } },
  ...(idHidden.value ? [] : [{ k: 'ownerNationalId', label: { ar: 'رقم هوية المالك', en: 'Owner’s identity number' }, dir: 'ltr', hint: { ar: 'يظهر كاملًا لمن يبتّ في الطلبات فقط ولا يُكتب في أي سجل', en: 'Shown in full only to who decides applications; never written to a log' } }]),
  { k: 'managerName', label: { ar: 'المدير المسؤول', en: 'Manager' } },
  { k: 'mobile', label: { ar: 'الجوال', en: 'Mobile' }, dir: 'ltr' },
  { k: 'email', label: { ar: 'البريد الإلكتروني', en: 'E-mail' }, dir: 'ltr', validate: (v) => (v && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? t('بريد غير صالح', 'Not a valid e-mail') : null) },
  { k: 'city', label: { ar: 'المدينة', en: 'City' } },
  { k: 'district', label: { ar: 'الحي', en: 'District' } },
  { k: 'street', label: { ar: 'الشارع', en: 'Street' } },
  { k: 'buildingNo', label: { ar: 'رقم المبنى', en: 'Building no.' }, dir: 'ltr' },
  { k: 'additionalNo', label: { ar: 'الرقم الإضافي', en: 'Additional no.' }, dir: 'ltr' },
  { k: 'postalCode', label: { ar: 'الرمز البريدي', en: 'Postal code' }, dir: 'ltr' },
  { k: 'region', label: { ar: 'المنطقة', en: 'Region' } },
  { k: 'termsAccepted', label: { ar: 'الموافقة على الشروط', en: 'Terms accepted' }, type: 'select', opts: [['no', { ar: 'لم يوافق بعد', en: 'Not yet' }], ['yes', { ar: 'وافق العميل على الشروط', en: 'The customer accepted the terms' }]], def: 'no' },
  { k: 'contacts', label: { ar: 'مسؤولو المنشأة', en: 'The company’s people' }, full: true },
]);

const TEXT_KEYS = ['companyNameEn', 'legalEntityType', 'cr', 'vatNo', 'unifiedNo', 'ownerName', 'ownerNationalId', 'managerName', 'mobile', 'email', 'city', 'district', 'street', 'buildingNo', 'additionalNo', 'postalCode', 'region'];
const initial = computed(() => {
  const a = props.application;
  if (!a) return { contacts: [] };
  const out = { companyNameAr: a.companyNameAr || '', language: a.language || 'ar', termsAccepted: a.termsAccepted ? 'yes' : 'no', contacts: (a.contacts || []).map((c) => ({ ...c })) };
  for (const k of TEXT_KEYS) out[k] = a[k] || '';
  return out;
});
const title = computed(() => (edit.value ? { ar: `تعديل الطلب ${props.application.number}`, en: `Edit application ${props.application.number}` } : { ar: 'طلب فتح حساب جديد', en: 'New onboarding application' }));

function submit(values) {
  const contacts = (values.contacts || []).filter((c) => String(c.name || '').trim()).map((c) => ({ role: c.role || 'other', name: c.name.trim(), ...(c.mobile ? { mobile: c.mobile } : {}), ...(c.email ? { email: c.email } : {}) }));
  const body = { companyNameAr: values.companyNameAr, language: values.language || 'ar', termsAccepted: values.termsAccepted === 'yes', contacts };
  for (const k of TEXT_KEYS) {
    if (k === 'ownerNationalId' && idHidden.value) continue;
    const v = values[k];
    // a new application simply leaves out what is empty; an edit sends null for a field that was emptied, so it is cleared
    if (v !== undefined && v !== '') body[k] = v; else if (edit.value) body[k] = null;
  }

  return edit.value ? api.patch(`/portal/applications/${props.application.id}`, body) : api.postIdempotent('/portal/applications', body);
}
const action = { success: (a) => (edit.value ? t('حُفظ الطلب ✓', 'Application saved ✓') : t(`سُجّل الطلب ${a.number} مسودةً`, `Application ${a.number} entered as a draft`)), invalidate: ['portal'] };

const blank = () => ({ role: 'purchaser', name: '', mobile: '', email: '' });
/** On a phone the header row of the people editor is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="edit ? null : { ar: 'يُحفظ مسودة، ثم يُقدَّم للمراجعة من صفحة الطلب بعد إرفاق المستندات', en: 'Saved as a draft; it is sent for review from the application once its papers are attached' }"
              :fields="fields" :initial="initial" :submit="submit" :action="action" :width="620" @close="emit('close')" @done="(a) => emit('done', a)">
    <template #field-contacts="{ value, set }">
      <label class="field-l">{{ t('مسؤولو المنشأة (المشتريات، الاستلام، المحاسبة)', 'The company’s people (purchasing, receiving, accounts)') }}</label>
      <div class="col !gap-1.5">
        <div v-if="(value || []).length" class="le-head grid grid-cols-[112px_minmax(110px,1.2fr)_110px_minmax(110px,1fr)_28px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint">
          <div>{{ t('الصفة', 'Role') }}</div><div>{{ t('الاسم', 'Name') }}</div><div>{{ t('الجوال', 'Mobile') }}</div><div>{{ t('البريد', 'E-mail') }}</div><div />
        </div>
        <div v-for="(c, i) in value || []" :key="i" class="le-row grid grid-cols-[112px_minmax(110px,1.2fr)_110px_minmax(110px,1fr)_28px] items-start gap-2 px-2.5 py-1">
          <SelectInput small :label="cap('الصفة', 'Role')" :model-value="c.role || 'other'" :options="CONTACT_ROLE_OPTIONS" @update:model-value="(v) => set(value.map((x, j) => (j === i ? { ...x, role: v } : x)))" />
          <TextInput small :label="cap('الاسم', 'Name')" :model-value="c.name" @update:model-value="(v) => set(value.map((x, j) => (j === i ? { ...x, name: v } : x)))" />
          <TextInput small dir="ltr" :label="cap('الجوال', 'Mobile')" :model-value="c.mobile || ''" @update:model-value="(v) => set(value.map((x, j) => (j === i ? { ...x, mobile: v } : x)))" />
          <TextInput small dir="ltr" :label="cap('البريد', 'E-mail')" :model-value="c.email || ''" @update:model-value="(v) => set(value.map((x, j) => (j === i ? { ...x, email: v } : x)))" />
          <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف', 'Remove')" @click="set(value.filter((_, j) => j !== i))">✕</button>
        </div>
        <div><Btn tone="softPurple" size="sm" :label="{ ar: '+ مسؤول', en: '+ Person' }" @click="set([...(value || []), blank()])" /></div>
      </div>
    </template>
  </FormDrawer>
</template>
