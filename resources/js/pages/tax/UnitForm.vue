<script setup>
// An e-invoicing unit (einvoice.manage) — POST /tax/units · PATCH /tax/units/{id} (only what changed).
// Its identity (name, serial, environment, invoice types, branch, location, industry) is part of its certificate: it is
// edited only before a certificate exists (not_onboarded / csr_ready); the sending switch and "default" stay open.
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { PillChoice, TextInput } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { changedOnly, textOrNull, useTaxLookups } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The unit to edit; null makes a new one. */
  unit: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const TYPES = [{ v: '1100', l: { ar: 'قياسية ومبسطة', en: 'Standard and simplified' } }, { v: '1000', l: { ar: 'قياسية فقط', en: 'Standard only' } }, { v: '0100', l: { ar: 'مبسطة فقط', en: 'Simplified only' } }];
const YES_NO = [{ v: 'yes', l: { ar: 'نعم', en: 'Yes' } }, { v: 'no', l: { ar: 'لا', en: 'No' } }];
const f = reactive({});
let before = {};
const initial = (u) => ({
  name: u?.name || '', serial: u?.serial || '', environment: u?.environment || 'sandbox', invoiceTypes: u?.invoiceTypes || '1100',
  branchName: u?.branchName || '', location: u?.location || '', industry: u?.industry || '', sending: u ? (u.sending ? 'yes' : 'no') : 'yes', isDefault: u?.isDefault ? 'yes' : 'no',
});
watch(() => props.open, (open) => { if (open) { before = initial(props.unit); Object.assign(f, before); act.clearError(); } }, { immediate: true });

const identityOpen = computed(() => !props.unit || ['not_onboarded', 'csr_ready'].includes(props.unit.status));
const ok = computed(() => f.name.trim() !== '' && ['1100', '1000', '0100'].includes(f.invoiceTypes));
const identity = () => ({ name: f.name.trim(), serial: textOrNull(f.serial), environment: f.environment, invoiceTypes: f.invoiceTypes, branchName: textOrNull(f.branchName), location: textOrNull(f.location), industry: textOrNull(f.industry) });

async function submit() {
  if (props.unit) {
    const was = { ...before, serial: textOrNull(before.serial), branchName: textOrNull(before.branchName), location: textOrNull(before.location), industry: textOrNull(before.industry) };
    const body = changedOnly(was, { ...(identityOpen.value ? identity() : {}), sending: f.sending, isDefault: f.isDefault });
    if ('sending' in body) body.sending = body.sending === 'yes';
    if ('isDefault' in body) { if (body.isDefault === 'yes') body.isDefault = true; else delete body.isDefault; }
    if (!Object.keys(body).length) { emit('close'); return; }
    const r = await act.run(() => api.patch(`/tax/units/${encodeURIComponent(props.unit.id)}`, body), { success: t('حُفظت الوحدة', 'Unit saved') });
    if (r) emit('close');
    return;
  }
  const r = await act.run(() => api.postIdempotent('/tax/units', identity()), { success: (u) => t(`أُنشئت الوحدة ${u?.name}`, `Unit ${u?.name} created`) });
  if (!r) return;
  if (f.isDefault === 'yes') await act.run(() => api.patch(`/tax/units/${encodeURIComponent(r.id)}`, { isDefault: true }), { success: false });
  emit('close');
}
</script>

<template>
  <TaxFormDrawer :open="open" :title="unit ? { ar: `تعديل ${unit.name}`, en: `Edit ${unit.name}` } : { ar: 'وحدة فوترة إلكترونية جديدة', en: 'New e-invoicing unit' }"
                 :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="{ ar: 'اسم الوحدة إلزامي', en: 'The unit needs a name' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div v-if="unit && !identityOpen" class="banner teal"><div>{{ t('بيانات الوحدة داخلة في شهادتها — لا تُعدَّل بعد إصدار الشهادة. يمكن هنا إيقاف الإرسال أو تشغيله وجعلها الافتراضية.', 'The unit\'s data is part of its certificate — it is not changed once a certificate exists. Here you can pause or resume sending and make it the default.') }}</div></div>
    <div v-if="unit?.status === 'csr_ready' && identityOpen" class="hint amber !mt-0 mb-3">{{ t('تعديل بيانات الوحدة يُلغي طلب الشهادة الحالي ويُنشأ من جديد.', 'Changing the unit\'s data drops the current certificate request; it is made again.') }}</div>
    <div class="form-grid">
      <template v-if="identityOpen">
        <TextInput v-model="f.name" :label="{ ar: 'اسم الوحدة', en: 'Unit name' }" required dir="auto" />
        <TextInput v-model="f.serial" :label="{ ar: 'الرقم التسلسلي للجهاز', en: 'Device serial' }" dir="ltr" mono :hint="{ ar: 'بالصيغة 1-المصنّع|2-الطراز|3-الرقم — يُترك فارغًا ليُولَّد', en: 'Form 1-maker|2-model|3-serial — leave empty to have it generated' }" />
        <PillChoice v-model="f.environment" :label="{ ar: 'البيئة', en: 'Environment' }" :options="lk.environments.value" full />
        <PillChoice v-model="f.invoiceTypes" :label="{ ar: 'أنواع الفواتير', en: 'Invoice types' }" :options="TYPES" full />
        <TextInput v-model="f.branchName" :label="{ ar: 'اسم الفرع', en: 'Branch name' }" dir="auto" />
        <TextInput v-model="f.location" :label="{ ar: 'الموقع', en: 'Location' }" dir="auto" />
        <TextInput v-model="f.industry" :label="{ ar: 'النشاط', en: 'Industry' }" dir="auto" />
      </template>
      <PillChoice v-if="unit" v-model="f.sending" :label="{ ar: 'الإرسال إلى الهيئة', en: 'Sending to the authority' }" :options="YES_NO" :hint="{ ar: 'لا يُرسل شيء قبل ربط الوحدة، مهما كان هذا الخيار', en: 'Nothing is sent before the unit is onboarded, whatever this says' }" />
      <PillChoice v-if="!unit?.isDefault" v-model="f.isDefault" :label="{ ar: 'الوحدة الافتراضية', en: 'Default unit' }" :options="YES_NO" />
    </div>
  </TaxFormDrawer>
</template>
