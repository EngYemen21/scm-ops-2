<script setup>
// The small summary on top of the page: a few figures of GET /hr/summary, each a door to the list it counts.
// A person sees only the cards of what they may open (the API leaves the other parts out of the answer).
//   <SummaryStrip :summary="s" :loading @go="(tab, params) => nav.go(tab, params)" />
import { computed } from 'vue';
import { KpiCard, KpiGrid } from '@/components';
import { fmtNum } from '@/i18n';
import { money } from './shared';

const props = defineProps({
  summary: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['go']);

const RED = '#b23b3b';
const AMBER = '#b26a16';
const cards = computed(() => {
  const s = props.summary;
  if (!s) return [];
  const out = [];
  if (s.directory) {
    out.push({ k: 'employees', value: s.directory.employees, label: { ar: 'موظفون على رأس العمل', en: 'Active employees' },
      sub: { ar: `${fmtNum(s.directory.departments)} قسمًا · ${fmtNum(s.directory.jobs)} مسمى وظيفيًا`, en: `${fmtNum(s.directory.departments)} departments · ${fmtNum(s.directory.jobs)} job positions` }, go: ['directory', {}] });
  }
  if (s.due) {
    out.push({ k: 'expired', value: s.due.expired, color: s.due.expired ? RED : undefined, label: { ar: 'وثائق وعقود منتهية', en: 'Expired papers' }, go: ['due', { stage: 'expired' }] });
    out.push({ k: 'soon', value: s.due.d7 + s.due.d30, color: s.due.d7 + s.due.d30 ? AMBER : undefined, label: { ar: 'تنتهي خلال 30 يومًا', en: 'Expiring within 30 days' },
      sub: { ar: `و${fmtNum(s.due.d60)} خلال 60 يومًا`, en: `and ${fmtNum(s.due.d60)} within 60 days` }, go: ['due', { stage: 'd7,d30' }] });
  }
  if (s.contracts) {
    out.push({ k: 'contracts', value: s.contracts.running, label: { ar: 'عقود سارية', en: 'Running contracts' },
      sub: s.contracts.withoutContract ? { ar: `${fmtNum(s.contracts.withoutContract)} موظفًا بلا عقد سارٍ`, en: `${fmtNum(s.contracts.withoutContract)} employee(s) without a running contract` } : null, go: ['contracts', { state: 'running' }] });
  }
  const mine = s.claims?.mine;
  if (mine) {
    out.push({ k: 'mine', value: mine.draft + mine.submitted + mine.approved, label: { ar: 'مطالباتي المفتوحة', en: 'My open claims' },
      sub: Number(mine.toReceive) > 0 ? { ar: `مستحق لي ${money(mine.toReceive)} ر.س`, en: `SAR ${money(mine.toReceive)} due to me` } : null, go: ['claims', { scope: 'mine' }] });
  }
  const review = s.claims?.review;
  if (review) {
    out.push({ k: 'waiting', value: review.waiting, color: review.waiting ? AMBER : undefined, label: { ar: 'مطالبات تنتظر المراجعة', en: 'Claims waiting for review' },
      sub: review.waiting ? { ar: `${money(review.waitingTotal)} ر.س`, en: `SAR ${money(review.waitingTotal)}` } : null, go: ['claims', { scope: 'review', state: 'submitted' }] });
    out.push({ k: 'toPost', value: review.toPost, label: { ar: 'معتمدة تنتظر الترحيل', en: 'Approved, to be posted' }, go: ['claims', { scope: 'review', state: 'approved' }] });
    out.push({ k: 'toPay', value: review.toPay, label: { ar: 'مرحَّلة تنتظر السداد', en: 'Posted, to be paid' },
      sub: review.toPay ? { ar: `${money(review.toPayTotal)} ر.س للموظفين`, en: `SAR ${money(review.toPayTotal)} owed to employees` } : null, go: ['claims', { scope: 'review', state: 'posted' }] });
  }
  return out;
});
</script>

<template>
  <KpiGrid v-if="loading || cards.length" compact>
    <template v-if="loading && !summary">
      <KpiCard v-for="i in 4" :key="i" compact loading :label="{ ar: '…', en: '…' }" />
    </template>
    <KpiCard v-for="c in cards" :key="c.k" compact clickable :value="c.value" :label="c.label" :sub="c.sub" :color="c.color" @click="emit('go', c.go[0], c.go[1])" />
  </KpiGrid>
</template>
