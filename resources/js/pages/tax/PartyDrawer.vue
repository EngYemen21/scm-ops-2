<script setup>
// The tax details of one customer or supplier — GET /tax/parties/{type}/{id} and PATCH /tax/parties/{type}/{id}
// (only what changed): fiscal position, default tax code, invoice kind (customers), national address, identification.
// Kept by vatreturn.manage OR einvoice.manage; read-only otherwise. The VAT number and the name stay on the master record.
import { computed, reactive, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { KV, PillChoice, SelectInput, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { INVOICE_KIND, SELECT, changedOnly, nameOf, optionsOf, textOrNull, useTaxLookups } from './tax';

const props = defineProps({
  type: { type: String, default: 'customer' },
  id: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const lk = useTaxLookups();
const q = useGet(() => (props.id ? `/tax/parties/${props.type}/${encodeURIComponent(props.id)}` : null));
const p = computed(() => q.data.value || null);
const canEdit = computed(() => auth.can(['vatreturn.manage', 'einvoice.manage']));
const act = useAction({ invalidate: ['tax'] });
const TEXT = ['street', 'buildingNo', 'additionalNo', 'district', 'city', 'postalCode', 'countryCode', 'idScheme', 'idValue'];
const f = reactive({});
let before = {};
const body = () => ({
  fiscalPositionId: f.fiscalPositionId || null, defaultCodeId: f.defaultCodeId || null, ...(props.type === 'customer' ? { invoiceKind: f.invoiceKind || 'auto' } : {}),
  ...Object.fromEntries(TEXT.map((k) => [k, textOrNull(k === 'countryCode' ? String(f[k] || '').toUpperCase() : f[k])])),
});
watch(p, (x) => {
  if (!x) return;
  Object.assign(f, { fiscalPositionId: x.fiscalPositionId || '', defaultCodeId: x.defaultCodeId || '', invoiceKind: x.invoiceKind || 'auto', ...Object.fromEntries(TEXT.map((k) => [k, x[k] || ''])) });
  before = body();
  act.clearError();
}, { immediate: true });
const codeOptions = computed(() => lk.codeOptions(props.type === 'customer' ? 'sale' : 'purchase'));
const ok = computed(() => (!f.countryCode || /^[A-Za-z]{2}$/.test(f.countryCode)) && (!f.buildingNo || /^\d{4}$/.test(f.buildingNo)) && (!f.postalCode || /^\d{5}$/.test(f.postalCode)));

async function submit() {
  const b = changedOnly(before, body());
  if (!Object.keys(b).length) { emit('close'); return; }
  const r = await act.run(() => api.patch(`/tax/parties/${props.type}/${encodeURIComponent(props.id)}`, b), { success: t('حُفظت البيانات الضريبية', 'Tax details saved') });
  if (r) emit('close');
}
</script>

<template>
  <TaxFormDrawer :open="!!id" :title="p ? nameOf(p) : (type === 'customer' ? { ar: 'عميل', en: 'Customer' } : { ar: 'مورد', en: 'Supplier' })" :sub="p?.code || null"
                 :pending="act.pending.value" :error="act.error.value || q.error.value" :disabled="!ok || !p" :read-only="!canEdit" :z-index="70"
                 :missing="{ ar: 'رمز الدولة حرفان، رقم المبنى 4 أرقام، الرمز البريدي 5 أرقام', en: 'Country code 2 letters, building number 4 digits, postal code 5 digits' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="!p && q.isLoading.value" class="skel h-[260px]" />
    <template v-else-if="p">
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'الرقم الضريبي', en: 'VAT number' }" :v="p.vatNo || t('لا يوجد', 'none')" ltr />
        <KV :k="{ ar: 'السجل التجاري', en: 'CR' }" :v="p.cr || '—'" ltr />
        <KV :k="{ ar: 'العنوان في البطاقة', en: 'Address on the card' }" :v="[p.masterAddress, p.masterCity].filter(Boolean).join(' · ') || '—'" />
        <KV :k="{ ar: 'آخر تعديل', en: 'Last change' }" :v="p.updatedAt ? `${p.updatedBy || '—'} · ${fmtDate(p.updatedAt)}` : t('لم تُحدَّد بعد', 'not set yet')" />
      </div>
      <div v-if="!canEdit" class="hint !mt-0 mb-3">{{ t('للعرض فقط — التعديل لمن يدير الإقرارات أو الفوترة الإلكترونية.', 'Read-only — changed by whoever manages VAT returns or e-invoicing.') }}</div>
      <div class="form-grid">
        <SelectInput v-model="f.fiscalPositionId" :label="{ ar: 'الموقف الضريبي', en: 'Fiscal position' }" :options="lk.positionOptions.value" :placeholder="{ ar: '— بلا موقف —', en: '— none —' }" :disabled="!canEdit" />
        <SelectInput v-model="f.defaultCodeId" :label="{ ar: 'الرمز الضريبي الافتراضي', en: 'Default tax code' }" :options="codeOptions" :placeholder="{ ar: '— الافتراضي العام —', en: '— the general default —' }" :disabled="!canEdit" />
        <PillChoice v-if="type === 'customer' && canEdit" v-model="f.invoiceKind" :label="{ ar: 'نوع الفاتورة الإلكترونية', en: 'E-invoice kind' }" :options="optionsOf(INVOICE_KIND)" full />
        <TextInput v-model="f.buildingNo" :label="{ ar: 'رقم المبنى', en: 'Building number' }" dir="ltr" mono :disabled="!canEdit" />
        <TextInput v-model="f.street" :label="{ ar: 'الشارع', en: 'Street' }" :disabled="!canEdit" />
        <TextInput v-model="f.additionalNo" :label="{ ar: 'الرقم الإضافي', en: 'Additional number' }" dir="ltr" mono :disabled="!canEdit" />
        <TextInput v-model="f.district" :label="{ ar: 'الحي', en: 'District' }" :disabled="!canEdit" />
        <TextInput v-model="f.city" :label="{ ar: 'المدينة', en: 'City' }" :disabled="!canEdit" />
        <TextInput v-model="f.postalCode" :label="{ ar: 'الرمز البريدي', en: 'Postal code' }" dir="ltr" mono :disabled="!canEdit" />
        <TextInput v-model="f.countryCode" :label="{ ar: 'رمز الدولة', en: 'Country code' }" dir="ltr" mono :disabled="!canEdit" />
        <SelectInput v-model="f.idScheme" :label="{ ar: 'نوع المعرّف', en: 'Identification scheme' }" :options="lk.buyerIdSchemes.value" :placeholder="SELECT" :disabled="!canEdit"
                     :hint="{ ar: 'للمشتري بلا رقم ضريبي', en: 'For a buyer without a VAT number' }" />
        <TextInput v-model="f.idValue" :label="{ ar: 'رقم المعرّف', en: 'Identification' }" dir="ltr" mono :disabled="!canEdit" />
      </div>
    </template>
  </TaxFormDrawer>
</template>
