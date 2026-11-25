<script setup>
// What planning needs a person to say, never guesses (forecast.manage to change; reading is open to the page):
//   maps      which OPS warehouse each Odoo warehouse is — GET /planning/warehouse-maps · PUT /planning/warehouse-maps/{odooId} { warehouseId | null }
//   product   how critical a product is  — GET /planning/attributes/product?criticality&q&page · PUT /planning/attributes/product/{id} { criticality }
//   customer  how critical a customer is — same on /customer
// Keys: set=maps|product|customer · sq · scrit · spage. Without a search the lists show only what a person set.
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Chip, DataTable, ErrorBanner, SelectInput, Tabs, TextInput } from '@/components';
import { fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { CRITICALITY, nameOf, options, usePlanningLookups, usePlanningNav, useSearchParam } from './planning';

const auth = useAuth();
const nav = usePlanningNav();
const lk = usePlanningLookups();
const canEdit = computed(() => auth.can('forecast.manage'));
const set = computed(() => (['maps', 'product', 'customer'].includes(nav.get('set')) ? nav.get('set') : 'maps'));
const SETS = computed(() => [
  { k: 'maps', label: { ar: 'ربط مستودعات أودو', en: 'Odoo warehouse mapping' }, badge: lk.unmapped.value || null },
  { k: 'product', label: { ar: 'أهمية المنتجات', en: 'Product criticality' } },
  { k: 'customer', label: { ar: 'أهمية العملاء', en: 'Customer criticality' } },
]);
const act = useAction({ invalidate: ['planning'] });

// ---- warehouse maps
const maps = useGet(() => (set.value === 'maps' ? '/planning/warehouse-maps' : null));
const mapFilter = ref('');
const mapRows = computed(() => {
  const s = mapFilter.value.trim().toLowerCase();
  const rows = maps.data.value?.items || [];
  return s ? rows.filter((r) => [r.name, r.code, r.odooId, r.warehouse?.code].some((x) => String(x ?? '').toLowerCase().includes(s))) : rows;
});
async function mapTo(row, warehouseId) {
  await act.run(() => api.put(`/planning/warehouse-maps/${row.odooId}`, { warehouseId: warehouseId || null }),
    { success: warehouseId ? t(`رُبط «${row.name}» بـ ${lk.whCode(warehouseId)}`, `"${row.name}" mapped to ${lk.whCode(warehouseId)}`) : t('أُلغي الربط', 'Mapping removed') });
}
const mapCols = [
  { key: 'odooId', header: { ar: 'رقم أودو', en: 'Odoo id' }, width: '80px', kind: 'id' },
  { key: 'name', header: { ar: 'مستودع أودو', en: 'Odoo warehouse' }, width: 'minmax(160px,1.4fr)' },
  { key: 'code', header: { ar: 'الرمز', en: 'Code' }, width: '80px', kind: 'id' },
  { key: 'warehouse', header: { ar: 'مستودع OPS', en: 'OPS warehouse' }, width: 'minmax(200px,1.2fr)', bare: true },
  { key: 'mappedBy', header: { ar: 'ربطه', en: 'Mapped by' }, width: '150px', value: (r) => (r.mappedBy ? `${r.mappedBy} · ${fmtDate(r.mappedAt)}` : '—') },
];

// ---- criticality
const s = useSearchParam(nav, 'sq', 'spage');
const attrs = useList(() => (set.value === 'maps' ? null : `/planning/attributes/${set.value}`), () => ({
  q: nav.get('sq') || undefined, criticality: nav.get('scrit') || undefined, page: nav.pageOf('spage'), pageSize: 50,
}));
async function setCrit(row, criticality) {
  if (!criticality || criticality === row.criticality) return;
  await act.run(() => api.put(`/planning/attributes/${set.value}/${row.id}`, { criticality }), { success: t(`${row.code}: ${CRITICALITY[criticality].ar}`, `${row.code}: ${CRITICALITY[criticality].en}`) });
}
const attrCols = computed(() => [
  { key: 'code', header: set.value === 'product' ? 'SKU' : { ar: 'الرمز', en: 'Code' }, width: '120px', kind: 'id' },
  { key: 'name', header: { ar: 'الاسم', en: 'Name' }, width: 'minmax(200px,2fr)', value: (r) => nameOf(r) },
  { key: 'criticality', header: { ar: 'الأهمية', en: 'Criticality' }, width: '170px', bare: true },
  { key: 'updatedBy', header: { ar: 'حدّدها', en: 'Set by' }, width: '120px', value: (r) => (r.set ? r.updatedBy || '—' : t('افتراضي', 'default')) },
]);
const ph = (ar, en) => ({ ar, en });
</script>

<template>
  <Tabs :model-value="set" :tabs="SETS" variant="sm" @update:model-value="(k) => nav.patch({ set: k === 'maps' ? null : k, sq: null, scrit: null, spage: null })" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="!canEdit" class="hint !mt-0 mb-2">{{ t('للعرض فقط — التعديل يحتاج صلاحية إدارة التوقع.', 'Read only — changing needs the forecast management permission.') }}</div>

  <template v-if="set === 'maps'">
    <div class="hint !mt-0 mb-2">{{ t('سجل مستودع أودو غير المربوط يُستبعد من كل تشغيل ويُعدّ في تشخيصاته — لا يُخمَّن أبدًا. كل مستودع OPS يُربط بمستودع أودو واحد.', 'The history of an unmapped Odoo warehouse is left out of every run and counted in its diagnostics — it is never guessed. Each OPS warehouse maps to one Odoo warehouse.') }}</div>
    <div class="row wrap mb-2.5">
      <TextInput small type="search" class="w-[240px]" :model-value="mapFilter" :placeholder="ph('بحث بالاسم أو الرمز…', 'Search name or code…')" @update:model-value="(x) => (mapFilter = x)" />
      <span v-if="maps.data.value" class="text-[11px] font-bold" :class="maps.data.value.unmapped ? 'text-warn' : 'text-ok'">{{ t(`غير مربوط: ${maps.data.value.unmapped}`, `Unmapped: ${maps.data.value.unmapped}`) }}</span>
    </div>
    <ErrorBanner :error="maps.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="mapCols" :rows="mapRows" :loading="maps.isLoading.value" :row-key="(r) => r.odooId" :page-size="25"
                 :empty-text="ph('لا مستودعات أودو في النسخة المحلية.', 'No Odoo warehouse in the local copy.')">
        <template #cell-name="{ row }"><span class="cell-name truncate" dir="auto">{{ row.name }}</span></template>
        <template #cell-warehouse="{ row }">
          <SelectInput small class="w-full" :model-value="row.warehouse ? String(row.warehouse.id) : ''" :options="lk.warehouseOptions.value" :disabled="!canEdit || act.pending.value"
                       :placeholder="ph('— غير مربوط —', '— not mapped —')" @update:model-value="(x) => mapTo(row, x)" />
        </template>
      </DataTable>
    </div>
  </template>

  <template v-else>
    <div class="hint !mt-0 mb-2">{{ t('بلا تحديد = عادي. توزيع الميزانية يموّل المنتجات الحرجة أولًا ويزن العملاء بأهميتهم. بلا بحث تظهر فقط القيم المحدَّدة؛ البحث يصل إلى الكل.', 'Not set = normal. Budget allocation funds critical products first and weighs customers by it. Without a search only what was set is listed; a search reaches everything.') }}</div>
    <div class="row wrap mb-2.5">
      <TextInput small type="search" class="w-[240px]" :model-value="s.text.value" :placeholder="set === 'product' ? ph('بحث SKU أو اسم…', 'Search SKU or name…') : ph('بحث رمز أو اسم…', 'Search code or name…')" @update:model-value="s.type" @enter="s.apply" />
      <SelectInput small class="w-[130px]" :model-value="nav.get('scrit')" :options="options(CRITICALITY)" :placeholder="ph('أي أهمية', 'Any criticality')" @update:model-value="(x) => nav.setFilter('scrit', x, 'spage')" />
    </div>
    <ErrorBanner :error="attrs.error.value" :closable="false" />
    <div class="card">
      <DataTable :columns="attrCols" :paged="attrs.data.value" :loading="attrs.isFetching.value" :row-key="(r) => r.id"
                 :empty-text="nav.get('sq') || nav.get('scrit') ? ph('لا نتائج.', 'No result.') : ph('لم تُحدَّد أهمية لأي سجل بعد — ابحث لتحديدها.', 'No criticality set yet — search to set one.')"
                 @page="(p) => nav.patch({ spage: p > 1 ? p : null })">
        <template #cell-criticality="{ row }">
          <SelectInput v-if="canEdit" small class="w-full" :model-value="row.criticality" :options="options(CRITICALITY)" :disabled="act.pending.value" @update:model-value="(x) => setCrit(row, x)" />
          <Chip v-else small :map="CRITICALITY" :k="row.criticality" />
        </template>
      </DataTable>
    </div>
  </template>
</template>
