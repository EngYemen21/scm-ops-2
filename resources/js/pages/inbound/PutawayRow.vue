<script setup>
// One putaway task: product, suggested bin, scan bin + scan SKU, confirm. `big` = worker (touch) sizing.
// The server rejects a wrong / quarantined bin or a different product (and raises a wrong-location exception) — the
// rejection shows in the ErrorBanner under the row and the cursor goes back to the bin scan field.
import { nextTick, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, ScanInput } from '@/components';
import { fmtDate, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { pn } from './shared';

const props = defineProps({
  task: { type: Object, required: true },
  big: { type: Boolean, default: false },
});
const emit = defineEmits(['done']);

const auth = useAuth();
const act = useAction({ invalidate: ['inbound', 'inventory', 'dashboard'] });
const bin = ref('');
const sku = ref('');
const binInput = ref(null);
const skuInput = ref(null);

async function confirmPutaway() {
  const task = props.task;
  /** @type {{ task: string, bin: string, movement: string, shipmentDone: boolean|null }|undefined} */
  const r = await act.run(() => api.postIdempotent(`/inbound/putaway/${task.number}/confirm`, { scannedBin: bin.value.trim() || undefined, scannedProduct: sku.value.trim() || undefined }), {
    success: (x) => t(`تم تخزين ${pn(task.product, 'ar')} × ${fmtNum(task.qty)} في ${x.bin} — حركة ${x.movement}${x.shipmentDone ? ' · اكتملت الشحنة ✓' : ''}`, `${task.qty} × ${task.product.sku} stored in ${x.bin} — ${x.movement}`),
  });
  if (r) emit('done', r);
  else {
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
      </div>
      <div class="num min-w-[90px]" :class="big ? 'text-[14px] text-violet' : 'text-[10.5px] text-brand-dark'" :title="t('الموقع المقترح', 'Suggested bin')">{{ task.suggestedBin?.code || '—' }}</div>
      <div v-if="!big" class="min-w-[180px] flex-1 text-[9.5px] leading-[1.7] text-muted">
        {{ lang === 'ar' ? task.suggestionAr : task.suggestionEn }}<span v-if="task.suggestedBin?.zone" class="num"> · {{ task.suggestedBin.zone.code }} ({{ task.suggestedBin.zone.type }})</span>
      </div>

      <template v-if="task.status === 'open' && auth.can('putaway.confirm')">
        <div class="row !gap-1.5">
          <div :class="big ? 'w-[130px]' : 'w-[118px]'"><ScanInput ref="binInput" v-model="bin" :small="!big" :clear-on-submit="false" placeholder="Scan Bin" :class="big ? '!h-10' : '!h-[30px]'" @submit="onBinScan" /></div>
          <div :class="big ? 'w-[120px]' : 'w-[110px]'"><ScanInput ref="skuInput" v-model="sku" :small="!big" :clear-on-submit="false" placeholder="Scan SKU" :class="big ? '!h-10' : '!h-[30px]'" @submit="onSkuScan" /></div>
        </div>
        <Btn :tone="big ? 'purple' : 'success'" :class="big ? '!h-11 !text-[12px]' : '!h-[34px] !text-[10px]'" :loading="act.pending.value" :label="big ? { ar: 'Scan الموقع وتأكيد', en: 'Scan bin & confirm' } : { ar: 'Scan وتأكيد التخزين', en: 'Scan & confirm' }" @click="confirmPutaway" />
      </template>
      <Chip v-else-if="task.status === 'done'" fg="#1d7a3e" bg="#e6f9ec" :label="`${t('مخزّن ✓', 'Stored ✓')} ${task.actualBin?.code || ''}`" :title="task.confirmedBy ? `${task.confirmedBy} · ${fmtDate(task.confirmedAt)}` : null" />
    </div>
    <ErrorBanner :error="act.error.value" class="mt-2 !mb-0" @close="act.clearError()" />
  </div>
</template>
