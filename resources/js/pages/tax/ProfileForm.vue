<script setup>
// The taxpayer — GET /tax/profile (+ `missing`: what the seller's data lacks for a valid e-invoice) and
// PATCH /tax/profile (only what changed). Kept by vatreturn.manage OR einvoice.manage; read-only otherwise.
import { computed, reactive, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, ErrorBanner, PillChoice, SectionCard, SelectInput, TextInput } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { FREQUENCY, SELECT, changedOnly, optionsOf, textOrNull, useTaxLookups } from './tax';

const auth = useAuth();
const lk = useTaxLookups();
const q = useGet('/tax/profile');
const p = computed(() => q.data.value || null);
const canEdit = computed(() => auth.can(['vatreturn.manage', 'einvoice.manage']));
const act = useAction({ invalidate: ['tax'] });
const YES_NO = [{ v: 'yes', l: { ar: 'نعم', en: 'Yes' } }, { v: 'no', l: { ar: 'لا', en: 'No' } }];
const TEXT = ['legalNameAr', 'legalNameEn', 'vatNo', 'idScheme', 'idValue', 'street', 'buildingNo', 'additionalNo', 'district', 'city', 'postalCode', 'province', 'countryCode'];
const f = reactive({});
let before = {};
const fromProfile = (x) => ({ ...Object.fromEntries(TEXT.map((k) => [k, x?.[k] || ''])), filingFrequency: x?.filingFrequency || 'monthly', einvoiceAuto: x?.einvoiceAuto ? 'yes' : 'no', einvoiceFrom: x?.einvoiceFrom || '' });
const body = () => ({
  ...Object.fromEntries(TEXT.map((k) => [k, k === 'legalNameAr' ? String(f[k] || '').trim() : textOrNull(f[k])])),
  countryCode: textOrNull(String(f.countryCode || '').toUpperCase()), filingFrequency: f.filingFrequency, einvoiceAuto: f.einvoiceAuto === 'yes', einvoiceFrom: f.einvoiceFrom || null,
});
watch(p, (x) => { if (x) { Object.assign(f, fromProfile(x)); before = body(); } }, { immediate: true });
const missingOf = computed(() => Object.fromEntries((p.value?.missing || []).map((m) => [m.field, bi(m)])));
const dirty = computed(() => p.value && Object.keys(changedOnly(before, body())).length > 0);
const ok = computed(() => String(f.legalNameAr || '').trim() !== '' && (!f.countryCode || /^[A-Za-z]{2}$/.test(f.countryCode)));

async function save() {
  const b = changedOnly(before, body());
  if (!Object.keys(b).length) return;
  const r = await act.run(() => api.patch('/tax/profile', b), { success: t('حُفظت بيانات المكلّف', 'Taxpayer data saved') });
  if (r) { Object.assign(f, fromProfile(r)); before = body(); }
}
const reset = () => { if (p.value) Object.assign(f, fromProfile(p.value)); };
</script>

