<script setup>
// Integration Control Tower (docs/integration/ARCHITECTURE.md §16–§17): health of the connected systems, the event
// queues, exceptions, product / customer mapping, and the end-to-end trace of one order by its correlation id.
// GET /api/integration/{overview,inbox,deliveries,exceptions,trace/{key},events/{id},mappings/{entity}} — view needs
// integration.view; retry / replay / resolve / map / run need integration.manage (enforced by the server).
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, EmptyState, ErrorBanner, KpiCard, KpiGrid, PageHead, SelectInput, Tabs, TextInput } from '@/components';
import { fmtAgo, fmtDate, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import { useQueryState } from '../dashboard/shared';

const auth = useAuth();
const canManage = auth.can('integration.manage');
const canCreateProduct = canManage && auth.can('product.manage');
const qs = useQueryState();
const TABS = ['overview', 'trace', 'inbox', 'deliveries', 'exceptions', 'mappings'];
const tab = computed(() => (TABS.includes(qs.get('tab')) ? qs.get('tab') : 'overview'));
const setTab = (k) => qs.replace({ tab: k });
const tabs = computed(() => [
  { k: 'overview', label: { ar: 'نظرة عامة', en: 'Overview' } },
  { k: 'trace', label: { ar: 'تتبع رحلة', en: 'Trace' } },
  { k: 'inbox', label: { ar: 'الوارد', en: 'Inbox' } },
  { k: 'deliveries', label: { ar: 'الصادر', en: 'Deliveries' } },
  { k: 'exceptions', label: { ar: 'الاستثناءات', en: 'Exceptions' }, badge: ov.data.value?.exceptions ? ((ov.data.value.exceptions.crit || 0) + (ov.data.value.exceptions.warn || 0)) || null : null }, // info items (e.g. unmapped products) are listed, not counted
  { k: 'mappings', label: { ar: 'ربط الأصناف', en: 'Product mapping' } },
]);
const page = computed(() => Number(qs.get('page') || 1));
const setPage = (p) => qs.setParam('page', p > 1 ? String(p) : null);
const status = computed(() => qs.get('status') || undefined);
const q = computed(() => qs.get('q') || undefined);

const ov = useGet('/integration/overview', null, { refetchInterval: 15000 });
const inbox = useList('/integration/inbox', () => ({ status: status.value, q: q.value, page: page.value, pageSize: 30 }), { enabled: () => tab.value === 'inbox' });
const deliveries = useList('/integration/deliveries', () => ({ status: status.value, q: q.value, page: page.value, pageSize: 30 }), { enabled: () => tab.value === 'deliveries' });
const exceptions = useList('/integration/exceptions', () => ({ status: status.value ?? 'open', q: q.value, page: page.value, pageSize: 30 }), { enabled: () => tab.value === 'exceptions' });
const mappings = useList('/integration/mappings/product', () => ({ state: qs.get('state') || 'unmapped', q: q.value, page: page.value, pageSize: 30 }), { enabled: () => tab.value === 'mappings' });

const traceKey = ref(qs.get('key') || '');
const trace = useGet(() => (tab.value === 'trace' && qs.get('key') ? `/integration/trace/${encodeURIComponent(qs.get('key'))}` : null), null, { retry: false });
const runTrace = () => qs.replace({ tab: 'trace', key: traceKey.value.trim() || undefined });

const act = useAction({ invalidate: ['integration'] });
const payloadId = ref(null);
const payload = useGet(() => (payloadId.value ? `/integration/events/${payloadId.value}` : null));
const mapDraft = ref({});

const HEALTH = { ok: ['#1d7a3e', '#e7f6ec', { ar: 'سليم', en: 'Healthy' }], degraded: ['#b26a00', '#fff4e0', { ar: 'متعثّر', en: 'Degraded' }], down: ['#b42318', '#fdecea', { ar: 'متوقف (قاطع مفتوح)', en: 'Down (breaker open)' }], disabled: ['#6b6880', '#f1f0f5', { ar: 'غير مفعّل', en: 'Disabled' }] };
const ST_COLOR = { processed: '#1d7a3e', sent: '#1d7a3e', stale: '#6b6880', blocked: '#b26a00', pending: '#3C79F5', received: '#3C79F5', failed: '#b26a00', dead: '#b42318', rejected: '#b42318', open: '#b42318', resolved: '#1d7a3e', ignored: '#6b6880' };
const sum = (o) => Object.values(o || {}).reduce((a, b) => a + b, 0);

const inboxCols = [
  { key: 'type', header: { ar: 'الحدث', en: 'Event' }, width: 'minmax(170px,1.2fr)' },
  { key: 'subject', header: { ar: 'الكيان', en: 'Subject' }, width: '150px' },
  { key: 'status', header: { ar: 'النتيجة', en: 'Outcome' }, width: '150px' },
  { key: 'attempts', header: { ar: 'محاولات', en: 'Tries' }, width: '70px', align: 'center' },
  { key: 'receivedAt', header: { ar: 'الوقت', en: 'When' }, width: '140px' },
  { key: 'act', header: '', width: '150px' },
];
const delCols = [
  { key: 'event', header: { ar: 'الحدث', en: 'Event' }, width: 'minmax(170px,1.2fr)' },
  { key: 'subscriber', header: { ar: 'إلى', en: 'To' }, width: '90px' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '170px' },
  { key: 'attempts', header: { ar: 'محاولات', en: 'Tries' }, width: '70px', align: 'center' },
  { key: 'createdAt', header: { ar: 'الوقت', en: 'When' }, width: '140px' },
  { key: 'act', header: '', width: '150px' },
];
const excCols = [
  { key: 'code', header: { ar: 'النوع', en: 'Code' }, width: '170px' },
  { key: 'message', header: { ar: 'الوصف', en: 'Message' }, width: 'minmax(260px,2fr)' },
  { key: 'occurrences', header: '×', width: '50px', align: 'center' },
  { key: 'lastAt', header: { ar: 'آخر حدوث', en: 'Last' }, width: '130px' },
  { key: 'act', header: '', width: '170px' },
];
const mapCols = [
  { key: 'externalId', header: { ar: 'صنف المبيعات', en: 'Sales product' }, width: 'minmax(200px,1.4fr)' },
  { key: 'state', header: { ar: 'صنف العمليات', en: 'OPS SKU' }, width: 'minmax(220px,1.4fr)' },
  { key: 'act', header: '', width: '260px' },
];

const retry = (id) => act.run(() => api.post(`/integration/deliveries/${id}/retry`), { success: { ar: 'أُعيد الإرسال', en: 'Retried' } });
const replay = (id) => act.run(() => api.post(`/integration/inbox/${id}/replay`), { success: { ar: 'أُعيدت المعالجة', en: 'Replayed' } });
const resolve = async (row, st) => {
  const note = await ask({
    title: st === 'resolved' ? { ar: `إغلاق الاستثناء ${row.code}`, en: `Resolve ${row.code}` } : { ar: `تجاهل الاستثناء ${row.code}`, en: `Ignore ${row.code}` },
    sub: row.message, label: { ar: 'ملاحظة الإغلاق — ماذا فُعل؟', en: 'Closing note — what was done?' }, required: true,
    okLabel: st === 'resolved' ? { ar: 'حُلّ', en: 'Resolve' } : { ar: 'تجاهل', en: 'Ignore' },
  });
  if (note === null) return;
  act.run(() => api.post(`/integration/exceptions/${row.id}/resolve`, { status: st, note }), { success: { ar: 'أُغلق', en: 'Closed' } });
};
const runCycle = () => act.run(() => api.post('/integration/run'), { success: (r) => (r?.ran ? t(`اكتملت الدورة في ${r.ms}ms`, `Cycle done in ${r.ms}ms`) : t('دورة أخرى قيد التشغيل', 'Another cycle is running')) });
const reconcile = () => act.run(() => api.post('/integration/reconcile'), { success: { ar: 'اكتملت المطابقة', en: 'Reconciled' } });
const map = (row, sku) => sku && act.run(() => api.post('/integration/mappings/product', { externalId: row.externalId, internal: sku }), { success: (r) => (lang.value === 'ar' ? r.messageAr : r.messageEn) });
// "create in OPS": the other system sells a product OPS does not have — the form opens with an estimate read from the
// pack text; the steward corrects the numbers, then the product is created and linked in one step
const adoptRow = ref(null);
const adoptForm = ref({});
const STORAGE = computed(() => [['ambient', t('عادي', 'Ambient')], ['chilled', t('مبرد +4°', 'Chilled')], ['frozen', t('مجمد −18°', 'Frozen')]]);
const openAdopt = (row) => {
  const e = row.estimate || {};
  adoptForm.value = { sku: row.externalId, weightKg: e.weightKg ?? '', lengthCm: e.lengthCm ?? '', widthCm: e.widthCm ?? '', heightCm: e.heightCm ?? '', storageClass: e.storageClass || 'ambient', uomCode: e.uomCode || null, touched: false };
  adoptRow.value = row;
};
const adoptReady = computed(() => ['weightKg', 'lengthCm', 'widthCm', 'heightCm'].every((k) => Number(adoptForm.value[k]) > 0) && String(adoptForm.value.sku || '').trim().length >= 3);
const adopt = () => {
  const f = adoptForm.value;
  return act.run(() => api.post('/integration/mappings/product/adopt', {
    externalId: adoptRow.value.externalId, sku: String(f.sku).trim(), weightKg: Number(f.weightKg), lengthCm: Number(f.lengthCm), widthCm: Number(f.widthCm), heightCm: Number(f.heightCm),
    storageClass: f.storageClass, uomCode: f.uomCode || undefined, estimated: !f.touched,
  }), { success: (r) => { adoptRow.value = null; return lang.value === 'ar' ? r.messageAr : r.messageEn; } });
};
const unmap = (row) => act.run(() => api.del(`/integration/mappings/product/${encodeURIComponent(row.externalId)}`), { success: { ar: 'أُلغي الربط', en: 'Unlinked' } });
const filter = (k, v) => qs.replace({ tab: tab.value, [k]: v || undefined });
</script>

<template>
  <PageHead :sub="t('ربط منصة المبيعات بنظام العمليات: أحداث موقّعة بلا قاعدة مشتركة — كل حدث يُتتبع ويُعاد ولا يضيع', 'Sales ↔ OPS: signed events, no shared database — every event traceable, retryable, never lost')">
    <Btn v-if="canManage" tone="soft" size="sm" :label="{ ar: 'مطابقة الآن', en: 'Reconcile now' }" :loading="act.pending.value" @click="reconcile" />
    <Btn v-if="canManage" tone="dark" size="sm" :label="{ ar: 'تشغيل دورة', en: 'Run cycle' }" :loading="act.pending.value" @click="runCycle" />
  </PageHead>
  <ErrorBanner :error="act.error.value" />
  <Tabs :model-value="tab" :tabs="tabs" class="!mb-3" @update:model-value="setTab" />

  <!-- ───── overview ───── -->
  <div v-if="tab === 'overview'" class="col">
    <ErrorBanner :error="ov.error.value" :closable="false" />
    <KpiGrid>
      <KpiCard :loading="ov.isLoading.value" :value="sum(ov.data.value?.inbox?.last24h)" :label="{ ar: 'أحداث واردة (24 س)', en: 'Events in (24h)' }" :sub="ov.data.value ? t(`متوسط المعالجة ${ov.data.value.avgProcessingMs}ms`, `avg ${ov.data.value.avgProcessingMs}ms`) : null" />
      <KpiCard :loading="ov.isLoading.value" :value="sum(ov.data.value?.deliveries?.last24h)" :label="{ ar: 'أحداث صادرة (24 س)', en: 'Events out (24h)' }" :sub="ov.data.value ? t(`متوسط الإرسال ${ov.data.value.avgDeliveryMs}ms`, `avg ${ov.data.value.avgDeliveryMs}ms`) : null" />
      <KpiCard clickable :loading="ov.isLoading.value" :value="(ov.data.value?.deliveries?.open?.pending || 0) + (ov.data.value?.deliveries?.open?.failed || 0)" :label="{ ar: 'بانتظار الإرسال / إعادة', en: 'Pending / retrying' }" color="#3C79F5" @click="qs.replace({ tab: 'deliveries', status: 'failed' })" />
      <KpiCard clickable :loading="ov.isLoading.value" :value="(ov.data.value?.deliveries?.open?.dead || 0) + (ov.data.value?.inbox?.open?.dead || 0)" :label="{ ar: 'Dead Letter', en: 'Dead letter' }" color="#b42318" @click="qs.replace({ tab: 'deliveries', status: 'dead' })" />
      <KpiCard clickable :loading="ov.isLoading.value" :value="ov.data.value?.inbox?.open?.blocked || 0" :label="{ ar: 'بانتظار ربط', en: 'Waiting for mapping' }" color="#b26a00" @click="qs.replace({ tab: 'inbox', status: 'blocked' })" />
      <KpiCard :loading="ov.isLoading.value" :value="ov.data.value?.integratedOrders?.open" :label="{ ar: 'طلبات مبيعات قيد التنفيذ', en: 'Sales orders in progress' }" :sub="ov.data.value ? t(`${ov.data.value.integratedOrders.backordered} بانتظار مخزون`, `${ov.data.value.integratedOrders.backordered} backordered`) : null" />
    </KpiGrid>
    <div class="card">
      <div class="card-head"><b>{{ t('الأنظمة المربوطة', 'Connected systems') }}</b></div>
      <EmptyState v-if="ov.data.value && !ov.data.value.systems.length" :text="{ ar: 'لا أنظمة مضبوطة', en: 'No systems configured' }" />
      <div v-for="s in ov.data.value?.systems || []" :key="s.code" class="flex flex-wrap items-center gap-3 border-t border-line px-[18px] py-3">
        <div class="min-w-[160px] flex-1">
          <div class="font-bold">{{ s.name }} <span class="num text-[10px] text-faint">({{ s.code }})</span></div>
          <div class="cell-sub">{{ t('مفاتيح', 'Keys') }}: {{ s.keys }} · {{ s.scopes.join(' · ') || '—' }}</div>
        </div>
        <Chip small :label="HEALTH[s.health][2]" :fg="HEALTH[s.health][0]" :bg="HEALTH[s.health][1]" />
        <div class="text-[10.5px] text-sec">{{ t('آخر استلام', 'Last in') }}: <span class="num">{{ s.lastReceivedAt ? fmtAgo(s.lastReceivedAt, lang) : '—' }}</span></div>
        <div class="text-[10.5px] text-sec">{{ t('آخر إرسال', 'Last out') }}: <span class="num">{{ s.lastDeliveredAt ? fmtAgo(s.lastDeliveredAt, lang) : '—' }}</span></div>
        <div class="text-[10.5px]" :class="s.dead ? 'font-bold text-bad' : 'text-sec'">{{ t('معلّق', 'Pending') }} {{ s.pending }} · Dead {{ s.dead }}</div>
      </div>
      <div class="border-t border-line px-[18px] py-2.5 text-[10px] text-faint">
        {{ t('آخر دورة', 'Last cycle') }}: <span class="num">{{ ov.data.value?.lastRun?.at ? `${fmtDate(ov.data.value.lastRun.at)} · ${ov.data.value.lastRun.trigger}` : '—' }}</span>
        · {{ t('آخر مطابقة', 'Last reconciliation') }}: <span class="num">{{ ov.data.value?.lastReconciliation?.at ? fmtDate(ov.data.value.lastReconciliation.at) : '—' }}</span>
      </div>
    </div>
  </div>

  <!-- ───── trace ───── -->
  <div v-if="tab === 'trace'" class="col">
    <div class="card flex flex-wrap items-end gap-2.5 p-[14px]">
      <TextInput v-model="traceKey" field-class="flex-1 min-w-[220px]" mono :label="{ ar: 'رقم طلب المبيعات أو أمر البيع', en: 'Sales order id or OPS SO number' }" placeholder="ORD-2482 / SO-2026-00126" @keyup.enter="runTrace" />
      <Btn tone="dark" :label="{ ar: 'تتبع', en: 'Trace' }" @click="runTrace" />
    </div>
    <ErrorBanner :error="trace.error.value" :closable="false" />
    <template v-if="trace.data.value">
      <div class="card p-[16px]">
        <div class="flex flex-wrap items-center gap-2">
          <b class="num">{{ trace.data.value.correlationId }}</b>
          <span v-if="trace.data.value.order" class="text-sec">→ <RouterLink class="link num" :to="`/so/${trace.data.value.order.number}`">{{ trace.data.value.order.number }}</RouterLink> · {{ trace.data.value.order.customer }}</span>
          <Chip v-if="trace.data.value.order" small :label="trace.data.value.order.status" />
        </div>
        <div class="mt-2.5 flex flex-wrap gap-2">
          <component :is="d.link ? 'RouterLink' : 'span'" v-for="d in trace.data.value.documents" :key="d.type + d.number" :to="d.link" class="rounded-[10px] border border-line px-2.5 py-1.5 text-[10.5px]">
            <span class="text-faint">{{ d.type }}</span> <b class="num">{{ d.number }}</b> <span class="text-sec">· {{ d.status }}</span>
          </component>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><b>{{ t('الرحلة بالترتيب الزمني', 'Journey, in time order') }}</b></div>
        <div v-for="(r, i) in trace.data.value.timeline" :key="i" class="flex items-start gap-2.5 border-t border-line px-[18px] py-2 text-[11px]">
          <Chip small :label="r.kind === 'received' ? t('وارد', 'IN') : r.kind === 'published' ? t('صادر', 'OUT') : 'OPS'" :fg="r.kind === 'received' ? '#3C79F5' : r.kind === 'published' ? '#654e92' : '#55506a'" bg="#F7F6FA" />
          <div class="flex-1">
            <div class="font-bold">{{ r.title }} <span class="font-normal" :style="{ color: ST_COLOR[r.status] || '#55506a' }">· {{ r.status }}</span></div>
            <div v-if="r.detail" class="cell-sub num-mixed">{{ r.detail }}</div>
          </div>
          <button v-if="r.id && r.kind !== 'ops'" type="button" class="link text-[10px]" @click="payloadId = r.id">{{ t('الحمولة', 'Payload') }}</button>
          <div class="num w-[120px] text-end text-[9.5px] text-faint">{{ fmtDate(r.at) }}</div>
        </div>
      </div>
      <div v-if="trace.data.value.exceptions.integration.length || trace.data.value.exceptions.operational.length" class="card p-[16px]">
        <b>{{ t('الاستثناءات', 'Exceptions') }}</b>
        <div v-for="x in trace.data.value.exceptions.integration" :key="x.id" class="mt-1.5 text-[11px]"><Chip small :label="x.code" fg="#b42318" bg="#fdecea" /> {{ x.message }} <span class="text-faint">· {{ x.status }}</span></div>
        <div v-for="x in trace.data.value.exceptions.operational" :key="x.number" class="mt-1.5 text-[11px]"><Chip small :label="x.kind" fg="#b26a00" bg="#fff4e0" /> <RouterLink class="link num" :to="`/exc/${x.number}`">{{ x.number }}</RouterLink> {{ x.textAr }}</div>
      </div>
    </template>
    <EmptyState v-else-if="!trace.isLoading.value && !trace.error.value" tone="dashed" :text="{ ar: 'أدخل رقم طلب المبيعات (ORD-…) لرؤية رحلته كاملة عبر النظامين', en: 'Enter a Sales order id (ORD-…) to see its whole journey across both systems' }" />
  </div>

  <!-- ───── inbox ───── -->
  <div v-if="tab === 'inbox'" class="card">
    <div class="flex flex-wrap gap-1.5 p-3">
      <Btn v-for="s in ['', 'processed', 'blocked', 'failed', 'dead', 'rejected', 'stale']" :key="s" size="sm" :tone="(status || '') === s ? 'dark' : 'ghost'" :label="s || t('الكل', 'All')" @click="filter('status', s)" />
    </div>
    <DataTable :columns="inboxCols" :paged="inbox.data.value" :loading="inbox.isLoading.value" :row-key="(r) => r.id" :min-width="820" dense @page="setPage">
      <template #cell-type="{ row }"><b>{{ row.type }}</b><div class="cell-sub">{{ row.source }} · #{{ row.sequence ?? '—' }}</div></template>
      <template #cell-subject="{ row }"><button type="button" class="link num" @click="qs.replace({ tab: 'trace', key: row.correlationId || row.subject })">{{ row.subject }}</button></template>
      <template #cell-status="{ row }"><span class="font-bold" :style="{ color: ST_COLOR[row.status] }">{{ row.status }}</span><div v-if="row.lastCode" class="cell-sub" :title="row.lastError">{{ row.lastCode }}</div></template>
      <template #cell-receivedAt="{ row }"><span class="num">{{ fmtDate(row.receivedAt) }}</span></template>
      <template #cell-act="{ row }">
        <button type="button" class="link text-[10.5px]" @click="payloadId = row.id">{{ t('الحمولة', 'Payload') }}</button>
        <button v-if="canManage && ['failed', 'dead', 'blocked', 'rejected'].includes(row.status)" type="button" class="link ms-2.5 text-[10.5px]" @click="replay(row.id)">{{ t('إعادة', 'Replay') }}</button>
      </template>
    </DataTable>
  </div>

  <!-- ───── deliveries ───── -->
  <div v-if="tab === 'deliveries'" class="card">
    <div class="flex flex-wrap gap-1.5 p-3">
      <Btn v-for="s in ['', 'sent', 'pending', 'failed', 'dead']" :key="s" size="sm" :tone="(status || '') === s ? 'dark' : 'ghost'" :label="s || t('الكل', 'All')" @click="filter('status', s)" />
    </div>
    <DataTable :columns="delCols" :paged="deliveries.data.value" :loading="deliveries.isLoading.value" :row-key="(r) => r.id" :min-width="820" dense @page="setPage">
      <template #cell-event="{ row }"><b>{{ row.event?.type }}</b><div class="cell-sub"><button type="button" class="link num" @click="qs.replace({ tab: 'trace', key: row.event?.correlationId })">{{ row.event?.subject }}</button> · #{{ row.event?.sequence }}</div></template>
      <template #cell-status="{ row }"><span class="font-bold" :style="{ color: ST_COLOR[row.status] }">{{ row.status }}</span><div v-if="row.lastError" class="cell-sub truncate" :title="row.lastError">{{ row.lastError }}</div></template>
      <template #cell-createdAt="{ row }"><span class="num">{{ fmtDate(row.createdAt) }}</span><div v-if="row.responseMs" class="cell-sub num">{{ row.responseMs }}ms</div></template>
      <template #cell-act="{ row }">
        <button type="button" class="link text-[10.5px]" @click="payloadId = row.id">{{ t('الحمولة', 'Payload') }}</button>
        <button v-if="canManage && row.status !== 'pending'" type="button" class="link ms-2.5 text-[10.5px]" @click="retry(row.id)">{{ row.status === 'sent' ? t('إعادة بث', 'Replay') : t('إعادة', 'Retry') }}</button>
      </template>
    </DataTable>
  </div>

  <!-- ───── exceptions ───── -->
  <div v-if="tab === 'exceptions'" class="card">
    <div class="flex flex-wrap gap-1.5 p-3">
      <Btn v-for="s in ['open', 'resolved', 'ignored']" :key="s" size="sm" :tone="(status || 'open') === s ? 'dark' : 'ghost'" :label="s" @click="filter('status', s)" />
    </div>
    <DataTable :columns="excCols" :paged="exceptions.data.value" :loading="exceptions.isLoading.value" :row-key="(r) => r.id" :min-width="760" dense :empty-text="{ ar: 'لا استثناءات — كل شيء متطابق.', en: 'No exceptions.' }" @page="setPage">
      <template #cell-code="{ row }"><Chip small :label="row.code" :fg="row.severity === 'crit' ? '#b42318' : row.severity === 'info' ? '#3C79F5' : '#b26a00'" bg="#F7F6FA" /><div class="cell-sub">{{ row.system }} · {{ row.entityRef }}</div></template>
      <template #cell-message="{ row }"><span class="leading-[1.7] text-sec">{{ row.message }}</span><div v-if="row.resolution" class="cell-sub">✓ {{ row.resolution }} — {{ row.resolvedBy }}</div></template>
      <template #cell-lastAt="{ row }"><span class="num">{{ fmtAgo(row.lastAt, lang) }}</span></template>
      <template #cell-act="{ row }">
        <button v-if="row.correlationId" type="button" class="link text-[10.5px]" @click="qs.replace({ tab: 'trace', key: row.correlationId })">{{ t('تتبع', 'Trace') }}</button>
        <template v-if="canManage && row.status === 'open'">
          <button type="button" class="link ms-2.5 text-[10.5px]" @click="resolve(row, 'resolved')">{{ t('حُلّ', 'Resolve') }}</button>
          <button type="button" class="link ms-2.5 text-[10.5px]" @click="resolve(row, 'ignored')">{{ t('تجاهل', 'Ignore') }}</button>
        </template>
      </template>
    </DataTable>
  </div>

  <!-- ───── product mapping ───── -->
  <div v-if="tab === 'mappings'" class="card">
    <div class="flex flex-wrap gap-1.5 p-3">
      <Btn v-for="s in ['unmapped', 'mapped', 'all']" :key="s" size="sm" :tone="(qs.get('state') || 'unmapped') === s ? 'dark' : 'ghost'" :label="{ unmapped: { ar: 'غير مربوط', en: 'Unmapped' }, mapped: { ar: 'مربوط', en: 'Mapped' }, all: { ar: 'الكل', en: 'All' } }[s]" @click="filter('state', s)" />
      <div class="flex-1" />
      <div class="self-center text-[10px] text-faint">{{ t('الربط يدوي دائمًا — الاقتراحات بالتشابه للمساعدة فقط. صنف لا مقابل له هنا؟ «إنشاء في العمليات»', 'Links are always made by a person — suggestions are hints only. No counterpart here? “Create in OPS”') }}</div>
    </div>
    <DataTable :columns="mapCols" :paged="mappings.data.value" :loading="mappings.isLoading.value" :row-key="(r) => r.externalId" :min-width="760" dense @page="setPage">
      <template #cell-externalId="{ row }"><b>{{ row.label }}</b><div class="cell-sub num">{{ row.externalId }} · {{ row.data?.unit }}</div></template>
      <template #cell-state="{ row }">
        <template v-if="row.mapped"><RouterLink class="link num font-bold" :to="`/product/${row.internalCode}`">{{ row.internalCode }}</RouterLink><div class="cell-sub">{{ row.mappedBy }} · {{ fmtAgo(row.mappedAt, lang) }}</div></template>
        <div v-else class="flex flex-wrap gap-1">
          <button v-for="s in row.suggestions || []" :key="s.id" type="button" class="rounded-[8px] border border-line px-2 py-0.5 text-[10px]" :disabled="!canManage" @click="map(row, s.code)">{{ s.code }} · {{ s.name }} <span class="text-faint">{{ s.score }}%</span></button>
          <span v-if="!(row.suggestions || []).length" class="text-[10.5px] text-faint">{{ t('لا اقتراحات — أدخل SKU', 'No suggestions — enter a SKU') }}</span>
        </div>
      </template>
      <template #cell-act="{ row }">
        <div v-if="canManage" class="flex items-center gap-1.5">
          <template v-if="!row.mapped">
            <TextInput v-model="mapDraft[row.externalId]" small mono field-class="w-[140px]" placeholder="SKU" />
            <Btn size="sm" tone="dark" :label="{ ar: 'ربط', en: 'Map' }" @click="map(row, mapDraft[row.externalId])" />
            <Btn v-if="canCreateProduct" size="sm" tone="soft" :label="{ ar: 'إنشاء في العمليات', en: 'Create in OPS' }" @click="openAdopt(row)" />
          </template>
          <Btn v-else size="sm" tone="softRed" :label="{ ar: 'إلغاء الربط', en: 'Unlink' }" @click="unmap(row)" />
        </div>
      </template>
    </DataTable>
  </div>

  <Drawer :open="!!adoptRow" :title="{ ar: 'إنشاء الصنف في العمليات وربطه', en: 'Create the product in OPS and link it' }" :width="460" @close="adoptRow = null">
    <template v-if="adoptRow">
      <div class="text-[13px] font-extrabold">{{ adoptRow.label }}</div>
      <div class="num mt-0.5 text-[10.5px] text-faint">{{ adoptRow.externalId }} · {{ adoptRow.data?.unit || '—' }}</div>
      <div class="mt-3 rounded-[12px] border border-dashed border-line bg-[#FBFAFD] p-3 text-[10.5px] leading-[1.8] text-muted">
        {{ t('الاسم والعبوة من منصة المبيعات. الوزن والأبعاد أدناه تقدير من وصف العبوة — صحّحها بالقياس الفعلي إن عرفته؛ يمكن تعديلها لاحقًا من شاشة المنتج. الصنف يُنشأ بلا مخزون.', 'Name and pack come from Sales. Weight and dimensions below are an estimate from the pack text — correct them if you know the measured values; they can be edited later on the product screen. The product starts with no stock.') }}
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2.5">
        <TextInput v-model="adoptForm.sku" full mono :label="{ ar: 'رمز الصنف SKU', en: 'SKU' }" required />
        <SelectInput v-model="adoptForm.storageClass" full :options="STORAGE" :label="{ ar: 'فئة التخزين', en: 'Storage class' }" />
        <TextInput v-model="adoptForm.weightKg" full type="number" :label="{ ar: 'الوزن (كجم)', en: 'Weight (kg)' }" required @update:model-value="adoptForm.touched = true" />
        <TextInput v-model="adoptForm.lengthCm" full type="number" :label="{ ar: 'الطول (سم)', en: 'Length (cm)' }" required @update:model-value="adoptForm.touched = true" />
        <TextInput v-model="adoptForm.widthCm" full type="number" :label="{ ar: 'العرض (سم)', en: 'Width (cm)' }" required @update:model-value="adoptForm.touched = true" />
        <TextInput v-model="adoptForm.heightCm" full type="number" :label="{ ar: 'الارتفاع (سم)', en: 'Height (cm)' }" required @update:model-value="adoptForm.touched = true" />
      </div>
      <div class="mt-4 flex gap-2">
        <Btn tone="dark" :disabled="!adoptReady" :loading="act.pending.value" :label="{ ar: 'إنشاء وربط', en: 'Create and link' }" @click="adopt" />
        <Btn tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="adoptRow = null" />
      </div>
    </template>
  </Drawer>

  <Drawer :open="!!payloadId" :title="{ ar: 'حمولة الحدث', en: 'Event payload' }" :width="560" @close="payloadId = null">
    <ErrorBanner :error="payload.error.value" :closable="false" />
    <pre v-if="payload.data.value" class="num ltr whitespace-pre-wrap break-words rounded-[12px] bg-[#F7F6FA] p-3 text-[10.5px] leading-[1.6]">{{ JSON.stringify(payload.data.value, null, 2) }}</pre>
  </Drawer>
</template>
