<script setup>
// Receipt of an approved return: what really arrived per line (0 … requested, default everything).
// POST /api/returns/:number/receive { lines: [{ lineNo, receivedQty }], notes? } — only the received quantity enters RET-01;
// a shortfall raises an exception. Mount with v-if so every opening starts clean.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, TextArea } from '@/components';
import { fmtNum, t } from '@/i18n';
import { pname } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const act = useAction();
const rows = ref((props.ret.lines || []).map((l) => ({ lineNo: l.lineNo, name: pname(l.product), sku: l.product?.sku, batchNo: l.batchNo || l.batch?.batchNo || null, qty: l.qty, receivedQty: l.qty })));
const notes = ref('');
const bad = (r) => !(Number.isInteger(r.receivedQty) && r.receivedQty >= 0 && r.receivedQty <= r.qty);
const total = computed(() => rows.value.reduce((a, r) => a + (Number(r.receivedQty) || 0), 0));
const requested = computed(() => rows.value.reduce((a, r) => a + r.qty, 0));
const short = computed(() => requested.value - total.value);
/** Why the button is disabled ({ar,en}) — null when the receipt can be posted. */
const blocker = computed(() => {
  if (rows.value.some(bad)) return { ar: 'كمية غير صالحة: بين صفر والكمية المطلوبة', en: 'Invalid quantity: between 0 and the requested quantity' };
  if (total.value === 0) return { ar: 'لم يُستلم شيء — ألغِ المرتجع بدل استلام صفر', en: 'Nothing received — cancel the return instead' };
  return null;
});
const credit = computed(() => props.ret.flow?.credit);

async function submit() {
  if (blocker.value) return;
  const body = { lines: rows.value.map((r) => ({ lineNo: r.lineNo, receivedQty: r.receivedQty })), notes: notes.value.trim() || undefined };
  const r = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(props.ret.number)}/receive`, body), {
    success: (x) => x?.message || (short.value > 0 ? t('استُلم جزئيًا — سُجّل النقص كاستثناء', 'Partially received — the shortfall was raised') : t('استُلم المرتجع — بانتظار الفحص', 'Received — awaiting inspection')),
    invalidate: ['returns', 'inventory', 'dashboard', 'finance', 'exceptions'],
  });
  if (r !== undefined) { emit('close'); emit('done'); }
}
const GRID = 'grid grid-cols-[minmax(150px,1.6fr)_70px_100px] items-center gap-2';
</script>

<template>
  <Modal open :width="560" @close="emit('close')">
    <template #title>
      {{ t('استلام المرتجع', 'Receive return') }}
      <div class="drawer-sub"><span class="num">{{ ret.number }}</span> · {{ t('سجّل ما وصل فعلًا', 'record what actually arrived') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div :class="GRID" class="le-head px-0.5 pb-1 text-[9px] font-extrabold text-faint"><div>{{ t('الصنف', 'Item') }}</div><div class="text-center">{{ t('المطلوب', 'Requested') }}</div><div class="text-center">{{ t('المستلم', 'Received') }}</div></div>
    <div v-for="r in rows" :key="r.lineNo" :class="GRID" class="le-row border-t border-[#F7F6FA] py-1.5">
      <div class="min-w-0"><div class="ellipsis text-[11px] font-extrabold">{{ r.name }}</div><div class="cell-sub num">{{ r.sku }}<template v-if="r.batchNo"> · {{ r.batchNo }}</template></div></div>
      <div class="num text-center text-[11px]" :data-label="t('المطلوب', 'Requested')">{{ fmtNum(r.qty) }}</div>
      <NumberInput v-model="r.receivedQty" small center :min="0" :max="r.qty" :step="1" :error="bad(r) ? t(`0 – ${r.qty}`, `0 – ${r.qty}`) : null" />
    </div>
    <div class="row mt-2 text-[10.5px] font-extrabold">
      <span>{{ t('الإجمالي', 'Total') }}: <span class="num">{{ fmtNum(total) }}</span> / <span class="num">{{ fmtNum(requested) }}</span></span>
      <span v-if="short > 0" class="text-warn">{{ t(`ناقص ${fmtNum(short)}`, `${fmtNum(short)} short`) }}</span>
    </div>
    <div class="form-grid mt-2"><TextArea v-model="notes" :label="{ ar: 'ملاحظة الاستلام (اختياري)', en: 'Receipt note (optional)' }" full /></div>

    <div v-if="short > 0" class="hint amber mt-2.5">{{ t('الكمية الناقصة لا تدخل منطقة المرتجعات: يُفتح بها استثناء للمتابعة، والسطر الذي لم يصل منه شيء يُقفل كغير مستلم.', 'The missing quantity does not enter the returns area: an exception is raised for it, and a line with nothing received is closed as not received.') }}</div>
    <div v-if="credit === 'expected'" class="hint teal mt-2">{{ t('مرتجع مرتبط بتسليم مفوتر: عند الاستلام تُعكس تكلفة المبيعات ويصدر إشعار دائن للعميل بالكمية المستلمة فقط.', 'Linked to an invoiced delivery: on receipt the cost of sales is reversed and a credit note is issued for the received quantity only.') }}</div>
    <div v-else-if="credit === 'none'" class="hint amber mt-2">{{ t('لا مرجع تسليم على هذا المرتجع: يُستلم بمتوسط التكلفة ولن يصدر إشعار دائن.', 'No delivery reference on this return: it is received at average cost and no credit note will be issued.') }}</div>
    <div v-else-if="credit === 'undelivered'" class="hint teal mt-2">{{ t('بضاعة لم تُسلَّم للعميل ولم تُفوتَر: تعود من «بضاعة في الطريق» بتكلفة تحميلها، بلا إشعار دائن.', 'Goods never delivered or invoiced: they come back from goods in transit at their load cost, without a credit note.') }}</div>
    <div class="hint teal mt-2">{{ t('تدخل الكمية المستلمة منطقة المرتجعات RET-01 (غير متاحة للبيع) بانتظار الفحص ثم القرار.', 'The received quantity enters the returns area RET-01 (not available) pending inspection and a decision.') }}</div>

    <template #footer>
      <div v-if="blocker" class="mb-1.5 text-[10px] font-bold text-bad">{{ t(blocker.ar, blocker.en) }}</div>
      <div class="row">
        <Btn tone="dark" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!blocker" :label="{ ar: 'تأكيد الاستلام', en: 'Confirm receipt' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
