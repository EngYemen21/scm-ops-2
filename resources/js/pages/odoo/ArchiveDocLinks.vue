<script setup>
// Documents as links: one row per document — its number, what it is, its date, state and amount — that opens the
// document's own drawer (a partner or a product opens its view). Used for the related documents of a document, the
// documents of a partner or a product, and the results of the search across the archive.
//   <ArchiveDocLinks :kind="group.kind" :items="group.items" />
// `kind` is the kind the items belong to (an item may name its own). A kind that is not in the user's catalog is
// listed without links. A line (a row of an order, a journal item, a stock move) that says what it hangs on opens
// that document: the sale of a product is read in its order, not in the line alone.
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import ArchiveValue from './ArchiveValue.vue';
import { dayText, isBlank, isId, isKindKey, isObj, refOf, textOf, useArchive } from './archive';

const props = defineProps({
  kind: { type: String, default: null },
  /** [{ odooId, kind?, number, title?, partner?, date, state, amount, currency?, qty? }] */
  items: { type: Array, default: () => [] },
  /** A name every row would repeat (the partner whose view lists them): left out. */
  omit: { type: String, default: '' },
});

const { nav, kindOf, placeOf } = useArchive();
const rows = computed(() => props.items.filter((it) => it && typeof it === 'object').map((it, i) => {
  const kindKey = isKindKey(it.kind) ? it.kind : props.kind;
  const number = textOf(it.number);
  const title = textOf(it.title);
  const partner = refOf(it.partner)?.name || (typeof it.partner === 'string' ? it.partner : '');
  const head = number || title || (isId(it.odooId) ? `#${it.odooId}` : '—');
  // what the document is, beside its number: its title and its partner, each once (a customer's title is its own name)
  const sub = [...new Set([number ? title : '', partner].filter((x) => x && x !== head && x !== props.omit))].join(' · ');
  const entry = kindKey ? kindOf(kindKey) : null;
  const up = entry && entry.line === true && isObj(it.parent) && isId(it.parent.id) && kindOf(it.parent.kind) ? placeOf(it.parent.kind, it.parent.id) : null;
  return { it, kindKey, key: isId(it.odooId) ? `${kindKey}:${it.odooId}` : `i${i}`, head, sub, to: up || (entry && isId(it.odooId) ? placeOf(kindKey, it.odooId) : null) };
}));
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-line-2">
    <div v-for="r in rows" :key="r.key" class="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-t border-line-2 px-3 py-2 first:border-t-0" :class="{ 'cursor-pointer hover:bg-soft': r.to }"
         @click="r.to && nav.open(r.to)">
      <RouterLink v-if="r.to" :to="r.to" class="num-mixed max-w-full truncate text-[10.5px] font-bold text-violet hover:text-brand-dark" dir="auto" @click.stop>{{ r.head }}</RouterLink>
      <span v-else class="num-mixed max-w-full truncate text-[10.5px] font-bold text-ink" dir="auto">{{ r.head }}</span>
      <span class="min-w-[40px] flex-1 truncate text-[10px] text-sec [text-align:match-parent]" dir="auto" :title="r.sub">{{ r.sub }}</span>
      <span v-if="!isBlank(r.it.date)" class="cell-date">{{ dayText(r.it.date) }}</span>
      <ArchiveValue v-if="!isBlank(r.it.state)" type="state" :value="r.it.state" :kind="r.kindKey" />
      <ArchiveValue v-if="!isBlank(r.it.qty)" type="qty" :value="r.it.qty" class="text-[10.5px] text-sec" />
      <ArchiveValue v-if="!isBlank(r.it.amount)" type="money" :value="r.it.amount" :currency="r.it.currency" class="text-[11px] text-ink" />
    </div>
  </div>
</template>
