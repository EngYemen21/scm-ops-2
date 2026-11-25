<script setup>
// The person's own clock: GET /payroll/attendance/me → { linked, date, timezone, working, plannedIn, plannedOut, in,
// since, openId, workedMinutesToday }. «تسجيل الحضور» → POST /payroll/attendance/check-in, «تسجيل الانصراف» → …/check-out
// (one open presence at a time; the server refuses a second one). The time worked today counts on while the person is in.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, ErrorBanner, SectionCard } from '@/components';
import { t } from '@/i18n';
import { confirm } from '@/stores/ui';
import { fmtClock, fmtDay, hm } from './payroll';

const q = useGet('/payroll/attendance/me', undefined, { refetchInterval: 300_000 });
const me = computed(() => q.data.value || null);
const act = useAction({ invalidate: ['payroll'] });

// a minute hand for the running total (cleared when the card goes away)
const now = ref(Date.now());
let timer = null;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 30_000); });
onBeforeUnmount(() => clearInterval(timer));
const running = computed(() => (me.value?.in && me.value.since ? Math.max(0, Math.floor((now.value - new Date(me.value.since).getTime()) / 60000)) : 0));
const workedToday = computed(() => (me.value?.workedMinutesToday || 0) + running.value);

async function checkIn() {
  const r = await act.run(() => api.postIdempotent('/payroll/attendance/check-in'), { success: t('سُجِّل حضورك', 'Checked in') });
  if (r) q.refetch();
}
async function checkOut() {
  if (!(await confirm({ title: { ar: 'تسجيل الانصراف الآن؟', en: 'Check out now?' }, sub: { ar: `داخل منذ ${fmtClock(me.value?.since)}`, en: `In since ${fmtClock(me.value?.since)}` }, tone: 'dark', okLabel: { ar: 'انصراف', en: 'Check out' } }))) return;
  const r = await act.run(() => api.postIdempotent('/payroll/attendance/check-out'), { success: t('سُجِّل انصرافك', 'Checked out') });
  if (r) q.refetch();
}
</script>

<template>
  <SectionCard :title="{ ar: 'ساعة الدوام', en: 'My clock' }">
    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[110px]" />
    <div v-else-if="me && !me.linked" class="text-[11px] text-muted">{{ t('حسابك غير مربوط بسجل موظف.', 'Your login is not linked to an employee record.') }}</div>
    <template v-else-if="me">
      <div class="row wrap !items-start">
        <div class="min-w-0 flex-1">
          <div class="text-[10px] text-muted">{{ t('اليوم', 'Today') }} <span class="num ltr inline-block">{{ fmtDay(me.date) }}</span><span v-if="me.timezone" class="num"> · {{ me.timezone }}</span></div>
          <div class="mt-1 text-[12px] font-extrabold">
            <template v-if="me.working">{{ t('الدوام المخطط', 'Planned') }}: <span class="num ltr inline-block">{{ me.plannedIn || '—' }} – {{ me.plannedOut || '—' }}</span></template>
            <template v-else>{{ t('لا دوام مخطط اليوم (عطلة)', 'No work planned today (day off)') }}</template>
          </div>
          <div class="mt-2 flex items-baseline gap-1.5">
            <span class="num text-[24px] font-bold" :class="me.in ? 'text-ok' : 'text-ink'">{{ hm(workedToday, false) }}</span>
            <span class="text-[10px] text-muted">{{ t('ساعة عمل اليوم', 'hours worked today') }}</span>
          </div>
          <div class="mt-1 text-[10.5px] font-bold" :class="me.in ? 'text-ok' : 'text-muted'">
            <template v-if="me.in">● {{ t('داخل منذ', 'In since') }} <span class="num ltr inline-block">{{ fmtClock(me.since) }}</span></template>
            <template v-else>{{ t('غير مسجَّل حضورًا الآن', 'Not checked in now') }}</template>
          </div>
        </div>
        <div class="flex flex-col gap-2">
          <Btn v-if="!me.in" tone="success" class="!h-12 min-w-[140px]" :loading="act.pending.value" :label="{ ar: 'تسجيل الحضور', en: 'Check in' }" @click="checkIn" />
          <Btn v-else tone="dark" class="!h-12 min-w-[140px]" :loading="act.pending.value" :label="{ ar: 'تسجيل الانصراف', en: 'Check out' }" @click="checkOut" />
        </div>
      </div>
      <div class="hint !mt-2.5">{{ t('نسيت التسجيل أو أخطأت فيه؟ يُصحِّحه مسؤول الحضور بسبب مكتوب، ويبقى الأصل محفوظًا مع سجل التغيير.', 'Forgot to clock or clocked wrong? The timekeeper corrects it with a written reason; the original stays on record with the change log.') }}</div>
    </template>
  </SectionCard>
</template>
