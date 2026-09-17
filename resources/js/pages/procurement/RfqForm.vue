<script setup>
// Request for quotation. POST /procurement/rfq — suppliers are invited by rule (or picked manually).
//   <RfqForm :open :initial="{ prNumber, lines, deliveryWarehouseCode, notes }" @close @done="(rfq) => …" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { DateInput, SelectInput, TextArea, optL, optV } from '@/components';
import { t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import LinesEditor from './LinesEditor.vue';
import { DASH_PLACEHOLDER, INVITE_RULES_WITH_MANUAL, TERMS_OPTIONS, linesValid, plusDays, todayIso, useSupplierOptions, useWarehouseOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  initial: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const act = useAction();
const whOpts = useWarehouseOptions();
const { options: supOpts } = useSupplierOptions();

const blank = () => ({ invitedRule: 'cat', supplierCodes: [], closeDate: plusDays(3), terms: '', deliveryWarehouseCode: '', notes: '' });
const f = ref(blank());
const lines = ref([]);
watch(() => props.open, (open) => {
  if (!open) return;
  const init = props.initial || {};
  f.value = { ...blank(), deliveryWarehouseCode: init.deliveryWarehouseCode || (whOpts.value[0] ? optV(whOpts.value[0]) : '') || '', notes: init.notes || '' };
  lines.value = init.lines || [];
  act.clearError();
}, { immediate: true });

const ok = computed(() => linesValid(lines.value, false) && !!f.value.closeDate && (f.value.invitedRule !== 'manual' || f.value.supplierCodes.length > 0));
function toggleSupplier(code) {
  const on = f.value.supplierCodes.includes(code);
  f.value.supplierCodes = on ? f.value.supplierCodes.filter((x) => x !== code) : [...f.value.supplierCodes, code];
}

async function submit() {
  const v = f.value;
  const body = { prNumber: props.initial?.prNumber, lines: lines.value.map((l) => ({ sku: l.sku, qty: Number(l.qty) })), invitedRule: v.invitedRule, supplierCodes: v.invitedRule === 'manual' ? v.supplierCodes : undefined, closeDate: v.closeDate, terms: v.terms || undefined, deliveryWarehouseCode: v.deliveryWarehouseCode || undefined, notes: v.notes || undefined };
  const r = await act.run(() => api.postIdempotent('/procurement/rfq', body), { success: (x) => t(`أُرسل ${x.number} إلى ${x._count?.suppliers ?? ''} موردين`, `${x.number} sent to suppliers`), invalidate: ['procurement'] });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <ActionDrawer :open="open" :title="{ ar: 'طلب عروض أسعار RFQ', en: 'Request for quotation' }" :sub="{ ar: 'تُدعى الموردون حسب القاعدة، ثم تُسجَّل عروضهم وتُقارن', en: 'Suppliers are invited by rule; quotations are then recorded and compared' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'إنشاء وإرسال الدعوات', en: 'Create & invite' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <SelectInput v-model="f.invitedRule" :label="{ ar: 'الموردون المدعوون', en: 'Invited suppliers' }" :options="INVITE_RULES_WITH_MANUAL" />
      <DateInput v-model="f.closeDate" :label="{ ar: 'آخر موعد للعروض', en: 'Closing date' }" required :min="todayIso()" />
      <SelectInput v-model="f.terms" :label="{ ar: 'شروط الدفع المطلوبة', en: 'Required payment terms' }" :options="TERMS_OPTIONS" :placeholder="DASH_PLACEHOLDER" />
      <SelectInput v-model="f.deliveryWarehouseCode" :label="{ ar: 'موقع التوريد', en: 'Delivery warehouse' }" :options="whOpts" :placeholder="DASH_PLACEHOLDER" />
      <div v-if="f.invitedRule === 'manual'" class="field full">
        <label class="field-l">{{ t('الموردون', 'Suppliers') }} *</label>
        <div class="row wrap !gap-1.5">
          <button v-for="o in supOpts" :key="optV(o)" type="button" class="pill purple" :class="{ active: f.supplierCodes.includes(optV(o)) }" @click="toggleSupplier(optV(o))">{{ optL(o) }}</button>
        </div>
      </div>
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" :full="false" />
    </div>
    <div class="mt-3.5"><div class="field-l mb-1.5">{{ t('البنود', 'Lines') }} *</div><LinesEditor v-model="lines" /></div>
  </ActionDrawer>
</template>
