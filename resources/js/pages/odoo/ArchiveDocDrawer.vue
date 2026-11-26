<script setup>
// One document of the archive, whatever its kind (`doc=<kind>:<odooId>` in the query string, `tab=` its tab):
//   المستند   the summary as labelled values — a partner, a product or another document in it is a link —, the
//             child lists (lines) as tables, the related documents as links that open their own drawer
//   السجل     notes, e-mails and value changes as a timeline          المرفقات   the files, with a download once archived
//   كل الحقول every field of the record with Odoo's own label and its technical name
// Nothing here knows a kind by name: the answer describes itself — what one such document is called, its state with
// its wording and tone, what it hangs on (`parent`), whether Odoo still holds it (`deleted`), whether it is people's
// file (`private`). The record may be of another kind than the address says (a customer is a contact): the answer's
// own `kind` is the one its states and links are read with.
// GET /odoo-archive/docs/{kind}/{odooId} (403 ODOO_PRIVATE for people's files without odoo.private, 404 when the
// record is not in the archive — both are shown as the server words them).
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useGet } from '@/api/client';
import { Chip, Drawer, EmptyState, ErrorBanner, KV, Tabs } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import ArchiveDocLinks from './ArchiveDocLinks.vue';
import ArchiveFields from './ArchiveFields.vue';
import ArchiveFiles from './ArchiveFiles.vue';
import ArchiveHistory from './ArchiveHistory.vue';
import ArchiveLines from './ArchiveLines.vue';
import ArchiveValue from './ArchiveValue.vue';
import { API, ARCHIVED_CHIP, DELETED_CHIP, PRIVATE_CHIP, PRIVATE_HELP, arr, isBlank, isId, isKindKey, isObj, labelOf, oneOf, saidLabel, textOf, useArchive } from './archive';

