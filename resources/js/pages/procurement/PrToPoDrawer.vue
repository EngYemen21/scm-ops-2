<script setup>
// Convert an approved requisition directly to a PO: supplier, dates and one unit price per PR line.
// POST /procurement/pr/:number/to-po → opens the new PO. Kept mounted by the PR tab so the supplier list is ready.
// Price control: every line shows the requisition's estimate beside the new price and the % change. A price above the
// estimate beyond the policy tolerance (procurement.prPriceTolerancePct) needs a written reason — the order is then
// flagged and finance joins its approval chain. The supplier-score limit is the policy too, not a constant.
//   <PrToPoDrawer :pr="prOrNull" @close />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import ScoreOverrideField from './ScoreOverrideField.vue';
import { SELECT_PLACEHOLDER, TERMS_OPTIONS, pctChange, plusDays, pname, usePolicy, useSupplierOptions } from './shared';

const props = defineProps({
  /** Pr — see shared.js; null = closed. */
  pr: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['procurement'] });
const router = useRouter();
const { options: supOpts, suppliers } = useSupplierOptions();
const policy = usePolicy();

const blank = () => ({ supplierCode: '', dueDate: plusDays(7), paymentTerms: '', notes: '', priceReason: '', overrideSupplierScore: false, overrideReason: '' });
const f = ref(blank());
/** sku → unit price (number | null) */
const prices = ref({});
watch(() => props.pr, (pr) => {
  if (!pr) return;
  const prefName = pr.lines[0]?.product?.preferredSupplierName;
  const pref = prefName ? suppliers.value.find((s) => s.nameAr === prefName) : undefined;
  f.value = { ...blank(), supplierCode: pref?.code || '' };
  const p = {};
  pr.lines.forEach((l) => { p[l.product.sku] = l.estPrice != null ? Number(l.estPrice) : l.product.purchasePrice != null ? Number(l.product.purchasePrice) : null; });
  prices.value = p;
  act.clearError();
}, { immediate: true });

const lines = computed(() => props.pr?.lines || []);
const sup = computed(() => suppliers.value.find((s) => s.code === f.value.supplierCode));
const minScore = computed(() => Number(policy.value.minSupplierScore));
const lowScore = computed(() => !!sup.value && sup.value.score < minScore.value);
const tolerance = computed(() => Number(policy.value.prPriceTolerancePct));
/** The requisition's estimate of a line (what the requisition was approved at); null = it had none. */
const estimate = (l) => (l.estPrice != null && Number(l.estPrice) > 0 ? Number(l.estPrice) : null);
const change = (l) => pctChange(prices.value[l.product.sku], estimate(l));
const above = (l) => change(l) != null && change(l) > tolerance.value;
const raised = computed(() => lines.value.filter(above));
const estTotal = computed(() => lines.value.reduce((a, l) => a + l.qty * (estimate(l) || 0), 0));
const total = computed(() => lines.value.reduce((a, l) => a + l.qty * (Number(prices.value[l.product.sku]) || 0), 0));
const reasonMissing = computed(() => raised.value.length > 0 && f.value.priceReason.trim().length < 5);
const ok = computed(() => !!f.value.supplierCode && !!f.value.dueDate && lines.value.every((l) => Number(prices.value[l.product.sku]) > 0) && !reasonMissing.value);

async function submit() {
  const pr = props.pr; const v = f.value;
  const body = {
    supplierCode: v.supplierCode, dueDate: v.dueDate, paymentTerms: v.paymentTerms || undefined, notes: v.notes || undefined,
    prices: pr.lines.map((l) => ({ sku: l.product.sku, price: Number(prices.value[l.product.sku]) })), priceReason: raised.value.length ? v.priceReason.trim() : undefined,
    overrideSupplierScore: v.overrideSupplierScore || undefined, overrideReason: v.overrideReason || undefined,
  };
  const r = await act.run(() => api.postIdempotent(`/procurement/pr/${pr.number}/to-po`, body), {
    success: (po) => (po.priceChanged ? t(`أُنشئ ${po.number} من ${pr.number} — سعر أعلى من التقدير: أُضيف اعتماد المالية`, `${po.number} created from ${pr.number} — priced above the estimate: finance approval added`) : t(`أُنشئ ${po.number} من ${pr.number} — بانتظار الاعتماد`, `${po.number} created from ${pr.number}`)),
  });
  if (r) { emit('close'); router.push(`/po/${encodeURIComponent(r.number)}`); }
}
</script>

