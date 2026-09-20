<script setup>
// Phone home, top part (the design's mobile dashboard): greeting + warehouse chip, the dark "order fulfilment" card,
// a swipeable KPI strip and the work-queue tiles. Built ONLY from the figures of GET /api/dashboard — the same numbers
// the desktop KPI grid shows; nothing is estimated, and there are no decorative charts without data behind them.
// Tiles and KPIs that lead to a page the user cannot open are left out.
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import WarehouseChip from '@/layout/mobile/WarehouseChip.vue';
import { ROLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';

const props = defineProps({
  /** GET /api/dashboard response (null while loading) */
  d: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});

const auth = useAuth();
const router = useRouter();
const user = computed(() => auth.user);
const firstName = computed(() => ((lang.value === 'ar' ? user.value?.nameAr : user.value?.nameEn) || '').replace(/^(م|د|أ)\.\s*/, '').split(' ')[0]);
const roleName = computed(() => (user.value?.roles || []).map((r) => (ROLE_LABELS[r] ? ROLE_LABELS[r][lang.value] : r)).join(' · '));
const greeting = computed(() => (new Date().getHours() < 12 ? t('صباح الخير', 'Good morning') : t('مساء الخير', 'Good evening')));

const kpi = computed(() => Object.fromEntries((props.d?.kpis || []).map((k) => [k.key, k])));
const val = (key) => Number(kpi.value[key]?.value ?? 0);
/** A link is usable when its page is in the user's navigation. */
const canOpen = (link) => !!link && (user.value?.nav || []).includes(String(link).split('?')[0].replace(/^\//, ''));
const go = (link) => { if (canOpen(link)) router.push(link); };

// ---- order fulfilment pipeline
const STAGES = [
  { key: 'ordersWaitingAllocation', label: { ar: 'جديد', en: 'New' }, color: '#3c79f5' },
  { key: 'picking', label: { ar: 'قيد التجهيز', en: 'Picking' }, color: '#d9962b' },
  { key: 'packing', label: { ar: 'التعبئة', en: 'Packing' }, color: '#8d73c4' },
  { key: 'readyForDispatch', label: { ar: 'جاهز للشحن', en: 'Ready' }, color: '#1bc4db' },
  { key: 'deliveriesCompletedToday', label: { ar: 'سُلّم اليوم', en: 'Delivered today' }, color: '#3fbf6b' },
];
const stages = computed(() => STAGES.filter((s) => kpi.value[s.key]).map((s) => ({ ...s, value: val(s.key), link: kpi.value[s.key].link })));
const delivered = computed(() => val('deliveriesCompletedToday'));
const total = computed(() => stages.value.reduce((n, s) => n + s.value, 0));
const pct = computed(() => (total.value > 0 ? Math.round((delivered.value / total.value) * 100) : 0));

// ---- KPI strip
const STRIP = [
  ['availableUnits', '#0d7f93'], ['availableValue', '#654e92'], ['lowStock', '#b26a16'], ['expiringSoon', '#b26a16'], ['expired', '#b23b3b'],
  ['expectedInbounds', '#0d7f93'], ['waitingPutaway', '#654e92'], ['returnsOpen', '#0d7f93'], ['exceptionsOpen', '#b26a16'], ['slaBreached', '#b23b3b'],
];
const strip = computed(() => STRIP.filter(([key]) => kpi.value[key]).map(([key, color]) => ({ ...kpi.value[key], color })));
const shown = (k) => (k.key === 'availableValue' ? fmtMoney(k.value) : fmtNum(k.value));

// ---- work queues
const QUEUES = [
  { key: 'picking', label: { ar: 'للتجهيز', en: 'To pick' }, sub: 'Picking', tint: ['#fbf0dd', '#b26a16'] },
  { key: 'packing', label: { ar: 'للتعبئة', en: 'To pack' }, sub: 'Packing', tint: ['#efeaf8', '#654e92'] },
  { key: 'readyForDispatch', label: { ar: 'للشحن', en: 'To dispatch' }, sub: 'Dispatch', tint: ['#e8effe', '#3c79f5'] },
  { key: 'expectedInbounds', label: { ar: 'شحنات واردة', en: 'Inbound' }, sub: 'Receiving', tint: ['#d9f4f9', '#0d7f93'] },
  { key: 'waitingQc', label: { ar: 'بانتظار الفحص', en: 'Waiting QC' }, sub: 'QC', tint: ['#fbf0dd', '#b26a16'] },
  { key: 'tripsToday', label: { ar: 'رحلات اليوم', en: 'Trips today' }, sub: 'Trips', tint: ['#e8effe', '#3c79f5'] },
];
const queues = computed(() => QUEUES.filter((q) => kpi.value[q.key] && canOpen(kpi.value[q.key].link)).map((q) => ({ ...q, value: val(q.key), link: kpi.value[q.key].link })));
</script>

<template>
  <div class="m-home">
    <div class="m-greet">
      <div class="m-avatar sm">{{ user?.initials || firstName.slice(0, 1) }}</div>
      <div class="min-w-0 flex-1">
        <div class="ellipsis text-[15px] font-extrabold">{{ greeting }}{{ firstName ? t('، ', ', ') + firstName : '' }}</div>
        <div class="ellipsis mt-0.5 text-[11px] text-muted">{{ roleName }}</div>
      </div>
      <WarehouseChip />
    </div>

    <!-- order fulfilment -->
    <div class="m-hero">
      <div class="flex items-center">
        <div class="flex-1 text-[12.5px] font-extrabold text-white">{{ t('تنفيذ الطلبات', 'Order fulfilment') }}</div>
        <div class="num ltr text-[10.5px] text-[#8b90a5]">{{ fmtDateOnly(new Date()) }}</div>
      </div>
      <template v-if="loading"><div class="skel mt-3 h-8 w-24 !bg-[#2a2e42] !bg-none" /></template>
      <template v-else>
        <div class="mt-2 flex items-end gap-2">
          <div class="num ltr text-[34px] leading-none text-white">{{ fmtNum(delivered) }}</div>
          <div class="num ltr pb-1 text-[13px] text-[#8b90a5]">/ {{ fmtNum(total) }}</div>
          <div class="flex-1" />
          <div class="pb-1 text-[11.5px] font-extrabold text-brand"><span class="num">{{ pct }}%</span> {{ t('سُلّم', 'delivered') }}</div>
        </div>
        <div class="m-hero-bar"><div :style="{ width: `${pct}%` }" /></div>
        <div class="m-hero-chips">
          <button v-for="s in stages" :key="s.key" type="button" class="m-hero-chip" @click="go(s.link)">
            <span class="m-dot" :style="{ background: s.color }" />{{ t(s.label.ar, s.label.en) }} <span class="num">{{ fmtNum(s.value) }}</span>
          </button>
        </div>
      </template>
    </div>

    <!-- KPI strip -->
    <div class="m-sec-head"><span>{{ t('المخزون والعمليات', 'Stock & operations') }}</span></div>
    <div class="m-strip">
      <template v-if="loading"><div v-for="i in 3" :key="i" class="m-strip-card"><div class="skel h-3 w-2/3" /><div class="skel mt-3 h-6 w-1/2" /></div></template>
      <button v-for="k in strip" v-else :key="k.key" type="button" class="m-strip-card" :class="{ '!cursor-default': !canOpen(k.link) }" @click="go(k.link)">
        <div class="flex items-center gap-1.5 text-[10.5px] font-extrabold text-muted"><span class="m-dot" :style="{ background: k.color }" /><span class="ellipsis">{{ lang === 'ar' ? k.labelAr : k.labelEn }}</span></div>
        <div class="num ltr mt-2 text-start text-[24px] leading-none" :style="{ color: k.color }">{{ shown(k) }}</div>
        <div class="num-mixed ellipsis mt-2 text-[9.5px] text-faint">{{ k.hint || ' ' }}</div>
      </button>
    </div>

    <!-- work queues -->
    <template v-if="queues.length">
      <div class="m-sec-head"><span>{{ t('طوابير العمل', 'Work queues') }}</span></div>
      <div class="m-queues">
        <button v-for="q in queues" :key="q.key" type="button" class="m-queue" @click="go(q.link)">
          <span class="m-queue-n num" :style="{ background: q.tint[0], color: q.tint[1] }">{{ fmtNum(q.value) }}</span>
          <span class="min-w-0 flex-1 text-start">
            <span class="ellipsis block text-[12.5px] font-extrabold text-ink">{{ t(q.label.ar, q.label.en) }}</span>
            <span class="num-mixed block text-[9.5px] text-faint">{{ q.sub }}</span>
          </span>
        </button>
      </div>
    </template>
  </div>
</template>
