<script setup>
// One lead as a card of the board: what it is, who it is, what it is worth, who works it and what comes next — the
// next to-do in red when its day has passed. A card of Odoo carries the «من أودو» chip (it is moved there, not here).
//   <LeadCard :lead="card" @open="…" />
import { computed } from 'vue';
import { Chip } from '@/components';
import { t } from '@/i18n';
import { ARCHIVED_CHIP, LEAD_STATUS, OWNED_CHIP, activityTypeLabel, dueLabel, leadWho, money, nameOf, stars, tagColor } from './crm';

const props = defineProps({
  /** A row of GET /crm/board or /crm/leads. */
  lead: { type: Object, required: true },
  /** Show the status chip (a card outside the board's own columns). */
  showStatus: { type: Boolean, default: false },
});
const emit = defineEmits(['open']);

const who = computed(() => [leadWho(props.lead), props.lead.city].filter(Boolean).join(' · '));
const next = computed(() => props.lead.nextActivity || null);
const nextClass = computed(() => (next.value?.overdue ? 'font-extrabold text-bad' : next.value?.today ? 'font-extrabold text-warn' : 'text-muted'));
</script>

<template>
  <div class="cursor-pointer rounded-[13px] border border-line bg-white p-2.5 hover:border-[#7FD6E5]" role="button" tabindex="0" @click="emit('open', lead)" @keydown.enter="emit('open', lead)">
    <div class="flex items-start gap-1.5">
      <div class="min-w-0 flex-1 text-[11.5px] font-extrabold leading-[1.55] text-ink [overflow-wrap:anywhere]" dir="auto">{{ lead.name }}</div>
      <span v-if="lead.priority" class="flex-none text-[11px] leading-[1.5] text-warn" :title="t('الأولوية', 'Priority')">{{ stars(lead.priority) }}</span>
    </div>
    <div v-if="who" class="mt-0.5 truncate text-[10px] text-sec" dir="auto">{{ who }}</div>
    <div class="mt-1.5 flex flex-wrap items-center gap-1">
      <span v-if="Number(lead.expectedRevenue) > 0" class="num ltr text-[10.5px] text-ink">{{ money(lead.expectedRevenue) }}</span>
      <Chip v-if="showStatus" small :map="LEAD_STATUS" :k="lead.status" />
      <Chip v-if="lead.archived" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
      <Chip v-for="tg in lead.tags" :key="tg.id" small :label="tg.name" :fg="tagColor(tg.color).fg" :bg="tagColor(tg.color).bg" />
      <Chip v-if="lead.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
    </div>
    <div class="mt-1.5 flex items-center gap-2 text-[9.5px]">
      <span class="min-w-0 flex-1 truncate text-muted" dir="auto">{{ lead.owner ? nameOf(lead.owner) : t('بلا مندوب', 'Unassigned') }}</span>
      <span v-if="next" class="flex-none" :class="nextClass" :title="next.summary || ''">{{ activityTypeLabel(next.type) }} · <span class="num">{{ next.overdue ? t('متأخر', 'late') + ' ' : '' }}{{ dueLabel(next) }}</span></span>
      <span v-else-if="lead.status === 'open'" class="flex-none text-faint">{{ t('لا نشاط مخطط', 'Nothing planned') }}</span>
    </div>
  </div>
</template>
