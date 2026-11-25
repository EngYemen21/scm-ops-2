<script setup>
// From a won opportunity to an OPS customer — and never a second customer for a company OPS already has.
// GET /crm/leads/{id}/customer?vatNo= answers what identity says: `match` (the customer its Odoo partner is linked to,
// or the one that carries the VAT number) — then there is nothing to decide — or `candidates` (same VAT number on
// several branches, same name, same phone) for a person to recognise. Otherwise the person says it:
//   POST /crm/leads/{id}/convert {}                          identity names the customer
//   POST … { customerId }                                    "it is this existing customer"
//   POST … { confirmNew: true, nameAr, zone, contact, … }    "it is new" — needs customer.manage as well
// The server decides again when it is asked (a VAT number that names a customer wins over "it is new").
//   <ConvertDrawer :lead="leadOrNull" @close="…" @done="(lead) => …" />      (open while `lead` is set)
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, NumberInput, SelectInput, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { CASH_TERMS, TERMS, customerOptions, useLookups } from '@/pages/sales/shared';
import { CANDIDATE_WHY, MATCH_BY, TONE, nameOf } from './crm';

const props = defineProps({
  /** The won lead: `{ id, number, name }`; null closes the drawer. */
  lead: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['crm', 'customers', 'master'] });
const isOpen = computed(() => !!props.lead);
const mayCreate = computed(() => auth.can('customer.manage'));

/** The VAT number being typed reaches the preview a moment after the last key. */
const vatText = ref('');
const vatAsked = ref('');
let vatTimer = null;
function typeVat(value) { vatText.value = value; clearTimeout(vatTimer); vatTimer = setTimeout(() => { vatAsked.value = String(vatText.value || '').trim(); }, 450); }
onBeforeUnmount(() => clearTimeout(vatTimer));

const preview = useGet(() => (props.lead ? `/crm/leads/${props.lead.id}/customer` : null), () => ({ vatNo: vatAsked.value || undefined }));
const p = computed(() => (isOpen.value ? preview.data.value || null : null));
const master = useLookups();
const everyCustomer = computed(() => customerOptions(master.data.value));

/** What the person chose: a candidate's id · 'other' (picked from every customer) · 'new'. */
const choice = ref('');
const otherCode = ref('');
const nw = reactive({ nameAr: '', city: '', zone: '', contact: '', cr: '', terms: CASH_TERMS, creditLimit: null });
const errors = ref({});
watch(() => props.lead?.id, () => { choice.value = ''; otherCode.value = ''; vatText.value = ''; vatAsked.value = ''; errors.value = {}; act.clearError(); });
// the new-customer form starts from what the lead holds
watch(() => p.value?.suggested, (s) => {
  if (!s || nw.nameAr || nw.zone || nw.contact) return;
  Object.assign(nw, { nameAr: s.nameAr || '', city: s.city || '', zone: s.zone || '', contact: s.contact || '', cr: '', terms: CASH_TERMS, creditLimit: null });
  if (!vatText.value && s.vatNo) vatText.value = s.vatNo;
});
watch(isOpen, (o) => { if (!o) Object.assign(nw, { nameAr: '', city: '', zone: '', contact: '', cr: '', terms: CASH_TERMS, creditLimit: null }); });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const credit = computed(() => nw.terms && nw.terms !== CASH_TERMS);
const ready = computed(() => {
  if (!p.value?.allowed) return false;
  if (p.value.match) return true;
  if (choice.value === 'new') return mayCreate.value && !!nw.nameAr.trim() && !!nw.zone.trim() && !!nw.contact.trim() && (!credit.value || Number(nw.creditLimit) > 0);
  if (choice.value === 'other') return !!otherCode.value;
  return !!choice.value;
});
const submitLabel = computed(() => (p.value?.match ? { ar: 'ربط الفرصة بهذا العميل', en: 'Link the opportunity to this customer' }
  : choice.value === 'new' ? { ar: 'إنشاء العميل وربط الفرصة به', en: 'Create the customer and link it' } : { ar: 'ربط الفرصة بالعميل المختار', en: 'Link to the chosen customer' }));

