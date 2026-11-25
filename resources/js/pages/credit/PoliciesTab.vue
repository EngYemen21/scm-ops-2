<script setup>
// The policies of the section (GET /credit/lookups `settings`; each saved with PUT /credit/settings/{group} { value }):
//   calendar   how target hours are counted: «working» (working days Sun..Sat = 0..6, day start / end, holidays) or
//              «calendar» (every hour counts)
//   documents  which of the papers a stage that requires papers asks for
//   reminders  how often a late stage is reminded again (hours) and how many times at most
//   signature  how many days an agreement sent for signature waits before it expires
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, ErrorBanner, Field, NumberInput, PillChoice, SectionCard, TextInput } from '@/components';
import { t } from '@/i18n';
import { fmtDay, labelOf, useCreditLookups } from './credit';
import { WEEKDAYS } from './labels';

const lk = useCreditLookups();
const settings = lk.settings;
const act = useAction({ invalidate: ['credit'] });
const saving = ref(null);

const cal = reactive({ mode: 'working', weekdays: [], dayStart: '', dayEnd: '', holidays: [] });
const docs = reactive({ required: [] });
const rem = reactive({ repeatHours: null, max: null });
const sig = reactive({ expiryDays: null });
const newHoliday = ref('');
const errors = ref({});

/** The forms start from what is saved, and again after every save (the lookups refresh). */
watch(settings, (s) => {
  const c = s.calendar || {};
  Object.assign(cal, { mode: c.mode || 'working', weekdays: [...(c.weekdays || [])].map(Number), dayStart: c.dayStart || '', dayEnd: c.dayEnd || '', holidays: [...(c.holidays || [])] });
  docs.required = [...(s.documents?.required || [])];
  Object.assign(rem, { repeatHours: s.reminders?.repeatHours ?? null, max: s.reminders?.max ?? null });
  sig.expiryDays = s.signature?.expiryDays ?? null;
}, { immediate: true });

const MODES = [{ v: 'working', l: { ar: 'ساعات العمل فقط', en: 'Working hours only' } }, { v: 'calendar', l: { ar: 'كل الساعات', en: 'Every hour' } }];
const toggleDay = (d) => { cal.weekdays = cal.weekdays.includes(d) ? cal.weekdays.filter((x) => x !== d) : [...cal.weekdays, d].sort((a, b) => a - b); };
function addHoliday() {
  const d = newHoliday.value;
  if (!d || cal.holidays.includes(d)) return;
  cal.holidays = [...cal.holidays, d].sort();
  newHoliday.value = '';
}
const removeHoliday = (d) => { cal.holidays = cal.holidays.filter((x) => x !== d); };
const slots = computed(() => lk.slots.value);
const toggleSlot = (s) => { docs.required = docs.required.includes(s) ? docs.required.filter((x) => x !== s) : [...docs.required, s]; };

async function save(group, value, check) {
  const errs = check ? check() : {};
  errors.value = errs;
  if (Object.keys(errs).length) return;
  saving.value = group;
  await act.run(() => api.put(`/credit/settings/${group}`, { value }), { success: t('حُفظت السياسة', 'Policy saved') });
  saving.value = null;
}
const hhmm = (x) => /^([01]\d|2[0-3]):[0-5]\d$/.test(String(x || ''));
const saveCalendar = () => save('calendar', { mode: cal.mode, weekdays: [...cal.weekdays], dayStart: cal.dayStart, dayEnd: cal.dayEnd, holidays: [...cal.holidays] }, () => {
  const e = {};
  if (cal.mode === 'working') {
    if (!cal.weekdays.length) e.weekdays = t('اختر يوم عمل واحدًا على الأقل', 'Choose at least one working day');
    if (!hhmm(cal.dayStart)) e.dayStart = t('بصيغة 08:00', 'As 08:00');
    if (!hhmm(cal.dayEnd)) e.dayEnd = t('بصيغة 17:00', 'As 17:00');
    if (hhmm(cal.dayStart) && hhmm(cal.dayEnd) && cal.dayEnd <= cal.dayStart) e.dayEnd = t('بعد بداية اليوم', 'After the day start');
  }
  return e;
});
const saveDocuments = () => save('documents', { required: [...docs.required] });
const saveReminders = () => save('reminders', { repeatHours: Number(rem.repeatHours), max: Number(rem.max) }, () => {
  const e = {};
  if (!(Number(rem.repeatHours) > 0)) e.repeatHours = t('أكبر من صفر', 'Above zero');
  if (!(Number.isInteger(Number(rem.max)) && Number(rem.max) >= 0)) e.max = t('عدد صحيح', 'A whole number');
  return e;
});
const saveSignature = () => save('signature', { expiryDays: Number(sig.expiryDays) }, () => (Number.isInteger(Number(sig.expiryDays)) && Number(sig.expiryDays) > 0 ? {} : { expiryDays: t('عدد أيام صحيح', 'A whole number of days') }));
</script>

