<script setup>
// Stock balances (nav `inv`): per-warehouse KPI summary, status / zone / storage / search filters,
// product × warehouse × bin × batch table, stock drawer, staging view and the admin reconciliation panel.
// Deep links: ?q= / ?sku= (search) · ?status=
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, PageHead, SectionCard, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import AdjustForm from './AdjustForm.vue';
import DaysChip from './DaysChip.vue';
import FlagChips from './FlagChips.vue';
import Hint from './Hint.vue';
import MoveForm from './MoveForm.vue';
import ProductCell from './ProductCell.vue';
import RefLink from './RefLink.vue';
import StockDrawer from './StockDrawer.vue';
import { DIRECTION_LABELS, STORAGE_LABELS, dictLabel, dictOptions, pillTabs, pname, useDebounced } from './shared';

const STATUS_PILLS = pillTabs([
  { k: '', ar: 'الكل', en: 'All' }, { k: 'available', ar: 'متاح', en: 'Available' }, { k: 'quarantine', ar: 'محجور', en: 'Quarantine' },
  { k: 'expiring', ar: 'قريب الانتهاء', en: 'Expiring' }, { k: 'expired', ar: 'منتهي', en: 'Expired' }, { k: 'zero', ar: 'نافد', en: 'Zero' },
]);

const auth = useAuth();
const wh = useWarehouse();
const route = useRoute();
const router = useRouter();
const qs = (k) => (typeof route.query[k] === 'string' ? route.query[k] : '');

const canRecon = computed(() => auth.can(['audit.view', 'inventory.adjust']));
/** 'bal' | 'stg' | 'rec' */
const tab = ref('bal');
const status = ref(qs('status'));
const zone = ref('');
const storage = ref('');
const q = ref(qs('q') || qs('sku'));
const dq = useDebounced(q);
const page = ref(1);
/** { key, order } | null — server-side sort */
const sort = ref(null);
/** StockFocus | null */
const focus = ref(null);
/** 'adjust' | 'move' | null */
const form = ref(null);

watch([() => wh.wh, status, zone, storage, dq], () => { page.value = 1; });
watch(() => wh.wh, () => { zone.value = ''; });
// A deep link followed while the page is already open (global search, notifications) re-seeds the filters.
watch(() => route.query, () => { const v = qs('q') || qs('sku'); if (v) q.value = v; if (qs('status')) status.value = qs('status'); });

/** { warehouses: WhSummary[], totals: {...}, costVisible } — `value` fields only arrive with inventory.view_cost. */
const summary = useGet('/inventory/balances/summary', () => ({ ...wh.whParams }));
const whDetail = useGet(() => (!wh.isAll ? `/warehouses/${wh.wh}` : null));
const list = useList('/inventory/balances', () => ({ ...wh.whParams, status: status.value, zone: zone.value, storageClass: storage.value, q: dq.value, page: page.value, pageSize: 50, sort: sort.value?.key, order: sort.value?.order }), { enabled: () => tab.value === 'bal' });
/** { items: StagingRow[], entries: StagingEntry[] } */
const staging = useGet('/inventory/staging', () => ({ ...wh.whParams }), { enabled: () => tab.value === 'stg' });
/** { ok, checked, warehouse, at, mismatches: [{ sku, name, bin, batch, onHand, reserved, ledger, reservedExpected }] } */
const recon = useGet('/inventory/reconciliation', () => ({ ...wh.whParams }), { enabled: () => tab.value === 'rec' && canRecon.value, staleTime: 0 });

const tot = computed(() => summary.data.value?.totals);
const costVisible = computed(() => !!summary.data.value?.costVisible);
const zoneOpts = computed(() => (whDetail.data.value?.zones || []).map((z) => ({ v: z.code, l: `${z.code} · ${lang.value === 'ar' ? z.nameAr : z.nameEn}` })));
const storageOpts = dictOptions(STORAGE_LABELS);
const tabs = computed(() => [
  { k: 'bal', label: { ar: 'الأرصدة', en: 'Balances' }, badge: list.data.value?.total },
  { k: 'stg', label: { ar: 'Staging — مواقع افتراضية', en: 'Staging bins' }, badge: staging.data.value?.items.length },
  { k: 'rec', label: { ar: 'مطابقة السجل ↔ الأرصدة', en: 'Reconciliation' }, hidden: !canRecon.value },
]);

