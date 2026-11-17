<script setup>
// Resolve the variance of a partially received transfer: what happened to the quantity that left the source and never
// reached the destination — written off as a loss (journal Dr inventory loss / Cr inventory in transit) or received
// back into the source warehouse (its original bin unless another one is named).
// POST /api/inventory/transfers/:id/resolve-variance { resolution: loss | returned, note, lines?: [{ lineNo, toBin }] }
// Mount it with v-if so every opening starts clean:  <TransferVarianceModal v-if="open" :transfer="tr" @close="…" @done="…" />
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, PillChoice, TextArea, TextInput } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import { pname } from './shared';

const props = defineProps({ transfer: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const RESOLUTIONS = [
  { v: 'returned', l: { ar: 'أُعيد إلى المصدر', en: 'Returned to the source' } },
  { v: 'loss', l: { ar: 'خسارة مخزون (فُقد / تلف في الطريق)', en: 'Stock loss (lost / damaged on the road)' } },
];

const act = useAction({ invalidate: ['inventory', 'dashboard', 'exceptions', 'finance'] });
const resolution = ref(null);
const note = ref('');
/** { [lineNo]: bin code } — only for `returned`; empty = the bin the line left. */
const bins = ref({});
const setBin = (lineNo, v) => { bins.value = { ...bins.value, [lineNo]: v }; };

const short = computed(() => props.transfer.lines.filter((l) => l.remainingQty > 0));
const openQty = computed(() => short.value.reduce((n, l) => n + l.remainingQty, 0));
/** Why the form cannot be sent yet (null = it can). */
const issue = computed(() => {
  if (!resolution.value) return t('اختر ما حدث للكمية التي لم تصل', 'Choose what happened to the quantity that did not arrive');
  if (note.value.trim().length < 3) return t('اكتب سبب التسوية (3 أحرف على الأقل) — يُسجل في Audit', 'Write the reason (at least 3 characters) — it is audited');
  return null;
});

async function submit() {
  if (issue.value) return;
  const lines = resolution.value === 'returned'
    ? short.value.map((l) => ({ lineNo: l.lineNo, toBin: String(bins.value[l.lineNo] || '').trim() })).filter((l) => l.toBin)
    : [];
  const body = { resolution: resolution.value, note: note.value.trim(), lines: lines.length ? lines : undefined };
  const r = await act.run(() => api.postIdempotent(`/inventory/transfers/${props.transfer.id}/resolve-variance`, body), {
    success: resolution.value === 'loss' ? { ar: 'سُوّي الفرق كخسارة مخزون — يمكن إقفال التحويل', en: 'Variance written off — the transfer can be closed' } : { ar: 'أُعيد الفرق إلى رصيد المصدر — يمكن إقفال التحويل', en: 'Variance received back at the source — the transfer can be closed' },
  });
  if (r !== undefined) { emit('close'); emit('done', r); }
}
</script>

<template>
  <Modal open :width="540" :title="{ ar: 'تسوية فرق التحويل', en: 'Resolve the transfer variance' }" @close="emit('close')">
    <template #title>
      {{ t('تسوية فرق التحويل', 'Resolve the transfer variance') }}
      <div class="drawer-sub"><span class="num">{{ transfer.number }}</span> · <span class="num">{{ fmtNum(openQty) }}</span> {{ t('وحدة خرجت من المصدر ولم تُستلم', 'unit(s) left the source and were not received') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />

    <div class="mb-3 overflow-hidden rounded-[10px] border border-line-2">
      <div v-for="l in short" :key="l.id" class="row border-b border-[#F7F6FA] px-2.5 py-1.5 last:border-b-0">
        <div class="min-w-0 flex-1">
          <div class="ellipsis text-[10.5px] font-extrabold">{{ pname(l.product) }}</div>
          <div class="cell-sub num ltr">{{ l.product.sku }} · {{ transfer.fromWarehouse.code }}/{{ l.fromBin.code }}{{ l.batchNo ? ` · ${l.batchNo}` : '' }}</div>
        </div>
        <div class="num text-[11px] font-extrabold text-bad">−{{ fmtNum(l.remainingQty) }}</div>
        <TextInput v-if="resolution === 'returned'" scan small dir="ltr" mono class="!w-[140px]" :model-value="bins[l.lineNo] || ''" :placeholder="l.fromBin.code" @update:model-value="setBin(l.lineNo, $event)" />
      </div>
    </div>

    <div class="form-grid">
      <PillChoice v-model="resolution" :options="RESOLUTIONS" required full :label="{ ar: 'ماذا حدث للكمية التي لم تصل؟', en: 'What happened to the quantity that did not arrive?' }" />
      <TextArea v-model="note" required full :rows="2" :label="{ ar: 'السبب / ملاحظة (تُسجل في Audit)', en: 'Reason / note (audited)' }" />
    </div>
    <div v-if="resolution === 'returned'" class="hint teal mt-2.5">{{ t('تعود الكمية إلى رصيد مستودع المصدر بتكلفة خروجها — في موقعها الأصلي ما لم تحدد موقعًا آخر لكل سطر أعلاه.', 'The quantity goes back into the source warehouse at the cost it left at — into its original bin unless you name another one per line above.') }}</div>
    <div v-if="resolution === 'loss'" class="hint amber mt-2.5">
      {{ t('يُرحَّل قيد واحد: مدين خسائر المخزون / دائن مخزون في الطريق بتكلفة خروج الكمية. لا يعود شيء للمخزون.', 'One journal is posted: Dr inventory loss / Cr inventory in transit at the cost the quantity left at. Nothing goes back into stock.') }}
      <template v-if="transfer.variance?.openValue != null"> {{ t('القيمة:', 'Value:') }} <span class="num ltr font-extrabold">{{ fmtMoney(transfer.variance.openValue) }}</span></template>
    </div>
    <div v-if="issue" class="field-err mt-2">{{ issue }}</div>

    <template #footer>
      <div class="row">
        <Btn :tone="resolution === 'loss' ? 'danger' : 'primary'" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!issue" :title="issue || undefined" :label="{ ar: 'تأكيد التسوية', en: 'Confirm resolution' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'رجوع', en: 'Back' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
