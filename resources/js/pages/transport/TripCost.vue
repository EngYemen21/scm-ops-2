<script setup>
// Trip room · "Cost": the trip's cost sheet (`trip.cost`) — every component with where its figure comes from
// (`trip.cost.basis[k].source`: actual / estimated / allocated per km / not configured), the per-unit KPIs and
// "recompute" (POST /transport/trips/:number/cost/recompute, permission trip.close).
// The server computes the sheet when the trip is closed and again whenever a fuel record or an expense voucher on the
// trip changes; a component with no data source is 0 and says what is missing. Nothing is posted to the ledger from it.
import { computed } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, SectionCard } from '@/components';
import { bi, fmtDate, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { COST_ROWS, COST_SOURCE_LABELS, costBasisText } from './fleet';

const props = defineProps({ trip: { type: Object, required: true } });

const auth = useAuth();
const act = useAction();
const cost = computed(() => props.trip.cost || null);
const computedAt = computed(() => cost.value?.computedAt || null);
const total = computed(() => Number(props.trip.costTotal) || 0);
const per = (d) => (Number(d) ? fmtNum(total.value / Number(d), 1) : '—');
const kpis = computed(() => {
  const trip = props.trip;
  const delivered = (trip.stops || []).filter((s) => ['delivered', 'partial'].includes(s.status)).length;
  return [{ k: t('تكلفة الرحلة', 'Trip cost'), v: fmtNum(total.value) }, { k: t('لكل توصيلة', 'Per delivery'), v: per(delivered) }, { k: t('لكل كم', 'Per km'), v: per(trip.km) }, { k: t('لكل كجم', 'Per kg'), v: per(trip.kg) }, { k: t('لكل م³', 'Per m³'), v: per(trip.cbm) }];
});
const rows = computed(() => COST_ROWS.map(([k, l]) => {
  const v = Number(cost.value?.[k] || 0);
  const basis = cost.value?.basis?.[k] || null;
  return { k, l: bi(l), v, pct: total.value ? (v / total.value) * 100 : 0, source: basis?.source || null, note: bi(costBasisText(k, basis)) };
}));
/** Components that are 0 only because their data is missing (parking / third party have no account of their own by design). */
const missing = computed(() => rows.value.filter((r) => r.source === 'not_configured' && !['parking', 'third'].includes(r.k)));
const canRecompute = computed(() => auth.can('trip.close'));
const recompute = () => act.run(() => api.postIdempotent(`/transport/trips/${props.trip.number}/cost/recompute`), { success: (r) => t(r?.message, r?.messageEn) || t('أُعيد احتساب التكلفة', 'Cost recomputed'), invalidate: ['transport'] });
</script>

<template>
  <div class="col !gap-3">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-2">
      <div v-for="k in kpis" :key="k.k" class="card sm px-[13px] py-[11px]">
        <div class="num text-[16px] font-bold text-violet">{{ k.v }}</div>
        <div class="mt-0.5 text-[8.5px] font-extrabold text-faint">{{ k.k }}</div>
      </div>
    </div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <SectionCard small>
      <div v-for="r in rows" :key="r.k" class="border-b border-[#F7F6FA] py-1.5 text-[10.5px]">
        <div class="row wrap !gap-2.5">
          <div class="w-[130px] font-extrabold text-sec">{{ r.l }}</div>
          <Chip v-if="r.source" small :map="COST_SOURCE_LABELS" :k="r.source" />
          <div class="h-[7px] min-w-[40px] flex-1 overflow-hidden rounded-full bg-line-2"><div class="h-full bg-violet" :style="{ width: r.pct + '%' }" /></div>
          <div class="num w-[70px] text-end font-bold">{{ fmtNum(r.v, r.v % 1 ? 2 : 0) }}</div>
        </div>
        <div v-if="r.note" class="mt-0.5 text-[9.5px] leading-[1.6]" :class="r.source === 'not_configured' ? 'text-faint' : 'text-muted'">{{ r.note }}</div>
      </div>
      <div class="row justify-between pt-2.5 text-[12px] font-extrabold"><div>{{ t('الإجمالي', 'Total') }}</div><div class="num">{{ fmtNum(total, total % 1 ? 2 : 0) }} {{ t('ر.س', 'SAR') }}</div></div>

      <div v-if="computedAt && missing.length" class="hint amber !mt-2.5">
        {{ t(`${missing.length} بنود بلا مصدر بيانات وتساوي صفرًا (${missing.map((m) => m.l).join('، ')}) — الإجمالي أقل من التكلفة الكاملة إلى أن تُضبط بياناتها.`, `${missing.length} component(s) have no data source and count as 0 (${missing.map((m) => m.l).join(', ')}) — the total is below the full cost until they are set up.`) }}
      </div>
      <div class="row wrap mt-2 !gap-2">
        <div class="min-w-[200px] flex-1 text-[9.5px] leading-[1.7] text-faint">
          <template v-if="computedAt">
            {{ t('آخر احتساب:', 'Last computed:') }} <span class="num">{{ fmtDate(computedAt) }}</span>.
            {{ t('تُحتسب التكلفة عند إقفال الرحلة، ويُعاد احتسابها تلقائيًا عند تسجيل أو تصحيح وقود أو سند مصروف على الرحلة. هي تكلفة إدارية: لا يُرحَّل منها قيد محاسبي.', 'The cost is computed when the trip is closed and again whenever fuel or an expense voucher on the trip is recorded or corrected. It is a management figure: nothing is posted to the ledger from it.') }}
          </template>
          <template v-else-if="total > 0">
            {{ t('هذه الأرقام من الاحتساب السابق (وقود وسندات الرحلة فقط). «إعادة الاحتساب» تضيف وقت السائق والصيانة والإهلاك وتبيّن مصدر كل بند.', 'These figures come from the earlier costing (the trip\'s fuel and vouchers only). "Recompute" adds driver time, maintenance and depreciation and shows where each figure comes from.') }}
          </template>
          <template v-else>
            {{ t('لم تُحتسب تكلفة هذه الرحلة بعد — تُحتسب عند إقفالها، أو عند تسجيل وقود أو سند مصروف عليها، أو بزر «إعادة الاحتساب».', 'This trip has not been costed yet — it is costed when it is closed, when fuel or an expense voucher is recorded on it, or with "Recompute".') }}
          </template>
        </div>
        <Btn v-if="canRecompute" size="sm" tone="outline" :loading="act.pending.value" :label="{ ar: 'إعادة الاحتساب', en: 'Recompute' }" @click="recompute" />
        <span v-else class="text-[9.5px] text-faint">{{ t('إعادة الاحتساب تحتاج صلاحية إقفال الرحلات', 'Recomputing needs the trip-closing permission') }}</span>
      </div>
    </SectionCard>
  </div>
</template>
