<script setup>
// Convert an approved requisition directly to a PO: supplier, dates and one unit price per PR line.
// POST /procurement/pr/:number/to-po → opens the new PO. Kept mounted by the PR tab so the supplier list is ready.
//   <PrToPoDrawer :pr="prOrNull" @close />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, SelectInput, TextInput } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import ScoreOverrideField from './ScoreOverrideField.vue';
import { SELECT_PLACEHOLDER, TERMS_OPTIONS, plusDays, pname, useSupplierOptions } from './shared';

const props = defineProps({
  /** Pr — see shared.js; null = closed. */
  pr: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['procurement'] });
const router = useRouter();
const { options: supOpts, suppliers } = useSupplierOptions();

const blank = () => ({ supplierCode: '', dueDate: plusDays(7), paymentTerms: '', notes: '', overrideSupplierScore: false, overrideReason: '' });
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
const total = computed(() => lines.value.reduce((a, l) => a + l.qty * (Number(prices.value[l.product.sku]) || 0), 0));
const ok = computed(() => !!f.value.supplierCode && !!f.value.dueDate && lines.value.every((l) => Number(prices.value[l.product.sku]) > 0));

async function submit() {
  const pr = props.pr; const v = f.value;
  const body = { supplierCode: v.supplierCode, dueDate: v.dueDate, paymentTerms: v.paymentTerms || undefined, notes: v.notes || undefined, prices: pr.lines.map((l) => ({ sku: l.product.sku, price: Number(prices.value[l.product.sku]) })), overrideSupplierScore: v.overrideSupplierScore || undefined, overrideReason: v.overrideReason || undefined };
  const r = await act.run(() => api.postIdempotent(`/procurement/pr/${pr.number}/to-po`, body), { success: (po) => t(`أُنشئ ${po.number} من ${pr.number} — بانتظار الاعتماد`, `${po.number} created from ${pr.number}`) });
  if (r) { emit('close'); router.push(`/po/${encodeURIComponent(r.number)}`); }
}
</script>

<template>
  <ActionDrawer v-if="pr" open :title="{ ar: `تحويل ${pr.number} إلى أمر شراء`, en: `Convert ${pr.number} to PO` }" :sub="{ ar: `${pr.warehouse.code} · ${pr.lines.length} بند`, en: `${pr.warehouse.code} · ${pr.lines.length} lines` }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'إنشاء أمر الشراء', en: 'Create PO' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.supplierCode" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supOpts" :placeholder="SELECT_PLACEHOLDER" />
      <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ التوريد', en: 'Delivery date' }" required />
      <SelectInput v-model="f.paymentTerms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS_OPTIONS" :placeholder="{ ar: 'حسب المورد', en: 'Supplier default' }" />
      <TextInput v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" />
      <ScoreOverrideField v-if="sup && sup.score < 65" v-model:checked="f.overrideSupplierScore" v-model:reason="f.overrideReason" :label="t(`تقييم المورد ${fmtNum(sup.score)} دون 65 — استثناء موثق`, `Score ${fmtNum(sup.score)} < 65 — documented override`)" />
    </div>
    <div class="mt-3.5 overflow-hidden rounded-xl border border-line-2">
      <div v-for="l in pr.lines" :key="l.id" class="row border-t border-line-2 px-2.5 py-2">
        <div class="grow"><div class="text-[10.5px] font-extrabold">{{ pname(l.product) }}</div><div class="num text-[8.5px] text-faint">{{ l.product.sku }} × {{ fmtNum(l.qty) }}</div></div>
        <div class="w-[110px] flex-none"><NumberInput small :model-value="prices[l.product.sku]" :min="0" :placeholder="{ ar: 'سعر الوحدة', en: 'unit price' }" @update:model-value="(v) => (prices = { ...prices, [l.product.sku]: v })" /></div>
      </div>
      <div class="border-t border-line-2 px-2.5 py-2 text-end text-[11px] font-extrabold">{{ t('الإجمالي', 'Total') }}: <span class="num">{{ fmtMoney(total) }}</span> {{ t('ر.س', 'SAR') }}</div>
    </div>
  </ActionDrawer>
</template>
