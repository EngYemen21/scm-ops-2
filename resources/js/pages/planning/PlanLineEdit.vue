<script setup>
// Edits one line of a draft purchase plan: quantity (whole units), vendor, order date, note.
//   PATCH /planning/plans/{id}/lines/{line} { qty, vendorId, orderDate, note } — only what changed is sent.
// The vendor is searched in GET /suppliers?q= (the master data list).
//   <PlanLineEdit :plan-id="…" :line="line" @close="…" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, DateInput, ErrorBanner, NumberInput, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { nameOf } from './planning';

const props = defineProps({
  planId: { type: String, required: true },
  line: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const v = reactive({ qty: null, orderDate: '', vendorId: '', note: '' });
watch(() => props.line, (l) => { v.qty = Number(l.qty); v.orderDate = l.orderDate || ''; v.vendorId = l.vendor?.id ? String(l.vendor.id) : ''; v.note = l.note || ''; }, { immediate: true });

const term = ref('');
const suppliers = useList('/suppliers', () => ({ q: term.value || undefined, pageSize: 20, active: 'true' }));
const vendorOptions = computed(() => {
  const rows = (suppliers.data.value?.items || []).map((s) => ({ v: String(s.id), l: `${s.code || ''} · ${nameOf(s)}` }));
  if (props.line.vendor?.id && !rows.some((r) => r.v === String(props.line.vendor.id))) rows.unshift({ v: String(props.line.vendor.id), l: props.line.vendor.name || String(props.line.vendor.id) });
  return rows;
});

const act = useAction({ invalidate: ['planning'] });
async function save() {
  const body = {};
  if (v.qty != null && Number(v.qty) !== Number(props.line.qty)) body.qty = Math.max(1, Math.round(Number(v.qty)));
  if (v.orderDate && v.orderDate !== props.line.orderDate) body.orderDate = v.orderDate;
  if ((v.vendorId || '') !== (props.line.vendor?.id ? String(props.line.vendor.id) : '')) body.vendorId = v.vendorId || null;
  if ((v.note || '') !== (props.line.note || '')) body.note = v.note || null;
  if (!Object.keys(body).length) { emit('close'); return; }
  const r = await act.run(() => api.patch(`/planning/plans/${props.planId}/lines/${props.line.id}`, body), { success: t('حُفظ البند', 'Line saved') });
  if (r) emit('close');
}
</script>

<template>
  <div class="card mb-3 !p-3">
    <div class="card-title mb-1.5">{{ t(`تعديل البند ${line.lineNo} · ${line.sku}`, `Edit line ${line.lineNo} · ${line.sku}`) }}</div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="form-grid">
      <NumberInput v-model="v.qty" :min="1" :step="1" :label="{ ar: 'الكمية', en: 'Quantity' }" />
      <DateInput v-model="v.orderDate" :label="{ ar: 'تاريخ الطلب', en: 'Order date' }" />
      <TextInput small type="search" :model-value="term" :label="{ ar: 'بحث مورد', en: 'Search vendor' }" :placeholder="{ ar: 'الاسم أو الرمز…', en: 'Name or code…' }" @update:model-value="(x) => (term = x)" />
      <SelectInput v-model="v.vendorId" :options="vendorOptions" :label="{ ar: 'المورد', en: 'Vendor' }" :placeholder="{ ar: '— بلا مورد —', en: '— no vendor —' }" />
      <TextInput v-model="v.note" :label="{ ar: 'ملاحظة', en: 'Note' }" full />
    </div>
    <div class="row mt-2 !justify-end">
      <Btn size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      <Btn size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="save" />
    </div>
  </div>
</template>
