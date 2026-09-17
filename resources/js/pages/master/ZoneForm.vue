<script setup>
// Add zone — the server generates aisles × racks × bins locations; the preview field shows the count live.
//   <ZoneForm :open="…" :warehouses="warehouses" :warehouse-code="sel" @close="…" @done="(zoneCreated, warehouseCode) => …" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { fmtNum, t } from '@/i18n';
import { serverMsg } from './_shared';
import { INV, PICK_LABELS, ZONE_TYPE_LABELS, opts, whOpts } from './_whForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('./_whForms').Warehouse[]} */
  warehouses: { type: Array, default: () => [] },
  warehouseCode: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const initial = computed(() => ({ warehouseCode: props.warehouseCode || '' }));
const fields = computed(() => [
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts(props.warehouses), required: true },
  { k: 'code', label: { ar: 'رمز المنطقة (حرف أو حرفان)', en: 'Zone code (1–2 letters)' }, required: true, dir: 'ltr', ph: 'A / CH / FZ', validate: (x) => (/^[A-Za-z]{1,2}$/.test(String(x || '')) ? null : t('رمز المنطقة حرف أو حرفان', 'Zone code must be 1–2 letters')) },
  { k: 'nameAr', label: { ar: 'اسم المنطقة', en: 'Zone name' }, required: true, ph: { ar: 'مثال: سريعة الحركة', en: 'e.g. Fast movers' } }, { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (English)' }, dir: 'ltr' },
  { k: 'type', label: { ar: 'نوع التخزين', en: 'Storage type' }, type: 'select', opts: opts(ZONE_TYPE_LABELS), def: 'ambient' }, { k: 'pickStrategy', label: { ar: 'استراتيجية الصرف', en: 'Pick strategy' }, type: 'select', opts: opts(PICK_LABELS), def: 'fefo' },
  { k: 'aisles', label: { ar: 'عدد الممرات', en: 'Aisles' }, type: 'num', min: 1, max: 99, required: true, def: 4 }, { k: 'racks', label: { ar: 'رفوف لكل ممر', en: 'Racks per aisle' }, type: 'num', min: 1, max: 50, required: true, def: 3 }, { k: 'bins', label: { ar: 'مواقع لكل رف', en: 'Bins per rack' }, type: 'num', min: 1, max: 50, required: true, def: 4 },
  { k: 'capacityUnits', label: { ar: 'سعة الموقع (وحدة)', en: 'Bin capacity (units)' }, type: 'num', min: 0, def: 400 }, { k: 'maxKg', label: { ar: 'حد الوزن للموقع كجم', en: 'Max kg per bin' }, type: 'num', min: 0, def: 800 },
  // rendered through the #field-_preview slot below
  { k: '_preview', label: { ar: 'المواقع المولّدة', en: 'Generated bins' }, full: true },
]);

/** Live preview numbers of the generated structure. */
function preview(values) {
  const a = Number(values.aisles) || 0; const r = Number(values.racks) || 0; const b = Number(values.bins) || 0;
  const z = String(values.code || 'A').toUpperCase();
  return { racks: a * r, bins: a * r * b, first: `${z}-01-1-B1`, last: `${z}-${String(a).padStart(2, '0')}-${r}-B${b}` };
}

function submit(v) {
  const { warehouseCode: wc, _preview: _p, ...body } = v;
  return api.postIdempotent(`/warehouses/${encodeURIComponent(String(wc))}/zones`, { ...body, code: String(body.code).toUpperCase() });
}
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'إضافة منطقة تخزين Zone', en: 'Add storage zone' }" :sub="{ ar: 'Zone ← Aisle ← Rack ← Bin — المواقع تُولَّد بباركود جاهز للطباعة', en: 'Zone → aisle → rack → bin — bins are generated with printable barcodes' }"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: (d) => serverMsg(d, { ar: 'أُنشئت المنطقة', en: 'Zone created' }), invalidate: INV }"
              @close="emit('close')" @done="(d, v) => emit('done', d, String(v.warehouseCode))">
    <template #field-_preview="{ values }">
      <div class="hint purple !mt-0">
        {{ t(`سيُولَّد ${fmtNum(preview(values).racks)} رفًا و ${fmtNum(preview(values).bins)} موقعًا تلقائيًا`, `${fmtNum(preview(values).racks)} racks and ${fmtNum(preview(values).bins)} bins will be generated`) }} —
        <bdi dir="ltr" class="num">{{ preview(values).first }} … {{ preview(values).last }}</bdi>
        <div v-if="preview(values).bins > 20000" class="font-extrabold text-bad">{{ t('يتجاوز الحد 20,000 موقع', 'Exceeds the 20,000 bin limit') }}</div>
      </div>
    </template>
  </FormDrawer>
</template>
