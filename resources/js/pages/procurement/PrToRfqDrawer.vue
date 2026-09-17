<script setup>
// Convert an approved requisition to an RFQ: PR lines are copied and suppliers invited by rule.
// POST /procurement/pr/:number/to-rfq. `manual` hands over to the full RFQ form (pick suppliers / edit lines).
//   <PrToRfqDrawer :pr="prOrNull" @close @manual="(pr) => …" />
import { ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, SelectInput, TextArea } from '@/components';
import { t } from '@/i18n';
import ActionDrawer from './ActionDrawer.vue';
import { DASH_PLACEHOLDER, INVITE_RULES, TERMS_OPTIONS, plusDays, todayIso } from './shared';

const props = defineProps({
  /** Pr — see shared.js; null = closed. */
  pr: { type: Object, default: null },
});
const emit = defineEmits(['close', 'manual']);

const act = useAction({ invalidate: ['procurement'] });
const f = ref({ closeDate: plusDays(3), invitedRule: 'cat', terms: '', notes: '' });
watch(() => props.pr, (pr) => {
  if (!pr) return;
  f.value = { closeDate: plusDays(3), invitedRule: 'cat', terms: '', notes: pr.justification || '' };
  act.clearError();
}, { immediate: true });

async function submit() {
  const pr = props.pr; const v = f.value;
  const r = await act.run(() => api.postIdempotent(`/procurement/pr/${pr.number}/to-rfq`, { closeDate: v.closeDate, invitedRule: v.invitedRule, terms: v.terms || undefined, deliveryWarehouseCode: pr.warehouse.code, notes: v.notes || undefined }),
    { success: (x) => t(`أُنشئ ${x.number} من ${pr.number}`, `${x.number} created from ${pr.number}`) });
  if (r) emit('close');
}
</script>

<template>
  <ActionDrawer v-if="pr" open :width="460" :title="{ ar: `تحويل ${pr.number} إلى RFQ`, en: `Convert ${pr.number} to RFQ` }" :sub="{ ar: 'تُنسخ بنود الطلب وتُدعى الموردون حسب القاعدة', en: 'PR lines are copied and suppliers invited by rule' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!f.closeDate" :submit-label="{ ar: 'إنشاء RFQ وإرسال الدعوات', en: 'Create RFQ & invite' }" @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <DateInput v-model="f.closeDate" :label="{ ar: 'آخر موعد للعروض', en: 'Closing date' }" required :min="todayIso()" />
      <SelectInput v-model="f.invitedRule" :label="{ ar: 'الموردون المدعوون', en: 'Invited suppliers' }" :options="INVITE_RULES" />
      <SelectInput v-model="f.terms" :label="{ ar: 'شروط الدفع المطلوبة', en: 'Payment terms' }" :options="TERMS_OPTIONS" :placeholder="DASH_PLACEHOLDER" />
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" :full="false" />
    </div>
    <div class="mt-2.5"><Btn tone="ghost" size="sm" :label="{ ar: 'اختيار الموردين يدويًا / تعديل البنود ←', en: 'Choose suppliers manually / edit lines →' }" @click="emit('manual', pr)" /></div>
  </ActionDrawer>
</template>
