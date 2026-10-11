<script setup>
// Award an RFQ to one quotation and create the PO: POST /procurement/rfq/:number/award { quotation, warehouseCode, dueDate, … }.
// Opens the new PO on success.
//   <AwardDrawer :rfq-number="sel" :quote="comparisonRowOrNull" :default-warehouse="cmp.rfq.warehouse?.code" @close />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, TextInput, optV } from '@/components';
import { fmtMoney, fmtNum, lang, t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import ScoreOverrideField from './ScoreOverrideField.vue';
import { SELECT_PLACEHOLDER, TERMS_OPTIONS, plusDays, useWarehouseOptions } from './shared';

const props = defineProps({
  rfqNumber: { type: String, required: true },
  /** ComparisonRow — see shared.js; null = closed. */
  quote: { type: Object, default: null },
  defaultWarehouse: { type: String, default: null },
  /** Recommended quotation id (choosing another one needs a written reason). */
  recommended: { type: String, default: null },
  /** { minQuotes, received } — fewer quotes than the policy need a written exception. */
  policy: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['procurement'] });
const router = useRouter();
const whOpts = useWarehouseOptions();

const f = ref({ warehouseCode: '', dueDate: '', paymentTerms: '', notes: '', overrideSupplierScore: false, overrideReason: '', awardReason: '', quotesException: '' });
watch(() => props.quote, (q) => {
  if (!q) return;
  f.value = { warehouseCode: props.defaultWarehouse || (whOpts.value[0] ? optV(whOpts.value[0]) : '') || '', dueDate: plusDays(q.lead || 0), paymentTerms: q.pay || '', notes: '', overrideSupplierScore: false, overrideReason: '', awardReason: '', quotesException: '' };
  act.clearError();
}, { immediate: true });

/** The quoted payment terms are offered even when they are not one of the standard options. */
const termOptions = computed(() => {
  const pay = props.quote?.pay;
  return pay && !TERMS_OPTIONS.some((o) => optV(o) === pay) ? [...TERMS_OPTIONS, [pay, pay]] : TERMS_OPTIONS;
});
const reasons = computed(() => (props.quote ? (lang.value === 'ar' ? props.quote.reasons : props.quote.reasonsEn) || [] : []));
const notRecommended = computed(() => !!props.quote && !!props.recommended && props.quote.quotationId !== props.recommended);
const belowPolicy = computed(() => !!props.policy && props.policy.received < props.policy.minQuotes);
const blocked = computed(() => !f.value.warehouseCode || !f.value.dueDate || (f.value.overrideSupplierScore && f.value.overrideReason.trim().length < 3)
  || (notRecommended.value && f.value.awardReason.trim().length < 5) || (belowPolicy.value && f.value.quotesException.trim().length < 5));

async function submit() {
  const v = f.value; const n = props.rfqNumber;
  const body = { quotation: props.quote.number, warehouseCode: v.warehouseCode, dueDate: v.dueDate, paymentTerms: v.paymentTerms || undefined, notes: v.notes || undefined, overrideSupplierScore: v.overrideSupplierScore || undefined, overrideReason: v.overrideReason || undefined,
    awardReason: v.awardReason.trim() || undefined, quotesException: v.quotesException.trim() || undefined };
  const r = await act.run(() => api.postIdempotent(`/procurement/rfq/${n}/award`, body), { success: (d) => t(`رُسّي ${n} — أُنشئ ${d.po.number} بانتظار الاعتماد`, `${n} awarded — ${d.po.number} created`) });
  if (r) { emit('close'); router.push(`/po/${encodeURIComponent(r.po.number)}`); }
}
function close() { act.clearError(); emit('close'); }
</script>

<template>
  <ActionDrawer v-if="quote" open :width="460" :title="{ ar: `ترسية ${rfqNumber} على ${quote.supplier.nameAr}`, en: `Award ${rfqNumber} to ${quote.supplier.nameEn}` }"
                :sub="{ ar: `${quote.number} · ${fmtMoney(quote.price)} ر.س · مهلة ${quote.lead} يوم`, en: `${quote.number} · ${fmtMoney(quote.price)} SAR · lead ${quote.lead} days` }"
                :pending="act.pending.value" :error="act.error.value" :disabled="blocked" :submit-label="{ ar: 'ترسية وإنشاء أمر الشراء', en: 'Award & create PO' }" @submit="submit" @close="close" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.warehouseCode" :label="{ ar: 'مستودع التوريد', en: 'Delivery warehouse' }" required :options="whOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ التوريد', en: 'Delivery date' }" required />
      <SelectInput v-model="f.paymentTerms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="termOptions" :placeholder="{ ar: 'حسب العرض', en: 'As quoted' }" />
      <TextInput v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
      <ScoreOverrideField v-if="quote.score < 65" v-model:checked="f.overrideSupplierScore" v-model:reason="f.overrideReason" :label="t(`تقييم المورد ${fmtNum(quote.score)} دون 65 — استثناء موثق`, `Score ${fmtNum(quote.score)} < 65 — documented override`)" />
      <TextArea v-if="notRecommended" v-model="f.awardReason" :label="{ ar: 'سبب اختيار عرض غير الموصى به', en: 'Why this quote and not the recommended one' }" required :rows="2" full :hint="{ ar: 'يُسجَّل على طلب العروض وفي Audit Trail (5 أحرف على الأقل)', en: 'Recorded on the RFQ and in the audit trail (at least 5 characters)' }" />
      <TextArea v-if="belowPolicy" v-model="f.quotesException" :label="{ ar: `مبرّر الترسية بأقل من ${policy.minQuotes} عروض (المستلم ${policy.received})`, en: `Exception: fewer than ${policy.minQuotes} quotes (${policy.received} received)` }" required :rows="2" full :hint="{ ar: 'مورد وحيد، حالة طارئة، عقد إطاري… — يُسجَّل ويُدقَّق', en: 'Sole source, emergency, framework agreement… — recorded and audited' }" />
    </div>
    <div class="hint !mt-3"><div v-for="(x, i) in reasons" :key="i">• {{ x }}</div></div>
  </ActionDrawer>
</template>
