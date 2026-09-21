<script setup>
// Product profile (deep link /product/:sku) — full master record, barcodes, suppliers, stock across warehouses & bins,
// and the recent movements of the inventory ledger.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useGet, useList } from '@/api/client';
import { Btn, Chip, ErrorBanner, KV, KpiCard, KpiGrid, PageHead, SectionCard } from '@/components';
import { fmtDate, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { showLabel } from '@/stores/ui';
import BarcodesPanel from './BarcodesPanel.vue';
import EditProductForm from './EditProductForm.vue';
import MovementsTable from './MovementsTable.vue';
import ReasonForm from './ReasonForm.vue';
import StockPerWarehouse from './StockPerWarehouse.vue';
import StockRows from './StockRows.vue';
import SuppliersPanel from './SuppliersPanel.vue';
import { ACTIVE_LABELS, STORAGE_LABELS, catPath, dims, ltrText } from './_shared';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const canManage = computed(() => auth.can('product.manage'));

const sku = computed(() => (typeof route.params.sku === 'string' ? route.params.sku : ''));
const edit = ref(false);
/** 'activate' | 'deactivate' | null */
const reasonMode = ref(null);
const page = ref(1);
watch(sku, () => { page.value = 1; });

const product = useGet(() => (sku.value ? `/products/${encodeURIComponent(sku.value)}` : null));
const p = computed(() => product.data.value);
const stock = useGet(() => (p.value ? `/inventory/products/${encodeURIComponent(p.value.sku)}/stock` : null));
const ledger = useList('/inventory/ledger', () => ({ sku: p.value?.sku, page: page.value, pageSize: 20 }), { enabled: () => !!p.value });

const totals = computed(() => (stock.data.value ? stock.data.value.perWarehouse.reduce((a, w) => ({ onHand: a.onHand + w.onHand, reserved: a.reserved + w.reserved, quarantine: a.quarantine + w.quarantine }), { onHand: 0, reserved: 0, quarantine: 0 }) : null));
const below = computed(() => !!(p.value && stock.data.value && p.value.reorderMin > 0 && stock.data.value.totalAvailable < p.value.reorderMin));
const preferredSupplier = computed(() => p.value?.preferredSupplierName || p.value?.suppliers?.find((s) => s.preferred)?.supplier.nameAr || '—');

// The shell renders the subtitle as plain text, so the LTR fragments are wrapped in Unicode isolates.
const headSub = computed(() => (p.value ? `${ltrText(p.value.sku)} · ${ltrText(p.value.nameEn)}${p.value.brand ? ` · ${p.value.brand}` : ''}` : t('ملف المنتج', 'Product profile')));

const reasonTitle = computed(() => (reasonMode.value === 'deactivate' ? { ar: `إيقاف الصنف ${p.value?.sku}`, en: `Deactivate ${p.value?.sku}` } : { ar: `إعادة تفعيل ${p.value?.sku}`, en: `Activate ${p.value?.sku}` }));
const reasonSub = computed(() => (reasonMode.value === 'deactivate' ? { ar: 'البيانات التاريخية محفوظة — لن يُقبل في طلبات جديدة', en: 'History is preserved — no new orders will accept it' } : null));
const submitReason = (reason) => api.postIdempotent(`/products/${p.value.id}/${reasonMode.value}`, { reason });
</script>

<template>
  <PageHead :title="p ? p.nameAr : sku" :sub="headSub">
    <Btn tone="outline" size="sm" :label="{ ar: '← المنتجات Master', en: '← Product Master' }" @click="router.push('/products')" />
    <Btn v-if="p" tone="soft" size="sm" :label="{ ar: 'ملصق الصنف', en: 'Item label' }" @click="showLabel({ type: 'code128', text: p.primaryBarcode?.barcode || p.sku, title: p.sku, sub: lang === 'ar' ? p.nameAr : p.nameEn || p.nameAr })" />
    <Btn v-if="p && canManage" tone="dark" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="edit = true" />
    <template v-if="p && canManage">
      <Btn v-if="p.active" tone="dangerOutline" size="sm" :label="{ ar: 'إيقاف الصنف', en: 'Deactivate' }" @click="reasonMode = 'deactivate'" />
      <Btn v-else tone="softGreen" size="sm" :label="{ ar: 'إعادة التفعيل', en: 'Activate' }" @click="reasonMode = 'activate'" />
    </template>
  </PageHead>

  <ErrorBanner :error="product.error.value" :closable="false" />
  <div v-if="product.isLoading.value" class="skel min-h-[240px]" />

  <template v-if="p">
    <KpiGrid compact>
      <KpiCard compact :value="totals?.onHand" :loading="stock.isLoading.value" :label="{ ar: 'فعلي — كل المستودعات', en: 'On hand — all warehouses' }" />
      <KpiCard compact :value="totals?.reserved" :loading="stock.isLoading.value" :label="{ ar: 'محجوز', en: 'Reserved' }" color="#b26a16" />
      <KpiCard compact :value="stock.data.value?.totalAvailable" :loading="stock.isLoading.value" :label="{ ar: 'متاح للبيع', en: 'Available to promise' }" :color="below ? '#b23b3b' : '#1d7a3e'"
               :sub="below ? { ar: `تحت حد الطلب ${fmtNum(p.reorderMin)}`, en: `Below reorder point ${fmtNum(p.reorderMin)}` } : null" />
      <KpiCard compact :value="totals?.quarantine" :loading="stock.isLoading.value" :label="{ ar: 'محجور', en: 'Quarantine' }" :color="totals?.quarantine ? '#b23b3b' : '#20242E'" />
      <KpiCard compact :label="{ ar: 'حالة الصنف', en: 'Status' }" :sub="{ ar: `آخر تحديث ${fmtDate(p.updatedAt)}`, en: `Updated ${fmtDate(p.updatedAt)}` }">
        <template #value><Chip :map="ACTIVE_LABELS" :k="p.active ? 'active' : 'inactive'" /></template>
      </KpiCard>
    </KpiGrid>

    <div class="grid-2">
      <SectionCard :title="{ ar: 'ملف Master', en: 'Master record' }" :padded="false">
        <div class="kv-grid px-4 py-3">
          <KV :k="{ ar: 'التصنيف', en: 'Category' }" :v="catPath(p.category)" />
          <KV :k="{ ar: 'العلامة', en: 'Brand' }" :v="p.brand || '—'" />
          <KV :k="{ ar: 'الوحدة', en: 'UoM' }" :v="p.baseUom ? `${p.baseUom.nameAr} (${p.baseUom.code})` : '—'" />
          <KV :k="{ ar: 'التخزين', en: 'Storage' }"><Chip :map="STORAGE_LABELS" :k="p.storageClass" small /></KV>
          <KV :k="{ ar: 'الأبعاد سم', en: 'Dims cm' }" :v="dims(p)" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'الحجم م³', en: 'Volume m³' }" :v="p.volumeM3 != null ? fmtNum(p.volumeM3, 4) : '—'" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'الوزن كجم', en: 'Weight kg' }" :v="fmtNum(p.weightKg, 2)" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'وحدات الطبلية', en: 'Units / pallet' }" :v="p.unitsPerPallet != null ? fmtNum(p.unitsPerPallet) : '—'" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'سعر الشراء', en: 'Purchase price' }" :v="p.purchasePrice != null ? `${fmtMoney(p.purchasePrice)} ${t('ر.س', 'SAR')}` : '—'" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'العمر الافتراضي', en: 'Shelf life' }" :v="p.shelfLifeDays != null ? `${fmtNum(p.shelfLifeDays)} ${t('يوم', 'days')}` : '—'" />
          <KV :k="{ ar: 'تتبع الصلاحية', en: 'Tracks expiry' }" :v="p.tracksExpiry ? t('نعم — صرف FEFO', 'Yes — FEFO picking') : t('لا', 'No')" />
          <KV :k="{ ar: 'حد إعادة الطلب', en: 'Reorder point' }" :v="fmtNum(p.reorderMin)" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'الحد الأقصى', en: 'Reorder max' }" :v="p.reorderMax != null ? fmtNum(p.reorderMax) : '—'" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'المستودع الرئيسي', en: 'Home warehouse' }" :v="p.homeWarehouse ? `${p.homeWarehouse.code} — ${p.homeWarehouse.nameAr}` : '—'" />
          <KV :k="{ ar: 'المورد المفضل', en: 'Preferred supplier' }" :v="preferredSupplier" />
          <KV :k="{ ar: 'الباركود الأساسي', en: 'Primary barcode' }" :v="p.primaryBarcode || '—'" ltr class="[&_.kv-v]:font-num" />
          <KV :k="{ ar: 'أُنشئ', en: 'Created' }" :v="fmtDate(p.createdAt)" ltr class="[&_.kv-v]:font-num" />
        </div>
      </SectionCard>
      <div class="col !gap-3.5">
        <SectionCard :title="{ ar: 'الباركود', en: 'Barcodes' }" :count="p.barcodes?.length"><BarcodesPanel :product="p" :can-manage="canManage" /></SectionCard>
        <SectionCard :title="{ ar: 'الموردون', en: 'Suppliers' }" :count="p.suppliers?.length"><SuppliersPanel :product="p" :can-manage="canManage" /></SectionCard>
      </div>
    </div>

    <div class="grid-eq mt-3.5">
      <SectionCard :title="{ ar: 'الرصيد حسب المستودع', en: 'Stock per warehouse' }" :padded="false"><StockPerWarehouse :stock="stock.data.value" :loading="stock.isLoading.value" /></SectionCard>
      <SectionCard :title="{ ar: 'الرصيد حسب الموقع Bin / الدفعة', en: 'Stock per bin / batch' }" :count="stock.data.value?.rows.length" :padded="false"><StockRows :rows="stock.data.value?.rows || []" :loading="stock.isLoading.value" /></SectionCard>
    </div>

    <SectionCard class="mt-3.5" :title="{ ar: 'آخر الحركات — سجل المخزون', en: 'Recent movements — inventory ledger' }" :count="ledger.data.value?.total" :padded="false">
      <template #actions><Btn tone="ghost" size="sm" :label="{ ar: 'السجل الكامل ←', en: 'Full ledger →' }" @click="router.push(`/ledger?sku=${encodeURIComponent(p.sku)}`)" /></template>
      <ErrorBanner :error="ledger.error.value" :closable="false" />
      <MovementsTable :paged="ledger.data.value" :loading="ledger.isLoading.value" @page="page = $event" />
    </SectionCard>

    <EditProductForm :product="edit ? p : null" :open="edit" @close="edit = false" />
    <ReasonForm :open="!!reasonMode" :title="reasonTitle" :sub="reasonSub" :submit="submitReason" @close="reasonMode = null" />
  </template>
</template>
