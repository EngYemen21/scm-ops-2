<script setup>
// Integration tower → «أودو» / "Odoo": the read-only live copy of the company's Odoo that OPS keeps in its own tables
// (app/Integration/Odoo — OPS reads Odoo and never writes to it). A second-level switch — overview · Odoo stock ·
// master data · models · changes · records · sync log — and two drawers (one model, one record). All of it lives in the query string, so
// every view, filter and open drawer is a link (the keys are listed at `useOdooNav` in ./odoo.js):
//   /itower?tab=odoo&view=changes&model=sale.order&kind=updated          /itower?tab=odoo&rec=res.partner:42
// GET /api/integration/odoo/status decides what is shown — connection not configured: one paragraph and nothing
// else — and is refreshed every 12 s while the overview is on screen. Reading needs integration.view; «مزامنة الآن»
// and the model actions need integration.manage (hidden without it; the server enforces both).
import { computed, watch } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner, Tabs } from '@/components';
import { t } from '@/i18n';
import OdooChanges from './OdooChanges.vue';
import OdooMaster from './OdooMaster.vue';
import OdooModelDrawer from './OdooModelDrawer.vue';
import OdooModels from './OdooModels.vue';
import OdooOverview from './OdooOverview.vue';
import OdooRecordDrawer from './OdooRecordDrawer.vue';
import OdooRecords from './OdooRecords.vue';
import OdooRuns from './OdooRuns.vue';
import OdooStock from './OdooStock.vue';
import { MODEL_STATUSES, useOdooNav } from './odoo';

const nav = useOdooNav();
const { view, modelOpen, recordOpen } = nav;

const status = useGet('/integration/odoo/status', null, { refetchInterval: computed(() => (view.value === 'overview' ? 12000 : false)) });
// coming back to the overview shows it current at once, not at the next tick of the timer
watch(view, (v) => { if (v === 'overview') status.refetch(); });
const st = computed(() => status.data.value || null);
/** Models registered so far, whatever their status: 0 until the first run has listed the models of Odoo. */
const registered = computed(() => (st.value?.models ? MODEL_STATUSES.reduce((n, k) => n + (st.value.models[k] || 0), 0) : 0));

const views = computed(() => [
  { k: 'overview', label: { ar: 'نظرة عامة', en: 'Overview' } },
  // what OPS makes of the copy: Odoo's own stock figures, and Odoo's products, customers and suppliers in OPS
  { k: 'stock', label: { ar: 'مخزون أودو', en: 'Odoo stock' } },
  { k: 'master', label: { ar: 'البيانات الأساسية', en: 'Master data' }, badge: st.value?.changes?.unprocessed || null },
  { k: 'models', label: { ar: 'النماذج', en: 'Models' }, badge: st.value?.models?.failing || null },
  { k: 'changes', label: { ar: 'التغييرات', en: 'Changes' } },
  { k: 'records', label: { ar: 'السجلات', en: 'Records' } },
  { k: 'runs', label: { ar: 'سجل المزامنة', en: 'Sync log' } },
]);
</script>

<template>
  <div class="col !gap-3">
    <ErrorBanner :error="status.error.value" :closable="false" class="!mb-0" />
    <div v-if="status.isLoading.value" class="skel h-[150px]" />

    <!-- the code is on this server but its tables are not yet (deployed before `php artisan migrate`): one line, nothing else -->
    <div v-else-if="st && st.installed === false" class="card p-[18px]">
      <div class="text-[13px] font-extrabold">{{ t('نسخة أودو لم تُجهَّز على هذا الخادم بعد', 'The Odoo mirror is not set up on this server yet') }}</div>
      <p class="m-0 mt-2 max-w-[760px] text-[11.5px] leading-[1.9] text-sec">
        {{ t('جداول النسخة لم تُنشأ في قاعدة البيانات. بعد تشغيل الترحيل على الخادم (php artisan migrate) وضبط اتصال أودو، تظهر هنا حالة الاتصال والنماذج والتغييرات. الخطوات في دليل التشغيل، القسم 8.', 'The mirror’s tables do not exist in the database. Once the migration has run on the server (php artisan migrate) and the Odoo connection is set, the connection, the models and the changes appear here. The steps are in the runbook, section 8.') }}
      </p>
    </div>

    <!-- the connection is set on the server, not here: say so and show nothing else -->
    <div v-else-if="st && !st.connection.configured" class="card p-[18px]">
      <div class="text-[13px] font-extrabold">{{ t('اتصال أودو غير مضبوط على هذا الخادم', 'The Odoo connection is not set up on this server') }}</div>
      <p class="m-0 mt-2 max-w-[760px] text-[11.5px] leading-[1.9] text-sec">
        {{ t('هذه الشاشة تعرض نسخة للقراءة فقط من بيانات أودو. الاتصال لا يُضبط من هنا، بل على الخادم نفسه عبر متغيرات البيئة (ODOO_URL و ODOO_DB و ODOO_LOGIN و ODOO_API_KEY، ثم ODOO_ENABLED لتشغيل المزامنة)، حتى لا يظهر مفتاح أودو في أي شاشة. بعد ضبطها تظهر هنا حالة الاتصال والنماذج والتغييرات.', 'This screen shows a read-only copy of the Odoo data. The connection is not set from here but on the server itself, through environment variables (ODOO_URL, ODOO_DB, ODOO_LOGIN and ODOO_API_KEY, then ODOO_ENABLED to switch the synchronisation on), so the Odoo key never appears on a screen. Once they are set, the connection, the models and the changes appear here.') }}
      </p>
    </div>

    <template v-else-if="st">
      <Tabs :model-value="view" :tabs="views" variant="sm" class="!mb-0" @update:model-value="(k) => nav.go(k)" />
      <OdooOverview v-if="view === 'overview'" :status="st" :registered="registered" />
      <OdooStock v-else-if="view === 'stock'" />
      <OdooMaster v-else-if="view === 'master'" />
      <OdooModels v-else-if="view === 'models'" :counts="st.models" />
      <OdooChanges v-else-if="view === 'changes'" />
      <OdooRecords v-else-if="view === 'records'" />
      <OdooRuns v-else-if="view === 'runs'" />

      <OdooModelDrawer :model="modelOpen" @close="nav.closeDrawer()" />
      <OdooRecordDrawer :model="recordOpen ? recordOpen.model : null" :odoo-id="recordOpen ? recordOpen.id : null" @close="nav.closeDrawer()" />
    </template>
  </div>
</template>
