<script setup>
// The review board: what waits, and for whom — the requests waiting for a reviewer, the ones in review and the ones
// sent back to their authors, the oldest first, each with its age — and how old the reviewers' queue is as a whole.
// GET /product-requests/board?q&origin&per → { columns: [{ status, count, oldestAt, items, more }], ageing: [{ key, count }], waiting }.
// A column shows its first cards and reads the rest page by page through the list (`status=…&sort=statusAt&order=asc`).
// The board is refreshed every minute. On a phone the columns become pills with one column on screen.
import { computed, reactive, ref, watch } from 'vue';
import { api, useGet } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, Tabs, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtNum, t } from '@/i18n';
import RequestCard from './RequestCard.vue';
import { AGEING, API, BOARD_COLUMNS, ORIGINS, SOURCE_CHIP, ageText, ageTone, usePreqNav, useSearchParam } from './preq';

const props = defineProps({
  /** GET /product-requests/summary — whether the section holds requests of Odoo (the origin pills). */
  summary: { type: Object, default: null },
});

const PER = 12;
const nav = usePreqNav();
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
const origin = computed(() => (['ops', 'odoo'].includes(nav.get('origin')) ? nav.get('origin') : ''));
const params = computed(() => ({ q: nav.get('q'), origin: origin.value }));

const board = useGet(`${API}/board`, () => ({ ...params.value, per: PER }), { refetchInterval: 60_000 });
const columns = computed(() => board.data.value?.columns || []);
const ageing = computed(() => (board.data.value?.ageing || []).filter((a) => AGEING[a.key]));
const empty = computed(() => !!board.data.value && columns.value.every((c) => !c.count));
const originTabs = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'ops', label: ORIGINS.ops }, { k: 'odoo', label: SOURCE_CHIP }];

// ---- the rest of a column, page by page
const extra = reactive({});
const moreError = ref(null);
watch(() => board.data.value, () => { Object.keys(extra).forEach((k) => delete extra[k]); });
const cardsOf = (col) => [...col.items, ...(extra[col.status]?.items || [])];
const hasMore = (col) => col.count > cardsOf(col).length;
async function loadMore(col) {
  if (!extra[col.status]) extra[col.status] = { items: [], page: 1, loading: false };
  const e = extra[col.status]; // read back from `extra`: the reactive one (the object that was put in is not, and the cards would never show)
  if (e.loading) return;
  e.loading = true;
  moreError.value = null;
  try {
    const r = await api.get(API, { ...params.value, status: col.status, sort: 'statusAt', order: 'asc', page: e.page + 1, pageSize: PER });
    e.items.push(...r.items);
    e.page += 1;
  } catch (err) { moreError.value = err; } finally { e.loading = false; }
}
/** The age of the oldest card of a column, in the colour of its urgency. */
const oldest = (col) => (col.items.length ? { text: ageText(col.items[0]), tone: ageTone(col.items[0].ageDays) } : null);

