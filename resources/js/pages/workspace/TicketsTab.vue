<script setup>
// The help desk: the tickets the caller may see — all of them, his own, his teams' — with search, filters, sort and
// paging, the overdue ones marked; a ticket opens in a drawer, a new one is raised from here, and whoever holds
// ticket.manage keeps the teams, stages and types.
// Everything is in the address: scope, state, team, stage, type, priority, assignee, overdue, q, sort, order, page ·
// ticket=<number> (the drawer) · new=1 (the form) · config=1 (the settings).
// GET /workspace/tickets · GET /workspace/tickets/lookups · GET /workspace/summary (the counters on top).
import { computed, ref } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, SelectInput, Tabs, TextInput } from '@/components';
import { fmtAgo, nm, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import OdooMark from './OdooMark.vue';
import PersonName from './PersonName.vue';
import TicketConfig from './TicketConfig.vue';
import TicketDrawer from './TicketDrawer.vue';
import TicketForm from './TicketForm.vue';
import { API, ARCHIVED_CHIP, PAGE_SIZE, PRIORITY, PRIORITY_OPTIONS, TONE, arr, dueInfo, personName, useLookups, useSearchBox, useSummary, useWsQuery } from './workspace';

const auth = useAuth();
const qs = useWsQuery();
const summary = useSummary();
const lookups = useLookups();
const desk = useGet(`${API}/tickets/lookups`, undefined, { staleTime: 60_000 });
const search = useSearchBox(qs);

// ---- the filters of the address
const SCOPES = ['all', 'mine', 'assigned', 'requested', 'team'];
const STATES = ['open', 'closed', 'archived', 'all'];
const FILTERS = ['scope', 'state', 'team', 'stage', 'type', 'priority', 'assignee', 'overdue', 'q'];
const scope = computed(() => (SCOPES.includes(qs.get('scope')) ? qs.get('scope') : 'all'));
const state = computed(() => (STATES.includes(qs.get('state')) ? qs.get('state') : 'open'));
const lateOnly = computed(() => qs.get('overdue') === '1');
const filtered = computed(() => FILTERS.some((k) => qs.get(k) !== ''));
const params = computed(() => ({
  scope: scope.value, state: state.value, team: qs.get('team') || undefined, stage: qs.get('stage') || undefined, type: qs.get('type') || undefined,
  priority: qs.get('priority') || undefined, assignee: qs.get('assignee') || undefined, overdue: lateOnly.value ? 1 : undefined, q: qs.get('q') || undefined,
  sort: qs.get('sort') || undefined, order: qs.get('sort') ? (qs.get('order') === 'asc' ? 'asc' : 'desc') : undefined, page: qs.page(), pageSize: PAGE_SIZE,
}));
const list = useList(`${API}/tickets`, params);

const SCOPE_TABS = [
  { k: 'all', label: { ar: 'الكل', en: 'All' } }, { k: 'mine', label: { ar: 'تذاكري', en: 'Mine' } }, { k: 'assigned', label: { ar: 'مسندة إليّ', en: 'Assigned to me' } },
  { k: 'requested', label: { ar: 'طلبتُها', en: 'Requested by me' } }, { k: 'team', label: { ar: 'فِرَقي', en: 'My teams' } },
];
const STATE_TABS = [
  { k: 'open', label: { ar: 'مفتوحة', en: 'Open' } }, { k: 'closed', label: { ar: 'مغلقة', en: 'Closed' } },
  { k: 'archived', label: { ar: 'مؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'كل الحالات', en: 'Every state' } },
];
const named = (rows) => arr(rows).map((x) => ({ v: x.id, l: personName(x) }));
const teamOptions = computed(() => named(desk.data.value?.teams));
const stageOptions = computed(() => named(desk.data.value?.stages));
const typeOptions = computed(() => named(desk.data.value?.types));
const assigneeOptions = computed(() => [
  { v: 'me', l: { ar: 'أنا', en: 'Me' } }, { v: 'none', l: { ar: 'غير مسندة', en: 'Unassigned' } },
  ...arr(desk.data.value?.assignees).filter((p) => p.key && p.id !== auth.user?.id).map((p) => ({ v: String(p.key), l: p.external ? `${personName(p)} · Odoo` : personName(p) })),
]);

// ---- the counters on top: each one is a ready-made filter
const counts = computed(() => summary.data.value?.tickets || null);
const preset = (changes) => qs.patch({ ...Object.fromEntries(FILTERS.map((k) => [k, ''])), ...changes });
const isPreset = (changes) => FILTERS.every((k) => qs.get(k) === (changes[k] || ''));
const manages = computed(() => summary.data.value?.me?.manages?.tickets ?? auth.can('ticket.manage'));

// ---- the table
const columns = [
  { key: 'number', header: { ar: 'الرقم', en: 'Number' }, width: '128px', kind: 'id', sortable: true },
  { key: 'subject', header: { ar: 'التذكرة', en: 'Ticket' }, width: 'minmax(220px,2.2fr)', sortable: true },
  { key: 'team', header: { ar: 'الفريق', en: 'Team' }, width: 'minmax(100px,0.9fr)', value: (r) => (r.team ? nm(r.team) : '—') },
  { key: 'stage', header: { ar: 'المرحلة', en: 'Stage' }, width: '132px', bare: true },
  { key: 'priority', header: { ar: 'الأولوية', en: 'Priority' }, width: '84px', sortable: true, bare: true },
  { key: 'assignee', header: { ar: 'المسؤول', en: 'Assignee' }, width: 'minmax(110px,1fr)' },
  { key: 'dueAt', header: { ar: 'الاستحقاق', en: 'Due' }, width: '132px', sortable: true },
  { key: 'lastMessageAt', header: { ar: 'آخر نشاط', en: 'Last activity' }, width: '100px', sortable: true },
];
const requesterText = (r) => { const who = r.requester || {}; return who.customer ? `${who.customer.code} · ${nm(who.customer)}` : who.user ? personName(who.user) : who.name || ''; };
const late = (r) => r.status === 'open' && !r.archived && (r.overdue || r.responseOverdue);
const rowStyle = (r) => (late(r) ? { background: '#FFF8F8', borderInlineStart: '3px solid #b23b3b' } : null);
const dueOf = (r) => dueInfo(r.dueAt, r.status !== 'open');
const stageTone = (r) => (r.status === 'closed' ? TONE.ok : TONE.neutral);
const emptyText = computed(() => (filtered.value ? { ar: 'لا تذاكر تطابق هذا الفلتر.', en: 'No ticket matches this filter.' } : { ar: 'لا تذاكر مفتوحة. افتح أول تذكرة من زر «+ تذكرة».', en: 'No open tickets. Raise the first one with “+ Ticket”.' }));

// ---- what is open
/** The ticket whose details are being corrected (the form opens over its drawer). */
const editing = ref(null);
const formOpen = computed(() => !!editing.value || qs.get('new') === '1');
/** A new ticket was just saved: its drawer replaces the form in one step. */
let saved = false;
function onSaved(ticket) {
  if (editing.value) return;
  saved = true;
  qs.patch({ new: '', ticket: ticket?.number || '' }, true);
}
function closeForm() {
  if (editing.value) { editing.value = null; return; }
  if (saved) { saved = false; return; }
  qs.close('new');
}
</script>

<template>
  <KpiGrid compact>
    <KpiCard :value="counts?.open" :label="{ ar: 'تذاكر مفتوحة', en: 'Open tickets' }" clickable :active="isPreset({})" :loading="summary.isLoading.value" @click="preset({})" />
    <KpiCard :value="counts?.mine" :label="{ ar: 'مسندة إليّ', en: 'Assigned to me' }" color="#654e92" clickable :active="isPreset({ assignee: 'me' })" :loading="summary.isLoading.value" @click="preset({ assignee: 'me' })" />
    <KpiCard :value="counts?.unassigned" :label="{ ar: 'بلا مسؤول', en: 'Unassigned' }" color="#b26a16" clickable :active="isPreset({ assignee: 'none' })" :loading="summary.isLoading.value" @click="preset({ assignee: 'none' })" />
    <KpiCard :value="counts?.overdue" :label="{ ar: 'متأخرة عن هدفها', en: 'Past their target' }" color="#b23b3b" clickable :active="isPreset({ overdue: '1' })" :loading="summary.isLoading.value" @click="preset({ overdue: '1' })" />
  </KpiGrid>

  <div class="row wrap mb-2">
    <TextInput v-model="search" small type="search" class="w-[240px]" :placeholder="{ ar: 'بحث بالرقم أو العنوان أو صاحب الطلب…', en: 'Search number, subject, requester…' }" />
    <Tabs :model-value="scope" :tabs="SCOPE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => qs.patch({ scope: k === 'all' ? '' : k })" />
    <span class="grow" />
    <Btn v-if="manages" tone="outline" size="sm" :label="{ ar: 'الإعدادات', en: 'Settings' }" @click="qs.open('config', '1')" />
    <Btn tone="primary" size="sm" :label="{ ar: '+ تذكرة', en: '+ Ticket' }" @click="qs.open('new', '1')" />
  </div>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="state" :tabs="STATE_TABS" variant="pillPurple" class="!mb-0" @update:model-value="(k) => qs.patch({ state: k === 'open' ? '' : k })" />
    <SelectInput small class="w-[150px]" :model-value="qs.get('team')" :options="teamOptions" :placeholder="{ ar: 'كل الفرق', en: 'Every team' }" :aria-label="t('الفريق', 'Team')" @update:model-value="(v) => qs.patch({ team: v })" />
    <SelectInput small class="w-[150px]" :model-value="qs.get('stage')" :options="stageOptions" :placeholder="{ ar: 'كل المراحل', en: 'Every stage' }" :aria-label="t('المرحلة', 'Stage')" @update:model-value="(v) => qs.patch({ stage: v })" />
    <SelectInput small class="w-[140px]" :model-value="qs.get('type')" :options="typeOptions" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" :aria-label="t('النوع', 'Type')" @update:model-value="(v) => qs.patch({ type: v })" />
    <SelectInput small class="w-[130px]" :model-value="qs.get('priority')" :options="PRIORITY_OPTIONS" :placeholder="{ ar: 'كل الأولويات', en: 'Every priority' }" :aria-label="t('الأولوية', 'Priority')" @update:model-value="(v) => qs.patch({ priority: v })" />
    <SelectInput small class="w-[160px]" :model-value="qs.get('assignee')" :options="assigneeOptions" :placeholder="{ ar: 'كل المسؤولين', en: 'Every assignee' }" :aria-label="t('المسؤول', 'Assignee')" @update:model-value="(v) => qs.patch({ assignee: v })" />
    <button type="button" class="pill" :class="{ active: lateOnly }" :aria-pressed="lateOnly" @click="qs.patch({ overdue: lateOnly ? '' : '1' })">{{ t('المتأخرة فقط', 'Overdue only') }}</button>
    <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="preset({})" />
  </div>

  <ErrorBanner :error="desk.error.value || list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="qs.get('ticket') || null" :row-style="rowStyle"
               server-sort :sort="qs.sort()" :empty-text="emptyText" @sort="qs.setSort" @page="(p) => qs.patch({ page: p })" @row-click="(r) => qs.open('ticket', r.number)">
      <template #cell-subject="{ row }">
        <div class="cell-name break-words" dir="auto">{{ row.subject }}</div>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[9.5px] text-muted">
          <span v-if="requesterText(row)" dir="auto">{{ requesterText(row) }}</span>
          <span v-if="row.type" dir="auto">· {{ nm(row.type) }}</span>
          <span v-if="row.messageCount" class="num">· {{ row.messageCount }} {{ t('رسالة', 'msg') }}</span>
          <OdooMark :row="row" />
        </div>
      </template>
      <template #cell-stage="{ row }">
        <span class="inline-flex flex-wrap items-center gap-1">
          <Chip v-if="row.stage" small :fg="stageTone(row).fg" :bg="stageTone(row).bg">{{ nm(row.stage) }}</Chip>
          <Chip v-if="row.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        </span>
      </template>
      <template #cell-priority="{ row }"><Chip :map="PRIORITY" :k="String(row.priority ?? 0)" small /></template>
      <template #cell-assignee="{ row }"><PersonName :person="row.assignee" :empty="{ ar: 'غير مسندة', en: 'Unassigned' }" /></template>
      <template #cell-dueAt="{ row }">
        <span v-if="dueOf(row)" class="text-[10px] font-extrabold" :class="late(row) ? 'text-bad' : 'text-sec'">{{ dueOf(row).text }}</span>
        <span v-else class="text-faint">—</span>
        <div v-if="row.status === 'open' && row.responseOverdue" class="text-[9px] font-bold text-bad">{{ t('بانتظار أول رد', 'First answer overdue') }}</div>
      </template>
      <template #cell-lastMessageAt="{ row }"><span class="cell-date" style="direction: inherit">{{ fmtAgo(row.lastMessageAt || row.createdAt) }}</span></template>
    </DataTable>
  </div>
  <div class="hint">
    {{ t('تاريخ الاستحقاق يُحسب من هدف «الحل» لفريق التذكرة، و«أول رد» يُقاس حتى أول رد من غير صاحب الطلب. التذكرة المتأخرة عن أيٍّ منهما تُعلَّم بالأحمر.',
         'The due date comes from the “closing” target of the ticket team, and the “first answer” runs until the first reply of somebody who is not the requester. A ticket past either one is marked in red.') }}
  </div>

  <TicketDrawer :number="qs.get('ticket')" :desk="desk.data.value" :lookups="lookups.data.value" @close="qs.close('ticket')" @edit="(ticket) => (editing = ticket)" />
  <TicketForm :open="formOpen" :ticket="editing" :desk="desk.data.value" :lookups="lookups.data.value" @close="closeForm" @done="onSaved" />
  <TicketConfig :open="manages && qs.get('config') === '1'" :desk="desk.data.value" :lookups="lookups.data.value" :loading="desk.isLoading.value" @close="qs.close('config')" />
</template>
