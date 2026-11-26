<script setup>
// An attendance policy — new (POST /payroll/policies) or edited (PATCH /payroll/policies/{id}):
// { nameAr, nameEn?, lateGraceMinutes, earlyGraceMinutes, isDefault?, active?, notes?,
//   late / early: [{ type rate|fix, thresholdMinutes, rate?, amountMinutes?, weights: [1st..5th] }],
//   absence: [{ counter, rate }], overtime: [{ dayType workday|weekend|holiday, activeAfterMinutes, rate }] }.
// A rule's lines are one value: sent whole, and only when they changed. On a policy from Odoo (`locked`) the grace, the
// default choice and the notes are OPS's; the name, the rules and the active flag are Odoo's.
//   <PolicyForm :policy="null | {} | row" @close />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { DAY_TYPE, RULE_TYPE, optionsOf } from './payroll';

const props = defineProps({
  policy: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['payroll'] });
const isNew = computed(() => !!props.policy && !props.policy.id);
const locked = computed(() => !!props.policy?.locked);
let key = 0;
const f = reactive({ nameAr: '', nameEn: '', lateGraceMinutes: 0, earlyGraceMinutes: 0, isDefault: false, active: true, notes: '', late: [], early: [], absence: [], overtime: [] });
const before = {};
const errors = ref({});

