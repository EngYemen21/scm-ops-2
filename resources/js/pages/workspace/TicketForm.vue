<script setup>
// A ticket is raised (POST /workspace/tickets) or its details are corrected (PATCH /workspace/tickets/{id}).
// The requester is a member of staff (by default the person who raises it), an OPS customer (searched through
// GET /workspace/customers?q=) or somebody who is neither (a name, with an e-mail / a phone). The due date is not a
// field of a new ticket: it comes from the team's target; an edit may set it by hand or give it back to the target.
// Who works the ticket is changed from the ticket itself (`assign`), not from the edit form.
//   <TicketForm :open :ticket="editing" :desk="desk" :lookups="lookups" @close @done="(ticket) => …" />
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { nm, t, toDate } from '@/i18n';
import { useAuth } from '@/stores/auth';
import FilePicker from './FilePicker.vue';
import RecordLinkField from './RecordLinkField.vue';
import WsFormDrawer from './WsFormDrawer.vue';
import { API, INVALIDATE, PRIORITY_OPTIONS, REQUESTER_KIND, SELECT_PH, arr, dictOptions, toUploads, userOptions } from './workspace';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The ticket being edited (the answer of GET tickets/{id}); null = a new ticket. */
  ticket: { type: Object, default: null },
  /** GET tickets/lookups: teams, stages, types. */
  desk: { type: Object, default: null },
  /** GET lookups: users, record kinds, the rules of an upload. */
  lookups: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const auth = useAuth();
const act = useAction();
const editing = computed(() => !!props.ticket);

/** A moment as a `datetime-local` field holds it (the browser's own time zone). */
function localInput(iso) {
  const d = toDate(iso);
  if (!d) return '';
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}
function blank() {
  const teams = arr(props.desk?.teams).filter((x) => x.active !== false);
  return { subject: '', description: '', teamId: teams.length === 1 ? teams[0].id : '', typeId: '', priority: '0', requesterKind: 'staff', requesterUserId: auth.user?.id || '',
    customer: null, requesterName: '', requesterEmail: '', requesterPhone: '', assigneeId: '', refType: '', refNumber: '', dueAt: '', dueTouched: false, files: [] };
}
function fromTicket(x) {
  const r = x.requester || {};
  return { subject: x.subject || '', description: x.description || '', teamId: x.team?.id || '', typeId: x.type?.id || '', priority: String(x.priority ?? 0),
    requesterKind: REQUESTER_KIND[r.kind] ? r.kind : 'other', requesterUserId: r.user?.id || '', customer: r.customer || null, requesterName: r.name || '',
    requesterEmail: r.email || '', requesterPhone: r.phone || '', assigneeId: '', refType: x.ref?.type || '', refNumber: x.ref?.number || '',
    dueAt: localInput(x.dueAt), dueTouched: false, files: [] };
}
const f = ref(props.ticket ? fromTicket(props.ticket) : blank());
/** The picker is still reading files from the disk. */
const picking = ref(false);
/** What is typed in the customer search. */
const customerText = ref('');
watch(() => props.open, (now) => {
  if (!now) return;
  f.value = props.ticket ? fromTicket(props.ticket) : blank();
  customerText.value = '';
  act.clearError();
});
// the teams arrive after the form opened: a desk with one team needs no choice
watch(() => props.desk, () => { if (props.open && !props.ticket && !f.value.teamId) f.value.teamId = blank().teamId; });

const teamOptions = computed(() => dictOptions(props.desk?.teams, f.value.teamId));
const typeOptions = computed(() => dictOptions(props.desk?.types, f.value.typeId));
const people = computed(() => userOptions(props.lookups?.users));
const kindOptions = Object.entries(REQUESTER_KIND).map(([v, l]) => ({ v, l }));
const team = computed(() => arr(props.desk?.teams).find((x) => x.id === f.value.teamId) || null);
const targetNote = computed(() => {
  const hours = team.value?.resolutionHours;
  return hours ? t(`تاريخ الاستحقاق يُحسب من هدف الفريق: ${hours} ساعة من فتح التذكرة.`, `The due date comes from the team's target: ${hours} hours after the ticket is opened.`)
    : team.value ? t('لا هدف زمنيًّا لهذا الفريق: التذكرة بلا تاريخ استحقاق.', 'This team has no target: the ticket gets no due date.') : '';
});

// ---- the customer who asks: searched while typed
const customerTerm = ref('');
let customerTimer = null;
watch(customerText, (v) => { clearTimeout(customerTimer); customerTimer = setTimeout(() => { customerTerm.value = v.trim(); }, 350); });
onBeforeUnmount(() => clearTimeout(customerTimer));
const searching = computed(() => props.open && f.value.requesterKind === 'customer' && !f.value.customer);
const customers = useGet(() => (searching.value ? `${API}/customers` : null), () => (customerTerm.value ? { q: customerTerm.value } : undefined));
const found = computed(() => arr(customers.data.value));
function pickCustomer(c) { f.value.customer = c; customerText.value = ''; customerTerm.value = ''; }

