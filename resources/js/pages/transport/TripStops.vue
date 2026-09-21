<script setup>
// Trip room · "Stops & map": honest map placeholder + the stop list with status chips, POD / fail-reason notes.
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { Chip, EmptyState } from '@/components';
import { fmtDate, fmtNum, fmtTime, lang, t } from '@/i18n';
import { STOP_LABELS } from '@/shared';
import { isMobile } from '@/composables/viewport';
import TripMap from './TripMap.vue';
import { STOP_DOT } from './tms';

const props = defineProps({ trip: { type: Object, required: true } });
const stops = computed(() => props.trip.stops || []);
const stopTime = (s) => s.actualTime || s.plannedTime || (s.arrivedAt ? fmtTime(s.arrivedAt) : '—');
</script>

<template>
  <div>
    <TripMap :number="trip.number" :height="isMobile ? 240 : 300" />
    <div class="col mt-3">
      <EmptyState v-if="stops.length === 0" tone="dashed" :text="{ ar: 'لا محطات على هذه الرحلة', en: 'No stops on this trip' }" />
      <div v-for="s in stops" :key="s.id" class="card sm px-[15px] py-3">
        <div class="row wrap !gap-2.5">
          <div class="num flex h-[26px] w-[26px] flex-none items-center justify-center rounded-full text-[10px] font-bold text-white" :style="{ background: STOP_DOT[s.status] || '#a8a4b8' }">{{ s.seq }}</div>
          <div class="min-w-[150px] flex-1">
            <div class="text-[11.5px] font-extrabold">{{ (lang === 'en' && s.customerEn) || s.customerAr || s.customer?.nameAr }}</div>
            <div class="num mt-px text-[8.5px] text-faint">
              <RouterLink v-if="s.fo?.number" :to="`/fo/${encodeURIComponent(s.fo.number)}`" class="cell-id">{{ s.fo.number }}</RouterLink>{{ s.invoice ? ` · ${s.invoice}` : '' }}{{ s.fo?.status ? ` · ${s.fo.status}` : '' }}
            </div>
          </div>
          <div class="num text-[10px] font-bold text-brand-dark">{{ stopTime(s) }}</div>
          <Chip :map="STOP_LABELS" :k="s.status" />
        </div>
        <div class="row wrap mt-2 !gap-3.5 text-[9.5px] text-sec">
          <div><span class="font-extrabold text-faint">{{ t('الأصناف', 'Items') }}</span> <span class="num">{{ fmtNum(s.items) }}</span>{{ s.fo?.cartons != null ? ` · ${fmtNum(s.fo.cartons)} ${t('كرتون', 'ctn')}` : '' }}</div>
          <div><span class="font-extrabold text-faint">{{ t('كجم', 'kg') }}</span> <span class="num">{{ fmtNum(s.kg || s.fo?.weightKg, 1) }}</span></div>
          <div><span class="font-extrabold text-faint">{{ t('النافذة', 'Window') }}</span> <span dir="ltr" class="num">{{ s.window || '—' }}</span></div>
          <div><span class="font-extrabold text-faint">{{ t('جهة الاتصال', 'Contact') }}</span> {{ s.contact || s.customer?.contact || '—' }}</div>
          <div v-if="s.address || s.customer?.address"><span class="font-extrabold text-faint">{{ t('العنوان', 'Address') }}</span> {{ s.address || s.customer?.address }}</div>
        </div>
        <div v-if="s.note" class="hint amber !mt-[7px] !px-2.5 !py-[5px] !text-[9.5px]">{{ s.note }}</div>
        <div v-if="s.pod" class="hint green !mt-[7px] !px-2.5 !py-[5px] !text-[9.5px] font-bold">POD <span class="num">{{ s.pod.number }}</span> · {{ s.pod.result }} · <span class="num">{{ fmtDate(s.pod.at) }}</span> · {{ t('المرفقات: Integration Pending', 'Attachments: Integration Pending') }}</div>
        <div v-if="s.failReason" class="hint !mt-[7px] !border-0 !bg-bad-soft !px-2.5 !py-[5px] !text-[9.5px] font-bold !text-bad">{{ t('سبب الفشل', 'Fail reason') }}: {{ s.failReason }}</div>
      </div>
    </div>
  </div>
</template>
