<script setup>
// Odoo tab → overview: is the connection alive, how far is the copy, what changed, what did the last run do.
// Everything shown is the answer of GET /integration/odoo/status, which the tab refreshes every 12 s.
// «فحص الاتصال» asks the server to test Odoo now (GET status?fresh=1 — otherwise the test is remembered for a minute).
// «مزامنة الآن» (integration.manage) starts one short run — POST /integration/odoo/sync — and shows what it found;
// a refusal (422 ODOO_DISABLED, 409 ODOO_BUSY) is shown as the server's own message.
import { computed } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner, Icon, KV, KpiCard, KpiGrid } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import OdooSyncResult from './OdooSyncResult.vue';
import { CHANGE_KIND, CHANGE_KINDS, CONNECTION_HEALTH, RUN_TRIGGER, TONE, agoAt, connectionError, fmtAt, lagParts, runFailedModels, runTiles, useOdooNav, useOdooSync } from './odoo';

const props = defineProps({
  /** Answer of GET /integration/odoo/status: { connection, models, firstCopy, changes, freshness, lastRun, policy }. */
  status: { type: Object, required: true },
  /** Models registered so far, whatever their status (0 = the first run has not happened yet). */
  registered: { type: Number, default: 0 },
});

const auth = useAuth();
const canManage = auth.can('integration.manage');
const nav = useOdooNav();

const sync = useOdooSync();
const found = sync.result;
const check = useAction({ invalidate: ['integration/odoo/status'] });
const checkNow = () => check.run(() => api.get('/integration/odoo/status', { fresh: 1 }), { success: (r) => (r?.connection?.ok ? t('أودو يستجيب ✓', 'Odoo is answering ✓') : t('أودو لا يستجيب', 'Odoo is not answering')) });
function clearErrors() { sync.clearError(); check.clearError(); }

const c = computed(() => props.status.connection);
const m = computed(() => props.status.models);
const fc = computed(() => props.status.firstCopy);
const ch = computed(() => props.status.changes);
const policy = computed(() => props.status.policy || {});
const run = computed(() => props.status.lastRun || null);
/** A run started and has not written its end yet (`status.sync.running`). */
const running = computed(() => !!props.status.sync?.running && !sync.pending.value);

const health = computed(() => (!c.value.ok ? 'down' : c.value.enabled ? 'ok' : 'off'));
const odooUser = computed(() => c.value.user?.name || c.value.login || null);
const readOnlyProof = computed(() => { const list = (policy.value.readOnlyMethods || []).join(' · '); return list ? t(`النداءات الوحيدة التي تُرسل إلى أودو: ${list}`, `The only calls ever sent to Odoo: ${list}`) : null; });
/** Records copied against the records Odoo holds in the mirrored models; 100 only once every model is done. */
const copyPct = computed(() => (fc.value.complete ? 100 : fc.value.expected > 0 ? Math.min(99, Math.floor((fc.value.records / fc.value.expected) * 100)) : 0));
/** `byKind` is a map of the kinds that occurred (an empty list when none did). */
const byKind = (k) => Number(ch.value.byKind?.[k]) || 0;
const lag = computed(() => lagParts(props.status.freshness?.coreLagSeconds));
</script>

