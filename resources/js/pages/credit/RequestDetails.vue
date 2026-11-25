<script setup>
// The details pane of a request: company and owner, address and contacts, credit asked and granted, the customer's
// current credit and exposure, payment methods, responsibles, authorised signatories and bank accounts.
// Personal values (owner's national id, responsibles' ids, account numbers, IBANs) arrive masked. «إظهار» (only when
// `can.reveal`) reads them once from GET /credit/requests/{id}/private — every read is audited by the server — and
// keeps them only while this pane is on screen: they are dropped when the pane closes, the drawer closes or another
// request opens. They are deliberately not put in the query cache.
//   <RequestDetails :request="detail" />
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, KV } from '@/components';
import { fmtDate, t } from '@/i18n';
import { money, nameOf } from './credit';

const props = defineProps({
  request: { type: Object, required: true },
});

const act = useAction({ invalidate: false, success: false });
/** The values read from /private while the pane is open (null = masked). */
const shown = ref(null);
watch(() => props.request.id, () => { shown.value = null; });
onBeforeUnmount(() => { shown.value = null; });

const hasPersonal = computed(() => {
  const r = props.request;
  return !!(r.ownerNationalId?.has || (r.responsibles || []).some((x) => x.nationalId?.has) || (r.bankLines || []).some((x) => x.accountNumber?.has || x.iban?.has));
});
async function reveal() {
  const id = props.request.id;
  const res = await act.run(() => api.get(`/credit/requests/${id}/private`));
  if (res && props.request.id === id) shown.value = res;
}
const hide = () => { shown.value = null; };

/** A personal value: the revealed one while shown, else the mask the API sent. */
function personal(masked, revealed) {
  if (revealed && !revealed.hidden && revealed.value) return revealed.value;
  if (!masked?.has) return '—';
  return masked.masked || '••••';
}
const respShown = (id) => (shown.value?.responsibles || []).find((x) => x.id === id)?.nationalId || null;
const bankShown = (id, key) => (shown.value?.bankLines || []).find((x) => x.id === id)?.[key] || null;
const isHidden = (v) => !!v && v.hidden;