// ---- phone: one column at a time
const picked = ref(null);
const shownStatus = computed(() => (columns.value.some((c) => c.status === picked.value) ? picked.value : (columns.value.find((c) => c.count) || columns.value[0])?.status || null));
const columnTabs = computed(() => columns.value.map((c) => ({ k: c.status, label: BOARD_COLUMNS[c.status] || { ar: c.status, en: c.status }, badge: c.count })));
const shown = computed(() => (isMobile.value ? columns.value.filter((c) => c.status === shownStatus.value) : columns.value));
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput small type="search" class="w-[250px]" :model-value="qText" :placeholder="{ ar: 'بحث بالاسم، الرقم، الباركود…', en: 'Search name, number, barcode…' }" @update:model-value="typeQ" @enter="applyQ" />
    <Tabs v-if="props.summary && props.summary.fromOdoo > 0" :model-value="origin" :tabs="originTabs" variant="pillPurple" class="!mb-0" @update:model-value="(k) => nav.setFilter('origin', k)" />
  </div>
  <ErrorBanner :error="board.error.value || moreError" :closable="!!moreError" @close="moreError = null" />

  <div v-if="board.isLoading.value" class="skel h-[260px]" />
  <template v-else-if="board.data.value">
    <!-- how long the reviewers' queue has been waiting -->
    <div class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
      <div v-for="a in ageing" :key="a.key" class="tile" :style="a.count ? { background: AGEING[a.key].bg } : null">
        <div class="tile-v" :style="{ color: a.count ? AGEING[a.key].fg : '#a8a4b8' }">{{ fmtNum(a.count) }}</div>
        <div class="tile-l">{{ t('عند المراجعين منذ', 'With the reviewers for') }} {{ t(AGEING[a.key].ar, AGEING[a.key].en) }}</div>
      </div>
    </div>

    <EmptyState v-if="empty" tone="success" :text="nav.get('q') || origin ? { ar: 'لا طلبات مطابقة على اللوحة.', en: 'No matching request on the board.' } : { ar: 'لا شيء بانتظار المراجعة ولا عند أصحابه للتصحيح ✓', en: 'Nothing waits for review and nothing is back with its author ✓' }" />
    <template v-else>
      <Tabs v-if="isMobile" :model-value="shownStatus" :tabs="columnTabs" variant="pill" @update:model-value="(k) => (picked = k)" />
      <div class="flex items-start gap-3" :class="isMobile ? 'flex-col' : 'overflow-x-auto pb-2'">
        <div v-for="col in shown" :key="col.status" class="flex min-w-0 flex-col rounded-[16px] border border-line bg-soft" :class="isMobile ? 'w-full' : 'min-w-[270px] flex-1'">
          <div class="px-3 pb-2 pt-2.5">
            <div class="flex items-center gap-2">
              <div class="min-w-0 flex-1 truncate text-[12px] font-extrabold" :style="{ color: (BOARD_COLUMNS[col.status] || {}).fg }">{{ t((BOARD_COLUMNS[col.status] || {}).ar || col.status, (BOARD_COLUMNS[col.status] || {}).en || col.status) }}</div>
              <span class="num rounded-full bg-white px-2 py-px text-[10px] text-sec">{{ fmtNum(col.count) }}</span>
            </div>
            <div class="mt-0.5 flex items-center gap-1.5 text-[9.5px] text-muted">
              <span class="min-w-0 flex-1 truncate">{{ t((BOARD_COLUMNS[col.status] || {}).help?.ar || '', (BOARD_COLUMNS[col.status] || {}).help?.en || '') }}</span>
              <span v-if="oldest(col)" class="flex-none font-bold" :style="{ color: oldest(col).tone.fg }">{{ t('أقدمها', 'oldest') }} {{ oldest(col).text }}</span>
            </div>
          </div>
          <div class="col !gap-1.5 px-2 pb-2">
            <RequestCard v-for="r in cardsOf(col)" :key="r.id" :request="r" @open="nav.openRequest(r.number)" />
            <div v-if="!col.count" class="rounded-[11px] border border-dashed border-line px-2 py-4 text-center text-[10px] text-faint">{{ t('لا طلبات هنا', 'No request here') }}</div>
            <Btn v-if="hasMore(col)" size="sm" tone="soft" block :loading="!!extra[col.status]?.loading" :label="{ ar: `عرض المزيد (${fmtNum(col.count - cardsOf(col).length)})`, en: `Show more (${fmtNum(col.count - cardsOf(col).length)})` }" @click="loadMore(col)" />
          </div>
        </div>
      </div>
      <div class="mt-1 text-[9.5px] text-faint">{{ t('الأقدم أولًا. العمر محسوب من دخول الطلب حالته الحالية؛ طلبات أودو تُراجَع في أودو وتظهر هنا للمتابعة.', 'The oldest first. The age counts from the moment a request entered its present state; requests of Odoo are reviewed in Odoo and shown here to be followed.') }}</div>
    </template>
  </template>
</template>
