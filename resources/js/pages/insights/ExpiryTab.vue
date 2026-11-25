<script setup>
// «الصلاحية» — expiry alert levels (not for brand owners). view=levels|upcoming|log in the query string.
//   levels    GET /insights/expiry/levels → { items }; expiryalert.manage: POST / PATCH levels (LevelForm), activate /
//             deactivate (PATCH { active }), POST expiry/run → { levels, announced, byLevel }.
//   upcoming  GET /insights/expiry/upcoming?q&page → lots with stock the active levels will announce or have announced.
//   log       GET /insights/expiry/log?level&sku&source&from&to&q&sort&order&page → what was announced, to whom, how.
// No e-mail server is configured: an e-mail channel is recorded as "not sent" (emailStatus `skipped`), never as sent.
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, fmtNum, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import LevelForm from './LevelForm.vue';
import { CHANNEL, EMAIL_STATUS, ODOO_CHIP, OWNED_HELP, fmtDay, levelColor, nameOf, useInsightsNav, useSearchParam, userName } from './insights';

const props = defineProps({
  me: { type: Object, required: true },
});

const nav = useInsightsNav();
const { page } = nav;
const canManage = computed(() => !!props.me.expiryManager);
const VIEWS = ['levels', 'upcoming', 'log'];
const view = computed(() => (VIEWS.includes(nav.get('view')) ? nav.get('view') : 'levels'));
const views = [
  { k: 'levels', label: { ar: 'مستويات التنبيه', en: 'Alert levels' } },
  { k: 'upcoming', label: { ar: 'الدفعات القادمة', en: 'Lots coming up' } },
  { k: 'log', label: { ar: 'سجل التنبيهات', en: 'Alert log' } },
];
const setView = (k) => nav.go('expiry', k === 'levels' ? {} : { view: k });
const { text, type, apply } = useSearchParam(nav);

// ---- levels
const levels = useGet('/insights/expiry/levels');
const levelItems = computed(() => levels.data.value?.items || []);
const levelColumns = computed(() => [
  { key: 'name', header: { ar: 'المستوى', en: 'Level' }, width: 'minmax(170px,1.3fr)' },
  { key: 'daysBeforeExpiry', header: { ar: 'قبل الانتهاء', en: 'Before expiry' }, width: '96px', kind: 'num' },
  { key: 'notificationType', header: { ar: 'القناة', en: 'Channel' }, width: '120px', bare: true },
  { key: 'recipients', header: { ar: 'المستلمون', en: 'Recipients' }, width: 'minmax(160px,1.2fr)' },
  { key: 'notifications', header: { ar: 'تنبيهات أُرسلت', en: 'Alerts sent' }, width: '90px', kind: 'num' },
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '64px', kind: 'num' },
  { key: 'active', header: { ar: 'الحالة', en: 'State' }, width: '110px', bare: true },
  { key: 'actions', header: '', width: '170px', bare: true, hidden: !canManage.value },
]);
const act = useAction();
const lastRun = ref(null);
async function toggle(l) {
  if (!(await confirm({ title: l.active ? { ar: `إيقاف المستوى «${l.name}»؟`, en: `Deactivate "${l.name}"?` } : { ar: `تفعيل المستوى «${l.name}»؟`, en: `Activate "${l.name}"?` }, tone: 'dark' }))) return;
  await act.run(() => api.patch(`/insights/expiry/levels/${encodeURIComponent(l.id)}`, { active: !l.active }), { success: t('حُفظ المستوى', 'Level saved'), invalidate: ['insights'] });
}
async function runNow() {
  const r = await act.run(() => api.postIdempotent('/insights/expiry/run'), { success: (x) => t(`أُعلن ${x.announced} دفعة`, `${x.announced} lot(s) announced`), invalidate: ['insights'] });
  if (r) lastRun.value = r;
}
const creating = ref(false);
const editing = ref(null);

// ---- upcoming
const upcoming = useList(() => (view.value === 'upcoming' ? '/insights/expiry/upcoming' : null), () => ({ q: nav.get('q') || undefined, page: page.value, pageSize: 50 }));
const upLevels = computed(() => upcoming.data.value?.levels || []);
const upColumns = computed(() => [
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(190px,1.5fr)' },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Lot' }, width: '110px', kind: 'id' },
  { key: 'expiry', header: { ar: 'الانتهاء', en: 'Expiry' }, width: '88px', kind: 'date', value: (r) => fmtDay(r.expiry) },
  { key: 'daysLeft', header: { ar: 'أيام متبقية', en: 'Days left' }, width: '76px', kind: 'num' },
  { key: 'qty', header: { ar: 'الرصيد', en: 'On hand' }, width: '72px', kind: 'num' },
  { key: 'levels', header: { ar: 'المستويات', en: 'Levels' }, width: 'minmax(200px,1.4fr)', bare: true },
]);

