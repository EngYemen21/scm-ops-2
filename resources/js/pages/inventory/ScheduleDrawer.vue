<script setup>
// Schedule-a-count form (POST /inventory/counts): warehouse, type, scope, zone (when scope = zone), blind, freeze,
// date, counter, notes. Emits `done` with the created count.
//   <ScheduleDrawer :open="schedOpen" :default-wh="code" @close="schedOpen = false" @done="(count) => …" />
import { computed, reactive, ref, watch } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, DateInput, Drawer, ErrorBanner, PillChoice, SelectInput, TextArea, TextInput } from '@/components';
import { lang, t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import { COUNT_SCOPES, COUNT_TYPES, dictOptions } from './shared';

const props = defineProps({
  open: { type: Boolean, default: false },
  defaultWh: { type: String, default: '' },
});
const emit = defineEmits(['close', 'done']);

const wh = useWarehouse();
const act = useAction();
const today = () => new Date().toISOString().slice(0, 10);
const blank = () => ({ warehouseCode: props.defaultWh, type: 'cycle', scope: 'zone', zoneCode: '', blind: '1', freeze: '1', date: today(), counterUsername: '', notes: '' });
const f = reactive(blank());
const errs = ref({});
watch(() => [props.open, props.defaultWh], () => { if (props.open) { Object.assign(f, blank()); errs.value = {}; act.clearError(); } }, { immediate: true });

/** Zones of the chosen warehouse. */
const whd = useGet(() => (props.open && f.warehouseCode ? `/warehouses/${f.warehouseCode}` : null));
function set(k, v) { f[k] = v; if (errs.value[k]) { const n = { ...errs.value }; delete n[k]; errs.value = n; } }
const effScope = computed(() => (f.type === 'full' ? 'all' : f.type === 'abc' ? 'abc' : f.scope));
const scopeLocked = computed(() => f.type === 'full' || f.type === 'abc');
const scopeHint = computed(() => (f.type === 'full' ? { ar: 'الجرد الشامل يغطي كل المستودع', en: 'Full count covers the whole warehouse' } : f.type === 'abc' ? { ar: 'أصناف A — أعلى 20% بالقيمة', en: 'A items — top 20% by value' } : null));

const whOpts = computed(() => wh.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` })));
const zoneOpts = computed(() => (whd.data.value?.zones || []).map((z) => ({ v: z.code, l: `${z.code} · ${lang.value === 'ar' ? z.nameAr : z.nameEn} (${z.type})` })));
const typeOpts = dictOptions(COUNT_TYPES);
const scopeOpts = dictOptions(COUNT_SCOPES);
const BLIND_OPTS = [{ v: '1', l: { ar: 'عد أعمى (يخفي رصيد النظام)', en: 'Blind (hides system qty)' } }, { v: '0', l: { ar: 'عد عادي', en: 'Open' } }];
const FREEZE_OPTS = [{ v: '1', l: { ar: 'تجميد أثناء العد', en: 'Freeze while counting' } }, { v: '0', l: { ar: 'بدون تجميد', en: 'No freeze' } }];

async function submit() {
  const e = {};
  if (!f.warehouseCode) e.warehouseCode = t('إلزامي', 'Required');
  if (!f.date) e.date = t('إلزامي', 'Required');
  if (effScope.value === 'zone' && !f.zoneCode) e.zoneCode = t('نطاق «منطقة» يتطلب اختيار المنطقة', 'Zone scope needs a zone');
  errs.value = e;
  if (Object.keys(e).length) return;
  const body = { warehouseCode: f.warehouseCode, type: f.type, scope: effScope.value, zoneCode: effScope.value === 'zone' ? f.zoneCode : undefined, blind: f.blind === '1', freeze: f.freeze === '1', date: f.date, counterUsername: f.counterUsername || undefined, notes: f.notes || undefined };
  const r = await act.run(() => api.postIdempotent('/inventory/counts', body), { success: (x) => t(`جُدول الجرد ${x.number} (${x.lines.length} سطر)`, `Count ${x.number} scheduled (${x.lines.length} lines)`), invalidate: ['inventory'] });
  if (r) { emit('done', r); emit('close'); }
}
// Server-side validation details land on their fields.
watch(() => act.error.value, (err) => { if (err) errs.value = { ...errs.value, ...err.fieldErrors() }; });
</script>

<template>
  <Drawer :open="open" :width="520" :title="{ ar: 'جدولة جرد', en: 'Schedule a count' }" :sub="{ ar: 'تُنشأ أسطر الجرد من الأرصدة ضمن النطاق بلقطة رصيد النظام', en: 'Lines are built from balances in scope with a system-qty snapshot' }" @close="emit('close')">
    <div class="form-grid">
      <SelectInput :model-value="f.warehouseCode" :label="{ ar: 'المستودع', en: 'Warehouse' }" required :error="errs.warehouseCode" :options="whOpts" :placeholder="{ ar: '— اختر —', en: '— select —' }" @update:model-value="(v) => { set('warehouseCode', v); set('zoneCode', ''); }" />
      <DateInput :model-value="f.date" :label="{ ar: 'تاريخ الجرد', en: 'Count date' }" required :error="errs.date" @update:model-value="set('date', $event)" />
      <PillChoice full :model-value="f.type" :label="{ ar: 'نوع الجرد', en: 'Count type' }" :options="typeOpts" @update:model-value="set('type', $event)" />
      <SelectInput :model-value="effScope" :label="{ ar: 'النطاق', en: 'Scope' }" :disabled="scopeLocked" :options="scopeOpts" :hint="scopeHint" @update:model-value="set('scope', $event)" />
      <SelectInput v-if="effScope === 'zone'" :model-value="f.zoneCode" :label="{ ar: 'المنطقة', en: 'Zone' }" required :error="errs.zoneCode" :options="zoneOpts" :placeholder="whd.isLoading.value ? { ar: 'جارٍ التحميل…', en: 'Loading…' } : { ar: '— اختر —', en: '— select —' }" @update:model-value="set('zoneCode', $event)" />
      <PillChoice purple :model-value="f.blind" :label="{ ar: 'وضع العد', en: 'Mode' }" :options="BLIND_OPTS" @update:model-value="set('blind', $event)" />
      <PillChoice purple :model-value="f.freeze" :label="{ ar: 'تجميد الحركات', en: 'Freeze movements' }" :options="FREEZE_OPTS" @update:model-value="set('freeze', $event)" />
      <TextInput :model-value="f.counterUsername" :label="{ ar: 'العدّاد (اسم المستخدم — اختياري)', en: 'Counter username (optional)' }" dir="ltr" :error="errs.counterUsername" :hint="{ ar: 'إن حُدد، يستطيع هو فقط (أو من يملك inventory.adjust) إدخال العد', en: 'If set, only that user (or inventory.adjust holders) can enter counts' }" @update:model-value="set('counterUsername', $event)" />
      <TextArea :model-value="f.notes" :label="{ ar: 'ملاحظات', en: 'Notes' }" @update:model-value="set('notes', $event)" />
    </div>

    <template #footer>
      <ErrorBanner :error="act.error.value" hide-details @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="!h-11 flex-1" :loading="act.pending.value" :label="{ ar: 'جدولة', en: 'Schedule' }" @click="submit" />
        <Btn tone="soft" class="!h-11 w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Drawer>
</template>
