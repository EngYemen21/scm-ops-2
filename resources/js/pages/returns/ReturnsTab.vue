<script setup>
// Returns list of one type ('' = all): KPI tiles, search + status pills + warehouse select, cards → emits `open(number)`.
// A card says what matters at a glance: taken from stock or arriving, a short receipt, the debit note of a supplier return.
import { computed, ref, watch } from 'vue';
import { useList } from '@/api/client';
import { Chip, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtDateOnly, fmtNum, lang, t } from '@/i18n';
import { RETURN_LABELS } from '@/shared';
import { useWarehouse } from '@/stores/warehouse';
import ReturnStatusChip from './ReturnStatusChip.vue';
import { RETURN_REASON_LABELS, RETURN_TYPE_LABELS, isFromStock, labelOf, pname, sourceOf, useAutoOpen, useReturnKpis, useWhOpts } from './shared';

const props = defineProps({
  /** '' | cust | sup | del | dmg */
  type: { type: String, default: '' },
  /** `?q=` the page was opened with (deep link from search / notifications). */
  initialQ: { type: String, default: '' },
});
const emit = defineEmits(['open']);

const wh = useWarehouse();
const whOpts = useWhOpts();
const q = ref(props.initialQ);
const status = ref('');
const warehouse = ref(wh.current?.code || ''); // starts on the warehouse picked in the topbar
const page = ref(1);
const kpis = useReturnKpis();
watch(() => props.type, () => { page.value = 1; }); // switching between the return-type tabs restarts paging
watch(() => props.initialQ, (v) => { if (v) { q.value = v; page.value = 1; } }); // a new deep link while the page is open

const list = useList('/returns', () => ({ q: q.value.trim() || undefined, type: props.type || undefined, status: status.value || undefined, warehouse: warehouse.value || undefined, page: page.value, pageSize: 25 }), { refetchInterval: 60_000 });
const items = computed(() => list.data.value?.items || []);
useAutoOpen(() => props.initialQ, () => list.data.value?.items, (n) => emit('open', n));

const TILES = [
  { k: 'pending', label: { ar: 'بانتظار الاعتماد', en: 'Pending approval' }, c: '#b26a16' }, { k: 'received', label: { ar: 'بانتظار الفحص', en: 'Awaiting inspection' }, c: '#654e92' },
  { k: 'inspect', label: { ar: 'قرار مطلوب', en: 'Decision needed' }, c: '#b23b3b' }, { k: 'closed', label: { ar: 'مقفلة', en: 'Closed' }, c: '#1d7a3e' },
];
const statusTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, ...Object.entries(RETURN_LABELS).map(([k, l]) => ({ k, label: { ar: l.ar, en: l.en } }))];
const setStatus = (s) => { status.value = s; page.value = 1; };
const reasonNote = (r) => (lang.value === 'en' && r.reasonEn) || r.reasonAr || r.notes || '';
/** Units that were requested but did not arrive (0 until the return is received). */
const shortOf = (r) => (r.lines || []).reduce((a, l) => a + (l.receivedQty != null ? Math.max(0, l.qty - l.receivedQty) : 0), 0);
</script>

