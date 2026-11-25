<script setup>
// «فحص السعر» — ask the price service and see why it answers what it answers.
//   customer side:  pick a customer (or one list) + a product + a quantity + a day → GET /pricing/price
//   supplier side:  pick a product + a quantity + a day (and, to ask one supplier, a supplier) → GET /pricing/supplier-price
// The question lives in the query string, so an answer is a link:
//   /pricing?customer=CUS-1004&product=P00325&qty=12        /pricing?side=supplier&product=P00325&qty=50
// The supplier side shows what the company pays: it is offered only to those who may see cost (`lookups.costVisible`).
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Chip, DataTable, DateInput, EmptyState, ErrorBanner, NumberInput, SectionCard, SelectInput, Tabs } from '@/components';
import { bi, t } from '@/i18n';
import PickRecord from './PickRecord.vue';
import PriceAnswer from './PriceAnswer.vue';
import { OUTCOME, SUPPLIER_SOURCE, day, nameOf, periodText, plain, price, todayIso, usePricingLookups, usePricingNav } from './pricing';

const nav = usePricingNav();
const lk = usePricingLookups();
const { costVisible, allListOptions } = lk;

const side = computed(() => (nav.get('side') === 'supplier' && costVisible.value ? 'supplier' : 'customer'));
const sides = computed(() => [
  { k: 'customer', label: { ar: 'سعر العميل', en: 'Customer price' } },
  ...(costVisible.value ? [{ k: 'supplier', label: { ar: 'أفضل سعر مورد', en: 'Best supplier price' } }] : []),
]);
const customer = computed(() => nav.get('customer'));
const plist = computed(() => nav.get('plist'));
const product = computed(() => nav.get('product'));
const supplier = computed(() => nav.get('supplier'));
const qty = computed(() => { const n = Number(nav.get('qty')); return Number.isFinite(n) && n > 0 ? n : 1; });
const date = computed(() => (/^\d{4}-\d{2}-\d{2}$/.test(nav.get('date')) ? nav.get('date') : ''));
const setQty = (v) => nav.patch({ qty: v && v > 0 && v !== 1 ? v : null });

// ── customer side: asked as soon as there is a product and a customer or a list
const askCustomer = computed(() => side.value === 'customer' && !!product.value && (!!customer.value || !!plist.value));
const answerQ = useGet(() => (askCustomer.value ? '/pricing/price' : null),
  () => ({ product: product.value, qty: qty.value, date: date.value || undefined, ...(plist.value ? { list: plist.value } : { customer: customer.value }) }));
const answer = computed(() => (askCustomer.value ? answerQ.data.value : null));

// ── supplier side
const askSupplier = computed(() => side.value === 'supplier' && !!product.value);
const offersQ = useGet(() => (askSupplier.value ? '/pricing/supplier-price' : null),
  () => ({ product: product.value, qty: qty.value, date: date.value || undefined, supplier: supplier.value || undefined }));