<template>
  <div class="col !gap-3.5">
    <ErrorBanner :error="sync.error.value || check.error.value" class="!mb-0" @close="clearErrors" />

    <!-- ───── connection ───── -->
    <div class="card">
      <div class="card-head flex-wrap">
        <div class="card-title">{{ t('اتصال أودو', 'Odoo connection') }}</div>
        <Chip :map="CONNECTION_HEALTH" :k="health" dot />
        <Btn tone="soft" size="sm" :loading="check.pending.value" :label="{ ar: 'فحص الاتصال', en: 'Check connection' }" @click="checkNow" />
        <!-- one run at a time: while the periodic one works, starting another would only be refused (409 ODOO_BUSY) -->
        <Chip v-if="running" small :label="{ ar: 'مزامنة تعمل الآن', en: 'A run is in progress' }" :fg="TONE.blue.fg" :bg="TONE.blue.bg" dot />
        <Btn v-if="canManage" tone="dark" size="sm" :disabled="running" :loading="sync.pending.value" :label="{ ar: 'مزامنة الآن', en: 'Sync now' }" @click="sync.run()" />
      </div>
      <div class="card-body">
        <div class="kv-grid">
          <KV :k="{ ar: 'خادم أودو', en: 'Odoo server' }" :v="c.host" ltr />
          <KV :k="{ ar: 'قاعدة البيانات', en: 'Database' }" :v="c.db" ltr />
          <KV :k="{ ar: 'إصدار أودو', en: 'Odoo version' }" :v="c.version" ltr />
          <KV :k="{ ar: 'مستخدم أودو', en: 'Odoo user' }">
            <span dir="auto">{{ odooUser || '—' }}</span>
            <div v-if="c.user?.name && c.login" class="num ltr text-[9.5px] font-normal text-faint">{{ c.login }}</div>
          </KV>
          <KV v-if="c.user?.company" :k="{ ar: 'الشركة في أودو', en: 'Company in Odoo' }"><span dir="auto">{{ c.user.company }}</span></KV>
          <KV :k="{ ar: 'آخر فحص للاتصال', en: 'Connection checked' }"><span class="num-mixed" :title="fmtAt(c.checkedAt)">{{ agoAt(c.checkedAt) }}</span></KV>
        </div>
        <div v-if="!c.ok" class="mt-3 rounded-[10px] bg-bad-soft px-3 py-2 text-[10.5px] font-bold leading-[1.8] text-bad">
          {{ connectionError(c.errorKind) }}
          <div v-if="c.error" class="ltr num-mixed break-words font-normal">{{ c.error }}</div>
        </div>
        <div v-else-if="!c.enabled" class="hint amber">{{ t('المزامنة التلقائية متوقفة على هذا الخادم (ODOO_ENABLED). النسخة تبقى على حالها ولا تتحدّث حتى تُفعَّل من إعدادات الخادم.', 'Automatic synchronisation is switched off on this server (ODOO_ENABLED). The copy stays as it is and is not updated until it is switched on in the server settings.') }}</div>
        <div class="hint teal flex items-start gap-2" :title="readOnlyProof">
          <Icon name="lock" :size="14" class="mt-[3px]" />
          <div><b>{{ t('للقراءة فقط.', 'Read-only.') }}</b> {{ t('نظام العمليات يقرأ من أودو ولا يكتب فيه أبدًا: لا يُنشئ ولا يعدّل ولا يحذف أي شيء هناك. ما تراه هنا نسخة محفوظة داخل نظام العمليات.', 'OPS reads from Odoo and never writes to it: nothing there is created, changed or deleted from here. What you see is a copy kept inside OPS.') }}</div>
        </div>
      </div>
    </div>

    <OdooSyncResult :result="found" class="!mb-0" @close="sync.clear()" />

    <div v-if="!registered" class="hint !mt-0">{{ t('لم يُسجَّل أي نموذج بعد. أول مزامنة تتعرّف على نماذج أودو وتسجّلها، ثم تبدأ النسخة الأولى.', 'No model is registered yet. The first run lists the models of Odoo and registers them, then the first copy starts.') }}</div>

    <!-- ───── figures ───── -->
    <KpiGrid class="!mb-0">
      <KpiCard clickable :value="m.active" :label="{ ar: 'النماذج المنسوخة', en: 'Models mirrored' }" @click="nav.go('models', { status: 'active' })">
        <template #sub>
          {{ t('متخطّاة', 'skipped') }} <b class="num">{{ fmtNum(m.skipped) }}</b> ·
          <span :class="{ 'font-bold text-bad': m.failing > 0 }">{{ t('تتعثّر', 'failing') }} <b class="num">{{ fmtNum(m.failing) }}</b></span>
          <template v-if="m.new"> · {{ t('بانتظار الفحص', 'to inspect') }} <b class="num">{{ fmtNum(m.new) }}</b></template>
          <template v-if="m.paused"> · {{ t('موقوفة', 'paused') }} <b class="num">{{ fmtNum(m.paused) }}</b></template>
          <template v-if="m.error"> · <span class="font-bold text-bad">{{ t('متعطّلة', 'in error') }} <b class="num">{{ fmtNum(m.error) }}</b></span></template>
        </template>
      </KpiCard>
      <KpiCard clickable :value="fc.records" :label="{ ar: 'السجلات في النسخة', en: 'Records in the copy' }" @click="nav.go('records')">
        <template #sub>{{ t('في أودو', 'in Odoo') }} <b class="num">{{ fmtNum(fc.expected) }}</b></template>
      </KpiCard>
      <KpiCard clickable :value="`${copyPct}%`" :color="fc.complete ? TONE.ok.fg : TONE.blue.fg" :label="{ ar: 'تقدّم النسخة الأولى', en: 'First-copy progress' }" @click="nav.go('models', fc.complete ? {} : { state: 'copying' })">
        <template #sub>
          <template v-if="fc.complete">{{ t('اكتملت لكل النماذج', 'complete for every model') }} ✓</template>
          <template v-else>
            <div class="progress mb-1 mt-1 !h-1.5"><div :style="{ width: `${copyPct}%` }" /></div>
            {{ t('سجلات', 'records') }} <b class="num ltr">{{ fmtNum(fc.records) }} / {{ fmtNum(fc.expected) }}</b> · {{ t('نماذج', 'models') }} <b class="num ltr">{{ fmtNum(fc.done) }} / {{ fmtNum(fc.total) }}</b>
          </template>
        </template>
      </KpiCard>
      <KpiCard clickable :value="ch.last24h" :label="{ ar: 'تغييرات آخر 24 ساعة', en: 'Changes in the last 24 h' }" @click="nav.go('changes')">
        <template #sub>
          <span class="flex flex-wrap gap-x-2 gap-y-0.5">
            <span v-for="k in CHANGE_KINDS" :key="k" :style="{ color: CHANGE_KIND[k].fg }">{{ bi(CHANGE_KIND[k]) }} <b class="num">{{ fmtNum(byKind(k)) }}</b></span>
          </span>
        </template>
      </KpiCard>
      <KpiCard :value="lag.n" :unit="lag.unit" :color="lag.color" :label="{ ar: 'حداثة النماذج الأساسية', en: 'Core models freshness' }">
        <template #sub>{{ lag.n == null ? t('لم تكتمل النسخة الأولى لأي نموذج أساسي بعد', 'no core model has finished its first copy yet') : t('الوقت المنقضي منذ آخر فحص لها', 'time since they were last checked') }}</template>
      </KpiCard>
    </KpiGrid>

    <!-- ───── last run ───── -->
    <div class="card">
      <div class="card-head flex-wrap">
        <div class="card-title">
          {{ t('آخر مزامنة', 'Last run') }}
          <span v-if="run" class="num-mixed ms-1.5 text-[10px] font-normal text-muted" :title="fmtAt(run.startedAt)">{{ agoAt(run.startedAt) }}</span>
        </div>
        <Chip v-if="run" small :map="RUN_TRIGGER" :k="run.trigger" />
        <Chip v-if="run && !run.finishedAt" small :label="{ ar: 'جارية أو لم تكتمل', en: 'Running or cut short' }" :fg="TONE.warn.fg" :bg="TONE.warn.bg" />
        <Btn tone="ghost" size="sm" :label="{ ar: 'سجل المزامنة', en: 'Sync log' }" @click="nav.go('runs')" />
      </div>
      <div v-if="!run" class="empty">{{ t('لم تجرِ أي مزامنة بعد.', 'No run yet.') }}</div>
      <div v-else class="card-body">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(104px,1fr))] gap-2">
          <div v-for="x in runTiles(run)" :key="x.k" class="tile" :class="{ red: x.bad }">
            <div class="tile-v" :class="{ 'text-bad': x.bad }">{{ x.v }}</div>
            <div class="tile-l">{{ bi(x.l) }}</div>
          </div>
        </div>
        <div v-if="run.detail?.error" class="ltr num-mixed mt-2.5 break-words text-[10.5px] leading-[1.7] text-bad">{{ run.detail.error }}</div>
        <div v-else-if="runFailedModels(run).length" class="mt-2.5 text-[10.5px] leading-[1.7] text-bad">{{ t('تعثّرت في', 'Failed in') }} <span class="num ltr">{{ runFailedModels(run).join(' · ') }}</span></div>
      </div>
    </div>

    <div class="hint !mt-0">
      {{ t(
        `كيف تعمل النسخة: في أول مرة تُنسخ سجلات كل نموذج كاملة («النسخة الأولى»، ولا تُحسب تغييرات). بعدها تسأل كل مزامنة أودو عمّا كُتب منذ آخر فحص، وما اختلف يظهر في «التغييرات». النماذج الأساسية تُفحص في كل مزامنة والبقية تتناوب. الحذف لا يترك أثرًا في أودو، فيُكشف بمقارنة دورية: كل ${policy.deletionCheckMinutes ?? '—'} دقيقة للنماذج الأساسية و${policy.deletionCheckMinutesOther ?? '—'} دقيقة لغيرها. سجل التغييرات يُحفظ ${policy.keepChangesDays ?? '—'} يومًا.`,
        `How the copy works: the first time, every record of a model is copied (the “first copy”, which is not counted as changes). After that each run asks Odoo what was written since the last check, and whatever differs appears under “Changes”. Core models are checked on every run, the others take turns. A deletion leaves no trace in Odoo, so it is found by a periodic comparison: every ${policy.deletionCheckMinutes ?? '—'} minutes for core models and ${policy.deletionCheckMinutesOther ?? '—'} for the others. The change feed is kept for ${policy.keepChangesDays ?? '—'} days.`,
      ) }}
    </div>
  </div>
</template>
