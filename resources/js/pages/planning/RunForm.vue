<script setup>
// The parameters of a forecast run — new (POST /planning/runs) or changed (PATCH /planning/runs/{id}, draft or computed
// only; a computed run goes back to draft). Every key of RunsService::FIELDS, grouped: windows · method · history ·
// safety and lead time · products · warehouses · customers · output and budget. An edit sends only what changed.
//   <RunForm :open="open" :run="runOrNull" @close="…" @done="(run) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, Field, NumberInput, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { t } from '@/i18n';
import IdsPicker from './IdsPicker.vue';
import { METHOD, todayIso, usePlanningLookups } from './planning';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** The run to edit (GET /planning/runs/{id}); null = a new one. */
  run: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);

const lk = usePlanningLookups();
const act = useAction({ invalidate: ['planning'] });
const edit = computed(() => !!props.run);

// ---- the defaults of a new run (RunsService::defaults)
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (s, n) => { const d = new Date(`${s}T00:00:00`); d.setDate(d.getDate() + n); return iso(d); };
function defaults() {
  const today = todayIso();
  const ms = new Date(`${today.slice(0, 7)}-01T00:00:00`); ms.setDate(ms.getDate() + 62);
  const end = new Date(ms.getFullYear(), ms.getMonth() + 1, 0);
  return {
    name: '', note: '', anchorDate: today, historyFrom: addDays(today, -365), historyTo: addDays(today, -1), forecastFrom: today, forecastTo: iso(end), periodKind: 'month',
    method: 'wma', wmaWindow: 3, seasonalScale: true, growthPct: 0, demandNetting: 'net', historySource: 'delivery', includeReturns: true, includeOdoo: true, includeOps: true,
    safetyMethod: 'days', safetyDays: 7, serviceLevel: '95', targetCoverDays: 30, useVendorLeadTime: true, defaultLeadDays: 7, planningBufferDays: 0, qtyMultipleSource: 'one',
    productSelection: 'moving', productIds: [], categoryIds: [], warehouseIds: lk.warehouses.value.map((w) => String(w.id)), customerMode: 'none', customerIds: [], excludeCustomerIds: [],
    includeRollup: true, rollupMethod: 'pooled', includeHistorySheet: false, cadenceMinOrders: 3, cadenceIntervalWindow: 6, cadenceLapseFactor: 3, cadenceUseTrend: true,
    cadenceTrendCapPct: 25, cadenceUseSeasonality: false, cadenceFallback: 'estimate', budgetMonthlyAmount: 0,
  };
}
const KEYS = Object.keys(defaults());
const LISTS = ['productIds', 'categoryIds', 'warehouseIds', 'customerIds', 'excludeCustomerIds'];
function fromRun(r) {
  const p = r.parameters || {};
  const out = defaults();
  for (const k of KEYS) {
    if (p[k] === undefined || p[k] === null) { if (k === 'name' || k === 'note') out[k] = ''; continue; }
    out[k] = LISTS.includes(k) ? (p[k] || []).map(String) : k === 'serviceLevel' ? String(p[k]) : typeof out[k] === 'number' ? Number(p[k]) : typeof out[k] === 'boolean' ? !!p[k] : p[k];
  }
  return out;
}
const v = reactive(defaults());
let initial = defaults();
watch(() => [props.open, props.run], () => {
  if (!props.open) return;
  initial = props.run ? fromRun(props.run) : defaults();
  Object.assign(v, JSON.parse(JSON.stringify(initial)));
  act.clearError();
}, { immediate: true });
// a new run takes every active warehouse once the lookups arrive
watch(() => lk.warehouses.value, (w) => { if (props.open && !edit.value && !v.warehouseIds.length) v.warehouseIds = w.map((x) => String(x.id)); });

const toggleWh = (id) => { v.warehouseIds = v.warehouseIds.includes(id) ? v.warehouseIds.filter((x) => x !== id) : [...v.warehouseIds, id]; };

