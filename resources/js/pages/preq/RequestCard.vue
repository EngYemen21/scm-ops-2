<script setup>
// One request as a card of the review board: its picture, what is asked for, who asked, and how long it has stood
// where it stands — the age in the colour of its urgency. A card of Odoo carries the «من أودو» chip.
//   <RequestCard :request="card" @open="…" />
import { computed } from 'vue';
import { Chip } from '@/components';
import { t } from '@/i18n';
import PreqPicture from './PreqPicture.vue';
import { EXISTING_CHIP, SOURCE_CHIP, STATUS, TONE, ageText, ageTone, fmtPrice, otherName, personName, productName, refName } from './preq';

const props = defineProps({
  /** A card of GET /product-requests/board or a row of the list. */
  request: { type: Object, required: true },
  /** Show the status chip (a card outside the board's own columns). */
  showStatus: { type: Boolean, default: false },
});
const emit = defineEmits(['open']);

const age = computed(() => ageText(props.request));
const tone = computed(() => ageTone(props.request.ageDays));
const about = computed(() => [refName(props.request.category, props.request.categoryName), refName(props.request.supplier, props.request.supplierName)].filter(Boolean).join(' · '));
</script>

<template>
  <div class="cursor-pointer rounded-[13px] border border-line bg-white p-2.5 hover:border-[#7FD6E5]" role="button" tabindex="0" @click="emit('open', request)" @keydown.enter="emit('open', request)">
    <div class="flex items-start gap-2">
      <PreqPicture :picture="request.picture" class="h-11 w-11 rounded-[10px]" />
      <div class="min-w-0 flex-1">
        <div class="text-[11.5px] font-extrabold leading-[1.55] text-ink [overflow-wrap:anywhere]" dir="auto">{{ productName(request) }}</div>
        <div v-if="otherName(request)" class="truncate text-[9.5px] text-muted" dir="auto">{{ otherName(request) }}</div>
      </div>
    </div>
    <div v-if="about" class="mt-1 truncate text-[10px] text-sec" dir="auto">{{ about }}</div>
    <div class="mt-1.5 flex flex-wrap items-center gap-1">
      <span class="cell-id !text-[9.5px]">{{ request.number }}</span>
      <Chip v-if="showStatus" small :map="STATUS" :k="request.status" />
      <Chip v-if="request.requestType === 'existing'" small :label="EXISTING_CHIP" :fg="EXISTING_CHIP.fg" :bg="EXISTING_CHIP.bg" />
      <Chip v-if="request.sourceSystem === 'odoo'" small :label="SOURCE_CHIP" :fg="SOURCE_CHIP.fg" :bg="SOURCE_CHIP.bg" />
      <Chip v-if="request.returnsCount > 0" small :label="{ ar: `أُعيد ${request.returnsCount}×`, en: `Returned ${request.returnsCount}×` }" :fg="TONE.violet.fg" :bg="TONE.violet.bg" />
      <span v-if="request.salePrice != null" class="num ltr text-[10px] text-ink">{{ fmtPrice(request.salePrice, request.currency) }}</span>
    </div>
    <div class="mt-1.5 flex items-center gap-2 text-[9.5px]">
      <span class="min-w-0 flex-1 truncate text-muted" dir="auto">{{ request.requestedBy ? personName(request.requestedBy) : t('بلا صاحب', 'Nobody named') }}<template v-if="request.customerName || request.customer"> · {{ refName(request.customer, request.customerName) }}</template></span>
      <span v-if="age" class="flex-none rounded-full px-1.5 py-px font-extrabold" :style="{ color: tone.fg, background: tone.bg }" :title="t('منذ دخوله هذه الحالة', 'Since it entered this state')">{{ age }}</span>
    </div>
  </div>
</template>
