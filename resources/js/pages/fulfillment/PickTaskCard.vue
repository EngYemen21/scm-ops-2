<script setup>
// Pick-task card: sequence · product · zone/bin · batch · qty/picked, then (when pickable) scan bin → scan product →
// qty → confirm, or "short" with a mandatory reason (releases the allocation and raises an exception).
// The server rejects a wrong bin / product, over-picking, expired or quarantined batches — its message is shown as is.
//   <PickTaskCard :task="task" :fo-status="fo.status" @changed="(pickResult) => …" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Modal, NumberInput, TextArea, TextInput } from '@/components';
import { fmtDateOnly, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { TASK_LABELS, prodName } from './shared';

const props = defineProps({
  task: { type: Object, required: true },
  foStatus: { type: String, required: true },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const act = useAction();
const bin = ref('');
const sku = ref('');
const qty = ref(null);
const shortOpen = ref(false);
const reason = ref('');

const remaining = computed(() => props.task.qty - props.task.pickedQty);
const canDo = computed(() => auth.can('pick.confirm') && ['open', 'partial'].includes(props.task.status) && ['alloc', 'picking'].includes(props.foStatus));

async function confirmPick() {
  const body = { scannedBin: bin.value.trim(), scannedProduct: sku.value.trim(), ...(qty.value ? { qty: qty.value } : {}) };
  const r = await act.run(() => api.postIdempotent(`/fulfillment/pick-tasks/${props.task.id}/confirm`, body), { success: (x) => x.message || t('تم ✓', 'Done ✓'), invalidate: ['fulfillment', 'sales', 'inventory'] });
  if (r) { bin.value = ''; sku.value = ''; qty.value = null; emit('changed', r); }
}
function onEnter() { if (bin.value && sku.value) confirmPick(); }
async function recordShort() {
  const r = await act.run(() => api.postIdempotent(`/fulfillment/pick-tasks/${props.task.id}/short`, { reason: reason.value.trim() }), { success: t('سُجّل نقص التجهيز — فُتح استثناء لمدير المستودع', 'Short pick recorded — exception raised'), invalidate: ['fulfillment', 'sales', 'inventory', 'exceptions'] });
  if (r !== undefined) { shortOpen.value = false; reason.value = ''; emit('changed'); }
}
</script>

<template>
  <div class="rounded-[13px] border border-line-2 px-[15px] py-[11px]" :class="{ 'bg-[#FAFDFB]': task.status === 'done', 'bg-[#FFFAFA]': task.status === 'short' }">
    <div class="row wrap !gap-3">
      <div class="num flex h-[26px] w-[26px] flex-none items-center justify-center rounded-lg bg-canvas text-[10px] text-sec">{{ task.seq }}</div>
      <div class="min-w-[170px]"><div class="text-[11.5px] font-extrabold">{{ prodName(task.product) }}</div><div class="num mt-px text-[8.5px] text-faint">{{ task.product.sku }}</div></div>
      <div class="num min-w-[90px] text-[10.5px] text-violet"><span class="ltr">{{ task.bin.zone?.code ? `${task.bin.zone.code} · ` : '' }}{{ task.bin.code }}</span></div>
      <div class="num min-w-[70px] text-[10px] text-brand-dark">{{ task.batch?.batchNo || task.batchNo || '—' }}<div v-if="task.batch?.expiryDate" class="text-[8px] text-faint">{{ fmtDateOnly(task.batch.expiryDate) }}</div></div>
      <div class="min-w-[90px] flex-1 text-[11px] font-extrabold">{{ fmtNum(task.qty) }}<div class="num text-[8.5px] text-warn">{{ t(`مجهز ${task.pickedQty} / ${task.qty}`, `Picked ${task.pickedQty} / ${task.qty}`) }}</div></div>
      <template v-if="canDo">
        <div class="row flex-wrap !gap-1.5">
          <TextInput scan v-model="bin" small mono dir="ltr" class="w-[104px]" placeholder="Scan Bin" @enter="onEnter" />
          <TextInput scan v-model="sku" small mono dir="ltr" class="w-24" placeholder="Scan SKU" @enter="onEnter" />
          <NumberInput v-model="qty" small class="w-14" :min="1" :max="remaining" :placeholder="String(remaining)" @enter="onEnter" />
        </div>
        <Btn tone="primary" class="!h-[38px] !rounded-[11px] !text-[11px]" :loading="act.pending.value" :disabled="!bin.trim() || !sku.trim()" :label="{ ar: 'تأكيد', en: 'Confirm' }" @click="confirmPick">
          <template #icon><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 12h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg></template>
        </Btn>
        <Btn tone="softRed" size="sm" :label="{ ar: 'نقص', en: 'Short' }" @click="shortOpen = true" />
        <Chip v-if="task.status === 'partial'" :map="TASK_LABELS" k="partial" small />
      </template>
      <Chip v-else :map="TASK_LABELS" :k="task.status" />
    </div>
    <div v-if="task.pickedBy && task.status !== 'open'" class="faint mt-1.5 text-[8.5px]">{{ task.pickedBy }}{{ task.pickedAt ? ` · ${fmtDateOnly(task.pickedAt)}` : '' }}</div>
    <ErrorBanner class="mt-2" :error="act.error.value" @close="act.clearError()" />

    <Modal :open="shortOpen" :width="460" :title="{ ar: `نقص تجهيز — ${task.product.sku}`, en: `Short pick — ${task.product.sku}` }"
           :sub="{ ar: `المتبقي ${remaining} غير متوفر في ${task.bin.code} — يُفرج عن الحجز ويُفتح استثناء لمدير المستودع`, en: `${remaining} unavailable at ${task.bin.code} — releases the allocation and raises an exception` }" @close="shortOpen = false">
      <TextArea v-model="reason" :label="{ ar: 'السبب', en: 'Reason' }" required :placeholder="{ ar: 'مثال: الموقع فارغ / تالف / دفعة منتهية', en: 'e.g. empty bin / damaged / expired batch' }" />
      <template #footer>
        <div class="flex gap-2">
          <Btn tone="danger" class="!h-10 flex-1" :loading="act.pending.value" :disabled="!reason.trim()" :label="{ ar: 'تسجيل النقص', en: 'Record short pick' }" @click="recordShort" />
          <Btn tone="soft" class="!h-10 w-[100px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="shortOpen = false" />
        </div>
      </template>
    </Modal>
  </div>
</template>