const props = defineProps({
  /** Kind of the document; null closes the drawer. */
  kind: { type: String, default: null },
  /** Its record id in Odoo. */
  odooId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const { nav, kindOf, placeOf, stateOf } = useArchive();
const tab = nav.tab;
const isOpen = computed(() => !!props.kind && props.odooId != null);
// the archive changes only when a synchronisation run copies something: a document read a moment ago is not asked for again
const q = useGet(() => (isOpen.value ? `${API}/docs/${encodeURIComponent(props.kind)}/${props.odooId}` : null), null, { staleTime: 30_000 });
const d = computed(() => (isOpen.value && isObj(q.data.value) ? q.data.value : null));
const docKey = computed(() => `${props.kind}:${props.odooId}`);

/** The kind the record is of: the answer's own, else the one of the address. */
const ownKind = computed(() => (isKindKey(d.value?.kind) ? d.value.kind : props.kind));
/** Its catalog entry; null when the user's catalog does not hold the kind. */
const entry = computed(() => kindOf(ownKind.value));
/** What one such document is called: the answer says it, else the catalog (its name for one, then for the list). */
const kindLabel = computed(() => saidLabel(d.value) || oneOf(entry.value) || labelOf(entry.value));
const number = computed(() => textOf(d.value?.number));
const title = computed(() => { const x = textOf(d.value?.title); return x && x !== number.value ? x : ''; });
const state = computed(() => { const s = d.value?.state; const st = isBlank(s) ? null : stateOf(ownKind.value, s); return st && st.label ? st : null; });
const isPrivate = computed(() => d.value?.private === true || !!entry.value?.private);
/** The document this one hangs on (the order of a line, the invoice of a credit note): { to, label, name } or null. */
const parent = computed(() => {
  const p = d.value?.parent;
  if (!isObj(p) || !isId(p.id) || !kindOf(p.kind)) return null;
  return { to: placeOf(p.kind, p.id), label: saidLabel(p) || oneOf(kindOf(p.kind)) || labelOf(kindOf(p.kind)), name: textOf(p.name) || `#${p.id}` };
});
const summary = computed(() => arr(d.value?.summary).filter(isObj));
const sections = computed(() => arr(d.value?.sections).filter(isObj));
const related = computed(() => arr(d.value?.related).filter((g) => isObj(g) && arr(g.items).length).map((g, i) => {
  const kindKey = isKindKey(g.kind) ? g.kind : null;
  const total = Number.isFinite(Number(g.total)) && g.total != null ? Number(g.total) : g.items.length;
  // the whole group, in the list of its kind: with what the answer says finds it there
  const all = total > g.items.length && kindKey && kindOf(kindKey) && isObj(g.query) && Object.keys(g.query).length ? nav.kindTo(kindKey, g.query) : null;
  // `reconciled`: what settled the document (payments, credit notes) — the amount beside each is what was matched with it
  return { key: `${kindKey || 'g'}:${i}`, kind: kindKey, label: labelOf(g) || labelOf(kindOf(kindKey)) || t('مستندات مرتبطة', 'Related documents'), items: g.items, total, all, reconciled: g.reconciled === true };
}));
const history = computed(() => arr(d.value?.history));
const historyTotal = computed(() => { const n = Number(d.value?.historyTotal); return d.value?.historyTotal != null && Number.isFinite(n) ? n : null; });
const files = computed(() => arr(d.value?.attachments));
const fields = computed(() => arr(d.value?.fields));
const lineParent = computed(() => ({ model: d.value?.model, odooId: props.odooId }));

/** A state in the summary given as a bare value is the document's own state: worded the way the answer words it. */
const summaryValue = (s) => (s.type === 'state' && isObj(d.value?.state) && !isObj(s.value) && String(d.value.state.value) === String(s.value) ? d.value.state : s.value);
/** Text that needs the whole row: long, or in several lines. */
const wide = (s) => typeof s.value === 'string' && (s.value.length > 60 || s.value.includes('\n'));
const withCount = (label, n) => (n ? { ar: `${label.ar} (${fmtNum(n)})`, en: `${label.en} (${fmtNum(n)})` } : label);
const tabs = computed(() => [
  { k: 'doc', label: { ar: 'المستند', en: 'Document' } },
  { k: 'history', label: withCount({ ar: 'السجل', en: 'History' }, historyTotal.value ?? history.value.length) },
  { k: 'files', label: withCount({ ar: 'المرفقات', en: 'Attachments' }, files.value.length) },
  { k: 'fields', label: withCount({ ar: 'كل الحقول', en: 'All fields' }, fields.value.length) },
]);
</script>

<template>
  <Drawer :open="isOpen" :width="860" @close="emit('close')">
    <template #title>
      <span v-if="number" class="num-mixed" dir="auto">{{ number }}</span>
      <span v-if="title" class="font-bold text-sec" :class="{ 'ms-2': number }" dir="auto">{{ title }}</span>
      <span v-if="!number && !title" class="num ltr inline-block">#{{ odooId }}</span>
    </template>
    <template #sub><span v-if="kindLabel" dir="auto">{{ kindLabel }} · </span>{{ t('رقم السجل في أودو', 'Record id in Odoo') }} <span class="num ltr inline-block">#{{ odooId }}</span></template>
    <template v-if="d && (state || d.active === false || d.deleted === true || isPrivate)" #headExtra>
      <div class="row !gap-1">
        <Chip v-if="isPrivate" small :label="PRIVATE_CHIP" :fg="PRIVATE_CHIP.fg" :bg="PRIVATE_CHIP.bg" :title="bi(PRIVATE_HELP)" />
        <Chip v-if="d.deleted === true" small :label="DELETED_CHIP" :fg="DELETED_CHIP.fg" :bg="DELETED_CHIP.bg" />
        <Chip v-else-if="d.active === false" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <Chip v-if="state" :label="state.label" :fg="state.tone.fg" :bg="state.tone.bg" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="col"><div class="skel h-[30px] w-[70%]" /><div class="skel h-[96px]" /><div class="skel h-[180px]" /></div>
    <template v-if="d">
      <div v-if="d.deleted === true" class="hint amber !mt-0 mb-3">{{ t('هذا السجل لم يعد موجودًا في أودو. ما تراه آخر قيمه قبل الحذف، محفوظة في الأرشيف.', 'This record no longer exists in Odoo. What you see are its last values before the deletion, kept in the archive.') }}</div>
      <div v-if="parent" class="mb-3 text-[10.5px] text-muted">
        {{ t('تابع لـ', 'Part of') }} <span v-if="parent.label" dir="auto">{{ parent.label }}</span>
        <RouterLink :to="parent.to" class="num-mixed font-bold" dir="auto">{{ parent.name }}</RouterLink>
      </div>
      <Tabs :model-value="tab" :tabs="tabs" variant="sm" class="!mb-3.5" @update:model-value="nav.setTab" />

      <template v-if="tab === 'doc'">
        <div v-if="summary.length" class="kv-grid">
          <KV v-for="(s, i) in summary" :key="s.key || i" :k="labelOf(s) || '—'" :class="{ 'col-span-full': wide(s) }">
            <ArchiveValue :type="s.type || 'text'" :value="summaryValue(s)" :link="isObj(s.link) ? s.link : null" :currency="s.currency ?? d.currency" :kind="ownKind" wrap />
          </KV>
        </div>
        <ArchiveLines v-for="(s, i) in sections" :key="`${docKey}:${s.key ?? i}`" :parent="lineParent" :section="s" class="mt-[18px]" />
        <div v-for="g in related" :key="g.key" class="mt-[18px]">
          <div class="mb-1.5 flex flex-wrap items-center gap-2">
            <div class="text-[12px] font-extrabold" dir="auto">{{ g.label }}</div>
            <span class="num ltr text-[9.5px] font-bold text-faint">{{ fmtNum(g.total) }}</span>
            <span class="flex-1" />
            <RouterLink v-if="g.all" :to="g.all" class="text-[10px] font-bold">{{ t(`عرض الكل (${fmtNum(g.total)})`, `Show all (${fmtNum(g.total)})`) }}</RouterLink>
          </div>
          <div v-if="g.reconciled" class="mb-1.5 text-[9.5px] leading-[1.6] text-faint">{{ t('المبلغ أمام كل مستند هنا هو ما سُوِّي منه مع هذا المستند، لا إجماليه.', 'The amount beside each document here is what was settled against this one, not its own total.') }}</div>
          <ArchiveDocLinks :kind="g.kind" :items="g.items" />
        </div>
        <EmptyState v-if="!summary.length && !sections.length && !related.length" :text="{ ar: 'لا ملخّص لهذا النوع من السجلات. قيمه كلها في تبويب «كل الحقول».', en: 'This kind of record has no summary. All its values are under “All fields”.' }" />
      </template>
      <ArchiveHistory v-else-if="tab === 'history'" :key="`h:${docKey}`" :items="history" :total="historyTotal" />
      <ArchiveFiles v-else-if="tab === 'files'" :key="`f:${docKey}`" :items="files" />
      <ArchiveFields v-else :key="`a:${docKey}`" :fields="fields" :model="typeof d.model === 'string' ? d.model : null" :odoo-id="odooId" />
    </template>
  </Drawer>
</template>
