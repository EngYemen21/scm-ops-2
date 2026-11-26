<script setup>
// Add (POST /credit/stages) or change (PATCH /credit/stages/{id}) a stage of the workflow — credit.manage.
// What a stage is: code and names, the state a request has in it, its order, who decides it (a role and / or a person
// given by username), any one or every approver, target hours with the reminder before and the escalation after (to a
// role), and its flags (papers, signature, auto-send of the signature, locked request fields, visible on the portal,
// folded). An edit sends only what changed; «أرشفة» / «إعادة تفعيل» sends { active }.
//   <StageForm :stage="null | {} (new) | stage" @close="…" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Drawer, ErrorBanner, Field, NumberInput, PillChoice, SelectInput, TextInput } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { useCreditLookups } from './credit';
import { MODE } from './labels';

const props = defineProps({
  /** null = closed · {} = a new stage · a stage of the lookups = change it */
  stage: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const lk = useCreditLookups();
const act = useAction({ invalidate: ['credit'] });
const isOpen = computed(() => !!props.stage);
const edit = computed(() => !!props.stage?.id);

const FLAGS = [
  ['requireDocuments', { ar: 'تتطلب اكتمال الوثائق', en: 'Requires the papers' }],
  ['requireSignature', { ar: 'تتطلب التوقيع', en: 'Requires the signature' }],
  ['autoSendSignature', { ar: 'تُصدر الاتفاقية للتوقيع عند دخولها', en: 'Issues the agreement for signature on entering' }],
  ['lockRequestFields', { ar: 'تقفل بيانات الطلب', en: 'Locks the request fields' }],
  ['portalVisible', { ar: 'ظاهرة للعميل في البوابة', en: 'Visible to the customer on the portal' }],
  ['fold', { ar: 'مطوية في العرض', en: 'Folded in views' }],
];
const NUMS = ['sequence', 'slaHours', 'reminderBeforeHours', 'escalateAfterHours'];
const blank = () => ({
  code: '', nameAr: '', nameEn: '', state: 'in_review', sequence: null, approvalMode: 'any_user', responsibleRole: '', responsibleUserId: '', escalationRole: '',
  slaHours: null, reminderBeforeHours: null, escalateAfterHours: null, ...Object.fromEntries(FLAGS.map(([k]) => [k, false])),
});
const fromStage = (s) => ({
  code: s.code || '', nameAr: s.nameAr || '', nameEn: s.nameEn || '', state: s.state || '', sequence: s.sequence ?? null, approvalMode: s.approvalMode || 'any_user',
  responsibleRole: s.responsibleRole?.key || '', responsibleUserId: s.responsibleUser ? s.responsibleUser.username || String(s.responsibleUser.id || '') : '', escalationRole: s.escalationRole?.key || '',
  slaHours: s.slaHours ?? null, reminderBeforeHours: s.reminderBeforeHours ?? null, escalateAfterHours: s.escalateAfterHours ?? null,
  ...Object.fromEntries(FLAGS.map(([k]) => [k, !!s[k]])),
});
const v = reactive(blank());
let initial = blank();
const errors = ref({});
watch(() => props.stage, (s) => {
  if (!s) return;
  initial = s.id ? fromStage(s) : blank();
  Object.assign(v, JSON.parse(JSON.stringify(initial)));
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const modes = Object.entries(MODE).map(([k, l]) => ({ v: k, l }));
const out = (k, x) => {
  if (NUMS.includes(k)) return x == null || x === '' ? null : Number(x);
  if (typeof x === 'boolean') return x;
  return String(x ?? '').trim() === '' ? null : String(x).trim();
};
async function submit() {
  const errs = {};
  if (!v.code.trim()) errs.code = t('هذا الحقل إلزامي', 'Required');
  if (!v.nameAr.trim()) errs.nameAr = t('هذا الحقل إلزامي', 'Required');
  if (!v.state) errs.state = t('هذا الحقل إلزامي', 'Required');
  errors.value = errs;
  if (Object.keys(errs).length) return;
  const body = {};
  for (const k of Object.keys(v)) {
    const now = out(k, v[k]);
    if (edit.value) { if (now !== out(k, initial[k])) body[k] = now; } else if (now !== null) body[k] = now;
  }
  if ('sequence' in body && body.sequence === null) delete body.sequence; // the API takes an integer; empty = keep / put at the end
  if (edit.value && !Object.keys(body).length) { emit('close'); return; }
  const r = await act.run(() => (edit.value ? api.patch(`/credit/stages/${props.stage.id}`, body) : api.postIdempotent('/credit/stages', body)), { success: t('حُفظت المرحلة', 'Stage saved') });
  if (r) emit('close');
}
async function setActive(active) {
  const s = props.stage;
  if (!active && !(await confirm({
    title: { ar: `أرشفة المرحلة «${s.nameAr}»؟`, en: `Archive the stage "${s.nameEn || s.nameAr}"?` },
    sub: s.requests ? { ar: `فيها ${s.requests} طلب مفتوح — قد يرفض الخادم أرشفتها قبل نقلها`, en: `${s.requests} open request(s) are in it — the server may refuse until they move on` } : { ar: 'تخرج من مسار الطلبات الجديدة وتبقى في سجل ما مرّ بها', en: 'It leaves the path of new requests and stays on the record of those that went through it' },
    tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' },
  }))) return;
  const r = await act.run(() => api.patch(`/credit/stages/${s.id}`, { active }), { success: active ? t('أُعيد تفعيلها', 'Reactivated') : t('أُرشفت', 'Archived') });
  if (r) emit('close');
}
const title = computed(() => (edit.value ? { ar: `تعديل المرحلة ${props.stage.code}`, en: `Change the stage ${props.stage.code}` } : { ar: 'مرحلة جديدة', en: 'New stage' }));
</script>

<template>
  <Drawer :open="isOpen" :width="620" :z-index="70" :title="title" @close="emit('close')">
    <div class="form-grid">
      <TextInput v-model="v.code" required dir="ltr" :label="{ ar: 'الرمز', en: 'Code' }" :placeholder="{ ar: 'finance_review', en: 'finance_review' }" :error="errors.code" />
      <SelectInput v-model="v.state" required :options="lk.stateOptions.value" :label="{ ar: 'حالة الطلب في هذه المرحلة', en: 'Request state in this stage' }" :error="errors.state" />
      <TextInput v-model="v.nameAr" required :label="{ ar: 'الاسم بالعربية', en: 'Name (AR)' }" :error="errors.nameAr" />
      <TextInput v-model="v.nameEn" dir="ltr" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (EN)' }" :error="errors.nameEn" />
      <NumberInput v-model="v.sequence" :min="0" :max="9999" :step="1" :label="{ ar: 'الترتيب في المسار', en: 'Order in the path' }" :hint="{ ar: 'الأصغر أولًا', en: 'Smallest first' }" :error="errors.sequence" />
      <PillChoice v-model="v.approvalMode" :options="modes" :label="{ ar: 'طريقة الاعتماد', en: 'Approval mode' }" :error="errors.approvalMode" />
      <SelectInput v-model="v.responsibleRole" :options="lk.roleOptions.value" :placeholder="{ ar: '— بلا دور —', en: '— no role —' }" :label="{ ar: 'الدور المسؤول', en: 'Responsible role' }" :error="errors.responsibleRole" />
      <TextInput v-model="v.responsibleUserId" dir="ltr" :label="{ ar: 'المسؤول بالاسم (اسم المستخدم)', en: 'Responsible person (username)' }" :hint="{ ar: 'فارغ = الدور وحده يقرّر', en: 'Empty = the role alone decides' }" :error="errors.responsibleUserId" />
      <NumberInput v-model="v.slaHours" :min="0" :max="2000" :label="{ ar: 'المدة المستهدفة (ساعات عمل)', en: 'Target time (working hours)' }" :error="errors.slaHours" />
      <NumberInput v-model="v.reminderBeforeHours" :min="0" :max="2000" :label="{ ar: 'التذكير قبل الموعد (س)', en: 'Reminder before due (h)' }" :error="errors.reminderBeforeHours" />
      <NumberInput v-model="v.escalateAfterHours" :min="0" :max="2000" :label="{ ar: 'التصعيد بعد الموعد (س)', en: 'Escalate after due (h)' }" :error="errors.escalateAfterHours" />
      <SelectInput v-model="v.escalationRole" :options="lk.roleOptions.value" :placeholder="{ ar: '— مدير الائتمان —', en: '— credit manager —' }" :label="{ ar: 'التصعيد إلى دور', en: 'Escalate to role' }" :error="errors.escalationRole" />
    </div>
    <Field class="mt-3.5" :label="{ ar: 'المتطلبات والخيارات', en: 'Requirements and options' }">
      <div class="grid grid-cols-2 gap-1.5 max-[520px]:grid-cols-1">
        <label v-for="[k, l] in FLAGS" :key="k" class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-line-2 px-2.5 py-2 text-[10.5px]">
          <input v-model="v[k]" type="checkbox" class="h-4 w-4 accent-[#654e92]">
          <span>{{ t(l.ar, l.en) }}</span>
        </label>
      </div>
    </Field>
    <div v-if="edit && stage.unmapped" class="hint amber">{{ t('هذه المرحلة غير مربوطة بحالة طلب معروفة — اختر حالتها.', 'This stage maps to no known request state — choose its state.') }}</div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex flex-wrap gap-2">
        <Btn tone="dark" class="!h-11 flex-1 !text-[11.5px]" :loading="act.pending.value" :label="edit ? { ar: 'حفظ التعديلات', en: 'Save changes' } : { ar: 'إضافة المرحلة', en: 'Add the stage' }" @click="submit" />
        <Btn v-if="edit && stage.active" tone="dangerOutline" class="!h-11" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="setActive(false)" />
        <Btn v-if="edit && !stage.active" tone="softGreen" class="!h-11" :loading="act.pending.value" :label="{ ar: 'إعادة تفعيل', en: 'Reactivate' }" @click="setActive(true)" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
