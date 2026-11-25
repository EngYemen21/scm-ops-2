<script setup>
// One forecast run: where it stands, what it found, what it suggests — and what may be done with it now.
// GET /planning/runs/{id} → head + parameters, totals, diagnostics, lastError, can {edit, compute, freeze, stop}, plans.
// Moves (forecast.manage): compute · stop · freeze {note} · archive {reason} / restore · copy · push (reorder min/max)
// · actuals (cadence) · edit parameters (RunForm). While the run is computing and the drawer is open, the page pushes it
// on with POST …/advance every ~2 s (stops when the drawer closes). Export (report.export): …/export?format=xlsx|csv&sheet=.
//   <RunDrawer :run-id="idOrNull" @close="…" @edit="(run) => …" @copied="(run) => …" />
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api, useAction, useGet, useInvalidate } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, ProgressBar, SelectInput, Tabs } from '@/components';
import { bi, fmtDate, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import RunBudgets from './RunBudgets.vue';
import RunItems from './RunItems.vue';
import RunPatterns from './RunPatterns.vue';
import RunRequirements from './RunRequirements.vue';
import {
  ARCHIVED_CHIP, METHOD, OWNED_CHIP, OWNED_HELP, PLAN_STATUS, READ_ORIGIN, RUN_STATUS, downloadFromApi, fmtDay, label, money, qty, skipLabel, usePlanningNav,
} from './planning';

const props = defineProps({
  runId: { type: String, default: null },
});
const emit = defineEmits(['close', 'edit', 'copied']);

const auth = useAuth();
const nav = usePlanningNav();
const invalidate = useInvalidate();
const canManage = computed(() => auth.can('forecast.manage'));
const q = useGet(() => (props.runId ? `/planning/runs/${encodeURIComponent(props.runId)}` : null), undefined, {
  // a reader without forecast.manage cannot push the computation on: he only follows it
  refetchInterval: (query) => (query.state.data?.status === 'computing' && !auth.can('forecast.manage') ? 3000 : false),
});
const run = computed(() => (props.runId ? q.data.value || null : null));
const owned = computed(() => !!run.value?.owned);
const fromOdoo = computed(() => run.value?.sourceSystem === 'odoo');
const can = computed(() => (canManage.value && run.value && !owned.value ? run.value.can || {} : {}));
const act = useAction({ invalidate: ['planning'] });
const url = (s = '') => `/planning/runs/${run.value.id}${s}`;

// ---- sub-tabs
const RTABS = ['items', 'requirements', 'patterns', 'budgets'];
const rtab = computed(() => (RTABS.includes(nav.get('rtab')) ? nav.get('rtab') : 'items'));
const showPatterns = computed(() => run.value?.method === 'cadence' || (run.value?.totals?.patterns || 0) > 0);
const rtabs = computed(() => [
  { k: 'items', label: { ar: 'بنود المخزون', en: 'Stock items' }, badge: run.value?.totals?.items || null },
  { k: 'requirements', label: { ar: 'احتياجات الشراء', en: 'Requirements' }, badge: run.value?.totals?.requirements || null },
  ...(showPatterns.value ? [{ k: 'patterns', label: { ar: 'أنماط العملاء', en: 'Customer patterns' }, badge: run.value?.totals?.patterns || null }] : []),
  { k: 'budgets', label: { ar: 'سيناريوهات الميزانية', en: 'Budget scenarios' } },
]);

// ---- computing: push it on while the drawer is open
const poll = useAction({ success: false, errorToast: false, invalidate: false });
let timer = null;
let alive = true;
let busy = false;
async function tick() {
  timer = null;
  if (!alive || busy || run.value?.status !== 'computing' || !canManage.value) return;
  busy = true;
  await poll.run(() => api.postIdempotent(url('/advance')));
  busy = false;
  await q.refetch();
  schedule();
}
function schedule() {
  clearTimeout(timer); timer = null;
  if (alive && props.runId && run.value?.status === 'computing' && canManage.value && !fromOdoo.value) timer = setTimeout(tick, 2000);
}
watch(() => [props.runId, run.value?.status], ([, st], old) => {
  schedule();
  if (old && old[1] === 'computing' && st && st !== 'computing') void invalidate(['planning']);
});
onBeforeUnmount(() => { alive = false; clearTimeout(timer); });
const progressPct = computed(() => { const p = run.value?.progress; return p && p.total ? Math.round((p.done / p.total) * 100) : 0; });
const PHASE = { collect: { ar: 'قراءة السجل', en: 'Reading history' }, items: { ar: 'حساب البنود', en: 'Computing items' }, finish: { ar: 'الإنهاء', en: 'Finishing' }, done: { ar: 'انتهى', en: 'Done' } };

// ---- moves
async function compute() {
  if (run.value.status === 'computed' && !(await confirm({ title: { ar: `إعادة حساب ${run.value.number}؟`, en: `Recompute ${run.value.number}?` }, sub: { ar: 'تُستبدل النتائج الحالية بنتائج الحساب الجديد', en: 'The current results are replaced by the new computation' }, tone: 'primary' }))) return;
  const r = await act.run(() => api.postIdempotent(url('/compute')), { success: t('بدأ الحساب', 'Computation started') });
  if (r) await q.refetch();
}
async function stop() {
  if (!(await confirm({ title: { ar: `إيقاف حساب ${run.value.number}؟`, en: `Stop computing ${run.value.number}?` }, sub: { ar: 'يعود مسودة وتُحذف النتائج الجزئية', en: 'It goes back to draft and the partial results are dropped' }, tone: 'danger' }))) return;
  await act.run(() => api.postIdempotent(url('/stop')), { success: t('أُوقف الحساب', 'Computation stopped') });
}
async function freeze() {
  const note = await ask({ title: { ar: `تجميد ${run.value.number}`, en: `Freeze ${run.value.number}` }, sub: { ar: 'التشغيل المجمّد لا يُعدَّل ولا يُعاد حسابه — يبقى مرجعًا. يمكن نسخه.', en: 'A frozen run is never edited nor recomputed — it stays as a reference. It can be copied.' }, label: { ar: 'ملاحظة (اختياري)', en: 'Note (optional)' }, okLabel: { ar: 'تجميد', en: 'Freeze' }, tone: 'primary' });
  if (note === null) return;
  await act.run(() => api.postIdempotent(url('/freeze'), note ? { note } : undefined), { success: t('جُمّد التشغيل', 'Run frozen') });
}
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة ${run.value.number}`, en: `Archive ${run.value.number}` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'أرشفة', en: 'Archive' }, tone: 'danger' });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(url('/archive'), reason ? { reason } : undefined), { success: t('أُرشف التشغيل', 'Run archived') });
}
async function restore() {
  await act.run(() => api.postIdempotent(url('/restore')), { success: t('استُعيد التشغيل', 'Run restored') });
}
async function copy() {
  if (!(await confirm({ title: { ar: `نسخ ${run.value.number} إلى تشغيل جديد؟`, en: `Copy ${run.value.number} to a new run?` }, sub: { ar: 'مسودة جديدة بالمعطيات نفسها', en: 'A new draft with the same parameters' }, tone: 'primary' }))) return;
  const r = await act.run(() => api.postIdempotent(url('/copy')), { success: (x) => t(`أُنشئ ${x?.number || ''}`, `Created ${x?.number || ''}`) });
  if (r) emit('copied', r);
}
const pushed = ref(null);
async function push() {
  if (!(await confirm({ title: { ar: 'كتابة الحد الأدنى والأقصى المقترحين على المنتجات؟', en: 'Write the suggested min / max to the products?' },
    sub: { ar: 'كل بند يختلف اقتراحه عن إعداد المنتج الحالي — عبر شاشة المنتجات وبسجل تدقيق يذكر هذا التشغيل', en: 'Every item whose suggestion differs from the product — through the products service, audited with this run named' }, tone: 'primary' }))) return;
  const r = await act.run(() => api.postIdempotent(url('/push')), { success: (x) => t(`كُتب على ${x?.pushed ?? 0} منتج`, `Written to ${x?.pushed ?? 0} product(s)`) });
  if (r) pushed.value = r;
}
const actuals = ref(null);
async function refreshActuals() {
  const r = await act.run(() => api.postIdempotent(url('/actuals')), { success: t('حُدّثت الطلبات الفعلية', 'Actual orders refreshed') });
  if (r) actuals.value = r;
}
watch(() => props.runId, () => { pushed.value = null; actuals.value = null; act.clearError(); });

// ---- export
const sheet = ref('items');
const SHEETS = [
  { v: 'items', l: { ar: 'البنود', en: 'Items' } }, { v: 'requirements', l: { ar: 'الاحتياجات', en: 'Requirements' } }, { v: 'periods', l: { ar: 'فترات التوقع', en: 'Forecast periods' } },
  { v: 'patterns', l: { ar: 'أنماط العملاء', en: 'Customer patterns' } }, { v: 'history', l: { ar: 'السجل', en: 'History' } },
];
const exporting = useAction({ invalidate: false });
const exportXlsx = () => exporting.run(() => downloadFromApi(url('/export'), { format: 'xlsx' }, `${run.value.number}.xlsx`), { success: (f) => t(`نُزّل ${f}`, `Downloaded ${f}`) });
const exportCsv = () => exporting.run(() => downloadFromApi(url('/export'), { format: 'csv', sheet: sheet.value }, `${run.value.number}-${sheet.value}.csv`), { success: (f) => t(`نُزّل ${f}`, `Downloaded ${f}`) });

// ---- what it found
const diag = computed(() => run.value?.diagnostics || null);
const skipped = computed(() => Object.entries(diag.value?.skipped || {}).filter(([, n]) => Number(n) > 0));
const readRows = computed(() => Object.entries(diag.value?.read || {}));
const isCadence = computed(() => run.value?.method === 'cadence');
const pushable = computed(() => ['computed', 'frozen'].includes(run.value?.status));
</script>

<template>
  <Drawer :open="!!runId" :width="1000" @close="emit('close')">
    <template #title><span dir="auto">{{ run ? run.name || run.number : runId }}</span></template>
    <template #sub>
      <span v-if="run"><span class="num ltr inline-block">{{ run.number }}</span> · {{ label(METHOD, run.method) }} · <span class="num">{{ fmtDay(run.forecastFrom) }} → {{ fmtDay(run.forecastTo) }}</span><template v-if="run.externalRef"> · Odoo #{{ run.externalRef }}</template></span>
    </template>
    <template v-if="run" #headExtra>
      <span class="inline-flex flex-wrap gap-1">
        <Chip :map="RUN_STATUS" :k="run.status" />
        <Chip v-if="!run.active" :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <Chip v-if="fromOdoo" :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </span>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value || exporting.error.value" :closable="!q.error.value" @close="act.clearError(); exporting.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[220px]" />
    <template v-if="run">
      <div v-if="owned" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }}</div>
      <div v-else-if="fromOdoo" class="hint purple !mt-0 mb-3">{{ t('حُسب هذا التشغيل في أودو — لا يُعاد حسابه هنا. انسخه لتشغيل جديد.', 'This run was computed in Odoo — it is never recomputed here. Copy it to a new run.') }}</div>
      <div v-if="run.lastError" class="banner red mb-3">{{ t('آخر خطأ في الحساب:', 'Last computation error:') }} <span dir="auto">{{ run.lastError }}</span></div>

      <!-- progress -->
      <div v-if="run.status === 'computing'" class="card mb-3 !p-3">
        <div class="row mb-1.5 text-[11px] font-extrabold">
          <span>{{ t('قيد الحساب', 'Computing') }} · {{ bi(PHASE[run.progress?.phase] || { ar: run.progress?.phase || '…', en: run.progress?.phase || '…' }) }}</span>
          <div class="grow" />
          <span class="num">{{ fmtNum(run.progress?.done || 0) }} / {{ fmtNum(run.progress?.total || 0) }}</span>
        </div>
        <ProgressBar :pct="progressPct" show-pct />
        <div v-if="!canManage" class="hint !mb-0 mt-2">{{ t('يتقدّم الحساب بأمر من صاحب صلاحية التوقع أو من أمر الخادم المجدول؛ هذه الصفحة تتابعه فقط.', 'The computation is pushed on by someone with the forecast permission or the scheduled server command; this page only follows it.') }}</div>
      </div>

      <!-- moves -->
      <div class="row wrap mb-3 !gap-1.5">
        <template v-if="canManage && !owned">
          <Btn v-if="can.compute && run.status !== 'computing'" size="sm" tone="primary" :loading="act.pending.value" :label="run.status === 'computed' ? { ar: 'إعادة الحساب', en: 'Recompute' } : { ar: 'احسب', en: 'Compute' }" @click="compute" />
          <Btn v-if="can.stop" size="sm" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إيقاف الحساب', en: 'Stop' }" @click="stop" />
          <Btn v-if="can.freeze" size="sm" tone="softPurple" :loading="act.pending.value" :label="{ ar: 'تجميد', en: 'Freeze' }" @click="freeze" />
          <Btn v-if="can.edit" size="sm" tone="soft" :label="{ ar: 'تعديل المعطيات', en: 'Edit parameters' }" @click="emit('edit', run)" />
          <Btn v-if="pushable" size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'دفع الحد الأدنى/الأقصى للمنتجات', en: 'Push reorder min / max' }" @click="push" />
          <Btn v-if="pushable && isCadence" size="sm" tone="softBlue" :loading="act.pending.value" :label="{ ar: 'تحديث الطلبات الفعلية', en: 'Refresh actual orders' }" @click="refreshActuals" />
          <Btn v-if="run.active && run.status !== 'computing'" size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
          <Btn v-if="!run.active" size="sm" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click="restore" />
        </template>
        <Btn v-if="canManage" size="sm" tone="outline" :loading="act.pending.value" :label="{ ar: 'نسخ إلى تشغيل جديد', en: 'Copy to a new run' }" @click="copy" />
        <div class="grow" />
        <template v-if="auth.can('report.export') && pushable">
          <Btn size="sm" tone="softGreen" :loading="exporting.pending.value" :label="{ ar: 'تصدير Excel', en: 'Export Excel' }" @click="exportXlsx" />
          <SelectInput v-model="sheet" small class="w-[130px]" :options="SHEETS" />
          <Btn size="sm" tone="soft" :loading="exporting.pending.value" :label="{ ar: 'CSV', en: 'CSV' }" @click="exportCsv" />
        </template>
      </div>
      <div v-if="pushed" class="hint green !mt-0 mb-3">
        {{ t(`كُتب الحد الأدنى/الأقصى على ${pushed.pushed} منتج`, `Reorder min / max written to ${pushed.pushed} product(s)`) }}<template v-if="pushed.products?.length">:
          <span v-for="p in pushed.products.slice(0, 12)" :key="p.productId" class="me-2 inline-block"><span class="num ltr inline-block">{{ p.sku }} {{ p.oldMin ?? '—' }}/{{ p.oldMax ?? '—' }} → {{ p.newMin }}/{{ p.newMax }}</span></span><template v-if="pushed.products.length > 12"> …</template>
        </template>
      </div>
      <div v-if="actuals" class="hint teal !mt-0 mb-3">
        {{ t(`فُحص ${actuals.checked} طلب متوقع: ${actuals.ordered} طُلب فعلًا · ${actuals.missed} لم يُطلب · ${actuals.pending} بانتظار`, `${actuals.checked} expected order(s) checked: ${actuals.ordered} ordered · ${actuals.missed} missed · ${actuals.pending} pending`) }}
      </div>

      <!-- totals -->
      <div class="grid-eq mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
        <div class="tile"><div class="tile-v">{{ fmtNum(run.totals?.items || 0) }}</div><div class="tile-l">{{ t('بنود المخزون', 'Stock items') }}</div></div>
        <div class="tile red"><div class="tile-v">{{ fmtNum(run.totals?.shortage || 0) }}</div><div class="tile-l">{{ t('بنود بعجز', 'Items short') }} · <span class="num">{{ qty(run.totals?.shortageQty) }}</span></div></div>
        <div class="tile amber"><div class="tile-v">{{ fmtNum(run.totals?.surplus || 0) }}</div><div class="tile-l">{{ t('بنود بفائض', 'Items in surplus') }} · <span class="num">{{ qty(run.totals?.surplusQty) }}</span></div></div>
        <div class="tile"><div class="tile-v">{{ qty(run.totals?.forecastQty) }}</div><div class="tile-l">{{ t('الطلب المتوقع (كمية)', 'Forecast demand (qty)') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ qty(run.totals?.suggestedQty) }}</div><div class="tile-l">{{ t('كمية الشراء المقترحة', 'Suggested purchase qty') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ money(run.totals?.suggestedValue) }}</div><div class="tile-l">{{ t('قيمة الشراء المقترحة', 'Suggested purchase value') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ fmtNum(run.totals?.requirements || 0) }}</div><div class="tile-l">{{ t('احتياجات شراء', 'Requirements') }} · {{ money(run.totals?.requirementValue) }}</div></div>
        <div class="tile teal"><div class="tile-v">{{ fmtNum(run.totals?.patterns || 0) }}</div><div class="tile-l">{{ t('أنماط عملاء', 'Customer patterns') }} · <span class="num">{{ fmtNum(run.totals?.events || 0) }}</span> {{ t('طلب متوقع', 'expected orders') }}</div></div>
      </div>

      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'تاريخ الإسناد', en: 'Anchor day' }" :v="fmtDay(run.anchorDate)" ltr />
        <KV :k="{ ar: 'السجل', en: 'History' }" :v="`${fmtDay(run.historyFrom)} → ${fmtDay(run.historyTo)}`" ltr />
        <KV :k="{ ar: 'حُسب', en: 'Computed' }" :v="run.computedAt ? fmtDate(run.computedAt) : '—'" ltr />
        <KV :k="{ ar: 'مدة الحساب', en: 'Compute time' }" :v="run.computeSeconds != null ? `${fmtNum(run.computeSeconds, 1)} s` : '—'" ltr />
        <KV :k="{ ar: 'لقطة المخزون', en: 'Stock snapshot' }" :v="run.stockAt ? fmtDate(run.stockAt) : '—'" ltr />
        <KV :k="{ ar: 'جُمّد', en: 'Frozen' }" :v="run.frozenAt ? `${fmtDate(run.frozenAt)} · ${run.frozenBy || ''}` : '—'" />
        <KV :k="{ ar: 'أنشأه', en: 'Created by' }" :v="`${run.createdBy || '—'} · ${fmtDate(run.createdAt)}`" />
        <KV v-if="run.note" :k="{ ar: 'ملاحظة', en: 'Note' }" :v="run.note" />
      </div>

      <!-- diagnostics -->
      <details v-if="diag" class="card mb-3 !p-3">
        <summary class="cursor-pointer text-[11.5px] font-extrabold">
          {{ t('ما قرأه الحساب وما استبعده', 'What the computation read and left out') }}
          <span v-if="skipped.length" class="ms-1 text-warn">· {{ t(`${skipped.length} سبب استبعاد`, `${skipped.length} exclusion reason(s)`) }}</span>
        </summary>
        <div class="kv-grid mt-2">
          <KV v-for="[k, n] in readRows" :key="`r-${k}`" :k="READ_ORIGIN[k] || { ar: k, en: k }" :v="fmtNum(n)" ltr />
          <KV :k="{ ar: 'منتجات دُرست', en: 'Products studied' }" :v="fmtNum(diag.products || 0)" ltr />
          <KV :k="{ ar: 'صفوف طلب يومية', en: 'Daily demand rows' }" :v="fmtNum(diag.demandRows || 0)" ltr />
          <KV :k="{ ar: 'بنود بطريقة بديلة', en: 'Items on a fallback method' }" :v="fmtNum(diag.fallbacks || 0)" ltr />
          <KV :k="{ ar: 'بنود شراء بلا سعر', en: 'Items to buy without a price' }" :v="fmtNum(diag.noPrice || 0)" ltr />
        </div>
        <div v-if="skipped.length" class="mt-2">
          <div class="mb-1 text-[10.5px] font-extrabold text-sec">{{ t('سطور مستبعدة', 'Lines left out') }}</div>
          <div v-for="[k, n] in skipped" :key="k" class="row border-b border-line py-1 text-[11px]">
            <span dir="auto">{{ bi(skipLabel(k)) }}</span><div class="grow" /><span class="num font-extrabold">{{ fmtNum(n) }}</span>
          </div>
        </div>
      </details>

      <div v-if="run.plans?.length" class="mb-3">
        <div class="card-title mb-1">{{ t('خطط شراء من هذا التشغيل', 'Purchase plans from this run') }}</div>
        <div class="row wrap !gap-1.5">
          <button v-for="p in run.plans" :key="p.id" type="button" class="pill" @click="nav.patch({ plan: p.id })">
            <span class="num ltr inline-block">{{ p.number }}</span> · {{ label(PLAN_STATUS, p.status) }}
          </button>
        </div>
      </div>

      <Tabs :model-value="rtab" :tabs="rtabs" variant="sm" @update:model-value="(k) => nav.patch({ rtab: k === 'items' ? null : k })" />
      <RunItems v-if="rtab === 'items'" :run="run" />
      <RunRequirements v-else-if="rtab === 'requirements'" :run="run" />
      <RunPatterns v-else-if="rtab === 'patterns'" :run="run" />
      <RunBudgets v-else-if="rtab === 'budgets'" :run="run" />
    </template>
  </Drawer>
</template>
