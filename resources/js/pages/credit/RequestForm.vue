<script setup>
// The form of a credit request — new (POST /credit/requests, needs credit.request) or edit (PATCH /credit/requests/{id}):
// the customer, the company, the owner, contact and national address, the amounts asked, the payment methods, and the
// responsibles, authorised signatories and bank accounts (CreditLines).
// An edit sends only what changed (an absent key is unchanged); a list that changed is sent whole and replaces that
// kind of lines — every line keeps its id. Personal values (owner's national id, a responsible's national id, account
// number, IBAN) are never sent back: their field starts empty with the saved mask as placeholder, and only what the
// person types is sent. Validation details of the server land on their fields.
//   <RequestForm :open="open" :request="detailOrNull" @close="…" @done="(detail) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Drawer, ErrorBanner, Field, NumberInput, TextInput } from '@/components';
import { num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import CreditLines from './CreditLines.vue';
import CustomerPicker from './CustomerPicker.vue';
import { useCreditLookups } from './credit';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The request detail to edit; null = a new request. */
  request: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['credit'] });
const lk = useCreditLookups();
const edit = computed(() => !!props.request);

const TEXT = ['companyName', 'commercialRegistration', 'businessActivity', 'legalEntityType', 'ownerName', 'phone', 'mobile', 'email', 'website', 'poBox', 'postalCode', 'street', 'district', 'buildingNo', 'city'];
const NUMS = ['requestedCreditLimit', 'requestedPaymentDays', 'monthlyPurchaseVolume'];
let seq = 0;
const key = () => `l${seq++}`;
const blank = () => ({
  ...Object.fromEntries(TEXT.map((k) => [k, ''])), ...Object.fromEntries(NUMS.map((k) => [k, null])),
  customer: null, ownerNationalId: '', ownerNationalIdMask: '', paymentMethodIds: [], responsibles: [], signatories: [], bankLines: [],
});
const fromRequest = (r) => ({
  ...Object.fromEntries(TEXT.map((k) => [k, r[k] || ''])),
  requestedCreditLimit: r.requestedCreditLimit != null ? num(r.requestedCreditLimit) : null,
  requestedPaymentDays: r.requestedPaymentDays ?? null,
  monthlyPurchaseVolume: r.monthlyPurchaseVolume != null ? num(r.monthlyPurchaseVolume) : null,
  customer: r.customer ? { ...r.customer, creditLimit: r.customerCredit?.creditLimit ?? null, terms: r.customerCredit?.terms ?? null } : null,
  ownerNationalId: '', ownerNationalIdMask: r.ownerNationalId?.has ? r.ownerNationalId.masked || '••••' : '',
  paymentMethodIds: (r.paymentMethods || []).map((m) => String(m.id)),
  responsibles: (r.responsibles || []).map((l) => ({ key: key(), id: l.id, name: l.name || '', position: l.position || '', mobile: l.mobile || '', nationalId: '', nationalIdMask: l.nationalId?.has ? l.nationalId.masked || '••••' : '' })),
  signatories: (r.signatories || []).map((l) => ({ key: key(), id: l.id, name: l.name || '', jobTitle: l.jobTitle || '', mobile: l.mobile || '', signatureNote: l.signatureNote || '' })),
  bankLines: (r.bankLines || []).map((l) => ({
    key: key(), id: l.id, bankName: l.bankName || '', branch: l.branch || '', swift: l.swift || '',
    accountNumber: '', accountNumberMask: l.accountNumber?.has ? l.accountNumber.masked || '••••' : '', iban: '', ibanMask: l.iban?.has ? l.iban.masked || '••••' : '',
  })),
});
const v = reactive(blank());
let initial = blank();
const errors = ref({});

