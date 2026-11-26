<script setup>
// The alerts of the brand dashboards — GET /insights/alerts?owner&read&type&severity&customer&from&to&q&sort&order&page
// → paged rows + `unread`. POST alerts/{id}/read | unread, POST brands/{owner}/alerts/read-all.
//   <AlertsList :owner-id="id" />                 one brand (its drawer, or the outsider's page): filters kept locally
//   <AlertsList :manager query-state />           every brand (the «التنبيهات» tab): filters in the query string, owner filter
// A row opens the alert with the figures it was raised on (`data`).
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, Drawer, ErrorBanner, KV, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtNum, lang, t } from '@/i18n';
import { ALERT_TYPE, SEVERITY, alertTypeOptions, fmtDay, nameOf, own, severityOptions, useInsightsNav, useLocalNav, useSearchParam } from './insights';

const props = defineProps({
  /** One brand; empty = every brand the user may see (manager). */
  ownerId: { type: String, default: '' },
  manager: { type: Boolean, default: false },
  /** Keep the filters in the query string (the alerts tab); otherwise they are local to this list. */
  queryState: { type: Boolean, default: false },
});

const nav = props.queryState ? useInsightsNav() : useLocalNav();
const { page } = nav;
const { text, type, apply } = useSearchParam(nav);
const sort = nav.sortOf(['date', 'severity'], 'desc');

