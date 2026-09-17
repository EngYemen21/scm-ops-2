<script setup>
// Order consolidation: left pool of confirmed / allocated orders grouped by zone / customer / delivery date / warehouse
// with checkboxes, sticky dark summary → "create consolidation batch"; batches table with status pills and the batch
// detail card (BatchDetail). Data: /api/sales/orders and /api/sales/consolidations.
// Deep links: `/consol?q=OC-…` opens a batch, `/consol?pick=SO-…` pre-selects an order in the pool.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, PageHead, Tabs } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { OC_LABELS, SO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import BatchDetail from './BatchDetail.vue';
import SoDrawer from './SoDrawer.vue';
import { GROUPS, OC_STEPS, custsOf, itemsOf, sumOf, zonesOf } from './consol';
import { custName } from './shared';

const auth = useAuth();
const wh = useWarehouse();
const route = useRoute();
const router = useRouter();
const act = useAction();

const qStr = (v) => (typeof v === 'string' && v ? v : null);
const group = ref('zone');
/** Selected order numbers of the pool. */
const sel = ref(new Set(qStr(route.query.pick) ? [route.query.pick] : []));
const openOc = ref(qStr(route.query.q));
const ocStatus = ref('');
const page = ref(1);
const openSo = ref(null);
watch(() => route.query.q, (q) => { if (qStr(q)) openOc.value = q; });

// pool: confirmed + allocated orders of the selected warehouse that are not consolidated yet (the API filters one status at a time)
const confirmed = useList('/sales/orders', () => ({ ...wh.whParams, pageSize: 100, status: 'confirmed' }));
const allocated = useList('/sales/orders', () => ({ ...wh.whParams, pageSize: 100, status: 'allocated' }));
const pool = computed(() => [...(confirmed.data.value?.items || []), ...(allocated.data.value?.items || [])].filter((o) => !o.consolidation));
const poolLoading = computed(() => confirmed.isLoading.value || allocated.isLoading.value);
const poolError = computed(() => confirmed.error.value || allocated.error.value);

const groupTabs = GROUPS.map((g) => ({ k: g.k, label: { ar: g.ar, en: g.en } }));
function groupKey(o) {
  if (group.value === 'zone') return o.customer.zone || t('بدون منطقة', 'No zone');
  if (group.value === 'cust') return custName(o.customer);
  if (group.value === 'due') return fmtDateOnly(o.dueDate);
  return `${o.warehouse.code} — ${o.warehouse.nameAr}`;
}
const groups = computed(() => {
  const m = new Map();
  for (const o of pool.value) { const k = groupKey(o); if (!m.has(k)) m.set(k, []); m.get(k).push(o); }
  return Array.from(m.entries()).map(([key, rows]) => ({ key, rows, kg: sumOf(rows, 'kg'), allOn: rows.every((r) => sel.value.has(r.number)) }));
});

const selected = computed(() => pool.value.filter((o) => sel.value.has(o.number)));
const selWhs = computed(() => new Set(selected.value.map((o) => o.warehouse.code)));
const selItems = computed(() => selected.value.reduce((s, o) => s + itemsOf(o), 0));
const canCreate = computed(() => auth.can('consol.manage') && selected.value.length >= 2 && selWhs.value.size === 1);

function toggle(n) { const x = new Set(sel.value); if (x.has(n)) x.delete(n); else x.add(n); sel.value = x; }
function toggleGroup(g) { const x = new Set(sel.value); g.rows.forEach((r) => (g.allOn ? x.delete(r.number) : x.add(r.number))); sel.value = x; }

async function create() {
  const rule = GROUPS.find((g) => g.k === group.value).rule;
  const n = selected.value.length;
  const r = await act.run(() => api.postIdempotent('/sales/consolidations', { orderNumbers: selected.value.map((o) => o.number), rule }), {
    success: (oc) => t(`أُنشئت دفعة التجميع ${oc.number} من ${n} طلبات`, `Batch ${oc.number} created from ${n} orders`),
    invalidate: ['sales'],
  });
  if (r) { sel.value = new Set(); openOc.value = r.number; router.replace({ query: { ...route.query, pick: undefined, q: r.number } }); }
}
function closeBatch() { openOc.value = null; router.replace({ query: { ...route.query, q: undefined } }); }

