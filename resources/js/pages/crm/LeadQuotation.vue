<script setup>
// A quotation for an opportunity. It is a document of the sales module — made through that module's own service, with
// its rules (validity, the discount limit and its approval) — and the opportunity remembers which quotations answer it:
//   POST /crm/leads/{id}/quotations { validUntil, terms?, delivery?, notes?, lines: [{ sku, qty, price, discPct? }] }   needs sales.manage too
//   POST /crm/leads/{id}/quotations { number }       links a quotation already made on the sales screens (same customer)
// The line editor is the sales module's (pages/sales/LinesEditor.vue): one way of entering lines in the whole system.
//   <LeadQuotation :lead="leadOrNull" @close="…" @done="(lead) => …" />      (open while `lead` is set; it needs lead.customer)
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, SelectInput, Tabs, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import LinesEditor from '@/pages/sales/LinesEditor.vue';
import { DELIVERY, TERMS, isoDatePlus, linesBody, newLine, validateLines } from '@/pages/sales/shared';
import { nameOf } from './crm';

const props = defineProps({
  /** The lead (GET /crm/leads/{id}); null closes the drawer. */
  lead: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['crm', 'sales'] });
const isOpen = computed(() => !!props.lead);
const mayCreate = computed(() => auth.can('sales.manage'));
const mode = ref('new');
const modes = computed(() => [{ k: 'new', label: { ar: 'عرض سعر جديد', en: 'New quotation' } }, { k: 'link', label: { ar: 'ربط عرض موجود', en: 'Link an existing one' } }]);

const v = reactive({ validUntil: isoDatePlus(14), terms: '', delivery: '', notes: '' });
const lines = ref([newLine()]);
const number = ref('');
const errors = ref({});
watch(() => props.lead?.id, () => {
  Object.assign(v, { validUntil: isoDatePlus(14), terms: '', delivery: '', notes: '' });
  lines.value = [newLine()]; number.value = ''; errors.value = {}; act.clearError();
  mode.value = mayCreate.value ? 'new' : 'link';
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

async function submit() {
  let body;
  if (mode.value === 'link') {
    if (!number.value.trim()) { errors.value = { number: t('اكتب رقم عرض السعر', 'Type the quotation number') }; return; }
    body = { number: number.value.trim() };
  } else {
    const errs = { ...validateLines(lines.value, true) };
    if (!v.validUntil) errs.validUntil = t('هذا الحقل إلزامي', 'Required');
    errors.value = errs;
    if (Object.keys(errs).length) return;
    body = { validUntil: v.validUntil, terms: v.terms || undefined, delivery: v.delivery || undefined, notes: v.notes || undefined, lines: linesBody(lines.value, true) };
  }
  const r = await act.run(() => api.postIdempotent(`/crm/leads/${props.lead.id}/quotations`, body), {
    success: (l) => (mode.value === 'link' ? t('رُبط عرض السعر بالفرصة', 'Quotation linked to the opportunity') : t(`أُنشئ عرض السعر ${l.quotations?.[0]?.number || ''} كمسودة`, `Quotation ${l.quotations?.[0]?.number || ''} created as a draft`)),
  });
  if (r) { emit('done', r); emit('close'); }
}
</script>

<template>
  <Drawer :open="isOpen" :width="680" :z-index="70" :title="{ ar: 'عرض سعر للفرصة', en: 'Quotation for the opportunity' }"
          :sub="lead ? `${lead.number} · ${t('العميل', 'Customer')}: ${nameOf(lead.customer)} (${lead.customer?.code || '—'})` : null" @close="emit('close')">
    <Tabs v-model="mode" :tabs="modes" variant="pill" />

    <template v-if="mode === 'new'">
      <div v-if="!mayCreate" class="hint amber !mt-0">{{ t('إنشاء عرض سعر يحتاج صلاحية المبيعات (sales.manage). يمكنك ربط عرض موجود برقمه.', 'Creating a quotation needs the sales permission (sales.manage). You can link an existing one by its number.') }}</div>
      <template v-else>
        <div class="form-grid">
          <DateInput v-model="v.validUntil" required :label="{ ar: 'صالح حتى', en: 'Valid until' }" :error="errors.validUntil" />
          <SelectInput v-model="v.terms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS" :placeholder="{ ar: '—', en: '—' }" />
          <SelectInput v-model="v.delivery" :label="{ ar: 'شروط التوصيل', en: 'Delivery terms' }" :options="DELIVERY" :placeholder="{ ar: '—', en: '—' }" />
        </div>
        <div class="mt-3.5">
          <div class="field-l mb-1.5">{{ t('الأسطر', 'Lines') }} *</div>
          <LinesEditor v-model:lines="lines" discount :errors="errors" :customer="lead?.customer?.code || ''" />
        </div>
        <TextArea v-model="v.notes" field-class="mt-3" :label="{ ar: 'ملاحظات', en: 'Notes' }" :placeholder="{ ar: 'تُذكر الفرصة في ملاحظات العرض تلقائيًا إن تُركت فارغة', en: 'Left empty, the quotation notes name the opportunity' }" />
        <div class="hint">{{ t('يُحفظ العرض كمسودة في شاشة المبيعات؛ إرساله للعميل واعتماده وتحويله إلى أمر بيع من هناك. خصم فوق الحد يحتاج اعتماد الخصم قبل الإرسال.', 'The quotation is saved as a draft on the sales screens; sending, approving and converting it to a sales order happen there. A discount above the limit needs discount approval before it is sent.') }}</div>
      </template>
    </template>

    <template v-else>
      <div class="form-grid">
        <TextInput v-model="number" full dir="ltr" scan required :label="{ ar: 'رقم عرض السعر', en: 'Quotation number' }" placeholder="QT-2026-00001" :error="errors.number" @enter="submit" />
      </div>
      <div class="hint">{{ t('يُربط عرض صدر من شاشة المبيعات لعميل هذه الفرصة نفسه؛ العرض الواحد يخص فرصة واحدة.', 'A quotation made on the sales screens for this opportunity’s own customer is linked; one quotation answers one opportunity.') }}</div>
    </template>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="mode === 'new' && !mayCreate" :label="mode === 'link' ? { ar: 'ربط العرض', en: 'Link the quotation' } : { ar: 'حفظ العرض كمسودة', en: 'Save the quotation as a draft' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
