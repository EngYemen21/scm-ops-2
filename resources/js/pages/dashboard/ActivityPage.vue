<script setup>
// Activity & audit: tabs سجل النشاط · Audit Trail (`audit.view`) · الإشعارات.
// GET /api/activity (paged) · GET /api/audit (paged) · GET /api/notifications.
// Tab, filters and page live in the URL so deep links work:  /activity?tab=audit&entity=EXC-00012
//
// Activity row: { id, entityType, entityId, entityNumber, textAr, textEn, username, at }
// Audit row:    { id, username, action, entityType, entityId, entityNumber, field, oldValue, newValue, requestId, at }
import { computed, reactive, watch } from 'vue';
import { useList } from '@/api/client';
import { Chip, DataTable, EmptyState, ErrorBanner, PageHead, Tabs } from '@/components';
import { fmtAgo, fmtDate, lang, t } from '@/i18n';
import { entityPath } from '@/router/routes';
import { useAuth } from '@/stores/auth';
import ActivityFilters from './ActivityFilters.vue';
import EntityCell from './EntityCell.vue';
import { useQueryState } from './shared';
import { useNotifications } from './useNotifications';

const FILTER_KEYS = ['q', 'entity', 'user', 'from', 'to', 'entityType', 'action'];
const actionColor = (a) => (/DELETE|CANCEL|REJECT|FAIL/.test(a) ? '#b23b3b' : /CREATE|RAISE|POST|APPROVE/.test(a) ? '#1d7a3e' : /STATUS|UPDATE|SETTING|PERMISSIONS/.test(a) ? '#3C79F5' : /SECURITY/.test(a) ? '#654e92' : '#55506a');
const short = (v, n = 80) => (v == null || v === '' ? '—' : v.length > n ? `${v.slice(0, n)}…` : v);

const auth = useAuth();
const qs = useQueryState();
const { router } = qs;
const canAudit = auth.can('audit.view');

const tab = computed(() => (qs.get('tab') === 'audit' && canAudit ? 'audit' : qs.get('tab') === 'notif' ? 'notif' : 'activity'));
const setTab = (k) => qs.replace({ tab: k });

// ---- filters: edited in a draft, applied to the URL with Search / Enter
const draft = reactive(Object.fromEntries(FILTER_KEYS.map((k) => [k, qs.get(k)])));
// The inputs always mirror the applied filters (deep link while the page is open, tab switch, back / forward).
watch(() => qs.route.query, () => { FILTER_KEYS.forEach((k) => { draft[k] = qs.get(k); }); });
function apply() {
  const n = { tab: tab.value };
  FILTER_KEYS.forEach((k) => { if (draft[k]) n[k] = draft[k]; });
  qs.replace(n);
}
function clear() {
  FILTER_KEYS.forEach((k) => { draft[k] = ''; });
  setTab(tab.value);
}
const page = computed(() => Number(qs.get('page') || 1));
const setPage = (p) => qs.setParam('page', p > 1 ? String(p) : null);
const common = computed(() => ({ q: qs.get('q') || undefined, entity: qs.get('entity') || undefined, user: qs.get('user') || undefined, from: qs.get('from') || undefined, to: qs.get('to') || undefined, page: page.value, pageSize: 30 }));

const act = useList('/activity', () => common.value, { enabled: () => tab.value === 'activity' });
const aud = useList('/audit', () => ({ ...common.value, entityType: qs.get('entityType') || undefined, action: qs.get('action') || undefined }), { enabled: () => tab.value === 'audit' && canAudit });
const notif = useNotifications();

const tabs = computed(() => [
  { k: 'activity', label: { ar: 'سجل النشاط', en: 'Activity' } },
  { k: 'audit', label: 'Audit Trail', hidden: !canAudit },
  { k: 'notif', label: { ar: 'الإشعارات', en: 'Notifications' }, badge: notif.unread.value || null },
]);

const actFilters = [
  { k: 'q', label: { ar: 'بحث', en: 'Search' }, ph: { ar: 'SO-00012 / نص…', en: 'SO-00012 / text…' }, width: 180 },
  { k: 'entity', label: { ar: 'الكيان / الرقم', en: 'Entity / number' }, ph: 'PurchaseOrder / PO-…' },
  { k: 'user', label: { ar: 'المستخدم', en: 'User' }, ph: 'admin', width: 120 },
  { k: 'from', label: { ar: 'من', en: 'From' }, type: 'date' }, { k: 'to', label: { ar: 'إلى', en: 'To' }, type: 'date' },
];
const audFilters = [
  { k: 'q', label: { ar: 'بحث', en: 'Search' }, ph: { ar: 'رقم / إجراء / قيمة', en: 'number / action / value' }, width: 170 },
  { k: 'entityType', label: { ar: 'نوع الكيان', en: 'Entity type' }, ph: 'PurchaseOrder', width: 130 },
  { k: 'entity', label: { ar: 'رقم الكيان', en: 'Entity number' }, ph: 'PO-00001', width: 120 },
  { k: 'user', label: { ar: 'المستخدم', en: 'User' }, ph: 'admin', width: 110 },
  { k: 'action', label: { ar: 'الإجراء', en: 'Action' }, ph: 'PO.STATUS', width: 120 },
  { k: 'from', label: { ar: 'من', en: 'From' }, type: 'date' }, { k: 'to', label: { ar: 'إلى', en: 'To' }, type: 'date' },
];
const actCols = [
  { key: 'entity', header: { ar: 'الكيان', en: 'Entity' }, width: '140px' },
  { key: 'text', header: { ar: 'الحدث', en: 'Event' }, width: 'minmax(240px,2fr)' },
  { key: 'username', header: { ar: 'المستخدم', en: 'User' }, width: '130px' },
  { key: 'at', header: { ar: 'الوقت', en: 'When' }, width: '150px', kind: 'date' },
];
const audCols = [
  { key: 'username', header: { ar: 'المستخدم', en: 'User' }, width: '120px' },
  { key: 'what', header: { ar: 'الإجراء · الكيان · الحقل', en: 'Action · Entity · Field' }, width: 'minmax(220px,1.6fr)' },
  { key: 'oldValue', header: { ar: 'القيمة السابقة', en: 'Old value' }, width: 'minmax(120px,1fr)' },
  { key: 'newValue', header: { ar: 'القيمة الجديدة', en: 'New value' }, width: 'minmax(120px,1fr)' },
  { key: 'at', header: { ar: 'الوقت', en: 'When' }, width: '150px', kind: 'date' },
];