<template>
  <ErrorBanner :error="lk.query.error.value || act.error.value" :closable="!lk.query.error.value" @close="act.clearError()" />
  <div class="grid grid-cols-2 gap-3 max-[900px]:grid-cols-1">
    <SectionCard :title="{ ar: 'تقويم العمل', en: 'Working calendar' }" :sub="{ ar: 'كيف تُحسب المدد المستهدفة للمراحل', en: 'How the target hours of the stages are counted' }">
      <PillChoice v-model="cal.mode" :options="MODES" :label="{ ar: 'طريقة الحساب', en: 'Counting' }" />
      <template v-if="cal.mode === 'working'">
        <Field class="mt-3" :label="{ ar: 'أيام العمل', en: 'Working days' }" :error="errors.weekdays">
          <div class="row wrap !gap-1.5">
            <button v-for="(d, i) in WEEKDAYS" :key="i" type="button" class="pill" :class="{ active: cal.weekdays.includes(i) }" :aria-pressed="cal.weekdays.includes(i)" @click="toggleDay(i)">{{ t(d.ar, d.en) }}</button>
          </div>
        </Field>
        <div class="form-grid mt-3">
          <TextInput v-model="cal.dayStart" type="time" dir="ltr" :label="{ ar: 'بداية اليوم', en: 'Day starts' }" :error="errors.dayStart" />
          <TextInput v-model="cal.dayEnd" type="time" dir="ltr" :label="{ ar: 'نهاية اليوم', en: 'Day ends' }" :error="errors.dayEnd" />
        </div>
        <Field class="mt-3" :label="{ ar: 'العطل الرسمية', en: 'Holidays' }">
          <div class="row wrap !gap-1.5">
            <DateInput v-model="newHoliday" small field-class="w-[170px]" />
            <Btn size="sm" tone="softPurple" :disabled="!newHoliday" :label="{ ar: '+ إضافة', en: '+ Add' }" @click="addHoliday" />
          </div>
          <div v-if="cal.holidays.length" class="row wrap mt-2 !gap-1.5">
            <span v-for="d in cal.holidays" :key="d" class="pill !cursor-default">
              <span class="num">{{ fmtDay(d) }}</span>
              <button type="button" class="ms-1 text-[10px] text-bad" :aria-label="t('إزالة', 'Remove')" @click="removeHoliday(d)">✕</button>
            </span>
          </div>
          <div v-else class="mt-1 text-[10px] text-faint">{{ t('لا عطل مسجلة.', 'No holiday recorded.') }}</div>
        </Field>
      </template>
      <div v-else class="hint">{{ t('كل ساعة تُحتسب، بلا أيام عمل ولا عطل.', 'Every hour counts — no working days, no holidays.') }}</div>
      <div class="row mt-3"><span class="grow" /><Btn size="sm" tone="dark" :loading="saving === 'calendar'" :label="{ ar: 'حفظ التقويم', en: 'Save the calendar' }" @click="saveCalendar" /></div>
    </SectionCard>

    <SectionCard :title="{ ar: 'الوثائق المطلوبة', en: 'Required papers' }" :sub="{ ar: 'ما تطلبه المرحلة التي «تتطلب الوثائق»', en: 'What a stage that “requires the papers” asks for' }">
      <div class="col !gap-1.5">
        <label v-for="s in slots" :key="s.slot" class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-line-2 px-2.5 py-2 text-[10.5px]">
          <input type="checkbox" class="h-4 w-4 accent-[#654e92]" :checked="docs.required.includes(s.slot)" @change="toggleSlot(s.slot)">
          <span class="flex-1" dir="auto">{{ labelOf(s) }}</span>
          <span v-if="s.many" class="text-[9px] text-faint">{{ t('أكثر من ملف', 'many files') }}</span>
        </label>
      </div>
      <div v-if="!slots.length" class="empty !p-3">{{ t('لا خانات وثائق.', 'No paper slots.') }}</div>
      <div class="row mt-3"><span class="grow" /><Btn size="sm" tone="dark" :loading="saving === 'documents'" :label="{ ar: 'حفظ الوثائق المطلوبة', en: 'Save the required papers' }" @click="saveDocuments" /></div>
    </SectionCard>

    <SectionCard :title="{ ar: 'التذكيرات', en: 'Reminders' }" :sub="{ ar: 'للمرحلة التي تجاوزت موعدها', en: 'For a stage past its due time' }">
      <div class="form-grid">
        <NumberInput v-model="rem.repeatHours" :min="1" :label="{ ar: 'يُعاد التذكير كل (ساعة)', en: 'Remind again every (hours)' }" :error="errors.repeatHours" />
        <NumberInput v-model="rem.max" :min="0" :step="1" :label="{ ar: 'أقصى عدد للتذكيرات', en: 'At most (reminders)' }" :error="errors.max" />
      </div>
      <div class="row mt-3"><span class="grow" /><Btn size="sm" tone="dark" :loading="saving === 'reminders'" :label="{ ar: 'حفظ التذكيرات', en: 'Save the reminders' }" @click="saveReminders" /></div>
    </SectionCard>

    <SectionCard :title="{ ar: 'التوقيع', en: 'Signature' }" :sub="{ ar: 'مهلة الاتفاقية المرسلة للتوقيع', en: 'How long an agreement sent for signature waits' }">
      <div class="form-grid">
        <NumberInput v-model="sig.expiryDays" :min="1" :step="1" :label="{ ar: 'تنتهي بعد (يوم)', en: 'Expires after (days)' }" :error="errors.expiryDays" />
      </div>
      <div v-if="lk.signatureProvider.value" class="hint">{{ t('المزوّد', 'Provider') }}: {{ labelOf(lk.signatureProvider.value) }}<template v-if="!lk.signatureProvider.value.remote"> — {{ t('رفع يدوي للنسخة الموقّعة', 'manual upload of the signed copy') }}</template></div>
      <div class="row mt-3"><span class="grow" /><Btn size="sm" tone="dark" :loading="saving === 'signature'" :label="{ ar: 'حفظ', en: 'Save' }" @click="saveSignature" /></div>
    </SectionCard>
  </div>
  <div class="hint">{{ t('قنوات الإشعار: داخل النظام دائمًا؛ البريد الإلكتروني وواتساب', 'Notification channels: in app always; e-mail and WhatsApp') }}
    <b>{{ lk.channels.value.email ? t('البريد مُعدّ', 'e-mail configured') : t('البريد غير مُعدّ', 'e-mail not configured') }}</b> ·
    <b>{{ lk.channels.value.whatsapp ? t('واتساب مُعدّ', 'WhatsApp configured') : t('واتساب غير مُعدّ', 'WhatsApp not configured') }}</b>
    — {{ t('ما لا مزوّد له يُسجَّل «لم يُرسل».', 'what has no provider is logged as "skipped".') }}</div>
</template>