// batches
const list = useList('/sales/consolidations', () => ({ ...wh.whParams, status: ocStatus.value || undefined, page: page.value, pageSize: 20 }));
const statusTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...OC_STEPS.map((k) => ({ k, label: { ar: OC_LABELS[k].ar, en: OC_LABELS[k].en } }))];
const columns = [
  { key: 'number', header: { ar: 'الدفعة', en: 'Batch' }, width: '120px', kind: 'id' },
  { key: 'createdAt', header: { ar: 'التاريخ', en: 'Date' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.createdAt) },
  { key: 'wh', header: { ar: 'المستودع', en: 'Warehouse' }, width: '90px' },
  { key: 'n', header: { ar: 'طلبات', en: 'Orders' }, width: '60px', kind: 'num', value: (r) => r.orders.length },
  { key: 'cust', header: { ar: 'عملاء', en: 'Customers' }, width: '60px', kind: 'num' },
  { key: 'kg', header: { ar: 'الكمية', en: 'Qty' }, width: '80px', kind: 'num' },
  { key: 'rule', header: { ar: 'قاعدة التجميع', en: 'Rule' }, width: 'minmax(140px,1.2fr)' },
  { key: 'trip', header: { ar: 'الرحلة', en: 'Trip' }, width: '120px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
];
</script>

<template>
  <div>
    <PageHead :sub="t('تجميع أوامر البيع المؤكدة حسب المنطقة / العميل / تاريخ التسليم — ثم تجهيز ← تعبئة ← رحلة', 'Group confirmed sales orders by zone / customer / delivery date — then pick → pack → trip')" />

    <div class="grid grid-cols-[minmax(0,1.6fr)_minmax(260px,1fr)] items-start gap-3.5 max-[860px]:grid-cols-1">
      <!-- pool -->
      <div class="min-w-0">
        <div class="row wrap">
          <div class="text-[12.5px] font-extrabold">{{ t('أوامر بيع مؤكدة — جاهزة للتجميع', 'Confirmed sales orders — ready to consolidate') }}</div>
          <div class="grow" />
          <Tabs v-model="group" :tabs="groupTabs" variant="pillPurple" class="!mb-0" />
        </div>
        <ErrorBanner class="mt-2.5" :error="poolError" :closable="false" />
        <div v-if="poolLoading && pool.length === 0" class="skel mt-2.5 h-[120px]" />
        <div v-if="!poolLoading && pool.length === 0" class="empty dashed mt-2.5 !p-[34px]">{{ t(wh.wh === 'all' ? 'لا أوامر بيع مؤكدة بانتظار التجميع' : `لا أوامر بيع مؤكدة بانتظار التجميع في ${wh.wh}`, 'No confirmed orders awaiting consolidation') }}</div>
        <div class="col mt-2.5 !gap-2.5">
          <div v-for="g in groups" :key="g.key" class="card !rounded-2xl">
            <div class="row !gap-2.5 bg-soft px-4 py-[11px]">
              <div class="text-[11.5px] font-extrabold text-violet">{{ g.key }}</div>
              <div class="text-[9.5px] text-muted">{{ t(`${g.rows.length} طلبات`, `${g.rows.length} orders`) }} · {{ fmtNum(g.kg) }} {{ t('كجم', 'kg') }}</div>
              <div class="grow" />
              <div class="cursor-pointer text-[9px] font-extrabold text-brand-dark" @click="toggleGroup(g)">{{ g.allOn ? t('إلغاء تحديد المجموعة', 'Unselect group') : t('تحديد المجموعة', 'Select group') }}</div>
            </div>
            <div v-for="r in g.rows" :key="r.number" class="row cursor-pointer !gap-2.5 border-t border-line-2 px-4 py-[9px]" :class="{ 'bg-[#FAF7FF]': sel.has(r.number) }" @click="toggle(r.number)">
              <div class="flex h-[18px] w-[18px] flex-none items-center justify-center rounded-md border-[1.5px]" :class="sel.has(r.number) ? 'border-violet bg-violet' : 'border-[#DCD2EE] bg-white'">
                <svg v-if="sel.has(r.number)" width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L20 7" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </div>
              <div class="num min-w-[110px] text-[10px] text-violet" @click.stop="openSo = r.number">{{ r.number }}</div>
              <div class="grow">
                <div class="text-[11px] font-extrabold">{{ custName(r.customer) }}</div>
                <div class="text-[8.5px] text-faint">{{ r.customer.zone || '—' }} · {{ fmtDateOnly(r.dueDate) }}{{ r.window ? ` · ${r.window}` : '' }} · {{ r.warehouse.code }}</div>
              </div>
              <div class="num text-[9.5px] text-muted">{{ fmtNum(r.kg) }} {{ t('كجم', 'kg') }}</div>
              <div class="num text-[10px]">{{ fmtNum(itemsOf(r)) }}</div>
              <Chip :map="SO_LABELS" :k="r.status" small />
              <div v-if="r.priority === 'high'" class="rounded-full bg-bad px-[7px] py-0.5 text-[8px] font-extrabold text-white">!</div>
            </div>
          </div>
        </div>
      </div>

      <!-- selection summary -->
      <div class="sticky top-[76px] max-[860px]:static">
        <div class="rounded-[18px] bg-night px-[18px] py-4 text-white">
          <div class="grid grid-cols-3 gap-2">
            <div class="text-center"><div class="num text-[22px] text-brand">{{ selected.length }}</div><div class="text-[8.5px] font-extrabold text-[#8b90a5]">{{ t('طلبات', 'Orders') }}</div></div>
            <div class="text-center"><div class="num text-[22px]">{{ custsOf(selected) }}</div><div class="text-[8.5px] font-extrabold text-[#8b90a5]">{{ t('عملاء', 'Customers') }}</div></div>
            <div class="text-center"><div class="num text-[22px]">{{ fmtNum(selItems) }}</div><div class="text-[8.5px] font-extrabold text-[#8b90a5]">{{ t('قطعة', 'Items') }}</div></div>
          </div>
          <div class="row mt-3 !gap-3 text-[10px] text-[#c9cde0]">
            <div><span class="num text-white">{{ fmtNum(sumOf(selected, 'kg')) }}</span> {{ t('كجم', 'kg') }}</div>
            <div><span class="num text-white">{{ fmtNum(sumOf(selected, 'cbm'), 1) }}</span> {{ t('م³', 'm³') }}</div>
          </div>
          <div class="mt-1.5 text-[9.5px] text-[#8b90a5]">{{ t('المناطق', 'Zones') }}: <span class="text-[#c9cde0]">{{ zonesOf(selected).join('، ') || '—' }}</span></div>
          <div v-if="selWhs.size > 1" class="mt-1.5 text-[9.5px] text-[#f3b8b8]">{{ t('الطلبات من مستودعات مختلفة — التجميع داخل مستودع واحد', 'Orders from different warehouses — consolidate within one warehouse') }}</div>
          <div v-if="!auth.can('consol.manage') && selected.length >= 2" class="mt-1.5 text-[9.5px] text-[#8b90a5]">{{ t('صلاحية غير كافية لإنشاء دفعة', 'Insufficient permission to create a batch') }}</div>
          <Btn v-if="canCreate" tone="primary" block class="mt-[13px] !h-11 !rounded-xl !text-[12px]" :loading="act.pending.value" :label="{ ar: 'إنشاء دفعة تجميع', en: 'Create consolidation batch' }" @click="create" />
          <div v-if="selected.length > 0" class="mt-2 cursor-pointer text-center text-[9.5px] text-[#8b90a5]" @click="sel = new Set()">{{ t('مسح التحديد', 'Clear selection') }}</div>
          <div v-if="selected.length < 2" class="mt-2.5 text-[9px] leading-[1.7] text-[#8b90a5]">{{ t('اختر طلبين على الأقل من نفس المستودع لإنشاء دفعة تجميع.', 'Select at least two orders from the same warehouse to create a batch.') }}</div>
        </div>
      </div>
    </div>
    <ErrorBanner class="mt-2.5" :error="act.error.value" @close="act.clearError()" />

    <!-- batches -->
    <div class="row wrap mt-[18px]">
      <div class="text-[12.5px] font-extrabold">{{ t('دفعات التجميع', 'Consolidation batches') }}</div>
      <div class="grow" />
      <Tabs v-model="ocStatus" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="page = 1" />
    </div>
    <ErrorBanner class="mt-2" :error="list.error.value" :closable="false" />
    <div class="card mt-2">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="900" :row-key="(r) => r.number" :selected-key="openOc" :empty-text="{ ar: 'لا دفعات تجميع بعد', en: 'No batches yet' }"
                 @page="page = $event" @row-click="(r) => (openOc === r.number ? closeBatch() : (openOc = r.number))">
        <template #cell-wh="{ row }"><span class="text-[10px] font-bold text-sec">{{ row.warehouse.code }}</span></template>
        <template #cell-cust="{ row }"><span class="muted">{{ custsOf(row.orders) }}</span></template>
        <template #cell-kg="{ row }"><span class="muted">{{ fmtNum(sumOf(row.orders, 'kg')) }} {{ t('كجم', 'kg') }}</span></template>
        <template #cell-rule="{ row }"><span class="text-[9.5px] text-sec">{{ row.rule || '—' }}</span></template>
        <template #cell-trip="{ row }">
          <RouterLink v-if="row.trips?.length" :to="`/trip/${encodeURIComponent(row.trips[0].number)}`" class="num text-[9.5px] !text-brand-dark" @click.stop>{{ row.trips[0].number }}</RouterLink>
          <span v-else class="faint">—</span>
        </template>
        <template #cell-status="{ row }"><Chip :map="OC_LABELS" :k="row.status" /></template>
      </DataTable>
    </div>

    <BatchDetail v-if="openOc" :key="openOc" :number="openOc" :pool="pool" @close="closeBatch" @open-so="(n) => (openSo = n)" />
    <SoDrawer :number="openSo" @close="openSo = null" />
  </div>
</template>
