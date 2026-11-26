<script setup>
// «الأسعار المعلنة» — the list price of each product: what a discount rule starts from, and what a customer pays for a
// product no rule of its list covers.   /pricing?tab=base&has=no        products that have none yet
// GET /pricing/list-prices → { items, total, …, costVisible, follows }; the cost and the margin are in the rows only for
// those who may see what the company pays. Setting, pasting and clearing need pricelist.manage. A price that came
// from Odoo is Odoo's while the section follows Odoo (the product's sales price is changed there).
import { computed, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, SelectInput, Tabs, TextInput } from '@/components';
import { fmtAgo, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import PasteDrawer from './PasteDrawer.vue';
import { OWNED_CHIP, nameOf, plain, price, usePricingLookups, usePricingNav, useSearchParam } from './pricing';

const auth = useAuth();
const nav = usePricingNav();
const { page } = nav;
const { follows } = usePricingLookups();
const { text, type, apply } = useSearchParam(nav);
const canManage = computed(() => auth.can('pricelist.manage'));

const HAS = [{ k: '', label: { ar: 'كل المنتجات', en: 'Every product' } }, { k: 'yes', label: { ar: 'لها سعر معلن', en: 'With a list price' } }, { k: 'no', label: { ar: 'بلا سعر معلن', en: 'Without one' } }];
const ORIGIN = [{ v: 'ops', l: { ar: 'سُعِّرت هنا', en: 'Priced in OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const SORTS = ['sku', 'nameAr', 'price', 'updatedAt'];
const has = computed(() => (['yes', 'no'].includes(nav.get('has')) ? nav.get('has') : ''));
const sort = computed(() => nav.sortOf(SORTS));
const list = useList('/pricing/list-prices', () => ({
  q: nav.get('q'), has: has.value || undefined, source: nav.get('source') || undefined, sort: sort.value?.key, order: sort.value?.order, page: page.value, pageSize: 25,
}));
const costVisible = computed(() => !!list.data.value?.costVisible);

const columns = computed(() => [
  { key: 'sku', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(220px,2fr)', sortable: true },
  { key: 'category', header: { ar: 'الفئة', en: 'Category' }, width: 'minmax(100px,0.8fr)', value: (r) => (r.category ? nameOf(r.category) : '—') },
  { key: 'price', header: { ar: 'السعر المعلن', en: 'List price' }, width: '110px', kind: 'num', sortable: true },
  { key: 'cost', header: { ar: 'التكلفة', en: 'Cost' }, width: '90px', kind: 'num', hidden: !costVisible.value, value: (r) => price(r.cost) },
  { key: 'marginPct', header: { ar: 'الهامش %', en: 'Margin %' }, width: '80px', kind: 'num', hidden: !costVisible.value },
  { key: 'updatedAt', header: { ar: 'آخر تعديل', en: 'Changed' }, width: '110px', sortable: true },
  { key: 'actions', header: '', width: '140px', bare: true, hidden: !canManage.value },
]);

// ── set / clear / paste
const act = useAction({ invalidate: ['pricing'] });
/** The product whose list price is being typed. */
const setting = ref(null);
const pasting = ref(false);
const fields = computed(() => [
  { k: 'price', label: { ar: `السعر المعلن (${setting.value?.listPrice?.currency || 'SAR'})`, en: `List price (${setting.value?.listPrice?.currency || 'SAR'})` }, type: 'num', required: true, min: 0, full: true,
    validate: (v) => (num(v) > 0 ? null : t('أكبر من صفر', 'Greater than zero')), hint: { ar: 'سعر الوحدة قبل الضريبة', en: 'Unit price before VAT' } },
]);
const save = (v) => api.postIdempotent('/pricing/list-prices', { product: setting.value.sku, price: v.price });
async function clear(row) {
  if (!(await confirm({ title: { ar: `حذف السعر المعلن لـ ${nameOf(row)}؟`, en: `Remove the list price of ${nameOf(row)}?` }, sub: { ar: 'قواعد الخصم التي تبدأ من السعر المعلن لن تسعّر هذا المنتج، والعميل الذي لا قاعدة له يبقى بلا سعر.', en: 'Discount rules that start from the list price will not price this product, and a customer without a rule gets no price.' }, tone: 'danger' }))) return;
  await act.run(() => api.postIdempotent(`/pricing/list-prices/${encodeURIComponent(row.sku)}/clear`), { success: { ar: 'حُذف السعر المعلن', en: 'List price removed' } });
}
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small scan type="search" class="w-[250px]" :model-value="text" :placeholder="{ ar: 'بحث بالرمز أو اسم المنتج…', en: 'Search SKU or product name…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="has" :tabs="HAS" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('has', k)" />
    <SelectInput v-if="follows" small class="w-[140px]" :model-value="nav.get('source')" :options="ORIGIN" :placeholder="{ ar: 'أي مصدر', en: 'Any origin' }" @update:model-value="(v) => nav.setFilter('source', v)" />
    <span class="grow" />
    <Btn v-if="canManage" size="sm" tone="soft" :label="{ ar: 'لصق أسعار', en: 'Paste prices' }" @click="pasting = true" />
  </div>
  <ErrorBanner :error="list.error.value || act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="{ ar: 'لا منتجات تطابق البحث والفلاتر.', en: 'No product matches the search and filters.' }" @sort="nav.setSort" @page="nav.setPage">
      <template #cell-sku="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
          <div class="cell-sub">{{ row.sku }}<template v-if="!row.active"> · {{ t('موقوف', 'stopped') }}</template></div>
        </div>
      </template>
      <template #cell-price="{ row }">
        <span v-if="row.listPrice && num(row.listPrice.price) > 0" class="font-extrabold">{{ price(row.listPrice.price) }}</span>
        <span v-else class="text-[9.5px] font-bold text-warn">{{ t('بلا سعر', 'none') }}</span>
        <Chip v-if="row.listPrice?.owned" small class="ms-1" :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </template>
      <template #cell-marginPct="{ row }"><span :class="row.marginPct != null && row.marginPct < 0 ? 'font-extrabold text-bad' : ''">{{ row.marginPct != null ? `${plain(row.marginPct)}%` : '—' }}</span></template>
      <template #cell-updatedAt="{ row }"><span class="num-mixed text-[9.5px] text-muted">{{ row.listPrice?.updatedAt ? fmtAgo(row.listPrice.updatedAt) : '—' }}</span></template>
      <template #cell-actions="{ row }">
        <span v-if="row.listPrice?.owned" class="text-[9px] text-muted">{{ t('يُعدَّل في أودو', 'Changed in Odoo') }}</span>
        <span v-else class="inline-flex gap-1">
          <Btn size="sm" tone="soft" :label="row.listPrice ? { ar: 'تعديل', en: 'Edit' } : { ar: 'تسعير', en: 'Set price' }" @click.stop="setting = row" />
          <Btn v-if="row.listPrice" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'حذف', en: 'Remove' }" @click.stop="clear(row)" />
        </span>
      </template>
    </DataTable>
  </div>
  <div class="hint">
    {{ t('السعر المعلن هو سعر بيع الوحدة قبل الضريبة لمن لا قاعدة له في قائمته، وهو أساس قواعد «خصم بنسبة» و«معادلة». منتج بلا سعر معلن ولا قاعدة: فحص السعر يقول «لا سعر» ولا يخمّن.',
      'The list price is the unit sales price before VAT for a customer whose list has no rule for the product, and the base of "discount %" and "formula" rules. A product with neither a list price nor a rule: the price check says "no price" and never guesses.') }}
  </div>

  <FormDrawer :open="!!setting" :title="setting ? { ar: `السعر المعلن — ${nameOf(setting)}`, en: `List price — ${nameOf(setting)}` } : null" :sub="setting?.sku" :fields="fields"
              :initial="setting?.listPrice ? { price: num(setting.listPrice.price) } : null" :submit="save" :action="{ success: { ar: 'حُفظ السعر المعلن ✓', en: 'List price saved ✓' }, invalidate: ['pricing'] }"
              @close="setting = null" />
  <PasteDrawer :open="pasting" @close="pasting = false" />
</template>
