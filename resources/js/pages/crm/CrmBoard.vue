<script setup>
// The board: one column per stage with its count and expected revenue, the most urgent cards first.
// GET /crm/board?q&owner&team&priority&tag&activity&origin&per → { stages: [{ …stage, count, revenue, weighted, items, more }], totals }.
// A column holds hundreds of leads: it shows its first cards and reads the rest page by page through the list
// (`/crm/leads?state=board&stage=…&sort=board`). Moving a lead: drag its card to another column (desktop) or use the
// stage buttons inside the lead — dropping on a won stage wins it. Cards of Odoo are not dragged here.
// On a phone the columns become stage pills with one column on screen.
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, Tabs } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import CrmFilters from './CrmFilters.vue';
import LeadCard from './LeadCard.vue';
import { money, nameOf, useCrmLookups, useCrmNav } from './crm';

const PER = 20;
const auth = useAuth();
const nav = useCrmNav();
const { follows, loaded } = useCrmLookups();
const canManage = computed(() => auth.can('crm.manage'));

const board = useGet('/crm/board', () => ({ ...nav.leadParams(), per: PER }));
const columns = computed(() => board.data.value?.stages || []);
const totals = computed(() => board.data.value?.totals || null);
/** A pipeline with no stage: where its stages come from depends on what the section follows. */
const noStages = computed(() => (follows.value
  ? { ar: 'لا مراحل في المسار بعد — تُحمَّل مراحل أودو وفرصه عند أول تحميل للقسم من نسخة أودو.', en: 'The pipeline has no stage yet — the stages and opportunities of Odoo arrive with the first load of the section from the Odoo copy.' }
  : { ar: 'لا مراحل في المسار بعد — أضف المراحل من «إعدادات المسار».', en: 'The pipeline has no stage yet — add stages in «Pipeline setup».' }));

// ---- the rest of a column, page by page
const extra = reactive({});
const moreError = ref(null);
watch(() => board.data.value, () => { Object.keys(extra).forEach((k) => delete extra[k]); });
const cardsOf = (col) => [...col.items, ...(extra[col.id]?.items || [])];
const hasMore = (col) => col.count > cardsOf(col).length;
async function loadMore(col) {
  const e = extra[col.id] || (extra[col.id] = { items: [], page: 1, loading: false });
  if (e.loading) return;
  e.loading = true; moreError.value = null;
  try {
    const r = await api.get('/crm/leads', { ...nav.leadParams(), state: 'board', stage: col.id, sort: 'board', page: e.page + 1, pageSize: PER });
    e.items.push(...r.items); e.page += 1;
  } catch (err) { moreError.value = err; } finally { e.loading = false; }
}

// ---- moving a card
const act = useAction({ invalidate: ['crm'] });
const dragging = ref(null);
const over = ref(null);
const movable = (lead) => canManage.value && !lead.owned && lead.status === 'open' && !isMobile.value;
function onDragStart(lead, ev) {
  dragging.value = lead;
  if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', lead.number); }
}
function onDragEnd() { dragging.value = null; over.value = null; }
async function onDrop(col) {
  const lead = dragging.value;
  onDragEnd();
  if (!lead || lead.stage?.id === col.id) return;
  if (col.isWon && !(await confirm({ title: { ar: `تسجيل كسب الفرصة ${lead.number}؟`, en: `Mark ${lead.number} as won?` }, sub: lead.name, tone: 'primary', okLabel: { ar: 'كسب', en: 'Won' } }))) return;
  await act.run(() => api.postIdempotent(`/crm/leads/${lead.id}/move`, { stageId: col.id }), {
    success: col.isWon ? t(`كُسبت الفرصة ${lead.number}`, `${lead.number} won`) : t(`نُقلت ${lead.number} إلى «${nameOf(col)}»`, `${lead.number} moved to "${nameOf(col)}"`),
  });
}

// ---- a stage marked "folded" (pipeline setup) stands as a narrow column until it is opened — for this visit
const opened = reactive({});
const folded = (col) => !isMobile.value && col.fold && !opened[col.id];

// ---- phone: one column at a time
const picked = ref(null);
const shownId = computed(() => (columns.value.some((c) => c.id === picked.value) ? picked.value : columns.value[0]?.id || null));
const stageTabs = computed(() => columns.value.map((c) => ({ k: c.id, label: { ar: c.nameAr, en: c.nameEn || c.nameAr }, badge: c.count })));
const shown = computed(() => (isMobile.value ? columns.value.filter((c) => c.id === shownId.value) : columns.value));
</script>

