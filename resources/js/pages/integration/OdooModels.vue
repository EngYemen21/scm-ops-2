<script setup>
// Odoo tab → models: every Odoo model the mirror knows — is it copied, how far its first copy got, when it was last
// checked, what went wrong. GET /integration/odoo/models?status=&state=&q=&sort=&order=&page= (refreshed every 15 s:
// the first copy moves while one watches). A row opens the model drawer (`m=<model>` in the query string).
// Filters, sort and page live in the query string:  /itower?tab=odoo&view=models&state=failing
import { computed } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { CORE_CHIP, CORE_HELP, MODEL_STATE, MODEL_STATUS, MODEL_STATUSES, MODEL_STATUS_HELP, agoAt, fmtAt, isCopying, modelReason, useOdooNav, useSearchParam } from './odoo';

const props = defineProps({
  /** `status.models` of GET status — { active, new, skipped, paused, error, failing } — the counts on the filter pills. */
  counts: { type: Object, default: null },
});

const nav = useOdooNav();
const { page, modelOpen } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);

const status = computed(() => (MODEL_STATUSES.includes(nav.get('status')) ? nav.get('status') : ''));
const state = computed(() => (Object.keys(MODEL_STATE).includes(nav.get('state')) ? nav.get('state') : ''));
/** Column key → the `sort` value the API knows. Without a sort the list comes core models first. */
const SORTS = { model: 'model', records: 'mirrored_count', lastSyncedAt: 'last_synced_at', lastChangedAt: 'last_changed_at' };
const sort = computed(() => (Object.keys(SORTS).includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });

const list = useList('/integration/odoo/models', () => ({
  status: status.value, state: state.value, q: nav.get('q'), sort: sort.value ? SORTS[sort.value.key] : undefined, order: sort.value?.order, page: page.value, pageSize: 50,
}), { refetchInterval: 15000 });

const registered = computed(() => (props.counts ? MODEL_STATUSES.reduce((n, k) => n + (props.counts[k] || 0), 0) : null));
const withCount = (label, n) => (n == null ? label : { ar: `${label.ar} (${fmtNum(n)})`, en: `${label.en} (${fmtNum(n)})` });
const statusTabs = computed(() => [
  { k: '', label: withCount({ ar: 'الكل', en: 'All' }, registered.value) },
  ...MODEL_STATUSES.map((k) => ({ k, label: withCount(MODEL_STATUS[k], props.counts?.[k]) })),
]);
// «قيد النسخة الأولى» means an active model, so it never stays combined with another status (the list would be empty)
const setStatus = (k) => nav.patch({ status: k, state: state.value === 'copying' && k && k !== 'active' ? null : state.value, page: null });
function toggleState(k) {
  const on = state.value !== k;
  nav.patch({ state: on ? k : null, status: on && k === 'copying' && status.value !== 'active' ? null : status.value, page: null });
}
const emptyText = computed(() => (registered.value === 0
  ? { ar: 'لم يُسجَّل أي نموذج بعد — أول مزامنة تتعرّف على نماذج أودو وتسجّلها.', en: 'No model is registered yet — the first run lists the models of Odoo and registers them.' }
  : { ar: 'لا نماذج مطابقة.', en: 'No matching models.' }));

const columns = [
  { key: 'model', header: { ar: 'النموذج', en: 'Model' }, width: 'minmax(230px,1.6fr)', sortable: true },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '104px', bare: true },
  { key: 'records', header: { ar: 'السجلات: في النسخة / في أودو', en: 'Records: copied / in Odoo' }, width: '190px', sortable: true },
  { key: 'lastSyncedAt', header: { ar: 'آخر فحص', en: 'Last checked' }, width: '100px', sortable: true },
  { key: 'lastChangedAt', header: { ar: 'آخر تغيير', en: 'Last change' }, width: '100px', sortable: true },
  { key: 'note', header: { ar: 'ملاحظة / خطأ', en: 'Note / error' }, width: 'minmax(170px,1.2fr)' },
];
</script>

