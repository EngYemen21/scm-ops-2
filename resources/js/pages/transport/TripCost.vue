<script setup>
// Trip room · "Cost": cost KPIs (per delivery / km / kg / m³) and the cost breakdown. The cost is computed by the server at trip close.
import { computed } from 'vue';
import { SectionCard } from '@/components';
import { fmtNum, t } from '@/i18n';

const props = defineProps({ trip: { type: Object, required: true } });

const total = computed(() => Number(props.trip.costTotal) || 0);
const per = (d) => (Number(d) ? fmtNum(total.value / Number(d), 1) : '—');
const kpis = computed(() => {
  const trip = props.trip;
  const delivered = (trip.stops || []).filter((s) => ['delivered', 'partial'].includes(s.status)).length;
  return [{ k: t('تكلفة الرحلة', 'Trip cost'), v: fmtNum(total.value) }, { k: t('لكل توصيلة', 'Per delivery'), v: per(delivered) }, { k: t('لكل كم', 'Per km'), v: per(trip.km) }, { k: t('لكل كجم', 'Per kg'), v: per(trip.kg) }, { k: t('لكل م³', 'Per m³'), v: per(trip.cbm) }];
});
const ROWS = [['fuel', { ar: 'وقود', en: 'Fuel' }], ['driver', { ar: 'أجر السائق', en: 'Driver wage' }], ['ot', { ar: 'عمل إضافي', en: 'Overtime' }], ['maint', { ar: 'حصة صيانة', en: 'Maintenance share' }], ['dep', { ar: 'إهلاك المركبة', en: 'Depreciation' }], ['tolls', { ar: 'رسوم طرق', en: 'Tolls' }], ['parking', { ar: 'مواقف', en: 'Parking' }], ['third', { ar: 'نقل طرف ثالث', en: 'Third-party' }], ['other', { ar: 'أخرى', en: 'Other' }]];
const rows = computed(() => {
  const c = props.trip.cost || {};
  return ROWS.map(([k, l]) => { const v = Number(c[k] || 0); return { k, l: t(l.ar, l.en), v, pct: total.value ? (v / total.value) * 100 : 0 }; });
});
</script>

<template>
  <div class="col !gap-3">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-2">
      <div v-for="k in kpis" :key="k.k" class="card sm px-[13px] py-[11px]">
        <div class="num text-[16px] font-bold text-violet">{{ k.v }}</div>
        <div class="mt-0.5 text-[8.5px] font-extrabold text-faint">{{ k.k }}</div>
      </div>
    </div>
    <SectionCard small>
      <div v-for="r in rows" :key="r.k" class="row !gap-2.5 border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
        <div class="w-[130px] font-extrabold text-sec">{{ r.l }}</div>
        <div class="h-[7px] flex-1 overflow-hidden rounded-full bg-line-2"><div class="h-full bg-violet" :style="{ width: r.pct + '%' }" /></div>
        <div class="num w-[70px] text-end font-bold">{{ fmtNum(r.v) }}</div>
      </div>
      <div class="row justify-between pt-2.5 text-[12px] font-extrabold"><div>{{ t('الإجمالي', 'Total') }}</div><div class="num">{{ fmtNum(total) }} {{ t('ر.س', 'SAR') }}</div></div>
      <div class="mt-1.5 text-[9.5px] text-faint">{{ trip.cost ? t('تُحتسب التكلفة عند إقفال الرحلة من سجلات الوقود والصيانة وأجور السائق.', 'Cost is computed at trip close from fuel, maintenance and driver-wage records.') : t('لم تُحتسب التكلفة بعد — تُحتسب عند إقفال الرحلة.', 'Cost not computed yet — computed at trip close.') }}</div>
    </SectionCard>
  </div>
</template>
