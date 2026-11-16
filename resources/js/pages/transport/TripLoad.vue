<script setup>
// Trip room · "Capacity": weight / volume / pallet bars against the assigned vehicle, utilisation health, pick / pack / load
// progress of the trip's fulfillment orders and the dispatch gate.
// The gate is NOT computed here: `trip.dispatchGate` is the server's own rule (TripsService::dispatchGate — what the
// dispatch endpoint enforces): trip loading, a usable vehicle, a driver who may drive, something loaded, EVERY packed
// order loaded, no open critical loading exception. Orders that are not packed yet do not block — they leave the trip.
import { computed } from 'vue';
import { ProgressBar, SectionCard } from '@/components';
import { fmtNum, lang, t } from '@/i18n';

const props = defineProps({ trip: { type: Object, required: true } });

const barColor = (b) => (b.over || b.pct >= 90 ? '#b23b3b' : b.pct >= 70 ? '#b26a16' : '#1d7a3e');
const bars = computed(() => {
  const trip = props.trip; const u = trip.utilisation; const v = trip.vehicle;
  if (!v) return [];
  return [
    { l: t('الوزن', 'Weight'), txt: `${fmtNum(trip.kg)} / ${fmtNum(v.maxKg)} ${t('كجم', 'kg')}`, pct: u?.kg ?? 0, over: u?.overKg },
    { l: t('الحجم', 'Volume'), txt: `${fmtNum(trip.cbm, 1)} / ${fmtNum(v.maxCbm, 1)} ${t('م³', 'm³')}`, pct: u?.cbm ?? 0, over: u?.overCbm },
    { l: t('الطبليات', 'Pallets'), txt: `${fmtNum(trip.pallets)} / ${fmtNum(v.pallets)}`, pct: u?.pallets ?? 0, over: u?.overPallets },
  ];
});
const over = computed(() => bars.value.some((b) => b.over));
const health = computed(() => {
  const maxPct = Math.max(0, ...bars.value.map((b) => b.pct));
  if (!props.trip.vehicle) return { l: t('لا مركبة مسندة — أسند مركبة لحساب الحمولة', 'No vehicle assigned — assign one to compute capacity'), c: '#7d7990' };
  if (over.value) return { l: t('الحمولة المخططة تتجاوز سعة المركبة — التحميل يرفض ما يتجاوز الوزن أو الحجم', 'The planned load exceeds the vehicle — loading refuses whatever goes over weight or volume'), c: '#b23b3b' };
  if (maxPct >= 90) return { l: t('حمولة حرجة', 'Critical load'), c: '#b26a16' };
  if (maxPct >= 50) return { l: t('استخدام صحي', 'Healthy utilisation'), c: '#1d7a3e' };
  return { l: t('استخدام منخفض — ادمج طلبات', 'Low utilisation — consolidate orders'), c: '#7d7990' };
});

const orders = computed(() => props.trip.orders || []);
const fos = computed(() => orders.value.map((o) => o.fo).filter(Boolean));
const loaded = computed(() => props.trip.loadedCount ?? orders.value.filter((o) => o.loaded).length);
/** The server's verdict; null once the trip left (or for an API answer that carries none). */
const gate = computed(() => props.trip.dispatchGate || null);
const progressRows = computed(() => {
  const n = fos.value.length;
  const picked = fos.value.filter((f) => ['picked', 'packed', 'loaded', 'onroute', 'delivered'].includes(f.status)).length;
  const packed = fos.value.filter((f) => ['packed', 'loaded', 'onroute', 'delivered'].includes(f.status)).length;
  return [
    { k: t('التجهيز Picking', 'Picking'), v: `${picked} / ${n}` }, { k: t('التعبئة Packing', 'Packing'), v: `${packed} / ${n}` },
    { k: t('التحميل Loading', 'Loading'), v: `${loaded.value} / ${n}` }, { k: t('الطلبات', 'Orders'), v: fos.value.map((f) => f.number).join(' · ') || '—' },
  ];
});
</script>

<template>
  <div class="col !gap-3">
    <SectionCard small>
      <div class="col !gap-3">
        <div v-for="b in bars" :key="b.l">
          <div class="row text-[10.5px] font-extrabold"><div>{{ b.l }}</div><div class="flex-1" /><div class="num" :style="{ color: barColor(b) }">{{ b.txt }} · {{ fmtNum(b.pct) }}%</div></div>
          <ProgressBar class="mt-[5px]" :pct="b.pct" :color="barColor(b)" :height="12" />
        </div>
      </div>
      <div class="mt-3 text-[10.5px] font-extrabold" :style="{ color: health.c }">{{ health.l }}</div>
    </SectionCard>
    <SectionCard small :padded="false">
      <div v-for="r in progressRows" :key="r.k" class="kv"><div class="kv-k !w-[150px]">{{ r.k }}</div><div class="kv-v num">{{ r.v }}</div></div>

      <!-- the dispatch gate: the server's rule, blocker by blocker -->
      <template v-if="gate">
        <div class="border-t border-line-2 px-[15px] py-2.5 text-[10.5px] font-extrabold" :class="gate.ok ? 'text-ok' : 'text-warn'">
          {{ gate.ok ? t('جاهزة للإرسال ✓ — من لوحة التحميل والإرسال', 'Ready to dispatch ✓ — from the load & dispatch board') : t('بوابة الإرسال — ما يمنع الخروج الآن:', 'Dispatch gate — what blocks it now:') }}
        </div>
        <div v-for="b in gate.blockers" :key="b.code" class="row !items-baseline !gap-2 border-t border-[#F7F6FA] px-[15px] py-[7px] text-[10px] font-bold text-bad">
          <span>✕</span><span class="flex-1">{{ lang === 'en' ? b.en : b.ar }}</span>
        </div>
        <div v-if="gate.leaving.length > 0" class="border-t border-[#F7F6FA] px-[15px] py-[7px] text-[10px] text-warn">
          {{ t('طلبات لم تُجهَّز بعد — لا تمنع الإرسال، لكنها تخرج من الرحلة عند الانطلاق وتبقى للتخطيط:', 'Orders not packed yet — they do not block dispatch, but leave the trip when it departs and stay for planning:') }}
          <span class="num font-bold">{{ gate.leaving.join(' · ') }}</span>
        </div>
        <div class="border-t border-[#F7F6FA] px-[15px] py-[7px] text-[9.5px] text-faint">{{ t('القاعدة: الرحلة في التحميل · مركبة صالحة · سائق مؤهل (غير موقوف، في الوردية، رخصته وإقامته ساريتان) · كل طلب مجهَّز محمَّل · لا استثناء تحميل حرج مفتوح.', 'The rule: trip loading · a usable vehicle · a driver who may drive (not blocked, on shift, licence and iqama valid) · every packed order loaded · no open critical loading exception.') }}</div>
      </template>
      <div v-else class="border-t border-line-2 px-[15px] py-2.5 text-[10.5px] font-bold text-muted">{{ t('الرحلة غادرت أو انتهت — لا بوابة إرسال.', 'The trip has left or ended — no dispatch gate.') }}</div>
    </SectionCard>
  </div>
</template>
