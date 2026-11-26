<script setup>
// The to-do list across leads: mine by default, the soonest first, what is late in red; done with a note or cancelled.
// GET /crm/activities?state&owner&type&q&page → { items, total, …, counts: { overdue, today, upcoming, open } }.
// State in the query string:  /crm?view=activities&todo=overdue&who=all&type=visit&q=الواحة
//   todo = open (default) | overdue | today | upcoming | done | cancelled      who = me (default) | all | <user id>
// A row opens its lead; «تم» asks what happened (POST /crm/activities/{id}/done), «إلغاء» asks why (…/cancel).
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, fmtDateOnly, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import ActivityForm from './ActivityForm.vue';
import { ACTIVITY_STATES, ACTIVITY_STATUS, ACTIVITY_TYPE, activityTypeLabel, activityTypeOptions, dueLabel, nameOf, useCrmLookups, useCrmNav, useSearchParam } from './crm';

const auth = useAuth();
const nav = useCrmNav();
const { page } = nav;
const { userOptions } = useCrmLookups();
const { text, type, apply } = useSearchParam(nav);
const canManage = computed(() => auth.can('crm.manage'));

const state = computed(() => (ACTIVITY_STATES.some((s) => s.k === nav.get('todo')) ? nav.get('todo') : 'open'));
const who = computed(() => nav.get('who') || 'me');
/** The API sorts by the due date (default: the soonest first; closed lists: the latest closed first) or by the day it was added. */
const sort = computed(() => (['dueDate', 'createdAt'].includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const list = useList('/crm/activities', () => ({
  state: state.value, owner: who.value, type: nav.get('type') || undefined, q: nav.get('q') || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const counts = computed(() => list.data.value?.counts || {});
const stateTabs = computed(() => ACTIVITY_STATES.map((s) => ({ ...s, badge: counts.value[s.k] ?? null })));
const whoOptions = computed(() => [{ v: 'me', l: { ar: 'أنشطتي', en: 'My to-dos' } }, { v: 'all', l: { ar: 'أنشطة الجميع', en: 'Everybody’s' } }, ...userOptions.value.filter((u) => u.v !== auth.user?.id)]);
const types = activityTypeOptions();

const act = useAction({ invalidate: ['crm'] });
const editing = ref(null);
async function done(a) {
  const note = await ask({ title: { ar: `إنجاز: ${a.summary}`, en: `Done: ${a.summary}` }, label: { ar: 'ما الذي حدث؟ (اختياري)', en: 'What happened? (optional)' }, okLabel: { ar: 'تم', en: 'Done' }, tone: 'primary' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(`/crm/activities/${a.id}/done`, note ? { note } : undefined), { success: t('أُنجز النشاط', 'To-do done') });
}
async function cancel(a) {
  const reason = await ask({ title: { ar: `إلغاء: ${a.summary}`, en: `Cancel: ${a.summary}` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إلغاء النشاط', en: 'Cancel the to-do' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/crm/activities/${a.id}/cancel`, reason ? { reason } : undefined), { success: t('أُلغي النشاط', 'To-do cancelled') });
}

const empty = computed(() => (state.value === 'open' || state.value === 'overdue' || state.value === 'today'
  ? { ar: 'لا أنشطة هنا — أضف نشاطًا من داخل الفرصة.', en: 'Nothing to do here — add a to-do from inside an opportunity.' }
  : { ar: 'لا أنشطة مطابقة.', en: 'No matching to-do.' }));
const columns = computed(() => [
  { key: 'summary', header: { ar: 'النشاط', en: 'To-do' }, width: 'minmax(210px,1.6fr)' },
  { key: 'lead', header: { ar: 'الفرصة', en: 'Opportunity' }, width: 'minmax(170px,1.3fr)' },
  { key: 'dueDate', header: { ar: 'الاستحقاق', en: 'Due' }, width: '112px', sortable: true },
  { key: 'user', header: { ar: 'المسؤول', en: 'Owner' }, width: '120px' },
  { key: 'createdAt', header: { ar: 'أُضيف', en: 'Added' }, width: '92px', kind: 'date', sortable: true, value: (r) => fmtDateOnly(r.createdAt) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '150px', bare: true },
  { key: 'act', header: '', width: '170px', bare: true, hidden: !canManage.value },
]);
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث في الأنشطة أو باسم الفرصة…', en: 'Search to-dos or the opportunity…' }" @update:model-value="type" @enter="apply" />
    <SelectInput small class="w-[160px]" :model-value="who" :options="whoOptions" @update:model-value="(v) => nav.setFilter('who', v === 'me' ? null : v)" />
    <SelectInput small class="w-[150px]" :model-value="nav.get('type')" :options="types" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" @update:model-value="(v) => nav.setFilter('type', v)" />
  </div>
  <Tabs :model-value="state" :tabs="stateTabs" variant="pill" @update:model-value="(k) => nav.setFilter('todo', k === 'open' ? null : k)" />
  <ErrorBanner :error="list.error.value || act.error.value" :closable="!list.error.value" @close="act.clearError()" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :empty-text="empty" :row-style="(r) => (r.overdue ? { background: '#FFF8F8' } : null)"
               :sort="sort" server-sort @sort="setSort" @page="nav.setPage" @row-click="(r) => r.lead && nav.openLead(r.lead.number)">
      <template #cell-summary="{ row }">
        <div class="flex min-w-0 items-center gap-2">
          <Chip small :label="activityTypeLabel(row.type)" :fg="(ACTIVITY_TYPE[row.type] || {}).fg" :bg="(ACTIVITY_TYPE[row.type] || {}).bg" />
          <span class="min-w-0 truncate text-[11px] font-extrabold text-ink" :class="{ 'line-through opacity-60': row.status === 'cancelled' }" dir="auto" :title="row.note || row.summary">{{ row.summary }}</span>
        </div>
      </template>
      <template #cell-lead="{ row }">
        <div v-if="row.lead" class="min-w-0">
          <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ row.lead.name }}</div>
          <div class="cell-sub">{{ row.lead.number }}<template v-if="row.lead.stage"> · <span class="font-sans">{{ nameOf(row.lead.stage) }}</span></template></div>
        </div>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-dueDate="{ row }">
        <span class="num text-[10.5px]" :class="row.overdue ? 'font-extrabold text-bad' : row.today ? 'font-extrabold text-warn' : 'text-sec'">{{ dueLabel(row) }}</span>
        <div v-if="row.overdue" class="text-[8.5px] font-extrabold text-bad">{{ t('متأخر', 'Overdue') }}</div>
      </template>
      <template #cell-user="{ row }"><span class="truncate text-[10px] text-sec" dir="auto">{{ row.user ? nameOf(row.user) : '—' }}</span></template>
      <template #cell-status="{ row }">
        <div>
          <Chip small :map="ACTIVITY_STATUS" :k="row.status" />
          <div v-if="row.status === 'done'" class="mt-0.5 truncate text-[8.5px] text-muted" dir="auto" :title="row.doneNote || ''">{{ row.doneBy }} · <span class="num ltr">{{ fmtDate(row.doneAt) }}</span></div>
          <div v-else-if="row.status === 'cancelled' && row.cancelReason" class="mt-0.5 truncate text-[8.5px] text-muted" dir="auto" :title="row.cancelReason">{{ row.cancelReason }}</div>
        </div>
      </template>
      <template #cell-act="{ row }">
        <span v-if="row.status === 'open'" class="row justify-end !gap-1.5">
          <Btn size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'تم', en: 'Done' }" @click.stop="done(row)" />
          <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="editing = row" />
          <Btn size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click.stop="cancel(row)" />
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('النشاط مهمة على فرصة: نوع وتاريخ استحقاق ومسؤول. يُضاف من داخل الفرصة، ويُنجز بملاحظة عمّا حدث أو يُلغى — ولا يُحذف. خسارة الفرصة تلغي أنشطتها المفتوحة.', 'A to-do is a task on an opportunity: a type, a due date and an owner. It is added from inside the opportunity and is done with a note of what happened, or cancelled — never deleted. Losing an opportunity cancels its open to-dos.') }}</div>

  <ActivityForm :open="!!editing" :lead="editing ? editing.lead : null" :activity="editing" @close="editing = null" />
</template>