<template>
  <div>
    <div class="mb-3 grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-2">
      <div v-for="x in TILES" :key="x.k" class="tile cursor-pointer" :class="{ amber: status === x.k }" @click="setStatus(status === x.k ? '' : x.k)">
        <div class="tile-v" :style="{ color: x.c }">{{ kpis[x.k] ?? '—' }}</div><div class="tile-l">{{ bi(x.label) }}</div>
      </div>
    </div>

    <div class="row wrap mb-2.5">
      <TextInput v-model="q" small class="!w-[260px]" :placeholder="{ ar: 'بحث برقم المرتجع / المصدر / المرجع', en: 'Search return / source / reference' }" @update:model-value="page = 1" />
      <Tabs :model-value="status" :tabs="statusTabs" variant="pill" class="!mb-0" @update:model-value="setStatus" />
      <SelectInput v-model="warehouse" small class="!w-[180px]" :options="whOpts" :placeholder="{ ar: 'كل المستودعات', en: 'All warehouses' }" @update:model-value="page = 1" />
      <div class="grow" />
      <div class="text-[10.5px] font-extrabold text-muted"><span class="num">{{ list.data.value?.total ?? '—' }}</span> {{ t('مرتجع', 'returns') }}</div>
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div v-if="list.isLoading.value && !list.data.value" class="skel h-[120px]" />
    <div v-if="!list.isLoading.value && items.length === 0" class="empty dashed">{{ t('لا مرتجعات مطابقة', 'No matching returns') }}</div>

    <div class="col">
      <div v-for="r in items" :key="r.number" class="card sm cursor-pointer px-4 py-3" @click="emit('open', r.number)">
        <div class="row wrap">
          <span class="num text-[12px] text-violet">{{ r.number }}</span>
          <Chip small :map="RETURN_TYPE_LABELS" :k="r.type" />
          <b class="text-[11px]">{{ sourceOf(r) }}</b>
          <Chip v-if="r.reasonCode" small fg="#55506a" bg="#F1EFF6" :label="`${t('كود السبب', 'Reason')}: ${bi(labelOf(RETURN_REASON_LABELS, r.reasonCode))}`" />
          <Chip v-if="isFromStock(r)" small fg="#0d7f93" bg="#d9f4f9" :label="{ ar: 'من المخزون', en: 'From stock' }" />
          <span class="num muted ltr text-[9px]">{{ fmtDateOnly(r.createdAt) }} · {{ r.warehouse?.code }}</span>
          <div class="grow" /><ReturnStatusChip :r="r" />
        </div>
        <div class="row wrap mt-1.5 !gap-3 text-[10.5px] text-sec">
          <span>
            <b>{{ r.lines?.[0] ? pname(r.lines[0].product) : '—' }}</b><span v-if="r.lines?.[0]" class="num"> ×{{ fmtNum(r.lines[0].qty) }}</span>
            <span v-if="(r.lines?.length || 0) > 1" class="muted"> {{ t(`+${r.lines.length - 1} أصناف`, `+${r.lines.length - 1} more`) }}</span>
          </span>
          <span class="muted">{{ reasonNote(r) }}</span>
          <span v-if="r.reference" class="num muted">· {{ r.reference }}</span>
          <span v-if="shortOf(r) > 0" class="font-bold text-warn">· {{ t(`استلام ناقص ${fmtNum(shortOf(r))}`, `${fmtNum(shortOf(r))} short on receipt`) }}</span>
          <span v-for="n in r.debitNotes || []" :key="n.number" class="font-bold text-violet">· {{ t('إشعار مدين', 'Debit note') }} <span class="num">{{ n.number }}</span></span>
        </div>
      </div>
    </div>

    <div v-if="list.data.value && list.data.value.pages > 1" class="row mt-2.5 justify-center !gap-1.5">
      <button v-for="i in list.data.value.pages" :key="i" type="button" class="pg-btn" :class="{ active: page === i }" @click="page = i">{{ i }}</button>
    </div>
    <div class="hint teal mt-3">{{ t('بضاعة تصل (عميل / توصيل): اعتماد ← استلام ما وصل فعلًا ← فحص ← قرار لكل سطر، وكل قرار حركة مخزون مسجلة. من المخزون (إرجاع لمورد / تالف): اعتماد يحجز الكمية في موقعها ← شحن. مرتجعات التوصيل تُنشأ تلقائيًا من فشل التسليم أو التسليم الجزئي. الإلغاء متاح قبل الاستلام أو الشحن.', 'Arriving goods (customer / delivery): approve → receive what actually arrived → inspect → decide per line; every decision is a recorded stock movement. From stock (supplier / damaged): approval holds the quantity in its bin → ship. Delivery returns are created automatically from failed or partial deliveries. Cancelling is possible before receipt or shipping.') }}</div>
  </div>
</template>
