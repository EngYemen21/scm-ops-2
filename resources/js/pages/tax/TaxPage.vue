<script setup>
// «الضريبة والفوترة الإلكترونية» — VAT returns, e-invoices and their onboarding with the authority, the tax dictionary
// (codes, groups, fiscal positions), the taxpayer and its partners, and the e-invoice history that came from Odoo.
// Everything lives in the query string (keys listed at `useTaxNav` in ./tax.js), so every view is a link:
//   /tax                                    the overview            /tax?tab=returns&ret=<id>         one return
//   /tax?tab=returns&ret=<id>&box=1         the documents behind line 1 of the return
//   /tax?tab=einvoices&doc=<id>             one e-invoice           /tax?tab=einvoices&view=pending
//   /tax?tab=codes&code=<id>                one tax code            /tax?tab=history&hist=<id>
// Reading needs page.tax; vatreturn.manage keeps the dictionary and draft returns, vatreturn.file files / locks,
// einvoice.manage e-invoices and units (buttons are hidden without them; the server enforces all of it).
import { computed } from 'vue';
import { ErrorBanner, PageHead, Tabs } from '@/components';
import { t } from '@/i18n';
import CodesTab from './CodesTab.vue';
import EinvoiceDrawer from './EinvoiceDrawer.vue';
import EinvoicesTab from './EinvoicesTab.vue';
import EntriesDrawer from './EntriesDrawer.vue';
import HistoryDrawer from './HistoryDrawer.vue';
import HistoryTab from './HistoryTab.vue';
import PartiesTab from './PartiesTab.vue';
import PositionsTab from './PositionsTab.vue';
import ReturnDrawer from './ReturnDrawer.vue';
import ReturnsTab from './ReturnsTab.vue';
import TaxOverview from './TaxOverview.vue';
import UnitsTab from './UnitsTab.vue';
import { useTaxLookups, useTaxNav } from './tax';

const nav = useTaxNav();
const { tab } = nav;
const lk = useTaxLookups();
const follows = lk.follows;
const lookupsError = computed(() => lk.query.error.value);

const tabs = [
  { k: 'overview', label: { ar: 'نظرة عامة', en: 'Overview' } },
  { k: 'returns', label: { ar: 'إقرارات القيمة المضافة', en: 'VAT returns' } },
  { k: 'einvoices', label: { ar: 'الفواتير الإلكترونية', en: 'E-invoices' } },
  { k: 'units', label: { ar: 'الوحدات والربط', en: 'Units & onboarding' } },
  { k: 'codes', label: { ar: 'الرموز الضريبية', en: 'Tax codes' } },
  { k: 'positions', label: { ar: 'المواقف الضريبية', en: 'Fiscal positions' } },
  { k: 'parties', label: { ar: 'المكلّف والأطراف', en: 'Taxpayer & parties' } },
  { k: 'history', label: { ar: 'سجل أودو', en: 'Odoo history' } },
];

const returnId = computed(() => nav.get('ret') || null);
const openBox = ({ box, column }) => nav.patch({ box, col: column || null });
</script>

<template>
  <PageHead :sub="t('إقرارات ضريبة القيمة المضافة · الفوترة الإلكترونية (فاتورة) · الرموز والمواقف الضريبية · بيانات المكلّف', 'VAT returns · e-invoicing (Fatoora) · tax codes and fiscal positions · taxpayer data')" />

  <ErrorBanner :error="lookupsError" :closable="false" />
  <div v-if="follows" class="hint purple no-print !mt-0 mb-3">
    {{ t('هذا القسم يتبع أودو حاليًا: الرموز والمجموعات والمواقف الضريبية القادمة من أودو تُعرض هنا وتُعدَّل هناك؛ ما يخص العمليات منها (الرمز المختصر، الافتراضي، البند أو السبب إن لم يحدّده أودو) يُعدَّل هنا.',
      'This section follows Odoo for now: tax codes, groups and fiscal positions that come from Odoo are shown here and changed there; what is OPS\'s own on them (short code, default, the line or reason Odoo does not give) is edited here.') }}
  </div>

  <div class="no-print"><Tabs :model-value="tab" :tabs="tabs" @update:model-value="(k) => nav.go(k)" /></div>
  <TaxOverview v-if="tab === 'overview'" />
  <ReturnsTab v-else-if="tab === 'returns'" />
  <EinvoicesTab v-else-if="tab === 'einvoices'" />
  <UnitsTab v-else-if="tab === 'units'" />
  <CodesTab v-else-if="tab === 'codes'" />
  <PositionsTab v-else-if="tab === 'positions'" />
  <PartiesTab v-else-if="tab === 'parties'" />
  <HistoryTab v-else-if="tab === 'history'" />

  <!-- records that open on any tab -->
  <ReturnDrawer :id="returnId" @close="nav.patch({ ret: null, box: null, col: null })" @box="openBox" />
  <EntriesDrawer :return-id="returnId" :box="returnId ? nav.get('box') || null : null" :column="nav.get('col') || null" @close="nav.patch({ box: null, col: null })" />
  <EinvoiceDrawer :id="nav.get('doc') || null" @close="nav.patch({ doc: null })" @open="(id) => nav.patch({ doc: id })" />
  <HistoryDrawer :id="nav.get('hist') || null" @close="nav.patch({ hist: null })" />
</template>
