<script setup>
// One value of the archive, written the way its type asks — the `type` of a list column, of a summary entry or of a
// line column: text · number · qty · money (with the currency Odoo holds) · date · datetime (UTC from the API, shown
// in local time) · state (a chip: the bare value worded by the column's `options`, or `{ value, label, tone }`) ·
// partner / product `{ id, name }` (a link that opens their view) · user · bool. A summary entry may carry
// `link: { kind, id }`: its text then opens that document.
// Whatever came from Odoo is written as text, never as markup; nothing (null, Odoo's `false`) is «—».
//   <ArchiveValue type="money" :value="row.amount" :currency="row.currency" />
//   <ArchiveValue type="state" :value="row.state" :kind="kind.key" col="state" />
//   <ArchiveValue :type="s.type" :value="s.value" :link="s.link" wrap />
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { Chip } from '@/components';
import { t } from '@/i18n';
import { atText, currencyCode, dayText, figureOf, isBlank, numText, refOf, textOf, useArchive } from './archive';

const props = defineProps({
  type: { type: String, default: 'text' },
  value: { type: null, default: null },
  /** Currency of a money value that does not carry its own. */
  currency: { type: null, default: null },
  /** Kind whose catalog entry words a `state` value (its column of that key, else its choice filter). */
  kind: { type: String, default: null },
  /** Key of the column, for a state column that is not called `state`. */
  col: { type: String, default: 'state' },
  /** The wording of a state column, when the column at hand carries it: [{ value, label, tone }]. */
  options: { type: Array, default: null },
  /** `{ kind, id }` of a summary entry: the document the text opens. */
  link: { type: Object, default: null },
  /** Long text keeps its lines (summary); otherwise it stays on one line with an ellipsis (table cell). */
  wrap: { type: Boolean, default: false },
  /** The title of a row (the first column of a table): written heavier, as the name of the record. */
  lead: { type: Boolean, default: false },
});

const { stateOf, targetOf } = useArchive();

const YES = [true, 1, '1', 'true'];
const figure = computed(() => figureOf(props.value));
/** What is written, and how: { mode: blank | state | money | num | time | link | text, text }. */
const shown = computed(() => {
  const v = props.value;
  if (props.type === 'bool') return v == null || v === '' ? { mode: 'blank' } : { mode: 'text', text: YES.includes(v) ? t('نعم', 'Yes') : t('لا', 'No') };
  if (isBlank(v)) return { mode: 'blank' };
  let mode = 'text';
  let text = '';
  if (props.type === 'state') { const s = stateOf(props.kind, v, props.col, props.options); return s.label ? { mode: 'state', text: s.label, tone: s.tone } : { mode: 'blank' }; }
  if (props.type === 'money') { mode = figure.value.n == null ? 'text' : 'money'; text = figure.value.n == null ? textOf(v) : numText(figure.value.n, 'money'); }
  else if (props.type === 'number' || props.type === 'qty') { mode = 'num'; text = numText(v, props.type) || textOf(v); }
  else if (props.type === 'date') { mode = 'time'; text = dayText(v) || textOf(v); }
  else if (props.type === 'datetime') { mode = 'time'; text = atText(v) || textOf(v); }
  else { const ref = refOf(v); text = ref && ref.name ? ref.name : textOf(v); }
  if (!text) return { mode: 'blank' };
  return { mode: mode === 'text' && target.value ? 'link' : mode, text };
});
const target = computed(() => targetOf(props.type, props.value, props.link));
const unit = computed(() => figure.value.currency || currencyCode(props.currency));
/** A long text in a summary (terms, a note) starts folded to five lines. */
const long = computed(() => props.wrap && shown.value.mode === 'text' && (shown.value.text.length > 320 || shown.value.text.split('\n').length > 5));
const unfolded = ref(false);
/** A time is Odoo's UTC: the tooltip keeps the value as Odoo wrote it. */
const asWritten = computed(() => (props.type === 'datetime' && typeof props.value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(props.value) ? `${props.value} UTC` : null));
</script>

<template>
  <span v-if="shown.mode === 'blank'" class="font-normal text-faint">—</span>
  <Chip v-else-if="shown.mode === 'state'" small :label="shown.text" :fg="shown.tone.fg" :bg="shown.tone.bg" />
  <span v-else-if="shown.mode === 'money'" class="num ltr whitespace-nowrap" :class="{ 'text-ink': lead }">{{ shown.text }}<span v-if="unit" class="ms-1 text-[8.5px] font-normal text-muted">{{ unit }}</span></span>
  <span v-else-if="shown.mode === 'num' || shown.mode === 'time'" class="num ltr whitespace-nowrap" :class="{ 'text-ink': lead }" :title="asWritten">{{ shown.text }}</span>
  <RouterLink v-else-if="shown.mode === 'link'" :to="target" class="text-brand-dark hover:text-violet" :class="[wrap ? 'break-words' : 'block truncate [text-align:match-parent]', lead ? 'font-extrabold' : 'font-bold']" dir="auto" :title="wrap ? null : shown.text" @click.stop>{{ shown.text }}</RouterLink>
  <span v-else-if="long" class="block">
    <span class="block whitespace-pre-wrap break-words" :class="{ 'line-clamp-5': !unfolded }" dir="auto">{{ shown.text }}</span>
    <button type="button" class="mt-0.5 cursor-pointer border-0 bg-transparent p-0 text-[9.5px] font-bold text-brand-dark hover:text-violet" @click="unfolded = !unfolded">{{ unfolded ? t('إخفاء', 'Show less') : t('عرض الكل', 'Show all') }}</button>
  </span>
  <span v-else dir="auto" :class="[wrap ? 'whitespace-pre-wrap break-words' : 'block truncate [text-align:match-parent]', { 'font-extrabold text-ink': lead }]" :title="wrap ? null : shown.text">{{ shown.text }}</span>
</template>
