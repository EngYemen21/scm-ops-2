<script setup>
// Trip deep link (/trip/:number): Trip Control Room rendered full-page — dark header, tabs (stops / assignment / capacity /
// timeline / cost / PODs), close & cancel. Same data as the drawer: GET /api/transport/trips/:number (polled every 20s).
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, PageHead } from '@/components';
import { fmtDateOnly, t } from '@/i18n';
import { TRIP_LABELS } from '@/shared';
import { appLink, showLabel } from '@/stores/ui';
import { useTripActions } from './tmsComposables';
import TripBody from './TripBody.vue';
import TripHead from './TripHead.vue';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const tab = ref('stops');

const q = useGet(() => (number.value ? `/transport/trips/${encodeURIComponent(number.value)}` : null), undefined, { refetchInterval: 20_000 });
const trip = computed(() => q.data.value);
const { act, canClose, canCancel, closeTrip, cancelTrip } = useTripActions(trip);

const sub = computed(() => (trip.value
  ? `${t('غرفة تحكم الرحلة', 'Trip Control Room')} · ${t('تاريخ', 'Date')} ${fmtDateOnly(trip.value.date)} · ${t('انطلاق', 'Start')} ${trip.value.plannedStart || '—'}`
  : t('غرفة تحكم الرحلة', 'Trip Control Room')));
</script>

<template>
  <PageHead :title="trip?.number || number" :sub="sub">
    <Chip v-if="trip" :map="TRIP_LABELS" :k="trip.status" />
    <Btn v-if="canClose" tone="success" :loading="act.pending.value" :label="{ ar: 'إقفال الرحلة', en: 'Close trip' }" @click="closeTrip" />
    <Btn v-if="canCancel" tone="dangerOutline" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="cancelTrip" />
    <Btn tone="outline" :label="{ ar: 'لوحة التحميل والإرسال', en: 'Load & dispatch' }" @click="router.push(`/dispatch?trip=${encodeURIComponent(number)}`)" />
    <Btn v-if="trip" tone="soft" :label="{ ar: 'رمز QR', en: 'QR code' }" @click="showLabel({ type: 'qr', text: appLink(`/trip/${encodeURIComponent(trip.number)}`), title: trip.number, sub: { ar: 'رحلة توصيل', en: 'Delivery run' } })" />
    <Btn tone="ghost" :label="{ ar: 'كل الرحلات', en: 'All trips' }" @click="router.push('/trips')" />
  </PageHead>

  <ErrorBanner :error="q.error.value" :closable="false" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value && !trip" class="skel h-40" />
  <div v-if="trip" class="card">
    <TripHead :trip="trip" class="pt-[18px]" />
    <div class="bg-canvas px-[22px] pb-6 pt-3.5">
      <TripBody v-model:tab="tab" :trip="trip" />
    </div>
  </div>
</template>
