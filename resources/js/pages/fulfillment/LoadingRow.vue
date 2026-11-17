<script setup>
// One stop of the loading plan: delivery sequence + load sequence, customer, FO link with cartons / kg / cbm / packages,
// state chip and its actions. The load sequence is the REAL one (trip_orders.load_seq) once the order is on the truck;
// before that the planned position is shown, marked as planned. An order that may not go in yet says what it waits for
// (a later stop still being prepared, or a later stop to load first). Actions: «تأكيد التحميل» (disabled with the reason),
// «تجاوز الترتيب» for a supervisor, «إنزال» for an order on the truck.
//   <LoadingRow :row="row" :plan-seq="3" is-next can-load :load-why="null" can-override can-unload :loading="…" @load @override @unload />
import { computed } from 'vue';
import { Btn, Chip } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { FO_LABELS } from '@/shared';

const props = defineProps({
  /** LoadRow { stopSeq, customer, fo, so?, status, loaded, loadedAt?, loadSeq?, cartons, kg, cbm, packages, ready, waitFor: string[], loadFirst: string[] } */
  row: { type: Object, required: true },
  /** Planned position in the reverse-order plan (1 = goes in first). */
  planSeq: { type: Number, default: null },
  isNext: { type: Boolean, default: false },
  /** The user may load on this trip at all (permission + trip state). */
  canLoad: { type: Boolean, default: false },
  /** Why the load button cannot work right now (no vehicle, no scan …); null = it can. */
  loadWhy: { type: String, default: null },
  canOverride: { type: Boolean, default: false },
  canUnload: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['load', 'override', 'unload']);

const waitFor = computed(() => props.row.waitFor || []);
const loadFirst = computed(() => props.row.loadFirst || []);
const outOfTurn = computed(() => waitFor.value.length > 0 || loadFirst.value.length > 0);
const turnWhy = computed(() => (waitFor.value.length
  ? t(`ينتظر تعبئة محطات لاحقة: ${waitFor.value.join('، ')} — تُحمَّل قبله (أو تُزال من الرحلة)`, `Waits for later stops still being prepared: ${waitFor.value.join(', ')} — they load first (or leave the trip)`)
  : loadFirst.value.length ? t(`حمّل قبله: ${loadFirst.value.join('، ')}`, `Load first: ${loadFirst.value.join(', ')}`) : null));
const why = computed(() => props.loadWhy || turnWhy.value);
</script>

<template>
  <div class="row wrap !gap-3 rounded-[13px] border px-[15px] py-[11px]"
       :class="[isNext ? 'border-brand shadow-[0_0_0_3px_rgba(27,196,219,.15)]' : 'border-line-2', row.loaded ? 'bg-[#F8FDFE]' : isNext ? 'bg-[#F2FCFD]' : '']">
    <div class="row flex-none !gap-1.5">
      <div class="w-10 text-center"><div class="num text-[14px] text-azure">{{ row.stopSeq }}</div><div class="text-[7.5px] font-extrabold text-faint">{{ t('تسلسل التوصيل', 'Stop seq') }}</div></div>
      <div class="w-12 text-center">
        <div class="num text-[14px]" :class="row.loadSeq ? 'text-violet' : 'text-faint'">{{ row.loadSeq || planSeq || '—' }}</div>
        <div class="text-[7.5px] font-extrabold text-faint">{{ row.loadSeq ? t('حُمّل بالترتيب', 'Loaded as') : t('ترتيب مخطط', 'Planned') }}</div>
      </div>
    </div>
    <div class="min-w-[190px] flex-1">
      <div class="text-[11.5px] font-extrabold">{{ row.customer }}<span v-if="isNext" class="ms-2 text-[8.5px] text-brand-dark">← {{ t('التالي للتحميل', 'next to load') }}</span></div>
      <div class="num mt-px text-[9px] text-faint">
        <RouterLink :to="`/fo/${encodeURIComponent(row.fo)}`" class="font-bold !text-violet">{{ row.fo }}</RouterLink>{{ row.so ? ` · ${row.so}` : '' }}
        · {{ row.cartons }} {{ t('كرتون', 'ctn') }} · {{ fmtNum(row.kg) }} {{ t('كجم', 'kg') }} · {{ fmtNum(row.cbm, 1) }} {{ t('م³', 'm³') }}{{ row.packages ? ` · ${row.packages} ${t('طرد', 'pkg')}` : '' }}{{ row.loadedAt ? ` · ${fmtDate(row.loadedAt)}` : '' }}
      </div>
      <div v-if="!row.loaded && row.ready && turnWhy" class="mt-0.5 text-[9px] font-bold text-warn">{{ turnWhy }}</div>
    </div>
    <div>
      <Chip v-if="row.loaded" :label="{ ar: 'محمَّل ✓', en: 'Loaded ✓' }" fg="#0d7f93" bg="#d9f4f9" />
      <Chip v-else :map="FO_LABELS" :k="row.status" />
    </div>
    <span v-if="!row.loaded && !row.ready" class="faint text-[9px]">{{ t('بانتظار التعبئة — يخرج من الرحلة إن أُرسلت قبل تعبئته', 'Awaiting packing — leaves the trip if it is dispatched first') }}</span>
    <template v-if="canLoad && row.ready && !row.loaded">
      <Btn tone="dark" class="!h-9 !rounded-[10px] !text-[10.5px]" :loading="loading" :disabled="!!why" :title="why" :label="{ ar: 'تأكيد التحميل', en: 'Confirm load' }" @click="emit('load')" />
      <Btn v-if="canOverride && outOfTurn && !loadWhy" tone="softAmber" size="sm" :loading="loading" :title="t('تحميل خارج الترتيب بقرار المشرف وسبب مكتوب', 'Load out of turn — a supervisor decision with a written reason')" :label="{ ar: 'تجاوز الترتيب', en: 'Override order' }" @click="emit('override')" />
    </template>
    <Btn v-if="canUnload && row.loaded" tone="softRed" size="sm" :loading="loading" :label="{ ar: 'إنزال', en: 'Unload' }" @click="emit('unload')" />
  </div>
</template>