async function submit() {
  const vatNo = String(vatText.value || '').trim() || undefined;
  let body = {};
  if (!p.value.match) {
    if (choice.value === 'new') body = { confirmNew: true, vatNo, nameAr: nw.nameAr.trim(), city: nw.city.trim() || undefined, zone: nw.zone.trim(), contact: nw.contact.trim(), cr: nw.cr.trim() || undefined, terms: nw.terms || undefined, creditLimit: credit.value ? Number(nw.creditLimit) : undefined };
    else body = { customerId: choice.value === 'other' ? otherCode.value : choice.value, vatNo };
  } else if (vatNo) body = { vatNo };
  const r = await act.run(() => api.postIdempotent(`/crm/leads/${props.lead.id}/convert`, body), {
    success: (l) => (l.conversion?.by === 'new'
      ? t(`أُنشئ العميل ${l.conversion.customer.code} ورُبطت به الفرصة`, `Customer ${l.conversion.customer.code} created and linked`)
      : t(`رُبطت الفرصة بالعميل ${l.conversion?.customer?.code || ''} — ${bi(MATCH_BY[l.conversion?.by] || MATCH_BY.person)}`, `Linked to customer ${l.conversion?.customer?.code || ''} — ${MATCH_BY[l.conversion?.by]?.en || ''}`)),
  });
  if (r) { emit('done', r); emit('close'); }
}
const why = (c) => (c.why || []).map((k) => bi(CANDIDATE_WHY[k] || { ar: k, en: k })).join(' · ');
</script>

