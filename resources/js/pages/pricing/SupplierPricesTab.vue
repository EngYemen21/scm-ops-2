<script setup>
// «أسعار الموردين» — what each supplier charges for each product, from a quantity and inside a period:
//   /pricing?tab=suppliers&supplier=SUP-0012          one supplier's price list       &product=P00325   one product's suppliers
//   /pricing?tab=suppliers&view=suppliers             the suppliers that have prices  &period=expired · &state=pending
// GET /pricing/supplier-prices · /pricing/supplier-prices/suppliers. They are purchase costs: the tab is offered only to
// those who may see cost (the server answers 403 PRICING_COST_HIDDEN otherwise); adding, changing and archiving need
// supplierprice.manage. A price that came from Odoo is read-only while the section follows Odoo.
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtAgo, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import PickRecord from './PickRecord.vue';
import SupplierPriceForm from './SupplierPriceForm.vue';
import { ARCHIVED_CHIP, OWNED_CHIP, PERIOD, WAITING_CHIP, nameOf, periodText, plain, price, usePricingLookups, usePricingNav, useSearchParam } from './pricing';

const auth = useAuth();
const nav = usePricingNav();
const { page } = nav;
const { follows } = usePricingLookups();
const { text, type, apply } = useSearchParam(nav);
const canManage = computed(() => auth.can('supplierprice.manage'));

