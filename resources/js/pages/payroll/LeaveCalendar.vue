<script setup>
// Who is off, day by day, in one month: GET /payroll/leaves/calendar?from&to&department → { from, to, items: [{ id,
// number, employee, type { color, paid }, dateFrom, dateTo, halfDay, days, state }] } — among the people the caller may
// see. A request that still waits for a decision is drawn lighter and dashed. A grid of people × days on a wide screen,
// a list on a phone. `month` and `department` live in the query string; a coloured cell opens its request.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, SelectInput, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { t } from '@/i18n';
import { LEAVE_STATE, days, fmtDay, isPeriod, monthIso, pname, todayIso, typeColor, usePayrollLookups, usePayrollNav } from './payroll';

const nav = usePayrollNav();
const { departmentOptions } = usePayrollLookups();
const month = computed(() => (isPeriod(nav.get('month')) ? nav.get('month') : monthIso()));
const range = computed(() => {
  const [y, m] = month.value.split('-').map(Number);
  const last = new Date(y, m, 0).getDate();
  return { from: `${month.value}-01`, to: `${month.value}-${String(last).padStart(2, '0')}`, last, y, m };
});
const q = useGet('/payroll/leaves/calendar', () => ({ from: range.value.from, to: range.value.to, department: nav.get('department') || undefined }));
const items = computed(() => q.data.value?.items || []);

function shift(by) {
  const d = new Date(range.value.y, range.value.m - 1 + by, 1);
  nav.setFilter('month', monthIso(d));
}
const dayList = computed(() => Array.from({ length: range.value.last }, (_, i) => {
  const d = new Date(range.value.y, range.value.m - 1, i + 1);
  const iso = `${month.value}-${String(i + 1).padStart(2, '0')}`;
  return { n: i + 1, iso, wd: d.getDay(), today: iso === todayIso() };
}));
const WD = [['ح', 'Su'], ['ن', 'Mo'], ['ث', 'Tu'], ['ر', 'We'], ['خ', 'Th'], ['ج', 'Fr'], ['س', 'Sa']];
/** One row per person; each day points at the leave that covers it. */
const people = computed(() => {
  const map = new Map();
  for (const l of items.value) {
    const id = l.employee?.id || l.id;
    if (!map.has(id)) map.set(id, { employee: l.employee, cells: {}, count: 0 });
    const row = map.get(id);
    row.count += Number(l.days) || 0;
    for (const d of dayList.value) if (d.iso >= l.dateFrom && d.iso <= l.dateTo) row.cells[d.iso] = l;
  }
  return [...map.values()].sort((a, b) => pname(a.employee).localeCompare(pname(b.employee)));
});
const waiting = (l) => l.state !== 'approved';
const cellStyle = (l) => {
  const c = typeColor(l.type?.color);
  return waiting(l) ? { background: c.bg, border: `1px dashed ${c.fg}` } : { background: c.fg };
};
const cols = computed(() => `minmax(150px,1fr) repeat(${range.value.last}, 24px)`);
</script>

<template>
  <div class="row wrap mb-2.5">
    <Btn size="sm" tone="soft" :label="{ ar: '‹ السابق', en: '‹ Previous' }" @click="shift(-1)" />
    <TextInput small type="month" class="w-[150px]" :model-value="month" :aria-label="t('الشهر', 'Month')" @update:model-value="(v) => isPeriod(v) && nav.setFilter('month', v)" />
    <Btn size="sm" tone="soft" :label="{ ar: 'التالي ›', en: 'Next ›' }" @click="shift(1)" />
    <SelectInput small class="w-[170px]" :model-value="nav.get('department')" :options="departmentOptions" :placeholder="{ ar: 'كل الأقسام', en: 'Every department' }" @update:model-value="(v) => nav.setFilter('department', v)" />
    <span class="grow" />
    <span class="inline-flex items-center gap-1.5 text-[9.5px] text-muted"><span class="inline-block h-3 w-4 rounded-[3px] bg-[#654e92]" />{{ t('معتمدة', 'Approved') }}</span>
    <span class="inline-flex items-center gap-1.5 text-[9.5px] text-muted"><span class="inline-block h-3 w-4 rounded-[3px] border border-dashed border-[#654e92] bg-[#efeaf8]" />{{ t('بانتظار القرار', 'Waiting') }}</span>
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="skel h-[220px]" />
  <div v-else-if="!items.length && !q.error.value" class="card"><div class="empty">{{ t('لا أحد في إجازة هذا الشهر.', 'Nobody is off this month.') }}</div></div>

  <!-- phone: a list -->
  <div v-else-if="isMobile" class="col !gap-1.5">
    <button v-for="l in items" :key="l.id" type="button" class="card flex items-center gap-2.5 px-3 py-2.5 text-start" @click="nav.open('leave', l.number)">
      <span class="h-9 w-1.5 shrink-0 rounded-full" :style="{ background: typeColor(l.type?.color).fg, opacity: waiting(l) ? 0.45 : 1 }" />
      <span class="min-w-0 flex-1">
        <span class="block truncate text-[13px] font-extrabold" dir="auto">{{ pname(l.employee) }}</span>
        <span class="block truncate text-[11px] text-muted" dir="auto">{{ pname(l.type) }} · <span class="num ltr inline-block">{{ fmtDay(l.dateFrom) }}<template v-if="l.dateTo !== l.dateFrom"> – {{ fmtDay(l.dateTo) }}</template></span> · <span class="num">{{ days(l.days) }}</span> {{ t('يوم', 'd') }}</span>
      </span>
      <Chip small :map="LEAVE_STATE" :k="l.state" />
    </button>
  </div>

  <!-- wide screen: people × days -->
  <div v-else class="card overflow-x-auto p-2">
    <div class="grid min-w-max gap-y-1" :style="{ gridTemplateColumns: cols }">
      <div class="sticky start-0 z-[1] bg-white px-2 text-[10px] font-extrabold text-faint">{{ t('الموظف', 'Employee') }}</div>
      <div v-for="d in dayList" :key="d.iso" class="text-center leading-tight" :class="d.today ? 'font-extrabold text-brand' : 'text-faint'">
        <div class="text-[8px]">{{ t(WD[d.wd][0], WD[d.wd][1]) }}</div><div class="num text-[9.5px]">{{ d.n }}</div>
      </div>
      <template v-for="p in people" :key="p.employee?.id">
        <div class="sticky start-0 z-[1] min-w-0 truncate bg-white px-2 text-[10.5px] font-bold" dir="auto" :title="pname(p.employee)">{{ pname(p.employee) }} <span class="num text-[9px] text-muted">({{ days(p.count) }})</span></div>
        <div v-for="d in dayList" :key="d.iso" class="flex h-6 items-center justify-center">
          <button v-if="p.cells[d.iso]" type="button" class="h-5 w-[22px] rounded-[4px]" :style="cellStyle(p.cells[d.iso])"
                  :title="`${pname(p.cells[d.iso].type)} · ${p.cells[d.iso].number}`" :aria-label="`${pname(p.employee)} ${d.iso} ${pname(p.cells[d.iso].type)}`" @click="nav.open('leave', p.cells[d.iso].number)">
            <span v-if="p.cells[d.iso].halfDay" class="text-[8px] font-bold text-white">½</span>
          </button>
          <span v-else class="h-5 w-[22px] rounded-[4px] bg-soft" />
        </div>
      </template>
    </div>
  </div>
</template>
