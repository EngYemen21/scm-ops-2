<script setup>
// Supplier quotation comparison of one RFQ (GET /procurement/rfq/:number/comparison): one row per quotation, the best
// composite index first. Five blocks instead of nine loose columns, so every value sits with what explains it:
// supplier (score, flags) · price (unit + landed total) · supply (lead, warranty) · terms (payment, delivery, MOQ) ·
// the system's assessment (composite index 50% price · 30% lead · 20% supplier score, reasons, validity) · award.
// Actions: record a quote, cancel the RFQ, award → PO.
//   <RfqComparison :number="sel" @close @quote="({ rfqNumber }) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
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
const scoreText = (s) => fmtNum(s, Number.isInteger(Number(s)) ? 0 : 1);

/** Awarded first, then by composite index (lower = better); quotes the system could not rank (incomplete / expired) last. */
const rows = computed(() => [...(cmp.value?.quotes || [])].sort((a, b) =>
  Number(isAwarded(b)) - Number(isAwarded(a)) || (a.weighted ?? Infinity) - (b.weighted ?? Infinity) || String(a.number).localeCompare(String(b.number))));

const columns = [
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(200px,1.4fr)' },
  { key: 'price', header: { ar: 'السعر', en: 'Price' }, width: '140px' },
  { key: 'supply', header: { ar: 'التوريد', en: 'Supply' }, width: '120px' },
  { key: 'terms', header: { ar: 'الشروط', en: 'Terms' }, width: 'minmax(150px,1fr)' },
  { key: 'eval', header: { ar: 'تقييم النظام', en: 'System assessment' }, width: 'minmax(240px,1.6fr)' },
  { key: 'actions', header: '', width: '150px', bare: true },
];
const rowStyle = (r) => (r.rec ? { background: '#F6FDF8' } : isAwarded(r) ? { background: '#FAF8FE' } : null);

/** Three-quotes policy: fewer received → the award needs a written exception. */
const belowPolicy = computed(() => !!cmp.value?.policy && cmp.value.policy.received < cmp.value.policy.minQuotes);

