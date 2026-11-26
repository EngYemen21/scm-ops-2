<script setup>
// Raising an approval request, or correcting one's draft. The form is drawn from the category: a field the category
// does not use is not shown, one it requires is marked and checked before sending; the approvers of the category are
// shown as they will be asked, and the requester may add more.
//   <RequestForm :open :number="draftNumberOrNull" :preset="categoryCodeOrNull" @close="…" @done="(number) => …" />
// Saving is three steps, each a call of its own so a large file never rides in the request itself:
//   1. the draft   POST /approvals/requests  (or PATCH …/{number} when it exists)
//   2. the files   POST …/{number}/attachments, one by one
//   3. sending     POST …/{number}/submit            (skipped for «حفظ كمسودة»)
// A step that fails leaves the form open on what was done so far: pressing again goes on from there — the draft is
// never created twice and a file is never sent twice.
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet, useInvalidate } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, Field, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { bi, fmtMoney, t } from '@/i18n';
import { toast } from '@/stores/ui';
import ItemsEditor from './ItemsEditor.vue';
import LookupPicker from './LookupPicker.vue';
import { FIELD_LABELS, MAX_FILE_BYTES, MODE_LABELS, PARTNER_TYPE_OPTIONS, PAYMENT_OPTIONS, catName, own, personName, readFile, sizeText, useLookups } from './approvals';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The draft being edited (its number); null = a new request. */
  number: { type: String, default: null },
  /** Category code to start a new request with. */
  preset: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const lookups = useLookups(() => props.open);
const draftQ = useGet(() => (props.open && props.number ? `/approvals/requests/${encodeURIComponent(props.number)}` : null));
const draft = computed(() => (props.open && props.number ? draftQ.data.value || null : null));
const act = useAction({ invalidate: false, success: false });
const invalidate = useInvalidate();

const blank = () => ({ category: '', title: '', amount: null, quantity: null, date: '', periodStart: '', periodEnd: '', location: '', reference: '', partnerType: 'supplier', partner: null, partnerName: '',
  paymentMethod: '', reason: '', items: [], approvers: [] });
const form = reactive(blank());
const errors = ref({});
/** Files picked here, sent after the draft is saved. */
const files = ref([]);
/** The number of the draft once the server holds it (a new request that was saved by an earlier press). */
const saved = ref(null);
const step = ref('');
const picker = ref(null);
const newApprover = ref('');
const newRequired = ref(false);
/** The draft the form has been filled from (so a refetch does not overwrite what is being typed). */
let filledFrom = null;

