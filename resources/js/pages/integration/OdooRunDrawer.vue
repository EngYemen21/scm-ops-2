<script setup>
// One run of the mirror, model by model: what it copied for the first time, the changes it found, the deletions it
// noticed, the records it recovered, and where it failed. Everything comes from the row of the run log itself
// (`detail` of GET /integration/odoo/runs — there is no single-run endpoint, so this drawer is opened from the
// list and is not a link). A model line opens that model's drawer.
//   <OdooRunDrawer :run="rowOrNull" @close="…" />
import { computed } from 'vue';
import { Chip, DataTable, Drawer } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { RUN_TRIGGER, TONE, fmtAt, isModelName, runLine, runStepLines, runStepTitle, runTiles, useOdooNav } from './odoo';

const props = defineProps({
  /** A row of odoo_runs: { id, trigger, startedAt, finishedAt, ms, models, calls, pulled, created, updated, deleted, errors, detail }. */
  run: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const nav = useOdooNav();
const entries = computed(() => Object.entries(props.run?.detail?.models || {}));
/** `detail.models`: { model: { firstCopy?, changed?, deleted?, healed?, error? } } — only the models where something happened. */
const perModel = computed(() => entries.value.filter(([name]) => isModelName(name)).map(([model, x]) => ({ model, ...x })));
/** Entries that are not a model: what the run applied to the OPS master data ({ changes, counts } or { error }). */
const steps = computed(() => entries.value.filter(([name]) => !isModelName(name)).map(([name, x]) => ({ name, error: x?.error || null, changes: x?.changes ?? null, lines: runStepLines(x?.counts) })));
function openModel(name) { emit('close'); nav.openModel(name); }

const columns = [
  { key: 'model', header: { ar: 'النموذج', en: 'Model' }, width: 'minmax(150px,1.4fr)' },
  { key: 'firstCopy', header: { ar: 'نسخة أولى', en: 'First copy' }, width: '74px', kind: 'num' },
  { key: 'changed', header: { ar: 'تغييرات', en: 'Changes' }, width: '66px', kind: 'num' },
  { key: 'deleted', header: { ar: 'محذوفة', en: 'Deleted' }, width: '62px', kind: 'num' },
  { key: 'healed', header: { ar: 'مستدركة', en: 'Recovered' }, width: '68px', kind: 'num' },
];
</script>

<template>
  <Drawer :open="!!run" :width="560" :title="run ? { ar: `دورة المزامنة رقم ${run.id}`, en: `Run no. ${run.id}` } : null" @close="emit('close')">
    <template #sub><span v-if="run" class="num ltr inline-block">{{ fmtAt(run.startedAt) }}</span></template>
    <template v-if="run" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="RUN_TRIGGER" :k="run.trigger" />
        <Chip v-if="!run.finishedAt" small :label="{ ar: 'لم تنتهِ', en: 'Not finished' }" :fg="TONE.warn.fg" :bg="TONE.warn.bg" />
      </div>
    </template>

    <template v-if="run">
      <div class="text-[11px] font-bold leading-[1.8] text-sec">{{ runLine(run) }}</div>
      <div class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(104px,1fr))] gap-2">
        <div v-for="x in runTiles(run)" :key="x.k" class="tile" :class="{ red: x.bad }">
          <div class="tile-v" :class="{ 'text-bad': x.bad }">{{ x.v }}</div>
          <div class="tile-l">{{ bi(x.l) }}</div>
        </div>
      </div>
      <div v-if="run.detail?.error" class="mt-3 rounded-[10px] bg-bad-soft px-3 py-2 text-[10.5px] leading-[1.8] text-bad">
        <b>{{ t('لم تستطع الدورة القراءة من أودو', 'The run could not read from Odoo') }}</b>
        <div class="ltr num-mixed break-words">{{ run.detail.error }}</div>
      </div>

      <div class="mx-0.5 mb-2 mt-[18px] text-[11px] font-extrabold text-muted">{{ t('ما فعلته في كل نموذج', 'What it did in each model') }} <span class="card-count num">{{ fmtNum(perModel.length) }}</span></div>
      <div class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="columns" :rows="perModel" :row-key="(r) => r.model" dense :empty-text="{ ar: 'لا جديد في أي نموذج خلال هذه الدورة.', en: 'Nothing new in any model during this run.' }" @row-click="(r) => openModel(r.model)">
          <template #cell-model="{ row }">
            <div class="min-w-0">
              <div class="cell-id ltr break-all">{{ row.model }}</div>
              <div v-if="row.error" class="ltr num-mixed mt-0.5 break-words text-[9.5px] leading-[1.6] text-bad">{{ row.error }}</div>
            </div>
          </template>
        </DataTable>
      </div>
      <div class="mt-2 text-[9.5px] leading-[1.7] text-faint">
        {{ t('«نسخة أولى»: سجلات نُسخت لأول مرة. «مستدركة»: سجلات موجودة في أودو لم تكن في النسخة فأُضيفت أثناء المقارنة التي تكشف الحذف.', '“First copy”: records copied for the first time. “Recovered”: records Odoo holds that the copy lacked, added during the comparison that finds deletions.') }}
        <template v-if="entries.length >= 60"> {{ t('يُحفظ تفصيل أول 60 نموذجًا فقط.', 'Only the first 60 models are kept in the detail.') }}</template>
      </div>

      <!-- steps of the run that are not a model: what it applied to the OPS master data -->
      <div v-for="s in steps" :key="s.name" class="mt-3 rounded-xl border border-line-2 px-3 py-2.5 text-[10.5px] leading-[1.8]">
        <div class="font-extrabold text-ink">{{ runStepTitle(s.name) }}</div>
        <div v-if="s.error" class="ltr num-mixed break-words text-bad">{{ s.error }}</div>
        <template v-else>
          <div v-if="s.changes != null" class="text-muted">{{ t('تغييرات من أودو طُبّقت على بيانات النظام', 'Changes from Odoo applied to the OPS data') }} <b class="num">{{ fmtNum(s.changes) }}</b></div>
          <div v-for="l in s.lines" :key="l.entity" class="text-sec"><b>{{ l.entity }}:</b> {{ l.parts }}</div>
        </template>
      </div>
    </template>
  </Drawer>
</template>
