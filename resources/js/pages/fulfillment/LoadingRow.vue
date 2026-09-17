<script setup>
// One stop of the loading plan: delivery sequence + load sequence (reverse order), customer, FO link with cartons / kg /
// cbm / packages, state chip and the "confirm load" button. The next order to load is highlighted.
//   <LoadingRow :row="row" :load-seq="3" is-next can-load :loading="act.pending.value" @load="load(row.fo)" />
import { Btn, Chip } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { FO_LABELS } from '@/shared';

defineProps({
  /** LoadRow { stopSeq, customer, fo, status, loaded, loadedAt?, cartons, kg, cbm, packages, ready } */
  row: { type: Object, required: true },
  loadSeq: { type: Number, default: null },
  isNext: { type: Boolean, default: false },
  canLoad: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['load']);
</script>

<template>
  <div class="row wrap !gap-3 rounded-[13px] border px-[15px] py-[11px]"
       :class="[isNext ? 'border-brand shadow-[0_0_0_3px_rgba(27,196,219,.15)]' : 'border-line-2', row.loaded ? 'bg-[#F8FDFE]' : isNext ? 'bg-[#F2FCFD]' : '']">
    <div class="row flex-none !gap-1.5">
      <div class="w-10 text-center"><div class="num text-[14px] text-azure">{{ row.stopSeq }}</div><div class="text-[7.5px] font-extrabold text-faint">{{ t('تسلسل التوصيل', 'Stop seq') }}</div></div>
      <div class="w-10 text-center"><div class="num text-[14px] text-violet">{{ loadSeq }}</div><div class="text-[7.5px] font-extrabold text-faint">{{ t('تسلسل التحميل', 'Load seq') }}</div></div>
    </div>
    <div class="min-w-[190px] flex-1">
      <div class="text-[11.5px] font-extrabold">{{ row.customer }}<span v-if="isNext" class="ms-2 text-[8.5px] text-brand-dark">← {{ t('التالي للتحميل', 'next to load') }}</span></div>
      <div class="num mt-px text-[9px] text-faint">
        <RouterLink :to="`/fo/${encodeURIComponent(row.fo)}`" class="font-bold !text-violet">{{ row.fo }}</RouterLink>
        · {{ row.cartons }} {{ t('كرتون', 'ctn') }} · {{ fmtNum(row.kg) }} {{ t('كجم', 'kg') }} · {{ fmtNum(row.cbm, 1) }} {{ t('م³', 'm³') }}{{ row.packages ? ` · ${row.packages} ${t('طرد', 'pkg')}` : '' }}{{ row.loadedAt ? ` · ${fmtDate(row.loadedAt)}` : '' }}
      </div>
    </div>
    <div>
      <Chip v-if="row.loaded" :label="{ ar: 'محمَّل ✓', en: 'Loaded ✓' }" fg="#0d7f93" bg="#d9f4f9" />
      <Chip v-else :map="FO_LABELS" :k="row.status" />
    </div>
    <span v-if="!row.loaded && !row.ready" class="faint text-[9px]">{{ t('بانتظار التعبئة', 'Awaiting packing') }}</span>
    <Btn v-if="canLoad && row.ready && !row.loaded" tone="dark" class="!h-9 !rounded-[10px] !text-[10.5px]" :loading="loading" :label="{ ar: 'تأكيد التحميل', en: 'Confirm load' }" @click="emit('load')" />
  </div>
</template>