<template>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="!p && q.isLoading.value" class="skel h-[300px]" />
  <SectionCard v-else-if="p" :title="{ ar: 'بيانات المكلّف (البائع)', en: 'Taxpayer (seller) data' }" :sub="p.updatedAt ? `${p.updatedBy || '—'} · ${fmtDate(p.updatedAt)}` : null">
    <div v-if="p.missing?.length" class="hint amber !mt-0 mb-3">
      <div class="font-extrabold">{{ t('ينقص لإصدار فاتورة إلكترونية صحيحة:', 'Missing for a valid e-invoice:') }}</div>
      <ul class="ms-4 list-disc"><li v-for="m in p.missing" :key="m.field">{{ bi(m) }}</li></ul>
    </div>
    <div v-else class="hint green !mt-0 mb-3">{{ t('البيانات مكتملة لإصدار الفاتورة الإلكترونية', 'The data is complete for e-invoicing') }}</div>
    <div v-if="!canEdit" class="hint !mt-0 mb-3">{{ t('للعرض فقط — التعديل لمن يدير الإقرارات أو الفوترة الإلكترونية.', 'Read-only — changed by whoever manages VAT returns or e-invoicing.') }}</div>

    <div class="form-grid">
      <TextInput v-model="f.legalNameAr" :label="{ ar: 'الاسم النظامي (عربي)', en: 'Legal name (Arabic)' }" required dir="rtl" :error="missingOf.legalNameAr" :disabled="!canEdit" />
      <TextInput v-model="f.legalNameEn" :label="{ ar: 'الاسم النظامي (إنجليزي)', en: 'Legal name (English)' }" dir="ltr" :disabled="!canEdit" />
      <TextInput v-model="f.vatNo" :label="{ ar: 'الرقم الضريبي', en: 'VAT number' }" dir="ltr" mono :error="missingOf.vatNo" :hint="{ ar: '15 رقمًا يبدأ وينتهي بـ 3', en: '15 digits, first and last 3' }" :disabled="!canEdit" />
      <SelectInput v-model="f.idScheme" :label="{ ar: 'نوع المعرّف', en: 'Identification scheme' }" :options="lk.sellerIdSchemes.value" :placeholder="SELECT" :disabled="!canEdit" />
      <TextInput v-model="f.idValue" :label="{ ar: 'رقم المعرّف (السجل التجاري…)', en: 'Identification (CR…)' }" dir="ltr" mono :error="missingOf.idValue" :disabled="!canEdit" />
      <TextInput v-model="f.street" :label="{ ar: 'الشارع', en: 'Street' }" :error="missingOf.street" :disabled="!canEdit" />
      <TextInput v-model="f.buildingNo" :label="{ ar: 'رقم المبنى', en: 'Building number' }" dir="ltr" mono :error="missingOf.buildingNo" :disabled="!canEdit" />
      <TextInput v-model="f.additionalNo" :label="{ ar: 'الرقم الإضافي', en: 'Additional number' }" dir="ltr" mono :disabled="!canEdit" />
      <TextInput v-model="f.district" :label="{ ar: 'الحي', en: 'District' }" :error="missingOf.district" :disabled="!canEdit" />
      <TextInput v-model="f.city" :label="{ ar: 'المدينة', en: 'City' }" :error="missingOf.city" :disabled="!canEdit" />
      <TextInput v-model="f.postalCode" :label="{ ar: 'الرمز البريدي', en: 'Postal code' }" dir="ltr" mono :error="missingOf.postalCode" :disabled="!canEdit" />
      <TextInput v-model="f.province" :label="{ ar: 'المنطقة', en: 'Province' }" :disabled="!canEdit" />
      <TextInput v-model="f.countryCode" :label="{ ar: 'رمز الدولة', en: 'Country code' }" dir="ltr" mono :disabled="!canEdit" />
      <PillChoice v-model="f.filingFrequency" :label="{ ar: 'دورية الإقرار', en: 'Filing frequency' }" :options="optionsOf(FREQUENCY)" />
      <PillChoice v-model="f.einvoiceAuto" :label="{ ar: 'إنشاء الفواتير الإلكترونية تلقائيًا', en: 'Make e-invoices automatically' }" :options="YES_NO" />
      <DateInput v-model="f.einvoiceFrom" :label="{ ar: 'بدءًا من تاريخ', en: 'Starting from' }" :hint="{ ar: 'الفواتير المنشأة قبله لا تنتظر فاتورة إلكترونية', en: 'Invoices made before it do not wait for an e-invoice' }" :disabled="!canEdit" />
    </div>
    <ErrorBanner :error="act.error.value" class="mt-3" @close="act.clearError()" />
    <div v-if="canEdit" class="mt-3 flex flex-wrap gap-2">
      <Btn tone="dark" :loading="act.pending.value" :disabled="!dirty || !ok" :label="{ ar: 'حفظ', en: 'Save' }" @click="save" />
      <Btn v-if="dirty" tone="soft" :label="{ ar: 'تراجع', en: 'Discard' }" @click="reset" />
    </div>
  </SectionCard>
</template>
