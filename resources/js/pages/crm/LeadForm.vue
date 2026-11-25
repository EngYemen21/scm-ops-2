<script setup>
// The form of a lead / opportunity — add (POST /crm/leads) or edit (PATCH /crm/leads/{id}): what it is about, who the
// prospect is (company, contact, phone, e-mail, city), what it is worth (expected revenue, chance, priority, expected
// closing), who works it (salesperson, team), where it came from (source), its tags and notes.
// An edit sends only what changed: a value left alone is left alone on the server too (a salesperson kept by name from
// Odoo stays until another is picked). Stage and closing are not here — they are moves, made from the lead itself.
//   <LeadForm :open="open" :lead="leadOrNull" @close="…" @done="(lead) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, Field, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { customerOptions, useLookups } from '@/pages/sales/shared';
import { nameOf, priorityOptions, tagColor, useCrmLookups } from './crm';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The lead to edit (GET /crm/leads/{id}); null = a new one. */
  lead: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction({ invalidate: ['crm'] });
const { openStageOptions, userOptions, teamOptions, tags, sources } = useCrmLookups();
const master = useLookups();
const customers = computed(() => customerOptions(master.data.value));
const edit = computed(() => !!props.lead);
const priorities = priorityOptions();

const TEXT = ['name', 'companyName', 'contactName', 'jobTitle', 'phone', 'mobile', 'email', 'website', 'street', 'city', 'country', 'vatNo', 'source', 'description'];
const blank = () => ({ ...Object.fromEntries(TEXT.map((k) => [k, ''])), customerId: '', expectedRevenue: null, probability: null, priority: '0', deadline: '', stageId: '', ownerId: '', teamId: '', tagIds: [] });
const fromLead = (l) => ({
  ...Object.fromEntries(TEXT.map((k) => [k, l[k] || ''])), customerId: l.customer?.code || '', expectedRevenue: num(l.expectedRevenue) || null, probability: l.probability ?? null,
  priority: String(l.priority ?? 0), deadline: l.deadline || '', stageId: l.stage?.id || '', ownerId: l.owner?.id || '', teamId: l.team?.id || '', tagIds: (l.tags || []).map((x) => x.id),
});
const v = reactive(blank());
let initial = blank();
const errors = ref({});

watch(() => [props.open, props.lead], () => {
  if (!props.open) return;
  initial = props.lead ? fromLead(props.lead) : blank();
  // a new lead: the person who enters it works it, unless another is picked
  if (!props.lead && auth.user && userOptions.value.some((u) => u.v === auth.user.id)) initial.ownerId = auth.user.id;
  Object.assign(v, JSON.parse(JSON.stringify(initial)));
  errors.value = {};
  act.clearError();
}, { immediate: true });
// server-side validation details land on their fields
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

/** The salesperson Odoo names when that person has no OPS user: shown, and kept until another is picked. */
const keptOwner = computed(() => (props.lead?.owner?.external ? nameOf(props.lead.owner) : null));
const activeTags = computed(() => tags.value.filter((x) => x.active || v.tagIds.includes(x.id)));
const toggleTag = (id) => { v.tagIds = v.tagIds.includes(id) ? v.tagIds.filter((x) => x !== id) : [...v.tagIds, id]; };
const tagStyle = (tg) => (v.tagIds.includes(tg.id) ? { color: tagColor(tg.color).fg, background: tagColor(tg.color).bg, borderColor: tagColor(tg.color).fg } : null);

/** A value as the API takes it: empty text is null, numbers are numbers. */
function sent(key, value) {
  if (key === 'tagIds') return [...value];
  if (key === 'priority') return Number(value) || 0;
  if (key === 'expectedRevenue') return value == null || value === '' ? (edit.value ? 0 : null) : Number(value);
  if (key === 'probability') return value == null || value === '' ? null : Number(value);
  return typeof value === 'string' ? (value.trim() === '' ? null : value.trim()) : value;
}
function body() {
  const out = {};
  for (const key of Object.keys(v)) {
    if (key === 'stageId' && edit.value) continue;
    const now = sent(key, v[key]);
    if (edit.value) { if (JSON.stringify(now) !== JSON.stringify(sent(key, initial[key]))) out[key] = now; } else if (now !== null && !(Array.isArray(now) && !now.length)) out[key] = now;
  }
  return out;
}

async function submit() {
  const errs = {};
  if (!v.name.trim()) errs.name = t('هذا الحقل إلزامي', 'Required');
  if (v.probability != null && v.probability !== '' && (Number(v.probability) < 0 || Number(v.probability) > 100)) errs.probability = t('بين 0 و100', 'Between 0 and 100');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const payload = body();
  if (edit.value && !Object.keys(payload).length) { emit('close'); return; }
  const r = await act.run(() => (edit.value ? api.patch(`/crm/leads/${props.lead.id}`, payload) : api.postIdempotent('/crm/leads', payload)), {
    success: (l) => (edit.value ? t(`حُفظت الفرصة ${l.number}`, `Opportunity ${l.number} saved`) : t(`أُضيفت الفرصة ${l.number}`, `Opportunity ${l.number} added`)),
  });
  if (r) emit('done', r);
}
const title = computed(() => (edit.value ? { ar: `تعديل الفرصة ${props.lead.number}`, en: `Edit opportunity ${props.lead.number}` } : { ar: 'فرصة جديدة', en: 'New opportunity' }));
</script>

