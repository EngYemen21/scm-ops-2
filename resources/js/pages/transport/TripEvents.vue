<script setup>
// Trip room · "Timeline": the trip's event log + "add event" (trip.manage) → POST /transport/trips/:n/events { textAr }.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, SectionCard, TextInput } from '@/components';
import { fmtDateOnly, fmtTime, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';

const props = defineProps({ trip: { type: Object, required: true } });

const auth = useAuth();
const act = useAction();
const text = ref('');
const events = computed(() => props.trip.events || []);

async function record() {
  if (!text.value.trim()) return;
  const r = await act.run(() => api.postIdempotent(`/transport/trips/${props.trip.number}/events`, { textAr: text.value.trim() }), { success: (x) => x?.message || t('سُجل الحدث', 'Event recorded'), invalidate: ['transport'] });
  if (r !== undefined) text.value = '';
}
</script>

<template>
  <div class="col !gap-3">
    <SectionCard small>
      <EmptyState v-if="events.length === 0" :text="{ ar: 'لا أحداث بعد', en: 'No events yet' }" />
      <div v-for="e in events" :key="e.id" class="row !items-baseline !gap-3 border-b border-[#F7F6FA] py-[7px]">
        <div dir="ltr" class="num w-11 flex-none text-[10px] font-bold text-brand-dark">{{ fmtTime(e.at) }}</div>
        <div class="h-[7px] w-[7px] flex-none rounded-full bg-[#c9c6d4]" />
        <div class="flex-1 text-[10.5px] font-bold leading-[1.7] text-sec">
          {{ (lang === 'en' && e.textEn) || e.textAr }}<span v-if="e.label" class="num text-[8.5px] text-faint"> · {{ e.label }}</span>
          <div class="num text-[8.5px] !font-normal text-faint">{{ fmtDateOnly(e.at) }}</div>
        </div>
      </div>
    </SectionCard>
    <SectionCard v-if="auth.can('trip.manage')" small :title="{ ar: 'إضافة حدث', en: 'Add event' }">
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="row !items-end">
        <div class="flex-1"><TextInput v-model="text" :label="{ ar: 'النص', en: 'Text' }" full /></div>
        <Btn tone="dark" :loading="act.pending.value" :disabled="!text.trim()" :label="{ ar: 'تسجيل', en: 'Record' }" @click="record" />
      </div>
    </SectionCard>
  </div>
</template>