watch(() => [props.open, props.request], () => {
  if (!props.open) return;
  initial = props.request ? fromRequest(props.request) : blank();
  Object.assign(v, JSON.parse(JSON.stringify(initial)));
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

/** A new customer fills the company name and city when they are still empty. */
watch(() => v.customer, (c, was) => {
  if (!c || c === was || edit.value) return;
  if (!v.companyName) v.companyName = c.nameAr || c.nameEn || '';
  if (!v.city && c.city) v.city = c.city;
});
const methods = computed(() => lk.paymentMethods.value.filter((m) => m.active || v.paymentMethodIds.includes(String(m.id))));
const toggleMethod = (id) => { const k = String(id); v.paymentMethodIds = v.paymentMethodIds.includes(k) ? v.paymentMethodIds.filter((x) => x !== k) : [...v.paymentMethodIds, k]; };

// ---- what is sent
const clean = (x) => (typeof x === 'string' ? (x.trim() === '' ? null : x.trim()) : x ?? null);
const LINE_KEYS = { responsibles: ['name', 'position', 'mobile'], signatories: ['name', 'jobTitle', 'mobile', 'signatureNote'], bankLines: ['bankName', 'branch', 'swift'] };
const PERSONAL = { responsibles: ['nationalId'], signatories: [], bankLines: ['accountNumber', 'iban'] };
/** The lines of a kind as the API takes them: empty lines dropped, ids kept, personal values only when typed. */
function linesOut(kind, rows) {
  return rows.filter((l) => [...LINE_KEYS[kind], ...PERSONAL[kind]].some((k) => String(l[k] || '').trim())).map((l) => {
    const out = l.id ? { id: l.id } : {};
    for (const k of LINE_KEYS[kind]) out[k] = clean(l[k]);
    for (const k of PERSONAL[kind]) if (String(l[k] || '').trim()) out[k] = String(l[k]).trim();
    return out;
  });
}
function body() {
  const now = {
    customerId: v.customer?.id ?? null,
    ...Object.fromEntries(TEXT.map((k) => [k, clean(v[k])])),
    requestedCreditLimit: v.requestedCreditLimit == null || v.requestedCreditLimit === '' ? null : Number(v.requestedCreditLimit),
    requestedPaymentDays: v.requestedPaymentDays == null || v.requestedPaymentDays === '' ? null : Number(v.requestedPaymentDays),
    monthlyPurchaseVolume: v.monthlyPurchaseVolume == null || v.monthlyPurchaseVolume === '' ? null : Number(v.monthlyPurchaseVolume),
    paymentMethodIds: [...v.paymentMethodIds].sort(),
    responsibles: linesOut('responsibles', v.responsibles),
    signatories: linesOut('signatories', v.signatories),
    bankLines: linesOut('bankLines', v.bankLines),
  };
  const out = {};
  if (!edit.value) {
    for (const [k, x] of Object.entries(now)) if (x !== null && !(Array.isArray(x) && !x.length)) out[k] = x;
  } else {
    const was = { ...body0(initial) };
    for (const [k, x] of Object.entries(now)) if (JSON.stringify(x) !== JSON.stringify(was[k])) out[k] = x;
  }
  if (v.ownerNationalId.trim()) out.ownerNationalId = v.ownerNationalId.trim();
  return out;
}
/** The same shape for the values the form started from (to send only what changed). */
function body0(s) {
  return {
    customerId: s.customer?.id ?? null,
    ...Object.fromEntries(TEXT.map((k) => [k, clean(s[k])])),
    requestedCreditLimit: s.requestedCreditLimit == null ? null : Number(s.requestedCreditLimit),
    requestedPaymentDays: s.requestedPaymentDays == null ? null : Number(s.requestedPaymentDays),
    monthlyPurchaseVolume: s.monthlyPurchaseVolume == null ? null : Number(s.monthlyPurchaseVolume),
    paymentMethodIds: [...s.paymentMethodIds].sort(),
    responsibles: linesOut('responsibles', s.responsibles),
    signatories: linesOut('signatories', s.signatories),
    bankLines: linesOut('bankLines', s.bankLines),
  };
}

const tenDigits = (x) => /^\d{10}$/.test(String(x || '').trim());
async function submit() {
  const errs = {};
  if (!v.customer) errs.customerId = t('اختر العميل', 'Choose the customer');
  if (!v.companyName.trim()) errs.companyName = t('هذا الحقل إلزامي', 'Required');
  if (v.commercialRegistration.trim() && !tenDigits(v.commercialRegistration)) errs.commercialRegistration = t('10 أرقام', '10 digits');
  if (v.ownerNationalId.trim() && !tenDigits(v.ownerNationalId)) errs.ownerNationalId = t('10 أرقام', '10 digits');
  const d = v.requestedPaymentDays;
  if (d != null && d !== '' && (!Number.isInteger(Number(d)) || Number(d) < 1 || Number(d) > 365)) errs.requestedPaymentDays = t('من 1 إلى 365 يومًا', 'From 1 to 365 days');
  v.responsibles.forEach((l, i) => { if (String(l.nationalId || '').trim() && !tenDigits(l.nationalId)) errs[`responsibles.${i}.nationalId`] = t('10 أرقام', '10 digits'); });
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const payload = body();
  if (edit.value && !Object.keys(payload).length) { emit('close'); return; }
  const r = await act.run(() => (edit.value ? api.patch(`/credit/requests/${props.request.id}`, payload) : api.postIdempotent('/credit/requests', payload)), {
    success: (x) => (edit.value ? t(`حُفظ الطلب ${x?.number || ''}`, `Request ${x?.number || ''} saved`) : t(`أُنشئ الطلب ${x?.number || ''} كمسودة`, `Request ${x?.number || ''} created as a draft`)),
  });
  if (r) emit('done', r);
}
const title = computed(() => (edit.value ? { ar: `تعديل الطلب ${props.request.number}`, en: `Edit request ${props.request.number}` } : { ar: 'طلب ائتمان جديد', en: 'New credit request' }));
</script>

<template>
  <Drawer :open="open" :width="760" :z-index="70" :title="title" :sub="{ ar: 'يُحفظ كمسودة، ثم يُرسل للمراجعة من الطلب نفسه', en: 'Saved as a draft, then sent for review from the request itself' }" @close="emit('close')">
    <div class="col !gap-4">
      <CustomerPicker v-model="v.customer" :error="errors.customerId" />

      <section>
        <div class="field-l mb-1.5">{{ t('المنشأة والمالك', 'Company and owner') }}</div>
        <div class="form-grid">
          <TextInput v-model="v.companyName" required :label="{ ar: 'اسم المنشأة', en: 'Company name' }" :error="errors.companyName" />
          <TextInput v-model="v.commercialRegistration" dir="ltr" :label="{ ar: 'السجل التجاري (10 أرقام)', en: 'Commercial registration (10 digits)' }" :error="errors.commercialRegistration" />
          <TextInput v-model="v.businessActivity" :label="{ ar: 'النشاط', en: 'Business activity' }" :error="errors.businessActivity" />
          <TextInput v-model="v.legalEntityType" :label="{ ar: 'الكيان القانوني', en: 'Legal entity type' }" :placeholder="{ ar: 'مؤسسة فردية، شركة ذات مسؤولية محدودة…', en: 'Sole proprietorship, LLC…' }" :error="errors.legalEntityType" />
          <TextInput v-model="v.ownerName" :label="{ ar: 'اسم المالك', en: 'Owner name' }" :error="errors.ownerName" />
          <TextInput v-model="v.ownerNationalId" dir="ltr" autocomplete="off" :label="{ ar: 'هوية المالك (10 أرقام)', en: 'Owner’s national id (10 digits)' }"
                     :placeholder="v.ownerNationalIdMask ? { ar: `${v.ownerNationalIdMask} (اتركه فارغًا ليبقى)`, en: `${v.ownerNationalIdMask} (leave empty to keep)` } : null" :error="errors.ownerNationalId" />
        </div>
      </section>

      <section>
        <div class="field-l mb-1.5">{{ t('التواصل والعنوان الوطني', 'Contact and national address') }}</div>
        <div class="form-grid">
          <TextInput v-model="v.mobile" dir="ltr" :label="{ ar: 'الجوال', en: 'Mobile' }" :error="errors.mobile" />
          <TextInput v-model="v.phone" dir="ltr" :label="{ ar: 'الهاتف', en: 'Phone' }" :error="errors.phone" />
          <TextInput v-model="v.email" dir="ltr" type="email" :label="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" :error="errors.email" />
          <TextInput v-model="v.website" dir="ltr" :label="{ ar: 'الموقع الإلكتروني', en: 'Website' }" :error="errors.website" />
          <TextInput v-model="v.city" :label="{ ar: 'المدينة', en: 'City' }" :error="errors.city" />
          <TextInput v-model="v.district" :label="{ ar: 'الحي', en: 'District' }" :error="errors.district" />
          <TextInput v-model="v.street" :label="{ ar: 'الشارع', en: 'Street' }" :error="errors.street" />
          <TextInput v-model="v.buildingNo" dir="ltr" :label="{ ar: 'رقم المبنى', en: 'Building no.' }" :error="errors.buildingNo" />
          <TextInput v-model="v.postalCode" dir="ltr" :label="{ ar: 'الرمز البريدي', en: 'Postal code' }" :error="errors.postalCode" />
          <TextInput v-model="v.poBox" dir="ltr" :label="{ ar: 'صندوق البريد', en: 'P.O. box' }" :error="errors.poBox" />
        </div>
      </section>

      <section>
        <div class="field-l mb-1.5">{{ t('الائتمان المطلوب', 'Credit asked') }}</div>
        <div class="form-grid">
          <NumberInput v-model="v.requestedCreditLimit" :min="0" :label="{ ar: 'الحد المطلوب (ر.س)', en: 'Limit asked (SAR)' }" :error="errors.requestedCreditLimit" />
          <NumberInput v-model="v.requestedPaymentDays" :min="1" :max="365" :step="1" :label="{ ar: 'أيام السداد المطلوبة', en: 'Payment days asked' }" :error="errors.requestedPaymentDays" />
          <NumberInput v-model="v.monthlyPurchaseVolume" :min="0" :label="{ ar: 'حجم المشتريات الشهري (ر.س)', en: 'Monthly purchases (SAR)' }" :error="errors.monthlyPurchaseVolume" />
        </div>
        <Field class="mt-3" :label="{ ar: 'طرق الدفع', en: 'Payment methods' }" :error="errors.paymentMethodIds">
          <div v-if="methods.length" class="row wrap !gap-1.5">
            <button v-for="m in methods" :key="m.id" type="button" class="pill" :class="{ active: v.paymentMethodIds.includes(String(m.id)) }" :aria-pressed="v.paymentMethodIds.includes(String(m.id))" @click="toggleMethod(m.id)">{{ t(m.nameAr, m.nameEn || m.nameAr) }}</button>
          </div>
          <div v-else class="text-[10.5px] text-faint">{{ t('لا طرق دفع مُعدّة في الإعداد.', 'No payment method is set up.') }}</div>
        </Field>
      </section>

      <section>
        <div class="field-l mb-1.5">{{ t('المسؤولون', 'Responsibles') }}</div>
        <CreditLines v-model:lines="v.responsibles" kind="responsibles" :errors="errors" />
      </section>
      <section>
        <div class="field-l mb-1.5">{{ t('المفوضون بالتوقيع', 'Authorised signatories') }}</div>
        <CreditLines v-model:lines="v.signatories" kind="signatories" :errors="errors" />
      </section>
      <section>
        <div class="field-l mb-1.5">{{ t('الحسابات البنكية', 'Bank accounts') }}</div>
        <CreditLines v-model:lines="v.bankLines" kind="bankLines" :errors="errors" />
      </section>
    </div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="edit ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إنشاء الطلب', en: 'Create the request' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t(`* حقل إلزامي · الهويات والحسابات لا تُعرض كاملة ولا تُسجَّل قيمها · يُسجل الإجراء باسم ${auth.user?.nameAr || ''}`, `* required · ids and accounts are never shown in full nor logged · recorded as ${auth.user?.nameEn || ''}`) }}</div>
    </template>
  </Drawer>
</template>