// ---- what is still missing, in words (the save button says why it is off)
const halfLink = computed(() => !!f.value.refType !== !!f.value.refNumber.trim());
const missing = computed(() => {
  const v = f.value;
  if (!v.subject.trim()) return { ar: 'اكتب عنوان التذكرة.', en: 'Give the ticket a subject.' };
  if (!v.teamId) return { ar: 'اختر الفريق الذي يستلم التذكرة.', en: 'Pick the team that takes the ticket.' };
  if (v.requesterKind === 'staff' && !v.requesterUserId) return { ar: 'اختر الموظف صاحب الطلب.', en: 'Pick the member of staff who asks.' };
  if (v.requesterKind === 'customer' && !v.customer) return { ar: 'ابحث عن العميل واختره.', en: 'Search for the customer and pick it.' };
  if (v.requesterKind === 'other' && !v.requesterName.trim()) return { ar: 'اكتب اسم صاحب الطلب.', en: 'Type the name of the person who asks.' };
  if (halfLink.value) return { ar: 'أكمل ربط السجل (النوع والرقم معًا) أو أفرغه.', en: 'Complete the record link (kind and number together) or clear it.' };
  if (picking.value) return { ar: 'جارٍ قراءة الملفات…', en: 'Reading the files…' };
  return null;
});

function requesterPart(clear) {
  const v = f.value;
  const text = (s) => s.trim() || (clear ? null : undefined);
  const none = clear ? null : undefined;
  if (v.requesterKind === 'staff') return { requesterKind: 'staff', requesterUserId: v.requesterUserId, customerId: none, requesterName: none, requesterEmail: none, requesterPhone: none };
  if (v.requesterKind === 'customer') return { requesterKind: 'customer', customerId: v.customer.id, requesterUserId: none, requesterName: text(v.requesterName), requesterEmail: text(v.requesterEmail), requesterPhone: text(v.requesterPhone) };
  return { requesterKind: 'other', customerId: none, requesterUserId: none, requesterName: v.requesterName.trim(), requesterEmail: text(v.requesterEmail), requesterPhone: text(v.requesterPhone) };
}
async function save() {
  if (missing.value) return;
  const v = f.value;
  let done;
  if (editing.value) {
    // an edit says everything the form holds: what was emptied is cleared
    const body = { subject: v.subject.trim(), description: v.description.trim() || null, teamId: v.teamId, typeId: v.typeId || null, priority: Number(v.priority) || 0, ...requesterPart(true),
      refType: v.refType || null, refNumber: v.refType ? v.refNumber.trim() : null,
      ...(v.dueTouched ? { dueAt: v.dueAt && toDate(v.dueAt) ? toDate(v.dueAt).toISOString() : null } : {}) };
    done = await act.run(() => api.patch(`${API}/tickets/${props.ticket.id}`, body), { success: t('حُفظت التذكرة', 'Ticket saved'), invalidate: INVALIDATE });
  } else {
    const body = { subject: v.subject.trim(), description: v.description.trim() || undefined, teamId: v.teamId, typeId: v.typeId || undefined, priority: Number(v.priority) || 0, ...requesterPart(false),
      assigneeId: v.assigneeId || undefined, refType: v.refType || undefined, refNumber: v.refType ? v.refNumber.trim() : undefined,
      ...(v.files.length ? { files: toUploads(v.files) } : {}) };
    done = await act.run(() => api.postIdempotent(`${API}/tickets`, body), { success: (x) => t(`فُتحت التذكرة ${x?.number || ''}`, `Ticket ${x?.number || ''} opened`), invalidate: INVALIDATE });
  }
  if (done) { emit('done', done); emit('close'); }
}
</script>

