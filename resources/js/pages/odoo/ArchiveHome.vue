<script setup>
// The home of the archive: a search across every kind, and the sections of the catalog as cards — each kind with
// how many records the archive holds of it. A people's-files kind is marked; it is listed only for a user who holds
// odoo.private (the server leaves it out of the catalog otherwise, and so does the page) — a section that holds
// such kinds says so to who may not read them (`locked`), without saying what or how many.
// A kind may be another list of the same records (`view`: the customers among the contacts): it is counted once.
// The lines of documents (`line`) are listed under the documents, lighter: one reads them in their document.
// The catalog also lists every model of Odoo nobody described (`plain`), the largest first: a section shows the
// first of them and keeps the rest behind «عرض بقية الأنواع»; the search box finds a kind by its name too.
// `q=` in the query string is the search:  /odoo?q=S00042
import { computed, reactive } from 'vue';
import { RouterLink } from 'vue-router';
import { Btn, Chip, EmptyState, Icon, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useSearchParam } from '../integration/odoo';
import ArchiveSearch from './ArchiveSearch.vue';
import { PRIVATE_CHIP, PRIVATE_HELP, foldText, labelOf, namesOfKind, useArchive } from './archive';

const { nav, sections, data, hidesKinds } = useArchive();
const { text: qText, type: typeQ, apply: applyQ } = useSearchParam(nav);
/** The words of the search; under two characters nothing is asked. */
const term = computed(() => nav.get('q').trim().slice(0, 200));

/** The records of a section: every kind once (a view lists records another kind already counts). */
const sum = (s) => s.kinds.reduce((n, k) => n + (k.view === true ? 0 : Number(k.count) || 0), 0);
/** The records of the archive: what the catalog says it indexed — unless it counts kinds this user may not read —, else the sum of the kinds listed. */
const records = computed(() => (typeof data.value?.indexed === 'number' && !hidesKinds.value ? data.value.indexed : sections.value.reduce((n, s) => n + sum(s), 0)));
const kinds = computed(() => sections.value.reduce((n, s) => n + s.kinds.length, 0));
const building = computed(() => data.value?.ready === false);

/** Rows of a card that plain kinds may fill before the rest folds. */
const FEW = 8;
/** The sections the reader unfolded (by their place in the catalog). */
const unfolded = reactive(new Set());
const toggle = (si) => (unfolded.has(si) ? unfolded.delete(si) : unfolded.add(si));
/**
 * The cards as they are drawn. The kinds somebody described are always in sight; the plain ones — a model of Odoo as
 * it is — fill the card up to a few rows, and the rest (`more`) wait behind the button.
 */
const cards = computed(() => sections.value.map((s, si) => {
  const plain = s.kinds.filter((k) => k.plain === true);
  const open = unfolded.has(si);
  if (plain.length <= FEW) return { s, si, shown: s.kinds, more: 0, open: false };
  const keep = new Set(plain.slice(0, Math.max(0, FEW - (s.kinds.length - plain.length))));
  return { s, si, shown: open ? s.kinds : s.kinds.filter((k) => k.plain !== true || keep.has(k)), more: plain.length - keep.size, open };
}));

/** Kinds whose own name holds the words: a person may be looking for a list, not for one document. */
const namedKinds = computed(() => {
  const words = foldText(term.value).split(' ').filter(Boolean);
  if (term.value.length < 2 || !words.length) return [];
  return sections.value.flatMap((s) => s.kinds).filter((k) => { const names = namesOfKind(k); return words.every((w) => names.includes(w)); }).slice(0, 12);
});
</script>