<template>
  <Drawer :open="open" :width="640" :z-index="70" :title="title" :sub="{ ar: 'المرحلة والكسب والخسارة تُسجَّل من الفرصة نفسها', en: 'Stage, won and lost are recorded from the opportunity itself' }" @close="emit('close')">
    <div class="form-grid">
      <TextInput v-model="v.name" full required :label="{ ar: 'عنوان الفرصة', en: 'Opportunity title' }" :placeholder="{ ar: 'مثال: توريد شهري لمطعم الواحة', en: 'e.g. monthly supply for Al Waha restaurant' }" :error="errors.name" />
      <TextInput v-model="v.companyName" :label="{ ar: 'المنشأة (مطعم، كافيه، فندق…)', en: 'Company (restaurant, café, hotel…)' }" :error="errors.companyName" />
      <TextInput v-model="v.contactName" :label="{ ar: 'جهة الاتصال', en: 'Contact person' }" :error="errors.contactName" />
      <TextInput v-model="v.jobTitle" :label="{ ar: 'المسمى الوظيفي', en: 'Job title' }" :error="errors.jobTitle" />
      <TextInput v-model="v.mobile" dir="ltr" :label="{ ar: 'الجوال', en: 'Mobile' }" :error="errors.mobile" />
      <TextInput v-model="v.phone" dir="ltr" :label="{ ar: 'الهاتف', en: 'Phone' }" :error="errors.phone" />
      <TextInput v-model="v.email" dir="ltr" :label="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" :error="errors.email" />
      <TextInput v-model="v.city" :label="{ ar: 'المدينة', en: 'City' }" :error="errors.city" />
      <TextInput v-model="v.street" :label="{ ar: 'العنوان', en: 'Address' }" :error="errors.street" />
      <TextInput v-model="v.website" dir="ltr" :label="{ ar: 'الموقع الإلكتروني', en: 'Website' }" :error="errors.website" />
      <TextInput v-model="v.vatNo" dir="ltr" :label="{ ar: 'الرقم الضريبي', en: 'VAT number' }" :hint="{ ar: 'به يُعرف العميل عند التحويل فلا يتكرر', en: 'It is how the customer is recognised at conversion — no duplicate' }" :error="errors.vatNo" />
      <SelectInput v-model="v.customerId" full :label="{ ar: 'عميل قائم (إن كانت الفرصة لعميل موجود)', en: 'Existing customer (when the opportunity is for one)' }" :options="customers" :placeholder="{ ar: '— ليس عميلًا بعد —', en: '— not a customer yet —' }" :error="errors.customerId" />
    </div>

    <div class="form-grid mt-3.5">
      <NumberInput v-model="v.expectedRevenue" :min="0" :label="{ ar: 'الإيراد المتوقع (ر.س)', en: 'Expected revenue (SAR)' }" :error="errors.expectedRevenue" />
      <NumberInput v-model="v.probability" :min="0" :max="100" :label="{ ar: 'احتمال الكسب %', en: 'Chance of winning %' }" :hint="edit ? null : { ar: 'يُترك فارغًا ليأخذ احتمال المرحلة', en: 'Leave empty to take the stage’s chance' }" :error="errors.probability" />
      <DateInput v-model="v.deadline" :label="{ ar: 'الإغلاق المتوقع', en: 'Expected closing' }" :error="errors.deadline" />
      <PillChoice v-model="v.priority" :options="priorities" :label="{ ar: 'الأولوية', en: 'Priority' }" />
      <SelectInput v-if="!edit" v-model="v.stageId" :label="{ ar: 'المرحلة', en: 'Stage' }" :options="openStageOptions" :placeholder="{ ar: 'أول مرحلة في المسار', en: 'First stage of the pipeline' }" :error="errors.stageId" />
      <SelectInput v-model="v.ownerId" :label="{ ar: 'المندوب', en: 'Salesperson' }" :options="userOptions" :placeholder="keptOwner ? { ar: `${keptOwner} (من أودو)`, en: `${keptOwner} (from Odoo)` } : { ar: '— بلا مندوب —', en: '— unassigned —' }" :error="errors.ownerId" />
      <SelectInput v-model="v.teamId" :label="{ ar: 'فريق المبيعات', en: 'Sales team' }" :options="teamOptions" :placeholder="{ ar: '—', en: '—' }" :error="errors.teamId" />
      <TextInput v-model="v.source" list="crm-lead-sources" :label="{ ar: 'المصدر', en: 'Source' }" :placeholder="{ ar: 'زيارة ميدانية، إحالة، معرض، إعلان…', en: 'Field visit, referral, exhibition, ad…' }" :error="errors.source" />
    </div>
    <datalist id="crm-lead-sources"><option v-for="s in sources" :key="s" :value="s" /></datalist>

    <Field v-if="activeTags.length" class="mt-3.5" :label="{ ar: 'الوسوم', en: 'Tags' }" :error="errors.tagIds">
      <div class="row wrap !gap-1.5">
        <button v-for="tg in activeTags" :key="tg.id" type="button" class="pill" :style="tagStyle(tg)" :aria-pressed="v.tagIds.includes(tg.id)" @click="toggleTag(tg.id)">{{ tg.name }}</button>
      </div>
    </Field>
    <TextArea v-model="v.description" field-class="mt-3.5" :rows="4" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.description" />

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="edit ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إضافة الفرصة', en: 'Add opportunity' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t(`* حقل إلزامي · يُسجل الإجراء في سجل التدقيق باسم ${auth.user?.nameAr || ''}`, `* required · recorded in the audit trail as ${auth.user?.nameEn || ''}`) }}</div>
    </template>
  </Drawer>
</template>