<template>
  <CrmFilters />
  <ErrorBanner :error="board.error.value || moreError || act.error.value" @close="moreError = null; act.clearError()" />

  <div v-if="totals" class="row wrap mb-2.5 text-[10.5px] text-sec">
    <span>{{ t('على اللوحة', 'On the board') }} <b class="num text-ink">{{ fmtNum(totals.count) }}</b> {{ t('فرصة', 'opportunities') }}</span>
    <span>· {{ t('الإيراد المتوقع', 'Expected revenue') }} <b class="num ltr text-ink">{{ money(totals.revenue) }}</b></span>
    <span>· {{ t('مرجَّحًا بالاحتمال', 'weighted by chance') }} <b class="num ltr text-ink">{{ money(totals.weighted) }}</b></span>
  </div>

  <div v-if="board.isLoading.value" class="skel h-[260px]" />
  <EmptyState v-else-if="board.data.value && !columns.length" tone="dashed" :text="noStages">
    <template v-if="canManage && loaded && !follows" #action><Btn tone="softPurple" :label="{ ar: 'إعدادات المسار', en: 'Pipeline setup' }" @click="nav.go('settings')" /></template>
  </EmptyState>

  <template v-else-if="columns.length">
    <Tabs v-if="isMobile" :model-value="shownId" :tabs="stageTabs" variant="pill" @update:model-value="(k) => (picked = k)" />
    <div class="flex items-start gap-3" :class="isMobile ? 'flex-col' : 'overflow-x-auto pb-2'">
      <div v-for="col in shown" :key="col.id" class="flex flex-col rounded-[16px] border bg-soft" :class="[isMobile ? 'w-full' : folded(col) ? 'w-[54px] flex-none' : 'w-[272px] flex-none', over === col.id ? 'border-brand' : 'border-line']"
           @dragover.prevent="dragging && (over = col.id)" @dragleave="over === col.id && (over = null)" @drop.prevent="onDrop(col)">
        <!-- a folded stage: its count and its name, opened by a click (a card can still be dropped on it) -->
        <button v-if="folded(col)" type="button" class="flex cursor-pointer flex-col items-center gap-2 border-0 bg-transparent px-1 py-2.5" :title="t('فتح عمود المرحلة', 'Open the stage column')" @click="opened[col.id] = true">
          <span class="num rounded-full bg-white px-2 py-px text-[10px] text-sec">{{ fmtNum(col.count) }}</span>
          <span class="text-[11.5px] font-extrabold [writing-mode:vertical-rl]" :class="col.isWon ? 'text-ok' : 'text-ink'">{{ nameOf(col) }}</span>
        </button>
        <template v-else>
          <div class="px-3 pb-2 pt-2.5">
            <div class="flex items-center gap-2">
              <div class="min-w-0 flex-1 truncate text-[12px] font-extrabold" :class="col.isWon ? 'text-ok' : 'text-ink'" dir="auto">{{ nameOf(col) }}</div>
              <span class="num rounded-full bg-white px-2 py-px text-[10px] text-sec">{{ fmtNum(col.count) }}</span>
              <button v-if="col.fold && !isMobile" type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[9.5px] font-bold text-muted hover:text-violet" @click="opened[col.id] = false">{{ t('طيّ', 'Fold') }}</button>
            </div>
            <div class="num ltr mt-0.5 text-[10px] text-muted">{{ money(col.revenue) }}</div>
          </div>
          <div class="col !gap-1.5 px-2 pb-2">
            <div v-for="lead in cardsOf(col)" :key="lead.id" :draggable="movable(lead)" :class="{ 'opacity-50': dragging && dragging.id === lead.id }" @dragstart="onDragStart(lead, $event)" @dragend="onDragEnd">
              <LeadCard :lead="lead" @open="nav.openLead(lead.number)" />
            </div>
            <div v-if="!col.count" class="rounded-[11px] border border-dashed border-line px-2 py-4 text-center text-[10px] text-faint">{{ t('لا فرص في هذه المرحلة', 'No opportunity in this stage') }}</div>
            <Btn v-if="hasMore(col)" size="sm" tone="soft" block :loading="!!extra[col.id]?.loading" :label="{ ar: `عرض المزيد (${fmtNum(col.count - cardsOf(col).length)})`, en: `Show more (${fmtNum(col.count - cardsOf(col).length)})` }" @click="loadMore(col)" />
          </div>
        </template>
      </div>
    </div>
    <div v-if="canManage && !isMobile" class="mt-1 text-[9.5px] text-faint">{{ t('اسحب البطاقة إلى مرحلة أخرى لنقلها؛ الإفلات على مرحلة الكسب يسجّل كسبها. فرص أودو تُنقل في أودو.', 'Drag a card to another stage to move it; dropping it on a won stage wins it. Opportunities of Odoo are moved in Odoo.') }}</div>
  </template>
</template>
