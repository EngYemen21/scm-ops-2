<script setup>
// One putaway task: product, suggested bin, scan bin + scan SKU, confirm. `big` = worker (touch) sizing.
// The server rejects a wrong / quarantined bin or a different product (and raises a wrong-location exception) — the
// rejection shows in the ErrorBanner under the row and the cursor goes back to the bin scan field.
// A task can be put away in parts: type a quantity smaller than what is left and it goes into the scanned bin while
// the task stays open for the rest (a pallet that does not fit one location). Empty quantity = everything that is left.
import { computed, nextTick, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, ScanInput } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { parseQty, pn } from './shared';

const props = defineProps({
  task: { type: Object, required: true },
  big: { type: Boolean, default: false },
});
const emit = defineEmits(['done']);

const auth = useAuth();
const act = useAction({ invalidate: ['inbound', 'inventory', 'dashboard'] });
const bin = ref('');
const sku = ref('');
/** Partial quantity (null = the whole remainder). */
const part = ref(null);
const binInput = ref(null);
const skuInput = ref(null);

const doneQty = computed(() => Number(props.task.doneQty) || 0);
const left = computed(() => Math.max(0, props.task.qty - doneQty.value));
/** A typed quantity must be 1 … what is left of the task. */
const partBad = computed(() => part.value != null && (part.value < 1 || part.value > left.value));
function onPart(e) { const n = parseQty(e.target.value); e.target.value = n ?? ''; part.value = n; }

async function confirmPutaway() {
  const task = props.task;
  /** @type {{ task: string, bin: string, movement: string, qty: number, doneQty: number, remaining: number, taskDone: boolean, shipmentDone: boolean|null }|undefined} */
  const r = await act.run(() => api.postIdempotent(`/inbound/putaway/${task.number}/confirm`, { scannedBin: bin.value.trim() || undefined, scannedProduct: sku.value.trim() || undefined, qty: part.value || undefined }), {
    success: (x) => (x.taskDone === false
      ? t(`خُزّن ${fmtNum(x.qty)} من ${pn(task.product, 'ar')} في ${x.bin} — المتبقي من المهمة ${fmtNum(x.remaining)} · حركة ${x.movement}`, `${x.qty} × ${task.product.sku} stored in ${x.bin} — ${x.remaining} left on the task — ${x.movement}`)
      : t(`تم تخزين ${pn(task.product, 'ar')} × ${fmtNum(x.qty ?? task.qty)} في ${x.bin} — حركة ${x.movement}${x.shipmentDone ? ' · اكتملت الشحنة ✓' : ''}`, `${x.qty ?? task.qty} × ${task.product.sku} stored in ${x.bin} — ${x.movement}`)),
  });
  if (r) {
    emit('done', r);
    // a part was stored: the row stays for the rest — next bin, same product
    if (r.taskDone === false) { bin.value = ''; part.value = null; nextTick(() => binInput.value?.focus()); }
  } else {
    // The server rejected the scan (wrong bin / product, quarantined bin) → clear the codes so the next scan starts clean.
    if (['BUSINESS_RULE', 'CONFLICT', 'VALIDATION', 'NOT_FOUND'].includes(act.error.value?.category)) { bin.value = ''; sku.value = ''; }
    nextTick(() => binInput.value?.focus());
  }
}
const onBinScan = (c) => { bin.value = c; nextTick(() => skuInput.value?.focus()); };
const onSkuScan = (c) => { sku.value = c; };
</script>

