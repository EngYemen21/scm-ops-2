<script setup>
// Reports — catalogue from GET /api/reports, one report at a time from GET /api/reports/:name (the columns come with
// the response), totals row, CSV export via `?format=csv` (button shown with `report.export`; the server enforces it).
// The selected report and its parameters live in the URL:  /reports?r=expiry&warehouse=RYD&from=…&to=…&q=…&page=2
import { computed, ref } from 'vue';
import { useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, EmptyState, ErrorBanner, PageHead, SelectInput, TextInput } from '@/components';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import CardTitle from './CardTitle.vue';
import { REPORT_GROUP_LABELS, columnOf, downloadReportCsv, fmtCell, isoDay } from './reports';
import { useQueryState } from './shared';

const auth = useAuth();
const wh = useWarehouse();
const qs = useQueryState();

const catalogue = useGet('/reports');
const name = computed(() => qs.get('r'));
const meta = computed(() => (catalogue.data.value || []).find((r) => r.name === name.value));
/** [[group, ReportMeta[]], …] in catalogue order. */
const groups = computed(() => {
  const m = new Map();
  for (const r of catalogue.data.value || []) m.set(r.group, [...(m.get(r.group) || []), r]);
  return [...m.entries()];
});

// ---- parameters (the warehouse defaults to the topbar selector until the URL says otherwise)
const warehouse = computed(() => (qs.has('warehouse') ? qs.get('warehouse') : wh.current?.code || ''));
const from = computed(() => qs.get('from'));
const to = computed(() => qs.get('to'));
const q = ref(qs.get('q'));
const page = computed(() => Number(qs.get('page') || 1));
const params = computed(() => ({ warehouse: warehouse.value || undefined, from: from.value || undefined, to: to.value || undefined, q: qs.get('q') || undefined, page: page.value, pageSize: 50 }));
const hasFilters = computed(() => !!(warehouse.value || from.value || to.value || qs.get('q')));
const warehouseOpts = computed(() => wh.warehouses.map((w) => ({ v: w.code, l: { ar: `${w.code} · ${w.nameAr}`, en: `${w.code} · ${w.nameEn}` } })));

const report = useGet(() => (name.value ? `/reports/${name.value}` : null), () => params.value, { staleTime: 30_000 });
const rep = computed(() => report.data.value);
const columns = computed(() => (rep.value?.columns || []).map(columnOf));
const statusCols = computed(() => columns.value.filter((c) => c.type === 'status'));
const paged = computed(() => (rep.value ? { items: rep.value.rows, total: rep.value.total, page: rep.value.page, pageSize: rep.value.pageSize, pages: rep.value.pages } : null));
const totalsEntries = computed(() => {
  const r = rep.value;
  return r?.totals && r.columns ? r.columns.filter((c) => r.totals[c.key] != null).map((c) => ({ c, v: r.totals[c.key] })) : [];
});

function pick(r) {
  const n = { ...qs.route.query, r: r.name };
  delete n.page; delete n.q;
  q.value = '';
  qs.replace(n);
}
function lastDays(days) {
  const n = { ...qs.route.query, from: isoDay(new Date(Date.now() - (days - 1) * 86400000)), to: isoDay(new Date()) };
  delete n.page;
  qs.replace(n);
}
function clearFilters() { q.value = ''; qs.replace({ r: name.value }); }

// ---- CSV export (a download, not a mutation: nothing to invalidate)
const exporter = useAction();
function exportCsv() {
  if (!name.value) return;
  exporter.run(() => downloadReportCsv(name.value, params.value), { success: (file) => t(`تم تصدير ${file}`, `Exported ${file}`), invalidate: false });
}
</script>

