<script setup>
// The history of a document as a timeline: who wrote what and when — notes, e-mails, and the changes Odoo itself
// recorded. A change of a value is written in words, on two lines: the name of the value, «من» what it was and
// «إلى» what it became (the API sends them typed: a number, yes / no, a day, a moment — each is written as such).
// Only what is attached to the document is here (people's direct chats never are).
// The text of a message comes from Odoo: it is shown as text, never rendered as markup.
//   <ArchiveHistory :items="doc.history" :total="doc.historyTotal" />
import { computed, ref } from 'vue';
import { Timeline } from '@/components';
import { fmtNum, t } from '@/i18n';
import { TONE, utcDate } from '../integration/odoo';
import { arr, isObj, labelOf, looseText, textOf } from './archive';

const props = defineProps({
  /** [{ at, author, type: note | email | system, subject?, text, changes: [{ field, name?, old, new }] }] — in the order the API gives them (newest first). */
  items: { type: Array, default: () => [] },
  /** How many messages the document has in Odoo, when the answer carries only the newest of them. */
  total: { type: Number, default: null },
});

const TYPE = {
  note: { ar: 'ملاحظة', en: 'Note', color: TONE.violet.fg },
  email: { ar: 'رسالة بريد', en: 'E-mail', color: TONE.blue.fg },
  system: { ar: 'سجّله أودو', en: 'Recorded by Odoo', color: TONE.off.fg },
};
const OTHER = { ar: 'حدث', en: 'Event', color: TONE.off.fg };
/** A message longer than this starts folded. */
const LONG = 420;
const unfolded = ref(new Set());
const toggle = (i) => { if (unfolded.value.has(i)) unfolded.value.delete(i); else unfolded.value.add(i); };
/** What changed: the label Odoo gives the field (`field`); where the copy does not know it («—»), its technical name. */
const fieldOf = (c) => { const said = labelOf(c.label ?? c.field) || textOf(c.field); return said && said !== '—' ? said : textOf(c.name); };

const rows = computed(() => props.items.filter(isObj).map((h, i) => {
  const type = Object.prototype.hasOwnProperty.call(TYPE, h.type) ? TYPE[h.type] : OTHER;
  const text = textOf(h.text).trim();
  return {
    i, at: utcDate(h.at), by: textOf(h.author), label: type, color: type.color, subject: textOf(h.subject).trim(), text, long: text.length > LONG || text.split('\n').length > 6,
    changes: arr(h.changes).filter(isObj).map((c) => ({ field: fieldOf(c), old: looseText(c.old), now: looseText(c.new) })),
  };
}));
const older = computed(() => (props.total != null && props.total > rows.value.length ? props.total : null));
</script>

<template>
  <Timeline :items="rows" :empty-text="{ ar: 'لا شيء مسجّل على هذا المستند في أودو. تظهر هنا الملاحظات والرسائل المرفقة به وتغييرات قيمه.', en: 'Nothing is recorded on this document in Odoo. Its notes, the messages attached to it and the changes of its values appear here.' }">
    <template #note="{ item }">
      <div v-if="item.subject && item.subject !== item.text" class="font-bold text-ink" dir="auto">{{ item.subject }}</div>
      <div v-if="item.text" class="whitespace-pre-wrap break-words" :class="{ 'line-clamp-6': item.long && !unfolded.has(item.i) }"><span dir="auto">{{ item.text }}</span></div>
      <button v-if="item.long" type="button" class="mt-0.5 cursor-pointer border-0 bg-transparent p-0 text-[9.5px] font-bold text-brand-dark hover:text-violet" @click="toggle(item.i)">{{ unfolded.has(item.i) ? t('إخفاء', 'Show less') : t('عرض الكل', 'Show all') }}</button>
      <div v-for="(c, ci) in item.changes" :key="ci" class="mt-1.5 rounded-[10px] bg-soft px-2.5 py-1.5">
        <div class="font-bold text-ink" dir="auto">{{ c.field || t('قيمة', 'A value') }}</div>
        <div class="flex gap-2"><span class="w-[30px] flex-none text-faint">{{ t('من', 'From') }}</span><span class="min-w-0 break-words" :class="{ 'text-faint': !c.old }" dir="auto">{{ c.old || t('فارغ', 'empty') }}</span></div>
        <div class="flex gap-2"><span class="w-[30px] flex-none text-faint">{{ t('إلى', 'To') }}</span><span class="min-w-0 break-words font-bold" :class="c.now ? 'text-ink' : 'text-faint'" dir="auto">{{ c.now || t('فارغ', 'empty') }}</span></div>
      </div>
    </template>
  </Timeline>
  <div v-if="older" class="mt-2 text-[9.5px] text-faint">{{ t(`المعروض أحدث ${fmtNum(rows.length)} من ${fmtNum(older)} في أودو.`, `The newest ${fmtNum(rows.length)} of ${fmtNum(older)} in Odoo are shown.`) }}</div>
</template>
