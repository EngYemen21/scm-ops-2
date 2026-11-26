<script setup>
// «أرشيف أودو» / "Odoo archive" (docs/odoo/WAVE2.md §3–§4): everything the company's Odoo holds — sales orders,
// invoices, payments, stock transfers, leads, employees… — consulted inside OPS as business documents, Arabic first.
// Read-only: it shows the copy OPS keeps of Odoo and changes nothing, here or there.
//   the home          the sections of the catalog as cards, and a search across every kind        ArchiveHome.vue
//   a list            any kind, driven by its catalog entry: columns, filters, sort, totals        ArchiveList.vue
//   a document        any document: summary, lines, related documents, history, files, fields      ArchiveDocDrawer.vue
//   a partner · a product   opened from any partner / product link                                 Archive{Partner,Product}Drawer.vue
// All of it lives in the query string, so every view is a link (the keys are listed at `useArchiveNav` in ./archive.js):
//   /odoo?kind=sale_order&state=sale&q=S00&doc=sale_order:123          /odoo?card=partner:42
// GET /api/odoo-archive/catalog decides what is listed (page.odoo; people's files only with odoo.private).
// «تصدير CSV» — GET docs/{kind}/export with the filters of the list on screen, in the language on screen (`lang`) —
// needs report.export.
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useAction } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, PageHead } from '@/components';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { utcDate } from '../integration/odoo';
import ArchiveDocDrawer from './ArchiveDocDrawer.vue';
import ArchiveHome from './ArchiveHome.vue';
import ArchiveList from './ArchiveList.vue';
import ArchivePartnerDrawer from './ArchivePartnerDrawer.vue';
import ArchiveProductDrawer from './ArchiveProductDrawer.vue';
import { API, downloadFromApi, isoToday, provideArchive } from './archive';

const { nav, query: catalog, data, kind, list, canExport, hidesKinds } = provideArchive();
const { wantsKind } = nav;

/** `indexed` of the catalog: how many records the index holds so far, or when it was last built. */
const indexed = computed(() => {
  const v = data.value?.indexed;
  if (typeof v === 'number' && Number.isFinite(v)) return hidesKinds.value ? null : { count: v }; // a count that includes what this user may not read is not shown
  const at = typeof v === 'string' ? utcDate(v) : null;
  return at ? { at: fmtDate(at) } : null;
});

// one drawer at a time: a partner / product view is drawn above the document it was opened from, which shows again when the view closes
const card = computed(() => nav.cardOpen.value);
const doc = computed(() => (card.value ? null : nav.docOpen.value));

// ---- CSV export of the list on screen (a download, not a mutation: nothing to invalidate)
const exporter = useAction();
function exportCsv() {
  const k = kind.value;
  if (!k) return;
  // the file's headings and the labels of its states come in the language of the screen
  exporter.run(() => downloadFromApi(`${API}/docs/${encodeURIComponent(k.key)}/export`, { ...list.exportParams.value, lang: lang.value }, `${k.key}-${isoToday()}.csv`), { success: (file) => t(`تم تصدير ${file}`, `Exported ${file}`), invalidate: false });
}
</script>

<template>
  <PageHead :sub="t('كل ما يحتفظ به أودو — مبيعات، مشتريات، مخزون، محاسبة… — محفوظ داخل نظام العمليات للاطلاع والبحث', 'Everything Odoo holds — sales, purchasing, inventory, accounting… — kept inside OPS to consult and search')">
    <Btn v-if="kind && canExport" tone="dark" :loading="exporter.pending.value" :title="t('ملف CSV بأعمدة هذه القائمة وبالبحث والتصفية الحاليين — حتى 50,000 صف', 'A CSV file with the columns of this list and the current search and filters — up to 50,000 rows')" :label="{ ar: 'تصدير CSV', en: 'Export CSV' }" @click="exportCsv" />
  </PageHead>

  <div class="col !gap-3">
    <ErrorBanner :error="catalog.error.value" :closable="false" class="!mb-0" />
    <ErrorBanner :error="exporter.error.value" class="!mb-0" @close="exporter.clearError()" />
    <template v-if="catalog.isLoading.value">
      <div class="skel h-[40px]" />
      <div class="grid-eq"><div v-for="i in 3" :key="i" class="skel h-[170px]" /></div>
    </template>

    <template v-else-if="data">
      <div v-if="data.ready === false" class="hint amber !mt-0">
        <b>{{ t('فهرس الأرشيف لم يكتمل بعد.', 'The archive index is not complete yet.') }}</b>
        {{ t('الأعداد ونتائج البحث والقوائم قد تكون ناقصة إلى أن ينتهي بناؤه. تُحدَّث أعداد الأقسام هنا تلقائيًا كل نصف دقيقة.', 'Counts, search results and lists may be incomplete until it is built. The counts of the sections refresh by themselves every half minute.') }}
        <template v-if="indexed && indexed.count != null">{{ t('عدد السجلات المفهرسة حتى الآن', 'Records indexed so far') }} <b class="num ltr">{{ fmtNum(indexed.count) }}</b>.</template>
        <template v-else-if="indexed">{{ t('آخر فهرسة', 'Last indexed') }} <span class="num ltr">{{ indexed.at }}</span>.</template>
      </div>

      <ArchiveList v-if="kind" :key="kind.key" :kind="kind" />
      <!-- `kind=` names nothing this user may open: a wrong link, or people's files without the permission -->
      <EmptyState v-else-if="wantsKind" tone="dashed" :text="{ ar: 'لا يوجد في الأرشيف نوع بهذا الاسم، أو لا تملك صلاحية فتحه. تظهر هنا قائمة سجلاته حين يكون الرابط صحيحًا.', en: 'The archive has no kind of that name, or you may not open it. Its list of records appears here when the link is right.' }">
        <template #action><RouterLink :to="nav.homeTo()" class="btn soft sm">{{ t('كل الأقسام', 'All sections') }}</RouterLink></template>
      </EmptyState>
      <ArchiveHome v-else />
    </template>
  </div>

  <ArchiveDocDrawer :kind="doc ? doc.kind : null" :odoo-id="doc ? doc.id : null" @close="nav.closeDoc()" />
  <ArchivePartnerDrawer :odoo-id="card && card.type === 'partner' ? card.id : null" @close="nav.closeCard()" />
  <ArchiveProductDrawer :odoo-id="card && card.type === 'product' ? card.id : null" @close="nav.closeCard()" />
</template>
