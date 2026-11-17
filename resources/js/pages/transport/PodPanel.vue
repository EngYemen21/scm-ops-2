<script setup>
// Proof of delivery panel of an arrived stop. One proof = the right goods + the receiver + the quantity:
//  · scan: the order's label / package / carton is scanned (checked at once by POST /delivery/stops/:id/scan — a code of
//    another order is refused there and then), or "I cannot scan" with a reason;
//  · receiver: name (required) + signature or photo (the photo is shrunk in the browser: longest side 1280, JPEG 0.7);
//    someone recording on behalf of the driver writes the reason in the notes instead;
//  · quantity: one stepper per item, full by default. Everything delivered → POST …/deliver; an item short →
//    POST …/partial { lines: [{ lineNo, deliveredQty }] } and what was not delivered returns to the warehouse.
// The policies (`requireScan`, `requireSignature`) come from the API with the trip; the server enforces them.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { ErrorBanner, NumberInput, PillChoice, TextArea, TextInput } from '@/components';
import { fmtNum, fmtTime, lang, t } from '@/i18n';
import { registerScanTarget } from '@/stores/ui';
import { BIG, captureGps } from './driver';
import FileToBase64 from './FileToBase64.vue';
import SignaturePad from './SignaturePad.vue';

const props = defineProps({
  stop: { type: Object, required: true },
  /** `{ requireScan, requireSignature, noScanReasons: [{ code, ar, en }] }` as the API reports it. */
  policy: { type: Object, default: () => ({}) },
  /** The signed-in user records for the driver (dispatch): no signature in hand → the reason goes in the notes. */
  onBehalf: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const act = useAction();
const scanAct = useAction();
const name = ref('');
const notes = ref('');
const signature = ref(undefined);
const photo = ref(undefined);
const cartons = ref(null);
/** { status: 'pending' | 'captured' | 'denied' | 'unavailable', gps? } */
const gps = ref({ status: 'pending' });
const openedAt = fmtTime(new Date());

// ── quantities: one row per order line, delivered in full unless the driver lowers it
const fo = computed(() => props.stop.fo || {});
const rows = reactive((fo.value.lines || []).map((l) => ({ lineNo: l.lineNo, name: (lang.value === 'en' && l.product?.nameEn) || l.product?.nameAr || l.product?.sku || `#${l.lineNo}`, sku: l.product?.sku || '', qty: l.qty || 0, delivered: l.qty || 0 })));
const totalQty = computed(() => rows.reduce((a, r) => a + r.qty, 0));
const deliveredQty = computed(() => rows.reduce((a, r) => a + clamp(r), 0));
const isPartial = computed(() => rows.length > 0 && deliveredQty.value < totalQty.value);
const orderCartons = computed(() => (fo.value.packages || []).reduce((a, p) => a + (p.cartons || 0), 0) || fo.value.cartons || 0);
function clamp(r) { const n = Number(r.delivered); return Number.isFinite(n) ? Math.min(r.qty, Math.max(0, Math.round(n))) : 0; }
function step(r, by) { r.delivered = Math.min(r.qty, Math.max(0, clamp(r) + by)); }

// ── scan
const scanCode = ref('');
/** [{ code, ok }] — ok: the code identifies this stop's order; false: the system does not know it. */
const scans = ref([]);
const cannotScan = ref(false);
const noScanReason = ref(null);
const noScanText = ref('');
const scanned = computed(() => scans.value.some((s) => s.ok));
const reasonOptions = computed(() => [...(props.policy.noScanReasons || []).map((r) => [r.code, { ar: r.ar, en: r.en }]), ['other', { ar: 'سبب آخر', en: 'Other reason' }]]);
const reasonValue = computed(() => (noScanReason.value === 'other' ? noScanText.value.trim() : noScanReason.value) || null);

async function checkScan() {
  const code = String(scanCode.value || '').trim();
  if (!code || scanAct.pending.value) return;
  if (scans.value.some((s) => s.code === code)) { scanCode.value = ''; return; }
  // a code of another order comes back as WRONG_PACKAGE (shown in the banner) and is never added to the proof
  const r = await scanAct.run(() => api.post(`/delivery/stops/${props.stop.id}/scan`, { code }), { success: false, invalidate: false });
  if (r === undefined) return;
  scans.value = [...scans.value, { code: r.code || code, ok: !!r.match }];
  scanCode.value = '';
  if (r.match) { cannotScan.value = false; }
}
const dropScan = (code) => { scans.value = scans.value.filter((s) => s.code !== code); };

let alive = true;
let offScan = null;
onMounted(() => {
  captureGps().then((g) => { if (alive) gps.value = g; });
  // the scan button of the top bar (worker / driver shell) delivers what it reads to this panel
  offScan = registerScanTarget((code) => { scanCode.value = code; void checkScan(); });
  if (props.onBehalf) { cannotScan.value = true; noScanReason.value = 'on_behalf'; }
});
onBeforeUnmount(() => { alive = false; offScan?.(); });

// ── what still stops the proof (shown under the button — never a silent disabled state)
const proofOk = computed(() => !!signature.value || !!photo.value);
const blocker = computed(() => {
  if (!name.value.trim()) return t('اسم المستلم مطلوب', 'Receiver name is required');
  if (rows.length > 0 && deliveredQty.value === 0) return t('لم يُسلَّم شيء — استخدم «تعذر التسليم» بدلًا من إثبات تسليم', 'Nothing was delivered — use "Unable to deliver" instead');
  if (props.policy.requireScan && !scanned.value && !(cannotScan.value && reasonValue.value)) return t('امسح ملصق الطلب أو أحد كراتينه، أو اختر «لا أستطيع المسح» مع السبب', "Scan the order's label or one of its cartons, or choose \"I cannot scan\" with a reason");
  if (props.policy.requireSignature && !proofOk.value) {
    if (!props.onBehalf) return t('التقط توقيع المستلم أو صورة التسليم', 'Take the receiver signature or a delivery photo');
    if (!notes.value.trim()) return t('تسجيل بالنيابة بلا توقيع أو صورة — اكتب السبب في الملاحظات', 'Recording on behalf without a signature or photo — write the reason in the notes');
  }
  if (cartons.value != null && orderCartons.value > 0 && cartons.value > orderCartons.value) return t(`عدد الكراتين أكبر من كراتين الطلب (${orderCartons.value})`, `More cartons than the order has (${orderCartons.value})`);
  return null;
});

const body = () => ({
  receiverName: name.value.trim(), notes: notes.value.trim() || undefined, gps: gps.value.gps, gpsStatus: gps.value.status === 'pending' ? 'unavailable' : gps.value.status,
  signature: signature.value, photo: photo.value,
  scans: scans.value.map((s) => s.code), noScanReason: !scanned.value && cannotScan.value ? reasonValue.value || undefined : undefined,
  deliveredCartons: cartons.value ?? undefined,
});
function submit() {
  if (blocker.value) return;
  if (isPartial.value) {
    return act.run(() => api.postIdempotent(`/delivery/stops/${props.stop.id}/partial`, { ...body(), lines: rows.map((r) => ({ lineNo: r.lineNo, deliveredQty: clamp(r) })) }),
      { success: (r) => r?.message || t(`تسليم جزئي — POD ${r?.pod || ''}${r?.return ? ` · مرتجع ${r.return}` : ''}`, `Partial delivery — POD ${r?.pod || ''}${r?.return ? ` · return ${r.return}` : ''}`), invalidate: ['delivery', 'transport', 'fulfillment', 'returns'] });
  }
  return act.run(() => api.postIdempotent(`/delivery/stops/${props.stop.id}/deliver`, body()), { success: (r) => r?.message || t(`تم التسليم — POD ${r?.pod || ''}`, `Delivered — POD ${r?.pod || ''}`), invalidate: ['delivery', 'transport', 'fulfillment'] });
}

/** Capture chips: ok = true (green) · null (grey, still working) · false (amber, missing). */
const chips = computed(() => {
  const g = gps.value;
  return [
    { ok: scanned.value ? true : cannotScan.value && reasonValue.value ? null : false, l: scanned.value ? `${t('مسح الطلب', 'Order scan')} ✓` : cannotScan.value && reasonValue.value ? t('بلا مسح — بسبب', 'No scan — reason given') : `${t('مسح الطلب', 'Order scan')} …` },
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
    <div v-if="onBehalf" class="hint amber !mt-0 mb-2 !px-2.5 !py-1.5 !text-[9.5px]">{{ t('تسجّل بالنيابة عن السائق: إن لم يتوفر توقيع أو صورة فاكتب السبب في الملاحظات.', 'You are recording on behalf of the driver: without a signature or photo, write the reason in the notes.') }}</div>

    <!-- 1 · the right goods -->
    <div class="field-l">{{ t('مسح الطلب عند العميل', 'Scan the order at the customer') }} <span v-if="policy.requireScan" class="text-bad">*</span></div>
    <div class="row !items-start !gap-1.5">
      <TextInput v-model="scanCode" scan small dir="ltr" mono field-class="flex-1" :disabled="scanAct.pending.value" :placeholder="{ ar: 'امسح ملصق الطلب / الطرد / الكرتون', en: 'Scan the order / package / carton label' }" @enter="checkScan" />
      <button type="button" :class="BIG" class="h-8 rounded-[9px] bg-night px-3 text-[10.5px] text-white disabled:opacity-50" :disabled="!String(scanCode || '').trim() || scanAct.pending.value" @click="checkScan">{{ scanAct.pending.value ? '…' : t('تحقق', 'Check') }}</button>
    </div>
    <ErrorBanner :error="scanAct.error.value" class="mt-1.5" @close="scanAct.clearError()" />
    <div v-if="scans.length > 0" class="row wrap mt-1.5 !gap-1.5">
      <span v-for="s in scans" :key="s.code" dir="ltr" class="num inline-flex items-center gap-1 rounded-full px-2.5 py-[4px] text-[9px] font-extrabold" :style="chipStyle(s.ok ? true : false)">
        {{ s.code }} {{ s.ok ? '✓' : `· ${t('رمز غير معروف', 'unknown code')}` }}
        <button type="button" class="cursor-pointer text-[10px]" :aria-label="t('حذف', 'Remove')" @click="dropScan(s.code)">✕</button>
      </span>
    </div>
    <template v-if="!scanned">
      <button type="button" class="btn ghost sm mt-1.5 !px-2 !py-0.5" @click="cannotScan = !cannotScan">{{ cannotScan ? t('سأمسح الطلب', 'I will scan the order') : t('لا أستطيع المسح', 'I cannot scan') }}</button>
      <div v-if="cannotScan" class="mt-1.5">
        <PillChoice v-model="noScanReason" :options="reasonOptions" :label="{ ar: 'سبب عدم المسح — يُبلَّغ به الإرسال', en: 'Why no scan — dispatch is told' }" :required="!!policy.requireScan" />
        <TextInput v-if="noScanReason === 'other'" v-model="noScanText" small class="mt-1.5" :placeholder="{ ar: 'اكتب السبب', en: 'Write the reason' }" />
      </div>
    </template>

    <!-- 2 · the receiver -->
    <TextInput v-model="name" required autofocus class="mt-2.5 !h-[46px] !rounded-xl !bg-soft !text-[13px]" :placeholder="{ ar: 'اسم المستلم *', en: 'Receiver name *' }" />
    <div class="row wrap mt-[9px] !gap-1.5">
      <div v-for="(c, i) in chips" :key="i" dir="ltr" class="rounded-full px-2.5 py-[5px] text-[9px] font-extrabold" :style="chipStyle(c.ok)">{{ c.l }}</div>
    </div>
    <SignaturePad v-model="signature" />
    <FileToBase64 v-model="photo" :label="t('صورة التسليم (كاميرا)', 'Delivery photo (camera)')" capture :max-side="1280" :quality="0.7" :note="t('جاهزة — تُحفظ مع إثبات التسليم', 'Ready — stored with the proof of delivery')" />
    <div v-if="policy.requireSignature && !onBehalf" class="mt-1 text-[9px] text-faint">{{ t('التوقيع أو الصورة — أحدهما إلزامي.', 'Signature or photo — one of them is required.') }}</div>
    <TextArea v-model="notes" :rows="2" :placeholder="onBehalf ? { ar: 'ملاحظات — سبب غياب التوقيع / الصورة', en: 'Notes — why there is no signature / photo' } : { ar: 'ملاحظات (اختياري)', en: 'Notes (optional)' }" />

    <!-- 3 · the quantity, per item -->
    <div v-if="rows.length > 0" class="mt-2.5 overflow-hidden rounded-[10px] border border-line-2">
      <div class="row bg-soft px-2.5 py-[5px] text-[9px] font-extrabold text-faint"><span class="flex-1">{{ t('الكمية المسلَّمة لكل صنف', 'Delivered quantity per item') }}</span><span class="num">{{ fmtNum(deliveredQty) }} / {{ fmtNum(totalQty) }}</span></div>
      <div v-for="r in rows" :key="r.lineNo" class="row !gap-1.5 border-t border-[#F7F6FA] px-2.5 py-[6px]">
        <div class="min-w-0 flex-1">
          <div class="ellipsis text-[10.5px] font-bold">{{ r.name }}</div>
          <div class="num text-[8.5px] text-faint">{{ r.sku }} · {{ t('المطلوب', 'ordered') }} {{ fmtNum(r.qty) }}<span v-if="clamp(r) < r.qty" class="font-extrabold text-warn"> · {{ t('يعود', 'returns') }} {{ fmtNum(r.qty - clamp(r)) }}</span></div>
        </div>
        <button type="button" :class="BIG" class="h-8 w-8 rounded-[9px] border border-line bg-white text-[14px] text-sec disabled:opacity-40" :disabled="clamp(r) <= 0" :aria-label="t('أقل', 'Less')" @click="step(r, -1)">−</button>
        <NumberInput v-model="r.delivered" small center field-class="w-[62px]" :min="0" :max="r.qty" :step="1" />
        <button type="button" :class="BIG" class="h-8 w-8 rounded-[9px] border border-line bg-white text-[14px] text-sec disabled:opacity-40" :disabled="clamp(r) >= r.qty" :aria-label="t('أكثر', 'More')" @click="step(r, 1)">+</button>
      </div>
    </div>
    <NumberInput v-if="orderCartons > 0" v-model="cartons" small class="mt-2" :min="0" :max="orderCartons" :label="{ ar: `الكراتين المسلَّمة (اختياري) من ${orderCartons}`, en: `Cartons delivered (optional) of ${orderCartons}` }" />
    <div v-if="isPartial && deliveredQty > 0" class="hint amber !mt-2 !px-2.5 !py-1.5 !text-[9.5px]">{{ t(`تسليم جزئي: ${deliveredQty} من ${totalQty} — ما لم يُسلَّم (${totalQty - deliveredQty}) يعود للمستودع كمرتجع بنفس الأصناف.`, `Partial delivery: ${deliveredQty} of ${totalQty} — the ${totalQty - deliveredQty} not delivered return to the warehouse, item by item.`) }}</div>

    <ErrorBanner :error="act.error.value" class="mt-2" @close="act.clearError()" />
    <button type="button" :class="[BIG, { 'opacity-50': !!blocker }, isPartial ? 'bg-warn' : 'bg-ok']" class="mt-2.5 h-[46px] w-full rounded-xl text-[12.5px] text-white" :disabled="!!blocker || act.pending.value" @click="submit">
      {{ act.pending.value ? t('جارٍ الحفظ…', 'Saving…') : isPartial ? t(`تسليم جزئي (${deliveredQty} من ${totalQty})`, `Partial delivery (${deliveredQty} of ${totalQty})`) : t('إقفال التسليم', 'Confirm delivery') }}
    </button>
    <div v-if="blocker" class="mt-1 text-[9px] font-bold text-bad">{{ blocker }}</div>
  </div>
</template>