const owner = computed(() => props.ownerId || nav.get('owner') || '');
const read = computed(() => (['0', '1'].includes(nav.get('read')) ? nav.get('read') : ''));
const list = useList('/insights/alerts', () => ({
  owner: owner.value || undefined, read: read.value || undefined, type: nav.get('type') || undefined, severity: nav.get('severity') || undefined,
  customer: nav.get('customer') || undefined, from: nav.get('from') || undefined, to: nav.get('to') || undefined, q: nav.get('q') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const unread = computed(() => list.data.value?.unread ?? 0);

// owner filter (every brand) and customer filter (one brand)
const owners = useList(() => (!props.ownerId && props.manager ? '/insights/brands' : null), () => ({ pageSize: 500, sort: 'name', order: 'asc' }), { staleTime: 30_000 });
const ownerOptions = computed(() => (owners.data.value?.items || []).map((o) => ({ v: o.id, l: o.brandName ? `${o.name} — ${o.brandName}` : o.name })));
const ownerDetail = useGet(() => (owner.value ? `/insights/brands/${encodeURIComponent(owner.value)}` : null), undefined, { staleTime: 30_000 });
const customerOptions = computed(() => (ownerDetail.data.value?.franchisees || []).filter((f) => f.customer).map((f) => ({ v: f.customer.id, l: { ar: `${f.customer.nameAr || f.customer.nameEn} (${f.customer.code})`, en: `${f.customer.nameEn || f.customer.nameAr} (${f.customer.code})` } })));

const READS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: '0', label: { ar: 'غير المقروءة', en: 'Unread' } }, { k: '1', label: { ar: 'المقروءة', en: 'Read' } }];
const hasFilters = computed(() => ['q', 'read', 'type', 'severity', 'customer', 'from', 'to'].some((k) => !!nav.get(k)) || (!props.ownerId && !!nav.get('owner')));
const clear = () => nav.patch({ q: null, read: null, type: null, severity: null, customer: null, from: null, to: null, owner: props.ownerId ? undefined : null, page: null });
const empty = computed(() => (hasFilters.value ? { ar: 'لا تنبيهات تطابق الفلاتر.', en: 'No alert matches the filters.' } : { ar: 'لا تنبيهات بعد. تُحسب يوميًا للملاك النشطين.', en: 'No alert yet. They are computed daily for active owners.' }));

const columns = computed(() => [
  { key: 'title', header: { ar: 'التنبيه', en: 'Alert' }, width: 'minmax(240px,2fr)' },
  { key: 'severity', header: { ar: 'الخطورة', en: 'Severity' }, width: '84px', sortable: true, bare: true },
  { key: 'type', header: { ar: 'النوع', en: 'Type' }, width: '120px' },
  { key: 'owner', header: { ar: 'العلامة', en: 'Brand' }, width: 'minmax(120px,0.8fr)', hidden: !!props.ownerId },
  { key: 'customer', header: { ar: 'الفرع', en: 'Franchisee' }, width: 'minmax(130px,0.9fr)' },
  { key: 'date', header: { ar: 'التاريخ', en: 'Date' }, width: '88px', kind: 'date', sortable: true, value: (r) => fmtDay(r.date) },
  { key: 'actions', header: '', width: '110px', bare: true },
]);
const titleOf = (a) => (lang.value === 'ar' ? a.titleAr || a.titleEn : a.titleEn || a.titleAr) || '—';
const descOf = (a) => (lang.value === 'ar' ? a.descriptionAr || a.descriptionEn : a.descriptionEn || a.descriptionAr) || '';
const typeOf = (a) => bi(a.typeLabel || own(ALERT_TYPE, a.type) || { ar: a.type, en: a.type });

// ---- actions
const act = useAction();
const mark = (a, toRead) => act.run(() => api.postIdempotent(`/insights/alerts/${encodeURIComponent(a.id)}/${toRead ? 'read' : 'unread'}`), {
  success: toRead ? t('عُلّم مقروءًا', 'Marked read') : t('عُلّم غير مقروء', 'Marked unread'), invalidate: ['insights'],
}).then((r) => { if (r && open.value?.id === r.id) open.value = r; return r; });
const readAll = () => act.run(() => api.postIdempotent(`/insights/brands/${encodeURIComponent(owner.value)}/alerts/read-all`), {
  success: (r) => t(`عُلّم ${r.updated} تنبيه مقروءًا`, `${r.updated} alert(s) marked read`), invalidate: ['insights'],
});

// ---- the open alert
const open = ref(null);
const dataRows = computed(() => Object.entries(open.value?.data || {}).filter(([, v]) => v == null || ['string', 'number', 'boolean'].includes(typeof v)));
const fmtVal = (v) => (typeof v === 'number' ? fmtNum(v, Number.isInteger(v) ? 0 : 2) : typeof v === 'boolean' ? (v ? '✓' : '✕') : v ?? '—');
</script>

<template>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[230px]" :model-value="text" :placeholder="{ ar: 'بحث في عنوان التنبيه…', en: 'Search the alert title…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="read" :tabs="READS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('read', k)" />
    <div class="grow" />
    <span class="text-[10px] text-muted">{{ t('غير مقروءة', 'Unread') }} <b class="num" :class="unread ? 'text-bad' : ''">{{ fmtNum(unread) }}</b></span>
    <Btn v-if="owner && unread" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'تعليم الكل مقروءًا', en: 'Mark all read' }" @click="readAll" />
  </div>
  <div class="row wrap mb-2.5">
    <SelectInput v-if="!ownerId && manager" small class="w-[200px]" :model-value="nav.get('owner')" :options="ownerOptions" :placeholder="{ ar: 'كل العلامات', en: 'Every brand' }" @update:model-value="(v) => nav.patch({ owner: v, customer: null, page: null })" />
    <SelectInput v-if="owner && customerOptions.length" small class="w-[190px]" :model-value="nav.get('customer')" :options="customerOptions" :placeholder="{ ar: 'كل الفروع', en: 'Every franchisee' }" @update:model-value="(v) => nav.setFilter('customer', v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('type')" :options="alertTypeOptions()" :placeholder="{ ar: 'كل الأنواع', en: 'Every type' }" @update:model-value="(v) => nav.setFilter('type', v)" />
    <SelectInput small class="w-[130px]" :model-value="nav.get('severity')" :options="severityOptions()" :placeholder="{ ar: 'كل الدرجات', en: 'Any severity' }" @update:model-value="(v) => nav.setFilter('severity', v)" />
    <DateInput small class="w-[140px]" :title="t('من تاريخ', 'From date')" :model-value="nav.get('from')" @update:model-value="(v) => nav.setFilter('from', v)" />
    <DateInput small class="w-[140px]" :title="t('إلى تاريخ', 'To date')" :model-value="nav.get('to')" @update:model-value="(v) => nav.setFilter('to', v)" />
    <Btn v-if="hasFilters" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value || act.error.value" :closable="!!act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="open?.id" :sort="sort" server-sort :empty-text="empty"
               @sort="nav.setSort" @page="nav.setPage" @row-click="(r) => (open = r)">
      <template #cell-title="{ row }">
        <div class="min-w-0">
          <div class="truncate text-[11px]" :class="row.read ? 'font-bold text-sec' : 'font-extrabold text-ink'" dir="auto"><span v-if="!row.read" class="me-1 inline-block h-1.5 w-1.5 rounded-full bg-bad align-middle" />{{ titleOf(row) }}</div>
          <div class="truncate text-[9.5px] text-muted" dir="auto">{{ descOf(row) }}</div>
        </div>
      </template>
      <template #cell-severity="{ row }"><Chip small :map="SEVERITY" :k="row.severity" /></template>
      <template #cell-type="{ row }"><span class="truncate text-[10px] text-sec">{{ typeOf(row) }}</span></template>
      <template #cell-owner="{ row }"><span class="truncate text-[10px] font-bold text-violet" dir="auto">{{ row.owner ? row.owner.brandName || row.owner.name : '—' }}</span></template>
      <template #cell-customer="{ row }">
        <span v-if="row.customer" class="truncate text-[10px]" dir="auto">{{ nameOf(row.customer) }} <span class="num ltr inline-block text-muted">{{ row.customer.code }}</span></span>
        <span v-else class="text-faint">—</span>
      </template>
      <template #cell-actions="{ row }">
        <Btn size="sm" :tone="row.read ? 'ghost' : 'soft'" :loading="act.pending.value" :label="row.read ? { ar: 'غير مقروء', en: 'Unread' } : { ar: 'مقروء ✓', en: 'Read ✓' }" @click.stop="mark(row, !row.read)" />
      </template>
    </DataTable>
  </div>

  <Drawer :open="!!open" :title="open ? titleOf(open) : ''" :sub="open ? typeOf(open) : ''" :width="480" :z-index="80" @close="open = null">
    <template v-if="open">
      <div class="row wrap mb-3">
        <Chip :map="SEVERITY" :k="open.severity" />
        <span class="text-[10px] font-bold" :class="open.read ? 'text-ok' : 'text-bad'">{{ open.read ? t('مقروء', 'Read') : t('غير مقروء', 'Unread') }}</span>
        <div class="grow" />
        <Btn size="sm" tone="soft" :loading="act.pending.value" :label="open.read ? { ar: 'تعليمه غير مقروء', en: 'Mark unread' } : { ar: 'تعليمه مقروءًا', en: 'Mark read' }" @click="mark(open, !open.read)" />
      </div>
      <div v-if="descOf(open)" class="mb-3 whitespace-pre-line text-[12px] leading-[1.9] text-ink" dir="auto">{{ descOf(open) }}</div>
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'التاريخ', en: 'Date' }" :v="fmtDay(open.date)" ltr />
        <KV :k="{ ar: 'العلامة', en: 'Brand' }" :v="open.owner ? open.owner.brandName || open.owner.name : '—'" />
        <KV :k="{ ar: 'الفرع', en: 'Franchisee' }" :v="open.customer ? `${nameOf(open.customer)} · ${open.customer.code}` : '—'" />
        <KV v-if="open.read" :k="{ ar: 'قرأه', en: 'Read by' }" :v="open.readBy ? `${open.readBy} · ${fmtDay(open.readAt)}` : fmtDay(open.readAt)" />
        <KV v-if="open.sourceSystem" :k="{ ar: 'المصدر', en: 'Source' }" :v="open.sourceSystem === 'odoo' ? t('أودو', 'Odoo') : open.sourceSystem" />
      </div>
      <template v-if="dataRows.length">
        <div class="card-title mb-1">{{ t('الأرقام التي رُفع عليها التنبيه', 'The figures the alert was raised on') }}</div>
        <div class="kv-grid">
          <KV v-for="[k, v] in dataRows" :key="k" :k="k" :v="String(fmtVal(v))" ltr />
        </div>
      </template>
    </template>
  </Drawer>
</template>
