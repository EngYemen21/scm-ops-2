<script setup>
// Supplier quotation comparison of one RFQ (GET /procurement/rfq/:number/comparison): one row per quotation with
// unit price, total (+ weighted score), lead time, payment, MOQ, the system notes and the recommendation
// (price 50% · lead 30% · supplier score 20%). Actions: record a quote, cancel the RFQ, award → PO.
//   <RfqComparison :number="sel" @close @quote="({ rfqNumber }) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import AwardDrawer from './AwardDrawer.vue';
import { RFQ_LABELS, SQ_LABELS, linesSummary, sname } from './shared';

const props = defineProps({
  /** RFQ number */
  number: { type: String, required: true },
});
const emit = defineEmits(['close', 'quote']);

const auth = useAuth();
const act = useAction({ invalidate: ['procurement'] });
const cmpQ = useGet(() => `/procurement/rfq/${encodeURIComponent(props.number)}/comparison`);
/** Comparison — see shared.js */
const cmp = computed(() => cmpQ.data.value);
const rfqOpen = computed(() => !!cmp.value && ['open', 'quoted', 'compared'].includes(cmp.value.rfq.status));
const need = computed(() => (cmp.value ? cmp.value.need.reduce((a, l) => a + l.qty, 0) : 0));
const invited = computed(() => (cmp.value?.invited || []).map((s) => sname(s)).join('، ') || '—');

/** Quotation row being awarded (null = closed). */
const award = ref(null);
watch(() => props.number, () => { award.value = null; });

const isAwarded = (r) => r.status === 'awarded' || cmp.value.rfq.awardedQuotationId === r.quotationId;
const canAward = (r) => rfqOpen.value && auth.can('rfq.award') && r.complete && !r.expired;
const reasonsOf = (r) => (lang.value === 'ar' ? r.reasons : r.reasonsEn) || [];

async function cancelRfq() {
  const n = props.number;
  if (!(await confirm({ title: { ar: `إلغاء ${n}؟`, en: `Cancel ${n}?` } }))) return;
  void act.run(() => api.postIdempotent(`/procurement/rfq/${n}/cancel`, {}), { success: t(`أُلغي ${n}`, `${n} cancelled`) });
}

const GT_STYLE = { '--gt-cols': 'minmax(160px,1.3fr) 85px 100px 80px 70px 110px 60px minmax(200px,1.5fr) 150px', '--gt-min': '1120px' };
/** Three-quotes policy: fewer received → the award needs a written exception. */
const belowPolicy = computed(() => !!cmp.value?.policy && cmp.value.policy.received < cmp.value.policy.minQuotes);
</script>

