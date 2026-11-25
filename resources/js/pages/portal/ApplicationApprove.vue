<script setup>
// Approving an onboarding application: the company becomes an OPS customer — and never a second customer for a
// company OPS already has. `application.match` is what identity says (the server works it out):
//   needs = nothing    a link, the VAT number or the registration names the customer: one button, nothing to decide
//   needs = conflict   the two numbers name two different customers: the reviewer says which
//   needs = choice     nobody is named: the reviewer picks an existing customer (the candidates are only shown, never
//                      acted upon) or confirms a NEW one — which needs customer.manage as well
// POST /portal/applications/{id}/approve { customerId? | confirmNew: true, zone?, contact?, address?, terms?, creditLimit?, priceList?, note? }
//   <ApplicationApprove :application="detailOrNull" @close="…" @done="(application) => …" />     (open while `application` is set)
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { num, t } from '@/i18n';
import { CASH_TERMS, PRICE_LISTS, TERMS } from '@/pages/sales/shared';
import { useAuth } from '@/stores/auth';
import CustomerPicker from './CustomerPicker.vue';
import { CANDIDATE_WHY, CUSTOMER_BY, TONE, customerLine, labelOf, nameOf } from './portal';

const props = defineProps({
  /** The application as GET /portal/applications/{id} gives it; null closes the drawer. */
  application: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['portal', 'customers', 'master'] });
const isOpen = computed(() => !!props.application);
const m = computed(() => props.application?.match || null);
const mayCreate = computed(() => auth.can('customer.manage'));
/** The customers the reviewer may pick with one tap: the two the numbers disagree on, or the candidates. */
const offered = computed(() => (m.value ? (m.value.needs === 'conflict' ? m.value.conflict : m.value.candidates) : []));

/** What the reviewer chose: a customer's id · 'other' (picked by name) · 'new'. */
const choice = ref('');
const otherCode = ref('');
const note = ref('');
const nw = reactive({ zone: '', contact: '', address: '', terms: CASH_TERMS, creditLimit: null, priceList: '' });
watch(() => props.application?.id, () => {
  choice.value = ''; otherCode.value = ''; note.value = '';
  const s = props.application?.match?.suggested || {};
  Object.assign(nw, { zone: s.zone || '', contact: s.contact || '', address: s.address || '', terms: CASH_TERMS, creditLimit: null, priceList: '' });
  act.clearError();
}, { immediate: true });

const creditMissing = computed(() => choice.value === 'new' && nw.terms !== CASH_TERMS && !(num(nw.creditLimit) > 0));
const ok = computed(() => {
  if (!m.value) return false;
  if (m.value.needs === 'nothing') return true;
  if (choice.value === 'new') return m.value.needs === 'choice' && mayCreate.value && !!nw.zone.trim() && !creditMissing.value;
  if (choice.value === 'other') return !!otherCode.value;
  return !!choice.value;
});