<template>
  <ActionDrawer v-if="pr" open :width="620" :title="{ ar: `تحويل ${pr.number} إلى أمر شراء`, en: `Convert ${pr.number} to PO` }" :sub="{ ar: `${pr.warehouse.code} · ${pr.lines.length} بند`, en: `${pr.warehouse.code} · ${pr.lines.length} lines` }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'إنشاء أمر الشراء', en: 'Create PO' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.supplierCode" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ التوريد', en: 'Delivery date' }" required />
      <SelectInput v-model="f.paymentTerms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS_OPTIONS" :placeholder="{ ar: 'حسب المورد', en: 'Supplier default' }" />
      <TextInput v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
      <ScoreOverrideField v-if="lowScore" v-model:checked="f.overrideSupplierScore" v-model:reason="f.overrideReason" :label="t(`تقييم المورد ${fmtNum(sup.score)} دون ${fmtNum(minScore)} — استثناء موثق`, `Score ${fmtNum(sup.score)} < ${fmtNum(minScore)} — documented override`)" />
    </div>

    <div class="mt-3.5 overflow-hidden rounded-xl border border-line-2">
      <div class="le-head grid grid-cols-[minmax(130px,1.6fr)_84px_104px_70px] gap-1.5 bg-soft px-2.5 py-[7px] text-[9.5px] font-extrabold text-muted">
        <div>{{ t('المنتج', 'Product') }}</div><div>{{ t('تقدير الطلب', 'PR estimate') }}</div><div>{{ t('سعر أمر الشراء', 'PO price') }}</div><div>{{ t('التغيّر', 'Change') }}</div>
      </div>
      <div v-for="l in pr.lines" :key="l.id" class="le-row grid grid-cols-[minmax(130px,1.6fr)_84px_104px_70px] items-center gap-1.5 border-t border-line-2 px-2.5 py-2" :class="{ 'bg-[#FFF8F8]': above(l) }">
        <div class="min-w-0"><div class="ellipsis text-[10.5px] font-extrabold">{{ pname(l.product) }}</div><div class="num text-[8.5px] text-faint">{{ l.product.sku }} × {{ fmtNum(l.qty) }}</div></div>
        <div class="num ltr text-[11px] text-muted" :title="t('السعر التقديري الذي اعتُمد عليه طلب الشراء', 'The estimate the requisition was approved at')">{{ estimate(l) != null ? fmtMoney(estimate(l)) : '—' }}</div>
        <NumberInput small :model-value="prices[l.product.sku]" :min="0" :placeholder="{ ar: 'سعر الوحدة', en: 'unit price' }" @update:model-value="(v) => (prices = { ...prices, [l.product.sku]: v })" />
        <div class="num ltr text-[11px] font-extrabold" :class="change(l) == null ? 'text-faint' : above(l) ? 'text-bad' : change(l) > 0 ? 'text-warn' : 'text-ok'">{{ change(l) == null ? '—' : `${change(l) > 0 ? '+' : ''}${fmtNum(change(l), 1)}%` }}</div>
      </div>
      <div class="flex flex-wrap justify-end gap-3 border-t border-line-2 px-2.5 py-2 text-[10.5px] font-extrabold">
        <span class="text-muted">{{ t('تقدير الطلب', 'PR estimate') }}: <span class="num ltr">{{ fmtMoney(estTotal) }}</span></span>
        <span>{{ t('الإجمالي', 'Total') }}: <span class="num ltr">{{ fmtMoney(total) }}</span> {{ t('ر.س', 'SAR') }}</span>
      </div>
    </div>

    <div v-if="raised.length" class="mt-3">
      <div class="hint amber !mt-0">{{ t(`${fmtNum(raised.length)} بند سعره يتجاوز تقدير طلب الشراء المعتمد بأكثر من ${fmtNum(tolerance)}% — اكتب سبب تغيّر السعر. يظهر السبب للمعتمدين ويُضاف اعتماد المالية إلى سلسلة أمر الشراء.`, `${fmtNum(raised.length)} line(s) are priced more than ${fmtNum(tolerance)}% above the approved requisition estimate — write why. Approvers see the reason and finance joins the PO approval chain.`) }}</div>
      <div class="form-grid mt-2">
        <TextArea v-model="f.priceReason" full required :rows="2" :label="{ ar: 'سبب تغيّر السعر', en: 'Reason for the price change' }" :error="reasonMissing && f.priceReason ? t('5 أحرف على الأقل', 'At least 5 characters') : null" :hint="{ ar: 'مثال: ارتفاع سعر المورد بعد اعتماد الطلب، تغيّر العبوة، مورد بديل', en: 'e.g. supplier price rose after approval, pack change, alternative supplier' }" />
      </div>
    </div>
    <div v-else class="hint !mt-3">{{ t(`سعر يتجاوز تقدير الطلب بأكثر من ${fmtNum(tolerance)}% يتطلب سببًا مكتوبًا واعتماد المالية (سياسة المشتريات).`, `A price more than ${fmtNum(tolerance)}% above the requisition estimate needs a written reason and finance approval (procurement policy).`) }}</div>
  </ActionDrawer>
</template>
