<script setup>
// One budget scenario: its money per month, the weights of the priority, the rules of the allocation, the allocation
// itself (month table + every requirement funded / partial / deferred) — and the purchase plan made from it.
//   GET   /planning/budgets/{id}                 GET /planning/budgets/{id}/allocations?status&month&q&page  (keys a*)
//   PATCH /planning/budgets/{id} { name, note, weights, criticalFirst, allowPartial, carryDeferred, carryUnused, urgencyHorizonDays, lines }
//   POST  /planning/budgets/{id}/allocate        POST /planning/budgets/{id}/plans { name, dateFrom, dateTo, rfqGrouping, note }
// A change takes the scenario back to "not allocated"; a scenario a live plan was made from keeps its allocation.
//   <BudgetDrawer :budget-id="idOrNull" @close="…" @plan="(plan) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, Drawer, ErrorBanner, NumberInput, ProgressBar, SelectInput, TextArea, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { bi, fmtDate, fmtNum, num, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import { ALLOC_STATUS, BUDGET_STATUS, CRITICALITY, GROUPING, OWNED_CHIP, OWNED_HELP, PLAN_STATUS, WEIGHTS, fmtDay, label, money, nameOf, options, pct, qty, usePlanningNav, useSearchParam } from './planning';

const props = defineProps({
  budgetId: { type: String, default: null },
});
const emit = defineEmits(['close', 'plan']);

const auth = useAuth();
const nav = usePlanningNav();
const q = useGet(() => (props.budgetId ? `/planning/budgets/${encodeURIComponent(props.budgetId)}` : null));
const b = computed(() => (props.budgetId ? q.data.value || null : null));
const livePlans = computed(() => (b.value?.plans || []).filter((p) => p.status !== 'cancel'));
const canEdit = computed(() => auth.can('forecast.manage') && b.value && !b.value.owned && !livePlans.value.length);
const canPlan = computed(() => auth.can('purchaseplan.manage') && b.value?.status === 'allocated' && !b.value.owned);
const act = useAction({ invalidate: ['planning'] });
const url = (s = '') => `/planning/budgets/${b.value.id}${s}`;

// ---- rules (editable copy)
const blank = () => ({ name: '', note: '', weights: Object.fromEntries(WEIGHTS.map((w) => [w.k, 0])), criticalFirst: true, allowPartial: true, carryDeferred: true, carryUnused: true, urgencyHorizonDays: 30, lines: [] });
const v = reactive(blank());
let initial = blank();
const load = (x) => ({ name: x.name || '', note: x.note || '', weights: { ...blank().weights, ...(x.weights || {}) }, criticalFirst: !!x.criticalFirst, allowPartial: !!x.allowPartial,
  carryDeferred: !!x.carryDeferred, carryUnused: !!x.carryUnused, urgencyHorizonDays: x.urgencyHorizonDays ?? 30, lines: (x.lines || []).map((l) => ({ month: l.month, amount: num(l.amount) })) });
watch(() => b.value, (x) => { if (x) { initial = load(x); Object.assign(v, JSON.parse(JSON.stringify(initial))); } }, { immediate: true });
watch(() => props.budgetId, () => { act.clearError(); planning.value = false; });
const dirty = computed(() => JSON.stringify(v) !== JSON.stringify(initial));
const weightSum = computed(() => WEIGHTS.reduce((s, w) => s + (Number(v.weights[w.k]) || 0), 0));
const setWeight = (k, x) => { v.weights[k] = Math.max(0, Math.min(100, Math.round(Number(x) || 0))); };

function addMonth() {
  const last = v.lines[v.lines.length - 1]?.month;
  let next;
  if (last) { const [y, m] = last.split('-').map(Number); const d = new Date(y, m, 1); next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; } else { const d = new Date(); next = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; }
  v.lines.push({ month: next, amount: v.lines[v.lines.length - 1]?.amount ?? 0 });
}
const removeMonth = (i) => v.lines.splice(i, 1);
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);

