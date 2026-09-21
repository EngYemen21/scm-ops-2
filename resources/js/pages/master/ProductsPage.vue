<script setup>
// Product Master (nav `products`): KPIs, categories panel, search / status / supplier / storage / category filters,
// 16-column master table, product drawer (stock across warehouses), new-product modal, edit form with mandatory reason.
// Deep links: /products?q=<search>  ·  /products?sku=<sku> (opens the drawer). Both are kept in sync with the URL.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, ErrorBanner, Icon, KpiCard, KpiGrid, PageHead, SelectInput, Tabs, TextInput } from '@/components';
import { fmtMoney, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import CategoryCard from './CategoryCard.vue';
import CategoryForm from './CategoryForm.vue';
import EditProductForm from './EditProductForm.vue';
import NewProductModal from './NewProductModal.vue';
import ProductDrawer from './ProductDrawer.vue';
import SkuPill from './SkuPill.vue';
import { ACTIVE_LABELS, STORAGE_LABELS, catPath, dims, useLookups } from './_shared';

const COLS = '88px minmax(200px,2fr) 92px 112px 70px 100px 96px 64px 56px 64px 74px 62px 62px minmax(115px,1fr) 74px 96px';
const CELL = 'flex min-w-0 items-center border-s border-[#F4F2F8] px-2 py-2.5';
const HEAD = 'border-s border-[#EFEDF4] px-2 py-[11px]';
const ROW_BTN = '!h-[26px] !rounded-[7px] !px-2 !text-[8.5px]';

const auth = useAuth();
const wh = useWarehouse();
const route = useRoute();
const router = useRouter();
const str = (v) => (typeof v === 'string' ? v : '');

// ---- filters ----
const q = ref(str(route.query.q));
const qLive = ref(q.value);
const stor = ref('');
const cat = ref('');
/** 'true' | 'false' | '' (all) */
const active = ref('true');
const supplier = ref('');
const page = ref(1);

// ---- panels / drawers ----
const catsOpen = ref(false);
const openSku = ref(str(route.query.sku) || null);
const edit = ref(null);
const newOpen = ref(false);
/** { mode: 'root' | 'sub' | 'edit', parent?, category? } */
const catForm = ref(null);

const canProd = computed(() => auth.can('product.manage'));
const canCat = computed(() => auth.can('category.manage'));

// ---- data ----
const list = useList('/products', () => ({ q: q.value || undefined, storageClass: stor.value || undefined, category: cat.value || undefined, active: active.value || undefined, supplier: supplier.value || undefined, ...wh.whParams, page: page.value, pageSize: 25 }));
const lookups = useLookups();
const lk = computed(() => lookups.data.value);
const kActive = useList('/products', { active: 'true', pageSize: 1 });
const kChilled = useList('/products', { active: 'true', storageClass: 'chilled', pageSize: 1 });
const kFrozen = useList('/products', { active: 'true', storageClass: 'frozen', pageSize: 1 });
const dash = useGet('/dashboard', undefined, { staleTime: 60_000, retry: false });
const summary = useGet('/inventory/balances/summary', undefined, { staleTime: 60_000, retry: false });
const cats = useGet('/categories', undefined, { enabled: () => catsOpen.value });

const rows = computed(() => list.data.value?.items || []);
const roots = computed(() => (lk.value?.categories || []).filter((c) => !c.parentId && c.active));
const lowStock = computed(() => dash.data.value?.kpis?.find((k) => k.key === 'lowStock')?.value);
const coldCount = computed(() => (kChilled.data.value && kFrozen.data.value ? kChilled.data.value.total + kFrozen.data.value.total : null));
const catName = (c) => (lang.value === 'ar' ? c.nameAr : c.nameEn || c.nameAr);
/** A sub-category picked from the categories panel is not among the root pills — it gets its own removable pill. */
const extraCat = computed(() => (cat.value && !roots.value.some((c) => c.code === cat.value) ? lk.value?.categories.find((c) => c.code === cat.value)?.pathAr || cat.value : null));

const storTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...['ambient', 'chilled', 'frozen'].map((k) => ({ k, label: STORAGE_LABELS[k] }))];
const activeOpts = [['true', { ar: 'النشطة', en: 'Active' }], ['false', { ar: 'الموقوفة', en: 'Inactive' }], ['', { ar: 'الكل', en: 'All' }]];
const supplierOpts = computed(() => (lk.value?.suppliers || []).map((s) => ({ v: s.code, l: s.nameAr })));

