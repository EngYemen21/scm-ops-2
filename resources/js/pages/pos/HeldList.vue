<script setup>
// The sales held in a session (kept on the server, so they survive a reload or a change of device):
//   GET /pos/sessions/{id}/held → [{ id, label, payload: { customer, lines }, total, status, createdAt }]
//   recall  POST /pos/held/{id}/recall  → the held sale (its lines go back into the cart, `heldId` is remembered)
//   discard POST /pos/held/{id}/discard { reason? }
import { computed } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, Modal } from '@/components';
import { fmtAgo, fmtTime, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import { money } from './pos';

const props = defineProps({
  open: { type: Boolean, default: false },
  sessionId: { type: String, default: null },
  /** The cart has lines: recalling replaces them (asked first). */
  cartHasLines: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'recalled']);

const list = useGet(() => (props.open && props.sessionId ? `/pos/sessions/${encodeURIComponent(props.sessionId)}/held` : null));
const rows = computed(() => (Array.isArray(list.data.value) ? list.data.value : []));
const act = useAction();
const lineCount = (h) => (h.payload?.lines || []).reduce((s, l) => s + (Number(l.qty) || 0), 0);

async function recall(h) {
  if (props.cartHasLines && !(await confirm({ title: { ar: 'في السلة بيع لم يُدفع', en: 'The cart holds an unpaid sale' }, sub: { ar: 'استرجاع هذا البيع يستبدل ما في السلة. علّق البيع الحالي أولًا إن أردت الاحتفاظ به.', en: 'Recalling replaces what is in the cart. Hold the current sale first to keep it.' }, tone: 'dark', okLabel: { ar: 'استرجاع', en: 'Recall' } }))) return;
  const r = await act.run(() => api.postIdempotent(`/pos/held/${encodeURIComponent(h.id)}/recall`), { success: t('استُرجع البيع إلى السلة', 'Sale recalled to the cart'), invalidate: ['pos'] });
  if (r) { emit('recalled', r); emit('close'); }
}
async function discard(h) {
  const reason = await ask({ title: { ar: `إلغاء «${h.label}»؟`, en: `Discard "${h.label}"?` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'إلغاء البيع المعلّق', en: 'Discard' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/pos/held/${encodeURIComponent(h.id)}/discard`, { reason: reason || undefined }), { success: t('أُلغي البيع المعلّق', 'Held sale discarded'), invalidate: ['pos'] });
}
</script>

<template>
  <Modal :open="open" :title="{ ar: 'المبيعات المعلّقة', en: 'Held sales' }" :width="560" @close="emit('close')">
    <ErrorBanner :error="list.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="list.isLoading.value" class="skel h-[120px]" />
    <EmptyState v-else-if="!rows.length" tone="dashed" :text="{ ar: 'لا مبيعات معلّقة في هذه الجلسة.', en: 'No held sale in this session.' }" />
    <div v-for="h in rows" :key="h.id" class="mb-2 flex flex-wrap items-center gap-2 rounded-xl border border-line p-2.5">
      <div class="min-w-0 flex-1">
        <div class="truncate text-[12px] font-extrabold text-ink" dir="auto">{{ h.label }}</div>
        <div class="text-[10px] text-muted">
          <span class="num ltr">{{ fmtTime(h.createdAt) }}</span> · {{ fmtAgo(h.createdAt) }} · {{ t('القطع', 'Items') }} <span class="num">{{ lineCount(h) }}</span>
          <template v-if="h.payload?.customer"> · {{ t('العميل', 'Customer') }} <span class="num ltr">{{ h.payload.customer }}</span></template>
        </div>
      </div>
      <div class="num ltr text-[13px] font-extrabold text-ink">{{ h.total != null ? money(h.total) : '—' }}</div>
      <Btn tone="primary" size="sm" :loading="act.pending.value" :label="{ ar: 'استرجاع', en: 'Recall' }" @click="recall(h)" />
      <Btn tone="softRed" size="sm" :disabled="act.pending.value" :label="{ ar: 'إلغاء', en: 'Discard' }" @click="discard(h)" />
    </div>
  </Modal>
</template>