const address = computed(() => {
  const r = props.request;
  return [r.buildingNo, r.street, r.district, r.city, r.postalCode].filter(Boolean).join('، ');
});
const exposure = computed(() => props.request.customerCredit?.exposure || null);
const webHref = computed(() => { const w = props.request.website; return w && /^https?:\/\//i.test(w) ? w : null; });
</script>

<template>
  <div class="col !gap-4">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <!-- personal values -->
    <div v-if="hasPersonal" class="row wrap rounded-[12px] bg-soft px-3 py-2 !gap-2">
      <span class="min-w-0 flex-1 text-[10px] text-sec">{{ shown
        ? t('القيم الشخصية ظاهرة الآن — قراءتها سُجّلت في سجل التدقيق، وتُخفى عند إغلاق هذا القسم.', 'Personal values are shown now — the read was recorded in the audit trail; they are hidden again when this pane closes.')
        : t('الهويات والحسابات البنكية تُعرض مقنّعة.', 'National ids and bank accounts are shown masked.') }}</span>
      <Btn v-if="shown" size="sm" tone="soft" :label="{ ar: 'إخفاء', en: 'Hide' }" @click="hide" />
      <Btn v-else-if="request.can?.reveal" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'إظهار (يُسجَّل)', en: 'Show (audited)' }" @click="reveal" />
    </div>

    <section>
      <div class="field-l mb-1.5">{{ t('المنشأة والمالك', 'Company and owner') }}</div>
      <div class="kv-grid">
        <KV :k="{ ar: 'اسم المنشأة', en: 'Company name' }"><span dir="auto">{{ request.companyName || '—' }}</span></KV>
        <KV :k="{ ar: 'الاسم لدى الشريك', en: 'Partner name' }"><span dir="auto">{{ request.partnerName || '—' }}</span></KV>
        <KV :k="{ ar: 'السجل التجاري', en: 'Commercial registration' }" :v="request.commercialRegistration || '—'" ltr />
        <KV :k="{ ar: 'النشاط', en: 'Business activity' }"><span dir="auto">{{ request.businessActivity || '—' }}</span></KV>
        <KV :k="{ ar: 'الكيان القانوني', en: 'Legal entity' }"><span dir="auto">{{ request.legalEntityType || '—' }}</span></KV>
        <KV :k="{ ar: 'المالك', en: 'Owner' }"><span dir="auto">{{ request.ownerName || '—' }}</span></KV>
        <KV :k="{ ar: 'هوية المالك', en: 'Owner’s national id' }" ltr>
          {{ personal(request.ownerNationalId, shown?.ownerNationalId) }}
          <span v-if="isHidden(shown?.ownerNationalId)" class="text-[9px] font-normal text-faint"> ({{ t('ليست لك رؤيتها', 'not yours to see') }})</span>
        </KV>
        <KV :k="{ ar: 'مصدر الطلب', en: 'Source' }"><span dir="auto">{{ request.source || request.sourceName || '—' }}</span></KV>
      </div>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('العنوان والتواصل', 'Address and contact') }}</div>
      <div class="kv-grid">
        <KV :k="{ ar: 'العنوان الوطني', en: 'National address' }"><span dir="auto">{{ address || '—' }}</span></KV>
        <KV :k="{ ar: 'صندوق البريد', en: 'P.O. box' }" :v="request.poBox || '—'" ltr />
        <KV :k="{ ar: 'الجوال', en: 'Mobile' }" ltr><a v-if="request.mobile" :href="`tel:${request.mobile}`">{{ request.mobile }}</a><template v-else>—</template></KV>
        <KV :k="{ ar: 'الهاتف', en: 'Phone' }" ltr><a v-if="request.phone" :href="`tel:${request.phone}`">{{ request.phone }}</a><template v-else>—</template></KV>
        <KV :k="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" ltr><a v-if="request.email" :href="`mailto:${request.email}`" class="break-all">{{ request.email }}</a><template v-else>—</template></KV>
        <KV :k="{ ar: 'الموقع الإلكتروني', en: 'Website' }" ltr><a v-if="webHref" :href="webHref" target="_blank" rel="noopener noreferrer" class="break-all">{{ request.website }}</a><span v-else class="break-all">{{ request.website || '—' }}</span></KV>
      </div>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('الائتمان المطلوب والمعتمد', 'Credit asked and granted') }}</div>
      <div class="kv-grid">
        <KV :k="{ ar: 'الحد المطلوب', en: 'Asked limit' }" :v="money(request.requestedCreditLimit, request.currency)" ltr />
        <KV :k="{ ar: 'أيام السداد المطلوبة', en: 'Asked payment days' }" :v="request.requestedPaymentDays ?? '—'" ltr />
        <KV :k="{ ar: 'حجم المشتريات الشهري', en: 'Monthly purchases' }" :v="money(request.monthlyPurchaseVolume, request.currency)" ltr />
        <KV :k="{ ar: 'الحد المعتمد', en: 'Granted limit' }" :v="money(request.approvedCreditLimit, request.currency)" ltr />
        <KV :k="{ ar: 'أيام السداد المعتمدة', en: 'Granted payment days' }" :v="request.approvedPaymentDays ?? '—'" ltr />
        <KV :k="{ ar: 'شرط الدفع المعتمد', en: 'Granted term' }" :v="request.approvedTerm || '—'" ltr />
        <KV v-if="request.approvedAt" :k="{ ar: 'اعتُمد', en: 'Approved' }"><span class="num ltr">{{ fmtDate(request.approvedAt) }}</span><span class="text-[9px] font-normal text-faint" dir="auto"> · {{ request.approvedBy || '—' }}</span></KV>
        <KV :k="{ ar: 'مقدّم الطلب', en: 'Requester' }"><span dir="auto">{{ request.requester ? nameOf(request.requester) : '—' }}</span></KV>
        <KV :k="{ ar: 'أُرسل', en: 'Submitted' }" :v="request.submittedAt ? fmtDate(request.submittedAt) : '—'" ltr />
      </div>
      <div v-if="request.customerCredit" class="mt-2.5 rounded-[12px] border border-line-2 px-3 py-2.5">
        <div class="mb-1.5 text-[10px] font-extrabold text-ink">{{ t('ائتمان العميل الحالي', 'The customer’s current credit') }} · <span class="num ltr inline-block">{{ request.customerCredit.code }}</span>
          <span v-if="request.customerCredit.sourceSystem === 'odoo'" class="text-[9px] font-bold text-violet"> · {{ t('من أودو', 'from Odoo') }}</span></div>
        <div class="kv-grid">
          <KV :k="{ ar: 'الحد الائتماني', en: 'Credit limit' }" :v="money(request.customerCredit.creditLimit)" ltr />
          <KV :k="{ ar: 'شروط الدفع', en: 'Payment terms' }" :v="request.customerCredit.terms || '—'" ltr />
          <template v-if="exposure">
            <KV :k="{ ar: 'الذمم المدينة', en: 'Receivables' }" :v="money(exposure.receivables)" ltr />
            <KV :k="{ ar: 'طلبات مفتوحة', en: 'Open orders' }" :v="money(exposure.openOrders)" ltr />
            <KV :k="{ ar: 'إجمالي الانكشاف', en: 'Exposure' }" :v="money(exposure.exposure)" ltr />
            <KV :k="{ ar: 'المتاح', en: 'Available' }" ltr><span :class="exposure.limited && Number(exposure.available) < 0 ? 'text-bad' : ''">{{ exposure.limited ? money(exposure.available) : t('بلا حد', 'No limit') }}</span></KV>
          </template>
        </div>
      </div>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('طرق الدفع', 'Payment methods') }}</div>
      <div v-if="request.paymentMethods?.length" class="row wrap !gap-1.5">
        <Chip v-for="m in request.paymentMethods" :key="m.id" small :label="{ ar: m.nameAr, en: m.nameEn || m.nameAr }" fg="#654e92" bg="#efeaf8" />
      </div>
      <div v-else class="text-[10.5px] text-faint">{{ t('لم تُحدَّد طرق دفع.', 'No payment method chosen.') }}</div>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('المسؤولون', 'Responsibles') }}</div>
      <div v-if="!request.responsibles?.length" class="text-[10.5px] text-faint">{{ t('لا مسؤولين مسجلين.', 'No responsible recorded.') }}</div>
      <div class="col !gap-1.5">
        <div v-for="p in request.responsibles" :key="p.id" class="row wrap rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
          <b class="min-w-0 flex-1 text-ink" dir="auto">{{ p.name }}</b>
          <span class="text-sec" dir="auto">{{ p.position || '—' }}</span>
          <span class="num ltr">{{ p.mobile || '—' }}</span>
          <span class="num ltr text-muted" :title="t('رقم الهوية', 'National id')">{{ personal(p.nationalId, respShown(p.id)) }}</span>
        </div>
      </div>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('المفوضون بالتوقيع', 'Authorised signatories') }}</div>
      <div v-if="!request.signatories?.length" class="text-[10.5px] text-faint">{{ t('لا مفوضين مسجلين.', 'No signatory recorded.') }}</div>
      <div class="col !gap-1.5">
        <div v-for="s in request.signatories" :key="s.id" class="rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
          <div class="row wrap"><b class="min-w-0 flex-1 text-ink" dir="auto">{{ s.name }}</b><span class="text-sec" dir="auto">{{ s.jobTitle || '—' }}</span><span class="num ltr">{{ s.mobile || '—' }}</span></div>
          <div v-if="s.signatureNote" class="mt-1 text-[9.5px] text-muted" dir="auto">{{ s.signatureNote }}</div>
        </div>
      </div>
    </section>

    <section>
      <div class="field-l mb-1.5">{{ t('الحسابات البنكية', 'Bank accounts') }}</div>
      <div v-if="!request.bankLines?.length" class="text-[10.5px] text-faint">{{ t('لا حسابات بنكية مسجلة.', 'No bank account recorded.') }}</div>
      <div class="col !gap-1.5">
        <div v-for="b in request.bankLines" :key="b.id" class="rounded-[12px] border border-line-2 px-3 py-2 text-[10.5px]">
          <div class="row wrap"><b class="min-w-0 flex-1 text-ink" dir="auto">{{ b.bankName || '—' }}</b><span class="text-sec" dir="auto">{{ b.branch || '' }}</span><span v-if="b.swift" class="num ltr">SWIFT {{ b.swift }}</span></div>
          <div class="row wrap mt-1 !gap-x-4 text-muted">
            <span>{{ t('رقم الحساب', 'Account') }}: <span class="num ltr inline-block">{{ personal(b.accountNumber, bankShown(b.id, 'accountNumber')) }}</span></span>
            <span>IBAN: <span class="num ltr inline-block">{{ personal(b.iban, bankShown(b.id, 'iban')) }}</span></span>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
