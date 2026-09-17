<script setup>
// Add a single bin: zone + aisle / rack / shelf → code `<Z>-<AA>-<R>-B<n>`, optional fixed product.
//   <BinForm :open="…" :warehouses="warehouses" :warehouse-code="sel" :zones="zones" :zone="binZone" @close="…" @done="(bin) => …" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { t } from '@/i18n';
import { serverMsg, useProductsList } from './_shared';
import { BIN_TYPE_LABELS, INV, opts, whOpts, zoneOpts } from './_whForms';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('./_whForms').Warehouse[]} */
  warehouses: { type: Array, default: () => [] },
  warehouseCode: { type: String, default: '' },
  /** @type {import('./_whForms').Zone[]} zones of the selected warehouse */
  zones: { type: Array, default: () => [] },
  /** Preselected zone code. */
  zone: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const prods = useProductsList({ active: 'true', pageSize: 300 }, () => props.open);
const initial = computed(() => ({ warehouseCode: props.warehouseCode || '', zone: props.zone || '' }));
const fields = computed(() => [
  { k: 'warehouseCode', label: { ar: 'المستودع', en: 'Warehouse' }, type: 'select', opts: whOpts(props.warehouses), required: true, disabled: !!props.warehouseCode },
  { k: 'zone', label: { ar: 'المنطقة', en: 'Zone' }, type: 'select', opts: zoneOpts(props.zones), required: true, hint: props.zones.length ? undefined : { ar: 'لا مناطق في هذا المستودع — أنشئ المنطقة أولًا', en: 'No zones — create a zone first' } },
  { k: 'aisle', label: { ar: 'الممر', en: 'Aisle' }, type: 'num', min: 1, max: 99, required: true, def: 1 }, { k: 'rack', label: { ar: 'الرف', en: 'Rack' }, type: 'num', min: 1, max: 99, required: true, def: 1 }, { k: 'shelf', label: { ar: 'الرقم (المستوى)', en: 'Shelf' }, type: 'num', min: 1, max: 99, required: true, def: 1 },
  { k: 'type', label: { ar: 'نوع الموقع', en: 'Bin type' }, type: 'select', opts: opts(BIN_TYPE_LABELS), def: 'shelf' },
  { k: 'capacityUnits', label: { ar: 'السعة (وحدة)', en: 'Capacity (units)' }, type: 'num', min: 0, def: 400 }, { k: 'maxKg', label: { ar: 'حد الوزن كجم', en: 'Max kg' }, type: 'num', min: 0, def: 800 },
  { k: 'fixedSku', label: { ar: 'تخصيص ثابت لمنتج؟', en: 'Fixed product?' }, type: 'select', full: true, ph: { ar: '— ديناميكي —', en: '— dynamic —' }, opts: (prods.data.value?.items || []).map((p) => ({ v: p.sku, l: `${p.sku} — ${p.nameAr}` })) },
  // rendered through the #field-_preview slot below
  { k: '_preview', label: { ar: 'الرمز', en: 'Code' }, full: true },
]);
const codeOf = (v) => `${String(v.zone || '?').toUpperCase()}-${String(Number(v.aisle) || 1).padStart(2, '0')}-${Number(v.rack) || 1}-B${Number(v.shelf) || 1}`;

function submit(v) {
  const { _preview: _p, ...body } = v;
  return api.postIdempotent('/bins', { ...body, fixedSku: body.fixedSku || undefined });
}
</script>

<template>
  <FormDrawer :open="open" :title="{ ar: 'إضافة موقع Bin', en: 'Add bin' }" :sub="{ ar: 'المنتج المجمد لا يُخصص خارج FZ والمبرد لا يُخصص خارج CH', en: 'Frozen products only in frozen zones, chilled only in cold zones' }"
              :fields="fields" :initial="initial" :submit="submit" :action="{ success: (d) => serverMsg(d, { ar: 'أُنشئ الموقع', en: 'Bin created' }), invalidate: INV }"
              @close="emit('close')" @done="emit('done', $event)">
    <template #field-_preview="{ values }">
      <div class="hint teal !mt-0">{{ t('رمز الموقع الناتج:', 'Resulting bin code:') }} <b class="num" dir="ltr">{{ codeOf(values) }}</b></div>
    </template>
  </FormDrawer>
</template>
