<script setup>
// Odoo tab → stock: what Odoo holds per warehouse and product, with its lots and the nearest expiry date — Odoo's own
// figures, read from the copy (app/Integration/Odoo/OdooStockView.php). It is NOT the OPS inventory and changes
// nothing in it. GET /integration/odoo/stock?warehouse=&expiry=expired|soon&q=&sort=&order=&page=
// A row opens the Odoo record of the product (`rec=product.product:<id>`). Filters, sort and page live in the query
// string:  /itower?tab=odoo&view=stock&expiry=expired
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, Tabs, TextInput } from '@/components';
import { t } from '@/i18n';
import { TONE, agoAt, fmtAt, fmtDay, useOdooNav, useSearchParam } from './odoo';

const nav = useOdooNav();
const { page } = nav;
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);

const warehouse = computed(() => (/^\d{1,9}$/.test(nav.get('warehouse')) ? nav.get('warehouse') : ''));
const EXPIRY = { expired: { ar: 'منتهية الصلاحية', en: 'Expired' }, soon: { ar: 'تنتهي خلال 30 يومًا', en: 'Expiring within 30 days' } };
const expiry = computed(() => (Object.keys(EXPIRY).includes(nav.get('expiry')) ? nav.get('expiry') : ''));
const SORTS = ['code', 'name', 'qty', 'reserved', 'available', 'lots', 'nearestExpiry'];
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'asc' ? 'asc' : 'desc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });

// the server rebuilds the picture when a run changed the stock models; a minute is how often a run happens
const list = useList('/integration/odoo/stock', () => ({
  warehouse: warehouse.value, expiry: expiry.value, q: nav.get('q'), sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 30,
}), { refetchInterval: 60000 });
const d = computed(() => list.data.value || null);
const totals = computed(() => d.value?.totals || null);
const warehouses = computed(() => d.value?.warehouses || []);
const filtered = computed(() => !!(warehouse.value || expiry.value || nav.get('q')));

/** A quantity as Odoo holds it: whole when it is whole, otherwise up to two decimals. */
const qty = (v) => (v == null ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 }));
const today = new Date().toISOString().slice(0, 10);
const soonDay = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
/** The colour of an expiry date: passed, within a month, or neither. */
const expiryTone = (day) => (!day ? null : day < today ? TONE.bad : day <= soonDay ? TONE.warn : null);

const expiryTabs = [{ k: '', label: { ar: 'كل الأرصدة', en: 'All stock' } }, ...Object.entries(EXPIRY).map(([k, label]) => ({ k, label }))];
const columns = [
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '92px', sortable: true },
  { key: 'name', header: { ar: 'الصنف في أودو', en: 'Product in Odoo' }, width: 'minmax(230px,2fr)', sortable: true },
  { key: 'warehouse', header: { ar: 'المستودع', en: 'Warehouse' }, width: '132px' },
  { key: 'qty', header: { ar: 'الكمية', en: 'On hand' }, width: '84px', sortable: true },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Reserved' }, width: '74px', sortable: true },
  { key: 'available', header: { ar: 'المتاح', en: 'Available' }, width: '84px', sortable: true },
  { key: 'lots', header: { ar: 'تشغيلات', en: 'Lots' }, width: '70px', sortable: true },
  { key: 'nearestExpiry', header: { ar: 'أقرب انتهاء', en: 'Nearest expiry' }, width: '150px', sortable: true },
  { key: 'ops', header: { ar: 'في نظام العمليات', en: 'In OPS' }, width: '112px', bare: true },
];
</script>

