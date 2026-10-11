<script setup>
// Fixed assets register: list (cost · accumulated depreciation · net book value), register form (vehicles first),
// monthly depreciation run, disposal.
import { computed, ref, watch } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, DateInput, ErrorBanner, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ActionDrawer from '@/pages/procurement/ActionDrawer.vue';
import FilterBar from '@/pages/procurement/FilterBar.vue';
import { ASSET_STATUS_LABELS, pname, todayIso, useBankOptions, useSupplierOptions } from './shared';

const auth = useAuth();
const q = ref('');
const page = ref(1);
watch(q, () => { page.value = 1; });
const list = useList('/finance/assets', () => ({ q: q.value, page: page.value, pageSize: 25 }));
const act = useAction({ invalidate: ['finance'] });
const form = ref(false);
const { options: bankOpts } = useBankOptions();
const { options: supplierOpts } = useSupplierOptions();
const vehicles = useList('/transport/vehicles', { pageSize: 300 }, { enabled: () => form.value });
const vehicleOpts = computed(() => (vehicles.data.value?.items || []).map((v) => ({ v: v.code, l: `${v.code} · ${v.plateAr}` })));
const FUNDED = [['opening', { ar: 'أصل قائم قبل بدء الدفاتر (رصيد افتتاحي)', en: 'Existing asset (opening balance)' }], ['bank', { ar: 'مشترى — سُدد من بنك', en: 'Purchased — paid from bank' }], ['supplier', { ar: 'مشترى — على حساب مورد', en: 'Purchased — on a supplier account' }]];
const CATS = [['vehicle', { ar: 'مركبة', en: 'Vehicle' }], ['equipment', { ar: 'معدات', en: 'Equipment' }], ['other', { ar: 'أخرى', en: 'Other' }]];
const blank = () => ({ nameAr: '', category: 'vehicle', vehicle: '', acquiredOn: todayIso(), cost: null, salvageValue: 0, usefulLifeMonths: 60, fundedBy: 'opening', bankAccount: 'BANK', supplier: '', accumulated: null, depreciatedThrough: '', notes: '' });
const f = ref(blank());
watch(form, (o) => { if (o) { f.value = blank(); act.clearError(); } });
const ok = computed(() => f.value.nameAr.trim().length > 0 && Number(f.value.cost) > 0 && Number(f.value.usefulLifeMonths) > 0 && Number(f.value.salvageValue || 0) < Number(f.value.cost) && (!Number(f.value.accumulated) || f.value.depreciatedThrough));
async function submit() {
  const v = f.value;
  const body = { nameAr: v.nameAr.trim(), category: v.category, vehicle: v.vehicle || undefined, acquiredOn: v.acquiredOn, cost: Number(v.cost), salvageValue: Number(v.salvageValue) || 0, usefulLifeMonths: Number(v.usefulLifeMonths), fundedBy: v.fundedBy,
    bankAccount: v.fundedBy === 'bank' ? v.bankAccount : undefined, supplier: v.fundedBy === 'supplier' ? v.supplier : undefined, accumulated: Number(v.accumulated) || undefined, depreciatedThrough: v.depreciatedThrough || undefined, notes: v.notes || undefined };
  const r = await act.run(() => api.postIdempotent('/finance/assets', body), { success: (a) => t(`سُجل الأصل ${a.code}`, `Asset ${a.code} registered`) });
  if (r) { form.value = false; list.refetch(); }
}
async function depreciate() {
  const period = await ask({ title: { ar: 'تشغيل الإهلاك الشهري', en: 'Run monthly depreciation' }, sub: { ar: 'يرحّل قسطًا ثابتًا لكل أصل قائم حتى الفترة (مرة واحدة لكل أصل وشهر)', en: 'Posts one straight-line month per active asset up to the period (once per asset and month)' }, label: { ar: 'الفترة (YYYY-MM)', en: 'Period (YYYY-MM)' }, value: new Date().toISOString().slice(0, 7), required: true, okLabel: { ar: 'تشغيل', en: 'Run' } });
  if (!period) return;
  const r = await act.run(() => api.postIdempotent('/finance/assets/depreciate', { period }), { success: (d) => t(`رُحّل ${d.posted.length} قسط إهلاك — ${fmtMoney(d.total)} ر.س`, `${d.posted.length} depreciation entries — ${fmtMoney(d.total)} SAR`) });
  if (r) list.refetch();
}
async function dispose(a) {
  const reason = await ask({ title: { ar: `استبعاد ${a.code}`, en: `Dispose ${a.code}` }, sub: { ar: 'يُقفل التكلفة ومجمع الإهلاك ويُثبت الربح/الخسارة (بلا متحصلات هنا؛ البيع بمتحصلات من واجهة API)', en: 'Closes cost and accumulated depreciation and books the gain/loss' }, label: { ar: 'السبب', en: 'Reason' }, required: true, okLabel: { ar: 'استبعاد', en: 'Dispose' } });
  if (!reason) return;
  const r = await act.run(() => api.postIdempotent(`/finance/assets/${a.code}/dispose`, { reason }), { success: t(`استُبعد ${a.code}`, `${a.code} disposed`) });
  if (r) list.refetch();
}
const columns = [
  { key: 'code', header: { ar: 'الأصل', en: 'Asset' }, width: '120px', kind: 'id' },
  { key: 'name', header: { ar: 'الاسم', en: 'Name' }, width: 'minmax(160px,1.5fr)', kind: 'name', value: (r) => pname(r) },
  { key: 'vehicle', header: { ar: 'المركبة', en: 'Vehicle' }, width: '110px', class: 'num text-[10px]', value: (r) => r.vehicle?.code || '—' },
  { key: 'acquiredOn', header: { ar: 'الاقتناء', en: 'Acquired' }, width: '90px', kind: 'date', value: (r) => fmtDateOnly(r.acquiredOn) },
  { key: 'cost', header: { ar: 'التكلفة', en: 'Cost' }, width: '100px', kind: 'num', value: (r) => fmtMoney(r.cost) },
  { key: 'accumulatedDepreciation', header: { ar: 'مجمع الإهلاك', en: 'Accum. dep.' }, width: '100px', kind: 'num', class: 'muted', value: (r) => fmtMoney(r.accumulatedDepreciation) },
  { key: 'netBookValue', header: { ar: 'صافي القيمة الدفترية', en: 'Net book value' }, width: '110px', kind: 'num', value: (r) => fmtMoney(r.netBookValue) },
  { key: 'life', header: { ar: 'العمر (شهر)', en: 'Life (mo)' }, width: '80px', kind: 'num', value: (r) => fmtNum(r.usefulLifeMonths) },
  { key: 'status', header: '', width: '80px' },
  { key: 'act', header: '', width: '90px' },
];
</script>