<template>
  <div class="card mt-3">
    <div class="card-head">
      <div class="card-title">
        {{ t('مقارنة عروض الموردين', 'Supplier quotation comparison') }} — <span class="num text-violet">{{ number }}</span>
        <Chip v-if="cmp" :map="RFQ_LABELS" :k="cmp.rfq.status" small class="ms-2" />
        <div v-if="cmp" class="card-sub">
          {{ t('الحاجة', 'Need') }}: {{ linesSummary(cmp.need) }} · {{ t('مدعوون', 'Invited') }}: {{ invited }} · {{ t('الإقفال', 'Closes') }} <span class="ltr num">{{ fmtDateOnly(cmp.rfq.closeDate) }}</span>
          <template v-if="cmp.rfq.pr"> · PR <span class="num">{{ cmp.rfq.pr.number }}</span></template>
        </div>
      </div>
      <Btn v-if="rfqOpen && auth.can('supquote.create')" size="sm" tone="soft" :label="{ ar: '+ تسجيل عرض', en: '+ Record quote' }" @click="emit('quote', { rfqNumber: number })" />
      <Btn v-if="rfqOpen && auth.can('rfq.create')" size="sm" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء RFQ', en: 'Cancel RFQ' }" @click="cancelRfq" />
      <button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button>
    </div>

    <div class="mx-[18px]"><ErrorBanner :error="cmpQ.error.value || act.error.value" @close="act.clearError()" /></div>
    <div v-if="cmpQ.isLoading.value" class="skel m-[18px] min-h-[100px]" />
    <div v-if="cmp?.policy" class="mx-[18px] mb-2 banner" :class="belowPolicy ? 'amber' : 'green'">{{ belowPolicy ? t(`سياسة ${cmp.policy.minQuotes} عروض: المستلم ${cmp.policy.received} — الترسية تتطلب مبرّر استثناء مكتوبًا (مورد وحيد، طارئ…)`, `${cmp.policy.minQuotes}-quotes policy: ${cmp.policy.received} received — awarding needs a written exception (sole source, emergency…)`) : t(`سياسة ${cmp.policy.minQuotes} عروض مستوفاة (${cmp.policy.received} عرض)`, `${cmp.policy.minQuotes}-quotes policy met (${cmp.policy.received} quotes)`) }}</div>
    <div v-if="cmp && cmp.quotes.length === 0" class="empty">{{ t('لا عروض مسجلة بعد — سجّل عروض الموردين لتبدأ المقارنة', 'No quotations recorded yet — record supplier quotes to start comparing') }}</div>

    <div v-if="cmp && cmp.quotes.length > 0" class="gt-wrap">
      <div class="gt" :style="GT_STYLE">
        <div class="gt-head">
          <div>{{ t('المورد', 'Supplier') }}</div><div>{{ t('سعر الوحدة', 'Unit price') }}</div><div>{{ t('الإجمالي الواصل', 'Landed total') }}</div><div>{{ t('مهلة التوريد', 'Lead time') }}</div><div>{{ t('ضمان', 'Warranty') }}</div>
          <div>{{ t('الدفع', 'Payment') }}</div><div>{{ t('حد أدنى', 'MOQ') }}</div><div>{{ t('تقييم النظام', 'System note') }}</div><div />
        </div>
        <div v-for="r in cmp.quotes" :key="r.quotationId" class="gt-row" :class="{ 'bg-[#F6FDF8]': r.rec }">
          <div class="min-w-0">
            <div class="text-[11px] font-extrabold text-ink">{{ sname(r.supplier) }} <span class="num muted text-[9px]">Score {{ fmtNum(r.score) }}</span></div>
            <div class="num text-[8.5px] text-faint">{{ r.number }}{{ r.supplierRef ? ` · ${r.supplierRef}` : '' }}</div>
            <div v-if="r.rec" class="mt-1"><Chip small fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'توصية النظام', en: 'Recommended' }" /></div>
          </div>
          <div class="num ltr text-start text-[12px] text-ink">{{ fmtMoney(r.unitPrice) }}</div>
          <div class="num ltr text-start text-[11px]">{{ fmtMoney(r.price) }}<div v-if="Number(r.shipping) > 0" class="text-[8px] text-faint">{{ fmtMoney(r.goods) }} + {{ t('شحن', 'ship') }} {{ fmtMoney(r.shipping) }}</div><div v-if="r.weighted != null" class="text-[8px] text-faint">w {{ r.weighted }}</div></div>
          <div class="num text-violet">{{ r.lead }} {{ t('يوم', 'days') }}</div>
          <div class="num text-[10px]" :class="r.warrantyMonths ? 'text-ink' : 'text-faint'">{{ r.warrantyMonths ? `${r.warrantyMonths} ${t('شهر', 'mo')}` : '—' }}</div>
          <div class="text-[9.5px] font-bold">{{ r.pay || '—' }}{{ r.deliveryTerms ? ` · ${r.deliveryTerms}` : '' }}</div>
          <div class="num ltr text-start" :class="r.moqExceedsNeed ? 'text-bad' : 'text-muted'">{{ fmtNum(r.min) }}</div>
          <div class="text-[9.5px] leading-[1.7] text-muted">
            <div v-for="(x, i) in reasonsOf(r)" :key="i">• {{ x }}</div>
            <div v-if="r.validUntil" class="text-[8.5px]" :class="r.expired ? 'text-bad' : 'text-faint'">{{ t('صالح حتى', 'Valid until') }} <span class="ltr num">{{ fmtDateOnly(r.validUntil) }}</span></div>
          </div>
          <div class="flex justify-end">
            <Chip v-if="isAwarded(r)" fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'تمت الترسية ✓', en: 'Awarded ✓' }" />
            <Btn v-else-if="canAward(r)" size="sm" tone="purple" :label="{ ar: 'ترسية وإنشاء PO', en: 'Award & create PO' }" @click="award = r" />
            <Chip v-else-if="r.status === 'lost'" :map="SQ_LABELS" k="lost" small />
          </div>
        </div>
      </div>
    </div>

    <div v-if="cmp" class="hint mx-[18px] !mt-0 mb-4">{{ t(`التوصية تُحسب آليًا على الإجمالي الواصل (الأصناف + الشحن): السعر 50% · مهلة التوريد 30% · تقييم المورد 20% — الحاجة ${fmtNum(need)} وحدة. اختيار عرض غير الموصى به يتطلب سببًا مكتوبًا. الترسية تُنشئ أمر شراء بانتظار الاعتماد وتُغلق العروض الأخرى.`, `Recommendation is computed on the landed total (goods + shipping): price 50% · lead 30% · supplier score 20% — need ${fmtNum(need)} units. Choosing a non-recommended quote needs a written reason. Awarding creates a PO pending approval and closes the other quotes.`) }}</div>
  </div>

  <AwardDrawer :rfq-number="number" :quote="cmp ? award : null" :default-warehouse="cmp?.rfq.warehouse?.code" :recommended="cmp?.rec" :policy="cmp?.policy" @close="award = null" />
</template>
