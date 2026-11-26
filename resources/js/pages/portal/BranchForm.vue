<script setup>
// Add / edit a customer branch. A branch that Odoo owns keeps its name, code and order (they are greyed out); where
// it is, whom to call, when it takes deliveries, its delivery site and the notes are OPS's own on every branch.
// The customer of a branch is its identity and is only chosen when the branch is added.
//   <BranchForm :open="open" :branch="detailOrNull" @close="…" @done="(branch) => …" />
// POST /portal/branches · PATCH /portal/branches/{id}  (in an edit an emptied field is sent as null: it is cleared)
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer, LocationField, TextInput } from '@/components';
import { t } from '@/i18n';
import CustomerPicker from './CustomerPicker.vue';
import { DAYS, nameOf } from './portal';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The branch as GET /portal/branches/{id} gives it; null = a new branch. */
  branch: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const edit = computed(() => !!props.branch);
const owned = computed(() => !!props.branch?.owned);
const HOUR = /^([01]\d|2[0-3]):[0-5]\d$/;
const siteOptions = computed(() => (props.branch?.sites || []).map((s) => ({ v: s.id, l: `${s.name}${s.city ? ` · ${s.city}` : ''}${s.active ? '' : t(' (موقوف)', ' (inactive)')}` })));

const fields = computed(() => [
  ...(edit.value ? [] : [{ k: 'customerId', label: { ar: 'العميل', en: 'Customer' }, required: true, full: true }]),
  { k: 'name', label: { ar: 'اسم الفرع', en: 'Branch name' }, required: true, disabled: owned.value, hint: owned.value ? { ar: 'يُدار من أودو', en: 'Managed in Odoo' } : null },
  { k: 'code', label: { ar: 'رمز الفرع لدى العميل', en: 'Customer’s branch code' }, dir: 'ltr', disabled: owned.value },
  { k: 'contactName', label: { ar: 'جهة الاتصال في الفرع', en: 'Contact at the branch' } },
  { k: 'phone', label: { ar: 'الجوال', en: 'Mobile' }, dir: 'ltr' },
  { k: 'email', label: { ar: 'البريد الإلكتروني', en: 'E-mail' }, dir: 'ltr', validate: (v) => (v && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v) ? t('بريد غير صالح', 'Not a valid e-mail') : null) },
  { k: 'sequence', label: { ar: 'ترتيبه بين فروع العميل', en: 'Order among the branches' }, type: 'num', min: 0, disabled: owned.value },
  { k: 'city', label: { ar: 'المدينة', en: 'City' } },
  { k: 'district', label: { ar: 'الحي', en: 'District' } },
  { k: 'address', label: { ar: 'العنوان', en: 'Address' }, type: 'area' },
  { k: 'windowFrom', label: { ar: 'التسليم من الساعة', en: 'Deliveries from' }, validate: (v, all) => (v && !HOUR.test(v) ? t('اكتب الوقت هكذا 08:00', 'Write the time as 08:00') : !v && all.windowTo ? t('حدّد بداية النافذة', 'Set the start of the window') : null) },
  { k: 'windowTo', label: { ar: 'إلى الساعة', en: 'Until' }, validate: (v, all) => (v && !HOUR.test(v) ? t('اكتب الوقت هكذا 13:00', 'Write the time as 13:00') : !v && all.windowFrom ? t('حدّد نهاية النافذة', 'Set the end of the window')
    : v && all.windowFrom && v <= all.windowFrom ? t('يجب أن تكون بعد البداية', 'Must be after the start') : null) },
  { k: 'deliveryDays', label: { ar: 'أيام التسليم', en: 'Delivery days' }, full: true },
  { k: 'deliveryNotes', label: { ar: 'تعليمات التسليم', en: 'Delivery instructions' }, full: true },
  ...(edit.value ? [{ k: 'siteId', label: { ar: 'موقع التسليم في نظام العمليات', en: 'Delivery site in OPS' }, type: 'select', opts: siteOptions.value, ph: { ar: '— غير مربوط —', en: '— not tied —' }, full: true,
    hint: siteOptions.value.length ? { ar: 'يربط طلبات الفرع وفواتيره به — ومنه يصدر كشف حساب الفرع', en: 'Ties the branch’s orders and invoices to it — a branch statement is made from it' }
      : { ar: 'لا مواقع تسليم مسجَّلة لهذا العميل بعد', en: 'This customer has no delivery site on record yet' } }] : []),
  { k: 'location', label: { ar: 'الموقع على الخريطة', en: 'Map location' }, full: true },
  { k: 'notes', label: { ar: 'ملاحظات', en: 'Notes' }, type: 'area' },
]);