<template>
  <ErrorBanner :error="list.error.value" :closable="false" class="!mb-0" />
  <div v-if="list.isLoading.value" class="skel h-[150px]" />

  <div v-else-if="d && !d.ready" class="card p-[18px]">
    <div class="text-[13px] font-extrabold">{{ t('أرصدة أودو تظهر بعد اكتمال نسخ نماذج المخزون', 'Odoo stock appears once the stock models are copied') }}</div>
    <p class="m-0 mt-2 max-w-[760px] text-[11.5px] leading-[1.9] text-sec">
      {{ t('هذه الشاشة تُبنى من خمسة نماذج في أودو: المستودعات، المواقع، التشغيلات، الأرصدة والأصناف. تُعرض حين تكتمل النسخة الأولى لها كلها، حتى لا تظهر صورة ناقصة. تابع التقدّم في «النماذج».', 'This view is built from five Odoo models: warehouses, locations, lots, quants and products. It is shown once the first copy of all of them is complete, so that no partial picture appears. Follow the progress under “Models”.') }}
    </p>
    <Btn class="mt-3" tone="soft" size="sm" :label="{ ar: 'فتح النماذج', en: 'Open models' }" @click="nav.go('models', { state: 'copying' })" />
  </div>

  <template v-else-if="d">
    <KpiGrid class="!mb-0">
      <KpiCard :value="qty(totals.qty)" :label="{ ar: 'إجمالي الكمية في أودو', en: 'Total on hand in Odoo' }">
        <template #sub>{{ t('أصناف لها رصيد', 'products with stock') }} <b class="num">{{ qty(totals.products) }}</b></template>
      </KpiCard>
      <KpiCard v-for="w in warehouses" :key="w.id" clickable :active="warehouse === String(w.id)" :value="qty(w.qty)" :label="w.name"
               @click="nav.setFilter('warehouse', warehouse === String(w.id) ? null : w.id)">
        <template #sub>{{ t('أصناف', 'products') }} <b class="num">{{ qty(w.products) }}</b><template v-if="w.code"> · <span class="num ltr">{{ w.code }}</span></template></template>
      </KpiCard>
      <KpiCard clickable :active="expiry === 'expired'" :value="qty(totals.expiredQty)" :color="totals.expiredQty > 0 ? TONE.bad.fg : undefined" :label="{ ar: 'كمية منتهية الصلاحية', en: 'Expired quantity' }"
               @click="nav.setFilter('expiry', expiry === 'expired' ? null : 'expired')">
        <template #sub>{{ t('ما زالت مسجّلة رصيدًا في أودو', 'still on hand in Odoo') }}</template>
      </KpiCard>
      <KpiCard clickable :active="expiry === 'soon'" :value="qty(totals.soonQty)" :color="totals.soonQty > 0 ? TONE.warn.fg : undefined" :label="{ ar: 'تنتهي خلال 30 يومًا', en: 'Expiring within 30 days' }"
               @click="nav.setFilter('expiry', expiry === 'soon' ? null : 'soon')">
        <template #sub>{{ t('تُصرف أولًا', 'to be issued first') }}</template>
      </KpiCard>
      <KpiCard :value="qty(totals.notInOps)" :color="totals.notInOps > 0 ? TONE.warn.fg : undefined" :label="{ ar: 'أصناف برصيد وليست في النظام', en: 'Stocked products not in OPS' }">
        <template #sub>
          <template v-if="totals.negative">{{ t('أرصدة سالبة', 'negative balances') }} <b class="num text-bad">{{ qty(totals.negative) }}</b></template>
          <template v-else>{{ t('لا أرصدة سالبة', 'no negative balance') }}</template>
        </template>
      </KpiCard>
    </KpiGrid>

    <div class="row wrap">
      <TextInput small type="search" class="w-[240px]" :model-value="qText" :placeholder="{ ar: 'بحث بالرمز أو باسم الصنف…', en: 'Search by code or product name…' }" @update:model-value="typeQ" @enter="applyQ" />
      <Tabs :model-value="expiry" :tabs="expiryTabs" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('expiry', k)" />
      <Btn v-if="filtered || sort" tone="ghost" size="sm" :label="{ ar: 'إزالة التصفية', en: 'Clear filters' }" @click="nav.go('stock')" />
      <span class="grow" />
      <span v-if="d.asOf" class="text-[10px] text-muted" :title="fmtAt(d.asOf)">{{ t('آخر قراءة من أودو', 'Last read from Odoo') }} <span class="num-mixed">{{ agoAt(d.asOf) }}</span></span>
    </div>

    <div class="card">
      <DataTable :columns="columns" :paged="d" :loading="list.isFetching.value" :row-key="(r) => `${r.warehouseId}:${r.productId}`" :sort="sort" server-sort dense
                 :empty-text="filtered ? { ar: 'لا أرصدة مطابقة.', en: 'No matching stock.' } : { ar: 'لا رصيد في مستودعات أودو.', en: 'No stock in the Odoo warehouses.' }"
                 @sort="setSort" @page="nav.setPage" @row-click="(r) => nav.openRecord('product.product', r.productId)">
        <template #cell-code="{ row }"><span v-if="row.code" class="cell-id ltr">{{ row.code }}</span><span v-else class="text-[10px] text-warn">{{ t('بلا رمز', 'No code') }}</span></template>
        <template #cell-name="{ row }"><div class="truncate text-[11px] font-bold text-ink [text-align:match-parent]" dir="auto" :title="row.name">{{ row.name || '—' }}</div></template>
        <template #cell-warehouse="{ row }"><span class="text-[10.5px]" dir="auto">{{ row.warehouse }}</span></template>
        <template #cell-qty="{ row }"><span class="cell-num" :class="{ 'text-bad': row.qty < 0 }">{{ qty(row.qty) }}</span></template>
        <template #cell-reserved="{ row }"><span class="cell-num" :class="{ 'text-faint': !row.reserved }">{{ qty(row.reserved) }}</span></template>
        <template #cell-available="{ row }"><span class="cell-num font-extrabold" :class="{ 'text-bad': row.available < 0 }">{{ qty(row.available) }}</span></template>
        <template #cell-lots="{ row }"><span class="cell-num" :class="{ 'text-faint': !row.lots }">{{ qty(row.lots) }}</span></template>
        <template #cell-nearestExpiry="{ row }">
          <span v-if="!row.nearestExpiry" class="text-faint">—</span>
          <div v-else>
            <span class="num ltr text-[10.5px] font-bold" :style="expiryTone(row.nearestExpiry) ? { color: expiryTone(row.nearestExpiry).fg } : null">{{ fmtDay(row.nearestExpiry) }}</span>
            <div v-if="row.expiredQty > 0" class="text-[9px] font-bold text-bad">{{ t('منتهٍ', 'expired') }} <span class="num">{{ qty(row.expiredQty) }}</span></div>
            <div v-else-if="row.soonQty > 0" class="text-[9px] font-bold text-warn">{{ t('خلال 30 يومًا', 'within 30 days') }} <span class="num">{{ qty(row.soonQty) }}</span></div>
          </div>
        </template>
        <template #cell-ops="{ row }">
          <RouterLink v-if="row.inOps" :to="{ path: '/products', query: { sku: row.code } }" class="inline-flex" :title="t('فتح الصنف في نظام العمليات', 'Open the product in OPS')" @click.stop>
            <Chip small :label="{ ar: 'موجود', en: 'In OPS' }" :fg="TONE.ok.fg" :bg="TONE.ok.bg" />
          </RouterLink>
          <Chip v-else small :label="{ ar: 'غير موجود', en: 'Missing' }" :fg="TONE.warn.fg" :bg="TONE.warn.bg" />
        </template>
      </DataTable>
    </div>
    <div class="hint !mt-0">
      {{ t('هذه أرقام أودو كما هي: الكمية في المواقع الداخلية لكل مستودع، مجموعة لكل صنف، مع عدد تشغيلاته وأقرب تاريخ انتهاء. ما خرج إلى العملاء أو سُوّي جرديًا ليس رصيدًا ولا يُحسب. أودو يسجّل المخزون على مستوى المستودع والتشغيلة بلا مواقع رفوف. هذه الشاشة للاطلاع والمطابقة فقط: لا تغيّر مخزون نظام العمليات ولا تكتب شيئًا في أودو.', 'These are Odoo’s own figures: the quantity in the internal locations of each warehouse, added up per product, with its number of lots and the nearest expiry date. What left to customers or was written off in a count is not stock and is not counted. Odoo keeps stock per warehouse and lot, with no shelf positions. This view is for looking and reconciling only: it does not change the OPS inventory and writes nothing to Odoo.') }}
    </div>
  </template>
</template>
