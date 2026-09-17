<script setup>
// Control Tower: KPI row → open exceptions queue (1.5fr) + SLA engine / stock at risk / throughput / integrations (1fr).
// Data: GET /api/tower (aggregates, every 30 s) + GET /api/exceptions (paged queue with ack / resolve) + POST /api/exceptions.
// Every figure is the API's; integrations show exactly the status the API reports.
//
// Tower: { generatedAt, costVisible, kpis: [{ key, labelAr, labelEn, value, hint?, link? }],
//   exceptions: { bySeverity, byStatus: { open, ack, resolved }, byKind: [{ kind, count }], queue: ExceptionRow[] },
//   slaBreaches: { count, items: [{ number, kind, slaHours, overdueMin, … }] },
//   lateTrips: { count, items: [{ number, status, delayMin, routeAr, routeEn, warehouse: { code }, vehicle, driver, path }] },
//   inboundAtRisk: { count, items: [{ number, eta, daysLate, po, supplierAr, supplierEn, warehouse, path }] },
//   stockAtRisk: { expiredQty, quarantineQty, damagedQty, blockedQty, expiredValue?, quarantineValue? },
//   transfersInTransit: { count, qty }, returnsAwaitingInspection: { count },
//   throughputToday: [{ warehouseId, code, grnQty, grns, pickedQty, dispatchedOrders, dispatchedTrips }],
//   integrations: [{ key, labelAr, labelEn, status, configVar }] }
import { computed, ref } from 'vue';
import { api, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, KpiCard, KpiGrid, PageHead, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { TRIP_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import Ago from './Ago.vue';
import CardTitle from './CardTitle.vue';
import ExceptionActionModal from './ExceptionActionModal.vue';
import ExceptionActions from './ExceptionActions.vue';
import IntegrationChip from './IntegrationChip.vue';
import ListRow from './ListRow.vue';
import SevChip from './SevChip.vue';
import SlaBadge from './SlaBadge.vue';
import StateChip from './StateChip.vue';
import { KIND_LABELS, ROLE_OPTIONS, SEVERITY_OPTIONS, excText, kindLabel, roleLabel, useExceptionActions, useQueryState } from './shared';

const KPI_COLOR = { critical: '#b23b3b', high: '#b26a16', medium: '#55506a', slaBreached: '#b23b3b', lateTrips: '#b26a16', inboundAtRisk: '#654e92', transfersInTransit: '#654e92', returnsAwaitingInspection: '#0d7f93', expiredQty: '#b23b3b', quarantineQty: '#b26a16' };

const auth = useAuth();
const wh = useWarehouse();
const qs = useQueryState();
const { router } = qs;
const tower = useGet('/tower', () => ({ ...wh.whParams }), { refetchInterval: 30_000 });
const tw = computed(() => tower.data.value);

// ---- queue filters (URL-synced: ?severity=c&sla=breached&status=…)
const severity = computed(() => qs.get('severity'));
const status = computed(() => qs.get('status') || 'open');
const kind = computed(() => qs.get('kind'));
const owner = computed(() => qs.get('owner'));
const breachedOnly = computed(() => qs.get('sla') === 'breached');
const page = computed(() => Number(qs.get('page') || 1));
const q = ref(qs.get('q'));
const filtered = computed(() => !!(severity.value || kind.value || owner.value || q.value || status.value !== 'open' || breachedOnly.value));
function clearFilters() { q.value = ''; qs.replace({}); }

const list = useList('/exceptions', () => ({
  status: status.value === 'all' ? undefined : status.value, severity: severity.value || undefined, kind: kind.value || undefined, owner: owner.value || undefined,
  q: q.value || undefined, page: page.value, pageSize: 15,
}), { refetchInterval: 30_000 });
/** "SLA breached only" narrows the loaded page (the API has no such filter). */
const paged = computed(() => {
  const data = list.data.value;
  if (!data) return null;
  return breachedOnly.value ? { ...data, items: (data.items || []).filter((e) => e.sla?.breached) } : data;
});
const exc = useExceptionActions();

const statusTabs = computed(() => [
  { k: 'open', label: { ar: 'مفتوح', en: 'Open' }, badge: tw.value?.exceptions.byStatus.open }, { k: 'ack', label: { ar: 'قيد المعالجة', en: 'Acknowledged' }, badge: tw.value?.exceptions.byStatus.ack },
  { k: 'resolved', label: { ar: 'مُغلق', en: 'Resolved' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } },
]);
const kindFilterOpts = computed(() => (tw.value?.exceptions.byKind || []).map((r) => ({ v: r.kind, l: { ar: `${KIND_LABELS[r.kind]?.ar || r.kind} (${r.count})`, en: `${KIND_LABELS[r.kind]?.en || r.kind} (${r.count})` } })));

const excCols = computed(() => [
  { key: 'severity', header: { ar: 'الخطورة', en: 'Sev.' }, width: '74px' },
  { key: 'number', header: '#', width: '92px', kind: 'id' },
  { key: 'text', header: { ar: 'الاستثناء', en: 'Exception' }, width: 'minmax(220px,2fr)' },
  { key: 'ownerRole', header: { ar: 'المسؤول', en: 'Owner' }, width: '110px' },
  { key: 'sla', header: 'SLA', width: '150px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '96px' },
  { key: 'createdAt', header: { ar: 'منذ', en: 'Age' }, width: '70px' },
  { key: 'act', header: '', width: '150px', hidden: !exc.manage },
]);
const throughputCols = [
  { key: 'code', header: { ar: 'المستودع', en: 'Warehouse' } },
  { key: 'grnQty', header: { ar: 'استلام', en: 'GRN' }, kind: 'num', width: '80px' },
  { key: 'pickedQty', header: { ar: 'تجهيز', en: 'Picked' }, kind: 'num', width: '70px' },
  { key: 'dispatchedOrders', header: { ar: 'شحن', en: 'Dispatched' }, kind: 'num', width: '70px' },
  { key: 'dispatchedTrips', header: { ar: 'رحلات', en: 'Trips' }, kind: 'num', width: '60px' },
];

// ---- KPI cards: a /tower?… link becomes a queue filter, anything else navigates
function goKpi(k) {
  if (!k.link) return;
  if (k.link.startsWith('/tower')) {
    const u = new URL(k.link, 'http://x');
    const n = { ...qs.route.query };
    delete n.severity; delete n.sla; delete n.page;
    u.searchParams.forEach((v, kk) => { n[kk] = v; });
    qs.replace(n);
    return;
  }
  router.push(k.link);
}
const kpiActive = (k) => (k.key === 'critical' && severity.value === 'c') || (k.key === 'high' && severity.value === 'w') || (k.key === 'medium' && severity.value === 'i') || (k.key === 'slaBreached' && breachedOnly.value);

// ---- SLA engine rows: one per open kind (target = SLA hours seen on the queue, breaches from slaBreaches)
const slaRows = computed(() => {
  const d = tw.value;
  if (!d) return [];
  const target = {};
  for (const e of d.exceptions.queue) if (target[e.kind] == null) target[e.kind] = e.slaHours;
  for (const e of d.slaBreaches.items) if (target[e.kind] == null) target[e.kind] = e.slaHours;
  const breaches = {};
  for (const e of d.slaBreaches.items) breaches[e.kind] = (breaches[e.kind] || 0) + 1;
  return d.exceptions.byKind.map((r) => ({ kind: r.kind, open: r.count, target: target[r.kind], breaches: breaches[r.kind] || 0 }));
});

// ---- open-exception form
const openForm = ref(false);
const fields = [
  { k: 'kind', label: { ar: 'نوع الاستثناء', en: 'Kind' }, type: 'select', opts: Object.entries(KIND_LABELS).map(([v, l]) => ({ v, l })), required: true, def: 'other' },
  { k: 'severity', label: { ar: 'الخطورة', en: 'Severity' }, type: 'select', opts: SEVERITY_OPTIONS, required: true, def: 'w' },
  { k: 'textAr', label: { ar: 'الوصف (عربي)', en: 'Description (AR)' }, type: 'area', required: true, full: true },
  { k: 'textEn', label: { ar: 'الوصف (إنجليزي)', en: 'Description (EN)' }, type: 'text', dir: 'ltr' },
  { k: 'ownerRole', label: { ar: 'الدور المسؤول', en: 'Owner role' }, type: 'select', opts: ROLE_OPTIONS, hint: { ar: 'يُحدد تلقائيًا حسب النوع إن تُرك فارغًا', en: 'Defaults by kind when empty' } },
  { k: 'slaHours', label: { ar: 'SLA (ساعات)', en: 'SLA (hours)' }, type: 'num', min: 0.5, step: 1, hint: { ar: 'يُقرأ من الإعدادات إن تُرك فارغًا', en: 'From settings when empty' } },
  { k: 'entityType', label: { ar: 'نوع الكيان', en: 'Entity type' }, type: 'select', opts: [['SalesOrder', 'SalesOrder / SO'], ['FulfillmentOrder', 'FulfillmentOrder / FO'], ['PurchaseOrder', 'PurchaseOrder / PO'], ['InboundShipment', 'InboundShipment'], ['GoodsReceipt', 'GRN'], ['Trip', 'Trip'], ['ReturnOrder', 'Return'], ['WarehouseTransfer', 'Transfer'], ['Product', 'Product'], ['Vehicle', 'Vehicle'], ['Driver', 'Driver']] },
  { k: 'entityNumber', label: { ar: 'رقم الكيان', en: 'Entity number' }, type: 'text', dir: 'ltr', ph: 'SO-00012' },
];
</script>

<template>
  <PageHead :sub="t('العمليات الحرجة الآن — Exception Management وSLA لا مجرد تسجيل بيانات', 'What is critical right now — exception management and SLA, not data entry')">
    <Btn v-if="auth.can('exception.create')" tone="dark" :label="{ ar: '+ فتح استثناء', en: '+ Open exception' }" @click="openForm = true" />
  </PageHead>
  <ErrorBanner :error="tower.error.value" :closable="false" />

  <KpiGrid compact class="!grid-cols-[repeat(auto-fit,minmax(140px,1fr))]">
    <template v-if="tower.isLoading.value && !tw"><KpiCard v-for="i in 6" :key="i" loading compact label="" /></template>
    <template v-else>
      <KpiCard v-for="k in tw?.kpis || []" :key="k.key" compact clickable :value="k.value" :label="{ ar: k.labelAr, en: k.labelEn }" :sub="k.hint" :color="KPI_COLOR[k.key] || '#20242E'" :active="kpiActive(k)" @click="goKpi(k)" />
    </template>
  </KpiGrid>

  <div class="grid-2 items-start">
    <!-- left: exceptions queue -->
    <div class="col !gap-3.5">
      <div class="card">
        <CardTitle>
          {{ t('الاستثناءات المفتوحة — مرتبة بالخطورة', 'Open exceptions — by severity') }}
          <template #right>
            <span class="num text-[10px] font-normal text-faint">{{ tw ? `${t('مفتوح', 'open')} ${fmtNum(tw.exceptions.byStatus.open)} · ${t('قيد المعالجة', 'ack')} ${fmtNum(tw.exceptions.byStatus.ack)} · ${t('مُغلق', 'resolved')} ${fmtNum(tw.exceptions.byStatus.resolved)}` : '' }}</span>
          </template>
        </CardTitle>
        <div class="row wrap px-[18px] pb-2.5">
          <Tabs variant="pill" class="!mb-0" :model-value="status" :tabs="statusTabs" @update:model-value="(k) => qs.setParam('status', k === 'open' ? null : k)" />
          <SelectInput small class="w-[130px]" :model-value="severity" :options="SEVERITY_OPTIONS" :placeholder="{ ar: 'كل الخطورات', en: 'All severities' }" @update:model-value="(v) => qs.setParam('severity', v || null)" />
          <SelectInput small class="w-[150px]" :model-value="kind" :options="kindFilterOpts" :placeholder="{ ar: 'كل الأنواع', en: 'All kinds' }" @update:model-value="(v) => qs.setParam('kind', v || null)" />
          <SelectInput small class="w-[140px]" :model-value="owner" :options="ROLE_OPTIONS" :placeholder="{ ar: 'كل الأدوار', en: 'All owners' }" @update:model-value="(v) => qs.setParam('owner', v || null)" />
          <TextInput v-model="q" small class="w-[170px]" :placeholder="{ ar: 'بحث برقم / نص…', en: 'Search number / text…' }" @enter="qs.setParam('q', q || null)" />
          <Chip v-if="breachedOnly" class="cursor-pointer" :label="{ ar: 'خرق SLA فقط ✕', en: 'SLA breached only ✕' }" fg="#b23b3b" bg="#fdecec" @click="qs.setParam('sla', null)" />
          <Btn v-if="filtered" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلتر', en: 'Clear' }" @click="clearFilters" />
        </div>
        <div v-if="list.error.value" class="mx-[18px]"><ErrorBanner :error="list.error.value" :closable="false" /></div>
        <DataTable :columns="excCols" :paged="paged" :loading="list.isLoading.value" :row-key="(e) => e.number" :min-width="760" dense :empty-text="{ ar: 'لا استثناءات مطابقة ✓', en: 'No matching exceptions ✓' }"
                   @row-click="(e) => router.push(`/exc/${e.number}`)" @page="(p) => qs.setParam('page', p > 1 ? String(p) : null)">
          <template #cell-severity="{ row }"><SevChip :k="row.severity" small /></template>
          <template #cell-text="{ row }">
            <div class="leading-[1.7]">
              <div class="font-bold text-sec">{{ excText(row, lang) }}</div>
              <div class="cell-sub" style="direction: inherit">
                {{ kindLabel(row.kind) }}<template v-if="row.entityNumber"> · <span class="num text-violet">{{ row.entityNumber }}</span></template><template v-if="row.documentNumber && row.documentNumber !== row.entityNumber"> · <span class="num">{{ row.documentNumber }}</span></template>
              </div>
            </div>
          </template>
          <template #cell-ownerRole="{ row }"><Chip small :label="roleLabel(row.ownerRole)" fg="#654e92" bg="#efeaf8" /></template>
          <template #cell-sla="{ row }"><SlaBadge :sla="row.sla" :sla-hours="row.slaHours" :status="row.status" /></template>
          <template #cell-status="{ row }"><StateChip :k="row.status" small /></template>
          <template #cell-createdAt="{ row }"><Ago :at="row.createdAt" /></template>
          <template #cell-act="{ row }"><ExceptionActions :row="row" @act="(mode) => exc.open(mode, row.number)" /></template>
        </DataTable>
      </div>

      <!-- late trips + inbound at risk -->
      <div v-if="tw?.lateTrips.count || tw?.inboundAtRisk.count" class="grid-eq">
        <div v-if="tw.lateTrips.count" class="card sm">
          <CardTitle>{{ t('رحلات متأخرة', 'Late trips') }} <span class="num text-warn">({{ fmtNum(tw.lateTrips.count) }})</span></CardTitle>
          <ListRow v-for="tr in tw.lateTrips.items" :key="tr.number" clickable @click="router.push(tr.path)">
            <span class="cell-id flex-none">{{ tr.number }}</span>
            <div class="flex-1 text-[10.5px] leading-[1.6] text-sec">
              {{ lang === 'ar' ? tr.routeAr : tr.routeEn || tr.routeAr }} · {{ tr.warehouse.code }}<template v-if="tr.vehicle"> · <span class="num">{{ tr.vehicle.code }}</span></template><template v-if="tr.driver"> · {{ tr.driver.nameAr }}</template>
            </div>
            <Chip :map="TRIP_LABELS" :k="tr.status" small />
            <Chip small :label="`+${fmtNum(tr.delayMin)} ${t('د', 'min')}`" fg="#b23b3b" bg="#fdecec" />
          </ListRow>
        </div>
        <div v-if="tw.inboundAtRisk.count" class="card sm">
          <CardTitle>{{ t('شحنات واردة متأخرة', 'Inbound at risk') }} <span class="num text-violet">({{ fmtNum(tw.inboundAtRisk.count) }})</span></CardTitle>
          <ListRow v-for="s in tw.inboundAtRisk.items" :key="s.number" clickable @click="router.push(s.path)">
            <span class="cell-id flex-none">{{ s.number }}</span>
            <div class="flex-1 text-[10.5px] leading-[1.6] text-sec">{{ lang === 'ar' ? s.supplierAr : s.supplierEn }} · <span class="num text-violet">{{ s.po }}</span> · {{ s.warehouse }}</div>
            <span class="cell-date">ETA {{ fmtDateOnly(s.eta) }}</span>
            <Chip small :label="`${fmtNum(s.daysLate)} ${t('يوم تأخير', 'days late')}`" fg="#b26a16" bg="#fbf0dd" />
          </ListRow>
        </div>
      </div>
    </div>

    <!-- right: SLA engine · stock at risk · throughput · integrations -->
    <div class="col !gap-3.5">
      <div class="card">
        <CardTitle>
          {{ t('محرك SLA — حسب نوع الاستثناء', 'SLA engine — by exception kind') }}
          <template #right>
            <template v-if="tw">
              <Chip v-if="tw.slaBreaches.count" small class="cursor-pointer" :label="{ ar: `خرق نشط (${tw.slaBreaches.count})`, en: `Active breaches (${tw.slaBreaches.count})` }" fg="#b23b3b" bg="#fdecec" @click="qs.setParam('sla', 'breached')" />
              <Chip v-else small :label="{ ar: 'ملتزم ✓', en: 'On target ✓' }" fg="#1d7a3e" bg="#e6f9ec" />
            </template>
          </template>
        </CardTitle>
        <div class="flex border-t border-line-2 bg-soft px-[18px] py-[7px] text-[9px] font-extrabold text-faint">
          <div class="flex-[1.4]">{{ t('القاعدة', 'Rule') }}</div><div class="flex-[0.8]">{{ t('الهدف', 'Target') }}</div><div class="w-[110px]">{{ t('الحالة', 'Status') }}</div>
        </div>
        <div v-if="slaRows.length === 0" class="empty !p-4">{{ tw ? t('لا استثناءات مفتوحة ✓', 'No open exceptions ✓') : t('جارٍ التحميل…', 'Loading…') }}</div>
        <div v-for="s in slaRows" :key="s.kind" class="flex cursor-pointer items-center border-t border-line-2 px-[18px] py-[9px]" @click="qs.setParam('kind', s.kind)">
          <div class="flex-[1.4] text-[10px] font-bold leading-[1.6] text-sec">{{ kindLabel(s.kind) }} <span class="num text-faint">({{ s.open }})</span></div>
          <div class="num flex-[0.8] text-[10px] !font-extrabold text-violet">{{ s.target != null ? `${s.target} ${t('س', 'h')}` : '—' }}</div>
          <div class="w-[110px]">
            <Chip v-if="s.breaches" small :label="{ ar: `خرق نشط (${s.breaches})`, en: `Breach (${s.breaches})` }" fg="#b23b3b" bg="#fdecec" />
            <Chip v-else small :label="{ ar: 'ملتزم ✓', en: 'On target ✓' }" fg="#1d7a3e" bg="#e6f9ec" />
          </div>
        </div>
      </div>

      <div v-if="tw" class="card sm">
        <CardTitle>{{ t('مخزون معرّض للخطر', 'Stock at risk') }}</CardTitle>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(110px,1fr))] gap-2 px-[18px] pb-3.5">
          <div class="tile red cursor-pointer" @click="router.push('/batches?status=expired')">
            <div class="tile-v text-bad">{{ fmtNum(tw.stockAtRisk.expiredQty) }}</div>
            <div class="tile-l">{{ t('منتهي الصلاحية', 'Expired') }}<span v-if="tw.costVisible && tw.stockAtRisk.expiredValue != null" class="num"> · {{ fmtMoney(tw.stockAtRisk.expiredValue) }} {{ t('ر.س', 'SAR') }}</span></div>
          </div>
          <div class="tile amber cursor-pointer" @click="router.push('/inv?status=quarantine')">
            <div class="tile-v text-warn">{{ fmtNum(tw.stockAtRisk.quarantineQty) }}</div>
            <div class="tile-l">{{ t('محجور', 'Quarantined') }}<span v-if="tw.costVisible && tw.stockAtRisk.quarantineValue != null" class="num"> · {{ fmtMoney(tw.stockAtRisk.quarantineValue) }} {{ t('ر.س', 'SAR') }}</span></div>
          </div>
          <div class="tile"><div class="tile-v text-sec">{{ fmtNum(tw.stockAtRisk.damagedQty) }}</div><div class="tile-l">{{ t('تالف', 'Damaged') }}</div></div>
          <div class="tile"><div class="tile-v text-sec">{{ fmtNum(tw.stockAtRisk.blockedQty) }}</div><div class="tile-l">{{ t('محظور', 'Blocked') }}</div></div>
          <div class="tile purple cursor-pointer" @click="router.push('/returns?tab=transfers')">
            <div class="tile-v text-violet">{{ fmtNum(tw.transfersInTransit.count) }}</div>
            <div class="tile-l">{{ t('تحويلات في العبور', 'Transfers in transit') }} · {{ fmtNum(tw.transfersInTransit.qty) }} {{ t('وحدة', 'u') }}</div>
          </div>
          <div class="tile teal cursor-pointer" @click="router.push('/returns?status=received')">
            <div class="tile-v text-brand-dark">{{ fmtNum(tw.returnsAwaitingInspection.count) }}</div>
            <div class="tile-l">{{ t('مرتجعات بانتظار الفحص', 'Returns to inspect') }}</div>
          </div>
        </div>
      </div>

      <div v-if="tw && tw.throughputToday.length > 0" class="card sm">
        <CardTitle>{{ t('إنتاجية اليوم — حسب المستودع', 'Throughput today — by warehouse') }}</CardTitle>
        <DataTable :rows="tw.throughputToday" :row-key="(r) => r.warehouseId" dense :zebra="false" :columns="throughputCols">
          <template #cell-code="{ row }"><span class="font-extrabold">{{ row.code }}</span></template>
          <template #cell-grnQty="{ row }">{{ fmtNum(row.grnQty) }} <span class="muted">({{ row.grns }})</span></template>
        </DataTable>
      </div>

      <!-- Integrations / B2B outbox — dark card; every adapter reports its honest state -->
      <div class="rounded-[18px] bg-night px-[18px] py-[15px]">
        <div class="mb-2.5 flex items-center">
          <div class="flex-1 text-[12px] font-extrabold text-white">{{ t('التكاملات — B2B Outbox', 'Integrations — B2B outbox') }}</div>
          <Btn v-if="auth.can('settings.manage')" tone="ghost" size="sm" class="!text-[#7FD6E5]" :label="{ ar: 'الإعدادات ←', en: 'Settings →' }" @click="router.push('/settings?tab=integrations')" />
        </div>
        <div class="col !gap-[7px]">
          <div v-for="i in tw?.integrations || []" :key="i.key" class="flex items-center gap-2">
            <div class="h-1.5 w-1.5 flex-none rounded-full" :class="i.status === 'connected' ? 'pulse bg-brand' : 'bg-warn'" />
            <div class="min-w-[90px] text-[10px] font-bold text-[#7FD6E5]">{{ lang === 'ar' ? i.labelAr : i.labelEn }}</div>
            <div class="num text-[8px] text-[#5b6076]" dir="ltr">{{ i.configVar }}</div>
            <div class="flex-1 text-end"><IntegrationChip :status="i.status" small /></div>
          </div>
          <div v-if="tw && tw.integrations.length === 0" class="text-[10px] text-[#8b90a5]">—</div>
        </div>
        <div class="mt-2.5 text-[8.5px] leading-[1.7] text-[#5b6076]">{{ t('لا تُرسل الأحداث فعليًا إلا عند اتصال المنصة — تبقى في الـ Outbox بحالة Integration Pending.', 'Events are only delivered once the platform is connected — until then they wait in the outbox as Integration Pending.') }}</div>
      </div>
    </div>
  </div>

  <ExceptionActionModal :mode="exc.state.value?.mode" :number="exc.state.value?.number" @close="exc.close()" />
  <FormDrawer :open="openForm" :title="{ ar: 'فتح استثناء جديد', en: 'Open a new exception' }" :sub="{ ar: 'يُسند للدور المسؤول ويبدأ عدّاد SLA فورًا', en: 'Assigned to the owner role — the SLA clock starts now' }"
              :fields="fields" :submit="(v) => api.postIdempotent('/exceptions', v)" :submit-label="{ ar: 'فتح الاستثناء', en: 'Open exception' }"
              :action="{ success: (e) => t(`تم فتح الاستثناء ${e?.number || ''}`, `Exception ${e?.number || ''} opened`), invalidate: ['exceptions', 'tower', 'dashboard', 'activity'] }"
              @close="openForm = false" @done="(e) => { if (e?.number) router.push(`/exc/${e.number}`); }" />
</template>
