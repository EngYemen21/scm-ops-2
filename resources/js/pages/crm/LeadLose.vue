<script setup>
// Closing an opportunity as lost: the reason (one of the section's lost reasons) and a line of what happened.
// POST /crm/leads/{id}/lose { reasonId, note }. What was still planned for the lead is cancelled by the server.
//   <LeadLose :lead="leadOrNull" @close="…" @done="(lead) => …" />      (open while `lead` is set)
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { useCrmLookups } from './crm';

const props = defineProps({
  /** The lead to close: `{ id, number, name }`; null closes the dialog. */
  lead: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const { reasonOptions } = useCrmLookups();
const fields = computed(() => [
  { k: 'reasonId', label: { ar: 'سبب الخسارة', en: 'Lost reason' }, type: 'select', required: true, full: true, opts: reasonOptions.value },
  { k: 'note', label: { ar: 'ما الذي حدث؟', en: 'What happened?' }, type: 'area', ph: { ar: 'مثال: اختار موردًا يقدّم سعرًا أقل للأجبان', en: 'e.g. chose a supplier with a lower price for cheese' } },
]);
const title = computed(() => (props.lead ? { ar: `خسارة الفرصة ${props.lead.number}`, en: `Mark ${props.lead.number} as lost` } : null));
const submit = (values) => api.postIdempotent(`/crm/leads/${props.lead.id}/lose`, values);
const action = computed(() => ({ success: t('سُجّلت الفرصة خاسرة', 'Marked as lost'), invalidate: ['crm'] }));
</script>

<template>
  <FormDrawer :open="!!lead" :width="460" :title="title" :sub="lead ? lead.name : null" :fields="fields" :submit="submit" :action="action" :submit-label="{ ar: 'تسجيل الخسارة', en: 'Mark as lost' }"
              :note="{ ar: '* السبب إلزامي · تُلغى الأنشطة المفتوحة على الفرصة · يمكن إعادة فتحها لاحقًا', en: '* a reason is required · the open to-dos of the opportunity are cancelled · it can be reopened later' }"
              @close="emit('close')" @done="(l) => emit('done', l)">
    <template v-if="!reasonOptions.length" #before>
      <div class="hint amber !mt-0 mb-3">{{ t('لا أسباب خسارة معرّفة بعد — أضف سببًا من «إعدادات المسار».', 'No lost reason is defined yet — add one in "Pipeline setup".') }}</div>
    </template>
  </FormDrawer>
</template>
