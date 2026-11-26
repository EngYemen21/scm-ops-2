<script setup>
// Results of the search across every kind of the archive:
// GET /odoo-archive/search?q= → { groups: [{ kind, label, sectionLabel?, total, items: [first 5] }] }.
// One block per kind, under the name of its section: its first matches as links that open the document (a partner
// or a product opens its view), and — when the kind holds more — a link to the kind's own list with the same words.
//   <ArchiveSearch :term="words" />
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useList } from '@/api/client';
import { EmptyState, ErrorBanner } from '@/components';
import { fmtNum, t } from '@/i18n';
import ArchiveDocLinks from './ArchiveDocLinks.vue';
import { API, arr, isObj, labelOf, useArchive } from './archive';

const props = defineProps({
  /** The words searched for (two characters at least). */
  term: { type: String, required: true },
});

const { nav, kindOf, isHidden } = useArchive();
// the answer of the previous words stays on screen while the next one is on its way
const res = useList(`${API}/search`, () => ({ q: props.term }));
const groups = computed(() => arr(res.data.value?.groups)
  .filter((g) => isObj(g) && !isHidden(g.kind))
  .map((g, i) => {
    const items = arr(g.items).filter(isObj);
    const total = Number.isFinite(Number(g.total)) && g.total != null ? Number(g.total) : items.length;
    const kind = typeof g.kind === 'string' ? g.kind : null;
    // the section the kind belongs to: the answer names it, else the catalog does
    const section = labelOf({ label: g.sectionLabel, labelEn: g.sectionLabelEn }) || labelOf(kindOf(kind)?.section);
    return { key: kind || `g${i}`, kind, label: labelOf(g) || labelOf(kindOf(kind)) || kind || t('نتائج', 'Results'), section, items, total, more: total > items.length && !!kindOf(kind) };
  })
  .filter((g) => g.items.length));
const found = computed(() => groups.value.reduce((n, g) => n + g.total, 0));
</script>

<template>
  <ErrorBanner :error="res.error.value" :closable="false" class="!mb-0" />
  <div v-if="res.isLoading.value || (res.isPlaceholderData.value && !groups.length)" class="col"><div class="skel h-[92px]" /><div class="skel h-[92px]" /></div>
  <template v-else-if="res.data.value">
    <EmptyState v-if="!groups.length" tone="dashed" :text="{ ar: `لا شيء في الأرشيف يطابق «${term}». جرّب رقم المستند كاملًا، أو جزءًا من اسم الشريك أو الصنف.`, en: `Nothing in the archive matches “${term}”. Try the whole document number, or a part of the partner’s or product’s name.` }" />
    <template v-else>
      <div class="flex flex-wrap gap-x-4 gap-y-1 text-[10.5px] text-muted">
        <span>{{ t('نتائج البحث', 'Search results') }} <b class="num ltr text-ink">{{ fmtNum(found) }}</b></span>
        <span>{{ t('أنواع السجلات', 'Kinds of record') }} <b class="num ltr text-ink">{{ fmtNum(groups.length) }}</b></span>
      </div>
      <div class="grid-eq items-start" :class="{ 'opacity-60': res.isFetching.value }">
        <div v-for="g in groups" :key="g.key" class="card">
          <div class="card-head">
            <div class="card-title" dir="auto">
              {{ g.label }}<span class="card-count num ms-2">{{ fmtNum(g.total) }}</span>
              <div v-if="g.section && g.section !== g.label" class="card-sub">{{ g.section }}</div>
            </div>
            <RouterLink v-if="g.more" :to="nav.kindTo(g.kind, { q: term })" class="text-[10px] font-bold">{{ t(`كل النتائج (${fmtNum(g.total)})`, `All results (${fmtNum(g.total)})`) }}</RouterLink>
          </div>
          <div class="card-body"><ArchiveDocLinks :kind="g.kind" :items="g.items" /></div>
        </div>
      </div>
    </template>
  </template>
</template>