const body = computed(() => {
  const out = {};
  for (const k of KEYS) {
    let val = v[k];
    if ((k === 'name' || k === 'note') && val === '') val = null;
    if (typeof initial[k] === 'number' && (val === null || val === '')) val = initial[k];
    if (edit.value && JSON.stringify(val) === JSON.stringify(k === 'name' || k === 'note' ? initial[k] || null : initial[k])) continue;
    out[k] = val;
  }
  return out;
});
async function save() {
  const r = await act.run(() => (edit.value ? api.patch(`/planning/runs/${props.run.id}`, body.value) : api.postIdempotent('/planning/runs', body.value)),
    { success: (x) => (edit.value ? t('حُفظت المعطيات — يعود التشغيل مسودة حتى يُحسب', 'Saved — the run is a draft until computed') : t(`أُنشئ ${x?.number || ''}`, `Created ${x?.number || ''}`)) });
  if (r) emit('done', r);
}

const o = (pairs) => pairs.map(([vv, ar, en]) => ({ v: vv, l: { ar, en } }));
const METHODS = computed(() => (lk.data.value?.methods || ['average', 'wma', 'seasonal', 'cadence']).map((m) => ({ v: m, l: { ar: METHOD[m]?.ar || m, en: METHOD[m]?.en || m } })));
const PERIOD = o([['month', 'شهري', 'Monthly'], ['week', 'أسبوعي', 'Weekly']]);
const NETTING = o([['net', 'خصم المحجوز من التوقع', 'Net booked orders off the forecast'], ['gross', 'التوقع والمحجوز معًا', 'Forecast plus booked'], ['ignore', 'تجاهل المحجوز', 'Ignore booked orders']]);
const HSOURCE = o([['delivery', 'ما سُلِّم فعلًا', 'What was delivered'], ['sale_order', 'ما طُلب في أوامر البيع', 'What was ordered']]);
const SAFETY = o([['days', 'أيام طلب', 'Days of demand'], ['service_level', 'مستوى خدمة', 'Service level']]);
const LEVELS = o([['90', '90%', '90%'], ['95', '95%', '95%'], ['98', '98%', '98%'], ['99', '99%', '99%']]);
const MULTIPLE = o([['one', 'وحدة واحدة', 'Single unit'], ['pack', 'كرتون المنتج', 'Product pack']]);
const SELECTION = o([['moving', 'المنتجات المتحركة', 'Moving products'], ['manual', 'تحديد يدوي', 'Manual selection'], ['all', 'كل المنتجات', 'All products']]);
const CMODE = o([['none', 'بلا تفصيل عملاء', 'No customer detail'], ['filter', 'عملاء محددون فقط', 'Selected customers only'], ['split', 'تفصيل لكل عميل', 'Split by customer']]);
const ROLLUP = o([['pooled', 'توقع مجمّع للمستودع', 'Pooled warehouse forecast'], ['sum', 'مجموع توقعات العملاء', 'Sum of customer forecasts']]);
const CFALLBACK = o([['estimate', 'تقدير بالمتوسط المرجّح', 'Estimate with WMA'], ['ignore', 'تجاهل العميل', 'Leave the customer out']]);
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <Drawer :open="open" :width="720" :title="edit ? { ar: `معطيات ${run.number}`, en: `Parameters of ${run.number}` } : { ar: 'تشغيل توقع جديد', en: 'New forecast run' }"
          :sub="edit && run.status === 'computed' ? { ar: 'حفظ التعديل يُسقط نتائج الحساب ويعيد التشغيل مسودة', en: 'Saving drops the computed results and takes the run back to draft' } : null"
          @close="emit('close')">
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />

    <div class="card-title mb-1.5">{{ t('التعريف', 'Identity') }}</div>
    <div class="form-grid mb-4">
      <TextInput v-model="v.name" :label="ph('الاسم', 'Name')" :placeholder="ph('مثال: خطة الربع القادم', 'e.g. Next quarter plan')" />
      <TextArea v-model="v.note" :label="ph('ملاحظة', 'Note')" full />
    </div>

    <div class="card-title mb-1.5">{{ t('النوافذ الزمنية', 'Windows') }}</div>
    <div class="form-grid mb-1">
      <DateInput v-model="v.anchorDate" :label="ph('تاريخ الإسناد (يُحسب منه)', 'Anchor day (computed from)')" required />
      <SelectInput v-model="v.periodKind" :options="PERIOD" :label="ph('طول الفترة', 'Period')" />
      <DateInput v-model="v.historyFrom" :label="ph('السجل من', 'History from')" required />
      <DateInput v-model="v.historyTo" :label="ph('السجل إلى', 'History to')" required />
      <DateInput v-model="v.forecastFrom" :label="ph('التوقع من', 'Forecast from')" required />
      <DateInput v-model="v.forecastTo" :label="ph('التوقع إلى', 'Forecast to')" required />
    </div>
    <div class="hint !mt-0 mb-4">{{ t('السجل ينتهي قبل بداية التوقع، ولا يزيد على خمس سنوات؛ التوقع لا يزيد على سنتين؛ تاريخ الإسناد لا يتجاوز بداية التوقع.', 'History ends before the forecast starts and is at most five years; the forecast is at most two years; the anchor day is not after the forecast starts.') }}</div>

    <div class="card-title mb-1.5">{{ t('طريقة التوقع', 'Forecast method') }}</div>
    <div class="form-grid mb-4">
      <SelectInput v-model="v.method" :options="METHODS" :label="ph('الطريقة', 'Method')" />
      <NumberInput v-if="v.method === 'wma'" v-model="v.wmaWindow" :min="1" :max="24" :label="ph('نافذة المتوسط المرجّح (فترات)', 'WMA window (periods)')" />
      <NumberInput v-model="v.growthPct" :min="-90" :max="500" :label="ph('نمو متوقع %', 'Growth %')" />
      <SelectInput v-model="v.demandNetting" :options="NETTING" :label="ph('الطلبات المحجوزة', 'Booked orders')" />
      <label v-if="v.method === 'seasonal'" class="row !gap-2 text-[11px] font-bold"><input v-model="v.seasonalScale" type="checkbox"> {{ t('تحجيم الموسم على مستوى هذا العام', "Scale last year's season to this year's level") }}</label>
      <template v-if="v.method === 'cadence'">
        <NumberInput v-model="v.cadenceMinOrders" :min="2" :max="50" :label="ph('أقل عدد طلبات لنمط', 'Min orders for a pattern')" />
        <NumberInput v-model="v.cadenceIntervalWindow" :min="1" :max="50" :label="ph('آخر كم فاصل يُحسب', 'Intervals counted')" />
        <NumberInput v-model="v.cadenceLapseFactor" :min="1" :max="20" :label="ph('معامل الانقطاع (× متوسط الفاصل)', 'Lapse factor (× mean interval)')" />
        <NumberInput v-model="v.cadenceTrendCapPct" :min="0" :max="200" :label="ph('سقف الاتجاه %', 'Trend cap %')" />
        <SelectInput v-model="v.cadenceFallback" :options="CFALLBACK" :label="ph('عميل بطلبات قليلة', 'Customer with few orders')" />
        <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.cadenceUseTrend" type="checkbox"> {{ t('استخدام اتجاه الكميات', 'Use the quantity trend') }}</label>
        <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.cadenceUseSeasonality" type="checkbox"> {{ t('استخدام الموسمية', 'Use seasonality') }}</label>
      </template>
    </div>

    <div class="card-title mb-1.5">{{ t('سجل الطلب', 'Demand history') }}</div>
    <div class="form-grid mb-4">
      <SelectInput v-model="v.historySource" :options="HSOURCE" :label="ph('مصدر السجل', 'History source')" />
      <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.includeReturns" type="checkbox"> {{ t('خصم مرتجعات العملاء', 'Net customer returns') }}</label>
      <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.includeOps" type="checkbox"> {{ t('سجل OPS', 'OPS history') }}</label>
      <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.includeOdoo" type="checkbox"> {{ t('سجل أودو', 'Odoo history') }}</label>
    </div>

    <div class="card-title mb-1.5">{{ t('المخزون الآمن ومدة التوريد', 'Safety stock and lead time') }}</div>
    <div class="form-grid mb-4">
      <SelectInput v-model="v.safetyMethod" :options="SAFETY" :label="ph('طريقة المخزون الآمن', 'Safety method')" />
      <NumberInput v-if="v.safetyMethod === 'days'" v-model="v.safetyDays" :min="0" :max="365" :label="ph('أيام الأمان', 'Safety days')" />
      <SelectInput v-else v-model="v.serviceLevel" :options="LEVELS" :label="ph('مستوى الخدمة', 'Service level')" />
      <NumberInput v-model="v.targetCoverDays" :min="0" :max="730" :label="ph('أيام التغطية المستهدفة', 'Target cover days')" />
      <NumberInput v-model="v.defaultLeadDays" :min="0" :max="365" :label="ph('مدة التوريد الافتراضية (يوم)', 'Default lead days')" />
      <NumberInput v-model="v.planningBufferDays" :min="0" :max="180" :label="ph('هامش تخطيط (يوم)', 'Planning buffer (days)')" />
      <SelectInput v-model="v.qtyMultipleSource" :options="MULTIPLE" :label="ph('تقريب الكمية إلى', 'Round quantities to')" />
      <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.useVendorLeadTime" type="checkbox"> {{ t('استخدام مدة توريد المورد إن وُجدت', "Use the supplier's lead time when known") }}</label>
    </div>

    <div class="card-title mb-1.5">{{ t('المنتجات', 'Products') }}</div>
    <div class="form-grid mb-4">
      <PillChoice v-model="v.productSelection" :options="SELECTION" :label="ph('أي منتجات', 'Which products')" full />
      <template v-if="v.productSelection === 'manual'">
        <IdsPicker v-model="v.productIds" kind="product" :label="ph('المنتجات', 'Products')" />
        <IdsPicker v-model="v.categoryIds" kind="category" :label="ph('الفئات', 'Categories')" />
      </template>
    </div>

    <div class="card-title mb-1.5">{{ t('المستودعات', 'Warehouses') }}</div>
    <Field full :label="ph('المستودعات المحسوبة (واحد على الأقل)', 'Warehouses computed (at least one)')" class="mb-4">
      <div class="row wrap !gap-1.5">
        <button v-for="w in lk.warehouses.value" :key="w.id" type="button" class="pill" :class="{ active: v.warehouseIds.includes(String(w.id)) }" @click="toggleWh(String(w.id))">
          <span class="num ltr inline-block">{{ w.code }}</span> <span dir="auto">{{ t(w.nameAr || w.nameEn, w.nameEn || w.nameAr) }}</span>
        </button>
        <span v-if="!lk.warehouses.value.length" class="text-faint text-[11px]">{{ t('لا مستودعات نشطة', 'No active warehouse') }}</span>
      </div>
    </Field>

    <div class="card-title mb-1.5">{{ t('العملاء', 'Customers') }}</div>
    <div class="form-grid mb-4">
      <PillChoice v-model="v.customerMode" :options="CMODE" :label="ph('تفصيل العملاء', 'Customer mode')" full />
      <IdsPicker v-if="v.customerMode === 'filter'" v-model="v.customerIds" kind="customer" :label="ph('العملاء المحسوب طلبهم', 'Customers counted')" />
      <IdsPicker v-model="v.excludeCustomerIds" kind="customer" :label="ph('عملاء مستثنون', 'Excluded customers')" />
      <template v-if="v.customerMode === 'split'">
        <SelectInput v-model="v.rollupMethod" :options="ROLLUP" :label="ph('إجمالي المستودع', 'Warehouse total')" />
        <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.includeRollup" type="checkbox"> {{ t('إظهار سطر إجمالي المستودع', 'Include the warehouse roll-up') }}</label>
      </template>
    </div>

    <div class="card-title mb-1.5">{{ t('الميزانية والتصدير', 'Budget and export') }}</div>
    <div class="form-grid mb-2">
      <NumberInput v-model="v.budgetMonthlyAmount" :min="0" :label="ph('ميزانية الشراء الشهرية (ر.س)', 'Monthly purchase budget (SAR)')" :hint="ph('أكبر من صفر: يُنشأ سيناريو ميزانية التشغيل ويوزّع بعد الحساب', 'Above zero: the run’s own budget scenario is made and allocated after computing')" />
      <label class="row !gap-2 text-[11px] font-bold"><input v-model="v.includeHistorySheet" type="checkbox"> {{ t('ورقة السجل في ملف Excel', 'History sheet in the Excel file') }}</label>
    </div>

    <template #footer>
      <Btn tone="ghost" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      <Btn tone="primary" :loading="act.pending.value" :disabled="!v.warehouseIds.length" :label="edit ? { ar: 'حفظ المعطيات', en: 'Save parameters' } : { ar: 'إنشاء التشغيل', en: 'Create run' }" @click="save" />
    </template>
  </Drawer>
</template>