<template>
  <div class="row wrap">
    <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: 'بحث باسم النموذج…', en: 'Search by model name…' }" @update:model-value="typeQ" @enter="applyQ" />
    <Tabs :model-value="status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="setStatus" />
    <button v-for="(s, k) in MODEL_STATE" :key="k" type="button" class="pill" :class="{ active: state === k }" :aria-pressed="state === k" @click="toggleState(k)">
      {{ bi(s) }}<template v-if="k === 'failing' && counts"> ({{ fmtNum(counts.failing) }})</template>
    </button>
    <Btn v-if="sort" tone="ghost" size="sm" :label="{ ar: 'الترتيب الأصلي', en: 'Default order' }" @click="nav.patch({ sort: null, order: null, page: null })" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" class="!mb-0" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.model" :selected-key="modelOpen" :sort="sort" server-sort dense :empty-text="emptyText"
               @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openModel(r.model)">
      <template #cell-model="{ row }">
        <div class="min-w-0">
          <div class="flex min-w-0 items-center gap-1.5">
            <span class="cell-id min-w-0 truncate">{{ row.model }}</span>
            <Chip v-if="row.core" small :label="CORE_CHIP" :fg="CORE_CHIP.fg" :bg="CORE_CHIP.bg" :title="bi(CORE_HELP)" />
          </div>
          <div v-if="row.label" class="truncate text-[9.5px] text-muted [text-align:match-parent]" dir="auto">{{ row.label }}</div>
        </div>
      </template>
      <template #cell-status="{ row }"><Chip small :map="MODEL_STATUS" :k="row.status" :title="bi(MODEL_STATUS_HELP[row.status] || '')" /></template>
      <template #cell-records="{ row }">
        <span v-if="row.remoteCount == null && !row.mirroredCount" class="text-faint">—</span>
        <div v-else>
          <span class="num ltr text-[10.5px] text-ink">{{ fmtNum(row.mirroredCount) }} <span class="font-normal text-faint">/ {{ row.remoteCount == null ? '—' : fmtNum(row.remoteCount) }}</span></span>
          <span v-if="row.fieldCount > 0 && row.status !== 'skipped'" class="num ltr ms-1.5 text-[9.5px]" :class="row.fullState === 'done' ? 'text-ok' : 'text-azure'">{{ row.copyPct }}%</span>
          <div v-if="isCopying(row)" class="progress mt-1 !h-1"><div :style="{ width: `${row.copyPct}%` }" /></div>
        </div>
      </template>
      <template #cell-lastSyncedAt="{ row }"><span class="num-mixed" :title="fmtAt(row.lastSyncedAt)">{{ agoAt(row.lastSyncedAt) }}</span></template>
      <template #cell-lastChangedAt="{ row }"><span class="num-mixed" :title="fmtAt(row.lastChangedAt)">{{ agoAt(row.lastChangedAt) }}</span></template>
      <template #cell-note="{ row }">
        <div v-if="row.reason || row.lastError" class="min-w-0">
          <div v-if="row.reason" class="text-[10px] leading-[1.6] text-muted">{{ modelReason(row.reason) }}</div>
          <div v-if="row.lastError" class="ltr num-mixed truncate text-[10px] text-bad" :title="row.lastError">{{ row.lastError }}</div>
          <div v-if="row.consecutiveErrors" class="text-[9px] text-muted">
            {{ t('محاولات فاشلة متتالية', 'Failed tries in a row') }} <b class="num">{{ fmtNum(row.consecutiveErrors) }}</b>
            <template v-if="row.nextAttemptAt && row.status !== 'error'"> · {{ t('المحاولة القادمة', 'next try') }} <span class="num ltr">{{ fmtAt(row.nextAttemptAt) }}</span></template>
          </div>
        </div>
        <span v-else class="text-faint">—</span>
      </template>
    </DataTable>
  </div>
  <div class="hint !mt-0">
    {{ t('النموذج هو جدول بيانات في أودو: أوامر البيع، المنتجات، العملاء… النماذج «الأساسية» تُفحص في كل مزامنة والبقية تتناوب. «متخطّى» يعني أنه لا يُنسخ (نموذج تقني، أو لا صلاحية لقراءته، أو تقرير محسوب) والسبب مكتوب بجانبه، ويمكن ضمّه إلى النسخة من نافذته. افتح أي نموذج لترى حقوله وآخر تغييراته.', 'A model is a table of data in Odoo: sales orders, products, customers… “Core” models are checked on every run, the others take turns. “Skipped” means it is not copied (a technical model, no read access, or a computed report); the reason is written beside it, and it can be taken into the mirror from its panel. Open a model to see its fields and its latest changes.') }}
  </div>
</template>
