<script setup>
// One category of approval requests: what a request of this kind must carry, who approves it (order, who is a must,
// from which amount each is asked), how many approvals are enough — and, for the approvals manager, the form that
// creates or changes it.
//   <CategoryDrawer :code="codeOrNull" :can-manage :can-request @close="…" @saved="(code) => …" @raise="(code) => …" />
// `code` = 'new' opens the form of a new category. GET /approvals/categories/{code} · POST /approvals/categories ·
// PATCH /approvals/categories/{code} (approval.manage). A category that came from Odoo is changed in Odoo while the
// section follows it (`owned`): the form is not offered and the reason is said.
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { bi, fmtMoney, fmtNum, t } from '@/i18n';
import { FIELD_KEYS, FIELD_LABELS, LEVEL_LABELS, MODE_LABELS, SOURCE_CHIP, TONE, catName, own, personName, useLookups } from './approvals';

const props = defineProps({
  /** The category's code; 'new' = the form of a new one; null closes the drawer. */
  code: { type: String, default: null },
  canManage: { type: Boolean, default: false },
  canRequest: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'saved', 'raise']);

const isNew = computed(() => props.code === 'new');
const q = useGet(() => (props.code && !isNew.value ? `/approvals/categories/${encodeURIComponent(props.code)}` : null));
const c = computed(() => (props.code && !isNew.value ? q.data.value || null : null));
const editing = ref(false);
const formOpen = computed(() => props.canManage && (isNew.value || (editing.value && !!c.value && !c.value.owned)));
const lookups = useLookups(() => formOpen.value);
const act = useAction({ invalidate: ['approvals'] });

let seq = 0;
const blank = () => ({ code: '', nameAr: '', nameEn: '', description: '', sort: 0, active: true, approvalMode: 'parallel', minApprovals: 1, allowSelfApproval: false,
  fields: Object.fromEntries(FIELD_KEYS.map((k) => [k, k === 'document' ? 'optional' : 'no'])), approvers: [] });
const form = reactive(blank());
const errors = ref({});
/** The approver list is sent only when it was touched, so editing a name never drops an approver the form cannot show. */
const approversTouched = ref(false);

function fill(cat) {
  Object.assign(form, blank());
  errors.value = {}; approversTouched.value = false; act.clearError();
  if (!cat) return;
  Object.assign(form, {
    code: cat.code, nameAr: cat.nameAr, nameEn: cat.nameEn === cat.nameAr ? '' : cat.nameEn, description: cat.description || '', sort: cat.sort, active: cat.active, approvalMode: cat.approvalMode,
    minApprovals: cat.minApprovals, allowSelfApproval: cat.allowSelfApproval, fields: { ...cat.fields },
    approvers: cat.approvers.filter((a) => a.user?.id).map((a) => ({ key: `e${seq++}`, user: a.user.id, required: a.required, minAmount: a.minAmount == null ? null : Number(a.minAmount) })),
  });
}
watch(() => props.code, (code) => { editing.value = false; if (code === 'new') fill(null); }, { immediate: true });
function startEdit() { fill(c.value); editing.value = true; }
function stopEdit() { if (isNew.value) emit('close'); else editing.value = false; }

/** People of Odoo on this category who have no user here: the form cannot name them. */
const unmatched = computed(() => (c.value?.approvers || []).filter((a) => !a.user?.id).map((a) => personName(a.user)));
const userOptions = (row) => lookups.data.value.users.filter((u) => u.id === row.user || !form.approvers.some((a) => a.user === u.id)).map((u) => ({ v: u.id, l: `${personName(u)} · ${u.username}` }));
const touch = () => { approversTouched.value = true; };
function addApprover() { form.approvers = [...form.approvers, { key: `n${seq++}`, user: '', required: false, minAmount: null }]; touch(); }
function dropApprover(i) { form.approvers = form.approvers.filter((_, j) => j !== i); touch(); }
function move(i, by) {
  const j = i + by;
  if (j < 0 || j >= form.approvers.length) return;
  const rows = [...form.approvers];
  [rows[i], rows[j]] = [rows[j], rows[i]];
  form.approvers = rows; touch();
}
const levelOptions = (field) => (field === 'document' ? ['optional', 'required'] : ['no', 'optional', 'required']).map((v) => ({ v, l: LEVEL_LABELS[v] }));
const MODE_OPTIONS = Object.entries(MODE_LABELS).map(([v, l]) => ({ v, l }));
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
const COLS = 'minmax(170px,1.5fr) 96px 116px 84px';