const timeLine = (l = {}) => ({ key: ++key, type: l.type || 'rate', thresholdMinutes: l.thresholdMinutes ?? 0, rate: l.rate ?? 1, amountMinutes: l.amountMinutes ?? 0, weights: [0, 1, 2, 3, 4].map((i) => l.weights?.[i] ?? null) });
const absenceLine = (l = {}, i = 0) => ({ key: ++key, counter: l.counter ?? i + 1, rate: l.rate ?? 1 });
const overtimeLine = (l = {}) => ({ key: ++key, dayType: l.dayType || 'workday', activeAfterMinutes: l.activeAfterMinutes ?? 0, rate: l.rate ?? 1.5 });
/** What the server takes for each rule — the form's lines without their keys. */
const out = {
  late: () => f.late.map((l) => ({ type: l.type, thresholdMinutes: Number(l.thresholdMinutes) || 0, ...(l.type === 'rate' ? { rate: Number(l.rate) || 0 } : { amountMinutes: Number(l.amountMinutes) || 0 }), weights: l.weights.map((w) => (w === null || w === '' ? null : Number(w))) })),
  early: () => f.early.map((l) => ({ type: l.type, thresholdMinutes: Number(l.thresholdMinutes) || 0, ...(l.type === 'rate' ? { rate: Number(l.rate) || 0 } : { amountMinutes: Number(l.amountMinutes) || 0 }), weights: l.weights.map((w) => (w === null || w === '' ? null : Number(w))) })),
  absence: () => f.absence.map((l) => ({ counter: Number(l.counter) || 1, rate: Number(l.rate) || 0 })),
  overtime: () => f.overtime.map((l) => ({ dayType: l.dayType, activeAfterMinutes: Number(l.activeAfterMinutes) || 0, rate: Number(l.rate) || 0 })),
};
watch(() => props.policy, (p) => {
  if (!p) return;
  Object.assign(f, { nameAr: p.nameAr || '', nameEn: p.nameEn || '', lateGraceMinutes: p.lateGraceMinutes ?? 0, earlyGraceMinutes: p.earlyGraceMinutes ?? 0, isDefault: !!p.isDefault, active: p.active !== false, notes: p.notes || '',
    late: (p.late || []).map(timeLine), early: (p.early || []).map(timeLine), absence: (p.absence || []).map(absenceLine), overtime: (p.overtime || []).map(overtimeLine) });
  for (const k of ['late', 'early', 'absence', 'overtime']) before[k] = JSON.stringify(out[k]());
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const add = { late: () => f.late.push(timeLine()), early: () => f.early.push(timeLine()), absence: () => f.absence.push(absenceLine({}, f.absence.length)), overtime: () => f.overtime.push(overtimeLine()) };
const remove = (kind, i) => f[kind].splice(i, 1);
const usedDayTypes = computed(() => f.overtime.map((l) => l.dayType));
const overtimeOk = computed(() => new Set(usedDayTypes.value).size === usedDayTypes.value.length && f.overtime.every((l) => Number(l.rate) > 0 && Number(l.rate) <= 10));
const ok = computed(() => f.nameAr.trim().length >= 2 && overtimeOk.value);
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
const TIME_KINDS = [['late', { ar: 'قاعدة التأخير', en: 'Lateness rule' }], ['early', { ar: 'قاعدة الخروج المبكر', en: 'Early-leave rule' }]];

async function save() {
  if (!ok.value) return;
  const p = props.policy;
  const own = { lateGraceMinutes: Number(f.lateGraceMinutes) || 0, earlyGraceMinutes: Number(f.earlyGraceMinutes) || 0, notes: f.notes.trim() || null, ...(f.isDefault && !p.isDefault ? { isDefault: true } : {}) };
  let body = own;
  if (!locked.value) {
    body = { ...own, nameAr: f.nameAr.trim(), nameEn: f.nameEn.trim() || null, active: f.active };
    for (const k of ['late', 'early', 'absence', 'overtime']) { const now = out[k](); if (isNew.value || JSON.stringify(now) !== before[k]) body[k] = now; }
  }
  const r = await act.run(() => (isNew.value ? api.postIdempotent('/payroll/policies', body) : api.patch(`/payroll/policies/${encodeURIComponent(p.id)}`, body)), { success: t('حُفظت سياسة الحضور', 'Attendance policy saved') });
  if (r) emit('close');
}
</script>

<template>
  <FormShell :open="!!policy" :width="860" :title="isNew ? { ar: 'سياسة حضور جديدة', en: 'New attendance policy' } : { ar: 'سياسة الحضور', en: 'Attendance policy' }"
             :sub="locked ? { ar: 'من أودو: السماح واختيار الافتراضي والملاحظات فقط هنا', en: 'From Odoo: only the grace, the default choice and the notes here' } : null"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="{ ar: 'الاسم، وقاعدة إضافي واحدة لكل نوع يوم بمعدل بين 0 و10', en: 'A name, and one overtime rule per kind of day with a rate between 0 and 10' }"
             @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.nameAr" required :disabled="locked" :label="{ ar: 'الاسم بالعربية', en: 'Name (Arabic)' }" :error="errors.nameAr" />
      <TextInput v-model="f.nameEn" dir="ltr" :disabled="locked" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" :error="errors.nameEn" />
      <NumberInput v-model="f.lateGraceMinutes" :min="0" :max="600" :label="{ ar: 'سماح التأخير (دقيقة)', en: 'Late grace (min)' }" :error="errors.lateGraceMinutes" />
      <NumberInput v-model="f.earlyGraceMinutes" :min="0" :max="600" :label="{ ar: 'سماح الخروج المبكر (دقيقة)', en: 'Early grace (min)' }" :error="errors.earlyGraceMinutes" />
      <label class="field flex-row items-center gap-2 text-[11px] font-bold"><input v-model="f.isDefault" type="checkbox" class="h-4 w-4" :disabled="policy?.isDefault">{{ t('السياسة الافتراضية', 'Default policy') }}</label>
      <label class="field flex-row items-center gap-2 text-[11px] font-bold"><input v-model="f.active" type="checkbox" class="h-4 w-4" :disabled="locked">{{ t('مفعَّلة', 'Active') }}</label>
    </div>

    <!-- lateness and early leave -->
    <div v-for="[kind, title] in TIME_KINDS" :key="kind" class="mt-4">
      <div class="row mb-1.5"><b class="text-[12px]">{{ t(title.ar, title.en) }}</b><span class="grow" /><Btn v-if="!locked" size="sm" tone="softPurple" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add[kind]()" /></div>
      <div v-if="!f[kind].length" class="text-[10px] text-faint">{{ t('بلا قاعدة: يُحتسب الوقت بعد السماح كما هو.', 'No rule: the time after the grace counts as it is.') }}</div>
      <template v-else>
        <div class="le-head grid grid-cols-[1fr_0.8fr_0.8fr_2.4fr_34px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint">
          <span>{{ t('الطريقة', 'How') }}</span><span>{{ t('بعد (دقيقة)', 'Above (min)') }}</span><span>{{ t('المعدل / المدة', 'Rate / time') }}</span><span>{{ t('مضاعف المرة 1…5 في الكشف', 'Weight of times 1…5 in the sheet') }}</span><span />
        </div>
        <div v-for="(l, i) in f[kind]" :key="l.key" class="le-row grid grid-cols-[1fr_0.8fr_0.8fr_2.4fr_34px] items-start gap-2 px-2.5 py-1">
          <SelectInput v-model="l.type" small :disabled="locked" :label="cap('الطريقة', 'How')" :options="optionsOf(RULE_TYPE)" />
          <NumberInput v-model="l.thresholdMinutes" small :min="0" :max="1440" :disabled="locked" :label="cap('بعد (دقيقة)', 'Above (min)')" />
          <NumberInput v-if="l.type === 'rate'" v-model="l.rate" small :min="0" :max="10" :step="0.25" :disabled="locked" :label="cap('المعدل', 'Rate')" />
          <NumberInput v-else v-model="l.amountMinutes" small :min="0" :max="1440" :disabled="locked" :label="cap('مدة ثابتة (دقيقة)', 'Fixed (min)')" />
          <div class="grid grid-cols-5 gap-1" :data-label="isMobile ? t('المضاعفات', 'Weights') : null">
            <NumberInput v-for="w in 5" :key="w" v-model="l.weights[w - 1]" small :min="0" :max="10" :step="0.25" :disabled="locked" :placeholder="String(w)" :aria-label="`${t('المرة', 'Time')} ${w}`" />
          </div>
          <button v-if="!locked" type="button" class="x-btn h-8 w-8 rounded-[8px] text-[14px] text-bad hover:bg-[#fdecec]" :aria-label="t('حذف السطر', 'Remove the line')" @click="remove(kind, i)">×</button>
        </div>
      </template>
    </div>

    <!-- absence -->
    <div class="mt-4">
      <div class="row mb-1.5"><b class="text-[12px]">{{ t('قاعدة الغياب', 'Absence rule') }}</b><span class="grow" /><Btn v-if="!locked" size="sm" tone="softPurple" :label="{ ar: '+ سطر', en: '+ Line' }" @click="add.absence()" /></div>
      <div v-if="!f.absence.length" class="text-[10px] text-faint">{{ t('بلا قاعدة: يوم الغياب يُحتسب بدقائقه المخططة.', 'No rule: an absent day counts its planned minutes.') }}</div>
      <template v-else>
        <div class="le-head grid grid-cols-[1fr_1fr_34px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint"><span>{{ t('الغياب رقم', 'Absence no.') }}</span><span>{{ t('مضاعف اليوم', 'Day multiplier') }}</span><span /></div>
        <div v-for="(l, i) in f.absence" :key="l.key" class="le-row grid grid-cols-[1fr_1fr_34px] items-start gap-2 px-2.5 py-1">
          <NumberInput v-model="l.counter" small :min="1" :max="31" :disabled="locked" :label="cap('الغياب رقم', 'Absence no.')" />
          <NumberInput v-model="l.rate" small :min="0" :max="10" :step="0.25" :disabled="locked" :label="cap('المضاعف', 'Multiplier')" />
          <button v-if="!locked" type="button" class="x-btn h-8 w-8 rounded-[8px] text-[14px] text-bad hover:bg-[#fdecec]" :aria-label="t('حذف السطر', 'Remove the line')" @click="remove('absence', i)">×</button>
        </div>
      </template>
    </div>

    <!-- overtime -->
    <div class="mt-4">
      <div class="row mb-1.5"><b class="text-[12px]">{{ t('قواعد العمل الإضافي', 'Overtime rules') }}</b><span class="grow" /><Btn v-if="!locked && f.overtime.length < 3" size="sm" tone="softPurple" :label="{ ar: '+ قاعدة', en: '+ Rule' }" @click="add.overtime()" /></div>
      <div v-if="!f.overtime.length" class="text-[10px] text-faint">{{ t('بلا قاعدة: لا يُحتسب إضافي.', 'No rule: no overtime counts.') }}</div>
      <template v-else>
        <div class="le-head grid grid-cols-[1.2fr_1fr_1fr_34px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint"><span>{{ t('نوع اليوم', 'Kind of day') }}</span><span>{{ t('بعد (دقيقة)', 'After (min)') }}</span><span>{{ t('المعدل', 'Rate') }}</span><span /></div>
        <div v-for="(l, i) in f.overtime" :key="l.key" class="le-row grid grid-cols-[1.2fr_1fr_1fr_34px] items-start gap-2 px-2.5 py-1">
          <SelectInput v-model="l.dayType" small :disabled="locked" :label="cap('نوع اليوم', 'Kind of day')" :options="optionsOf(DAY_TYPE)" />
          <NumberInput v-model="l.activeAfterMinutes" small :min="0" :max="1440" :disabled="locked" :label="cap('بعد (دقيقة)', 'After (min)')" />
          <NumberInput v-model="l.rate" small :min="0" :max="10" :step="0.25" :disabled="locked" :label="cap('المعدل', 'Rate')" />
          <button v-if="!locked" type="button" class="x-btn h-8 w-8 rounded-[8px] text-[14px] text-bad hover:bg-[#fdecec]" :aria-label="t('حذف القاعدة', 'Remove the rule')" @click="remove('overtime', i)">×</button>
        </div>
        <div v-if="!overtimeOk" class="field-err mt-1">{{ t('قاعدة واحدة لكل نوع يوم، ومعدل أكبر من صفر حتى 10', 'One rule per kind of day, a rate above 0 up to 10') }}</div>
      </template>
    </div>

    <div class="hint">{{ t('سطر التأخير أو الخروج المبكر يسري حين تتجاوز الدقائق حدّه: «نسبة» تضرب الدقائق في المعدل، و«مدة ثابتة» تحتسب مدة محددة؛ ومضاعفات المرة 1…5 تشدّد على تكرارها في الكشف (فارغ = 1). الغياب: مضاعف لليوم بحسب ترتيبه في الكشف. الإضافي: يُحتسب بعد الحد × المعدل.', 'A lateness or early-leave line applies above its threshold: “rate” multiplies the minutes, “fixed time” counts a set time; the weights of times 1…5 weigh repeats within the sheet (empty = 1). Absence: a multiplier of the day by its order in the sheet. Overtime: counted after the threshold × the rate.') }}</div>
    <TextArea v-model="f.notes" field-class="mt-3.5" :rows="2" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.notes" />
  </FormShell>
</template>