// ---- URL sync ----
function setQuery(patch) {
  const n = { ...route.query };
  for (const [k, v] of Object.entries(patch)) { if (v) n[k] = v; else delete n[k]; }
  router.replace({ query: n });
}
function submitSearch() { q.value = qLive.value.trim(); page.value = 1; setQuery({ q: q.value }); }
function clearSearch() { q.value = ''; qLive.value = ''; page.value = 1; setQuery({ q: '' }); }
function openProduct(sku) { openSku.value = sku; setQuery({ sku }); }
function setCat(code) { cat.value = code; page.value = 1; }
// Links that land here while the page is already open (global search, notifications).
watch(() => [route.name, route.query.q, route.query.sku], ([name, nq, nsku]) => {
  if (name !== 'products') return;
  if (str(nq) !== q.value) { q.value = str(nq); qLive.value = q.value; page.value = 1; }
  if ((str(nsku) || null) !== openSku.value) openSku.value = str(nsku) || null;
});

function exportCsv() {
  const h = ['SKU', 'Name AR', 'Name EN', 'Brand', 'Category', 'UoM', 'Storage', 'L', 'W', 'H', 'Vol m3', 'Kg', 'Pallet', 'Price', 'Shelf days', 'Supplier', 'WH', 'Active'];
  const lines = rows.value.map((p) => [p.sku, p.nameAr, p.nameEn, p.brand || '', p.category?.nameAr || '', p.baseUom?.code || '', p.storageClass, p.lengthCm ?? '', p.widthCm ?? '', p.heightCm ?? '', p.volumeM3 ?? '', p.weightKg, p.unitsPerPallet ?? '', p.purchasePrice ?? '', p.shelfLifeDays ?? '', p.preferredSupplierName || '', p.homeWarehouse?.code || '', p.active ? 1 : 0]
    .map((x) => `"${String(x).replace(/"/g, '""')}"`).join(','));
  const blob = new Blob(['﻿' + [h.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `products-page${page.value}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}

const pg = computed(() => list.data.value);
const pgFrom = computed(() => (pg.value ? (pg.value.page - 1) * (pg.value.pageSize || 25) + 1 : 0));
const pgTo = computed(() => (pg.value ? Math.min(pg.value.total, pg.value.page * (pg.value.pageSize || 25)) : 0));
</script>

<template>
  <PageHead :sub="t(`ملف Master المعتمد — ${fmtNum(kActive.data.value?.total)} صنفًا بكل خصائصه التجارية واللوجستية`, `Approved master file — ${fmtNum(kActive.data.value?.total)} SKUs with every commercial & logistics attribute`)">
    <Btn tone="outline" size="sm" :disabled="!rows.length" :label="{ ar: 'تصدير Excel', en: 'Export Excel' }" @click="exportCsv" />
  </PageHead>

  <KpiGrid compact class="!grid-cols-[repeat(auto-fit,minmax(150px,1fr))]">
    <KpiCard compact clickable :value="kActive.data.value?.total" :loading="kActive.isLoading.value" :label="{ ar: 'صنفًا معتمدًا', en: 'Active SKUs' }" color="#20242E" :active="active === 'true' && !stor" @click="active = 'true'; page = 1" />
    <KpiCard compact clickable :value="lk ? lk.categories.filter((c) => c.active).length : null" :loading="!lk" :label="{ ar: 'فئة', en: 'Categories' }" color="#654e92" @click="catsOpen = true" />
    <KpiCard compact clickable :value="coldCount" :loading="kChilled.isLoading.value || kFrozen.isLoading.value" :label="{ ar: 'مبرد ومجمد', en: 'Cold chain SKUs' }" color="#3C79F5" @click="stor = stor === 'chilled' ? 'frozen' : 'chilled'; page = 1" />
    <KpiCard compact :value="summary.data.value ? fmtNum(summary.data.value.totals?.available) : '—'" :loading="summary.isLoading.value" :label="{ ar: 'وحدة متاحة برصيد حي', en: 'Available units in stock' }" color="#1d7a3e" :sub="summary.error.value ? { ar: 'غير متاح لهذا الدور', en: 'Not available for this role' } : null" />
    <KpiCard compact :value="lowStock ?? '—'" :loading="dash.isLoading.value" :label="{ ar: 'تحت حد الطلب', en: 'Below reorder' }" :color="lowStock ? '#b23b3b' : '#20242E'" :sub="dash.error.value ? { ar: 'من لوحة القيادة — غير متاح', en: 'From dashboard — unavailable' } : null" />
  </KpiGrid>

  <!-- categories panel -->
  <div v-if="catsOpen" class="mb-3 rounded-[18px] border-[1.5px] border-[#DCD2EE] bg-white px-5 py-4">
    <div class="row">
      <div class="text-[13px] font-extrabold">{{ t('تصنيفات المنتجات — رئيسي ← فرعي', 'Product categories — root ← sub') }}</div>
      <span class="grow" />
      <Btn v-if="canCat" tone="dark" size="sm" class="!h-8" :label="{ ar: '+ تصنيف رئيسي', en: '+ Root category' }" @click="catForm = { mode: 'root' }" />
      <button type="button" class="x-btn" aria-label="close" @click="catsOpen = false">✕</button>
    </div>
    <ErrorBanner :error="cats.error.value" :closable="false" class="mt-2.5" />
    <div v-if="cats.isLoading.value" class="skel mt-3 min-h-[90px]" />
    <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-2.5">
      <CategoryCard v-for="c in cats.data.value || []" :key="c.id" :c="c" :can-cat="canCat" @edit="(x) => (catForm = { mode: 'edit', category: x })" @add-sub="(x) => (catForm = { mode: 'sub', parent: x })" @filter="setCat" />
      <div v-if="cats.data.value && cats.data.value.length === 0" class="empty">{{ t('لا تصنيفات بعد — أضف تصنيفًا رئيسيًا', 'No categories yet') }}</div>
    </div>
  </div>

  <!-- filters -->
  <div class="row wrap mt-1">
    <TextInput scan v-model="qLive" small class="w-[260px]" :placeholder="{ ar: 'بحث SKU / اسم / باركود / علامة…', en: 'Search SKU / name / barcode / brand…' }" @enter="submitSearch" />
    <Btn tone="soft" size="sm" :label="{ ar: 'بحث', en: 'Search' }" @click="submitSearch" />
    <Btn v-if="q" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلتر', en: 'Clear' }" @click="clearSearch" />
    <SelectInput v-model="active" small class="w-[110px]" :options="activeOpts" @update:model-value="page = 1" />
    <SelectInput v-model="supplier" small class="w-[160px]" :options="supplierOpts" :placeholder="{ ar: 'كل الموردين', en: 'All suppliers' }" @update:model-value="page = 1" />
    <span class="grow" />
    <Tabs v-model="stor" :tabs="storTabs" variant="pillPurple" class="!mb-0" @update:model-value="page = 1" />
    <div class="flex-none text-[10px] text-faint"><span class="num text-ink">{{ fmtNum(list.data.value?.total) }}</span> {{ t('صنفًا معروضًا', 'shown') }}</div>
    <Btn v-if="canCat" tone="outline" class="!h-10 !rounded-[11px] !text-[11px] !text-violet" :label="{ ar: 'إدارة التصنيفات', en: 'Manage categories' }" @click="catsOpen = !catsOpen" />
    <Btn v-if="canProd" tone="dark" class="!h-10 !rounded-[11px] !text-[11px]" :label="{ ar: 'منتج جديد', en: 'New product' }" @click="newOpen = true">
      <template #icon><Icon name="plus" :size="13" color="#fff" /></template>
    </Btn>
  </div>
  <div class="pill-bar !mb-0 mt-2.5">
    <button type="button" class="pill" :class="{ active: !cat }" @click="setCat('')">{{ t('كل الفئات', 'All categories') }}</button>
    <button v-for="c in roots" :key="c.id" type="button" class="pill" :class="{ active: cat === c.code }" @click="setCat(c.code)">{{ catName(c) }}</button>
    <button v-if="extraCat" type="button" class="pill active" @click="setCat('')">{{ extraCat }} ✕</button>
  </div>
  <div v-if="!wh.isAll" class="mt-2 text-[9.5px] text-muted">{{ t('يُعرض ما مستودعه الرئيسي هو المستودع المحدد في الأعلى — اختر «كل المستودعات» لرؤية الجميع', 'Filtered by the selected home warehouse — pick "All warehouses" to see everything') }}</div>

  <!-- master table -->
  <ErrorBanner :error="list.error.value" :closable="false" class="mt-3" />
  <div class="mt-3 overflow-hidden rounded-[18px] border border-line bg-white">
    <div class="overflow-x-auto">
      <div class="min-w-[1480px]">
        <div class="grid border-b-[1.5px] border-line bg-[#F7F6FA] px-3.5 text-[9px] font-extrabold text-muted" :style="{ gridTemplateColumns: COLS }">
          <div :class="HEAD" class="!border-s-0">SKU</div>
          <div :class="HEAD">{{ t('المنتج', 'Product') }}</div>
          <div :class="HEAD">{{ t('العلامة', 'Brand') }}</div>
          <div :class="HEAD">{{ t('الفئة', 'Category') }}</div>
          <div :class="HEAD">{{ t('الوحدة', 'UoM') }}</div>
          <div :class="HEAD">{{ t('التخزين', 'Storage') }}</div>
          <div :class="HEAD" class="text-center">{{ t('الأبعاد سم', 'Dims cm') }}</div>
          <div :class="HEAD" class="text-center">م³</div>
          <div :class="HEAD" class="text-center">{{ t('كجم', 'Kg') }}</div>
          <div :class="HEAD" class="text-center">{{ t('طبلية', 'Pallet') }}</div>
          <div :class="HEAD" class="text-center">{{ t('الشراء ر.س', 'Buy SAR') }}</div>
          <div :class="HEAD" class="text-center">{{ t('حد الطلب', 'Reorder') }}</div>
          <div :class="HEAD" class="text-center">{{ t('العمر', 'Shelf') }}</div>
          <div :class="HEAD">{{ t('المورد', 'Supplier') }}</div>
          <div :class="HEAD">{{ t('المستودع', 'WH') }}</div>
          <div :class="HEAD" class="text-center">{{ t('إجراءات', 'Actions') }}</div>
        </div>

        <template v-if="list.isLoading.value && rows.length === 0">
          <div v-for="i in 8" :key="i" class="border-t border-line-2 px-[22px] py-3"><div class="skel h-3" :style="{ width: `${50 + (((i - 1) * 13) % 40)}%` }" /></div>
        </template>
        <div v-if="!list.isLoading.value && rows.length === 0" class="empty">{{ t('لا نتائج مطابقة.', 'No matching results.') }}</div>

        <div v-for="(p, i) in rows" :key="p.id" class="grid cursor-pointer items-stretch border-t border-line-2 px-3.5 hover:bg-[#F3F0FA]" :class="[i % 2 ? 'bg-soft' : 'bg-white', { 'opacity-60': !p.active }]" :style="{ gridTemplateColumns: COLS }" @click="openProduct(p.sku)">
          <div :class="CELL" class="!border-s-0"><SkuPill :sku="p.sku" /></div>
          <div :class="CELL" class="!flex-col !items-stretch justify-center">
            <div class="ellipsis text-[10.5px] font-extrabold leading-[1.6] text-ink">{{ p.nameAr }}<Chip v-if="!p.active" :map="ACTIVE_LABELS" k="inactive" small class="ms-1.5" /></div>
            <div class="ellipsis text-[10.5px] font-bold leading-[1.6] text-brand-dark"><bdi dir="ltr">{{ p.nameEn }}</bdi></div>
          </div>
          <div :class="CELL" class="text-[9.5px] font-extrabold text-sec">{{ p.brand || '—' }}</div>
          <div :class="CELL" class="text-[9.5px] font-bold leading-[1.5] text-sec">{{ catPath(p.category) }}</div>
          <div :class="CELL" class="text-[9.5px] font-bold text-muted">{{ p.baseUom?.nameAr || p.baseUom?.code || '—' }}</div>
          <div :class="CELL" class="overflow-hidden !px-1.5"><Chip :map="STORAGE_LABELS" :k="p.storageClass" /></div>
          <div :class="CELL" class="num justify-center text-[9.5px]"><bdi dir="ltr">{{ dims(p) }}</bdi></div>
          <div :class="CELL" class="num justify-center text-[9.5px] text-muted">{{ p.volumeM3 != null ? fmtNum(p.volumeM3, 3) : '—' }}</div>
          <div :class="CELL" class="num justify-center text-[10px] text-sec">{{ fmtNum(p.weightKg, 2) }}</div>
          <div :class="CELL" class="num justify-center text-[10.5px] text-violet">{{ p.unitsPerPallet != null ? fmtNum(p.unitsPerPallet) : '—' }}</div>
          <div :class="CELL" class="num justify-center text-[10.5px]">{{ p.purchasePrice != null ? fmtMoney(p.purchasePrice) : '—' }}</div>
          <div :class="[CELL, p.reorderMin > 0 ? 'text-warn' : 'text-faint']" class="num justify-center text-[11px]">{{ fmtNum(p.reorderMin) }}</div>
          <div :class="CELL" class="justify-center text-[9.5px] font-bold text-warn">{{ p.shelfLifeDays != null ? `${p.shelfLifeDays} ${t('ي', 'd')}` : '—' }}</div>
          <div :class="CELL" class="text-[9.5px] font-extrabold text-sec"><span class="ellipsis">{{ p.preferredSupplierName || '—' }}</span></div>
          <div :class="CELL" class="text-[9.5px] font-bold text-brand-dark">{{ p.homeWarehouse?.code || '—' }}</div>
          <div :class="CELL" class="justify-center gap-1 !p-1.5" @click.stop>
            <Btn tone="soft" size="sm" :class="ROW_BTN" :label="{ ar: 'عرض', en: 'View' }" @click="openProduct(p.sku)" />
            <Btn v-if="canProd" tone="softPurple" size="sm" :class="ROW_BTN" :label="{ ar: 'تعديل', en: 'Edit' }" @click="edit = p" />
          </div>
        </div>
      </div>
    </div>
    <div v-if="pg && pg.pages > 1" class="gt-foot">
      <span class="flex-1">{{ t('عرض', 'Showing') }} <b class="num">{{ fmtNum(pgFrom) }}–{{ fmtNum(pgTo) }}</b> {{ t('من', 'of') }} <b class="num">{{ fmtNum(pg.total) }}</b></span>
      <div class="row !gap-1">
        <button type="button" class="pg-btn" :disabled="page <= 1" @click="page -= 1">‹</button>
        <span class="num px-1.5 text-[10px]">{{ pg.page }} / {{ pg.pages }}</span>
        <button type="button" class="pg-btn" :disabled="page >= pg.pages" @click="page += 1">›</button>
      </div>
    </div>
  </div>
  <div class="hint">{{ t('كل أعمدة ملف Master ممثلة: الأبعاد والحجم والوزن وسعة الطبلية تغذي اقتراح المواقع وتخطيط الحمولة، ودرجة التخزين تُفرض آليًا على Putaway. اضغط أي صنف لرصيده الحي وحركاته.', 'All master-file attributes drive putaway suggestions and load planning; click any SKU for live balances.') }}</div>

  <ProductDrawer :id-or-sku="openSku" @close="openProduct(null)" @edit="(p) => (edit = p)" />
  <EditProductForm :product="edit" :open="!!edit" @close="edit = null" />
  <NewProductModal :open="newOpen" @close="newOpen = false" @done="(p) => openProduct(p.sku)" />
  <CategoryForm :open="!!catForm" :mode="catForm?.mode || 'root'" :parent="catForm?.parent" :category="catForm?.category" @close="catForm = null" />
</template>