const initial = computed(() => {
  const b = props.branch;
  if (!b) return { sequence: 10, deliveryDays: [] };
  return {
    name: b.name, code: b.code || '', contactName: b.contactName || '', phone: b.phone || '', email: b.email || '', sequence: b.sequence, city: b.city || '', district: b.district || '', address: b.address || '',
    windowFrom: b.windowFrom || '', windowTo: b.windowTo || '', deliveryDays: b.deliveryDays || [], deliveryNotes: b.deliveryNotes || '', siteId: b.site?.id || '', location: b.lat != null && b.lng != null ? { lat: b.lat, lng: b.lng } : null,
    notes: b.notes || '',
  };
});
const title = computed(() => (edit.value ? { ar: `تعديل الفرع «${props.branch.name}»`, en: `Edit branch “${props.branch.name}”` } : { ar: 'إضافة فرع عميل', en: 'Add a customer branch' }));
const sub = computed(() => (edit.value ? nameOf(props.branch.customer) : null));

const TEXT_KEYS = ['name', 'code', 'contactName', 'phone', 'email', 'city', 'district', 'address', 'windowFrom', 'windowTo', 'deliveryNotes', 'siteId', 'notes'];
function submit(values) {
  const { location, deliveryDays, customerId, sequence, ...rest } = values;
  const days = Array.isArray(deliveryDays) ? deliveryDays : [];
  if (!edit.value) {
    return api.postIdempotent('/portal/branches', { ...rest, customerId, ...(sequence != null ? { sequence } : {}), ...(days.length ? { deliveryDays: days } : {}), ...(location ? { lat: location.lat, lng: location.lng } : {}) });
  }
  // the form hands over only what holds something: a field that was emptied is sent as null, so it is really cleared
  const body = { deliveryDays: days, ...(location ? { lat: location.lat, lng: location.lng } : { lat: null, lng: null }) };
  for (const k of TEXT_KEYS) {
    if (owned.value && (k === 'name' || k === 'code')) continue;
    body[k] = rest[k] === undefined || rest[k] === '' ? null : rest[k];
  }
  if (!owned.value && sequence != null) body.sequence = sequence;
  if (body.name === null) delete body.name;
  return api.patch(`/portal/branches/${props.branch.id}`, body);
}
const action = { success: () => t('حُفظ الفرع ✓', 'Branch saved ✓'), invalidate: ['portal'] };

const toggleDay = (list, d) => (list.includes(d) ? list.filter((x) => x !== d) : [...list, d]);
</script>

<template>
  <FormDrawer :open="open" :title="title" :sub="sub" :fields="fields" :initial="initial" :submit="submit" :action="action" :width="560" @close="emit('close')" @done="(b) => emit('done', b)">
    <!-- the form itself prints a field's error under its control -->
    <template #field-customerId="{ value, set }">
      <CustomerPicker :value="value || ''" :label="{ ar: 'العميل', en: 'Customer' }" required @pick="(c, code) => set(c ? c.code : code)" />
    </template>
    <template #field-windowFrom="{ value, set }">
      <TextInput type="time" dir="ltr" :model-value="value || ''" :label="{ ar: 'التسليم من الساعة', en: 'Deliveries from' }" @update:model-value="set" />
    </template>
    <template #field-windowTo="{ value, set }">
      <TextInput type="time" dir="ltr" :model-value="value || ''" :label="{ ar: 'إلى الساعة', en: 'Until' }" @update:model-value="set" />
    </template>
    <template #field-deliveryDays="{ value, set }">
      <label class="field-l">{{ t('أيام التسليم (بلا اختيار = كل الأيام)', 'Delivery days (none picked = every day)') }}</label>
      <div class="pill-choice row wrap !gap-1.5">
        <button v-for="[k, l] in DAYS" :key="k" type="button" class="pill" :class="{ active: (value || []).includes(k) }" @click="set(toggleDay(value || [], k))">{{ t(l.ar, l.en) }}</button>
      </div>
    </template>
    <template #field-location="{ value, set, values }">
      <LocationField :model-value="value || null" :seed="[values.district, values.city].filter(Boolean).join(' ')" :hint="{ ar: 'موقع الفرع على الخريطة — لمن يسلّم له', en: 'Where the branch is — for whoever delivers to it' }" @update:model-value="set" />
    </template>
  </FormDrawer>
</template>
