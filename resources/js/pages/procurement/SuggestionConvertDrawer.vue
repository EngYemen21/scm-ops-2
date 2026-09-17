<script setup>
// Purchase suggestion → PO draft / RFQ / PR shortcut. POST /procurement/suggestions/:sku/(po|rfq|pr).
// A PO draft opens right away; the system only suggests — nothing is created without this confirmation.
//   <SuggestionConvertDrawer :conv="{ kind: 'po' | 'rfq' | 'pr', s: suggestion } | null" @close />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction } from '@/api/client';
import { DateInput, NumberInput, SelectInput, optV } from '@/components';
import { fmtNum, t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import { INVITE_RULES, PRIORITY_OPTIONS, SELECT_PLACEHOLDER, plusDays, todayIso, useSupplierOptions, useWarehouseOptions } from './shared';

const props = defineProps({
  conv: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['procurement'] });
const router = useRouter();
const whOpts = useWarehouseOptions();
const { options: supOpts } = useSupplierOptions();

const f = ref({});
watch(() => props.conv, (conv) => {
  if (!conv) return;
  const s = conv.s;
  f.value = { qty: s.sug, warehouseCode: s.warehouse || (whOpts.value[0] ? optV(whOpts.value[0]) : '') || '', supplierCode: s.supplier?.code || '', price: s.price ?? null, dueDate: plusDays(s.lead), closeDate: plusDays(3), invitedRule: 'cat', priority: s.urgent ? 'urgent' : 'normal', needDate: plusDays(s.lead) };
  act.clearError();
}, { immediate: true });

const kind = computed(() => props.conv?.kind);
const title = computed(() => {
  const s = props.conv.s;
  return kind.value === 'po' ? { ar: `أمر شراء — ${s.nameAr}`, en: `PO — ${s.nameEn}` } : kind.value === 'rfq' ? { ar: `RFQ — ${s.nameAr}`, en: `RFQ — ${s.nameEn}` } : { ar: `طلب شراء — ${s.nameAr}`, en: `PR — ${s.nameEn}` };
});
const submitLabel = computed(() => (kind.value === 'po' ? { ar: 'إنشاء مسودة أمر شراء', en: 'Create PO draft' } : kind.value === 'rfq' ? { ar: 'إنشاء RFQ', en: 'Create RFQ' } : { ar: 'إنشاء وإرسال PR', en: 'Create & submit PR' }));

async function submit() {
  if (!props.conv) return;
  const { kind: k, s } = props.conv; const v = f.value;
  const body = k === 'po' ? { qty: Number(v.qty), supplierCode: v.supplierCode || undefined, warehouseCode: v.warehouseCode || undefined, dueDate: v.dueDate || undefined, price: v.price != null ? Number(v.price) : undefined }
    : k === 'rfq' ? { qty: Number(v.qty), closeDate: v.closeDate || undefined, invitedRule: v.invitedRule, deliveryWarehouseCode: v.warehouseCode || undefined }
      : { qty: Number(v.qty), warehouseCode: v.warehouseCode || undefined, needDate: v.needDate || undefined, priority: v.priority };
  const r = await act.run(() => api.postIdempotent(`/procurement/suggestions/${encodeURIComponent(s.sku)}/${k}`, body), {
    success: (d) => (k === 'po' ? t(`أُنشئت مسودة أمر الشراء ${d.number} — أرسلها للاعتماد`, `PO draft ${d.number} created`) : k === 'rfq' ? t(`أُنشئ ${d.number} وأُرسلت الدعوات`, `${d.number} created`) : t(`أُنشئ طلب الشراء ${d.number} وأُرسل للمراجعة`, `PR ${d.number} submitted`)),
  });
  if (r) { emit('close'); if (k === 'po') router.push(`/po/${encodeURIComponent(r.number)}`); }
}
</script>

<template>
  <ActionDrawer v-if="conv" open :width="460" :title="title" :sub="{ ar: `${conv.s.sku} · الكمية المقترحة ${fmtNum(conv.s.sug)}`, en: `${conv.s.sku} · suggested ${fmtNum(conv.s.sug)}` }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!(Number(f.qty) > 0)" :submit-label="submitLabel" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <NumberInput v-model="f.qty" :label="{ ar: 'الكمية', en: 'Quantity' }" required :min="1" />
      <SelectInput v-model="f.warehouseCode" :label="{ ar: 'المستودع', en: 'Warehouse' }" :options="whOpts" :placeholder="SELECT_PLACEHOLDER" />
      <template v-if="kind === 'po'">
        <SelectInput v-model="f.supplierCode" :label="{ ar: 'المورد', en: 'Supplier' }" required :options="supOpts" :placeholder="SELECT_PLACEHOLDER" />
        <NumberInput v-model="f.price" :label="{ ar: 'سعر الوحدة', en: 'Unit price' }" required :min="0" />
        <DateInput v-model="f.dueDate" :label="{ ar: 'تاريخ التوريد', en: 'Delivery date' }" />
      </template>
      <template v-else-if="kind === 'rfq'">
        <DateInput v-model="f.closeDate" :label="{ ar: 'آخر موعد للعروض', en: 'Closing date' }" :min="todayIso()" />
        <SelectInput v-model="f.invitedRule" :label="{ ar: 'الموردون المدعوون', en: 'Invited suppliers' }" :options="INVITE_RULES" />
      </template>
      <template v-else>
        <DateInput v-model="f.needDate" :label="{ ar: 'تاريخ الحاجة', en: 'Need-by' }" />
        <SelectInput v-model="f.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITY_OPTIONS" />
      </template>
    </div>
    <div v-if="kind === 'po' && !conv.s.supplier" class="banner amber mt-3">{{ t('لا يوجد مورد مفضل لهذا المنتج — اختر المورد والسعر، أو حوّل إلى RFQ', 'No preferred supplier — pick supplier and price, or convert to RFQ') }}</div>
  </ActionDrawer>
</template>