async function save() {
  const body = {};
  if (v.name.trim() && v.name !== initial.name) body.name = v.name.trim();
  if (v.note !== initial.note) body.note = v.note || null;
  if (JSON.stringify(v.weights) !== JSON.stringify(initial.weights)) body.weights = { ...v.weights };
  for (const k of ['criticalFirst', 'allowPartial', 'carryDeferred', 'carryUnused', 'urgencyHorizonDays']) if (v[k] !== initial[k]) body[k] = v[k];
  if (JSON.stringify(v.lines) !== JSON.stringify(initial.lines)) body.lines = v.lines.map((l) => ({ month: l.month, amount: Number(l.amount) || 0 }));
  await act.run(() => api.patch(url(), body), { success: t('حُفظ السيناريو — وزّعه من جديد', 'Scenario saved — allocate it again') });
}
async function allocate() {
  if (dirty.value && !(await confirm({ title: { ar: 'هناك تعديلات غير محفوظة', en: 'There are unsaved changes' }, sub: { ar: 'التوزيع يستخدم القواعد المحفوظة. احفظ أولًا أو تابع بالقواعد المحفوظة.', en: 'Allocation uses the saved rules. Save first, or go on with the saved rules.' }, okLabel: { ar: 'وزّع بالمحفوظ', en: 'Allocate saved' }, tone: 'dark' }))) return;
  await act.run(() => api.postIdempotent(url('/allocate')), { success: t('وُزّعت الميزانية', 'Budget allocated') });
}

// ---- allocations
const SORTS = ['rank', 'score', 'month', 'fundedValue', 'requiredValue', 'orderDate', 'sku'];
const sort = computed(() => nav.sortOf('asort', 'aorder', SORTS));
const allocs = useList(() => (b.value && b.value.status === 'allocated' ? url('/allocations') : null), () => ({
  status: nav.get('astatus') || undefined, month: nav.get('amonth') || undefined, q: nav.get('aq') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.pageOf('apage'), pageSize: 25,
}));
const as = useSearchParam(nav, 'aq', 'apage');
const monthOptions = computed(() => (b.value?.lines || []).map((l) => ({ v: l.month, l: l.month })));
const allocCols = [
  { key: 'rank', header: '#', width: '44px', kind: 'num', sortable: true, value: (r) => (r.rank ? r.rank : '—') },
  { key: 'sku', header: { ar: 'المنتج', en: 'Product' }, width: 'minmax(160px,1.5fr)', sortable: true },
  { key: 'month', header: { ar: 'الشهر', en: 'Month' }, width: '72px', sortable: true, kind: 'date', value: (r) => r.month || '—' },
  { key: 'orderDate', header: { ar: 'تاريخ الطلب', en: 'Order date' }, width: '86px', kind: 'date', sortable: true, value: (r) => fmtDay(r.orderDate) },
  { key: 'score', header: { ar: 'النقاط', en: 'Score' }, width: '58px', kind: 'num', sortable: true, value: (r) => (r.score == null ? '—' : fmtNum(r.score, 1)) },
  { key: 'requiredValue', header: { ar: 'المطلوب', en: 'Required' }, width: '90px', kind: 'num', sortable: true, value: (r) => money(r.requiredValue) },
  { key: 'fundedValue', header: { ar: 'المموّل', en: 'Funded' }, width: '90px', kind: 'num', sortable: true, value: (r) => money(r.fundedValue) },
  { key: 'status', header: { ar: 'النتيجة', en: 'Result' }, width: 'minmax(120px,1fr)', bare: true },
];
const scoreTip = (r) => WEIGHTS.map((w) => `${bi(w.l)}: ${r.scores?.[w.k] == null ? '—' : fmtNum(r.scores[w.k], 1)}`).join('\n');

