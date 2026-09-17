<script setup>
// UI kit — a living example of how a page is built here, on a real API (operational exceptions).
// Route: /kit (not in the sidebar). Copy patterns from this file:
//   list + filters + paging  → useList + DataTable (cell slots)       create form   → FormDrawer
//   detail                   → useGet + Drawer                        action button → useAction + api.postIdempotent
//   permissions              → auth.can('…') only hides buttons; the server enforces them anyway
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, FormDrawer, KV, KpiCard, KpiGrid, PageHead, SectionCard, Tabs, TextInput, Timeline } from '@/components';
import { fmtDate, lang, t } from '@/i18n';
import { EXCEPTION_KINDS, EXCEPTION_STATE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';

const auth = useAuth();

// ---- list state → query params (the query re-runs whenever they change) ----
const q = ref('');
const status = ref('');
const page = ref(1);
const list = useList('/exceptions', () => ({ q: q.value.trim() || undefined, status: status.value || undefined, page: page.value, pageSize: 8 }));

const SEVERITY = { c: { ar: 'حرج', en: 'Critical', fg: '#fff', bg: '#b23b3b' }, w: { ar: 'عالٍ', en: 'High', fg: '#b26a16', bg: '#fbf0dd' }, i: { ar: 'متوسط', en: 'Medium', fg: '#55506a', bg: '#F1EFF6' } };
const statusTabs = computed(() => [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(EXCEPTION_STATE_LABELS).map(([k, l]) => ({ k, label: l }))]);
const columns = [
  { key: 'number', header: { ar: 'الرقم', en: 'Number' }, width: '130px', kind: 'id' },
  { key: 'severity', header: { ar: 'الخطورة', en: 'Severity' }, width: '80px' },
  { key: 'text', header: { ar: 'الوصف', en: 'Description' }, width: 'minmax(220px,2fr)' },
  { key: 'ownerRole', header: { ar: 'المسؤول', en: 'Owner' }, width: '90px', kind: 'muted' },
  { key: 'createdAt', header: { ar: 'فُتح', en: 'Opened' }, width: '120px', kind: 'date', value: (r) => fmtDate(r.createdAt) },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px' },
];

// ---- detail ----
const selected = ref(null);
const detail = useGet(() => (selected.value ? `/exceptions/${encodeURIComponent(selected.value)}` : null));
const events = computed(() => (detail.data.value?.events || []).map((e) => ({ at: e.at, by: e.username || 'system', note: e.note, label: EXCEPTION_STATE_LABELS[e.toStatus] || e.toStatus, color: { open: '#b23b3b', ack: '#b26a16', resolved: '#1d7a3e' }[e.toStatus] })));

// ---- actions ----
const act = useAction();
async function setStatus(to) {
  const d = detail.data.value;
  if (to === 'resolve' && !(await confirm({ title: { ar: `إغلاق الاستثناء ${d.number}؟`, en: `Resolve ${d.number}?` }, tone: 'dark' }))) return;
  // No body: action buttons post nothing, the server treats that as {}.
  const r = await act.run(() => api.postIdempotent(`/exceptions/${d.number}/${to}`), { success: to === 'ack' ? t('استُلم الاستثناء', 'Acknowledged') : t('أُغلق الاستثناء', 'Resolved'), invalidate: ['exceptions'] });
  if (r) detail.refetch();
}

// ---- create ----
const creating = ref(false);
const fields = [
  { k: 'kind', label: { ar: 'النوع', en: 'Kind' }, type: 'select', required: true, opts: EXCEPTION_KINDS.map((k) => [k, k]) },
  { k: 'severity', label: { ar: 'الخطورة', en: 'Severity' }, type: 'select', def: 'w', opts: Object.entries(SEVERITY).map(([k, l]) => [k, l]) },
  { k: 'entityNumber', label: { ar: 'رقم المستند', en: 'Document no.' }, dir: 'ltr' },
  { k: 'slaHours', label: { ar: 'SLA (ساعات)', en: 'SLA (hours)' }, type: 'num', def: 4, min: 1 },
  { k: 'textAr', label: { ar: 'الوصف', en: 'Description' }, type: 'area', required: true },
];
</script>

<template>
  <PageHead :title="{ ar: 'UI Kit — مثال حي', en: 'UI kit — living example' }" :sub="t('نمط بناء الصفحات على واجهة الاستثناءات', 'The page pattern, on the exceptions API')">
    <Btn v-if="auth.can('exception.create')" tone="primary" :label="{ ar: '+ استثناء', en: '+ Exception' }" @click="creating = true" />
  </PageHead>

  <KpiGrid compact>
    <KpiCard :value="list.data.value?.total" :label="{ ar: 'نتائج الفلتر', en: 'Filtered results' }" :loading="list.isLoading.value" />
    <KpiCard :value="page" :label="{ ar: 'الصفحة', en: 'Page' }" color="#654e92" />
  </KpiGrid>

  <div class="row wrap mb-2.5">
    <TextInput v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث بالرقم أو الوصف…', en: 'Search number / text…' }" @update:model-value="page = 1" />
    <Tabs v-model="status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="page = 1" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />

  <SectionCard :padded="false">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.number" :selected-key="selected" :min-width="820" @page="page = $event" @row-click="(r) => (selected = r.number)">
      <template #cell-severity="{ row }"><Chip :map="SEVERITY" :k="row.severity" small /></template>
      <template #cell-text="{ row }"><span class="cell-name">{{ lang === 'ar' ? row.textAr : row.textEn || row.textAr }}</span></template>
      <template #cell-status="{ row }"><Chip :map="EXCEPTION_STATE_LABELS" :k="row.status" /></template>
    </DataTable>
  </SectionCard>

  <Drawer :open="!!selected" :title="selected" :sub="detail.data.value ? detail.data.value.sla?.labelAr : null" :width="520" @close="selected = null">
    <ErrorBanner :error="detail.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="!detail.data.value" class="skel h-[120px]" />
    <template v-else>
      <div class="row wrap mb-3">
        <Chip :map="SEVERITY" :k="detail.data.value.severity" /><Chip :map="EXCEPTION_STATE_LABELS" :k="detail.data.value.status" />
        <div class="grow" />
        <template v-if="auth.can('exception.manage')">
          <Btn v-if="detail.data.value.status === 'open'" tone="softAmber" size="sm" :loading="act.pending.value" :label="{ ar: 'استلام', en: 'Acknowledge' }" @click="setStatus('ack')" />
          <Btn v-if="detail.data.value.status !== 'resolved'" tone="success" size="sm" :loading="act.pending.value" :label="{ ar: 'إغلاق', en: 'Resolve' }" @click="setStatus('resolve')" />
        </template>
      </div>
      <div class="mb-3 text-[13px] font-extrabold leading-[1.8]">{{ detail.data.value.textAr }}</div>
      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'النوع', en: 'Kind' }" :v="detail.data.value.kind" />
        <KV :k="{ ar: 'المسؤول', en: 'Owner' }" :v="detail.data.value.ownerRole" />
        <KV :k="{ ar: 'فُتح', en: 'Opened' }" :v="fmtDate(detail.data.value.createdAt)" ltr />
        <KV :k="{ ar: 'المستند', en: 'Document' }" :v="detail.data.value.entityNumber" ltr />
      </div>
      <div class="card-title mb-1">{{ t('سجل الحالة', 'Status history') }}</div>
      <Timeline :items="events" />
    </template>
  </Drawer>

  <FormDrawer :open="creating" :title="{ ar: 'استثناء يدوي', en: 'Manual exception' }" :fields="fields" :submit="(values) => api.postIdempotent('/exceptions', values)"
              :action="{ success: (e) => t(`أُنشئ ${e.number}`, `Created ${e.number}`), invalidate: ['exceptions'] }" @close="creating = false" @done="(e) => (selected = e.number)" />
</template>