async function cancelRfq() {
  const n = props.number;
  if (!(await confirm({ title: { ar: `إلغاء ${n}؟`, en: `Cancel ${n}?` } }))) return;
  void act.run(() => api.postIdempotent(`/procurement/rfq/${n}/cancel`, {}), { success: t(`أُلغي ${n}`, `${n} cancelled`) });
}
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

    <DataTable v-if="cmp && cmp.quotes.length > 0" :columns="columns" :rows="rows" :row-key="(r) => r.quotationId" :row-style="rowStyle" :zebra="false">
      <!-- supplier: who, which quote, how the system rates them -->
      <template #cell-supplier="{ row: r }">
        <div class="text-[11px] font-extrabold text-ink">{{ sname(r.supplier) }}</div>
        <div class="cell-sub">{{ r.number }}<template v-if="r.supplierRef"> · {{ r.supplierRef }}</template></div>
        <div class="row wrap mt-1.5 !gap-x-2 !gap-y-1">
          <Chip v-if="r.rec" small fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'توصية النظام', en: 'Recommended' }" />
          <Chip v-if="r.supplier.isNew" small fg="#8a5f10" bg="#FBF0DD" :label="{ ar: 'مورد جديد', en: 'New supplier' }" />
          <span class="text-[8.5px] font-bold text-faint">{{ t('تقييم المورد', 'Supplier score') }} <span class="num text-[9.5px] text-muted">{{ scoreText(r.score) }}</span><template v-if="Number(r.supplier.otif) > 0"> · OTIF <span class="num text-[9.5px] text-muted">{{ fmtNum(r.supplier.otif) }}%</span></template></span>
        </div>
      </template>

      <!-- price: the unit price people quote, the landed total the recommendation is computed on -->
      <template #cell-price="{ row: r }">
        <div class="num text-[13px] font-bold leading-tight text-ink">{{ fmtMoney(r.unitPrice) }}</div>
        <div class="text-[8px] font-bold text-faint">{{ t('للوحدة', 'per unit') }}</div>
        <div class="num mt-1.5 text-[11px] font-bold text-muted">{{ fmtMoney(r.price) }}</div>
        <div class="text-[8px] font-bold text-faint">{{ Number(r.shipping) > 0 ? t('إجمالي واصل · شحن', 'landed · ship') + ' ' + fmtMoney(r.shipping) : t('الإجمالي', 'total') }}</div>
      </template>

      <!-- supply: how fast, how covered -->
      <template #cell-supply="{ row: r }">
        <div class="num text-[13px] font-bold leading-tight text-violet">{{ fmtNum(r.lead) }} <span class="font-sans text-[8.5px]">{{ t('يوم', 'days') }}</span></div>
        <div class="text-[8px] font-bold text-faint">{{ t('مهلة التوريد', 'lead time') }}</div>
        <div class="mt-1.5 text-[9.5px] font-bold" :class="r.warrantyMonths ? 'text-ink' : 'text-faint'">{{ r.warrantyMonths ? t(`ضمان ${fmtNum(r.warrantyMonths)} شهرًا`, `${fmtNum(r.warrantyMonths)}-month warranty`) : t('بلا ضمان مُعلن', 'No warranty stated') }}</div>
      </template>

      <!-- terms: payment · delivery · MOQ against the need -->
      <template #cell-terms="{ row: r }">
        <div class="text-[10px] font-extrabold" :class="r.pay ? 'text-ink' : 'text-faint'">{{ r.pay || t('شروط الدفع غير محددة', 'Payment terms not stated') }}</div>
        <div v-if="r.deliveryTerms" class="mt-0.5 text-[9px] font-bold text-muted">{{ r.deliveryTerms }}</div>
        <div class="mt-1 text-[9px] font-bold" :class="r.moqExceedsNeed ? 'text-bad' : r.min ? 'text-muted' : 'text-faint'">
          <template v-if="r.min">{{ t('حد أدنى للطلب', 'MOQ') }} <span class="num text-[10px]">{{ fmtNum(r.min) }}</span><template v-if="r.moqExceedsNeed"> — {{ t(`يتجاوز الحاجة (${fmtNum(need)})`, `exceeds the need (${fmtNum(need)})`) }}</template></template>
          <template v-else>{{ t('بلا حد أدنى للطلب', 'No minimum order') }}</template>
        </div>
      </template>

      <!-- assessment: the composite index, why, and until when the quote holds -->
      <template #cell-eval="{ row: r }">
        <div v-if="r.weighted != null" class="mb-1 text-[9px] font-extrabold" :class="r.rec ? 'text-ok' : 'text-muted'">{{ t('المؤشر المركّب', 'Composite index') }} <span class="num text-[10.5px]">{{ fmtNum(r.weighted, 2) }}</span><span v-if="r.rec" class="font-bold text-faint"> · {{ t('الأفضل', 'best') }}</span></div>
        <div v-else class="mb-1 text-[9px] font-extrabold text-faint">{{ t('خارج الترتيب', 'Not ranked') }}</div>
        <ul class="m-0 list-disc ps-3.5 text-[9.5px] leading-[1.7] text-muted"><li v-for="(x, i) in reasonsOf(r)" :key="i">{{ x }}</li></ul>
        <div v-if="r.validUntil" class="mt-1 text-[8.5px] font-bold" :class="r.expired ? 'text-bad' : 'text-faint'">{{ r.expired ? t('انتهت صلاحية العرض في', 'Quote expired on') : t('صالح حتى', 'Valid until') }} <span class="num">{{ fmtDateOnly(r.validUntil) }}</span></div>
      </template>

      <template #cell-actions="{ row: r }">
        <div class="flex">
          <Chip v-if="isAwarded(r)" fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'تمت الترسية ✓', en: 'Awarded ✓' }" />
          <Btn v-else-if="canAward(r)" size="sm" tone="purple" :label="{ ar: 'ترسية وإنشاء PO', en: 'Award & create PO' }" @click="award = r" />
          <Chip v-else-if="r.status === 'lost'" :map="SQ_LABELS" k="lost" small />
          <span v-else-if="r.expired" class="text-[9px] font-extrabold text-bad">{{ t('منتهي الصلاحية', 'Expired') }}</span>
          <span v-else-if="!r.complete" class="text-[9px] font-extrabold text-faint">{{ t('عرض غير مكتمل', 'Incomplete quote') }}</span>
        </div>
      </template>
    </DataTable>

    <div v-if="cmp" class="hint mx-[18px] !mt-0 mb-4">{{ t(`التوصية تُحسب آليًا على الإجمالي الواصل (الأصناف + الشحن): السعر 50% · مهلة التوريد 30% · تقييم المورد 20% — الحاجة ${fmtNum(need)} وحدة. المؤشر المركّب 1.00 يعني الأفضل في كل معيار، والأقل أفضل. اختيار عرض غير الموصى به يتطلب سببًا مكتوبًا. الترسية تُنشئ أمر شراء بانتظار الاعتماد وتُغلق العروض الأخرى.`, `Recommendation is computed on the landed total (goods + shipping): price 50% · lead 30% · supplier score 20% — need ${fmtNum(need)} units. A composite index of 1.00 means best on every criterion; lower is better. Choosing a non-recommended quote needs a written reason. Awarding creates a PO pending approval and closes the other quotes.`) }}</div>
  </div>

  <AwardDrawer :rfq-number="number" :quote="cmp ? award : null" :default-warehouse="cmp?.rfq.warehouse?.code" :recommended="cmp?.rec" :policy="cmp?.policy" @close="award = null" />
</template>