async function save() {
  const errs = {};
  if (!form.nameAr.trim()) errs.nameAr = t('اسم النوع إلزامي', 'The name is required');
  if (!(Number(form.minApprovals) >= 1)) errs.minApprovals = t('واحد على الأقل', 'At least one');
  const rows = form.approvers.filter((a) => a.user);
  form.approvers.forEach((a, i) => { if (!a.user) errs[`approvers.${i}.user`] = t('اختر المعتمد أو احذف السطر', 'Pick the approver or remove the line'); });
  const sendApprovers = isNew.value || approversTouched.value;
  const count = sendApprovers ? rows.length : c.value?.approvers.length || 0;
  if (!errs.minApprovals && count > 0 && Number(form.minApprovals) > count) errs.minApprovals = t(`أكبر من عدد المعتمدين (${count})`, `More than the approvers (${count})`);
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = {
    nameAr: form.nameAr.trim(), nameEn: form.nameEn.trim() || null, description: form.description.trim() || null, sort: Number(form.sort) || 0, approvalMode: form.approvalMode,
    minApprovals: Number(form.minApprovals), allowSelfApproval: !!form.allowSelfApproval, fields: { ...form.fields },
  };
  if (sendApprovers) body.approvers = rows.map((a, i) => ({ user: a.user, sequence: (i + 1) * 10, required: !!a.required, minAmount: a.minAmount > 0 ? a.minAmount : null }));
  if (isNew.value) body.code = form.code.trim() || null; else body.active = !!form.active;
  const saved = await act.run(() => (isNew.value ? api.postIdempotent('/approvals/categories', body) : api.patch(`/approvals/categories/${encodeURIComponent(c.value.code)}`, body)),
    { success: isNew.value ? t('أُضيف النوع', 'Category added') : t('حُفظ النوع', 'Category saved') });
  if (!saved) return;
  editing.value = false;
  emit('saved', saved.code);
}
// what the server refuses lands on its field
watch(() => act.error.value, (e) => { if (e) { const fe = e.fieldErrors(); if (Object.keys(fe).length) errors.value = { ...errors.value, ...fe }; } });

const required = computed(() => FIELD_KEYS.filter((f) => c.value?.fields?.[f] === 'required'));
const optional = computed(() => FIELD_KEYS.filter((f) => c.value?.fields?.[f] === 'optional'));
const title = computed(() => (isNew.value ? { ar: 'نوع طلب جديد', en: 'New category' } : c.value ? catName(c.value) : props.code));
const MANAGER_APPROVAL = { approver: { ar: 'مدير الموظف معتمد', en: 'The employee’s manager is an approver' }, required: { ar: 'اعتماد مدير الموظف إلزامي', en: 'The employee’s manager must approve' } };
</script>

