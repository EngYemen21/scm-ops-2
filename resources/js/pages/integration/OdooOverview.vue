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
import { CHANGE_KIND, CHANGE_KINDS, CONNECTION_HEALTH, PROJECTION_SECTION, RUN_TRIGGER, TONE, agoAt, connectionError, fmtAt, fmtBytes, lagParts, runFailedModels, runTiles, useOdooNav, useOdooSync } from './odoo';

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
/** `status.backup`: { verification (the last finished comparison with Odoo), files (attachment content kept), onHold }. */
const backup = computed(() => props.status.backup || null);
const verified = computed(() => backup.value?.verification || null);
const files = computed(() => backup.value?.files || null);
/**
 * `status.sections`: the OPS sections built for what Odoo has — [{ key, follows, copied, loadedAt, lastLoadAt, fedAt, waiting }].
 * A section is loaded once from the copy by a person; from then on the runs feed it.
 */
const SECTION_STATE = {
  copying: { label: { ar: 'نسخ بياناته من أودو لم يكتمل', en: 'Its data is still being copied' }, tone: 'neutral' },
  waiting: { label: { ar: 'لم يُحمَّل بعد', en: 'Not loaded yet' }, tone: 'warn' },
  follows: { label: { ar: 'يتبع أودو', en: 'Follows Odoo' }, tone: 'ok' },
  handed: { label: { ar: 'سُلّم لنظام العمليات', en: 'Handed over to OPS' }, tone: 'blue' },
};
const sections = computed(() => (props.status.sections || []).map((s) => ({
  ...s,
  label: PROJECTION_SECTION[s.key] || { ar: s.key, en: s.key },
  state: SECTION_STATE[!s.follows ? 'handed' : s.loadedAt ? 'follows' : s.copied ? 'waiting' : 'copying'],
})));
const notLoaded = computed(() => sections.value.filter((s) => s.follows && s.copied && !s.loadedAt).length);
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

    <!-- ───── the copy as a backup: is it complete, and how much of the attachments' content OPS keeps ───── -->
    <div v-if="backup" class="card">
      <div class="card-head flex-wrap">
        <div class="card-title">{{ t('النسخة الاحتياطية', 'The copy as a backup') }}</div>
        <Chip v-if="verified" small dot :label="verified.complete ? { ar: 'مكتملة ومطابقة لأودو', en: 'Complete and true to Odoo' } : { ar: 'تحتاج نظرة', en: 'Needs a look' }"
              :fg="verified.complete ? TONE.ok.fg : TONE.warn.fg" :bg="verified.complete ? TONE.ok.bg : TONE.warn.bg" />
        <Chip v-else small :label="{ ar: 'لم تُفحص بعد', en: 'Not checked yet' }" :fg="TONE.neutral.fg" :bg="TONE.neutral.bg" />
      </div>
      <div class="card-body">
        <div v-if="backup.onHold?.length" class="mb-3 rounded-[10px] bg-warn-soft px-3 py-2 text-[10.5px] font-bold leading-[1.8] text-warn">
          {{ t('نماذج أوقفتها النسخة للحماية لأن عددًا كبيرًا من سجلاتها اختفى من أودو دفعة واحدة. لم يُحذف شيء من النسخة، والقرار لك:', 'Models the copy put on hold because many of their records disappeared from Odoo at once. Nothing was removed from the copy; the decision is yours:') }}
          <button v-for="name in backup.onHold" :key="name" type="button" class="num ltr ms-1.5 underline" @click="nav.openModel(name)">{{ name }}</button>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-2">
          <template v-if="verified">
            <div class="tile"><div class="tile-v num-mixed text-[13px]" :title="fmtAt(verified.finishedAt)">{{ agoAt(verified.finishedAt) }}</div><div class="tile-l">{{ t('آخر مطابقة مع أودو', 'Last comparison with Odoo') }}</div></div>
            <div class="tile"><div class="tile-v">{{ fmtNum(verified.recordsCopy) }}</div><div class="tile-l">{{ t('سجلات في النسخة', 'Records in the copy') }} · {{ t('في أودو', 'in Odoo') }} <span class="num">{{ fmtNum(verified.recordsOdoo) }}</span></div></div>
            <div class="tile" :class="{ red: verified.missing > 0 }"><div class="tile-v" :class="{ 'text-bad': verified.missing > 0 }">{{ fmtNum(verified.missing) }}</div><div class="tile-l">{{ t('ناقصة عن أودو', 'Missing from the copy') }}</div></div>
            <div class="tile" :class="{ red: verified.differing > 0 }"><div class="tile-v" :class="{ 'text-bad': verified.differing > 0 }">{{ fmtNum(verified.differing) }}</div><div class="tile-l">{{ t('مختلفة من عيّنة', 'Differing in a sample of') }} <span class="num">{{ fmtNum(verified.sampled) }}</span></div></div>
            <div class="tile"><div class="tile-v">{{ fmtNum(verified.extra) }}</div><div class="tile-l">{{ t('حُذفت في أودو وتنتظر التأشير', 'Deleted in Odoo, waiting to be marked') }}</div></div>
          </template>
          <template v-if="files && files.ready">
            <div class="tile"><div class="tile-v">{{ fmtNum(files.stored) }}</div><div class="tile-l">{{ t('ملفات مرفقات محفوظة', 'Attachment files kept') }} · <span class="num ltr">{{ fmtBytes(files.storedBytes) }}</span></div></div>
            <div class="tile"><div class="tile-v" :class="{ 'text-azure': files.left > 0 }">{{ fmtNum(files.left) }}</div><div class="tile-l">{{ t('ملفات لم تُؤرشف بعد', 'Files not archived yet') }}</div></div>
            <div v-if="files.failed" class="tile red"><div class="tile-v text-bad">{{ fmtNum(files.failed) }}</div><div class="tile-l">{{ t('ملفات تعذّر حفظها', 'Files that could not be kept') }}</div></div>
          </template>
        </div>
        <p class="m-0 mt-3 text-[10.5px] leading-[1.9] text-muted">
          {{ t('النسخة تحفظ كل سجل حتى بعد حذفه من أودو، ولا تتبع حذفًا جماعيًا دون تأكيدك. المطابقة تقارن أرقام السجلات كلها وتعيد قراءة عيّنة من كل نموذج، وتجري تلقائيًا كل ليلة على خادم له مجدول. محتوى المرفقات يُحفظ على وسيط تخزين النظام، وقائمتها داخل النسخة.', 'The copy keeps every record even after it is deleted in Odoo, and does not follow a mass deletion without your confirmation. The comparison checks every record id and reads a sample of each model again; it runs every night on a server with a scheduler. The content of attachments is kept on the storage disk of OPS, their list inside the copy.') }}
        </p>
      </div>
    </div>

    <!-- ───── the OPS sections built for what Odoo has: did each take its first load, is it kept current ───── -->
    <div v-if="sections.length" class="card">
      <div class="card-head flex-wrap">
        <div class="card-title">{{ t('أقسام النظام التي تتبع أودو', 'OPS sections that follow Odoo') }} <span class="card-count num">{{ fmtNum(sections.length) }}</span></div>
        <Chip v-if="notLoaded" small dot :label="{ ar: `${notLoaded} بانتظار التحميل الأول`, en: `${notLoaded} waiting for the first load` }" :fg="TONE.warn.fg" :bg="TONE.warn.bg" />
      </div>
      <div class="card-body !pt-1">
        <div v-for="s in sections" :key="s.key" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line-2 py-2 text-[11px] last:border-b-0">
          <div class="min-w-[150px] flex-1 font-extrabold text-ink">{{ bi(s.label) }}</div>
          <Chip small dot :label="s.state.label" :fg="TONE[s.state.tone].fg" :bg="TONE[s.state.tone].bg" />
          <div class="min-w-[130px] text-muted">
            <template v-if="s.loadedAt">{{ t('حُمّل', 'loaded') }} <span class="num-mixed" :title="fmtAt(s.loadedAt)">{{ agoAt(s.loadedAt) }}</span></template>
            <template v-else>—</template>
          </div>
          <div class="min-w-[150px] text-muted">
            <template v-if="s.fedAt">{{ t('آخر تحديث من أودو', 'last fed from Odoo') }} <span class="num-mixed" :title="fmtAt(s.fedAt)">{{ agoAt(s.fedAt) }}</span></template>
            <template v-else-if="s.waiting">{{ t('تغييرات تنتظر', 'changes waiting') }} <b class="num">{{ fmtNum(s.waiting) }}</b></template>
          </div>
        </div>
        <p class="m-0 mt-3 text-[10.5px] leading-[1.9] text-muted">
          {{ t('كل قسم يأخذ بياناته من النسخة مرة واحدة بقرار مسؤول، ثم تحدّثه كل مزامنة بما يتغير في أودو. القسم الذي لم يُحمَّل بعد لا تمسّه المزامنة.', 'Each section takes its data from the copy once, by a person’s decision; after that every run feeds it what changes in Odoo. A section that has not been loaded yet is left alone by the runs.') }}
          <span v-if="notLoaded" class="num ltr inline-block">php artisan scm:odoo-project &lt;area&gt; --apply</span>
        </p>
      </div>
    </div>

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