const VIEWS = [{ k: 'prices', label: { ar: 'الأسعار', en: 'Prices' } }, { k: 'suppliers', label: { ar: 'حسب المورد', en: 'By supplier' } }];
const STATES = [{ k: 'active', label: { ar: 'الفعّالة', en: 'Active' } }, { k: 'pending', label: { ar: 'بانتظار الربط', en: 'Waiting' } }, { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } }];
const PERIODS = Object.entries(PERIOD).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
const ORIGIN = [{ v: 'ops', l: { ar: 'أُدخلت هنا', en: 'Entered in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const view = computed(() => (nav.get('view') === 'suppliers' ? 'suppliers' : 'prices'));
const state = computed(() => (STATES.some((s) => s.k === nav.get('state')) ? nav.get('state') : 'active'));
const period = computed(() => (PERIOD[nav.get('period')] ? nav.get('period') : ''));

// ── prices
const SORTS = ['sku', 'supplier', 'price', 'minQty', 'leadDays', 'validTo', 'updatedAt'];
const sort = computed(() => nav.sortOf(SORTS));
const list = useList(() => (view.value === 'prices' ? '/pricing/supplier-prices' : null), () => ({
  q: nav.get('q'), supplier: nav.get('supplier') || undefined, product: nav.get('product') || undefined, state: state.value, period: period.value || undefined, source: nav.get('source') || undefined,
  sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const filtered = computed(() => !!nav.get('q') || !!nav.get('supplier') || !!nav.get('product') || !!period.value || !!nav.get('source') || state.value !== 'active');
const columns = computed(() => [
  { key: 'sku', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(190px,1.6fr)', sortable: true },
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(150px,1.2fr)', sortable: true },
  { key: 'price', header: { ar: 'السعر', en: 'Price' }, width: '92px', kind: 'num', sortable: true },
  { key: 'minQty', header: { ar: 'من كمية', en: 'From qty' }, width: '68px', kind: 'num', sortable: true, value: (r) => plain(r.minQty) },
  { key: 'leadDays', header: { ar: 'المهلة', en: 'Lead' }, width: '62px', kind: 'num', sortable: true, value: (r) => (r.leadDays != null ? `${r.leadDays} ${t('ي', 'd')}` : '—') },
  { key: 'validTo', header: { ar: 'المدة', en: 'Period' }, width: 'minmax(120px,0.9fr)', sortable: true },
  { key: 'flags', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
  { key: 'actions', header: '', width: '128px', bare: true, hidden: !canManage.value },
]);

// ── by supplier
const SUPPLIER_SORTS = ['code', 'nameAr', 'prices', 'products', 'updatedAt'];
const supplierSort = computed(() => nav.sortOf(SUPPLIER_SORTS));
const suppliers = useList(() => (view.value === 'suppliers' ? '/pricing/supplier-prices/suppliers' : null), () => ({ q: nav.get('q'), sort: supplierSort.value?.key, order: supplierSort.value?.order, page: page.value, pageSize: 25 }));
const supplierCols = [
  { key: 'nameAr', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(220px,2fr)', sortable: true },
  { key: 'prices', header: { ar: 'الأسعار', en: 'Prices' }, width: '84px', kind: 'num', sortable: true, value: (s) => fmtNum(s.prices) },
  { key: 'products', header: { ar: 'المنتجات', en: 'Products' }, width: '84px', kind: 'num', sortable: true, value: (s) => fmtNum(s.products) },
  { key: 'expired', header: { ar: 'منتهية المدة', en: 'Expired' }, width: '92px', kind: 'num' },
  { key: 'leadDays', header: { ar: 'مهلة المورد', en: 'Supplier lead' }, width: '92px', kind: 'num', value: (s) => (s.leadDays != null ? `${s.leadDays} ${t('ي', 'd')}` : '—') },
  { key: 'updatedAt', header: { ar: 'آخر تعديل', en: 'Last change' }, width: '110px', sortable: true },
];

// ── add / change / archive
const act = useAction({ invalidate: ['pricing'] });
/** `{}` = a new price (with the filters as its start), a row = that price being changed, null = closed. */
const form = ref(null);
async function setActive(row, verb) {
  if (verb === 'archive' && !(await confirm({ title: { ar: 'أرشفة سعر المورد؟', en: 'Archive the supplier price?' }, sub: `${nameOf(row.supplier)} · ${row.product?.sku || ''} · ${price(row.netPrice)}`, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } }))) return;
  await act.run(() => api.postIdempotent(`/pricing/supplier-prices/${encodeURIComponent(row.id)}/${verb}`), { success: verb === 'archive' ? { ar: 'أُرشف السعر — يبقى مقروءًا', en: 'Price archived — it stays readable' } : { ar: 'استُعيد السعر ✓', en: 'Price restored ✓' } });
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs :model-value="view" :tabs="VIEWS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.go('suppliers', k === 'suppliers' ? { view: k } : {})" />
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="view === 'prices' ? { ar: 'بحث بالمنتج، المورد أو رمز المورد للمنتج…', en: 'Search product, supplier or the supplier\'s own code…' } : { ar: 'بحث باسم المورد أو رمزه…', en: 'Search supplier name or code…' }"
               @update:model-value="type" @enter="apply" />
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="primary" :label="{ ar: '+ سعر مورد', en: '+ Supplier price' }" @click="form = {}" />
  </div>

  <!-- ── prices -->
  <template v-if="view === 'prices'">
    <div class="row wrap mb-2.5 items-end">
      <PickRecord kind="supplier" small any-state class="w-[230px]" :model-value="nav.get('supplier')" @update:model-value="(v) => nav.setFilter('supplier', v)" />
      <PickRecord kind="product" small any-state class="w-[230px]" :model-value="nav.get('product')" @update:model-value="(v) => nav.setFilter('product', v)" />
      <SelectInput small class="w-[130px]" :model-value="period" :options="PERIODS" :placeholder="{ ar: 'أي مدة', en: 'Any period' }" @update:model-value="(v) => nav.setFilter('period', v)" />
      <SelectInput v-if="follows" small class="w-[140px]" :model-value="nav.get('source')" :options="ORIGIN" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
      <Tabs :model-value="state" :tabs="STATES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('state', k === 'active' ? null : k)" />
      <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="nav.go('suppliers')" />
    </div>
    <ErrorBanner :error="list.error.value || act.error.value" @close="act.clearError()" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
                 :empty-text="filtered ? { ar: 'لا أسعار موردين تطابق البحث والفلاتر.', en: 'No supplier price matches the search and filters.' } : { ar: 'لا أسعار موردين بعد — أضف أول سعر من «+ سعر مورد».', en: 'No supplier price yet — add the first one with “+ Supplier price”.' }"
                 @sort="nav.setSort" @page="nav.setPage">
        <template #cell-sku="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ row.product ? nameOf(row.product) : row.pendingLabel || '—' }}</div>
            <div class="cell-sub">{{ row.product?.sku }}<template v-if="row.supplierSku"> · {{ t('عند المورد', 'supplier code') }} {{ row.supplierSku }}</template><template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template></div>
          </div>
        </template>
        <template #cell-supplier="{ row }">
          <div class="min-w-0">
            <div class="truncate text-[10.5px] font-bold text-ink" dir="auto">{{ row.supplier ? nameOf(row.supplier) : row.pendingLabel || '—' }}</div>
            <div class="cell-sub">{{ row.supplier?.code }}</div>
          </div>
        </template>
        <template #cell-price="{ row }">
          <span class="font-extrabold">{{ price(row.netPrice) }}</span>
          <div v-if="row.discountPct" class="text-[8.5px] text-muted">{{ price(row.price) }} − {{ plain(row.discountPct) }}%</div>
        </template>
        <template #cell-validTo="{ row }">
          <span class="text-[9.5px] text-sec">{{ periodText(row.validFrom, row.validTo) }}</span>
          <Chip v-if="row.period !== 'valid'" small class="ms-1" :map="PERIOD" :k="row.period" />
        </template>
        <template #cell-flags="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1">
            <Chip v-if="row.pending" small :label="WAITING_CHIP" :fg="WAITING_CHIP.fg" :bg="WAITING_CHIP.bg" :title="row.pendingLabel || ''" />
            <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
            <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
            <span v-if="!row.pending && row.active && !row.owned" class="text-faint">—</span>
          </span>
        </template>
        <template #cell-actions="{ row }">
          <span v-if="row.owned" class="text-[9px] text-muted">{{ t('يُعدَّل في أودو', 'Changed in Odoo') }}</span>
          <span v-else class="inline-flex gap-1">
            <Btn v-if="row.active" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="form = row" />
            <Btn v-if="row.active" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click.stop="setActive(row, 'archive')" />
            <Btn v-else size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click.stop="setActive(row, 'restore')" />
          </span>
        </template>
      </DataTable>
    </div>
  </template>

  <!-- ── by supplier -->
  <template v-else>
    <ErrorBanner :error="suppliers.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="supplierCols" :paged="suppliers.data.value" :loading="suppliers.isFetching.value" :row-key="(s) => s.id" :sort="supplierSort" server-sort
                 :empty-text="{ ar: 'لا مورد له أسعار بعد.', en: 'No supplier has prices yet.' }" @sort="nav.setSort" @page="nav.setPage" @row-click="(s) => nav.go('suppliers', { supplier: s.code })">
        <template #cell-nameAr="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
            <div class="cell-sub">{{ row.code }}<template v-if="!row.active || row.status === 'suspended'"> · {{ t('موقوف', 'suspended') }}</template><template v-else-if="row.status === 'review'"> · {{ t('تحت المراجعة', 'under review') }}</template></div>
          </div>
        </template>
        <template #cell-expired="{ row }"><span :class="row.expired ? 'font-extrabold text-bad' : 'text-faint'">{{ row.expired ? fmtNum(row.expired) : '—' }}</span></template>
        <template #cell-updatedAt="{ row }"><span class="num-mixed text-[9.5px] text-muted">{{ row.lastAt ? fmtAgo(row.lastAt) : '—' }}</span></template>
      </DataTable>
    </div>
  </template>
  <div class="hint">
    {{ t('«أفضل سعر مورد» في فحص السعر يختار أقل سعر صافٍ بين الأسعار السارية في التاريخ والتي بلغت الكميةُ حدَّها الأدنى، ولا يختار موردًا موقوفًا أو تحت المراجعة. سعران فعّالان للمورد نفسه والمنتج نفسه والكمية نفسها بمدتين متقاطعتين مرفوضان.',
      'The "best supplier price" of the price check takes the lowest net price among the prices that hold on the day and whose minimum quantity is reached, and never a suspended supplier or one under review. Two active prices of one supplier, product and quantity with overlapping periods are refused.') }}
  </div>

  <SupplierPriceForm :open="!!form" :row="form && form.id ? form : null" :supplier="nav.get('supplier')" :product="nav.get('product')" @close="form = null" />
</template>
