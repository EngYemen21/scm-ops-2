<script setup>
// Trip Control Room — 760px drawer with a dark header. Same data as the full page: GET /api/transport/trips/:number (polled every 20s).
//   <TripRoom :number="openTrip" initial-tab="stops" @close="openTrip = null" />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import { TRIP_LABELS } from '@/shared';
import { useTripActions } from './tmsComposables';
import TripBody from './TripBody.vue';
import TripHead from './TripHead.vue';

const props = defineProps({
  number: { type: String, default: null },
  initialTab: { type: String, default: 'stops' },
});
const emit = defineEmits(['close']);

const router = useRouter();
const tab = ref(props.initialTab);
watch(() => [props.number, props.initialTab], () => { tab.value = props.initialTab; });

const q = useGet(() => (props.number ? `/transport/trips/${encodeURIComponent(props.number)}` : null), undefined, { refetchInterval: 20_000 });
// Only show the trip that is currently asked for (the previous one may still be in the query while the next loads).
const trip = computed(() => (props.number ? q.data.value : null));
const { act, canClose, canCancel, closeTrip, cancelTrip } = useTripActions(trip);
const shownNumber = computed(() => trip.value?.number || props.number || '');
</script>

<template>
  <Drawer :open="!!number" :width="760" dark dark-head :z-index="62" body-class="!p-0 !pb-[30px]" @close="emit('close')">
    <template #title><span class="num text-[16px]">{{ shownNumber }}</span></template>
    <template v-if="trip" #sub>
      <span class="text-[#8b90a5]">{{ t('تاريخ', 'Date') }} <span class="num">{{ fmtDateOnly(trip.date) }}</span> · {{ t('انطلاق', 'Start') }} <span class="num">{{ trip.plannedStart || '—' }}</span></span>
    </template>
    <template #headExtra>
      <div class="row wrap !gap-1.5">
        <Chip v-if="trip" :map="TRIP_LABELS" :k="trip.status" />
        <Btn v-if="canClose" tone="success" size="sm" :loading="act.pending.value" :label="{ ar: 'إقفال الرحلة', en: 'Close trip' }" @click="closeTrip" />
        <Btn v-if="canCancel" tone="dangerOutline" size="sm" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="cancelTrip" />
        <button type="button" class="btn sm !bg-night-3 !text-[#c9cde0]" @click="router.push(`/dispatch?trip=${encodeURIComponent(shownNumber)}`)">{{ t('لوحة التحميل والإرسال', 'Load & dispatch') }}</button>
        <button type="button" class="btn sm !bg-night-3 !text-[#c9cde0]" @click="router.push(`/trip/${encodeURIComponent(shownNumber)}`)">{{ t('صفحة كاملة', 'Full page') }}</button>
      </div>
    </template>

    <TripHead v-if="trip" :trip="trip" />
    <div class="px-[22px] pt-3.5">
      <ErrorBanner :error="q.error.value" :closable="false" />
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div v-if="q.isLoading.value && !trip" class="skel h-[120px]" />
      <TripBody v-if="trip" v-model:tab="tab" :trip="trip" />
    </div>
  </Drawer>
</template>