<template>
  <TextInput type="search" full :model-value="qText" :placeholder="{ ar: 'ابحث في كل الأرشيف: رقم مستند، اسم عميل أو مورد، صنف، مرجع…', en: 'Search the whole archive: a document number, a customer or a supplier, a product, a reference…' }" @update:model-value="typeQ" @enter="applyQ" />

  <template v-if="term.length >= 2">
    <div v-if="namedKinds.length" class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
      <span class="text-[10.5px] text-muted">{{ t('أنواع سجلات بهذا الاسم', 'Kinds of record by that name') }}</span>
      <RouterLink v-for="k in namedKinds" :key="k.key" :to="nav.kindTo(k.key)" class="pill">
        <span dir="auto">{{ labelOf(k) }}</span><span class="num ltr font-normal text-faint">{{ fmtNum(k.count) }}</span>
      </RouterLink>
    </div>
    <ArchiveSearch :term="term" />
  </template>
  <template v-else>
    <div v-if="term" class="text-[10px] text-muted">{{ t('اكتب حرفين على الأقل للبحث.', 'Type at least two characters to search.') }}</div>
    <template v-if="sections.length">
      <div class="flex flex-wrap gap-x-4 gap-y-1 text-[10.5px] text-muted">
        <span>{{ t('سجلات أودو في الأرشيف', 'Odoo records in the archive') }} <b class="num ltr text-ink">{{ fmtNum(records) }}</b></span>
        <span>{{ t('أنواع السجلات', 'Kinds of record') }} <b class="num ltr text-ink">{{ fmtNum(kinds) }}</b></span>
        <span>{{ t('الأقسام', 'Sections') }} <b class="num ltr text-ink">{{ fmtNum(sections.length) }}</b></span>
        <span>{{ t('افتح نوعًا لتتصفّح سجلاته.', 'Open a kind to browse its records.') }}</span>
      </div>
      <div class="grid-eq items-start">
        <div v-for="c in cards" :key="c.s.key || c.si" class="card">
          <div class="card-head">
            <div class="card-title" dir="auto">{{ labelOf(c.s) }}</div>
            <span class="num ltr text-[10px] font-bold text-faint" :title="t('سجلات هذا القسم في الأرشيف', 'Records of this section in the archive')">{{ fmtNum(sum(c.s)) }}</span>
          </div>
          <RouterLink v-for="k in c.shown" :key="k.key" :to="nav.kindTo(k.key)" class="flex items-center gap-2 border-t border-line-2 py-[9px] pe-[18px] text-ink hover:bg-soft hover:text-ink" :class="k.line === true ? 'ps-[34px]' : 'ps-[18px]'" :title="k.private ? bi(PRIVATE_HELP) : null">
            <Icon v-if="k.private" name="lock" :size="12" :color="PRIVATE_CHIP.fg" />
            <span class="min-w-0 flex-1 truncate [text-align:match-parent]" :class="k.line === true ? 'text-[10.5px] text-sec' : 'text-[11.5px] font-bold'" dir="auto">{{ labelOf(k) }}</span>
            <Chip v-if="k.private" small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" />
            <span class="num ltr text-[11px]" :class="Number(k.count) > 0 ? 'text-violet' : 'text-faint'">{{ fmtNum(k.count) }}</span>
          </RouterLink>
          <div v-if="c.more" class="border-t border-line-2 px-[10px] py-[5px]">
            <Btn tone="ghost" size="sm" :aria-expanded="c.open" :label="c.open ? { ar: 'عرض أقل', en: 'Show fewer' } : { ar: `عرض بقية الأنواع (${fmtNum(c.more)})`, en: `Show the other kinds (${fmtNum(c.more)})` }" @click="toggle(c.si)" />
          </div>
          <div v-if="c.s.locked" class="flex items-start gap-2 border-t border-line-2 px-[18px] py-[9px] text-[10px] leading-[1.7] text-muted">
            <Icon name="lock" :size="12" class="mt-[3px]" />
            <span>{{ t('في هذا القسم أيضًا ملفات أشخاص، تظهر فقط لمن يملك صلاحيتها.', 'This section also holds people’s files, listed only for those who may read them.') }}</span>
          </div>
        </div>
      </div>
    </template>
    <EmptyState v-else tone="dashed" :text="building
      ? { ar: 'لم يُفهرَس شيء في الأرشيف بعد. تظهر هنا أقسامه — المبيعات، المشتريات، المخزون، المحاسبة… — حين تُفهرس أول سجلاتها.', en: 'Nothing is indexed in the archive yet. Its sections — sales, purchasing, inventory, accounting… — appear here once their first records are indexed.' }
      : { ar: 'لا شيء في الأرشيف بعد. تظهر هنا أقسام أودو — المبيعات، المشتريات، المخزون، المحاسبة… — بعد أن تُنسخ سجلاتها وتُفهرس.', en: 'The archive holds nothing yet. The sections of Odoo — sales, purchasing, inventory, accounting… — appear here once their records are copied and indexed.' }" />
  </template>
</template>