// ---- log
const LOG_SORTS = ['notifiedAt', 'expiry'];
const logSort = nav.sortOf(LOG_SORTS, 'desc');
const log = useList(() => (view.value === 'log' ? '/insights/expiry/log' : null), () => ({
  q: nav.get('q') || undefined, level: nav.get('level') || undefined, sku: nav.get('sku') || undefined, source: nav.get('source') || undefined,
  from: nav.get('from') || undefined, to: nav.get('to') || undefined, sort: logSort.value?.key, order: logSort.value?.order, page: page.value, pageSize: 50,
}));
const levelOptions = computed(() => levelItems.value.map((l) => ({ v: String(l.id), l: `${l.name} (${l.daysBeforeExpiry})` })));
const SOURCES = [{ v: 'ops', l: { ar: 'العمليات', en: 'OPS' } }, { v: 'odoo', l: { ar: 'من أودو', en: 'From Odoo' } }];
const logColumns = [
  { key: 'notifiedAt', header: { ar: 'أُعلن', en: 'Announced' }, width: '120px', kind: 'date', sortable: true, value: (r) => fmtDate(r.notifiedAt) },
  { key: 'level', header: { ar: 'المستوى', en: 'Level' }, width: '130px', bare: true },
  { key: 'product', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(170px,1.4fr)' },
  { key: 'batchNo', header: { ar: 'الدفعة', en: 'Lot' }, width: '100px', kind: 'id' },
  { key: 'expiry', header: { ar: 'الانتهاء', en: 'Expiry' }, width: '88px', kind: 'date', sortable: true, value: (r) => fmtDay(r.expiry) },
  { key: 'daysLeft', header: { ar: 'أيام متبقية', en: 'Days left' }, width: '72px', kind: 'num' },
  { key: 'qtyOnHand', header: { ar: 'الرصيد', en: 'On hand' }, width: '68px', kind: 'num' },
  { key: 'channel', header: { ar: 'القناة / البريد', en: 'Channel / e-mail' }, width: '150px', bare: true },
  { key: 'recipients', header: { ar: 'المستلمون', en: 'Recipients' }, width: 'minmax(120px,1fr)' },
];
const logFilters = computed(() => ['q', 'level', 'sku', 'source', 'from', 'to'].some((k) => !!nav.get(k)));
const clearLog = () => nav.patch({ q: null, level: null, sku: null, source: null, from: null, to: null, page: null });
const skuText = useSearchParam(nav, 'sku');
const chip = (i) => levelColor(i);
</script>

<template>
  <Tabs :model-value="view" :tabs="views" variant="sm" @update:model-value="setView" />

  <!-- levels -->
  <template v-if="view === 'levels'">
    <div class="row wrap mb-2">
      <div class="text-[10.5px] text-muted">{{ t('كل مستوى يعلن مرة واحدة عن كل دفعة لها رصيد تنتهي خلال أيامه، للمستلمين المحددين. يعمل يوميًا تلقائيًا.', 'Each level announces once every lot with stock that expires within its days, to its recipients. It runs daily on its own.') }}</div>
      <div class="grow" />
      <template v-if="canManage">
        <Btn size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'شغّل الآن', en: 'Run now' }" @click="runNow" />
        <Btn size="sm" tone="primary" :label="{ ar: '+ مستوى', en: '+ Level' }" @click="creating = true" />
      </template>
    </div>
    <ErrorBanner :error="levels.error.value" :closable="false" />
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="lastRun" class="hint green !mt-0 mb-2.5">
      {{ t('آخر تشغيل', 'Last run') }}: {{ t('مستويات نشطة', 'active levels') }} <b class="num">{{ fmtNum(lastRun.levels) }}</b> · {{ t('دفعات أُعلنت', 'lots announced') }} <b class="num">{{ fmtNum(lastRun.announced) }}</b>
      <template v-for="(n, name) in lastRun.byLevel" :key="name"> · <span dir="auto">{{ name }}</span> <b class="num">{{ fmtNum(n) }}</b></template>
    </div>
    <div class="card">
      <DataTable :columns="levelColumns" :rows="levelItems" :loading="levels.isLoading.value" :row-key="(r) => r.id"
                 :empty-text="canManage ? { ar: 'لا مستويات بعد — أضف أول مستوى من «+ مستوى».', en: 'No level yet — add the first one with “+ Level”.' } : { ar: 'لا مستويات تنبيه بعد.', en: 'No alert level yet.' }">
        <template #cell-name="{ row }">
          <div class="min-w-0">
            <div class="truncate"><span class="chip sm" :style="{ color: chip(row.color).fg, background: chip(row.color).bg }" dir="auto">{{ row.name }}</span></div>
            <div v-if="row.ownedBySource" class="cell-sub">{{ t(OWNED_HELP.ar, OWNED_HELP.en) }}</div>
          </div>
        </template>
        <template #cell-daysBeforeExpiry="{ row }">{{ t(`${row.daysBeforeExpiry} يوم`, `${row.daysBeforeExpiry} days`) }}</template>
        <template #cell-notificationType="{ row }">
          <span class="inline-flex flex-col items-center gap-0.5">
            <Chip small :map="CHANNEL" :k="row.notificationType" />
            <span v-if="row.notificationType !== 'inapp'" class="text-[8.5px] font-bold text-warn">{{ t('البريد غير مُعدّ — لا يُرسل', 'E-mail not configured — not sent') }}</span>
          </span>
        </template>
        <template #cell-recipients="{ row }">
          <span class="truncate text-[10px] text-sec" dir="auto" :title="row.recipients.map((u) => u.username).join(', ')">
            {{ row.recipients.map((u) => userName(u)).join('، ') || '—' }}
            <template v-if="row.recipientsKept?.length"> · {{ t(`${row.recipientsKept.length} من أودو بلا مستخدم`, `${row.recipientsKept.length} from Odoo without a user`) }}</template>
          </span>
        </template>
        <template #cell-notifications="{ row }">{{ fmtNum(row.notifications) }}</template>
        <template #cell-active="{ row }">
          <span class="inline-flex flex-wrap items-center justify-center gap-1">
            <span class="chip sm" :class="row.active ? 'text-ok' : 'text-faint'" :style="{ background: row.active ? '#e6f9ec' : '#F1EFF6' }">{{ row.active ? t('نشط', 'Active') : t('موقوف', 'Inactive') }}</span>
            <Chip v-if="row.ownedBySource" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" />
          </span>
        </template>
        <template #cell-actions="{ row }">
          <span v-if="!row.ownedBySource" class="inline-flex gap-1">
            <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = row" />
            <Btn size="sm" :tone="row.active ? 'softRed' : 'softGreen'" :loading="act.pending.value" :label="row.active ? { ar: 'إيقاف', en: 'Deactivate' } : { ar: 'تفعيل', en: 'Activate' }" @click="toggle(row)" />
          </span>
          <span v-else class="text-[9.5px] text-violet">{{ t('يُعدَّل في أودو', 'Changed in Odoo') }}</span>
        </template>
      </DataTable>
    </div>
    <LevelForm :open="creating || !!editing" :level="editing" @close="creating = false; editing = null" />
  </template>

  <!-- upcoming -->
  <template v-else-if="view === 'upcoming'">
    <div class="row wrap mb-2">
      <TextInput scan small type="search" class="w-[250px]" :model-value="text" :placeholder="{ ar: 'بحث بالـ SKU أو رقم الدفعة…', en: 'Search SKU or lot number…' }" @update:model-value="type" @enter="apply" />
      <div class="grow" />
      <span class="text-[10px] text-muted">{{ t('الدفعات ذات الرصيد التي تنتهي خلال أطول مستوى نشط. ✓ = أُعلنت، ● = حان موعدها ولم تُعلن بعد.', 'Lots with stock expiring within the longest active level. ✓ = announced, ● = due, not announced yet.') }}</span>
    </div>
    <ErrorBanner :error="upcoming.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="upColumns" :paged="upcoming.data.value" :loading="upcoming.isFetching.value" :row-key="(r) => r.batchId" @page="nav.setPage"
                 :empty-text="nav.get('q') ? { ar: 'لا دفعة تطابق البحث.', en: 'No lot matches the search.' } : upLevels.length ? { ar: 'لا دفعات تنتهي خلال المستويات النشطة.', en: 'No lot expires within the active levels.' } : { ar: 'لا مستويات نشطة — لا شيء يُعلن.', en: 'No active level — nothing is announced.' }">
        <template #cell-product="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
            <div class="cell-sub"><span class="num ltr inline-block">{{ row.sku }}</span></div>
          </div>
        </template>
        <template #cell-daysLeft="{ row }"><span class="font-bold" :class="row.daysLeft <= 7 ? 'text-bad' : row.daysLeft <= 30 ? 'text-warn' : 'text-sec'">{{ fmtNum(row.daysLeft) }}</span></template>
        <template #cell-qty="{ row }">{{ fmtNum(row.qty) }}</template>
        <template #cell-levels="{ row }">
          <span class="inline-flex flex-wrap justify-center gap-1">
            <span v-for="l in row.levels" :key="l.id" class="chip sm" :class="l.notifiedAt ? 'text-ok' : l.due ? 'text-warn' : 'text-faint'" :style="{ background: l.notifiedAt ? '#e6f9ec' : l.due ? '#fbf0dd' : '#F1EFF6' }"
                  :title="l.notifiedAt ? `${t('أُعلن', 'Announced')} ${fmtDate(l.notifiedAt)}` : l.due ? t('حان موعده ولم يُعلن بعد', 'Due, not announced yet') : t('لم يحن بعد', 'Not due yet')">
              {{ l.notifiedAt ? '✓' : l.due ? '●' : '○' }} <span dir="auto">{{ l.name }}</span>
            </span>
          </span>
        </template>
      </DataTable>
    </div>
  </template>

  <!-- log -->
  <template v-else>
    <div class="row wrap mb-2">
      <TextInput small type="search" class="w-[220px]" :model-value="text" :placeholder="{ ar: 'بحث بالمنتج أو الدفعة…', en: 'Search product or lot…' }" @update:model-value="type" @enter="apply" />
      <TextInput scan small mono class="w-[150px]" :model-value="skuText.text.value" :placeholder="{ ar: 'SKU مطابق', en: 'Exact SKU' }" @update:model-value="skuText.type" @enter="skuText.apply" />
      <SelectInput small class="w-[170px]" :model-value="nav.get('level')" :options="levelOptions" :placeholder="{ ar: 'كل المستويات', en: 'Every level' }" @update:model-value="(v) => nav.setFilter('level', v)" />
      <SelectInput small class="w-[130px]" :model-value="nav.get('source')" :options="SOURCES" :placeholder="{ ar: 'أي مصدر', en: 'Any source' }" @update:model-value="(v) => nav.setFilter('source', v)" />
      <DateInput small class="w-[140px]" :title="t('أُعلن من', 'Announced from')" :model-value="nav.get('from')" @update:model-value="(v) => nav.setFilter('from', v)" />
      <DateInput small class="w-[140px]" :title="t('أُعلن حتى', 'Announced to')" :model-value="nav.get('to')" @update:model-value="(v) => nav.setFilter('to', v)" />
      <Btn v-if="logFilters" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clearLog" />
    </div>
    <ErrorBanner :error="log.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="logColumns" :paged="log.data.value" :loading="log.isFetching.value" :row-key="(r) => r.id" :sort="logSort" server-sort @sort="nav.setSort" @page="nav.setPage"
                 :empty-text="logFilters ? { ar: 'لا شيء يطابق الفلاتر.', en: 'Nothing matches the filters.' } : { ar: 'لم يُعلن شيء بعد.', en: 'Nothing was announced yet.' }">
        <template #cell-level="{ row }">
          <span v-if="row.level" class="chip sm" :style="{ color: chip(row.level.color).fg, background: chip(row.level.color).bg }" dir="auto">{{ row.level.name }}</span>
          <span v-else class="text-faint">—</span>
        </template>
        <template #cell-product="{ row }">
          <div class="min-w-0">
            <div class="cell-name truncate" dir="auto">{{ row.product ? nameOf(row.product) : '—' }}</div>
            <div class="cell-sub"><span v-if="row.product" class="num ltr inline-block">{{ row.product.sku }}</span><template v-if="row.sourceSystem === 'odoo'"> · {{ t('من أودو', 'From Odoo') }}</template></div>
          </div>
        </template>
        <template #cell-daysLeft="{ row }">{{ row.daysLeft == null ? '—' : fmtNum(row.daysLeft) }}</template>
        <template #cell-qtyOnHand="{ row }">{{ row.qtyOnHand == null ? '—' : fmtNum(row.qtyOnHand) }}</template>
        <template #cell-channel="{ row }">
          <span class="inline-flex flex-col items-center gap-0.5">
            <Chip small :map="CHANNEL" :k="row.channel" />
            <Chip v-if="row.emailStatus && row.emailStatus !== 'none'" small :map="EMAIL_STATUS" :k="row.emailStatus" />
          </span>
        </template>
        <template #cell-recipients="{ row }"><span class="truncate text-[10px] text-sec" dir="auto">{{ row.recipients.map((u) => u.nameAr || u.username).join('، ') || '—' }}</span></template>
      </DataTable>
    </div>
  </template>
</template>