// ---- make a plan
const planning = ref(false);
const pf = reactive({ name: '', dateFrom: '', dateTo: '', rfqGrouping: 'date', note: '' });
function openPlan() {
  const lines = b.value?.lines || [];
  pf.name = ''; pf.note = ''; pf.rfqGrouping = 'date';
  pf.dateFrom = lines[0] ? `${lines[0].month}-01` : '';
  if (lines.length) { const [y, m] = lines[lines.length - 1].month.split('-').map(Number); const d = new Date(y, m, 0); pf.dateTo = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; } else pf.dateTo = '';
  planning.value = true;
}
async function makePlan() {
  const body = { name: pf.name || null, dateFrom: pf.dateFrom || null, dateTo: pf.dateTo || null, rfqGrouping: pf.rfqGrouping, note: pf.note || null };
  const r = await act.run(() => api.postIdempotent(url('/plans'), body), { success: (x) => t(`أُنشئت خطة الشراء ${x?.number || ''}`, `Purchase plan ${x?.number || ''} made`) });
  if (r) { planning.value = false; emit('plan', r); }
}
const GROUPS = options(GROUPING);
const monthCols = [
  { key: 'month', header: { ar: 'الشهر', en: 'Month' }, width: '90px', kind: 'date' },
  { key: 'amount', header: { ar: 'المبلغ', en: 'Amount' }, width: 'minmax(90px,1fr)', kind: 'num', value: (r) => money(r.amount) },
  { key: 'carriedIn', header: { ar: 'مُرحّل إليه', en: 'Carried in' }, width: 'minmax(90px,1fr)', kind: 'num', value: (r) => money(r.carriedIn) },
  { key: 'due', header: { ar: 'المستحق', en: 'Due' }, width: 'minmax(90px,1fr)', kind: 'num', value: (r) => money(r.due) },
  { key: 'funded', header: { ar: 'المموّل', en: 'Funded' }, width: 'minmax(90px,1fr)', kind: 'num', value: (r) => money(r.funded) },
  { key: 'remaining', header: { ar: 'المتبقي', en: 'Remaining' }, width: 'minmax(90px,1fr)', kind: 'num', value: (r) => money(r.remaining) },
];
</script>