<template>
  <PageHead :sub="t('تقارير تشغيلية جاهزة للتصدير — كل الأرقام من قاعدة البيانات لحظة التوليد', 'Operational reports ready to export — every figure is computed from the database at generation time')">
    <Btn v-if="name && auth.can('report.export')" tone="dark" :loading="exporter.pending.value" :label="{ ar: 'تصدير CSV', en: 'Export CSV' }" @click="exportCsv" />
  </PageHead>
  <ErrorBanner :error="catalogue.error.value" :closable="false" />

  <div class="grid grid-cols-[minmax(210px,240px)_1fr] items-start gap-3.5">
    <!-- catalogue -->
    <div class="card">
      <CardTitle>{{ t('التقارير', 'Reports') }} <span class="num font-normal text-faint">({{ fmtNum(catalogue.data.value?.length || 0) }})</span></CardTitle>
      <div v-if="catalogue.isLoading.value && !catalogue.data.value" class="p-[18px]"><div class="skel mb-2 h-3" /><div class="skel h-3 w-[70%]" /></div>
      <div v-for="[g, list] in groups" :key="g" class="border-t border-line-2 px-2.5 py-2">
        <div class="px-2 pt-0.5 pb-1 text-[9px] font-extrabold text-faint">{{ lang === 'ar' ? REPORT_GROUP_LABELS[g]?.ar || g : REPORT_GROUP_LABELS[g]?.en || g }}</div>
        <button v-for="r in list" :key="r.name" type="button" class="menu-item !gap-1.5 !rounded-[9px] !border-0 !px-2 !py-[7px]" :class="r.name === name ? '!bg-[#EFEAF8] !font-extrabold !text-violet' : '!bg-transparent !text-sec'" @click="pick(r)">
          <span class="flex-1">{{ lang === 'ar' ? r.titleAr : r.titleEn }}</span>
          <span v-if="r.snapshot" class="num text-[8px] text-brand-dark">{{ t('لحظي', 'live') }}</span>
        </button>
      </div>
    </div>

    <!-- report -->
    <div class="card min-w-0">
      <EmptyState v-if="!name" tone="dashed" class="m-[18px]" :text="{ ar: 'اختر تقريرًا من القائمة', en: 'Pick a report from the list' }" />
      <template v-else>
        <CardTitle>
          {{ meta ? (lang === 'ar' ? meta.titleAr : meta.titleEn) : name }}
          <Chip v-if="meta?.snapshot" small class="ms-2" :label="{ ar: 'لقطة لحظية', en: 'Live snapshot' }" fg="#0d7f93" bg="#d9f4f9" />
          <span v-else-if="meta" class="ms-2 text-[9.5px] font-normal text-faint">{{ t(`آخر ${meta.defaultDays} يوم افتراضيًا`, `last ${meta.defaultDays} days by default`) }}</span>
          <template #right>
            <span v-if="rep" class="num text-[9.5px] font-normal text-faint">{{ fmtNum(rep.total) }} {{ t('صف', 'rows') }} · {{ t('وُلّد', 'generated') }} {{ fmtDate(rep.generatedAt) }}</span>
          </template>
        </CardTitle>
        <div class="row wrap !items-end px-[18px] pb-3">
          <SelectInput small class="w-[170px]" :label="{ ar: 'المستودع', en: 'Warehouse' }" :model-value="warehouse" :options="warehouseOpts" :placeholder="{ ar: 'كل المستودعات', en: 'All warehouses' }" @update:model-value="(v) => qs.setParam('warehouse', v || null)" />
          <template v-if="!meta?.snapshot">
            <DateInput small class="w-[140px]" :label="{ ar: 'من', en: 'From' }" :model-value="from" @update:model-value="(v) => qs.setParam('from', v || null)" />
            <DateInput small class="w-[140px]" :label="{ ar: 'إلى', en: 'To' }" :model-value="to" @update:model-value="(v) => qs.setParam('to', v || null)" />
            <Btn tone="ghost" size="sm" :label="{ ar: '7 أيام', en: '7d' }" @click="lastDays(7)" />
            <Btn tone="ghost" size="sm" :label="{ ar: '30 يوم', en: '30d' }" @click="lastDays(30)" />
          </template>
          <TextInput v-model="q" small class="w-[190px]" :label="{ ar: 'بحث', en: 'Search' }" :placeholder="{ ar: 'SKU / رقم / اسم…', en: 'SKU / number / name…' }" @enter="qs.setParam('q', q || null)" />
          <Btn tone="dark" size="sm" :label="{ ar: 'تطبيق', en: 'Apply' }" @click="qs.setParam('q', q || null)" />
          <Btn v-if="hasFilters" tone="ghost" size="sm" :label="{ ar: 'إزالة الفلتر', en: 'Clear' }" @click="clearFilters" />
        </div>
        <div v-if="report.error.value || exporter.error.value" class="mx-[18px]"><ErrorBanner :error="report.error.value || exporter.error.value" @close="exporter.clearError()" /></div>
        <DataTable :columns="columns" :paged="paged" :loading="report.isLoading.value || report.isFetching.value" :row-key="(_, i) => `${rep?.page || 1}-${i}`" :min-width="Math.max(600, columns.length * 120)" dense
                   :empty-text="{ ar: 'لا بيانات مطابقة للفلاتر.', en: 'No data for these filters.' }" @page="(p) => qs.setParam('page', p > 1 ? String(p) : null)">
          <template v-for="c in statusCols" :key="c.key" #[`cell-${c.key}`]="{ row }">
            <Chip v-if="row[c.key] != null && row[c.key] !== ''" small :label="String(row[c.key])" /><template v-else>—</template>
          </template>
          <template v-if="totalsEntries.length" #footer>
            <div class="row wrap me-3 !gap-3">
              <span class="font-extrabold text-violet">{{ t('الإجماليات', 'Totals') }}:</span>
              <span v-for="{ c, v } in totalsEntries" :key="c.key" class="num-mixed"><span class="text-faint">{{ lang === 'ar' ? c.labelAr : c.labelEn }}</span> <b class="num text-ink">{{ fmtCell(v, c.type === 'text' ? 'number' : c.type) }}</b></span>
            </div>
          </template>
        </DataTable>
      </template>
    </div>
  </div>
</template>