<template>
  <Drawer :open="isOpen" :width="560" :z-index="70" :title="{ ar: 'تحويل الفرصة إلى عميل', en: 'Opportunity to customer' }" :sub="lead ? `${lead.number} · ${lead.name}` : null" @close="emit('close')">
    <ErrorBanner :error="preview.error.value" :closable="false" />
    <div v-if="preview.isLoading.value" class="skel h-[140px]" />
    <template v-else-if="p">
      <div v-if="!p.allowed" class="hint amber !mt-0">{{ t(p.reasonAr, p.reasonEn) }}</div>

      <!-- identity names the customer: nothing to decide -->
      <template v-else-if="p.match">
        <div class="hint green !mt-0">
          <div class="text-[11.5px] font-extrabold">{{ t('العميل معروف — لن يُنشأ عميل جديد', 'The customer is known — no new customer is made') }}</div>
          <div class="mt-1">{{ nameOf(p.match.customer) }} <span class="num ltr">({{ p.match.customer.code }})</span> — {{ bi(MATCH_BY[p.match.by] || MATCH_BY.person) }}</div>
        </div>
      </template>

      <template v-else>
        <div class="hint !mt-0">{{ t('لم يُعرف العميل برابط ولا برقم ضريبي. اكتب الرقم الضريبي إن وُجد، أو حدّد العميل الموجود الذي تخصه هذه الفرصة، أو أكّد أنه عميل جديد.', 'No link and no VAT number name the customer. Type the VAT number if there is one, pick the existing customer this opportunity belongs to, or confirm that it is a new one.') }}</div>
        <div class="form-grid mt-3">
          <TextInput :model-value="vatText" dir="ltr" full :label="{ ar: 'الرقم الضريبي للمنشأة', en: 'VAT number of the company' }" :hint="{ ar: 'إن كان لعميل موجود رُبطت به الفرصة تلقائيًا', en: 'If an existing customer carries it, the opportunity is linked to that customer' }" @update:model-value="typeVat" />
        </div>

        <div v-if="p.candidates.length" class="mt-3.5">
          <div class="field-l mb-1.5">{{ t('عملاء موجودون قد يكون أحدهم هو المقصود', 'Existing customers that may be the one') }}</div>
          <div class="col !gap-1.5">
            <button v-for="c in p.candidates" :key="c.id" type="button" class="flex cursor-pointer items-center gap-2.5 rounded-[12px] border bg-white px-3 py-2 text-start" :class="choice === c.id ? 'border-brand' : 'border-line'" :aria-pressed="choice === c.id" @click="choice = c.id">
              <span class="h-3.5 w-3.5 flex-none rounded-full border-2" :class="choice === c.id ? 'border-brand bg-brand' : 'border-line'" />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-[11.5px] font-extrabold text-ink" dir="auto">{{ nameOf(c) }}<span v-if="!c.active" class="text-[9px] text-bad"> · {{ t('موقوف', 'inactive') }}</span></span>
                <span class="block truncate text-[9.5px] text-muted"><span class="num ltr">{{ c.code }}</span><template v-if="c.city || c.zone"> · {{ [c.city, c.zone].filter(Boolean).join(' · ') }}</template><template v-if="c.vatNo"> · <span class="num ltr">{{ c.vatNo }}</span></template></span>
              </span>
              <Chip small :label="why(c)" :fg="TONE.violet.fg" :bg="TONE.violet.bg" />
            </button>
          </div>
        </div>

        <div class="col mt-3.5 !gap-1.5">
          <button type="button" class="flex cursor-pointer items-center gap-2.5 rounded-[12px] border bg-white px-3 py-2 text-start" :class="choice === 'other' ? 'border-brand' : 'border-line'" :aria-pressed="choice === 'other'" @click="choice = 'other'">
            <span class="h-3.5 w-3.5 flex-none rounded-full border-2" :class="choice === 'other' ? 'border-brand bg-brand' : 'border-line'" />
            <span class="text-[11px] font-extrabold text-ink">{{ t('عميل موجود آخر', 'Another existing customer') }}</span>
          </button>
          <SelectInput v-if="choice === 'other'" v-model="otherCode" :options="everyCustomer" :placeholder="{ ar: '— اختر العميل —', en: '— pick the customer —' }" />

          <button type="button" class="flex cursor-pointer items-center gap-2.5 rounded-[12px] border bg-white px-3 py-2 text-start" :class="choice === 'new' ? 'border-brand' : 'border-line'" :aria-pressed="choice === 'new'" @click="choice = 'new'">
            <span class="h-3.5 w-3.5 flex-none rounded-full border-2" :class="choice === 'new' ? 'border-brand bg-brand' : 'border-line'" />
            <span class="text-[11px] font-extrabold text-ink">{{ t('عميل جديد — ليس أيًّا مما سبق', 'A new customer — none of the above') }}</span>
          </button>
        </div>

        <template v-if="choice === 'new'">
          <div v-if="!mayCreate" class="hint amber">{{ t('إنشاء عميل جديد يحتاج صلاحية إدارة العملاء (customer.manage). يمكنك ربط الفرصة بعميل موجود.', 'Creating a new customer needs the customers permission (customer.manage). You can link the opportunity to an existing customer.') }}</div>
          <div v-else class="form-grid mt-3">
            <TextInput v-model="nw.nameAr" full required :label="{ ar: 'اسم العميل', en: 'Customer name' }" :error="errors.nameAr" />
            <TextInput v-model="nw.city" :label="{ ar: 'المدينة', en: 'City' }" :error="errors.city" />
            <TextInput v-model="nw.zone" required :label="{ ar: 'المنطقة / الحي', en: 'Zone / district' }" :error="errors.zone" />
            <TextInput v-model="nw.contact" required :label="{ ar: 'جهة الاتصال · الجوال', en: 'Contact · mobile' }" :error="errors.contact" />
            <TextInput v-model="nw.cr" dir="ltr" :label="{ ar: 'السجل التجاري', en: 'CR number' }" :error="errors.cr" />
            <SelectInput v-model="nw.terms" :label="{ ar: 'شروط الدفع', en: 'Payment terms' }" :options="TERMS" :error="errors.terms" />
            <NumberInput v-if="credit" v-model="nw.creditLimit" required :min="0" :label="{ ar: 'الحد الائتماني (ر.س)', en: 'Credit limit (SAR)' }" :hint="{ ar: 'العميل الآجل يحتاج حدًا أكبر من صفر', en: 'A credit customer needs a limit above zero' }" :error="errors.creditLimit" />
          </div>
        </template>
      </template>
    </template>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :disabled="!ready" :label="submitLabel" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('لا يُنشأ عميل مكرر: الرابط القائم أولًا، ثم الرقم الضريبي، وإلا فقرارك أنت.', 'No duplicate customer: an existing link first, then the VAT number, otherwise your decision.') }}</div>
    </template>
  </Drawer>
</template>