<template>
  <Drawer :open="!!budgetId" :width="960" :z-index="70" @close="emit('close')">
    <template #title><span dir="auto">{{ b ? b.name : t('سيناريو ميزانية', 'Budget scenario') }}</span></template>
    <template #sub><span v-if="b?.run"><span class="num ltr inline-block">{{ b.run.number }}</span> · <span dir="auto">{{ b.run.name || '' }}</span></span></template>
    <template v-if="b" #headExtra>
      <span class="inline-flex gap-1"><Chip :map="BUDGET_STATUS" :k="b.status" /><Chip v-if="b.owned" :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" /></span>
    </template>

    <ErrorBanner :error="q.error.value || act.error.value" :closable="!q.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[220px]" />
    <template v-if="b">
      <div v-if="b.owned" class="hint purple !mt-0 mb-3">{{ bi(OWNED_HELP) }}</div>
      <div v-else-if="livePlans.length" class="hint amber !mt-0 mb-3">{{ t('صُنعت خطة شراء من هذا السيناريو — يحتفظ بتوزيعه ولا تُعدَّل قواعده.', 'A purchase plan was made from this scenario — it keeps its allocation and its rules are not changed.') }}</div>

      <!-- results -->
      <div class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
        <div class="tile"><div class="tile-v">{{ money(b.budgetTotal) }}</div><div class="tile-l">{{ t('الميزانية', 'Budget') }}</div></div>
        <div class="tile"><div class="tile-v">{{ money(b.required) }}</div><div class="tile-l">{{ t('المطلوب', 'Required') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ money(b.funded) }}</div><div class="tile-l">{{ t('المموّل', 'Funded') }} · <span class="num">{{ pct(b.fundedPct) }}</span></div></div>
        <div class="tile amber"><div class="tile-v">{{ money(b.deferred) }}</div><div class="tile-l">{{ t('المؤجّل', 'Deferred') }} · {{ t('غير مستخدم', 'unused') }} <span class="num">{{ money(b.unused) }}</span></div></div>
      </div>
      <ProgressBar class="mb-2" :pct="num(b.fundedPct)" />
      <div v-if="b.counts" class="row wrap mb-3 !gap-1.5 text-[10.5px]">
        <span class="pill !cursor-default">{{ t('احتياجات', 'Requirements') }} <span class="num">{{ b.counts.requirements }}</span></span>
        <span class="pill !cursor-default">{{ t('مموّلة', 'Funded') }} <span class="num">{{ b.counts.funded }}</span></span>
        <span class="pill !cursor-default">{{ t('جزئية', 'Partial') }} <span class="num">{{ b.counts.partial }}</span></span>
        <span class="pill !cursor-default">{{ t('مؤجّلة', 'Deferred') }} <span class="num">{{ b.counts.deferred }}</span></span>
        <span class="pill !cursor-default text-bad">{{ t('حرجة مؤجّلة', 'Critical deferred') }} <span class="num">{{ b.counts.criticalDeferred }}</span></span>
        <span class="pill !cursor-default text-warn">{{ t('معرّضة لنفاد', 'At risk') }} <span class="num">{{ b.counts.atRisk }}</span> · {{ money(b.stockoutUnfunded) }}</span>
        <span v-if="b.allocatedAt" class="text-muted">{{ t('وُزّع', 'Allocated') }} <span class="num">{{ fmtDate(b.allocatedAt) }}</span> · {{ b.allocatedBy }}</span>
      </div>

      <div class="row wrap mb-3 !gap-1.5">
        <Btn v-if="canEdit" size="sm" tone="primary" :loading="act.pending.value" :label="{ ar: 'وزّع الميزانية', en: 'Allocate' }" @click="allocate" />
        <Btn v-if="canEdit && dirty" size="sm" tone="success" :loading="act.pending.value" :label="{ ar: 'حفظ القواعد', en: 'Save rules' }" @click="save" />
        <Btn v-if="canPlan && !planning" size="sm" tone="purple" :label="{ ar: 'اصنع خطة شراء', en: 'Make a purchase plan' }" @click="openPlan" />
        <div class="grow" />
        <button v-for="p in b.plans || []" :key="p.id" type="button" class="pill" @click="nav.patch({ plan: p.id })"><span class="num ltr inline-block">{{ p.number }}</span> · {{ label(PLAN_STATUS, p.status) }}</button>
      </div>

      <div v-if="planning" class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('خطة شراء من هذا السيناريو', 'Purchase plan from this scenario') }}</div>
        <div class="form-grid">
          <TextInput v-model="pf.name" :label="{ ar: 'الاسم', en: 'Name' }" />
          <SelectInput v-model="pf.rfqGrouping" :options="GROUPS" :label="{ ar: 'تجميع طلبات الشراء', en: 'Group purchase requests' }" />
          <DateInput v-model="pf.dateFrom" :label="{ ar: 'تواريخ الطلب من', en: 'Order dates from' }" />
          <DateInput v-model="pf.dateTo" :label="{ ar: 'إلى', en: 'To' }" />
          <TextArea v-model="pf.note" :label="{ ar: 'ملاحظة', en: 'Note' }" full />
        </div>
        <div class="hint !mb-0 mt-2">{{ t('تدخل الخطة كل احتياج مموّل تاريخ طلبه في هذه الفترة ولم يدخل خطة أخرى.', 'The plan takes every funded requirement whose order date is in this window and is not in another plan.') }}</div>
        <div class="row mt-2 !justify-end">
          <Btn size="sm" tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="planning = false" />
          <Btn size="sm" tone="purple" :loading="act.pending.value" :label="{ ar: 'إنشاء الخطة', en: 'Make the plan' }" @click="makePlan" />
        </div>
      </div>

      <!-- rules -->
      <details class="card mb-3 !p-3" :open="b.status !== 'allocated'">
        <summary class="cursor-pointer text-[11.5px] font-extrabold">{{ t('قواعد التوزيع والميزانية الشهرية', 'Allocation rules and monthly budget') }}</summary>
        <div class="form-grid mt-2">
          <TextInput v-model="v.name" :label="{ ar: 'اسم السيناريو', en: 'Scenario name' }" :disabled="!canEdit" />
          <NumberInput v-model="v.urgencyHorizonDays" :min="1" :max="365" :disabled="!canEdit" :label="{ ar: 'أفق الإلحاح (يوم)', en: 'Urgency horizon (days)' }" />
        </div>
        <div class="mb-1 mt-3 text-[10.5px] font-extrabold text-sec">{{ t('أوزان الأولوية (0–100)', 'Priority weights (0–100)') }} · <span class="num" :class="weightSum === 100 ? 'text-ok' : 'text-warn'">Σ {{ weightSum }}</span></div>
        <div v-for="w in WEIGHTS" :key="w.k" class="row mb-1 !gap-2">
          <span class="w-[190px] shrink-0 text-[11px] font-bold">{{ bi(w.l) }}</span>
          <input type="range" min="0" max="100" step="1" class="min-w-0 flex-1" :value="v.weights[w.k]" :disabled="!canEdit" @input="setWeight(w.k, $event.target.value)">
          <NumberInput small center class="!w-[64px]" :model-value="v.weights[w.k]" :min="0" :max="100" :disabled="!canEdit" @update:model-value="(x) => setWeight(w.k, x)" />
        </div>
        <div class="mt-2 grid gap-1.5 md:grid-cols-2">
          <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.criticalFirst" type="checkbox" :disabled="!canEdit"> {{ t('المنتجات الحرجة أولًا', 'Critical products first') }}</label>
          <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.allowPartial" type="checkbox" :disabled="!canEdit"> {{ t('تمويل جزئي مسموح', 'Allow partial funding') }}</label>
          <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.carryDeferred" type="checkbox" :disabled="!canEdit"> {{ t('ترحيل المؤجّل للشهر التالي', 'Carry deferred to the next month') }}</label>
          <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.carryUnused" type="checkbox" :disabled="!canEdit"> {{ t('ترحيل غير المستخدم', 'Carry unused money') }}</label>
        </div>
        <div class="mb-1 mt-3 text-[10.5px] font-extrabold text-sec">{{ t('الميزانية لكل شهر', 'Budget per month') }}</div>
        <div class="le-head row !gap-2 text-[10px] font-extrabold text-muted"><span class="w-[120px]">{{ t('الشهر', 'Month') }}</span><span class="flex-1">{{ t('المبلغ (ر.س)', 'Amount (SAR)') }}</span><span class="w-[40px]" /></div>
        <div v-for="(l, i) in v.lines" :key="i" class="le-row row mb-1 !gap-2">
          <TextInput small class="!w-[120px]" dir="ltr" :label="cap('الشهر', 'Month')" :model-value="l.month" placeholder="YYYY-MM" :disabled="!canEdit" @update:model-value="(x) => (l.month = x)" />
          <NumberInput small field-class="flex-1" :label="cap('المبلغ', 'Amount')" :model-value="l.amount" :min="0" :disabled="!canEdit" @update:model-value="(x) => (l.amount = x ?? 0)" />
          <Btn v-if="canEdit" size="sm" tone="ghost" :label="{ ar: '✕', en: '✕' }" @click="removeMonth(i)" />
        </div>
        <Btn v-if="canEdit && v.lines.length < 36" size="sm" tone="soft" :label="{ ar: '+ شهر', en: '+ Month' }" @click="addMonth" />
        <TextArea v-model="v.note" class="mt-2" :label="{ ar: 'ملاحظة', en: 'Note' }" :disabled="!canEdit" />
      </details>

      <!-- months -->
      <div v-if="(b.lines || []).length" class="card mb-3 !p-3">
        <div class="card-title mb-1.5">{{ t('الأشهر', 'Months') }}</div>
        <DataTable dense :rows="b.lines" :row-key="(r) => r.month" :columns="monthCols" @row-click="(r) => nav.patch({ amonth: nav.get('amonth') === r.month ? null : r.month, apage: null })" />
      </div>

      <!-- allocations -->
      <div v-if="b.status === 'allocated'" class="card !p-3">
        <div class="row wrap mb-2 !gap-2">
          <div class="card-title">{{ t('قرارات التوزيع', 'Allocation decisions') }}</div>
          <div class="grow" />
          <TextInput small type="search" class="w-[180px]" :model-value="as.text.value" :placeholder="{ ar: 'بحث SKU، اسم، مورد…', en: 'Search SKU, name, vendor…' }" @update:model-value="as.type" @enter="as.apply" />
          <SelectInput small class="w-[120px]" :model-value="nav.get('astatus')" :options="options(ALLOC_STATUS)" :placeholder="{ ar: 'كل النتائج', en: 'Every result' }" @update:model-value="(x) => nav.setFilter('astatus', x, 'apage')" />
          <SelectInput small class="w-[110px]" :model-value="nav.get('amonth')" :options="monthOptions" :placeholder="{ ar: 'كل الأشهر', en: 'Every month' }" @update:model-value="(x) => nav.setFilter('amonth', x, 'apage')" />
        </div>
        <ErrorBanner :error="allocs.error.value" :closable="false" />
        <DataTable :columns="allocCols" :paged="allocs.data.value" :loading="allocs.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort compact
                   :empty-text="{ ar: 'لا قرارات تطابق الفلتر.', en: 'No decision matches the filter.' }"
                   @sort="(s) => nav.patch({ asort: s.key, aorder: s.order, apage: null })" @page="(p) => nav.patch({ apage: p > 1 ? p : null })">
          <template #cell-sku="{ row }">
            <div class="min-w-0">
              <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
              <div class="cell-sub"><span class="num ltr inline-block">{{ row.sku }}</span> · {{ row.warehouseCode }}<template v-if="row.vendorName"> · <span dir="auto">{{ row.vendorName }}</span></template><template v-if="row.criticality && row.criticality !== 'normal'"> · {{ label(CRITICALITY, row.criticality) }}</template></div>
            </div>
          </template>
          <template #cell-score="{ row }"><span class="num" :title="scoreTip(row)">{{ row.score == null ? '—' : fmtNum(row.score, 1) }}</span></template>
          <template #cell-status="{ row }">
            <span class="inline-flex min-w-0 flex-col items-start gap-0.5">
              <span class="inline-flex flex-wrap gap-1"><Chip small :map="ALLOC_STATUS" :k="row.status" /><span v-if="row.mustFund" class="text-[9px] font-extrabold text-bad">{{ t('إلزامي', 'must fund') }}</span><span v-if="row.atRisk" class="text-[9px] font-extrabold text-warn">{{ t('خطر نفاد', 'at risk') }}</span></span>
              <span class="num text-[9px] text-muted">{{ qty(row.fundedQty) }} / {{ qty(row.requiredQty) }}<template v-if="row.carriedFrom"> · {{ t('من', 'from') }} {{ row.carriedFrom }}</template></span>
              <span v-if="row.reason" class="truncate text-[9px] text-sec" :title="t(row.reason.ar, row.reason.en)">{{ t(row.reason.ar, row.reason.en) }}</span>
            </span>
          </template>
        </DataTable>
      </div>
      <div v-else class="empty">{{ t('لم يوزَّع السيناريو بعد — اضغط «وزّع الميزانية» (بعد حساب التشغيل).', 'The scenario is not allocated yet — press "Allocate" (after the run is computed).') }}</div>
    </template>
  </Drawer>
</template>