const target = computed(() => props.number || saved.value);
/** The category with its approvers when it is one of the active ones; a draft of a stopped category still knows its rules. */
const category = computed(() => lookups.data.value.categories.find((c) => c.code === (draft.value?.category?.code || form.category)) || draft.value?.category || null);
const rules = computed(() => category.value?.fields || {});
const uses = (field) => !!rules.value[field] && rules.value[field] !== 'no';
const needs = (field) => rules.value[field] === 'required';
/** `YYYY-MM-DDTHH:mm` of a local datetime field ⇄ the instant the API stores. */
const toLocal = (iso) => { if (!iso) return ''; const d = new Date(iso); if (Number.isNaN(d.getTime())) return ''; const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
const toInstant = (local) => { if (!local) return null; const d = new Date(local); return Number.isNaN(d.getTime()) ? null : d.toISOString(); };
let seq = 0;

function fill() {
  Object.assign(form, blank());
  errors.value = {}; files.value = []; saved.value = null; step.value = ''; newApprover.value = ''; newRequired.value = false; act.clearError();
  const d = draft.value;
  if (!d) { form.category = props.preset && props.preset !== '1' ? props.preset : ''; return; }
  Object.assign(form, {
    category: d.category?.code || '', title: d.title || '', amount: d.amount == null ? null : Number(d.amount), quantity: d.quantity == null ? null : Number(d.quantity), date: d.date || '',
    periodStart: toLocal(d.periodStart), periodEnd: toLocal(d.periodEnd), location: d.location || '', reference: d.reference || '', paymentMethod: d.paymentMethod || '', reason: d.reason || '',
    partnerType: d.partner?.type || 'supplier', partner: d.partner?.id ? { id: d.partner.id, code: d.partner.code, nameAr: d.partner.nameAr, nameEn: d.partner.nameEn } : null,
    partnerName: d.partner && !d.partner.id ? d.partner.name : '',
    items: d.items.map((i) => ({ key: `e${seq++}`, product: i.product, description: i.description, quantity: Number(i.quantity), uom: i.uom || '' })),
    // the approvers the requester added himself; the category's follow the category
    approvers: d.approvers.filter((a) => ['requester', 'manager'].includes(a.origin) && a.status !== 'removed' && a.user?.id).map((a) => ({ user: a.user.id, name: personName(a.user), required: a.required })),
  });
}
watch(() => [props.open, props.number, draft.value?.id], ([open, number, id]) => {
  if (!open) { filledFrom = null; return; }
  const key = number ? (id ? `draft:${id}` : null) : 'new';
  if (key && key !== filledFrom) { filledFrom = key; fill(); }
}, { immediate: true });
// a new request: with one category only, it is the category
watch(() => [props.open, lookups.data.value.categories.length], () => {
  if (props.open && !props.number && !form.category && lookups.data.value.categories.length === 1) form.category = lookups.data.value.categories[0].code;
});

/** The approvers the category asks at this amount, in their order. */
const categoryApprovers = computed(() => (category.value?.approvers || []).map((a) => ({ ...a, asked: a.minAmount == null || Number(form.amount || 0) >= Number(a.minAmount) })));
const fixedLines = computed(() => (draft.value ? draft.value.approvers.filter((a) => a.origin === 'odoo' && a.status !== 'removed') : []));
const userOptions = computed(() => lookups.userOptions.value.filter((o) => !form.approvers.some((a) => a.user === o.v)));
function addApprover() {
  const user = lookups.data.value.users.find((u) => u.id === newApprover.value);
  if (!user) return;
  form.approvers = [...form.approvers, { user: user.id, name: personName(user), required: newRequired.value }];
  newApprover.value = ''; newRequired.value = false;
}
const dropApprover = (i) => { form.approvers = form.approvers.filter((_, j) => j !== i); };

function pickFiles() { picker.value?.click(); }
function onPick(e) {
  const picked = [...(e.target.files || [])];
  e.target.value = '';
  const tooBig = picked.filter((f) => f.size > MAX_FILE_BYTES);
  if (tooBig.length) toast.say({ ar: `تجاوز 5 ميجابايت ولم يُضف: ${tooBig.map((f) => f.name).join('، ')}`, en: `Over 5 MB, not added: ${tooBig.map((f) => f.name).join(', ')}` }, 4200);
  files.value = [...files.value, ...picked.filter((f) => f.size <= MAX_FILE_BYTES)];
}
const dropFile = (i) => { files.value = files.value.filter((_, j) => j !== i); };
const attached = computed(() => (draft.value ? draft.value.attachments.filter((a) => !a.removed).length : 0));

function payload() {
  const f = form;
  const out = { title: f.title.trim() || null, reason: f.reason.trim() || null, approvers: f.approvers.map((a) => ({ user: a.user, required: !!a.required })) };
  if (!target.value) out.category = f.category;
  if (uses('amount')) out.amount = f.amount;
  if (uses('quantity')) out.quantity = f.quantity;
  if (uses('date')) out.date = f.date || null;
  if (uses('period')) { out.periodStart = toInstant(f.periodStart); out.periodEnd = toInstant(f.periodEnd); }
  if (uses('location')) out.location = f.location.trim() || null;
  if (uses('reference')) out.reference = f.reference.trim() || null;
  if (uses('paymentMethod')) out.paymentMethod = f.paymentMethod || null;
  if (uses('partner')) {
    const typed = f.partnerType === 'other';
    out.partnerType = typed ? (f.partnerName.trim() ? 'other' : null) : f.partner ? f.partnerType : null;
    out.partner = typed ? null : f.partner?.code || null;
    out.partnerName = typed ? f.partnerName.trim() || null : null;
  }
  if (uses('items')) out.items = f.items.filter((i) => i.product || i.description.trim()).map((i) => ({ product: i.product?.sku || null, description: i.description.trim() || null, quantity: i.quantity, uom: i.uom.trim() || null }));
  return out;
}

/** What the category requires and the form does not carry yet (checked before sending; the server checks again). */
function missing() {
  const f = form;
  const lines = f.items.filter((i) => i.product || i.description.trim());
  const checks = {
    amount: Number(f.amount) > 0, date: !!f.date, period: !!f.periodStart && !!f.periodEnd, partner: f.partnerType === 'other' ? !!f.partnerName.trim() : !!f.partner, reference: !!f.reference.trim(),
    quantity: Number(f.quantity) > 0, items: lines.length > 0, location: !!f.location.trim(), paymentMethod: !!f.paymentMethod, document: files.value.length + attached.value > 0,
  };
  return Object.keys(checks).filter((field) => needs(field) && !checks[field]);
}
const FIELD_PATH = { period: 'periodStart', document: 'attachments' };

async function save(send) {
  const errs = {};
  if (!category.value) errs.category = t('اختر نوع الطلب', 'Pick the category');
  form.items.forEach((i, n) => { if ((i.product || i.description.trim()) && !(Number(i.quantity) > 0)) errs[`items.${n}.quantity`] = t('أكبر من صفر', 'Must be positive'); });
  if (form.periodStart && form.periodEnd && new Date(form.periodEnd) < new Date(form.periodStart)) errs.periodEnd = t('نهاية الفترة قبل بدايتها', 'The period ends before it starts');
  if (send) missing().forEach((field) => { errs[FIELD_PATH[field] || field] = t('يشترطه هذا النوع', 'Required by this category'); });
  errors.value = errs;
  if (Object.keys(errs).length) return;

  // 1. the draft
  step.value = t('حفظ الطلب…', 'Saving the request…');
  const body = payload();
  const row = await act.run(() => (target.value ? api.patch(`/approvals/requests/${encodeURIComponent(target.value)}`, body) : api.postIdempotent('/approvals/requests', body)));
  if (!row) { step.value = ''; return; }
  saved.value = row.number;
  const base = `/approvals/requests/${encodeURIComponent(row.number)}`;
  // 2. its files, one call each
  while (files.value.length) {
    const file = files.value[0];
    step.value = t(`رفع «${file.name}»…`, `Uploading “${file.name}”…`);
    const sent = await act.run(async () => { const { fileName, mime, base64 } = await readFile(file); return api.postIdempotent(`${base}/attachments`, { fileName, mime, base64 }); });
    if (!sent) { step.value = ''; void invalidate(['approvals']); return; }
    files.value = files.value.slice(1);
  }
  // 3. sending it
  if (send) {
    step.value = t('إرسال للاعتماد…', 'Sending for approval…');
    const sent = await act.run(() => api.postIdempotent(`${base}/submit`));
    if (!sent) { step.value = ''; void invalidate(['approvals']); return; }
  }
  step.value = '';
  void invalidate(['approvals']);
  toast.say(send ? { ar: `أُرسل الطلب ${row.number} للاعتماد`, en: `Request ${row.number} was sent for approval` } : { ar: `حُفظت المسودة ${row.number}`, en: `Draft ${row.number} saved` });
  emit('done', row.number);
}
// what the server refuses lands on its field (APPROVAL_FIELDS_REQUIRED names the fields; a validation names its path)
watch(() => act.error.value, (e) => {
  if (!e) return;
  const fe = e.fieldErrors();
  if (!Object.keys(fe).length) return;
  const required = e.code === 'APPROVAL_FIELDS_REQUIRED';
  errors.value = { ...errors.value, ...Object.fromEntries(Object.entries(fe).map(([path, message]) => [path, required ? t('يشترطه هذا النوع', 'Required by this category') : message])) };
});
/** Leaving a form whose draft was already saved lands on that draft, not on nothing. */
function close() { if (saved.value && !props.number) emit('done', saved.value); else emit('close'); }

const busy = computed(() => act.pending.value || !!step.value);
const title = computed(() => (props.number ? { ar: `تعديل المسودة ${props.number}`, en: `Edit draft ${props.number}` } : { ar: 'طلب اعتماد جديد', en: 'New approval request' }));
const requiredList = computed(() => Object.keys(FIELD_LABELS).filter(needs).map((f) => bi(FIELD_LABELS[f])));
/** Refusals that have no field of their own to land on (an approver the server did not accept). */
const otherErrors = computed(() => Object.entries(errors.value).filter(([path]) => /^(approvers|user)/.test(path)).map(([, message]) => `${t('المعتمدون', 'Approvers')}: ${message}`));
</script>

<template>
  <Drawer :open="open" :title="title" :sub="category ? catName(category) : null" :width="700" :z-index="70" @close="close">
    <ErrorBanner :error="draftQ.error.value || lookups.query.error.value" :closable="false" />
    <div v-if="number && !draft" class="skel h-[200px]" />
    <template v-else>
      <div v-if="saved && !number" class="hint amber !mt-0 mb-3">{{ t(`حُفظ الطلب مسودة برقم ${saved}. أكمل ما تبقّى واضغط من جديد — لن يُنشأ مرتين.`, `The request was saved as draft ${saved}. Finish what is left and press again — it is not created twice.`) }}</div>
      <form class="form-grid" @submit.prevent="save(true)">
        <SelectInput v-if="!target" v-model="form.category" full required :label="{ ar: 'نوع الطلب', en: 'Category' }" :options="lookups.categoryOptions.value" :error="errors.category || null"
                     :placeholder="lookups.loaded.value && !lookups.data.value.categories.length ? { ar: 'لا أنواع مفعّلة — يضيفها مدير الاعتمادات', en: 'No active category — the approvals manager adds them' } : { ar: '— اختر —', en: '— select —' }" />
        <div v-if="category" class="field full">
          <div class="rounded-xl bg-soft px-3.5 py-2.5 text-[10.5px] leading-[1.9] text-sec">
            <div v-if="category.description" class="font-bold text-ink" dir="auto">{{ category.description }}</div>
            <div v-if="requiredList.length">{{ t('يشترط هذا النوع:', 'This category requires:') }} <b>{{ requiredList.join('، ') }}</b></div>
            <div v-if="categoryApprovers.length">
              {{ t('يعتمده', 'Approved by') }}
              <template v-for="(a, i) in categoryApprovers" :key="a.id"><span v-if="i"> {{ category.approvalMode === 'sequence' ? '←' : '·' }} </span><b :class="{ 'font-normal text-faint line-through': !a.asked }" :title="!a.asked ? t(`يُسأل من مبلغ ${fmtMoney(a.minAmount)}`, `Asked from ${fmtMoney(a.minAmount)}`) : null">{{ personName(a.user) }}</b><span v-if="a.minAmount != null" class="num ltr text-[9px] text-faint"> ≥{{ fmtMoney(a.minAmount) }}</span></template>
              <span class="text-muted"> — {{ bi(own(MODE_LABELS, category.approvalMode) || '') }}<template v-if="category.minApprovals > 1"> · {{ t(`يكفي ${category.minApprovals} اعتمادات`, `${category.minApprovals} approvals needed`) }}</template></span>
            </div>
            <div v-else-if="category.approvers" class="font-bold text-warn">{{ t('لا معتمدين محددين لهذا النوع — أضف معتمدًا واحدًا على الأقل أدناه.', 'This category names no approver — add at least one below.') }}</div>
          </div>
        </div>

        <template v-if="category">
          <TextInput v-model="form.title" full :label="{ ar: 'عنوان الطلب', en: 'Title' }" :error="errors.title || null" :placeholder="{ ar: 'يُكتب تلقائيًا من النوع والجهة إن تُرك فارغًا', en: 'Made from the category and the partner when left empty' }" />
          <NumberInput v-if="uses('amount')" v-model="form.amount" :label="{ ar: 'المبلغ (ر.س)', en: 'Amount (SAR)' }" :required="needs('amount')" :min="0" :error="errors.amount || null" />
          <NumberInput v-if="uses('quantity')" v-model="form.quantity" :label="FIELD_LABELS.quantity" :required="needs('quantity')" :min="0" :error="errors.quantity || null" />
          <DateInput v-if="uses('date')" v-model="form.date" :label="FIELD_LABELS.date" :required="needs('date')" :error="errors.date || null" />
          <TextInput v-if="uses('reference')" v-model="form.reference" dir="ltr" :label="FIELD_LABELS.reference" :required="needs('reference')" :error="errors.reference || null" :placeholder="{ ar: 'رقم المستند المرتبط', en: 'Number of the related document' }" />
          <DateInput v-if="uses('period')" v-model="form.periodStart" time :label="{ ar: 'من', en: 'From' }" :required="needs('period')" :error="errors.periodStart || null" />
          <DateInput v-if="uses('period')" v-model="form.periodEnd" time :label="{ ar: 'إلى', en: 'To' }" :required="needs('period')" :min="form.periodStart || null" :error="errors.periodEnd || null" />
          <TextInput v-if="uses('location')" v-model="form.location" :label="FIELD_LABELS.location" :required="needs('location')" :error="errors.location || null" />
          <SelectInput v-if="uses('paymentMethod')" v-model="form.paymentMethod" :label="FIELD_LABELS.paymentMethod" :required="needs('paymentMethod')" :options="PAYMENT_OPTIONS" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors.paymentMethod || null" />
          <template v-if="uses('partner')">
            <PillChoice v-model="form.partnerType" full :label="{ ar: 'الجهة', en: 'Partner' }" :required="needs('partner')" :options="PARTNER_TYPE_OPTIONS" @update:model-value="form.partner = null" />
            <TextInput v-if="form.partnerType === 'other'" v-model="form.partnerName" full :label="{ ar: 'اسم الجهة', en: 'Partner name' }" :required="needs('partner')" :error="errors.partner || null" />
            <LookupPicker v-else :key="form.partnerType" v-model="form.partner" full path="/approvals/partners" :params="{ type: form.partnerType }" :required="needs('partner')" :error="errors.partner || null"
                          :label="form.partnerType === 'customer' ? { ar: 'العميل', en: 'Customer' } : { ar: 'المورد', en: 'Supplier' }" :placeholder="{ ar: 'ابحث بالاسم أو الرمز…', en: 'Search by name or code…' }" />
          </template>
          <TextArea v-model="form.reason" :rows="3" :label="{ ar: 'السبب / التفاصيل', en: 'Reason / details' }" :error="errors.reason || null" :placeholder="{ ar: 'ما المطلوب اعتماده ولماذا؟', en: 'What is to be approved, and why?' }" />

          <div v-if="uses('items')" class="field full">
            <div class="field-l">{{ bi(FIELD_LABELS.items) }}<span v-if="needs('items')" class="text-bad"> *</span></div>
            <ItemsEditor v-model:items="form.items" :errors="errors" />
            <div v-if="errors.items" class="field-err">{{ errors.items }}</div>
          </div>

          <div class="field full">
            <div class="field-l">{{ t('معتمدون إضافيون', 'More approvers') }}</div>
            <div v-for="a in fixedLines" :key="a.id" class="mb-1 text-[10px] text-muted">{{ personName(a.user) }} — {{ t('من أودو', 'from Odoo') }}</div>
            <div v-for="(a, i) in form.approvers" :key="a.user" class="row mb-1 rounded-[10px] border border-line-2 px-2.5 py-1.5">
              <span class="min-w-0 flex-1 truncate text-[11px] font-extrabold text-ink">{{ a.name }}</span>
              <button type="button" class="pill !h-6 !text-[9px]" :class="{ active: a.required }" :aria-pressed="a.required" @click="a.required = !a.required">{{ t('اعتماده إلزامي', 'Required') }}</button>
              <button type="button" class="x-btn !h-6 !w-6 !text-[10px]" :aria-label="t('إزالة', 'Remove')" @click="dropApprover(i)">✕</button>
            </div>
            <div class="row wrap">
              <SelectInput v-model="newApprover" small field-class="min-w-[180px] flex-1" :options="userOptions" :placeholder="{ ar: '— أضف معتمدًا —', en: '— add an approver —' }" />
              <button type="button" class="pill" :class="{ active: newRequired }" :aria-pressed="newRequired" @click="newRequired = !newRequired">{{ t('اعتماده إلزامي', 'Required') }}</button>
              <Btn tone="softPurple" size="sm" :disabled="!newApprover" :label="{ ar: 'إضافة', en: 'Add' }" @click="addApprover" />
            </div>
            <div class="field-hint">{{ t('معتمدو النوع يُضافون تلقائيًا؛ أضف هنا من تريد قراره زيادةً عليهم.', 'The approvers of the category are added by themselves; add here whoever else should decide.') }}</div>
          </div>

          <div class="field full">
            <div class="field-l">{{ t('المرفقات', 'Files') }}<span v-if="needs('document')" class="text-bad"> *</span></div>
            <div v-if="attached" class="mb-1 text-[10px] text-muted">{{ t(`على الطلب ${attached} مرفق — تُعرض وتُزال من صفحة الطلب.`, `${attached} file(s) on the request — they are shown and removed from the request itself.`) }}</div>
            <div v-for="(f, i) in files" :key="`${f.name}-${i}`" class="row mb-1 rounded-[10px] border border-line-2 px-2.5 py-1.5">
              <span class="min-w-0 flex-1 truncate text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ f.name }}</span>
              <span class="num ltr flex-none text-[9.5px] text-muted">{{ sizeText(f.size) }}</span>
              <button type="button" class="x-btn !h-6 !w-6 !text-[10px]" :aria-label="t('إزالة', 'Remove')" @click="dropFile(i)">✕</button>
            </div>
            <input ref="picker" type="file" multiple class="hidden" @change="onPick">
            <div class="row wrap"><Btn tone="softPurple" size="sm" :label="{ ar: '+ إرفاق ملف', en: '+ Attach a file' }" @click="pickFiles" /><span class="text-[9.5px] text-faint">{{ t('حتى 5 ميجابايت للملف', 'Up to 5 MB per file') }}</span></div>
            <div v-if="errors.attachments" class="field-err">{{ errors.attachments }}</div>
          </div>
        </template>
      </form>
    </template>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div v-if="otherErrors.length" class="banner red"><div>{{ otherErrors.join(' · ') }}</div></div>
      <div class="flex gap-2">
        <Btn tone="primary" class="!h-11 flex-1 !text-[11.5px]" :loading="busy" :disabled="!category" :label="{ ar: 'إرسال للاعتماد', en: 'Send for approval' }" @click="save(true)" />
        <Btn tone="dark" class="!h-11 !text-[11.5px]" :disabled="busy || !category" :label="{ ar: 'حفظ كمسودة', en: 'Save as draft' }" @click="save(false)" />
        <Btn tone="soft" class="!h-11 w-[90px]" :disabled="busy" :label="{ ar: 'إغلاق', en: 'Close' }" @click="close" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ step || t('* حقول يشترطها النوع عند الإرسال · المسودة تُحفظ ناقصة وتُكمل لاحقًا · كل إجراء يُسجَّل في Audit Trail', '* fields the category requires when sending · a draft may be saved incomplete · every action is recorded in the audit trail') }}</div>
    </template>
  </Drawer>
</template>
