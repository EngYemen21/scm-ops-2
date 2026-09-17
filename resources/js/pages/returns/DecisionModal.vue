<script setup>
// Final decision of a return in `inspect`: restock / quarantine / supplier / damaged / dispose (+ bin, note).
// POST /api/returns/:number/decide — creates the stock movement (RETURN / SCRAP) and closes the return.
// Mount it with v-if so every opening starts from a clean state:  <DecisionModal v-if="open" :ret="r" @close="…" @done="…" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, TextArea, TextInput } from '@/components';
import { bi, t } from '@/i18n';
import { RETURN_DECISION_LABELS } from '@/shared';
import { DECISION_STYLE } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const act = useAction();
const decision = ref('restock');
const binCode = ref('');
const note = ref('');
const st = computed(() => DECISION_STYLE[decision.value]);
const binLabel = computed(() => ({
  ar: decision.value === 'restock' ? 'موقع الإعادة للمخزون (Bin)' : decision.value === 'qtn' ? 'موقع الحجر (Bin)' : 'الموقع (Bin — اختياري)',
  en: decision.value === 'restock' ? 'Restock bin' : decision.value === 'qtn' ? 'Quarantine bin' : 'Bin (optional)',
}));
/** Pill colours of a decision option (selected = filled with the decision colour). */
function pillStyle(k) {
  const s = DECISION_STYLE[k] || { fg: '#55506a', bg: '#F1EFF6' };
  const on = decision.value === k;
  return { border: `1.5px solid ${on ? s.fg : '#E9E6F0'}`, background: on ? (k === 'dispose' ? s.bg : s.fg) : '#fff', color: on ? '#fff' : '#55506a' };
}

async function submit() {
  const r = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(props.ret.number)}/decide`, { decision: decision.value, binCode: binCode.value.trim() || undefined, note: note.value.trim() || undefined }), {
    success: (x) => x?.message || t('سُجل القرار وأُقفل المرتجع', 'Decision recorded — return closed'), invalidate: ['returns', 'inventory', 'dashboard'],
  });
  if (r !== undefined) { emit('close'); emit('done'); }
}
</script>

<template>
  <Modal open :title="{ ar: 'القرار النهائي للمرتجع', en: 'Final return decision' }" :width="480" @close="emit('close')">
    <template #title>
      {{ t('القرار النهائي للمرتجع', 'Final return decision') }}
      <div class="drawer-sub"><span class="num">{{ ret.number }}</span> · <span class="num">{{ ret.lines?.length || 0 }}</span> {{ t('سطر', 'lines') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="row wrap mb-3 !gap-1.5">
      <button v-for="(l, k) in RETURN_DECISION_LABELS" :key="k" type="button" class="cursor-pointer rounded-full px-3 py-[7px] font-[inherit] text-[10.5px] font-extrabold" :style="pillStyle(k)" @click="decision = k">{{ bi(l) }}</button>
    </div>
    <div class="form-grid">
      <TextInput v-model="binCode" :label="binLabel" dir="ltr" :placeholder="{ ar: 'افتراضي حسب الإعدادات', en: 'default from settings' }" />
      <TextArea v-model="note" :label="{ ar: 'ملاحظة', en: 'Note' }" full />
    </div>
    <div class="hint teal mt-2.5">{{ t('للمخزون: حركة إدخال RETURN. حجر: منطقة الحجر. تالف / إعدام: يخرج من المخزون بحركة SCRAP. للمورد: يُجهز لإرجاعه بمطالبة.', 'Restock: RETURN movement. Quarantine: quarantine zone. Damaged / scrap: leaves stock via SCRAP. Supplier: prepared for a supplier claim.') }}</div>

    <template #footer>
      <div class="row">
        <Btn :tone="st?.tone || 'dark'" class="!h-[42px] flex-1" :loading="act.pending.value" @click="submit">{{ bi(RETURN_DECISION_LABELS[decision]) }}</Btn>
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