<template>
  <Drawer :open="!!code" :width="formOpen ? 700 : 600" :z-index="formOpen ? 70 : 60" @close="emit('close')">
    <template #title><span dir="auto">{{ typeof title === 'string' ? title : bi(title) }}</span></template>
    <template v-if="c" #sub><span class="num ltr inline-block">{{ c.code }}</span></template>
    <template v-if="c && !formOpen" #headExtra>
      <Chip v-if="c.source === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
      <Chip :label="c.active ? { ar: 'مفعّل', en: 'Active' } : { ar: 'موقوف', en: 'Stopped' }" :fg="c.active ? TONE.ok.fg : TONE.off.fg" :bg="c.active ? TONE.ok.bg : TONE.off.bg" />
    </template>

    <ErrorBanner :error="q.error.value || lookups.query.error.value" :closable="false" />
    <div v-if="!isNew && q.isLoading.value && !c" class="skel h-[200px]" />

    <!-- ───────────── the form (approval.manage) ───────────── -->
    <template v-if="formOpen">
      <form class="form-grid" @submit.prevent="save">
        <TextInput v-model="form.nameAr" required :label="{ ar: 'اسم النوع', en: 'Name (Arabic)' }" :error="errors.nameAr || null" :placeholder="{ ar: 'مثال: طلب صرف', en: 'e.g. طلب صرف' }" />
        <TextInput v-model="form.nameEn" dir="ltr" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" :error="errors.nameEn || null" :placeholder="{ ar: 'اختياري', en: 'Optional' }" />
        <TextInput v-if="isNew" v-model="form.code" dir="ltr" mono :label="{ ar: 'الرمز', en: 'Code' }" :error="errors.code || null" :hint="{ ar: 'حروف لاتينية وأرقام؛ يُولَّد تلقائيًا إن تُرك فارغًا', en: 'Latin letters and digits; made automatically when left empty' }" />
        <NumberInput v-model="form.sort" :label="{ ar: 'الترتيب في القائمة', en: 'Order in the list' }" :min="0" :error="errors.sort || null" />
        <TextArea v-model="form.description" :rows="2" :label="{ ar: 'الوصف', en: 'Description' }" :error="errors.description || null" :placeholder="{ ar: 'متى يُستخدم هذا النوع؟', en: 'When is this category used?' }" />

        <PillChoice v-model="form.approvalMode" full :label="{ ar: 'طريقة سؤال المعتمدين', en: 'How the approvers are asked' }" :options="MODE_OPTIONS" :error="errors.approvalMode || null" />
        <NumberInput v-model="form.minApprovals" required :label="{ ar: 'الحد الأدنى للاعتمادات', en: 'Minimum approvals' }" :min="1" :max="20" :error="errors.minApprovals || null"
                     :hint="{ ar: 'يُعتمد الطلب حين يبلغها ويعتمد كل معتمد إلزامي', en: 'The request is approved once it reaches them and every required approver approved' }" />
        <div class="field">
          <div class="field-l">{{ t('صاحب الطلب', 'The requester') }}</div>
          <div class="row wrap !gap-1.5">
            <button type="button" class="pill" :class="{ active: !form.allowSelfApproval }" :aria-pressed="!form.allowSelfApproval" @click="form.allowSelfApproval = false">{{ t('لا يعتمد طلبه', 'Never approves his own') }}</button>
            <button type="button" class="pill" :class="{ active: form.allowSelfApproval }" :aria-pressed="form.allowSelfApproval" @click="form.allowSelfApproval = true">{{ t('يعتمده إن كان من المعتمدين', 'May, when he is an approver') }}</button>
          </div>
        </div>
        <div v-if="!isNew" class="field">
          <div class="field-l">{{ t('الحالة', 'State') }}</div>
          <div class="row wrap !gap-1.5">
            <button type="button" class="pill" :class="{ active: form.active }" :aria-pressed="form.active" @click="form.active = true">{{ t('مفعّل', 'Active') }}</button>
            <button type="button" class="pill" :class="{ active: !form.active }" :aria-pressed="!form.active" @click="form.active = false">{{ t('موقوف — لا طلبات جديدة', 'Stopped — no new requests') }}</button>
          </div>
        </div>

        <div class="field full">
          <div class="field-l">{{ t('المعتمدون بالترتيب', 'Approvers, in order') }}</div>
          <div v-if="unmatched.length" class="hint amber !mt-0 mb-2">{{ t(`معتمدون من أودو بلا مستخدم هنا: ${unmatched.join('، ')}. يبقون كما هم ما لم تغيّر هذه القائمة؛ إن غيّرتها حُفظت كما تظهر هنا بدونهم.`, `Approvers of Odoo with no user here: ${unmatched.join(', ')}. They stay as they are unless this list is changed; a changed list is saved as shown here, without them.`) }}</div>
          <div class="col !gap-1.5">
            <div v-if="form.approvers.length" class="le-head grid gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint" :style="{ gridTemplateColumns: COLS }">
              <div>{{ t('المعتمد', 'Approver') }}</div><div>{{ t('اعتماده', 'His approval') }}</div><div>{{ t('يُسأل من مبلغ', 'Asked from amount') }}</div><div />
            </div>
            <div v-for="(a, i) in form.approvers" :key="a.key" class="le-row grid items-start gap-2 px-2.5 py-1" :style="{ gridTemplateColumns: COLS }">
              <SelectInput small :label="cap('المعتمد', 'Approver')" :model-value="a.user" :options="userOptions(a)" :placeholder="{ ar: '— اختر —', en: '— select —' }" :error="errors[`approvers.${i}.user`] || null" @update:model-value="(v) => { a.user = v; touch(); }" />
              <button type="button" class="pill !h-[30px] justify-center" :class="{ active: a.required }" :aria-pressed="a.required" @click="a.required = !a.required; touch()">{{ a.required ? t('إلزامي', 'Required') : t('اختياري', 'Optional') }}</button>
              <NumberInput small :label="cap('يُسأل من مبلغ', 'Asked from amount')" :model-value="a.minAmount" :min="0" :placeholder="{ ar: 'دائمًا', en: 'Always' }" :error="errors[`approvers.${i}.minAmount`] || null" @update:model-value="(v) => { a.minAmount = v; touch(); }" />
              <div class="row !gap-1">
                <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :disabled="i === 0" :aria-label="t('تقديم', 'Move up')" :title="t('تقديم', 'Move up')" @click="move(i, -1)">↑</button>
                <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :disabled="i === form.approvers.length - 1" :aria-label="t('تأخير', 'Move down')" :title="t('تأخير', 'Move down')" @click="move(i, 1)">↓</button>
                <button type="button" class="x-btn !h-7 !w-7 !text-[11px]" :aria-label="t('حذف', 'Remove')" :title="t('حذف', 'Remove')" @click="dropApprover(i)">✕</button>
              </div>
            </div>
            <div class="row wrap">
              <Btn tone="softPurple" size="sm" :label="{ ar: '+ معتمد', en: '+ Approver' }" @click="addApprover" />
              <span class="text-[9.5px] text-faint">{{ t('بلا معتمدين: يحدد صاحب الطلب معتمديه بنفسه. يُعرض هنا من يملك صلاحية فتح قسم الاعتمادات.', 'With no approver the requester names them himself. Only users who can open the approvals section are listed.') }}</span>
            </div>
          </div>
        </div>

        <div class="field full">
          <div class="field-l">{{ t('ما يحمله الطلب من هذا النوع', 'What a request of this category carries') }}</div>
          <div class="col !gap-1">
            <div v-for="f in FIELD_KEYS" :key="f" class="row wrap rounded-[10px] border border-line-2 px-2.5 py-1.5">
              <span class="min-w-[150px] flex-1 text-[11px] font-extrabold text-ink">{{ bi(FIELD_LABELS[f]) }}</span>
              <div class="row !gap-1">
                <button v-for="o in levelOptions(f)" :key="o.v" type="button" class="pill !h-[26px] !text-[9px]" :class="{ active: form.fields[f] === o.v }" :aria-pressed="form.fields[f] === o.v" @click="form.fields[f] = o.v">{{ bi(o.l) }}</button>
              </div>
            </div>
          </div>
        </div>
      </form>
    </template>

    <!-- ───────────── the category as everybody reads it ───────────── -->
    <template v-else-if="c">
      <div v-if="c.owned" class="hint purple !mt-0 mb-3">{{ t('هذا النوع مصدره أودو وما زال يُدار هناك: يُعدَّل في أودو فيصل التعديل تلقائيًا. يمكن رفع طلبات به من هنا.', 'This category comes from Odoo and is still managed there: change it in Odoo and the change arrives by itself. Requests can be raised with it here.') }}</div>
      <div v-if="c.description" class="mb-3 text-[12px] font-bold leading-[1.8] [text-align:match-parent]" dir="auto">{{ c.description }}</div>
      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'طريقة سؤال المعتمدين', en: 'How the approvers are asked' }" :v="bi(own(MODE_LABELS, c.approvalMode) || c.approvalMode)" />
        <KV :k="{ ar: 'الحد الأدنى للاعتمادات', en: 'Minimum approvals' }" :v="fmtNum(c.minApprovals)" ltr />
        <KV :k="{ ar: 'صاحب الطلب', en: 'The requester' }" :v="c.allowSelfApproval ? t('يعتمد طلبه إن كان من المعتمدين', 'May approve his own when he is an approver') : t('لا يعتمد طلبه', 'Never approves his own')" />
        <KV :k="{ ar: 'الطلبات', en: 'Requests' }" ltr>{{ fmtNum(c.counts?.requests || 0) }}<span v-if="c.counts?.pending" class="ms-1 font-sans text-[9.5px] text-warn">{{ t(`بانتظار ${fmtNum(c.counts.pending)}`, `${fmtNum(c.counts.pending)} waiting`) }}</span></KV>
        <KV v-if="c.sourceMeta?.sequenceCode" :k="{ ar: 'بادئة الترقيم في أودو', en: 'Number prefix in Odoo' }" :v="c.sourceMeta.sequenceCode" ltr />
        <KV v-if="c.sourceMeta?.managerApproval" :k="{ ar: 'مدير الموظف (في أودو)', en: 'Employee’s manager (in Odoo)' }" :v="bi(own(MANAGER_APPROVAL, c.sourceMeta.managerApproval) || c.sourceMeta.managerApproval)" />
      </div>

      <div class="field-l mb-1.5">{{ t('المعتمدون بالترتيب', 'Approvers, in order') }}</div>
      <div v-if="!c.approvers.length" class="empty !p-3.5">{{ t('لا معتمدين محددين — يختار صاحب الطلب معتمديه عند رفع الطلب.', 'No approver is named — the requester picks them when he raises the request.') }}</div>
      <div v-for="(a, i) in c.approvers" :key="a.id" class="row mb-1 rounded-[11px] border border-line-2 px-[12px] py-[8px]">
        <span class="num flex h-6 w-6 flex-none items-center justify-center rounded-full bg-soft text-[10px] font-bold text-muted">{{ i + 1 }}</span>
        <div class="min-w-0 flex-1">
          <div class="text-[11px] font-extrabold text-ink">{{ personName(a.user) }}<span v-if="!a.user.linked" class="ms-1 text-[9px] font-normal text-faint">{{ t('من أودو — بلا مستخدم هنا بعد', 'of Odoo — no user here yet') }}</span></div>
          <div v-if="a.minAmount != null" class="text-[9.5px] text-muted">{{ t('يُسأل من مبلغ', 'Asked from') }} <span class="num ltr">{{ fmtMoney(a.minAmount) }}</span></div>
        </div>
        <Chip small :label="a.required ? { ar: 'اعتماده إلزامي', en: 'Required' } : { ar: 'اختياري', en: 'Optional' }" :fg="a.required ? TONE.bad.fg : TONE.neutral.fg" :bg="a.required ? TONE.bad.bg : TONE.neutral.bg" />
      </div>

      <div class="field-l mb-1.5 mt-4">{{ t('ما يحمله الطلب', 'What a request carries') }}</div>
      <div class="rounded-xl bg-soft px-3.5 py-2.5 text-[11px] leading-[2]">
        <div><b>{{ t('إلزامي:', 'Required:') }}</b> {{ required.length ? required.map((f) => bi(FIELD_LABELS[f])).join('، ') : '—' }}</div>
        <div><b>{{ t('اختياري:', 'Optional:') }}</b> {{ optional.length ? optional.map((f) => bi(FIELD_LABELS[f])).join('، ') : '—' }}</div>
      </div>
    </template>

    <template v-if="formOpen" #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="save" />
        <Btn tone="soft" class="!h-11 w-[110px]" :disabled="act.pending.value" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="stopEdit" />
      </div>
      <div class="mt-2 text-[9px] leading-[1.7] text-faint">{{ t('التعديل يسري على الطلبات التي تُرسل بعده؛ الطلب المُرسل يبقى على القاعدة التي أُرسل بها · يُسجَّل في Audit Trail', 'A change applies to requests sent after it; a request already sent keeps the rule it was sent under · recorded in the audit trail') }}</div>
    </template>
    <template v-else-if="c && ((canRequest && c.active) || canManage)" #footer>
      <div class="col !gap-1.5">
        <div class="row wrap !gap-1.5">
          <Btn v-if="canRequest && c.active" tone="primary" :label="{ ar: '+ طلب من هذا النوع', en: '+ Request of this category' }" @click="emit('raise', c.code)" />
          <Btn v-if="canManage" tone="dark" :disabled="c.owned" :label="{ ar: 'تعديل النوع', en: 'Edit the category' }" @click="startEdit" />
        </div>
        <div v-if="canManage && c.owned" class="text-[10px] text-muted">{{ t('يُعدَّل في أودو ما دام قسم الاعتمادات يتابع أودو.', 'It is changed in Odoo while the approvals section follows Odoo.') }}</div>
      </div>
    </template>
  </Drawer>
</template>
