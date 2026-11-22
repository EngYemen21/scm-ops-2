<script setup>
// One Odoo model of the mirror: the facts of its copy, the fields that are mirrored (with their types), the fields
// left out (with the reason) and its latest changes. With integration.manage: pause / resume, take a skipped model
// into the mirror, try a stopped one again, mark / unmark as core, synchronise this model now.
//   <OdooModelDrawer :model="nameOrNull" @close="…" />
// GET /integration/odoo/models/{model} (every 15 s while open) · POST /integration/odoo/models/{model}
// { status?: active|paused, core?: bool } · POST /integration/odoo/sync { model }.
import { computed, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, ProgressBar, Tabs, TextInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import OdooFieldChips from './OdooFieldChips.vue';
import OdooSyncResult from './OdooSyncResult.vue';
import { CHANGE_KIND, CORE_CHIP, CORE_HELP, FULL_STATE, MODEL_STATUS, MODEL_STATUS_HELP, agoAt, fieldReason, fieldType, fmtAt, isCopying, modelReason, useOdooNav, useOdooSync } from './odoo';

const props = defineProps({
  /** Technical name of the Odoo model (sale.order …); null closes the drawer. */
  model: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const canManage = auth.can('integration.manage');
const nav = useOdooNav();

const q = useGet(() => (props.model ? `/integration/odoo/models/${encodeURIComponent(props.model)}` : null), null, { refetchInterval: 15000 });
/** The model row + { fields: { name: type }, skippedFields: { name: reason }, recentChanges: [change] }. */
const d = computed(() => (props.model ? q.data.value || null : null));

const act = useAction({ invalidate: ['integration/odoo'] });
const sync = useOdooSync();
const found = sync.result;
const post = (body, success) => act.run(() => api.postIdempotent(`/integration/odoo/models/${encodeURIComponent(props.model)}`, body), { success });
const pause = () => post({ status: 'paused' }, { ar: 'أُوقف النموذج مؤقتًا', en: 'Model paused' });
const resume = () => post({ status: 'active' }, { ar: 'استؤنف النموذج', en: 'Model resumed' });
const retry = () => post({ status: 'active' }, { ar: 'يُعاد فحص النموذج في المزامنة القادمة', en: 'The model is inspected again on the next run' });
const setCore = (core) => post({ core }, core ? { ar: 'صار النموذج أساسيًا', en: 'Marked as core' } : { ar: 'لم يعد النموذج أساسيًا', en: 'No longer core' });
async function takeIn() {
  // a model held back because it carries people's files (pay, contracts, leave, bank accounts): say what taking it in means
  const personal = String(d.value?.reason || '').startsWith('private:');
  const yes = await confirm({
    title: { ar: `ضمّ ${props.model} إلى النسخة؟`, en: `Take ${props.model} into the mirror?` },
    sub: personal
      ? { ar: 'هذا النموذج يحمل ملفات أشخاص: رواتب أو عقود أو إجازات أو حسابات بنكية. بعد ضمّه تُنسخ كل سجلاته إلى نظام العمليات ويراها كل من يملك صلاحية برج التكامل. ضمّه فقط إن كان ذلك مقصودًا.', en: 'This model carries people’s files: pay, contracts, leave or bank accounts. Once taken in, all its records are copied into OPS and visible to everyone who may open the integration tower. Take it in only if that is intended.' }
      : { ar: 'يُفحص في المزامنة القادمة، ثم تُنسخ كل سجلاته التي يستطيع مستخدم أودو قراءتها. بعض النماذج التقنية كبير جدًا.', en: 'It is inspected on the next run, then every record the Odoo user can read is copied. Some technical models are very large.' },
    tone: personal ? 'danger' : 'dark', okLabel: { ar: 'ضمّه إلى النسخة', en: 'Take it in' },
  });
  if (yes) post({ status: 'active' }, { ar: 'ضُمّ النموذج — يُفحص في المزامنة القادمة', en: 'Taken in — it is inspected on the next run' });
}
function clearErrors() { act.clearError(); sync.clearError(); }

/** A run can only work on a model that is being mirrored or waits to be inspected. */
const syncable = computed(() => ['active', 'new'].includes(d.value?.status));
const fields = computed(() => Object.entries(d.value?.fields || {}).map(([name, type]) => ({ name, type })));
const skipped = computed(() => Object.entries(d.value?.skippedFields || {}).map(([name, reason]) => ({ name, reason })));
const fieldQ = ref('');
const matches = (text) => { const f = fieldQ.value.trim().toLowerCase(); return !f || text.toLowerCase().includes(f); };
const shownFields = computed(() => fields.value.filter((f) => matches(`${f.name} ${fieldType(f.type)}`)));
const shownSkipped = computed(() => skipped.value.filter((f) => matches(`${f.name} ${fieldReason(f.reason)}`)));
/** A model that was never inspected (new, or skipped as technical) has no field list yet. */
const notInspected = computed(() => t('حقوله غير معروفة بعد: تُقرأ عند فحصه في أول مزامنة له.', 'Its fields are not known yet: they are read when it is inspected on its first run.'));

const pane = ref('changes');
const panes = computed(() => [
  { k: 'changes', label: { ar: 'آخر التغييرات', en: 'Latest changes' } },
  { k: 'fields', label: { ar: `الحقول المنسوخة (${fmtNum(fields.value.length)})`, en: `Mirrored fields (${fmtNum(fields.value.length)})` } },
  { k: 'skipped', label: { ar: `الحقول المستبعدة (${fmtNum(skipped.value.length)})`, en: `Fields left out (${fmtNum(skipped.value.length)})` } },
]);
watch(() => props.model, () => { pane.value = 'changes'; fieldQ.value = ''; sync.clear(); clearErrors(); });
</script>

<template>
  <Drawer :open="!!model" :width="580" @close="emit('close')">
    <template #title><span class="num ltr inline-block max-w-full break-all">{{ model }}</span></template>
    <template v-if="d?.label" #sub><span dir="auto">{{ d.label }}</span></template>
    <template v-if="d" #headExtra>
      <div class="row !gap-1">
        <Chip small :map="MODEL_STATUS" :k="d.status" />
        <Chip v-if="d.core" small :label="CORE_CHIP" :fg="CORE_CHIP.fg" :bg="CORE_CHIP.bg" :title="bi(CORE_HELP)" />
      </div>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value || sync.error.value" @close="clearErrors" />
    <div v-if="q.isLoading.value" class="skel h-[140px]" />
    <template v-if="d">
      <div class="text-[10.5px] leading-[1.8] text-sec">{{ bi(MODEL_STATUS_HELP[d.status]) }}<template v-if="d.reason"> — {{ modelReason(d.reason) }}</template></div>
      <ProgressBar v-if="isCopying(d)" class="mt-3" :pct="d.copyPct" show-pct :label="{ ar: 'تقدّم النسخة الأولى', en: 'First-copy progress' }" />

      <div class="kv-grid mt-3.5">
        <KV :k="{ ar: 'السجلات في النسخة', en: 'Records in the copy' }" :v="fmtNum(d.mirroredCount)" ltr />
        <KV :k="{ ar: 'السجلات في أودو', en: 'Records in Odoo' }" :v="d.remoteCount == null ? '—' : fmtNum(d.remoteCount)" ltr />
        <KV :k="{ ar: 'النسخة الأولى', en: 'First copy' }"><Chip small :map="FULL_STATE" :k="d.fullState" /></KV>
        <KV :k="{ ar: 'آخر فحص للتغييرات', en: 'Last checked for changes' }"><span class="num-mixed" :title="fmtAt(d.lastSyncedAt)">{{ agoAt(d.lastSyncedAt) }}</span></KV>
        <KV :k="{ ar: 'آخر تغيير رُصد', en: 'Last change seen' }"><span class="num-mixed" :title="fmtAt(d.lastChangedAt)">{{ agoAt(d.lastChangedAt) }}</span></KV>
        <KV :k="{ ar: 'آخر مقارنة لكشف الحذف', en: 'Last check for deletions' }"><span class="num-mixed" :title="fmtAt(d.lastDeletionCheckAt)">{{ agoAt(d.lastDeletionCheckAt) }}</span></KV>
        <KV v-if="d.fullStartedAt" :k="{ ar: 'بدأت النسخة الأولى', en: 'First copy started' }" :v="fmtAt(d.fullStartedAt)" ltr />
        <KV v-if="d.fullFinishedAt" :k="{ ar: 'اكتملت النسخة الأولى', en: 'First copy finished' }" :v="fmtAt(d.fullFinishedAt)" ltr />
      </div>

      <div v-if="d.lastError" class="mt-3 rounded-[10px] bg-bad-soft px-3 py-2 text-[10.5px] leading-[1.8] text-bad">
        <b>{{ t('آخر خطأ', 'Last error') }}</b>
        <template v-if="d.consecutiveErrors"> · {{ t('محاولات فاشلة متتالية', 'failed tries in a row') }} <b class="num">{{ fmtNum(d.consecutiveErrors) }}</b></template>
        <template v-if="d.nextAttemptAt && d.status !== 'error'"> · {{ t('المحاولة القادمة', 'next try') }} <span class="num ltr">{{ fmtAt(d.nextAttemptAt) }}</span></template>
        <div class="ltr num-mixed break-words">{{ d.lastError }}</div>
      </div>

      <div class="row wrap mt-3.5 !gap-[7px]">
        <Btn v-if="d.mirroredCount > 0" tone="softPurple" size="sm" :label="{ ar: 'تصفّح سجلاته', en: 'Browse its records' }" @click="nav.go('records', { model })" />
        <Btn tone="soft" size="sm" :label="{ ar: 'كل تغييراته', en: 'All its changes' }" @click="nav.go('changes', { model })" />
        <template v-if="canManage">
          <Btn v-if="syncable" tone="dark" size="sm" :loading="sync.pending.value" :disabled="act.pending.value" :label="{ ar: 'مزامنة هذا النموذج الآن', en: 'Sync this model now' }" @click="sync.run(model)" />
          <Btn v-if="syncable" tone="softAmber" size="sm" :loading="act.pending.value" :label="{ ar: 'إيقاف مؤقت', en: 'Pause' }" @click="pause" />
          <Btn v-if="d.status === 'paused'" tone="softGreen" size="sm" :loading="act.pending.value" :label="{ ar: 'استئناف', en: 'Resume' }" @click="resume" />
          <Btn v-if="d.status === 'skipped'" tone="softGreen" size="sm" :loading="act.pending.value" :label="{ ar: 'ضمّه إلى النسخة', en: 'Take it into the mirror' }" @click="takeIn" />
          <Btn v-if="d.status === 'error'" tone="softGreen" size="sm" :loading="act.pending.value" :label="{ ar: 'إعادة المحاولة', en: 'Try again' }" @click="retry" />
          <Btn v-if="d.status !== 'skipped'" tone="outline" size="sm" :loading="act.pending.value" :title="bi(CORE_HELP)" :label="d.core ? { ar: 'إلغاء «أساسي»', en: 'Unmark as core' } : { ar: 'اجعله أساسيًا', en: 'Mark as core' }" @click="setCore(!d.core)" />
        </template>
      </div>
      <OdooSyncResult :result="found" class="!mb-0 mt-3" @close="sync.clear()" />

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5 mt-[18px]" />

      <!-- latest changes -->
      <div v-if="pane === 'changes'" class="col !gap-[7px]">
        <div v-for="c in d.recentChanges" :key="c.id" class="row cursor-pointer rounded-xl border border-line-2 px-3 py-2 !gap-2.5 hover:bg-soft" @click="nav.openRecord(c.model, c.odooId)">
          <Chip small :map="CHANGE_KIND" :k="c.kind" />
          <div class="grow">
            <div class="truncate text-[10.5px] font-bold text-ink [text-align:match-parent]" dir="auto">{{ c.displayName || t('بلا اسم', 'No name') }}</div>
            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[9px] text-faint"><span class="num ltr">#{{ c.odooId }}</span><OdooFieldChips v-if="c.fields?.length" :fields="c.fields" /></div>
          </div>
          <span class="num-mixed flex-none text-[9px] text-faint" :title="fmtAt(c.seenAt)">{{ agoAt(c.seenAt) }}</span>
        </div>
        <div v-if="!d.recentChanges?.length" class="empty !p-3.5">{{ t('لا تغييرات مرصودة لهذا النموذج بعد. النسخة الأولى لا تُحسب تغييرات.', 'No change seen for this model yet. The first copy is not counted as changes.') }}</div>
      </div>

      <!-- mirrored fields / fields left out -->
      <template v-else>
        <TextInput v-if="(pane === 'fields' ? fields : skipped).length > 12" v-model="fieldQ" small type="search" full :placeholder="{ ar: 'بحث في الحقول…', en: 'Filter the fields…' }" />
        <div v-if="pane === 'fields'" class="mt-2 overflow-hidden rounded-xl border border-line-2">
          <div v-for="f in shownFields" :key="f.name" class="flex items-center gap-2.5 border-t border-line-2 px-3 py-[7px] first:border-t-0">
            <span class="num ltr min-w-0 flex-1 break-all text-[10.5px] text-ink">{{ f.name }}</span>
            <span class="flex-none text-[9.5px] text-muted" :title="f.type">{{ fieldType(f.type) }}</span>
          </div>
          <div v-if="!shownFields.length" class="empty !p-3.5">{{ fields.length ? t('لا حقول مطابقة.', 'No matching fields.') : notInspected }}</div>
        </div>
        <div v-else class="mt-2 overflow-hidden rounded-xl border border-line-2">
          <div v-for="f in shownSkipped" :key="f.name" class="flex items-start gap-2.5 border-t border-line-2 px-3 py-[7px] first:border-t-0">
            <span class="num ltr w-[42%] flex-none break-all text-[10.5px] text-ink">{{ f.name }}</span>
            <span class="min-w-0 flex-1 text-[9.5px] leading-[1.7] text-muted">{{ fieldReason(f.reason) }}</span>
          </div>
          <div v-if="!shownSkipped.length" class="empty !p-3.5">{{ skipped.length ? t('لا حقول مطابقة.', 'No matching fields.') : fields.length ? t('لا حقول مستبعدة: كل ما يُقرأ من هذا النموذج منسوخ.', 'Nothing is left out: everything that can be read from this model is copied.') : notInspected }}</div>
        </div>
      </template>
    </template>
  </Drawer>
</template>
