<script setup>
// Bin-to-bin move (MoveSchema): source = a live balance row of the warehouse, destination = an active bin
// (free text when the warehouse has more than 500 active bins). Recorded as a `move` ledger movement.
//   <MoveForm :open="form === 'move'" :warehouse-code="sel" :preset="{ sku, fromBin, batchNo }" @close="…" @done="…" />
import { computed } from 'vue';
import { api, useList } from '@/api/client';
import { FormDrawer } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { serverMsg } from './_shared';
import { MOVE_REASON_LABELS, ZONE_TYPE_LABELS, opts } from './_whForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  warehouseCode: { type: String, required: true },
  /** @type {import('./_whForms').MovePreset} */
  preset: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const enabled = () => props.open && !!props.warehouseCode;
const bal = useList('/inventory/balances', () => ({ warehouse: props.warehouseCode, pageSize: 500 }), { enabled, keepPrevious: false });
const bins = useList('/bins', () => ({ warehouse: props.warehouseCode, status: 'active', pageSize: 500 }), { enabled, keepPrevious: false });
const rows = computed(() => (bal.data.value?.items || []).filter((r) => r.onHand > 0));
const rowKey = (r) => `${r.bin}|${r.sku}|${r.batch || ''}`;

const initial = computed(() => {
  const ps = props.preset;
  let hit;
  if (ps?.sku) hit = rows.value.find((r) => r.sku === ps.sku && r.bin === ps.fromBin && (ps.batchNo ? r.batch === ps.batchNo : true));
  else if (ps?.fromBin) { const inBin = rows.value.filter((r) => r.bin === ps.fromBin); if (inBin.length === 1) hit = inBin[0]; } // "Move" on a bin that holds a single balance
  return { source: hit ? rowKey(hit) : ps?.fromBin && ps.sku ? `${ps.fromBin}|${ps.sku}|${ps.batchNo || ''}` : '', toBin: ps?.toBin || '', reason: 'slot' };
});
const srcOpts = computed(() => rows.value.map((r) => ({ v: rowKey(r), l: `${r.bin} · ${r.sku} · ${r.nameAr}${r.batch ? ` · ${r.batch}` : ''} · ${t('متاح', 'avail.')} ${fmtNum(r.available)}` })));
const binOpts = computed(() => (bins.data.value?.items || []).map((b) => ({ v: b.code, l: `${b.code} · ${bi(ZONE_TYPE_LABELS[b.zone.type] || { ar: b.zone.type, en: b.zone.type })}${b.fixedProduct ? ` · ${b.fixedProduct.sku}` : ''}` })));
const tooManyBins = computed(() => (bins.data.value?.total || 0) > (bins.data.value?.items?.length || 0));

const fields = computed(() => [
  { k: 'source', label: { ar: 'من الموقع (رصيد حي)', en: 'Source (live balance)' }, type: 'select', opts: srcOpts.value, required: true, full: true,
    hint: bal.isLoading.value ? { ar: 'جارٍ تحميل الأرصدة…', en: 'Loading balances…' } : rows.value.length ? undefined : { ar: 'لا رصيد في هذا المستودع', en: 'No stock in this warehouse' } },
  { k: 'qty', label: { ar: 'الكمية', en: 'Quantity' }, type: 'num', min: 1, required: true,
    validate: (x, v) => { const r = rows.value.find((y) => rowKey(y) === v.source); return r && Number(x) > r.available ? t(`الكمية تتجاوز المتاح (${fmtNum(r.available)}) — المحجوز لا يُنقل`, `Exceeds available (${fmtNum(r.available)})`) : null; } },
  tooManyBins.value
    ? { k: 'toBin', label: { ar: 'إلى الموقع', en: 'Destination bin' }, required: true, dir: 'ltr', ph: 'A-01-1-B1' }
    : { k: 'toBin', label: { ar: 'إلى الموقع', en: 'Destination bin' }, type: 'select', opts: binOpts.value, required: true },
  { k: 'reason', label: { ar: 'السبب', en: 'Reason' }, type: 'select', opts: opts(MOVE_REASON_LABELS), def: 'slot', full: true, hint: { ar: 'الحجر والتالف يجب أن يذهبا إلى منطقة الحجر/التالف · المبرد لا يخرج من سلسلة التبريد', en: 'Quarantine / damage go to their zones · cold chain rules apply' } },
]);

function submit(v) {
  const [fromBin, sku, batchNo] = String(v.source).split('|');
  return api.postIdempotent('/inventory/move', { sku, warehouseCode: props.warehouseCode, fromBin, toBin: String(v.toBin).trim().toUpperCase(), batchNo: batchNo || undefined, qty: Number(v.qty), reason: v.reason || 'slot' });
}

// A preset source can only be shown once the balances (the select's options) are there: the drawer is re-keyed when
// they arrive so it re-reads `initial` — same as the reference, which resets the form when its options load.
const formKey = computed(() => (props.preset && bal.isLoading.value ? 'loading' : 'ready'));
</script>

<template>
  <FormDrawer :key="formKey" :open="open" :title="{ ar: 'نقل مخزون بين مواقع (Bin-to-Bin)', en: 'Bin-to-bin move' }"
              :sub="{ ar: `المستودع ${warehouseCode} · يُسجل كحركة move في سجل المخزون`, en: `Warehouse ${warehouseCode} · recorded as a ledger movement` }"
              :fields="fields" :initial="initial" :submit="submit"
              :action="{ success: (d) => serverMsg(d, { ar: `نُقلت الكمية (${d?.number || ''})`, en: `Moved (${d?.number || ''})` }), invalidate: ['inventory', 'bins', 'warehouses'] }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