const cols = [
  { key: 'sku', header: 'SKU', width: '92px', kind: 'id' },
  { key: 'name', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(168px,2fr)' },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'WH' }, width: '64px' },
  { key: 'zone', header: { ar: 'المنطقة', en: 'Zone' }, width: '64px' },
  { key: 'rack', header: { ar: 'الرف', en: 'Rack' }, width: '60px', kind: 'muted' },
  { key: 'bin', header: { ar: 'الموقع Bin', en: 'Bin' }, width: '90px', kind: 'id', sortable: true },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '88px' },
  { key: 'expiry', header: { ar: 'الانتهاء / متبقٍ', en: 'Expiry / days' }, width: '124px', sortable: true },
  { key: 'onHand', header: { ar: 'فعلي', en: 'On hand' }, width: '68px', kind: 'num', sortable: true },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '68px', kind: 'num' },
  { key: 'allocated', header: { ar: 'مخصص', en: 'Allocated' }, width: '68px', kind: 'num' },
  { key: 'available', header: { ar: 'متاح', en: 'Available' }, width: '68px', kind: 'num' },
  { key: 'flags', header: { ar: 'الحالة', en: 'Status' }, width: '124px' },
];
const whCols = computed(() => [
  { key: 'code', header: { ar: 'المستودع', en: 'Warehouse' }, width: 'minmax(140px,1.2fr)' },
  { key: 'skus', header: { ar: 'أصناف', en: 'SKUs' }, width: '64px', kind: 'num' },
  { key: 'rows', header: { ar: 'مواقع', en: 'Rows' }, width: '64px', kind: 'num' },
  { key: 'onHand', header: { ar: 'فعلي', en: 'On hand' }, width: '76px', kind: 'num' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '76px', kind: 'num' },
  { key: 'available', header: { ar: 'متاح', en: 'Available' }, width: '76px', kind: 'num' },
  { key: 'quarantine', header: { ar: 'محجور', en: 'Quarantine' }, width: '76px', kind: 'num' },
  { key: 'expired', header: { ar: 'منتهي', en: 'Expired' }, width: '70px', kind: 'num' },
  { key: 'staging', header: 'Staging', width: '70px', kind: 'num' },
  { key: 'inTransit', header: { ar: 'عبور ← / →', en: 'Transit in / out' }, width: '110px' },
  // cost column only with inventory.view_cost (the API reports it as `costVisible`)
  { key: 'value', header: { ar: 'القيمة (ر.س)', en: 'Value (SAR)' }, width: '100px', kind: 'num', hidden: !costVisible.value, value: (w) => fmtNum(w.value ?? 0) },
]);
const stgCols = [
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(160px,1.4fr)' },
  { key: 'loc', header: { ar: 'المستودع / الموقع', en: 'WH / Bin' }, width: '110px' },
  { key: 'direction', header: { ar: 'الاتجاه', en: 'Direction' }, width: '80px' },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '90px' },
  { key: 'onHand', header: { ar: 'الكمية', en: 'Qty' }, width: '64px', kind: 'num' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '64px', kind: 'num' },
  { key: 'reference', header: { ar: 'المستند · الحركة', en: 'Document · movement' }, width: 'minmax(150px,1fr)' },
  { key: 'ageHours', header: { ar: 'العمر', en: 'Age' }, width: '70px' },
  { key: 'at', header: { ar: 'منذ', en: 'Since' }, width: '105px', kind: 'date', value: (r) => fmtDate(r.reference?.at) },
];
const entryCols = [
  { key: 'direction', header: { ar: 'الاتجاه', en: 'Direction' }, width: '80px' },
  { key: 'loc', header: { ar: 'المستودع / الموقع', en: 'WH / Bin' }, width: '110px' },
  { key: 'ref', header: { ar: 'المستند', en: 'Document' }, width: 'minmax(140px,1fr)' },
  { key: 'qty', header: { ar: 'الكمية', en: 'Qty' }, width: '64px', kind: 'num' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '90px' },
  { key: 'createdAt', header: { ar: 'منذ', en: 'Since' }, width: '105px', kind: 'date', value: (r) => fmtDate(r.createdAt) },
];
const reconCols = [
  { key: 'sku', header: 'SKU', width: '105px', kind: 'id' },
  { key: 'name', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(150px,1.2fr)', kind: 'name' },
  { key: 'bin', header: { ar: 'الموقع', en: 'Bin' }, width: '90px', kind: 'id' },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '90px' },
  { key: 'onHand', header: { ar: 'الرصيد', en: 'Balance' }, width: '70px', kind: 'num' },
  { key: 'ledger', header: { ar: 'Σ السجل', en: 'Σ ledger' }, width: '70px', kind: 'num' },
  { key: 'diff', header: { ar: 'الفرق', en: 'Diff' }, width: '64px', kind: 'num' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '70px', kind: 'num' },
  { key: 'reservedExpected', header: { ar: 'Σ التخصيصات', en: 'Σ allocations' }, width: '90px', kind: 'num' },
];