const offers = computed(() => (askSupplier.value ? offersQ.data.value : null));
const offerCols = [
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(170px,1.4fr)' },
  { key: 'price', header: { ar: 'السعر', en: 'Price' }, width: '86px', kind: 'num', value: (o) => price(o.price) },
  { key: 'discountPct', header: { ar: 'خصم %', en: 'Disc %' }, width: '62px', kind: 'num', value: (o) => (o.discountPct ? plain(o.discountPct) : '—') },
  { key: 'net', header: { ar: 'الصافي', en: 'Net' }, width: '90px', kind: 'num' },
  { key: 'minQty', header: { ar: 'من كمية', en: 'From qty' }, width: '70px', kind: 'num', value: (o) => plain(o.minQty) },
  { key: 'lead', header: { ar: 'مهلة التوريد', en: 'Lead time' }, width: '86px', kind: 'num', value: (o) => (o.leadDaysEffective != null ? `${o.leadDaysEffective} ${t('يوم', 'd')}` : '—') },
  { key: 'validity', header: { ar: 'المدة', en: 'Period' }, width: 'minmax(130px,1fr)', value: (o) => periodText(o.validFrom, o.validTo) },
  { key: 'flag', header: { ar: 'الحالة', en: 'Status' }, width: '128px', bare: true },
];
const otherCols = [
  { key: 'supplier', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(170px,1.4fr)' },
  { key: 'net', header: { ar: 'الصافي', en: 'Net' }, width: '90px', kind: 'num', value: (o) => price(o.netPrice) },
  { key: 'minQty', header: { ar: 'من كمية', en: 'From qty' }, width: '70px', kind: 'num', value: (o) => plain(o.minQty) },
  { key: 'validity', header: { ar: 'المدة', en: 'Period' }, width: 'minmax(130px,1fr)', value: (o) => periodText(o.validFrom, o.validTo) },
  { key: 'why', header: { ar: 'لماذا لم يُعرض', en: 'Why it is not offered' }, width: 'minmax(150px,1.2fr)' },
];
</script>

<template>
  <Tabs v-if="sides.length > 1" :model-value="side" :tabs="sides" variant="pill" @update:model-value="(k) => nav.patch({ side: k === 'customer' ? null : k })" />

  <SectionCard :title="side === 'customer' ? { ar: 'ما سعر هذا المنتج لهذا العميل؟', en: 'What does this product cost this customer?' } : { ar: 'من أين نشتري هذا المنتج بأفضل سعر؟', en: 'Where is this product bought at the best price?' }">
    <div class="form-grid">
      <template v-if="side === 'customer'">
        <PickRecord kind="customer" :model-value="customer" :disabled="!!plist" :label="{ ar: 'العميل', en: 'Customer' }" @update:model-value="(v) => nav.patch({ customer: v })" />
        <SelectInput :model-value="plist" :options="allListOptions" :label="{ ar: 'أو قائمة بعينها', en: 'Or one list' }" :placeholder="{ ar: '— حسب قائمة العميل —', en: '— the customer’s own list —' }"
                     :hint="{ ar: 'لمعرفة سعر المنتج في قائمة دون عميل', en: 'To see the price on a list without a customer' }" @update:model-value="(v) => nav.patch({ plist: v })" />
      </template>
      <PickRecord kind="product" :model-value="product" :label="{ ar: 'المنتج', en: 'Product' }" @update:model-value="(v) => nav.patch({ product: v })" />
      <PickRecord v-if="side === 'supplier'" kind="supplier" any-state :model-value="supplier" :label="{ ar: 'مورد بعينه (اختياري)', en: 'One supplier (optional)' }" @update:model-value="(v) => nav.patch({ supplier: v })" />
      <NumberInput :model-value="qty" :min="1" :label="{ ar: 'الكمية', en: 'Quantity' }" @update:model-value="setQty" />
      <DateInput :model-value="date" :label="{ ar: 'في تاريخ', en: 'On the day' }" :hint="{ ar: `فارغ = اليوم (${day(todayIso())})`, en: `Empty = today (${day(todayIso())})` }" @update:model-value="(v) => nav.patch({ date: v })" />
    </div>
  </SectionCard>

  <!-- ── what a customer pays -->
  <template v-if="side === 'customer'">
    <ErrorBanner :error="askCustomer ? answerQ.error.value : null" :closable="false" class="mt-3" />
    <div v-if="askCustomer && answerQ.isLoading.value" class="skel mt-3 h-[150px]" />
    <SectionCard v-else-if="answer" class="mt-3" :title="{ ar: 'السعر ولماذا', en: 'The price, and why' }">
      <PriceAnswer :answer="answer" @open-list="(code) => nav.go('lists', { list: code })" />
    </SectionCard>
    <EmptyState v-else-if="!askCustomer" tone="dashed" class="mt-3" :text="{ ar: 'اختر عميلًا (أو قائمة) ومنتجًا: يظهر السعر، القائمة التي سُعِّر بها، القاعدة التي حسمته وخطوات حسابه.', en: 'Pick a customer (or a list) and a product: the price appears with the list it came from, the rule that decided it and how it was worked out.' }" />
  </template>

  <!-- ── what a supplier charges -->
  <template v-else>
    <ErrorBanner :error="askSupplier ? offersQ.error.value : null" :closable="false" class="mt-3" />
    <div v-if="askSupplier && offersQ.isLoading.value" class="skel mt-3 h-[150px]" />
    <template v-else-if="offers">
      <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-2">
        <div class="tile" :class="offers.found ? 'green' : 'red'">
          <div class="tile-v !text-[22px]">{{ offers.found ? price(offers.price) : '—' }}<span v-if="offers.found" class="ms-1 text-[10px] font-bold text-muted">{{ offers.currency }}</span></div>
          <div class="tile-l">{{ t('أفضل سعر للوحدة', 'Best unit price') }} · {{ t('الكمية', 'qty') }} <span class="num">{{ plain(offers.qty) }}</span> · <span class="num">{{ day(offers.date) }}</span></div>
        </div>
        <div class="tile">
          <div class="truncate text-[12px] font-extrabold" dir="auto">{{ offers.supplier ? nameOf(offers.supplier) : '—' }}</div>
          <div class="tile-l">{{ t('المورد', 'Supplier') }}<span v-if="offers.supplier" class="num ltr ms-1">{{ offers.supplier.code }}</span></div>
        </div>
        <div class="tile">
          <div class="tile-v">{{ offers.leadDays != null ? offers.leadDays : '—' }}</div>
          <div class="tile-l">{{ t('مهلة التوريد بالأيام', 'Lead time, days') }}</div>
        </div>
      </div>
      <div class="hint mt-3" :class="offers.found ? 'teal' : 'amber'">
        <div class="row wrap !gap-1.5"><Chip small :map="SUPPLIER_SOURCE" :k="offers.source" /><span class="font-extrabold" dir="auto">{{ bi(offers.reason) }}</span></div>
      </div>

      <SectionCard class="mt-3" :padded="false" :title="{ ar: 'عروض الموردين لهذه الكمية', en: 'Supplier offers for this quantity' }" :count="offers.offers.length">
        <DataTable :columns="offerCols" :rows="offers.offers" :row-key="(o) => o.id" :empty-text="{ ar: 'لا مورد مسعِّر لهذه الكمية في هذا التاريخ.', en: 'No supplier is priced for this quantity on that day.' }">
          <template #cell-supplier="{ row }">
            <div class="min-w-0">
              <div class="cell-name truncate" dir="auto">{{ nameOf(row.supplier) }}</div>
              <div class="cell-sub">{{ row.supplier?.code }}<template v-if="row.supplierSku"> · {{ row.supplierSku }}</template></div>
            </div>
          </template>
          <template #cell-net="{ row }"><span class="font-extrabold" :class="row.best ? 'text-ok' : ''">{{ price(row.net) }}</span></template>
          <template #cell-flag="{ row }">
            <Chip v-if="row.best" small :label="{ ar: 'الأفضل', en: 'Best' }" fg="#1d7a3e" bg="#e6f9ec" />
            <Chip v-else-if="!row.orderable" small :label="row.notOrderableWhy" fg="#b23b3b" bg="#fdecec" />
            <span v-else class="text-faint">—</span>
          </template>
        </DataTable>
      </SectionCard>
      <SectionCard v-if="offers.others.length" class="mt-3" :padded="false" :title="{ ar: 'أسعار لا تنطبق على هذا السؤال', en: 'Prices that do not apply to this question' }" :count="offers.others.length">
        <DataTable :columns="otherCols" :rows="offers.others" :row-key="(o) => o.id" dense>
          <template #cell-supplier="{ row }"><span class="truncate text-[10.5px] font-bold" dir="auto">{{ nameOf(row.supplier) }}</span></template>
          <template #cell-why="{ row }"><Chip small :map="OUTCOME" :k="row.outcome" /><span class="ms-1 text-[9.5px] text-muted" dir="auto">{{ bi(row.why) }}</span></template>
        </DataTable>
        <div class="px-4 pb-3 pt-2 text-[9px] text-faint">{{ t('الشرائح الأعلى كميةً تظهر هنا: زيادة الكمية قد تعطي سعرًا أفضل.', 'Higher quantity tiers are listed here: a larger order may get a better price.') }}</div>
      </SectionCard>
    </template>
    <EmptyState v-else-if="!askSupplier" tone="dashed" class="mt-3" :text="{ ar: 'اختر منتجًا: يظهر أفضل سعر مورد لهذه الكمية في هذا التاريخ وكل العروض الأخرى.', en: 'Pick a product: the best supplier price for this quantity on that day appears, with every other offer.' }" />
  </template>
</template>
