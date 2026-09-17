<script setup>
// Trip room · "Assignment": smart vehicle recommendation (score + reasons, or why a vehicle is unfit) and the driver list
// (busy driver / expired license shown as the rejection reason). Assign / unassign only while the trip is editable.
//   GET /transport/trips/:n/recommend → [{ vehicle, ok, score, reasons[], why }]
import { computed } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, SectionCard } from '@/components';
import { bi, fmtNum, lang, t } from '@/i18n';
import { VEHICLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { DRIVER_STATE_LABELS, SHIFT_LABELS, TEMP_LABELS, VEHICLE_KIND_LABELS, drvName, labelOf, whyNot } from './tms';

const props = defineProps({ trip: { type: Object, required: true } });

const auth = useAuth();
const act = useAction();
const rec = useGet(() => `/transport/trips/${encodeURIComponent(props.trip.number)}/recommend`);
const drivers = useList('/transport/drivers', { pageSize: 100 });

const editable = computed(() => !!props.trip.canEdit);
const canAssign = computed(() => auth.can('trip.assign') && editable.value);
const rows = computed(() => rec.data.value || []);
const firstOk = computed(() => rows.value.find((r) => r.ok)?.vehicle?.code);
const driverRows = computed(() => drivers.data.value?.items || []);

/** @param {{ vehicleCode?: string, driverCode?: string }} body */
const assign = (body) => act.run(() => api.postIdempotent(`/transport/trips/${props.trip.number}/assign`, body), { success: (r) => r?.message || t('تم الإسناد', 'Assigned'), invalidate: ['transport'] });
/** @param {{ vehicle?: boolean, driver?: boolean }} body */
const unassign = (body) => act.run(() => api.postIdempotent(`/transport/trips/${props.trip.number}/unassign`, body), { success: (r) => r?.message || t('أُلغي الإسناد', 'Unassigned'), invalidate: ['transport'] });

const scoreColor = (r) => (!r.ok ? '#b23b3b' : r.score >= 70 ? '#1d7a3e' : r.score >= 45 ? '#b26a16' : '#7d7990');
const driverOk = (d) => d.canAssign?.ok !== false && !d.blocked;
</script>

<template>
  <div class="col !gap-3">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="!editable" class="hint teal !mt-0">{{ t('الإسناد متاح فقط قبل بدء التحميل (مسودة / مخططة / مسندة).', 'Assignment is only possible before loading starts (draft / planned / assigned).') }}</div>

    <SectionCard small :padded="false">
      <template #title>
        <span>{{ t('اقتراح المركبة الذكي', 'Smart vehicle recommendation') }}
          <span class="text-[9.5px] font-bold text-faint">— {{ t('وزن', 'weight') }} <span class="num">{{ fmtNum(trip.kg) }}</span> {{ t('كجم', 'kg') }} · <span class="num">{{ fmtNum(trip.cbm, 1) }}</span> {{ t('م³', 'm³') }} · <span class="num">{{ fmtNum(trip.pallets) }}</span> {{ t('طبلية', 'pallets') }} · {{ bi(labelOf(TEMP_LABELS, trip.tempNeed)) }}</span>
        </span>
      </template>
      <template v-if="trip.vehicle && canAssign" #actions>
        <Btn tone="softRed" size="sm" :label="{ ar: 'إلغاء إسناد المركبة', en: 'Unassign vehicle' }" @click="unassign({ vehicle: true })" />
      </template>

      <div v-if="rec.isLoading.value" class="skel m-3.5 h-20" />
      <ErrorBanner :error="rec.error.value" :closable="false" class="m-3" />
      <div v-for="r in rows" :key="r.vehicle.code" class="row wrap !gap-2.5 border-t border-line-2 px-[15px] py-2.5">
        <div class="min-w-[170px]">
          <div class="row !gap-1.5">
            <span class="num text-[11.5px] font-extrabold">{{ r.vehicle.code }}</span>
            <Chip v-if="r.ok && r.vehicle.code === firstOk" small fg="#0d7f93" bg="#d9f4f9" :label="{ ar: 'الترشيح الأول', en: 'Recommended' }" />
            <Chip v-if="trip.vehicle?.code === r.vehicle.code" small fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'مسندة ✓', en: 'Assigned ✓' }" />
          </div>
          <div class="mt-px text-[9px] text-faint">
            {{ (lang === 'en' && r.vehicle.typeEn) || r.vehicle.typeAr || bi(labelOf(VEHICLE_KIND_LABELS, r.vehicle.kind)) }} · <span class="num">{{ fmtNum(r.vehicle.maxKg) }}</span> {{ t('كجم', 'kg') }} · <span class="num">{{ fmtNum(r.vehicle.maxCbm, 1) }}</span> {{ t('م³', 'm³') }} · {{ r.vehicle.warehouse || '—' }} · <Chip small :map="VEHICLE_LABELS" :k="r.vehicle.state" />
          </div>
        </div>
        <div class="num min-w-9 text-[15px] font-bold" :style="{ color: scoreColor(r) }">{{ r.ok ? r.score : '✕' }}</div>
        <div class="min-w-[180px] flex-1 text-[9.5px] leading-[1.6]" :class="r.ok ? 'text-sec' : 'text-bad'">{{ r.ok ? (r.reasons || []).join(' · ') : r.why }}</div>
        <Btn v-if="r.ok && canAssign && trip.vehicle?.code !== r.vehicle.code" tone="dark" size="sm" :loading="act.pending.value" :label="{ ar: 'إسناد المركبة', en: 'Assign vehicle' }" @click="assign({ vehicleCode: r.vehicle.code })" />
      </div>
      <EmptyState v-if="!rec.isLoading.value && rows.length === 0" :text="{ ar: 'لا مركبات نشطة', en: 'No active vehicles' }" />
    </SectionCard>

    <SectionCard small :padded="false" :title="{ ar: 'السائقون', en: 'Drivers' }">
      <template v-if="trip.driver && canAssign" #actions>
        <Btn tone="softRed" size="sm" :label="{ ar: 'إلغاء إسناد السائق', en: 'Unassign driver' }" @click="unassign({ driver: true })" />
      </template>
      <div v-if="drivers.isLoading.value" class="skel m-3.5 h-[60px]" />
      <div v-for="d in driverRows" :key="d.code" class="row wrap !gap-2.5 border-t border-line-2 px-[15px] py-2.5">
        <div class="min-w-[170px]">
          <div class="row !gap-1.5">
            <span class="text-[11.5px] font-extrabold">{{ drvName(d, lang) }}</span>
            <Chip v-if="trip.driver?.code === d.code" small fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'مسند ✓', en: 'Assigned ✓' }" />
          </div>
          <div class="mt-px text-[9px] text-faint"><span class="num">{{ d.code }}</span> · {{ bi(labelOf(SHIFT_LABELS, d.shift, (lang === 'en' && d.shiftEn) || d.shift || '—')) }}</div>
        </div>
        <div class="text-[9.5px] text-sec">{{ t('في الموعد', 'On-time') }} <b class="num text-brand-dark">{{ fmtNum(d.ontimePct) }}%</b> · {{ t('السلامة', 'Safety') }} <b class="num">{{ fmtNum(d.safety) }}</b></div>
        <div class="flex-1" />
        <span v-if="!driverOk(d) && d.canAssign?.why" class="text-[9px] text-bad">{{ whyNot(d.canAssign, lang) }}</span>
        <Chip :map="DRIVER_STATE_LABELS" :k="d.blocked ? 'blocked' : d.state" />
        <Btn v-if="driverOk(d) && canAssign && trip.driver?.code !== d.code" tone="purple" size="sm" :loading="act.pending.value" :label="{ ar: 'إسناد السائق', en: 'Assign driver' }" @click="assign({ driverCode: d.code })" />
      </div>
    </SectionCard>
  </div>
</template>