const pathOf = (r) => entityPath(r.entityType, r.entityNumber || r.entityId);
function openRow(r) { const p = pathOf(r); if (p) router.push(p); }
</script>

<template>
  <PageHead :sub="t('سجل نشاط موحد + Audit Trail بالقيم القديمة والجديدة — لا حذف نهائي', 'Unified activity feed + audit trail with old / new values — nothing is ever hard-deleted')" />
  <Tabs :model-value="tab" :tabs="tabs" class="!mb-3" @update:model-value="setTab" />

  <div v-if="tab === 'activity'" class="card">
    <div class="h-2.5" />
    <ActivityFilters :values="draft" :fields="actFilters" @change="(k, v) => (draft[k] = v)" @apply="apply" @clear="clear" />
    <div v-if="act.error.value" class="mx-[18px]"><ErrorBanner :error="act.error.value" :closable="false" /></div>
    <DataTable :columns="actCols" :paged="act.data.value" :loading="act.isLoading.value" :row-key="(r) => r.id" :min-width="700" dense :empty-text="{ ar: 'لا نشاط مطابق.', en: 'No matching activity.' }" @page="setPage" @row-click="openRow">
      <template #cell-entity="{ row }"><EntityCell :row="row" /></template>
      <template #cell-text="{ row }"><span class="leading-[1.7] text-sec">{{ lang === 'ar' ? row.textAr : row.textEn || row.textAr }}</span></template>
      <template #cell-username="{ row }"><span class="font-bold">{{ row.username || 'system' }}</span></template>
      <template #cell-at="{ row }"><span :title="fmtAgo(row.at, lang)">{{ fmtDate(row.at) }}</span></template>
    </DataTable>
  </div>

  <div v-if="tab === 'audit' && canAudit" class="card">
    <div class="h-2.5" />
    <ActivityFilters :values="draft" :fields="audFilters" @change="(k, v) => (draft[k] = v)" @apply="apply" @clear="clear" />
    <div v-if="aud.error.value" class="mx-[18px]"><ErrorBanner :error="aud.error.value" :closable="false" /></div>
    <DataTable :columns="audCols" :paged="aud.data.value" :loading="aud.isLoading.value" :row-key="(r) => r.id" :min-width="820" dense :empty-text="{ ar: 'لا سجلات تدقيق مطابقة.', en: 'No matching audit entries.' }" @page="setPage">
      <template #cell-username="{ row }"><span class="font-bold">{{ row.username || 'system' }}</span></template>
      <template #cell-what="{ row }">
        <div class="leading-[1.7]">
          <Chip small :label="row.action" :fg="actionColor(row.action)" bg="#F7F6FA" class="ltr font-num" />
          <span class="ms-1.5 text-sec">{{ row.entityType }}</span> <EntityCell :row="row" />
          <div v-if="row.field" class="cell-sub"><span class="num">{{ row.field }}</span></div>
        </div>
      </template>
      <template #cell-oldValue="{ row }"><span class="num-mixed break-words text-bad" :title="row.oldValue || null">{{ short(row.oldValue) }}</span></template>
      <template #cell-newValue="{ row }"><span class="num-mixed break-words font-bold text-ok" :title="row.newValue || null">{{ short(row.newValue) }}</span></template>
      <template #cell-at="{ row }">
        <div>{{ fmtDate(row.at) }}</div>
        <div v-if="row.requestId" class="cell-sub num" title="requestId">{{ row.requestId.slice(0, 8) }}</div>
      </template>
    </DataTable>
  </div>

  <div v-if="tab === 'notif'" class="col">
    <ErrorBanner :error="notif.error.value" :closable="false" />
    <EmptyState v-if="notif.unavailable.value" tone="dashed" :text="{ ar: 'الإشعارات غير متاحة لدورك', en: 'Notifications are not available for your role' }" />
    <EmptyState v-else-if="notif.items.value.length === 0 && !notif.loading.value" tone="dashed" :text="{ ar: 'لا إشعارات لدورك حاليًا', en: 'No notifications for your role right now' }" />
    <div v-for="n in notif.items.value" :key="n.id" class="card sm flex items-center gap-2.5 !rounded-[13px] px-[15px] py-[11px]" :class="{ 'cursor-pointer': !!pathOf(n) }" @click="openRow(n)">
      <div class="h-2 w-2 flex-none rounded-full" :class="n.read ? 'bg-[#c9c6d4]' : 'bg-brand'" />
      <div class="flex-1 text-[10.5px] leading-[1.7] text-sec" :class="n.read ? 'font-normal' : 'font-bold'">{{ lang === 'ar' ? n.textAr : n.textEn || n.textAr }}</div>
      <div v-if="n.entityNumber" class="num text-[9.5px] text-violet">{{ n.entityNumber }}</div>
      <div class="num text-[9px] text-faint" dir="ltr">{{ fmtDate(n.at) }}</div>
    </div>
  </div>
</template>
