<script setup>
// Pick & Pack › pick lists tab: FO table (status / warehouse / search filters, «قوائمي» = the lists I hold, who holds each
// list) and, for the selected FO, the pick list panel — who holds it (take / give back / assign), tasks in pick sequence
// under a header per zone (scan bin → product → batch → qty → confirm, short pick or batch substitution with a reason),
// the last server message, the packing station once the order is picked, and the supervisor's put-back.
// The table and the panel refresh on their own so a list taken by a colleague shows as his.
// Deep link: `/picking?fo=FO-…` selects an order.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs, TextInput } from '@/components';
import { fmtAgo, fmtDateOnly, lang, t } from '@/i18n';
import { FO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import { useWarehouse } from '@/stores/warehouse';
import FoActions from './FoActions.vue';
import PackRow from './PackRow.vue';
import PickTaskCard from './PickTaskCard.vue';
import { ZONE_TYPES, custName, pickProgress, shortUnits, tasksByZone } from './shared';

const props = defineProps({
  initialFo: { type: String, default: '' },
});

const FO_FILTERS = [
  { k: 'active', label: { ar: 'النشطة', en: 'Active' } }, { k: 'alloc', label: { ar: 'بانتظار التجهيز', en: 'Awaiting picking' } }, { k: 'picking', label: { ar: 'قيد التجهيز', en: 'Picking' } },
  { k: 'picked', label: { ar: 'مُجهز', en: 'Picked' } }, { k: 'packed', label: { ar: 'معبأ', en: 'Packed' } }, { k: 'loaded', label: { ar: 'محمَّل', en: 'Loaded' } }, { k: 'onroute', label: { ar: 'في الطريق', en: 'On route' } }, { k: '', label: { ar: 'الكل', en: 'All' } },
];

const auth = useAuth();
const wh = useWarehouse();
const act = useAction();
const q = ref('');
const status = ref('active');
const mine = ref(false);
const page = ref(1);
const sel = ref(props.initialFo || null);
/** Result of the last confirmed pick / report (`{ message, orderDone?, lineDone?, tone? }`) — shown above the tasks. */
const last = ref(null);
watch(() => props.initialFo, (v) => { if (v) sel.value = v; });
watch(sel, () => { last.value = null; act.clearError(); });

const list = useList('/fulfillment/orders', () => ({ q: q.value.trim() || undefined, status: status.value || undefined, mine: mine.value ? 1 : undefined, ...wh.whParams, page: page.value, pageSize: 25 }), { refetchInterval: 20_000 });
const det = useGet(() => (sel.value ? `/fulfillment/orders/${encodeURIComponent(sel.value)}` : null), undefined, { refetchInterval: 20_000 });
const d = computed(() => det.data.value);
const pl = computed(() => d.value?.pickLists?.[0] || null);
const tasks = computed(() => (d.value?.pickLists || []).flatMap((p) => p.tasks).sort((a, b) => a.seq - b.seq));
const zones = computed(() => tasksByZone(tasks.value));
const doneTasks = computed(() => tasks.value.filter((x) => ['done', 'short', 'cancelled'].includes(x.status)).length);
const short = computed(() => shortUnits(d.value));

// who holds the list
const me = computed(() => auth.user?.username || '');
const holder = computed(() => pl.value?.assignedTo || null);
const listOpen = computed(() => pl.value?.status === 'open');
const canPick = computed(() => auth.can('pick.confirm'));
const canOverride = computed(() => auth.can('pick.override'));
/** The other picker whose list this is — scanning is closed for this user then (a supervisor may still scan). */
const heldByOther = computed(() => (holder.value && holder.value !== me.value && !canOverride.value ? holder.value : null));
const holderOf = (row) => (row.pickLists || []).find((p) => p.status === 'open')?.assignedTo || null;
const zoneName = (z) => (z ? `${z.code}${(lang.value === 'ar' ? z.nameAr : z.nameEn || z.nameAr) ? ` — ${lang.value === 'ar' ? z.nameAr : z.nameEn || z.nameAr}` : ''}` : '—');
const zoneType = (z) => (z && ZONE_TYPES[z.type] ? (lang.value === 'ar' ? ZONE_TYPES[z.type].ar : ZONE_TYPES[z.type].en) : '');

function refresh() { det.refetch(); list.refetch(); }
function onTaskChanged(r) { last.value = r || null; refresh(); }
async function listAction(path, body) {
  if (!pl.value) return;
  const r = await act.run(() => api.postIdempotent(`/fulfillment/pick-lists/${encodeURIComponent(pl.value.number)}/${path}`, body), { success: (x) => x.message, invalidate: ['fulfillment'] });
  if (r) refresh();
}
async function assign() {
  const username = await ask({ title: { ar: `إسناد ${pl.value.number} إلى مجهّز`, en: `Assign ${pl.value.number} to a picker` }, label: { ar: 'اسم المستخدم', en: 'Username' }, required: true, value: holder.value || '' });
  if (username === null) return;
  listAction('assign', { username });
}

const columns = [
  { key: 'number', header: { ar: 'أمر التنفيذ', en: 'Order' }, width: '110px', kind: 'id' },
  { key: 'customer', header: { ar: 'العميل (من منصة B2B)', en: 'Customer (from B2B)' }, width: 'minmax(190px,1.6fr)' },
  { key: 'zone', header: { ar: 'المنطقة', en: 'Zone' }, width: '130px' },
  { key: 'lines', header: { ar: 'أسطر', en: 'Lines' }, width: '70px', kind: 'num' },
  { key: 'picker', header: { ar: 'المجهّز', en: 'Picker' }, width: '100px' },
  { key: 'wh', header: { ar: 'المستودع', en: 'WH' }, width: '70px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
];
</script>

<template>
  <div>
    <div class="row wrap mb-2.5">
      <TextInput scan v-model="q" small class="w-[260px]" :placeholder="{ ar: 'بحث برقم الأمر / العميل / أمر البيع…', en: 'Search FO / customer / SO…' }" @update:model-value="page = 1" />
      <Tabs v-model="status" :tabs="FO_FILTERS" variant="pill" class="!mb-0" @update:model-value="page = 1" />
      <button type="button" class="pill purple" :class="{ active: mine }" :title="t('الأوامر التي قائمة تجهيزها بعهدتي', 'Orders whose pick list I hold')" @click="mine = !mine; page = 1">{{ t('قوائمي', 'My lists') }}</button>
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :min-width="760" :row-key="(r) => r.number" :selected-key="sel"
                 :empty-text="mine ? { ar: 'لا قوائم تجهيز بعهدتك — افتح أمرًا واضغط «استلام القائمة» أو ابدأ المسح', en: 'You hold no pick list — open an order and take its list, or just start scanning' } : { ar: 'لا أوامر تنفيذ — تُنشأ من تجميع الطلبات (جاهز للتجهيز) أو من أمر البيع', en: 'No fulfillment orders — created from consolidation (ready for picking) or from the sales order' }"
                 @page="page = $event" @row-click="(r) => (sel = sel === r.number ? null : r.number)">
        <template #cell-number="{ row }">
          <div><div class="cell-id">{{ row.number }}</div><div v-if="row.so?.priority === 'high'" class="font-sans text-[8px] font-extrabold text-bad">{{ t('عالية', 'High') }}</div></div>
        </template>
        <template #cell-customer="{ row }">
          <div><div class="cell-name">{{ custName(row.customer) }}</div><div class="cell-sub">{{ row.so?.number || '' }}{{ row.so?.dueDate ? ` · ${fmtDateOnly(row.so.dueDate)}` : '' }}{{ row.so?.window ? ` · ${row.so.window}` : '' }}</div></div>
        </template>
        <template #cell-zone="{ row }"><span class="text-[10px] font-bold text-sec">{{ row.customer.zone || row.zoneAr || '—' }}</span></template>
        <template #cell-lines="{ row }"><span class="text-muted">{{ row.lines.length }}<span class="text-[8.5px]"> · {{ pickProgress(row).done }}/{{ pickProgress(row).need }}</span></span></template>
        <template #cell-picker="{ row }"><span class="num text-[10px]" :class="holderOf(row) === me ? 'font-extrabold text-brand-dark' : 'text-sec'">{{ holderOf(row) || '—' }}</span></template>
        <template #cell-wh="{ row }"><span class="sec text-[10px] font-bold">{{ row.warehouse.code }}</span></template>
        <template #cell-status="{ row }"><Chip :map="FO_LABELS" :k="row.status" /></template>
      </DataTable>
    </div>

    <div v-if="sel" class="card selected mt-3.5 px-5 py-[18px]">
      <ErrorBanner :error="det.error.value" :closable="false" />
      <div v-if="!d && !det.error.value" class="skel h-[120px]" />
      <template v-if="d">
        <div class="row wrap !gap-2.5">
          <div class="min-w-[200px] flex-1 text-[13.5px] font-extrabold">
            {{ t('قائمة التجهيز', 'Pick list') }} <span class="num-mixed">{{ pl?.number || '—' }}</span> —
            <RouterLink :to="`/fo/${encodeURIComponent(d.number)}`" class="num-mixed !text-violet">{{ d.number }}</RouterLink>
            <span class="muted text-[10.5px]"> · {{ custName(d.customer) }}</span>
          </div>
          <Chip :map="FO_LABELS" :k="d.status" />
          <div class="num ltr text-[11px] text-brand-dark">{{ doneTasks }} / {{ tasks.length }}</div>
          <button type="button" class="x-btn" aria-label="close" @click="sel = null">✕</button>
        </div>

        <!-- who holds the list -->
        <div v-if="pl" class="row wrap mt-2 !gap-2 rounded-[11px] border border-line-2 bg-soft px-3 py-2">
          <span class="text-[10px] font-extrabold text-faint">{{ t('المجهّز', 'Picker') }}</span>
          <span v-if="holder" class="num text-[11px] font-extrabold" :class="holder === me ? 'text-brand-dark' : 'text-ink'">{{ holder }}{{ holder === me ? ` (${t('أنت', 'you')})` : '' }}<span v-if="pl.assignedAt" class="faint font-sans text-[9px] font-normal"> · {{ fmtAgo(pl.assignedAt) }}</span></span>
          <span v-else class="text-[10.5px] text-muted">{{ listOpen ? t('القائمة حرّة — أول مسح مؤكَّد يسندها لصاحبه', 'Free — the first confirmed scan takes it') : t('القائمة مغلقة', 'List closed') }}</span>
          <span class="grow" />
          <template v-if="listOpen">
            <Btn v-if="canPick && !holder" tone="primary" size="sm" :loading="act.pending.value" :label="{ ar: 'استلام القائمة', en: 'Take the list' }" @click="listAction('claim')" />
            <Btn v-if="holder && (holder === me || canOverride)" tone="soft" size="sm" :loading="act.pending.value" :label="holder === me ? { ar: 'تحرير القائمة', en: 'Give it back' } : { ar: `تحرير من ${holder}`, en: `Free from ${holder}` }" @click="listAction('release')" />
            <Btn v-if="canOverride" tone="softPurple" size="sm" :loading="act.pending.value" :label="{ ar: 'إسناد لمجهّز', en: 'Assign' }" @click="assign" />
          </template>
        </div>
        <div v-if="heldByOther && listOpen" class="hint amber !mt-2">{{ t(`هذه القائمة بعهدة ${heldByOther} — المسح عليها مقفل لك. يحرّرها هو، أو يعيد المشرف إسنادها.`, `This list is held by ${heldByOther} — scanning is closed for you. He gives it back, or a supervisor reassigns it.`) }}</div>
        <ErrorBanner class="mt-2" :error="act.error.value" @close="act.clearError()" />
        <div v-if="last" class="hint !mt-2.5" :class="last.tone || (last.orderDone ? 'green' : last.lineDone ? 'teal' : 'amber')">{{ last.message }}</div>
        <div v-if="short > 0" class="hint amber !mt-2">{{ t(`نقص ${short} قطعة في هذا الأمر — يُشحن المتوفر، والناقص يصير طلبًا معلّقًا (أو يُلغى) عند إقفال القائمة.`, `${short} units short on this order — what was found ships; the shortage becomes a backorder (or is cancelled) when the list closes.`) }}</div>
        <div v-if="d.status === 'cancelled'" class="hint !mt-2">{{ t('أُلغي أمر التنفيذ (لم يُجهَّز منه شيء، أو أُرجع تجهيزه للمواقع) — أمر البيع يُخصَّص ويُنفَّذ من جديد من صفحته.', 'This fulfillment order was cancelled (nothing could be picked, or its picking was put back) — the sales order is allocated and fulfilled again from its page.') }}</div>

        <div class="col mt-[13px]">
          <div v-if="tasks.length === 0" class="empty !p-3">{{ d.status === 'alloc' ? t('لا مهام تجهيز بعد — تُنشأ عند إنشاء أمر التنفيذ', 'No pick tasks yet') : t('لا مهام', 'No tasks') }}</div>
          <template v-for="(g, gi) in zones" :key="gi">
            <div class="row mt-1 !gap-2 border-b border-line-2 pb-1">
              <span class="text-[10.5px] font-extrabold text-violet">{{ t('المنطقة', 'Zone') }} {{ zoneName(g.zone) }}</span>
              <span v-if="zoneType(g.zone)" class="text-[9px] font-bold text-sec">{{ zoneType(g.zone) }}</span>
              <span class="num text-[9px] text-faint">{{ g.tasks.length }} {{ t('مهام', 'tasks') }}</span>
            </div>
            <PickTaskCard v-for="x in g.tasks" :key="x.id" :task="x" :fo-status="d.status" :held-by="heldByOther" @changed="onTaskChanged" />
          </template>
        </div>
        <div v-if="d.status === 'picked'" class="mt-3 rounded-[13px] border border-[#DCD2EE] bg-[#FCFBFE] px-[15px] py-3">
          <div class="mb-2 text-[11.5px] font-extrabold">{{ t('محطة التعبئة — تحقق من الأسطر ثم أدخل عدد الكراتين الفعلي والوزن والحجم', 'Packing station — verify lines then enter the real number of cartons, weight and volume') }}</div>
          <PackRow :key="d.number" :fo="d" standalone @changed="refresh" />
        </div>
        <div v-else-if="['picking', 'packed', 'loaded'].includes(d.status)" class="mt-3"><FoActions :fo="d" @changed="refresh" /></div>
        <div class="hint amber">{{ t('Scan الموقع ثم المنتج (SKU أو باركود) ثم الدفعة إن وُجد في الموقع أكثر من دفعة، ثم الكمية — يرفض النظام الموقع الخاطئ، المنتج الخاطئ، الدفعة الخاطئة، الكمية الزائدة، الدفعة المنتهية أو المحجورة، والرصيد السالب. التجهيز الجزئي مسموح. «نقص» يقفل السطر بسبب محدد؛ «دفعة بديلة» تعيد الحجز من موقع آخر. المهام مرتبة منطقةً منطقة (العادي ثم المبرد ثم المجمد).', 'Scan the location, the product (SKU or barcode), the batch when the bin holds several, then the quantity — wrong bin / product / batch, over-picking, expired or quarantined batches and negative stock are rejected. Partial picks are allowed. "Short" closes the line with a reason; "Substitute" reserves from another bin. Tasks run zone by zone (ambient, then chilled, then frozen).') }}</div>
      </template>
    </div>
  </div>
</template>
