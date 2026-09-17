<script setup>
// Proof of delivery panel of an arrived stop: receiver name (required), signature, photo, phone GPS, notes, and either
// "confirm delivery" → POST /delivery/stops/:id/deliver or "partial delivery" (delivered qty) → POST /delivery/stops/:id/partial.
// Signature / photo travel as base64 in the body exactly like the reference; their stored status is whatever the API
// returns (integration_pending) — nothing here claims an upload.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { ErrorBanner, NumberInput, TextArea, TextInput } from '@/components';
import { fmtNum, fmtTime, t } from '@/i18n';
import { BIG, captureGps } from './driver';
import FileToBase64 from './FileToBase64.vue';
import SignaturePad from './SignaturePad.vue';

const props = defineProps({
  stop: { type: Object, required: true },
  /** Ordered quantity of the stop's FO (upper bound of a partial delivery); 0 when unknown. */
  totalQty: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);

const act = useAction();
const name = ref('');
const notes = ref('');
const signature = ref(undefined);
const photo = ref(undefined);
const partQty = ref(null);
/** { status: 'pending' | 'captured' | 'denied' | 'unavailable', gps? } */
const gps = ref({ status: 'pending' });
const openedAt = fmtTime(new Date());

let alive = true;
onMounted(() => { captureGps().then((g) => { if (alive) gps.value = g; }); });
onBeforeUnmount(() => { alive = false; });

const valid = computed(() => name.value.trim().length > 0);
const partialOk = computed(() => valid.value && !!partQty.value && partQty.value > 0);
const body = () => ({ receiverName: name.value.trim(), notes: notes.value.trim() || undefined, gps: gps.value.gps, gpsStatus: gps.value.status === 'pending' ? 'unavailable' : gps.value.status, signature: signature.value, photo: photo.value });

const deliver = () => act.run(() => api.postIdempotent(`/delivery/stops/${props.stop.id}/deliver`, body()), { success: (r) => r?.message || t(`تم التسليم — POD ${r?.pod || ''}`, `Delivered — POD ${r?.pod || ''}`), invalidate: ['delivery', 'transport', 'fulfillment'] });
const partial = () => act.run(() => api.postIdempotent(`/delivery/stops/${props.stop.id}/partial`, { ...body(), deliveredQty: partQty.value }), { success: (r) => r?.message || t(`تسليم جزئي — POD ${r?.pod || ''}`, `Partial delivery — POD ${r?.pod || ''}`), invalidate: ['delivery', 'transport', 'fulfillment', 'returns'] });

/** Capture chips: ok = true (green) · null (grey, still working) · false (amber, missing). */
const chips = computed(() => {
  const g = gps.value;
  return [
    { ok: !!signature.value, l: `${t('توقيع العميل', 'Signature')} ${signature.value ? '✓' : '…'}` },
    { ok: !!photo.value, l: `${t('صورة التسليم', 'Photo')} ${photo.value ? '✓' : '…'}` },
    { ok: g.status === 'captured' ? true : g.status === 'pending' ? null : false, l: g.status === 'captured' ? `GPS ${g.gps.lat},${g.gps.lng} ✓` : g.status === 'pending' ? 'GPS …' : g.status === 'denied' ? t('GPS مرفوض', 'GPS denied') : t('GPS غير متاح', 'GPS unavailable') },
    { ok: true, l: `${t('الوقت', 'Time')} ${openedAt} ✓` },
  ];
});
const chipStyle = (ok) => ({ color: ok ? '#1d7a3e' : ok === null ? '#7d7990' : '#b26a16', background: ok ? '#e6f9ec' : ok === null ? '#F1EFF6' : '#fbf0dd' });
</script>

<template>
  <div class="mt-[11px] border-t border-line-2 pt-[11px]">
    <div class="row mb-1.5 justify-between"><b class="text-[11px]">{{ t('إثبات التسليم POD', 'Proof of delivery') }}</b><button type="button" class="x-btn" aria-label="close" @click="emit('close')">✕</button></div>
    <TextInput v-model="name" required autofocus class="!h-[46px] !rounded-xl !bg-soft !text-[13px]" :placeholder="{ ar: 'اسم المستلم *', en: 'Receiver name *' }" />
    <div class="row wrap mt-[9px] !gap-1.5">
      <div v-for="(c, i) in chips" :key="i" dir="ltr" class="rounded-full px-2.5 py-[5px] text-[9px] font-extrabold" :style="chipStyle(c.ok)">{{ c.l }}</div>
    </div>
    <SignaturePad v-model="signature" />
    <FileToBase64 v-model="photo" :label="t('صورة التسليم (كاميرا)', 'Delivery photo (camera)')" capture />
    <TextArea v-model="notes" :rows="2" :placeholder="{ ar: 'ملاحظات (اختياري)', en: 'Notes (optional)' }" />
    <ErrorBanner :error="act.error.value" class="mt-2" @close="act.clearError()" />
    <div class="row mt-[9px]">
      <NumberInput v-model="partQty" small class="w-[170px]" :min="1" :max="totalQty || null" :placeholder="{ ar: 'الكمية المسلَّمة (جزئي)', en: 'Delivered qty (partial)' }" />
      <button type="button" :class="[BIG, { 'opacity-50': !partialOk }]" class="h-8 rounded-[9px] bg-warn px-3.5 text-[10.5px] text-white" :disabled="!partialOk || act.pending.value" @click="partial">{{ t('تسليم جزئي', 'Partial delivery') }}</button>
      <span v-if="totalQty > 0" class="num muted text-[9px]">/ {{ fmtNum(totalQty) }}</span>
    </div>
    <button type="button" :class="[BIG, { 'opacity-50': !valid }]" class="mt-2.5 h-[46px] w-full rounded-xl bg-ok text-[12.5px] text-white" :disabled="!valid || act.pending.value" @click="deliver">{{ act.pending.value ? t('جارٍ الحفظ…', 'Saving…') : t('إقفال التسليم', 'Confirm delivery') }}</button>
    <div v-if="!valid" class="mt-1 text-[9px] text-bad">{{ t('اسم المستلم مطلوب', 'Receiver name is required') }}</div>
  </div>
</template>