<template>
  <WsFormDrawer :open="open" :title="editing ? { ar: `تعديل التذكرة ${ticket.number}`, en: `Edit ticket ${ticket.number}` } : { ar: 'تذكرة جديدة', en: 'New ticket' }"
                :submit-label="editing ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'فتح التذكرة', en: 'Open the ticket' }"
                :pending="act.pending.value" :error="act.error.value" :disabled="!!missing" :why="missing" @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.subject" :label="{ ar: 'العنوان', en: 'Subject' }" required full maxlength="250" />
      <SelectInput v-model="f.teamId" :label="{ ar: 'الفريق', en: 'Team' }" required :options="teamOptions" :placeholder="SELECT_PH" />
      <SelectInput v-model="f.typeId" :label="{ ar: 'النوع', en: 'Type' }" :options="typeOptions" :placeholder="{ ar: '— بلا نوع —', en: '— no type —' }" />
      <PillChoice v-model="f.priority" :label="{ ar: 'الأولوية', en: 'Priority' }" :options="PRIORITY_OPTIONS" full />

      <PillChoice v-model="f.requesterKind" :label="{ ar: 'صاحب الطلب', en: 'Requester' }" :options="kindOptions" full purple />
      <SelectInput v-if="f.requesterKind === 'staff'" v-model="f.requesterUserId" :label="{ ar: 'الموظف', en: 'Member of staff' }" required :options="people" :placeholder="SELECT_PH" full />
      <template v-else-if="f.requesterKind === 'customer'">
        <div class="field full">
          <div class="field-l">{{ t('العميل', 'Customer') }}<span class="text-bad"> *</span></div>
          <div v-if="f.customer" class="row wrap rounded-xl border border-line bg-soft px-3 py-2">
            <span class="cell-id">{{ f.customer.code }}</span>
            <span class="min-w-0 flex-1 text-[11.5px] font-extrabold" dir="auto">{{ nm(f.customer) }}</span>
            <Btn tone="ghost" size="sm" :label="{ ar: 'تغيير', en: 'Change' }" @click="f.customer = null" />
          </div>
          <template v-else>
            <input v-model="customerText" type="search" class="inp" :placeholder="t('ابحث باسم العميل أو رمزه…', 'Search by the customer name or code…')" :aria-label="t('بحث عن عميل', 'Search for a customer')">
            <div v-if="customers.error.value" class="field-err">{{ customers.error.value.localized ? customers.error.value.localized() : t('تعذّر البحث عن العملاء', 'The customer search failed') }}</div>
            <div v-else-if="customers.isFetching.value && !found.length" class="field-hint">{{ t('جارٍ البحث…', 'Searching…') }}</div>
            <div v-else-if="!found.length" class="field-hint">{{ t('لا عميل مطابق.', 'No matching customer.') }}</div>
            <div v-else class="max-h-[190px] overflow-y-auto rounded-xl border border-line-2">
              <button v-for="c in found" :key="c.id" type="button" class="flex w-full cursor-pointer items-center gap-2 border-0 border-t border-solid border-line-2 bg-white px-3 py-2 text-start first:border-t-0 hover:bg-soft" @click="pickCustomer(c)">
                <span class="cell-id flex-none">{{ c.code }}</span>
                <span class="min-w-0 flex-1 text-[11px] font-bold text-ink" dir="auto">{{ nm(c) }}</span>
                <span v-if="c.city" class="flex-none text-[9.5px] text-muted" dir="auto">{{ c.city }}</span>
              </button>
            </div>
          </template>
        </div>
        <TextInput v-model="f.requesterName" :label="{ ar: 'اسم المتواصل لدى العميل', en: 'Contact person' }" maxlength="190" />
      </template>
      <TextInput v-else v-model="f.requesterName" :label="{ ar: 'الاسم', en: 'Name' }" required maxlength="190" />
      <template v-if="f.requesterKind !== 'staff'">
        <TextInput v-model="f.requesterEmail" :label="{ ar: 'البريد الإلكتروني', en: 'E-mail' }" dir="ltr" maxlength="190" />
        <TextInput v-model="f.requesterPhone" :label="{ ar: 'الهاتف', en: 'Phone' }" dir="ltr" maxlength="40" />
      </template>

      <SelectInput v-if="!editing" v-model="f.assigneeId" :label="{ ar: 'المسؤول', en: 'Assignee' }" :options="people" :placeholder="{ ar: 'تلقائي: المسؤول الافتراضي للفريق', en: 'Automatic: the team default' }" full
                   :hint="{ ar: 'إن تُرك فارغًا تُسند التذكرة إلى المسؤول الافتراضي للفريق إن وُجد.', en: 'Left empty, the ticket goes to the team default assignee when there is one.' }" />
      <TextArea v-model="f.description" :label="{ ar: 'الوصف', en: 'Description' }" :rows="5" maxlength="20000" />
      <RecordLinkField v-model:type="f.refType" v-model:number="f.refNumber" :types="lookups?.recordTypes" />

      <div v-if="editing" class="field full">
        <DateInput :model-value="f.dueAt" time :label="{ ar: 'تاريخ الاستحقاق', en: 'Due date' }" @update:model-value="(v) => { f.dueAt = v; f.dueTouched = true; }" />
        <div class="row wrap">
          <span class="field-hint min-w-0 flex-1">{{ ticket.dueManual ? t('حُدِّد يدويًّا: هدف الفريق لم يعد يغيّره.', 'Set by hand: the team target no longer moves it.') : t('محسوب من هدف الفريق. تعديله هنا يثبّته.', 'Computed from the team target. Changing it here fixes it.') }}</span>
          <Btn v-if="ticket.dueManual || f.dueTouched" tone="ghost" size="sm" :label="{ ar: 'العودة إلى هدف الفريق', en: 'Back to the team target' }" @click="f.dueAt = ''; f.dueTouched = true" />
        </div>
      </div>
      <div v-else-if="targetNote" class="field full"><div class="field-hint">{{ targetNote }}</div></div>

      <FilePicker v-if="!editing" v-model="f.files" :lookups="lookups" :label="{ ar: 'مرفقات', en: 'Attachments' }" @busy="picking = $event" />
    </div>
  </WsFormDrawer>
</template>