async function submit() {
  const body = note.value.trim() ? { note: note.value.trim() } : {};
  if (m.value.needs !== 'nothing') {
    if (choice.value === 'new') Object.assign(body, { confirmNew: true, zone: nw.zone.trim(), contact: nw.contact.trim() || undefined, address: nw.address.trim() || undefined, terms: nw.terms, creditLimit: num(nw.creditLimit), priceList: nw.priceList || undefined });
    else body.customerId = choice.value === 'other' ? otherCode.value : choice.value;
  }
  const r = await act.run(() => api.postIdempotent(`/portal/applications/${props.application.id}/approve`, body),
    { success: (a) => (a.approval?.by === 'new' ? t(`اعتُمد الطلب وأُنشئ العميل ${a.approval.customer.code}`, `Approved — customer ${a.approval.customer.code} created`) : t(`اعتُمد الطلب ورُبط بالعميل ${a.approval?.customer?.code || ''}`, `Approved — linked to customer ${a.approval?.customer?.code || ''}`)) });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="isOpen" :width="600" :z-index="70" :title="application ? { ar: `اعتماد الطلب ${application.number}`, en: `Approve application ${application.number}` } : null"
          :sub="application ? application.companyNameAr : null" @close="emit('close')">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <template v-if="m">
      <!-- identity names the customer: nothing to decide -->
      <div v-if="m.needs === 'nothing'" class="hint green !mt-0">
        <div class="text-[11.5px] font-extrabold">{{ t('المنشأة معروفة في نظام العمليات', 'The company is known to OPS') }}</div>
        <div class="mt-1">{{ t(`يدل ${labelOf(CUSTOMER_BY, m.by)} على العميل`, `${labelOf(CUSTOMER_BY, m.by)} names the customer`) }} <b dir="auto">{{ customerLine(m.customer) }}</b> — {{ t('يُربط الطلب به ولا يُنشأ عميل جديد.', 'the application is linked to it; no new customer is made.') }}</div>
        <div v-if="!m.customer.active" class="mt-1 font-extrabold text-bad">{{ t('هذا العميل موقوف — يُفعَّل أولًا من صفحة العملاء.', 'This customer is inactive — activate it first on the customers page.') }}</div>
      </div>

      <template v-else>
        <div class="hint amber !mt-0">
          {{ m.needs === 'conflict'
            ? t('الرقم الضريبي والسجل التجاري يدلان على عميلين مختلفين. حدّد أيهما صاحب هذا الطلب — لا يُفترض شيء.', 'The VAT number and the commercial registration name two different customers. Say which one this application belongs to — nothing is assumed.')
            : t('لا رابط ولا رقم يدل على عميل موجود. حدّد العميل الموجود الذي يخصه الطلب، أو أكّد أنه عميل جديد.', 'No link and no number name an existing customer. Pick the existing customer this application belongs to, or confirm that it is new.') }}
        </div>

        <div v-if="offered.length" class="mt-3">
          <div class="field-l">{{ m.needs === 'conflict' ? t('العميلان', 'The two customers') : t('عملاء قد يكون أحدهم صاحب الطلب', 'Customers one of whom may be the applicant') }}</div>
          <button v-for="c in offered" :key="c.id" type="button" class="mb-1 flex w-full cursor-pointer items-center gap-2.5 rounded-[11px] border px-3 py-2 text-start" :class="choice === c.id ? 'border-violet bg-[#efeaf8]' : 'border-line-2 bg-white'" @click="choice = c.id">
            <span class="h-3.5 w-3.5 flex-none rounded-full border-2" :class="choice === c.id ? 'border-violet bg-violet' : 'border-line'" />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-[11px] font-extrabold" dir="auto">{{ nameOf(c) }} <span class="num ltr font-normal text-muted">({{ c.code }})</span></span>
              <span class="block text-[9.5px] text-muted"><template v-if="c.city">{{ c.city }} · </template><span v-if="c.vatNo" class="num ltr">VAT {{ c.vatNo }}</span><span v-if="c.cr" class="num ltr"> · CR {{ c.cr }}</span><template v-if="!c.active"> · {{ t('موقوف', 'inactive') }}</template></span>
            </span>
            <span class="flex flex-wrap justify-end gap-1"><Chip v-for="w in c.why || []" :key="w" small :label="CANDIDATE_WHY[w] || w" :fg="TONE.blue.fg" :bg="TONE.blue.bg" /></span>
          </button>
        </div>

        <template v-if="m.needs === 'choice'">
          <button type="button" class="mb-1 mt-2 flex w-full cursor-pointer items-center gap-2.5 rounded-[11px] border px-3 py-2 text-start" :class="choice === 'other' ? 'border-violet bg-[#efeaf8]' : 'border-line-2 bg-white'" @click="choice = 'other'">
            <span class="h-3.5 w-3.5 flex-none rounded-full border-2" :class="choice === 'other' ? 'border-violet bg-violet' : 'border-line'" />
            <span class="text-[11px] font-extrabold">{{ t('عميل موجود آخر — أبحث عنه', 'Another existing customer — let me find it') }}</span>
          </button>
          <div v-if="choice === 'other'" class="mb-2 ps-6"><CustomerPicker :value="otherCode" @pick="(c) => (otherCode = c ? c.code : '')" /></div>

          <button type="button" class="mb-1 flex w-full cursor-pointer items-center gap-2.5 rounded-[11px] border px-3 py-2 text-start" :class="choice === 'new' ? 'border-violet bg-[#efeaf8]' : 'border-line-2 bg-white'" @click="choice = 'new'">
            <span class="h-3.5 w-3.5 flex-none rounded-full border-2" :class="choice === 'new' ? 'border-violet bg-violet' : 'border-line'" />
            <span class="text-[11px] font-extrabold">{{ t('عميل جديد — أنشئه من بيانات الطلب', 'A new customer — make it from the application') }}</span>
          </button>
          <div v-if="choice === 'new'" class="ps-6">
            <div v-if="!mayCreate" class="hint amber !mt-1">{{ t('إنشاء عميل جديد يحتاج صلاحية إدارة العملاء. يمكنك ربط الطلب بعميل موجود، أو اطلب من صاحب الصلاحية اعتماده.', 'Making a new customer needs the customers permission. You can link the application to an existing customer, or ask a holder of that permission to approve it.') }}</div>
            <div v-else class="form-grid mt-1">
              <div class="field full"><label class="field-l">{{ t('يُنشأ العميل باسم', 'The customer is created as') }}</label><div class="inp flex items-center !bg-soft text-[11px] font-bold" dir="auto">{{ m.suggested.nameAr }}<span v-if="m.suggested.vatNo" class="num ltr ms-2 font-normal text-muted">VAT {{ m.suggested.vatNo }}</span></div></div>
              <TextInput v-model="nw.zone" :label="{ ar: 'المنطقة / الحي (نطاق التسليم)', en: 'Zone / district (delivery zone)' }" required />
              <TextInput v-model="nw.contact" :label="{ ar: 'جهة الاتصال · الجوال', en: 'Contact · mobile' }" />
              <SelectInput v-model="nw.terms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS" />
              <NumberInput v-model="nw.creditLimit" :label="{ ar: 'الحد الائتماني ر.س', en: 'Credit limit SAR' }" :min="0" :error="creditMissing ? t('العميل الآجل يحتاج حدًا ائتمانيًا أكبر من صفر', 'Credit terms need a credit limit above zero') : null" />
              <SelectInput v-model="nw.priceList" :label="{ ar: 'قائمة الأسعار', en: 'Price list' }" :options="PRICE_LISTS" :placeholder="{ ar: '—', en: '—' }" />
              <TextInput v-model="nw.address" full :label="{ ar: 'العنوان', en: 'Address' }" />
            </div>
          </div>
        </template>
      </template>

      <div class="mt-3"><TextArea v-model="note" :rows="2" :label="{ ar: 'ملاحظة على الاعتماد (اختياري)', en: 'A note on the approval (optional)' }" /></div>
    </template>

    <template #footer>
      <div class="flex gap-2">
        <Btn tone="success" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!ok" :label="m && m.needs === 'nothing' ? { ar: 'اعتماد وربط بالعميل', en: 'Approve and link' } : choice === 'new' ? { ar: 'اعتماد وإنشاء العميل', en: 'Approve and create the customer' } : { ar: 'اعتماد', en: 'Approve' }" @click="submit" />
        <Btn tone="soft" class="!h-11 !w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div v-if="m && !ok && !act.pending.value" class="mt-2 text-[10px] font-bold leading-[1.7] text-warn" role="status">{{ t('حدّد العميل الذي يخصه الطلب لتفعيل الزر', 'Say which customer the application belongs to, to enable the button') }}</div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('يُسجَّل الاعتماد ومن اعتمده وكيف عُرف العميل في سجل الطلب وفي Audit Trail.', 'The approval, who made it and how the customer was named are recorded on the application and in the audit trail.') }}</div>
    </template>
  </Drawer>
</template>
