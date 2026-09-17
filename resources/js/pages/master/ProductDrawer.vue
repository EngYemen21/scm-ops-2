<script setup>
// 520px product drawer opened from the Products table: stock tiles, profile, stock across warehouses / bins,
// barcodes and suppliers; footer with Edit, Activate / Deactivate (reason required) and the link to the full profile.
//   <ProductDrawer :id-or-sku="openSku" @close="openProduct(null)" @edit="(product) => (edit = product)" />
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Tabs } from '@/components';
import { fmtDate, fmtMoney, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import BarcodesPanel from './BarcodesPanel.vue';
import ReasonForm from './ReasonForm.vue';
import StockPerWarehouse from './StockPerWarehouse.vue';
import StockRows from './StockRows.vue';
import SuppliersPanel from './SuppliersPanel.vue';
import Tile from './Tile.vue';
import { ACTIVE_LABELS, STORAGE_LABELS, catPath, dims } from './_shared';

const props = defineProps({ idOrSku: { type: String, default: null } });
const emit = defineEmits(['close', 'edit']);

const router = useRouter();
const auth = useAuth();
const canManage = computed(() => auth.can('product.manage'));

const product = useGet(() => (props.idOrSku ? `/products/${encodeURIComponent(props.idOrSku)}` : null));
/** The loaded record — ignored while it still belongs to the previously opened product. */
const p = computed(() => (props.idOrSku ? product.data.value : null));
const stockQ = useGet(() => (p.value ? `/inventory/products/${encodeURIComponent(p.value.sku)}/stock` : null));
const stock = computed(() => stockQ.data.value);
const stockLoading = computed(() => stockQ.isLoading.value);
const totals = computed(() => (stock.value ? stock.value.perWarehouse.reduce((a, w) => ({ onHand: a.onHand + w.onHand, reserved: a.reserved + w.reserved }), { onHand: 0, reserved: 0 }) : null));
const belowReorder = computed(() => !!(stock.value && p.value && p.value.reorderMin > 0 && stock.value.totalAvailable < p.value.reorderMin));

const tab = ref('profile');
const tabs = [{ k: 'profile', label: { ar: 'الملف', en: 'Profile' } }, { k: 'stock', label: { ar: 'الرصيد بالمواقع', en: 'Stock' } }, { k: 'codes', label: { ar: 'الباركود والموردون', en: 'Barcodes & suppliers' } }];

/** 'activate' | 'deactivate' | null */
const reasonMode = ref(null);
const reasonTitle = computed(() => (reasonMode.value === 'deactivate' ? { ar: `إيقاف الصنف ${p.value?.sku}`, en: `Deactivate ${p.value?.sku}` } : { ar: `إعادة تفعيل ${p.value?.sku}`, en: `Activate ${p.value?.sku}` }));
const reasonSub = computed(() => (reasonMode.value === 'deactivate' ? { ar: 'البيانات التاريخية محفوظة — لن يُقبل في طلبات جديدة', en: 'History is preserved — no new orders will accept it' } : null));
const submitReason = (reason) => api.postIdempotent(`/products/${p.value.id}/${reasonMode.value}`, { reason });
const preferredSupplier = computed(() => p.value?.preferredSupplierName || p.value?.suppliers?.find((s) => s.preferred)?.supplier.nameAr || '—');
</script>

<template>
  <Drawer :open="!!idOrSku" :width="520" :title="p ? p.nameAr : t('جارٍ التحميل…', 'Loading…')" @close="emit('close')">
    <template v-if="p" #sub><span><bdi dir="ltr" class="num">{{ p.sku }}</bdi>{{ p.brand ? ` · ${p.brand}` : '' }} · <bdi dir="ltr">{{ p.nameEn }}</bdi></span></template>
    <template v-if="p" #headExtra><Chip :map="ACTIVE_LABELS" :k="p.active ? 'active' : 'inactive'" small /></template>

    <ErrorBanner :error="product.error.value" :closable="false" />
    <div v-if="product.isLoading.value" class="skel min-h-[160px]" />
    <template v-if="p">
      <div class="grid grid-cols-3 gap-2">
        <Tile :v="stockLoading ? '…' : fmtNum(totals?.onHand)" :l="{ ar: 'فعلي', en: 'On hand' }" />
        <Tile :v="stockLoading ? '…' : fmtNum(totals?.reserved)" :l="{ ar: 'محجوز', en: 'Reserved' }" tone="amber" />
        <Tile :v="stockLoading ? '…' : fmtNum(stock?.totalAvailable)" :l="{ ar: 'متاح', en: 'Available' }" tone="green" />
      </div>
      <div v-if="belowReorder" class="hint amber !mt-2">{{ t(`المتاح (${fmtNum(stock.totalAvailable)}) تحت حد إعادة الطلب (${fmtNum(p.reorderMin)}) — يظهر في اقتراحات الشراء`, `Available (${fmtNum(stock.totalAvailable)}) is below the reorder point (${fmtNum(p.reorderMin)})`) }}</div>

      <Tabs v-model="tab" :tabs="tabs" variant="sm" class="!mb-2.5 mt-3" />

      <div v-if="tab === 'profile'" class="rounded-xl border border-line-2">
        <KV :k="{ ar: 'التصنيف', en: 'Category' }" :v="catPath(p.category)" />
        <KV :k="{ ar: 'الوحدة', en: 'UoM' }" :v="p.baseUom ? `${p.baseUom.nameAr} (${p.baseUom.code})` : '—'" />
        <KV :k="{ ar: 'التخزين', en: 'Storage' }"><Chip :map="STORAGE_LABELS" :k="p.storageClass" small /></KV>
        <KV :k="{ ar: 'الأبعاد سم', en: 'Dims cm' }" :v="dims(p)" ltr class="[&_.kv-v]:font-num" />
        <KV :k="{ ar: 'الحجم م³ · الوزن كجم', en: 'Volume m³ · Weight kg' }" :v="`${p.volumeM3 != null ? fmtNum(p.volumeM3, 4) : '—'} · ${fmtNum(p.weightKg, 2)}`" ltr class="[&_.kv-v]:font-num" />
        <KV :k="{ ar: 'وحدات الطبلية', en: 'Units / pallet' }" :v="p.unitsPerPallet != null ? fmtNum(p.unitsPerPallet) : '—'" ltr class="[&_.kv-v]:font-num" />
        <KV :k="{ ar: 'سعر الشراء', en: 'Purchase price' }" :v="p.purchasePrice != null ? `${fmtMoney(p.purchasePrice)} ${t('ر.س', 'SAR')}` : '—'" ltr class="[&_.kv-v]:font-num" />
        <KV :k="{ ar: 'العمر الافتراضي', en: 'Shelf life' }" :v="p.shelfLifeDays != null ? `${fmtNum(p.shelfLifeDays)} ${t('يوم', 'days')}` : '—'" />
        <KV :k="{ ar: 'تتبع الصلاحية', en: 'Tracks expiry' }" :v="p.tracksExpiry ? t('نعم — FEFO', 'Yes — FEFO') : t('لا', 'No')" />
        <KV :k="{ ar: 'حد الطلب / الأقصى', en: 'Reorder min / max' }" :v="`${fmtNum(p.reorderMin)} / ${p.reorderMax != null ? fmtNum(p.reorderMax) : '—'}`" ltr class="[&_.kv-v]:font-num" />
        <KV :k="{ ar: 'المستودع الرئيسي', en: 'Home warehouse' }" :v="p.homeWarehouse ? `${p.homeWarehouse.code} — ${p.homeWarehouse.nameAr}` : '—'" />
        <KV :k="{ ar: 'المورد المفضل', en: 'Preferred supplier' }" :v="preferredSupplier" />
        <KV :k="{ ar: 'آخر تحديث', en: 'Updated' }" :v="fmtDate(p.updatedAt)" ltr class="[&_.kv-v]:font-num" />
      </div>

      <div v-if="tab === 'stock'" class="col !gap-3">
        <div><div class="card-title mb-1.5 !text-[11.5px]">{{ t('حسب المستودع', 'Per warehouse') }}</div><StockPerWarehouse :stock="stock" :loading="stockLoading" /></div>
        <div><div class="card-title mb-1.5 !text-[11.5px]">{{ t('حسب الموقع Bin / الدفعة', 'Per bin / batch') }}</div><StockRows :rows="stock?.rows || []" :loading="stockLoading" /></div>
      </div>

      <div v-if="tab === 'codes'" class="col !gap-3.5">
        <div><div class="card-title !text-[11.5px]">{{ t('الباركود', 'Barcodes') }}</div><BarcodesPanel :product="p" :can-manage="canManage" /></div>
        <div><div class="card-title !text-[11.5px]">{{ t('الموردون', 'Suppliers') }}</div><SuppliersPanel :product="p" :can-manage="canManage" /></div>
      </div>
    </template>

    <template v-if="p" #footer>
      <div class="row wrap">
        <Btn v-if="canManage" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', p)" />
        <template v-if="canManage">
          <Btn v-if="p.active" tone="dangerOutline" :label="{ ar: 'إيقاف الصنف', en: 'Deactivate' }" @click="reasonMode = 'deactivate'" />
          <Btn v-else tone="softGreen" :label="{ ar: 'إعادة التفعيل', en: 'Activate' }" @click="reasonMode = 'activate'" />
        </template>
        <span class="grow" />
        <Btn tone="outline" :label="{ ar: 'الملف الكامل والحركات ←', en: 'Full profile & movements →' }" @click="router.push(`/product/${encodeURIComponent(p.sku)}`)" />
      </div>
    </template>
  </Drawer>

  <ReasonForm v-if="p" :open="!!reasonMode" :title="reasonTitle" :sub="reasonSub" :submit="submitReason" @close="reasonMode = null" />
</template>
