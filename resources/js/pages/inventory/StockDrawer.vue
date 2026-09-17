<script setup>
// Stock balance drawer: product stock across warehouses, bins with allocations, recent movements and the manual
// actions (adjust / quarantine toggle / bin move) gated by permission.
//   <StockDrawer :focus="focus" @close="focus = null" />        focus = { sku, warehouse?, bin?, batch? } | null
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, t } from '@/i18n';
import { MOVEMENT_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import AdjustForm from './AdjustForm.vue';
import DaysChip from './DaysChip.vue';
import FlagChips from './FlagChips.vue';
import Hint from './Hint.vue';
import MoveForm from './MoveForm.vue';
import QuarantineForm from './QuarantineForm.vue';
import Tile from './Tile.vue';
import { BALANCE_STATUS, fmtSigned, locLabel, movementDoc, pname, signColor } from './shared';

const props = defineProps({
  /** StockFocus | null — null closes the drawer. */
  focus: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const router = useRouter();
/** Row picked inside the drawer ({ warehouse?, bin?, batch? }); overrides the focus the drawer was opened on. */
const pick = ref(null);
/** 'adjust' | 'move' | 'qtn' | null */
const form = ref(null);
watch(() => props.focus, () => { pick.value = null; });

const sku = computed(() => props.focus?.sku || null);
/** { product, totalAvailable, perWarehouse: [{ code, nameAr, onHand, reserved, available, quarantine }], rows: BalanceRow[] } */
const stock = useGet(() => (sku.value ? `/inventory/products/${encodeURIComponent(sku.value)}/stock` : null));
const recent = useList('/inventory/ledger', () => ({ sku: sku.value, pageSize: 8 }), { enabled: () => !!sku.value });

const sel = computed(() => pick.value ?? props.focus);
const row = computed(() => {
  const s = sel.value;
  return stock.data.value?.rows.find((r) => (!s?.warehouse || r.warehouse === s.warehouse) && (!s?.bin || r.bin === s.bin) && (s?.batch === undefined || (r.batch || null) === (s.batch || null)));
});
const totals = computed(() => {
  const rows = stock.data.value?.rows || [];
  const sum = (k) => rows.reduce((a, r) => a + r[k], 0);
  return { onHand: sum('onHand'), reserved: sum('reserved'), allocated: sum('allocated'), available: stock.data.value?.totalAvailable ?? 0 };
});
/** StockRef handed to the forms. */
const stockRef = computed(() => ({ sku: sku.value || undefined, warehouseCode: row.value?.warehouse || sel.value?.warehouse, binCode: row.value?.bin || sel.value?.bin, batchNo: row.value?.batch ?? sel.value?.batch ?? undefined, quarantine: row.value?.quarantine }));
const p = computed(() => stock.data.value?.product);
const subLine = computed(() => `${sku.value || ''}${row.value ? ` · ${row.value.warehouse}/${row.value.bin}${row.value.batch ? ` · ${row.value.batch}` : ''}` : ''}${p.value ? ` · ${p.value.storageClass}` : ''}`);

const binCols = [
  { key: 'loc', header: { ar: 'المستودع / الموقع', en: 'WH / Bin' }, width: '1.2fr' },
  { key: 'batch', header: { ar: 'الدفعة', en: 'Batch' }, width: '90px' },
  { key: 'onHand', header: { ar: 'فعلي', en: 'On hand' }, width: '58px', kind: 'num' },
  { key: 'reserved', header: { ar: 'محجوز', en: 'Res.' }, width: '58px', kind: 'num' },
  { key: 'allocated', header: { ar: 'مخصص', en: 'Alloc.' }, width: '58px', kind: 'num' },
  { key: 'available', header: { ar: 'متاح', en: 'Avail.' }, width: '58px', kind: 'num' },
];
</script>

<template>
  <Drawer :open="!!focus" :width="520" :title="p ? pname(p) : sku || ''" @close="emit('close')">
    <template #sub><span class="num ltr inline-block">{{ subLine }}</span></template>
    <template v-if="p" #headExtra>
      <Btn tone="ghost" size="sm" :label="{ ar: 'بطاقة المنتج', en: 'Product' }" @click="router.push(`/product/${encodeURIComponent(p.sku)}`)" />
    </template>

    <ErrorBanner :error="stock.error.value" :closable="false" />
    <div v-if="stock.isLoading.value" class="skel h-[60px]" />
    <template v-if="stock.data.value">
      <div class="grid grid-cols-3 gap-2">
        <Tile :value="row ? row.onHand : totals.onHand" :label="{ ar: 'فعلي On Hand', en: 'On hand' }" />
        <Tile :value="row ? row.reserved : totals.reserved" :label="{ ar: 'محجوز', en: 'Reserved' }" tone="amber" />
        <Tile :value="row ? row.available : totals.available" :label="{ ar: 'متاح', en: 'Available' }" tone="green" />
      </div>
      <div v-if="row" class="row wrap mt-2 !gap-1.5">
        <FlagChips :row="row" />
        <span v-if="row.blocked" class="muted text-[9px]">{{ t('الصف مجمد لجرد جارٍ — التسوية عبر اعتماد الجرد', 'Row frozen by an open count — adjust via the count') }}</span>
      </div>
      <div v-else class="muted mt-1.5 text-[9.5px]">{{ t('إجمالي المنتج في كل المستودعات — اختر موقعًا أدناه للإجراءات', 'Product totals across warehouses — pick a bin below for actions') }}</div>

      <div class="row wrap mt-3.5 !gap-[7px]">
        <Btn v-if="auth.can('inventory.adjust')" tone="dark" size="sm" :label="{ ar: 'تسوية رصيد', en: 'Adjust' }" @click="form = 'adjust'" />
        <Btn v-if="auth.can('inventory.move')" tone="softPurple" size="sm" :label="{ ar: 'نقل بين مواقع', en: 'Bin move' }" @click="form = 'move'" />
        <Btn v-if="auth.can('inventory.adjust') && row" :tone="row.quarantine ? 'softGreen' : 'softRed'" size="sm" :label="row.quarantine ? { ar: 'رفع الحجر', en: 'Release quarantine' } : { ar: 'حجر', en: 'Quarantine' }" @click="form = 'qtn'" />
        <Btn v-if="auth.can('inventory.transfer')" tone="outline" size="sm" :label="{ ar: 'طلب تحويل', en: 'Request transfer' }" @click="router.push('/returns')" />
        <Btn v-if="auth.can('pr.create') && p" tone="outline" size="sm" :label="{ ar: 'اقتراح شراء', en: 'Suggest purchase' }" @click="router.push(`/procurement?q=${encodeURIComponent(p.sku)}`)" />
        <Btn v-if="auth.can('inventory.count')" tone="outline" size="sm" :label="{ ar: 'جرد موضعي', en: 'Spot count' }" @click="router.push('/counts')" />
      </div>
      <div v-if="p && (p.reorderMin != null || p.reorderMax != null)" class="muted mt-2 text-[9.5px]">
        {{ t('حد إعادة الطلب', 'Reorder') }}: <b class="num">{{ fmtNum(p.reorderMin) }}</b> – <b class="num">{{ fmtNum(p.reorderMax) }}</b>
        <Chip v-if="totals.available < (p.reorderMin || 0)" small class="ms-1.5" :label="{ ar: 'تحت الحد الأدنى', en: 'Below min' }" fg="#b23b3b" bg="#fdecec" />
      </div>

      <div class="mx-0.5 mb-2 mt-[18px] text-[11px] font-extrabold text-muted">{{ t('الرصيد حسب المستودع', 'Stock per warehouse') }}</div>
      <div class="col !gap-1.5">
        <div v-for="w in stock.data.value.perWarehouse" :key="w.code" class="row cursor-pointer rounded-xl border border-line-2 px-3 py-2 !gap-2.5" :class="{ 'bg-soft': sel?.warehouse === w.code && !sel?.bin }" @click="pick = { warehouse: w.code }">
          <div class="grow"><div class="text-[10.5px] font-extrabold">{{ w.code }}</div><div class="muted text-[9px]">{{ pname(w) }}</div></div>
          <div class="num text-[10.5px]">{{ fmtNum(w.onHand) }}</div>
          <div class="num text-[10.5px] text-warn">{{ fmtNum(w.reserved) }}</div>
          <div class="num text-[10.5px] text-ok">{{ fmtNum(w.available) }}</div>
          <Chip v-if="w.quarantine > 0" small :map="BALANCE_STATUS" k="quarantine" :label="fmtNum(w.quarantine)" />
        </div>
      </div>

      <div class="mx-0.5 mb-2 mt-[18px] text-[11px] font-extrabold text-muted">{{ t('المواقع والتخصيصات', 'Bins & allocations') }} <span class="card-count num">{{ stock.data.value.rows.length }}</span></div>
      <DataTable :columns="binCols" :rows="stock.data.value.rows" dense :min-width="440" :row-key="(r) => r.id" :selected-key="row?.id ?? null" :empty-text="{ ar: 'لا أرصدة لهذا المنتج', en: 'No stock rows' }"
                 @row-click="(r) => (pick = { warehouse: r.warehouse, bin: r.bin, batch: r.batch })">
        <template #cell-loc="{ row: r }"><div><span class="cell-id">{{ r.warehouse }}/{{ r.bin }}</span><div class="cell-sub">{{ r.zone }}{{ r.rack ? ` · ${r.rack}` : '' }}</div></div></template>
        <template #cell-batch="{ row: r }"><div><span class="cell-id !text-[9.5px]">{{ r.batch || '—' }}</span><div v-if="r.expiry" class="cell-sub"><DaysChip :days="r.daysToExpiry" /></div></div></template>
        <template #cell-reserved="{ row: r }"><span class="text-warn">{{ fmtNum(r.reserved) }}</span></template>
        <template #cell-allocated="{ row: r }"><span class="text-brand-dark">{{ fmtNum(r.allocated) }}</span></template>
        <template #cell-available="{ row: r }"><span :class="r.available > 0 ? 'text-ok' : 'text-bad'">{{ fmtNum(r.available) }}</span></template>
      </DataTable>

      <div class="mx-0.5 mb-2 mt-[18px] text-[11px] font-extrabold text-muted">{{ t('آخر الحركات على هذا المنتج', 'Recent movements') }}</div>
      <div class="col !gap-[7px]">
        <div v-for="m in recent.data.value?.items || []" :key="m.id" class="row cursor-pointer rounded-xl border border-line-2 px-[13px] py-[9px] !gap-[9px]" @click="router.push(`/ledger?q=${encodeURIComponent(m.number)}`)">
          <Chip small :map="MOVEMENT_LABELS" :k="m.type" />
          <div class="grow">
            <div class="text-[10px] font-bold text-sec">{{ movementDoc(m) }} <span class="muted ltr inline-block text-[8.5px]">{{ locLabel(m.src) }} → {{ locLabel(m.dst) }}</span></div>
            <div class="mt-px text-[8px] text-faint">{{ m.username || '—' }} · <span class="num">{{ fmtDate(m.createdAt) }}</span></div>
          </div>
          <div class="num ltr text-[12px]" :style="{ color: signColor(m.signedQty) }">{{ fmtSigned(m.signedQty) }}</div>
        </div>
        <div v-if="recent.data.value && recent.data.value.items.length === 0" class="empty !p-3">{{ t('لا حركات بعد', 'No movements yet') }}</div>
        <Btn v-if="recent.data.value && recent.data.value.total > 8" tone="ghost" size="sm" :label="{ ar: `كل الحركات (${fmtNum(recent.data.value.total)}) ←`, en: `All movements (${fmtNum(recent.data.value.total)}) →` }" @click="router.push(`/ledger?sku=${encodeURIComponent(sku)}`)" />
      </div>
      <Hint v-if="p?.tracksExpiry && row?.expiry" tone="teal" class="!mt-3">{{ t('الصلاحية', 'Expiry') }}: <b class="num">{{ fmtDateOnly(row.expiry) }}</b> · FEFO — {{ t('الأقرب انتهاءً يُصرف أولًا', 'earliest expiry ships first') }}</Hint>
    </template>
  </Drawer>

  <AdjustForm :open="form === 'adjust'" :initial="stockRef" @close="form = null" />
  <MoveForm :open="form === 'move'" :initial="stockRef" @close="form = null" />
  <QuarantineForm :open="form === 'qtn'" :initial="stockRef" @close="form = null" />
</template>
