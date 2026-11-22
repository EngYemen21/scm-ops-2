<script setup>
// Odoo tab → sync log: every run of the mirror, newest first — who started it, how long it worked, how much it read
// and what it found. GET /integration/odoo/runs?page= (runs are kept as long as the change feed).
// A row opens what that run did model by model (OdooRunDrawer.vue).
import { ref } from 'vue';
import { useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
import { fmtNum, t } from '@/i18n';
import OdooRunDrawer from './OdooRunDrawer.vue';
import { RUN_TRIGGER, agoAt, fmtAt, fmtSecs, runFailedModels, useOdooNav } from './odoo';

const nav = useOdooNav();
const { page } = nav;
const list = useList('/integration/odoo/runs', () => ({ page: page.value, pageSize: 30 }));
/** The run whose detail is open: a row of the list. */
const sel = ref(null);

const columns = [
  { key: 'startedAt', header: { ar: 'بدأت', en: 'Started' }, width: '128px' },
  { key: 'trigger', header: { ar: 'من شغّلها', en: 'Started by' }, width: '180px', bare: true },
  { key: 'ms', header: { ar: 'المدة', en: 'Duration' }, width: '70px', kind: 'num', value: (r) => fmtSecs(r.ms) },
  { key: 'models', header: { ar: 'نماذج', en: 'Models' }, width: '62px', kind: 'num' },
  { key: 'calls', header: { ar: 'نداءات', en: 'Calls' }, width: '62px', kind: 'num' },
  { key: 'pulled', header: { ar: 'مقروءة', en: 'Read' }, width: '74px', kind: 'num' },
  { key: 'created', header: { ar: 'جديدة', en: 'Created' }, width: '66px', kind: 'num' },
  { key: 'updated', header: { ar: 'معدّلة', en: 'Updated' }, width: '66px', kind: 'num' },
  { key: 'deleted', header: { ar: 'محذوفة', en: 'Deleted' }, width: '66px', kind: 'num' },
  { key: 'errors', header: { ar: 'أخطاء', en: 'Errors' }, width: '62px' },
  { key: 'note', header: { ar: 'ملاحظة', en: 'Note' }, width: 'minmax(150px,1fr)' },
];
</script>

<template>
  <div class="row wrap">
    <div class="grow text-[10.5px] leading-[1.7] text-muted">{{ t('كل سطر دورة مزامنة واحدة: كم قرأت من أودو وماذا وجدت. افتح السطر لترى ما فعلته في كل نموذج.', 'Each line is one run: how much it read from Odoo and what it found. Open a line to see what it did in each model.') }}</div>
    <Btn tone="soft" size="sm" :loading="list.isFetching.value" :label="{ ar: 'تحديث', en: 'Refresh' }" @click="list.refetch()" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" class="!mb-0" />

  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :selected-key="sel ? sel.id : null" dense
               :empty-text="{ ar: 'لم تجرِ أي مزامنة بعد.', en: 'No run yet.' }" @page="nav.setPage" @row-click="(r) => (sel = r)">
      <template #cell-startedAt="{ row }">
        <span class="num ltr">{{ fmtAt(row.startedAt) }}</span>
        <div class="mt-px text-[8.5px] text-faint">{{ agoAt(row.startedAt) }}</div>
      </template>
      <template #cell-trigger="{ row }"><Chip small :map="RUN_TRIGGER" :k="row.trigger" /></template>
      <template #cell-errors="{ row }"><span class="cell-num" :class="row.errors > 0 ? 'text-bad' : 'text-faint'">{{ fmtNum(row.errors) }}</span></template>
      <template #cell-note="{ row }">
        <div v-if="row.detail?.error" class="ltr num-mixed truncate text-[10px] text-bad" :title="row.detail.error">{{ row.detail.error }}</div>
        <span v-else-if="!row.finishedAt" class="text-[10px] font-bold text-warn">{{ t('جارية الآن أو انقطعت قبل أن تنتهي', 'Running now, or cut before it finished') }}</span>
        <div v-else-if="runFailedModels(row).length" class="truncate text-[10px] text-bad" :title="runFailedModels(row).join(' · ')">{{ t('تعثّرت في', 'Failed in') }} <span class="num ltr">{{ runFailedModels(row).join(' · ') }}</span></div>
        <span v-else class="text-faint">—</span>
      </template>
    </DataTable>
  </div>

  <OdooRunDrawer :run="sel" @close="sel = null" />
</template>
