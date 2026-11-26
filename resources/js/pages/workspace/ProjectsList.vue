<script setup>
// The projects the caller may see (GET /workspace/projects): status, manager, how many tasks are open, late and
// finished, the dates. A row opens the project's board; «التفاصيل» opens its drawer (members, stages, status).
// The address holds state (active | done | archived | all), q, sort, order and page.
//   <ProjectsList @board="(id) => …" @details="(id) => …" />
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, ProgressBar, Tabs, TextInput } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import OdooMark from './OdooMark.vue';
import PersonName from './PersonName.vue';
import { API, ARCHIVED_CHIP, PAGE_SIZE, PROJECT_STATUS, useSearchBox, useWsQuery } from './workspace';

const emit = defineEmits(['board', 'details']);

const qs = useWsQuery();
const search = useSearchBox(qs);
const STATES = ['active', 'done', 'archived', 'all'];
const state = computed(() => (STATES.includes(qs.get('state')) ? qs.get('state') : 'active'));
const filtered = computed(() => qs.get('state') !== '' || qs.get('q') !== '');
const list = useList(`${API}/projects`, () => ({
  state: state.value, q: qs.get('q') || undefined, sort: qs.get('sort') || undefined, order: qs.get('sort') ? (qs.get('order') === 'asc' ? 'asc' : 'desc') : undefined,
  page: qs.page(), pageSize: PAGE_SIZE,
}));
const STATE_TABS = [
  { k: 'active', label: { ar: 'الجارية', en: 'Running' } }, { k: 'done', label: { ar: 'المنتهية', en: 'Done' } },
  { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
];
const columns = [
  { key: 'number', header: { ar: 'الرقم', en: 'Number' }, width: '128px', kind: 'id', sortable: true },
  { key: 'name', header: { ar: 'المشروع', en: 'Project' }, width: 'minmax(220px,2fr)', sortable: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '128px', bare: true },
  { key: 'manager', header: { ar: 'المدير', en: 'Manager' }, width: 'minmax(120px,1fr)' },
  { key: 'tasks', header: { ar: 'المهام', en: 'Tasks' }, width: '170px' },
  { key: 'endDate', header: { ar: 'المدة', en: 'Dates' }, width: '150px', sortable: true },
  { key: 'act', header: '', width: '150px', bare: true },
];
const day = (d) => (d ? fmtDate(d, { time: false }) : '…');
/** Shown in an LTR cell: the start is on the left, the arrow points at the end. */
const dates = (r) => (r.startDate || r.endDate ? `${day(r.startDate)} → ${day(r.endDate)}` : '—');
const donePct = (r) => (r.counts?.total ? Math.round(((r.counts.done || 0) / r.counts.total) * 100) : 0);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[240px]" :placeholder="{ ar: 'بحث باسم المشروع أو رقمه…', en: 'Search project name or number…' }" />
    <Tabs :model-value="state" :tabs="STATE_TABS" variant="pill" class="!mb-0" @update:model-value="(k) => qs.patch({ state: k === 'active' ? '' : k })" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" server-sort :sort="qs.sort()"
               :empty-text="filtered ? { ar: 'لا مشاريع تطابق هذا الفلتر.', en: 'No project matches this filter.' } : { ar: 'لا مشاريع جارية.', en: 'No running projects.' }"
               @sort="qs.setSort" @page="(p) => qs.patch({ page: p })" @row-click="(r) => emit('board', r.id)">
      <template #cell-name="{ row }">
        <div class="cell-name break-words" dir="auto">{{ row.name }}</div>
        <div v-if="row.description" class="mt-0.5 line-clamp-1 text-[9.5px] text-muted" dir="auto">{{ row.description }}</div>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center gap-1">
          <Chip :map="PROJECT_STATUS" :k="row.status" small />
          <Chip v-if="row.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
          <OdooMark :row="row" />
        </span>
      </template>
      <template #cell-manager="{ row }"><PersonName :person="row.manager" :empty="{ ar: 'بلا مدير', en: 'No manager' }" /></template>
      <template #cell-tasks="{ row }">
        <div class="text-[10px]">
          <span class="num font-bold">{{ fmtNum(row.counts?.open || 0) }}</span> {{ t('مفتوحة', 'open') }}
          <template v-if="row.counts?.overdue"> · <span class="num font-extrabold text-bad">{{ fmtNum(row.counts.overdue) }}</span> <span class="text-bad">{{ t('متأخرة', 'late') }}</span></template>
          · <span class="num">{{ fmtNum(row.counts?.done || 0) }}</span> {{ t('مكتملة', 'done') }}
        </div>
        <ProgressBar v-if="row.counts?.total" :pct="donePct(row)" :height="5" class="mt-1" />
      </template>
      <template #cell-endDate="{ row }"><span class="cell-date">{{ dates(row) }}</span></template>
      <template #cell-act="{ row }">
        <span class="inline-flex gap-1.5">
          <Btn tone="softPurple" size="sm" :label="{ ar: 'اللوحة', en: 'Board' }" @click.stop="emit('board', row.id)" />
          <Btn tone="outline" size="sm" :label="{ ar: 'التفاصيل', en: 'Details' }" @click.stop="emit('details', row.id)" />
        </span>
      </template>
    </DataTable>
  </div>
</template>