const focusRow = (r) => { focus.value = { sku: r.sku, warehouse: r.warehouse, bin: r.bin, batch: r.batch }; };
const selectedId = computed(() => {
  const f = focus.value;
  return f ? list.data.value?.items.find((r) => r.sku === f.sku && r.bin === f.bin && r.warehouse === f.warehouse)?.id ?? null : null;
});
const balanceRowStyle = (r) => (r.quarantine || r.status === 'expired' ? { background: '#FFF8F8' } : r.blocked ? { background: '#F8F5FD' } : null);
const ageColor = (h) => (h > 24 ? '#b23b3b' : h > 8 ? '#b26a16' : '#55506a');
const filterStatus = (s) => { tab.value = 'bal'; status.value = s; };
const formInitial = computed(() => ({ warehouseCode: wh.isAll ? wh.warehouses[0]?.code : wh.wh }));
</script>

<template>
  <PageHead :sub="t('On Hand − Reserved − Blocked = Available — البيع من المتاح فقط', 'On hand − reserved − blocked = available — sell from available only')">
    <div class="row !gap-1.5">
      <Btn v-if="auth.can('inventory.move')" tone="softPurple" size="sm" :label="{ ar: 'نقل بين مواقع', en: 'Bin move' }" @click="form = 'move'" />
      <Btn v-if="auth.can('inventory.adjust')" tone="outline" size="sm" :label="{ ar: 'تسوية رصيد', en: 'Adjust' }" @click="form = 'adjust'" />
      <Btn v-if="auth.can('inventory.count')" tone="dark" size="sm" :label="{ ar: 'جرد دوري', en: 'Cycle count' }" @click="router.push('/counts')" />
    </div>
  </PageHead>

  <ErrorBanner :error="summary.error.value" :closable="false" />
  <KpiGrid compact>
    <KpiCard compact :loading="summary.isLoading.value" :value="tot?.onHand" :label="{ ar: 'فعلي On Hand', en: 'On hand' }" />
    <KpiCard compact :loading="summary.isLoading.value" :value="tot?.reserved" :label="{ ar: 'محجوز', en: 'Reserved' }" color="#b26a16" />
    <KpiCard compact :loading="summary.isLoading.value" :value="tot?.available" :label="{ ar: 'متاح للبيع', en: 'Available' }" color="#1d7a3e" />
    <KpiCard compact clickable :loading="summary.isLoading.value" :value="tot?.quarantine" :label="{ ar: 'محجور', en: 'Quarantined' }" color="#b23b3b" :active="status === 'quarantine'" @click="filterStatus('quarantine')" />
    <KpiCard compact clickable :loading="summary.isLoading.value" :value="tot?.expired" :label="{ ar: 'منتهي الصلاحية', en: 'Expired' }" color="#b23b3b" :active="status === 'expired'" @click="filterStatus('expired')" />
    <KpiCard compact clickable :loading="summary.isLoading.value" :value="tot?.inTransit" :label="{ ar: 'في العبور (تحويلات)', en: 'In transit' }" color="#654e92" @click="router.push('/returns')" />
    <KpiCard compact clickable :loading="summary.isLoading.value" :value="tot?.staging" :label="{ ar: 'Staging', en: 'Staging' }" color="#0d7f93" :active="tab === 'stg'" @click="tab = 'stg'" />
    <KpiCard v-if="costVisible" compact :value="tot?.value ?? 0" :unit="{ ar: 'ر.س', en: 'SAR' }" :label="{ ar: 'قيمة المخزون (تكلفة)', en: 'Stock value (cost)' }" />
  </KpiGrid>

  <SectionCard v-if="wh.isAll && (summary.data.value?.warehouses.length || 0) > 1" class="mt-3" :padded="false" :title="{ ar: 'الملخص حسب المستودع', en: 'Per-warehouse summary' }" :sub="{ ar: 'اضغط مستودعًا لتصفية الصفحة عليه', en: 'Click a warehouse to filter the page' }">
    <DataTable :columns="whCols" :rows="summary.data.value?.warehouses" dense :min-width="900" :row-key="(w) => w.code" @row-click="(w) => wh.setWh(w.code)">
      <template #cell-code="{ row }"><div><span class="text-[11px] font-extrabold">{{ row.code }}</span> <span class="muted text-[9.5px]">{{ pname(row) }}</span></div></template>
      <template #cell-reserved="{ row }"><span class="text-warn">{{ fmtNum(row.reserved) }}</span></template>
      <template #cell-available="{ row }"><span class="text-ok">{{ fmtNum(row.available) }}</span></template>
      <template #cell-quarantine="{ row }"><span :class="{ 'text-bad': row.quarantine }">{{ fmtNum(row.quarantine) }}</span></template>
      <template #cell-expired="{ row }"><span :class="{ 'text-bad': row.expired }">{{ fmtNum(row.expired) }}</span></template>
      <template #cell-inTransit="{ row }"><span class="num ltr inline-block text-[10.5px] text-warn">+{{ fmtNum(row.inTransitIn) }} / −{{ fmtNum(row.inTransitOut) }}</span></template>
    </DataTable>
  </SectionCard>

  <Tabs v-model="tab" class="mt-3.5" :tabs="tabs" />

  <template v-if="tab === 'bal'">
    <div class="row wrap mt-3 items-end">
      <Tabs v-model="status" :tabs="STATUS_PILLS" variant="pill" class="!mb-0" />
      <div class="flex-1" />
      <SelectInput v-model="zone" small class="min-w-[190px]" :options="zoneOpts" :disabled="wh.isAll" :placeholder="wh.isAll ? { ar: 'المنطقة — اختر مستودعًا أولًا', en: 'Zone — pick a warehouse first' } : { ar: 'كل المناطق', en: 'All zones' }" />
      <SelectInput v-model="storage" small class="min-w-[150px]" :options="storageOpts" :placeholder="{ ar: 'كل فئات التخزين', en: 'All storage classes' }" />
      <TextInput scan v-model="q" small type="search" class="min-w-[240px]" :placeholder="{ ar: 'بحث: SKU / اسم / موقع / دفعة', en: 'Search: SKU / name / bin / batch' }" />
    </div>
    <SectionCard class="mt-3" :padded="false" :title="{ ar: 'الأرصدة حسب المنتج × المستودع × الموقع × الدفعة', en: 'Balances by product × warehouse × bin × batch' }">
      <template #actions><span class="text-[10px] text-faint"><b class="num text-ink">{{ fmtNum(list.data.value?.total ?? 0) }}</b> {{ t('موقع مخزون', 'stock locations') }}</span></template>
      <ErrorBanner :error="list.error.value" :closable="false" class="m-3" />
      <DataTable :columns="cols" :paged="list.data.value" :loading="list.isFetching.value" :min-width="1160" :row-key="(r) => r.id" :selected-key="selectedId" :row-style="balanceRowStyle"
                 server-sort :sort="sort" @sort="sort = $event" @page="page = $event" @row-click="focusRow">
        <template #cell-name="{ row }"><ProductCell :p="row">{{ row.category ? pname(row.category) : '—' }} · {{ dictLabel(STORAGE_LABELS, row.storageClass) }}</ProductCell></template>
        <template #cell-warehouse="{ row }"><span class="text-[10px] font-bold text-sec">{{ row.warehouse }}</span></template>
        <template #cell-zone="{ row }"><span class="num text-[10px] text-sec">{{ row.zone }}</span></template>
        <template #cell-rack="{ row }"><span class="num text-[10px]">{{ row.rack || '—' }}</span></template>
        <template #cell-batch="{ row }"><span class="cell-id !text-[9.5px]">{{ row.batch || '—' }}</span></template>
        <template #cell-expiry="{ row }">
          <div v-if="row.expiry" class="row !gap-1.5"><span class="num text-[10px]">{{ fmtDateOnly(row.expiry) }}</span><DaysChip :days="row.daysToExpiry" /></div>
          <span v-else class="muted">—</span>
        </template>
        <template #cell-reserved="{ row }"><span class="text-warn">{{ fmtNum(row.reserved) }}</span></template>
        <template #cell-allocated="{ row }"><span class="text-brand-dark">{{ fmtNum(row.allocated) }}</span></template>
        <template #cell-available="{ row }"><span :class="row.available > 0 ? 'text-ok' : 'text-bad'">{{ fmtNum(row.available) }}</span></template>
        <template #cell-flags="{ row }"><FlagChips :row="row" /></template>
      </DataTable>
    </SectionCard>
    <Hint>{{ t('المتاح = الفعلي − المحجوز، ولا يشمل الحجر أو الصفوف المجمدة للجرد أو الدفعات المنتهية أو مواقع Staging/المرتجعات/التالف. التخصيص والصرف من المتاح فقط، وكل تغيير للرصيد يمر عبر حركة موثقة في سجل الحركات (append-only). اضغط أي صف لفتح بطاقة الرصيد والإجراءات (تسوية / حجر / نقل).', 'Available = on hand − reserved, excluding quarantine, count-frozen rows, expired batches and staging / returns / damaged zones. Allocation and picking use available only; every balance change is a recorded ledger movement (append-only). Click a row for the stock card and actions (adjust / quarantine / move).') }}</Hint>
  </template>

  <template v-if="tab === 'stg'">
    <SectionCard class="mt-3" :padded="false" :title="{ ar: 'رصيد في مواقع Staging (STG-IN / STG-OUT / PACK)', en: 'Stock in staging bins (STG-IN / STG-OUT / PACK)' }" :sub="{ ar: 'غير متاح للبيع — كل صف موسوم بالمستند الذي وضعه هناك', en: 'Not sellable — each row tagged with the document that put it there' }" :count="staging.data.value?.items.length">
      <ErrorBanner :error="staging.error.value" :closable="false" class="m-3" />
      <DataTable :columns="stgCols" :rows="staging.data.value?.items" :loading="staging.isLoading.value" :min-width="980" :row-key="(r) => r.id" :empty-text="{ ar: 'لا رصيد في مواقع Staging — كل شيء في مكانه ✓', en: 'Nothing in staging — everything put away ✓' }" @row-click="focusRow">
        <template #cell-product="{ row }"><ProductCell :p="row" /></template>
        <template #cell-loc="{ row }"><span class="cell-id">{{ row.warehouse }}/{{ row.bin }}</span></template>
        <template #cell-direction="{ row }"><Chip small :map="DIRECTION_LABELS" :k="row.direction" /></template>
        <template #cell-batch="{ row }"><span class="cell-id !text-[9.5px]">{{ row.batch || '—' }}</span></template>
        <template #cell-reserved="{ row }"><span class="text-warn">{{ fmtNum(row.reserved) }}</span></template>
        <template #cell-reference="{ row }">
          <div v-if="row.reference"><RefLink :type="row.reference.type" :number="row.reference.number" /> <Chip small :map="MOVEMENT_LABELS" :k="row.reference.movementType" /><div class="cell-sub">{{ row.reference.movement }} · {{ row.reference.by || '—' }}</div></div>
          <span v-else class="muted">—</span>
        </template>
        <template #cell-ageHours="{ row }">
          <template v-if="row.ageHours == null">—</template>
          <span v-else class="num text-[10.5px]" :style="{ color: ageColor(row.ageHours) }">{{ fmtNum(row.ageHours) }} {{ t('س', 'h') }}</span>
        </template>
      </DataTable>
    </SectionCard>
    <SectionCard class="mt-3" :padded="false" :title="{ ar: 'طوابير Staging بانتظار المعالجة', en: 'Staging queue (waiting)' }" :count="staging.data.value?.entries.length">
      <DataTable :columns="entryCols" :rows="staging.data.value?.entries" :loading="staging.isLoading.value" :min-width="700" :row-key="(r) => r.id" :empty-text="{ ar: 'لا عناصر بانتظار', en: 'Queue empty' }">
        <template #cell-direction="{ row }"><Chip small :map="DIRECTION_LABELS" :k="row.direction" /></template>
        <template #cell-loc="{ row }"><span class="cell-id">{{ row.warehouse }}/{{ row.bin }}</span></template>
        <template #cell-ref="{ row }"><span><RefLink :type="row.referenceType" :number="row.referenceNumber" /> <span class="muted text-[9px]">{{ row.referenceType }}</span></span></template>
        <template #cell-status="{ row }"><Chip small :label="row.status === 'waiting' ? { ar: 'بانتظار', en: 'Waiting' } : row.status" fg="#b26a16" bg="#fbf0dd" /></template>
      </DataTable>
    </SectionCard>
    <Hint tone="teal">{{ t('STG-IN: مستلم ولم يُخزّن بعد (Putaway) · STG-OUT / PACK: مصروف للتجهيز ولم يُحمّل بعد. الأعمار الطويلة (> 24 س) تستحق متابعة.', 'STG-IN: received, not yet put away · STG-OUT / PACK: picked, not yet loaded. Long ages (> 24h) need follow-up.') }}</Hint>
  </template>

  <template v-if="tab === 'rec' && canRecon">
    <SectionCard class="mt-3" :title="{ ar: 'مطابقة الأرصدة مع سجل الحركات', en: 'Balances vs ledger reconciliation' }" :sub="recon.data.value ? `${recon.data.value.warehouse} · ${fmtDate(recon.data.value.at)}` : null">
      <template #actions><Btn tone="soft" size="sm" :loading="recon.isFetching.value" :label="{ ar: 'إعادة الفحص', en: 'Re-run' }" @click="recon.refetch()" /></template>
      <ErrorBanner :error="recon.error.value" :closable="false" />
      <div v-if="recon.isLoading.value" class="skel h-10" />
      <template v-if="recon.data.value">
        <div class="banner !mb-3" :class="recon.data.value.ok ? 'green' : 'red'">
          <div>{{ recon.data.value.ok
            ? t(`✓ مطابق — فُحص ${fmtNum(recon.data.value.checked)} صفًا: Σ السجل = الرصيد، والمحجوز = Σ التخصيصات النشطة`, `✓ Consistent — ${fmtNum(recon.data.value.checked)} rows checked: Σ ledger = balance and reserved = Σ active allocations`)
            : t(`✕ ${fmtNum(recon.data.value.mismatches.length)} صف غير مطابق من ${fmtNum(recon.data.value.checked)} — راجع الحركات أو أجرِ تسوية`, `✕ ${fmtNum(recon.data.value.mismatches.length)} of ${fmtNum(recon.data.value.checked)} rows inconsistent — review movements or adjust`) }}</div>
        </div>
        <DataTable :columns="reconCols" :rows="recon.data.value.mismatches" dense :min-width="860" :row-key="(r) => `${r.sku}|${r.bin}|${r.batch}`" :empty-text="{ ar: 'لا فروقات ✓', en: 'No mismatches ✓' }"
                   @row-click="(r) => (focus = { sku: r.sku, bin: r.bin, batch: r.batch === '—' ? null : r.batch })">
          <template #cell-batch="{ row }"><span class="cell-id !text-[9.5px]">{{ row.batch }}</span></template>
          <template #cell-ledger="{ row }"><span :class="row.ledger === row.onHand ? 'text-ok' : 'text-bad'">{{ fmtNum(row.ledger) }}</span></template>
          <template #cell-diff="{ row }"><span class="text-bad">{{ fmtNum(row.onHand - row.ledger) }}</span></template>
          <template #cell-reservedExpected="{ row }"><span :class="(row.reservedExpected ?? row.reserved) === row.reserved ? 'text-ok' : 'text-bad'">{{ fmtNum(row.reservedExpected ?? 0) }}</span></template>
        </DataTable>
      </template>
    </SectionCard>
    <Hint tone="amber">{{ t('يُحسب الرصيد المتوقع لكل صف من مجموع حركات السجل (داخل − خارج) ويُقارن بالرصيد الفعلي، ويُقارن المحجوز بمجموع التخصيصات النشطة. أي فرق يعني حركة ناقصة أو تدخّلًا خارج المحرك.', 'Expected balance per row = Σ ledger in − out, compared with on hand; reserved is compared with Σ active allocations. Any difference means a missing movement or a change outside the engine.') }}</Hint>
  </template>

  <StockDrawer :focus="focus" @close="focus = null" />
  <AdjustForm :open="form === 'adjust'" :initial="formInitial" @close="form = null" />
  <MoveForm :open="form === 'move'" :initial="formInitial" @close="form = null" />
</template>
