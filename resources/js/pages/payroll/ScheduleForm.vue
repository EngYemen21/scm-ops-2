<script setup>
// A work schedule — new (POST /payroll/schedules) or edited (PATCH /payroll/schedules/{id}) with the weekly stretches:
// { nameAr, nameEn?, timezone?, hoursPerDay?, isDefault?, active?, notes?, days?: [{ weekday 0=Mon..6=Sun, kind work|break, from HH:MM, to HH:MM, name? }] }.
// The stretches are one value (sent whole when changed). On a schedule that came from Odoo (`locked`) only the notes and
// «the default» are OPS's: the other fields are shown, not editable.
//   <ScheduleForm :schedule="null | {} | row" @close />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';
import FormShell from './FormShell.vue';
import { WEEKDAYS, hm } from './payroll';

const props = defineProps({
  schedule: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['payroll'] });
const isNew = computed(() => !!props.schedule && !props.schedule.id);
const locked = computed(() => !!props.schedule?.locked);
const f = reactive({ nameAr: '', nameEn: '', timezone: 'Asia/Riyadh', hoursPerDay: 8, isDefault: false, active: true, notes: '', days: [] });
let before = '';
let key = 0;
const errors = ref({});
const daysOf = (s) => (s?.days || []).map((d) => ({ key: ++key, weekday: String(d.weekday), kind: d.kind || 'work', from: d.from || '', to: d.to || '', name: d.name || '' }));
const daysJson = () => JSON.stringify(f.days.map((d) => ({ weekday: Number(d.weekday), kind: d.kind, from: d.from, to: d.to, name: d.name.trim() || null })));
watch(() => props.schedule, (s) => {
  if (!s) return;
  Object.assign(f, { nameAr: s.nameAr || '', nameEn: s.nameEn || '', timezone: s.timezone || 'Asia/Riyadh', hoursPerDay: s.hoursPerDay ?? 8, isDefault: !!s.isDefault, active: s.active !== false, notes: s.notes || '', days: daysOf(s) });
  before = daysJson();
  errors.value = {};
  act.clearError();
}, { immediate: true });
watch(() => act.error.value, (e) => { if (e) errors.value = { ...errors.value, ...e.fieldErrors() }; });

const weekdayOptions = WEEKDAYS.map((w, i) => [String(i), w]);
const KIND = [['work', { ar: 'عمل', en: 'Work' }], ['break', { ar: 'استراحة', en: 'Break' }]];
const addDay = () => f.days.push({ key: ++key, weekday: '6', kind: 'work', from: '08:00', to: '16:00', name: '' });
const removeDay = (i) => f.days.splice(i, 1);
/** Sunday to Thursday, 08:00–16:00. */
function standardWeek() {
  f.days = [6, 0, 1, 2, 3].map((w) => ({ key: ++key, weekday: String(w), kind: 'work', from: '08:00', to: '16:00', name: '' }));
}
const minutes = (v) => { const m = /^(\d{1,2}):(\d{2})$/.exec(String(v || '')); return m ? Number(m[1]) * 60 + Number(m[2]) : null; };
const weekly = computed(() => f.days.filter((d) => d.kind === 'work').reduce((s, d) => s + Math.max(0, (minutes(d.to) ?? 0) - (minutes(d.from) ?? 0)), 0));
const daysOk = computed(() => f.days.every((d) => minutes(d.from) != null && minutes(d.to) != null && minutes(d.to) > minutes(d.from)));
const ok = computed(() => f.nameAr.trim().length >= 2 && Number(f.hoursPerDay) > 0 && Number(f.hoursPerDay) <= 24 && daysOk.value);
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);

async function save() {
  if (!ok.value) return;
  const s = props.schedule;
  let body;
  if (locked.value) {
    body = { notes: f.notes.trim() || null, ...(f.isDefault && !s.isDefault ? { isDefault: true } : {}) };
  } else {
    body = { nameAr: f.nameAr.trim(), nameEn: f.nameEn.trim() || null, timezone: f.timezone.trim() || null, hoursPerDay: Number(f.hoursPerDay), active: f.active, notes: f.notes.trim() || null,
      ...(f.isDefault && !s.isDefault ? { isDefault: true } : {}), ...(isNew.value || daysJson() !== before ? { days: JSON.parse(daysJson()) } : {}) };
  }
  const r = await act.run(() => (isNew.value ? api.postIdempotent('/payroll/schedules', body) : api.patch(`/payroll/schedules/${encodeURIComponent(s.id)}`, body)), { success: t('حُفظ جدول الدوام', 'Schedule saved') });
  if (r) emit('close');
}
</script>

