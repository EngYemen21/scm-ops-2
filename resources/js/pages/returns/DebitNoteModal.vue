<script setup>
// Issue the supplier debit note of goods that already went back to a supplier — after its note was cancelled (the way a
// wrong price is corrected), or for a return left without one. Per line: the quantity sent and the unit price to claim
// (empty = the purchase-order price, else the cost the stock left at).
// POST /api/returns/:number/debit-note { lines?: [{ lineNo, unitPrice }] }. Mount with v-if.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import { pname } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const act = useAction();
/** Price each line carried on the last note (cancelled by now) — the starting point of the correction. */
const lastPrice = (lineNo) => { const notes = props.ret.debitNotes || []; for (let i = notes.length - 1; i >= 0; i--) { const l = (notes[i].lines || []).find((x) => x.lineNo === lineNo); if (l) return l.price; } return null; };
const rows = ref(Object.values((props.ret.decisions || []).filter((d) => d.decision === 'sup' && d.movementId && d.lineNo).reduce((acc, d) => {
  const line = (props.ret.lines || []).find((l) => l.lineNo === d.lineNo);
  acc[d.lineNo] ||= { lineNo: d.lineNo, name: pname(line?.product), sku: line?.product?.sku || d.sku, qty: 0, supplier: d.supplier, unitPrice: line?.unitPrice != null ? Number(line.unitPrice) : lastPrice(d.lineNo) };
  acc[d.lineNo].qty += d.qty;
  return acc;
}, {})));
const bad = (r) => r.unitPrice != null && r.unitPrice !== '' && !(Number(r.unitPrice) >= 0);
const net = computed(() => rows.value.reduce((a, r) => a + r.qty * (Number(r.unitPrice) || 0), 0));
const allPriced = computed(() => rows.value.every((r) => r.unitPrice != null && r.unitPrice !== ''));
const blocker = computed(() => (rows.value.some(bad) ? { ar: 'السعر لا يكون سالبًا', en: 'A price cannot be negative' } : null));

async function submit() {
  if (blocker.value) return;
  const lines = rows.value.filter((r) => r.unitPrice != null && r.unitPrice !== '').map((r) => ({ lineNo: r.lineNo, unitPrice: Number(r.unitPrice) }));
  const r = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(props.ret.number)}/debit-note`, lines.length ? { lines } : undefined), {
    success: (x) => x?.message || t('صدر إشعار المدين', 'Debit note issued'), invalidate: ['returns', 'finance', 'dashboard'],
  });
  if (r !== undefined) { emit('close'); emit('done'); }
}
const GRID = 'grid grid-cols-[minmax(150px,1.6fr)_70px_110px] items-center gap-2';
</script>

<template>
  <Modal open :width="560" @close="emit('close')">
    <template #title>
      {{ t('إصدار إشعار مدين على المورد', 'Issue supplier debit note') }}
      <div class="drawer-sub"><span class="num">{{ ret.number }}</span> · {{ t('عن الكمية التي خرجت للمورد', 'for the quantity sent to the supplier') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div :class="GRID" class="le-head px-0.5 pb-1 text-[9px] font-extrabold text-faint"><div>{{ t('الصنف', 'Item') }}</div><div class="text-center">{{ t('الكمية', 'Qty') }}</div><div class="text-center">{{ t('سعر الوحدة', 'Unit price') }}</div></div>
    <div v-for="r in rows" :key="r.lineNo" :class="GRID" class="le-row border-t border-[#F7F6FA] py-1.5">
      <div class="min-w-0"><div class="ellipsis text-[11px] font-extrabold">{{ r.name }}</div><div class="cell-sub num">{{ r.sku }}<template v-if="r.supplier"> · {{ r.supplier.code }}</template></div></div>
      <div class="num text-center text-[11px]" :data-label="t('الكمية', 'Qty')">{{ fmtNum(r.qty) }}</div>
      <NumberInput v-model="r.unitPrice" small center :min="0" :placeholder="{ ar: 'سعر أمر الشراء', en: 'PO price' }" :error="bad(r) ? t('غير صالح', 'Invalid') : null" />
    </div>
    <div v-if="!rows.length" class="empty !p-3.5">{{ t('لا كمية مُرجعة لمورد على هذا المرتجع', 'Nothing on this return went back to a supplier') }}</div>
    <div v-if="rows.length && allPriced" class="mt-2 text-[10.5px] font-extrabold">{{ t('الإجمالي قبل الضريبة', 'Net total') }}: <span class="num">{{ fmtMoney(net) }}</span></div>
    <div class="hint teal mt-2.5">{{ t('السعر الفارغ = سعر أمر الشراء (وإن لم يوجد فتكلفة خروج المخزون). تُضاف ضريبة أمر الشراء. البضاعة خرجت من المخزون مسبقًا — الإشعار يسجّل المطالبة المالية فقط فينخفض مستحق المورد بإجماليه.', 'An empty price = the purchase-order price (else the cost the stock left at). The VAT of the order is added. The goods already left stock — the note only records the financial claim, lowering the payable by its total.') }}</div>

    <template #footer>
      <div v-if="blocker" class="mb-1.5 text-[10px] font-bold text-bad">{{ t(blocker.ar, blocker.en) }}</div>
      <div class="row">
        <Btn tone="purple" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!blocker || !rows.length" :label="{ ar: 'إصدار الإشعار', en: 'Issue the note' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
