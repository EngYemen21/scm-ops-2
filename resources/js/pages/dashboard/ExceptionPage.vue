<script setup>
// Exception deep link — /exc/:number. GET /api/exceptions/:number → header (severity / status / SLA countdown),
// description, related-document links, status timeline, ack / resolve actions (`exception.manage`).
// The page title ("استثناء EXC-…") is the shell's default for entity routes.
import { computed, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, KV, PageHead, SectionCard, Timeline } from '@/components';
import { bi, fmtDate, lang, t } from '@/i18n';
import { EXCEPTION_STATE_LABELS } from '@/shared';
import ExceptionActionModal from './ExceptionActionModal.vue';
import ExceptionActions from './ExceptionActions.vue';
import RelatedLinks from './RelatedLinks.vue';
import SevChip from './SevChip.vue';
import SlaBadge from './SlaBadge.vue';
import StateChip from './StateChip.vue';
import { excText, kindLabel, liveSlaOf, roleLabel, slaLabel, useExceptionActions } from './shared';

const STATE_COLOR = { open: '#b23b3b', ack: '#b26a16', resolved: '#1d7a3e' };

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const q = useGet(() => (number.value ? `/exceptions/${encodeURIComponent(number.value)}` : null), undefined, { refetchInterval: 60_000 });
const e = computed(() => q.data.value);
const exc = useExceptionActions();

// Live countdown: the API gives `sla.leftMin` at fetch time — tick it locally every 30 s.
const tick = ref(0);
const timer = setInterval(() => { tick.value += 1; }, 30_000);
onBeforeUnmount(() => clearInterval(timer));
const liveSla = computed(() => { void tick.value; return liveSlaOf(e.value); });

const sub = computed(() => (e.value
  ? `${kindLabel(e.value.kind)} · ${t('المسؤول', 'owner')}: ${roleLabel(e.value.ownerRole)} · ${t('فُتح بواسطة', 'opened by')} ${e.value.createdBy || 'system'} · ${fmtDate(e.value.createdAt)}`
  : null));

/** Timeline rows: "from →" as the label, the target status as a chip (Timeline `#chip` slot). */
const events = computed(() => (e.value?.events || []).map((ev) => ({
  at: ev.at, by: ev.username || 'system', color: STATE_COLOR[ev.toStatus] || '#1BC4DB', note: ev.note, toStatus: ev.toStatus,
  label: ev.fromStatus ? `${bi(EXCEPTION_STATE_LABELS[ev.fromStatus] || { ar: ev.fromStatus, en: ev.fromStatus })} →` : '',
})));
</script>

<template>
  <PageHead :sub="sub">
    <Btn tone="soft" :label="{ ar: '← Control Tower', en: '← Control Tower' }" @click="router.push('/tower')" />
    <ExceptionActions v-if="e" :row="e" size="md" @act="(mode) => exc.open(mode, e.number)" />
  </PageHead>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value && !e" class="card p-[18px]"><div class="skel mb-2.5 h-4 w-2/5" /><div class="skel h-3 w-4/5" /></div>

  <div v-if="e" class="grid-2 items-start">
    <div class="col !gap-3.5">
      <SectionCard>
        <template #title><span class="row wrap"><SevChip :k="e.severity" /><StateChip :k="e.status" /><SlaBadge :sla="liveSla" :sla-hours="e.slaHours" :status="e.status" /></span></template>
        <div class="text-[13px] font-extrabold leading-[1.8] text-ink">{{ excText(e, lang) }}</div>
        <div v-if="lang === 'en' && e.textEn && e.textEn !== e.textAr" class="mt-1 text-[10.5px] leading-[1.8] text-muted" dir="rtl">{{ e.textAr }}</div>
        <div v-if="e.resolution" class="hint green !mt-3"><b>{{ t('القرار / الحل', 'Resolution') }}:</b> {{ e.resolution }}</div>
        <div v-if="e.status !== 'resolved' && liveSla?.breached" class="banner red mt-3">
          <span>{{ t('تم تجاوز SLA لهذا الاستثناء — يتطلب تصعيدًا', 'This exception has breached its SLA — escalate') }} · <span class="num">{{ slaLabel(liveSla, e.slaHours, lang) }}</span></span>
        </div>

        <div class="kv-grid mt-4">
          <KV :k="{ ar: 'النوع', en: 'Kind' }">{{ kindLabel(e.kind) }} <span class="muted num">({{ e.kind }})</span></KV>
          <KV :k="{ ar: 'الدور المسؤول', en: 'Owner role' }"><Chip small :label="roleLabel(e.ownerRole)" fg="#654e92" bg="#efeaf8" /></KV>
          <KV k="SLA"><span class="num">{{ e.slaHours }} {{ t('ساعة', 'h') }} · {{ t('يستحق', 'due') }} {{ fmtDate(e.sla?.dueAt) }}</span></KV>
          <KV :k="{ ar: 'فُتح', en: 'Opened' }"><span class="num">{{ fmtDate(e.createdAt) }}</span></KV>
          <KV :k="{ ar: 'استُلم', en: 'Acknowledged' }"><span class="num">{{ fmtDate(e.acknowledgedAt) }}</span></KV>
          <KV :k="{ ar: 'أُغلق', en: 'Resolved' }"><span class="num">{{ fmtDate(e.resolvedAt) }}</span></KV>
        </div>
      </SectionCard>

      <SectionCard :title="{ ar: 'المستندات المرتبطة — Traceability', en: 'Related documents — traceability' }" small>
        <RelatedLinks :exception="e" />
      </SectionCard>
    </div>

    <SectionCard :title="{ ar: 'سجل الحالة', en: 'Status history' }" :count="events.length">
      <Timeline :items="events" :empty-text="{ ar: 'لا أحداث', en: 'No events' }">
        <template #chip="{ item }"><StateChip :k="item.toStatus" small /></template>
      </Timeline>
    </SectionCard>
  </div>

  <ExceptionActionModal :mode="exc.state.value?.mode" :number="exc.state.value?.number" @close="exc.close()" @done="q.refetch()" />
</template>