<template>
  <FormShell :open="!!schedule" :width="720" :title="isNew ? { ar: 'جدول دوام جديد', en: 'New work schedule' } : { ar: 'جدول الدوام', en: 'Work schedule' }" :sub="locked ? { ar: 'من أودو: الملاحظات واختيار الافتراضي فقط هنا', en: 'From Odoo: only the notes and the default choice here' } : null"
             :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="{ ar: 'الاسم، ساعات اليوم بين 0 و24، وكل فترة نهايتها بعد بدايتها (HH:MM)', en: 'A name, hours per day between 0 and 24, every stretch ending after it starts (HH:MM)' }"
             @submit="save" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.nameAr" required :disabled="locked" :label="{ ar: 'الاسم بالعربية', en: 'Name (Arabic)' }" :error="errors.nameAr" />
      <TextInput v-model="f.nameEn" dir="ltr" :disabled="locked" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" :error="errors.nameEn" />
      <TextInput v-model="f.timezone" dir="ltr" :disabled="locked" :label="{ ar: 'المنطقة الزمنية', en: 'Time zone' }" placeholder="Asia/Riyadh" :error="errors.timezone" />
      <NumberInput v-model="f.hoursPerDay" :min="0.5" :max="24" :step="0.5" :disabled="locked" :label="{ ar: 'ساعات يوم العمل', en: 'Hours of a working day' }" :error="errors.hoursPerDay" />
      <label class="field flex-row items-center gap-2 text-[11px] font-bold"><input v-model="f.isDefault" type="checkbox" class="h-4 w-4" :disabled="schedule?.isDefault">{{ t('الجدول الافتراضي لمن لا جدول له', 'Default for whoever has none') }}</label>
      <label class="field flex-row items-center gap-2 text-[11px] font-bold"><input v-model="f.active" type="checkbox" class="h-4 w-4" :disabled="locked">{{ t('مفعَّل', 'Active') }}</label>
    </div>

    <div class="row mb-1.5 mt-4">
      <b class="text-[12px]">{{ t('فترات الأسبوع', 'Weekly stretches') }}</b>
      <span class="text-[10px] text-muted">{{ t('عمل أسبوعيًا', 'work a week') }} <span class="num">{{ hm(weekly, false) }}</span></span>
      <span class="grow" />
      <template v-if="!locked">
        <Btn size="sm" tone="soft" :label="{ ar: 'أسبوع قياسي (الأحد–الخميس 8–4)', en: 'Standard week (Sun–Thu 8–4)' }" @click="standardWeek" />
        <Btn size="sm" tone="softPurple" :label="{ ar: '+ فترة', en: '+ Stretch' }" @click="addDay" />
      </template>
    </div>
    <div v-if="!f.days.length" class="empty !p-3">{{ t('لا فترات — بلا فترات لا يُخطَّط دوام.', 'No stretch — without them no work is planned.') }}</div>
    <div v-else class="le-head grid grid-cols-[1.2fr_0.9fr_0.8fr_0.8fr_1.2fr_34px] gap-2 rounded-[10px] bg-soft px-2.5 py-1.5 text-[9.5px] font-extrabold text-faint">
      <span>{{ t('اليوم', 'Day') }}</span><span>{{ t('النوع', 'Kind') }}</span><span>{{ t('من', 'From') }}</span><span>{{ t('إلى', 'To') }}</span><span>{{ t('اسم (اختياري)', 'Name (optional)') }}</span><span />
    </div>
    <div v-for="(d, i) in f.days" :key="d.key" class="le-row grid grid-cols-[1.2fr_0.9fr_0.8fr_0.8fr_1.2fr_34px] items-start gap-2 px-2.5 py-1">
      <SelectInput v-model="d.weekday" small :disabled="locked" :label="cap('اليوم', 'Day')" :options="weekdayOptions" />
      <SelectInput v-model="d.kind" small :disabled="locked" :label="cap('النوع', 'Kind')" :options="KIND" />
      <TextInput v-model="d.from" small type="time" dir="ltr" :disabled="locked" :label="cap('من', 'From')" :error="errors[`days.${i}.from`]" />
      <TextInput v-model="d.to" small type="time" dir="ltr" :disabled="locked" :label="cap('إلى', 'To')" />
      <TextInput v-model="d.name" small :disabled="locked" :label="cap('الاسم', 'Name')" />
      <button v-if="!locked" type="button" class="x-btn h-8 w-8 rounded-[8px] text-[14px] text-bad hover:bg-[#fdecec]" :aria-label="t('حذف الفترة', 'Remove the stretch')" @click="removeDay(i)">×</button>
    </div>
    <div v-if="errors.days" class="field-err mt-1">{{ errors.days }}</div>

    <TextArea v-model="f.notes" field-class="mt-3.5" :rows="2" :label="{ ar: 'ملاحظات', en: 'Notes' }" :error="errors.notes" />
  </FormShell>
</template>
