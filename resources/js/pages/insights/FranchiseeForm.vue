<script setup>
// Add / edit a franchisee of a brand (brandowner.manage).
//   add:  POST /insights/brands/{owner}/franchisees { customerId (id or code), region?, city?, dateStart?, monthlyTarget?, notes? }
//   edit: PATCH /insights/franchisees/{id} { region?, city?, dateStart?, monthlyTarget?, notes? } (the customer stays)
// Region and city default to the customer's zone and city when left empty. Targets are in SAR, before VAT.
import { reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, KV, NumberInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import { nameOf } from './insights';

const props = defineProps({
  open: { type: Boolean, default: false },
  ownerId: { type: String, required: true },
  /** the franchisee row when editing; null to add */
  franchisee: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const f = reactive({ customer: null, region: '', city: '', dateStart: '', monthlyTarget: null, notes: '' });
const errors = ref({});
const act = useAction();
watch(() => props.open, (v) => {
  if (!v) return;
  const x = props.franchisee;
  Object.assign(f, { customer: null, region: x?.region || '', city: x?.city || '', dateStart: x?.dateStart || '', monthlyTarget: x ? Number(x.monthlyTarget) || 0 : null, notes: x?.notes || '' });
  errors.value = {}; act.clearError();
}, { immediate: true });

async function submit() {
  const errs = {};
  if (!props.franchisee && !f.customer) errs.customerId = t('اختر العميل', 'Pick the customer');
  if (f.monthlyTarget != null && f.monthlyTarget < 0) errs.monthlyTarget = t('الهدف لا يكون سالبًا', 'The target cannot be negative');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = { region: f.region.trim() || null, city: f.city.trim() || null, dateStart: f.dateStart || null, notes: f.notes.trim() || null };
  if (f.monthlyTarget != null) body.monthlyTarget = f.monthlyTarget;
  const res = await act.run(() => (props.franchisee
    ? api.patch(`/insights/franchisees/${encodeURIComponent(props.franchisee.id)}`, body)
    : api.postIdempotent(`/insights/brands/${encodeURIComponent(props.ownerId)}/franchisees`, { customerId: String(f.customer.id), ...body })), {
    success: props.franchisee ? t('حُفظ الفرع', 'Franchisee saved') : t('أُضيف الفرع', 'Franchisee added'), invalidate: ['insights'],
  });
  if (res) { emit('done', res); emit('close'); }
}
watch(() => act.error.value, (e) => { if (e) { const fe = e.fieldErrors(); if (Object.keys(fe).length) errors.value = { ...errors.value, ...fe }; } });
</script>

<template>
  <Drawer :open="open" :title="franchisee ? { ar: 'تعديل الفرع', en: 'Edit franchisee' } : { ar: 'فرع جديد', en: 'New franchisee' }" :sub="franchisee ? `${nameOf(franchisee.customer)} · ${franchisee.customer?.code || ''}` : null" :width="520" :z-index="90" @close="emit('close')">
    <form class="form-grid" @submit.prevent="submit">
      <CustomerPicker v-if="!franchisee" v-model="f.customer" required :label="{ ar: 'العميل', en: 'Customer' }" :error="errors.customerId" />
      <div v-else class="kv-grid full"><KV :k="{ ar: 'العميل', en: 'Customer' }" :v="`${nameOf(franchisee.customer)} · ${franchisee.customer?.code || ''}`" /></div>
      <TextInput v-model="f.region" :label="{ ar: 'المنطقة', en: 'Region' }" :error="errors.region" :hint="franchisee ? null : { ar: 'فارغ = منطقة العميل', en: 'Empty = the customer\'s zone' }" />
      <TextInput v-model="f.city" :label="{ ar: 'المدينة', en: 'City' }" :error="errors.city" :hint="franchisee ? null : { ar: 'فارغ = مدينة العميل', en: 'Empty = the customer\'s city' }" />
      <DateInput v-model="f.dateStart" :label="{ ar: 'تاريخ البداية', en: 'Start date' }" :error="errors.dateStart" :hint="{ ar: 'يدخل الهدف من شهر البداية', en: 'Counts in the target from this month' }" />
      <NumberInput v-model="f.monthlyTarget" :label="{ ar: 'الهدف الشهري (ر.س قبل الضريبة)', en: 'Monthly target (SAR before VAT)' }" :min="0" :error="errors.monthlyTarget" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.notes" />
    </form>
    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