<template>
  <FilterBar v-model="q">
    <span class="grow" />
    <Btn v-if="auth.can('asset.manage')" size="sm" tone="soft" :loading="act.pending.value" :label="{ ar: 'تشغيل الإهلاك الشهري', en: 'Run depreciation' }" @click="depreciate" />
    <Btn v-if="auth.can('asset.manage')" size="sm" tone="primary" :label="{ ar: '+ تسجيل أصل', en: '+ Register asset' }" @click="form = true" />
  </FilterBar>
  <ErrorBanner :error="list.error.value || act.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا أصول مسجلة — سجّل المركبات بتكلفة اقتنائها الحقيقية لتُهلك شهريًا وتظهر في المركز المالي.', en: 'No assets yet — register vehicles at their real acquisition cost so they depreciate monthly and appear on the balance sheet.' }" @page="page = $event">
      <template #cell-status="{ row }"><Chip :map="ASSET_STATUS_LABELS" :k="row.status" small /></template>
      <template #cell-act="{ row }"><Btn v-if="row.status === 'active' && auth.can('asset.manage')" size="sm" tone="dangerOutline" class="!h-7 !text-[9px]" :label="{ ar: 'استبعاد', en: 'Dispose' }" @click.stop="dispose(row)" /></template>
    </DataTable>
  </div>
  <div class="hint">{{ t('الإهلاك بالقسط الثابت شهريًا = (التكلفة − القيمة التخريدية) ÷ العمر الإنتاجي، يُرحَّل على مركز تكلفة المركبة فيدخل في تكلفة الأسطول. يُشغَّل آليًا أول كل شهر (scm:finance-depreciate) أو من الزر.', 'Straight-line monthly depreciation = (cost − salvage) ÷ useful life, posted on the vehicle cost center so it enters fleet costs. Runs automatically on the 1st (scm:finance-depreciate) or from the button.') }}</div>

  <ActionDrawer :open="form" :width="600" :title="{ ar: 'تسجيل أصل ثابت', en: 'Register fixed asset' }" :sub="{ ar: 'لا يُسجَّل أصل بقيمة مفترضة — التكلفة والعمر من عقد الشراء', en: 'No assumed values — cost and life from the purchase contract' }" :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :submit-label="{ ar: 'تسجيل الأصل', en: 'Register' }" @submit="submit" @close="form = false" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.nameAr" :label="{ ar: 'اسم الأصل', en: 'Asset name' }" required full />
      <SelectInput v-model="f.category" :label="{ ar: 'الفئة', en: 'Category' }" :options="CATS" />
      <SelectInput v-model="f.vehicle" :label="{ ar: 'المركبة المرتبطة', en: 'Linked vehicle' }" :options="vehicleOpts" :placeholder="{ ar: '—', en: '—' }" />
      <DateInput v-model="f.acquiredOn" :label="{ ar: 'تاريخ الاقتناء', en: 'Acquired on' }" required />
      <NumberInput v-model="f.cost" :label="{ ar: 'تكلفة الاقتناء ر.س', en: 'Acquisition cost SAR' }" required :min="0" />
      <NumberInput v-model="f.salvageValue" :label="{ ar: 'القيمة التخريدية ر.س', en: 'Salvage value SAR' }" :min="0" />
      <NumberInput v-model="f.usefulLifeMonths" :label="{ ar: 'العمر الإنتاجي (أشهر)', en: 'Useful life (months)' }" required :min="1" :max="600" />
      <SelectInput v-model="f.fundedBy" :label="{ ar: 'التمويل', en: 'Funding' }" :options="FUNDED" full />
      <SelectInput v-if="f.fundedBy === 'bank'" v-model="f.bankAccount" :label="{ ar: 'من حساب', en: 'From account' }" :options="bankOpts" />
      <SelectInput v-else-if="f.fundedBy === 'supplier'" v-model="f.supplier" :label="{ ar: 'المورد', en: 'Supplier' }" :options="supplierOpts" :placeholder="{ ar: '— اختر —', en: '— select —' }" />
      <template v-else>
        <NumberInput v-model="f.accumulated" :label="{ ar: 'مجمع إهلاك سابق ر.س', en: 'Prior accumulated depreciation' }" :min="0" />
        <DateInput v-model="f.depreciatedThrough" :label="{ ar: 'آخر شهر أُهلك', en: 'Depreciated through' }" />
      </template>
      <TextArea v-model="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" :rows="2" full />
    </div>
  </ActionDrawer>
</template>