<template>
  <div class="border-t border-line-2" :class="big ? 'px-[18px] py-3' : 'px-[18px] py-[11px]'">
    <div class="row wrap !gap-3">
      <div class="min-w-[170px]" :class="{ 'flex-1': big }">
        <div class="font-extrabold" :class="big ? 'text-[11.5px]' : 'text-[11px]'">{{ pn(task.product) }}</div>
        <div class="num mt-px text-[8.5px] text-faint">{{ fmtNum(task.qty) }} × {{ task.product.sku }} · {{ task.batchNo || '—' }}{{ task.expiryDate ? ` · ${fmtDateOnly(task.expiryDate)}` : '' }}{{ task.grn ? ` · ${task.grn.number}` : '' }}</div>
        <div v-if="task.status === 'open' && doneQty > 0" class="mt-0.5 text-[9px] font-extrabold text-warn">{{ t(`خُزّن ${fmtNum(doneQty)} — المتبقي ${fmtNum(left)}`, `${fmtNum(doneQty)} stored — ${fmtNum(left)} left`) }}</div>
      </div>
      <div class="num min-w-[90px]" :class="big ? 'text-[14px] text-violet' : 'text-[10.5px] text-brand-dark'" :title="t('الموقع المقترح', 'Suggested bin')">{{ task.suggestedBin?.code || '—' }}</div>
      <div v-if="!big" class="min-w-[180px] flex-1 text-[9.5px] leading-[1.7] text-muted">
        {{ lang === 'ar' ? task.suggestionAr : task.suggestionEn }}<span v-if="task.suggestedBin?.zone" class="num"> · {{ task.suggestedBin.zone.code }} ({{ task.suggestedBin.zone.type }})</span>
      </div>

      <template v-if="task.status === 'open' && auth.can('putaway.confirm')">
        <div class="row !gap-1.5">
          <div :class="big ? 'w-[130px]' : 'w-[118px]'"><ScanInput ref="binInput" v-model="bin" :small="!big" :clear-on-submit="false" placeholder="Scan Bin" :class="big ? '!h-10' : '!h-[30px]'" @submit="onBinScan" /></div>
          <div :class="big ? 'w-[120px]' : 'w-[110px]'"><ScanInput ref="skuInput" v-model="sku" :small="!big" :clear-on-submit="false" placeholder="Scan SKU" :class="big ? '!h-10' : '!h-[30px]'" @submit="onSkuScan" /></div>
          <input :value="part ?? ''" dir="ltr" inputmode="numeric" class="inp num text-center" :class="[big ? '!h-10 !w-[84px]' : 'sm !h-[30px] !w-[72px] !text-[10.5px]', { '!border-[#F3C4C4]': partBad }]"
                 :placeholder="fmtNum(left)" :title="t(`كمية جزئية لهذا الموقع (1 – ${left}). فارغ = كل المتبقي`, `Partial quantity for this bin (1 – ${left}). Empty = everything left`)" @input="onPart">
        </div>
        <Btn :tone="big ? 'purple' : 'success'" :class="big ? '!h-11 !text-[12px]' : '!h-[34px] !text-[10px]'" :loading="act.pending.value" :disabled="partBad"
             :title="partBad ? t(`الكمية يجب أن تكون بين 1 و${left}`, `The quantity must be between 1 and ${left}`) : null"
             :label="part && !partBad && part < left ? { ar: `تخزين ${part} في هذا الموقع`, en: `Store ${part} in this bin` } : big ? { ar: 'Scan الموقع وتأكيد', en: 'Scan bin & confirm' } : { ar: 'Scan وتأكيد التخزين', en: 'Scan & confirm' }" @click="confirmPutaway" />
      </template>
      <Chip v-else-if="task.status === 'done'" fg="#1d7a3e" bg="#e6f9ec" :label="`${t('مخزّن ✓', 'Stored ✓')} ${task.actualBin?.code || ''}`" :title="task.confirmedBy ? `${task.confirmedBy} · ${fmtDate(task.confirmedAt)}` : null" />
      <Chip v-else-if="task.status === 'cancelled'" fg="#7d7990" bg="#F1EFF6" :label="{ ar: 'ملغاة — أُلغي إذن الاستلام', en: 'Cancelled — receipt voided' }" />
    </div>
    <div v-if="partBad" class="field-err mt-1">{{ t(`الكمية يجب أن تكون بين 1 و${fmtNum(left)} — المتبقي من المهمة`, `The quantity must be between 1 and ${fmtNum(left)} — what is left of the task`) }}</div>
    <ErrorBanner :error="act.error.value" class="mt-2 !mb-0" @close="act.clearError()" />
  </div>
</template>
