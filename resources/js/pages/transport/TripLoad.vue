<script setup>
// Trip room · "Capacity": weight / volume / pallet bars against the assigned vehicle, utilisation health, pick / pack / load
// progress of the trip's fulfillment orders and the dispatch gate.
import { computed } from 'vue';
import { ProgressBar, SectionCard } from '@/components';
import { fmtNum, t } from '@/i18n';

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
  if (over.value) return { l: t('تجاوز السعة — ممنوع الإرسال', 'Over capacity — dispatch blocked'), c: '#b23b3b' };
  if (maxPct >= 90) return { l: t('حمولة حرجة', 'Critical load'), c: '#b26a16' };
  if (maxPct >= 50) return { l: t('استخدام صحي', 'Healthy utilisation'), c: '#1d7a3e' };
  return { l: t('استخدام منخفض — ادمج طلبات', 'Low utilisation — consolidate orders'), c: '#7d7990' };
});

const orders = computed(() => props.trip.orders || []);
const fos = computed(() => orders.value.map((o) => o.fo).filter(Boolean));
const loaded = computed(() => props.trip.loadedCount ?? orders.value.filter((o) => o.loaded).length);
const gateOk = computed(() => fos.value.length > 0 && loaded.value === fos.value.length && !!props.trip.vehicle && !!props.trip.driver && !over.value);
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
      <div class="px-[15px] py-2.5 text-[10.5px] font-extrabold" :class="gateOk ? 'text-ok' : 'text-warn'">{{ gateOk ? t('جاهزة للإرسال ✓', 'Ready to dispatch ✓') : t('بوابة الإرسال: يلزم مركبة وسائق وتحميل كل الطلبات ضمن السعة', 'Dispatch gate: vehicle, driver and all orders loaded within capacity') }}</div>
    </SectionCard>
  </div>
</template>
