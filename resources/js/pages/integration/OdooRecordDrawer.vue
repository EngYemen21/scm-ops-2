<script setup>
// One record of the copy, exactly as Odoo returned it: every value in a readable key / value list (see OdooValue.vue
// for how each kind of value is shown), when Odoo last wrote it, how many states of it were seen, when it first
// appeared here, and its change history (the newest 50).
//   <OdooRecordDrawer :model="modelOrNull" :odoo-id="idOrNull" @close="…" />
// GET /integration/odoo/records/{model}/{odooId} → the record + { fieldTypes: { field: type }, history: [change] }.
import { computed, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { Chip, Drawer, ErrorBanner, KV, Tabs, TextInput, Timeline } from '@/components';
import { fmtNum, t } from '@/i18n';
import OdooFieldChips from './OdooFieldChips.vue';
import OdooValue from './OdooValue.vue';
import { CHANGE_KIND, RECORD_STATE, fieldType, fmtAt, recordState, useOdooNav, valueKind, valueText } from './odoo';

const props = defineProps({
  /** Technical name of the Odoo model; null closes the drawer. */
  model: { type: String, default: null },
  /** The record's id in Odoo. */
  odooId: { type: Number, default: null },
});
const emit = defineEmits(['close']);

const nav = useOdooNav();
const isOpen = computed(() => !!props.model && props.odooId != null);
const q = useGet(() => (isOpen.value ? `/integration/odoo/records/${encodeURIComponent(props.model)}/${props.odooId}` : null));
const r = computed(() => (isOpen.value ? q.data.value || null : null));

const history = computed(() => r.value?.history || []);
/** Fields the newest change touched: marked in the list so "what changed" is one glance away. */
const lastChanged = computed(() => new Set(history.value[0]?.fields || []));
const rows = computed(() => Object.entries(r.value?.data || {}).map(([name, value]) => {
  const type = r.value.fieldTypes?.[name] || null;
  return { name, value, type, empty: valueKind(value, type) === 'empty', changed: lastChanged.value.has(name) };
}));
const filled = computed(() => rows.value.filter((x) => !x.empty));

const pane = ref('values');
/** '' = every field, 'filled' = only the ones that hold something. */
const only = ref('');
const fieldQ = ref('');
const shown = computed(() => {
  const f = fieldQ.value.trim().toLowerCase();
  return (only.value === 'filled' ? filled.value : rows.value).filter((x) => !f || x.name.toLowerCase().includes(f) || valueText(x.value).includes(f));
});
watch(() => [props.model, props.odooId], () => { pane.value = 'values'; fieldQ.value = ''; });

const panes = computed(() => [
  { k: 'values', label: { ar: `القيم (${fmtNum(rows.value.length)})`, en: `Values (${fmtNum(rows.value.length)})` } },
  { k: 'history', label: { ar: `سجل التغييرات (${fmtNum(history.value.length)})`, en: `Change history (${fmtNum(history.value.length)})` } },
]);
const onlyTabs = computed(() => [
  { k: '', label: { ar: `كل الحقول (${fmtNum(rows.value.length)})`, en: `Every field (${fmtNum(rows.value.length)})` } },
  { k: 'filled', label: { ar: `المعبّأة فقط (${fmtNum(filled.value.length)})`, en: `Filled only (${fmtNum(filled.value.length)})` } },
]);
const timeline = computed(() => history.value.map((h) => ({ at: h.seenAt, label: CHANGE_KIND[h.kind] || h.kind, color: CHANGE_KIND[h.kind]?.fg, fields: h.fields, writeDate: h.writeDate })));
</script>

<template>
  <Drawer :open="isOpen" :width="620" @close="emit('close')">
    <template #title><span dir="auto">{{ r?.displayName || (odooId != null ? `#${odooId}` : '') }}</span></template>
    <template #sub><span class="num ltr inline-block">{{ model }} · #{{ odooId }}</span></template>
    <template v-if="r && recordState(r) && recordState(r) !== 'live'" #headExtra><Chip small :map="RECORD_STATE" :k="recordState(r)" /></template>

    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value" class="skel h-[160px]" />
    <template v-if="r">
      <div v-if="r.deletedAt" class="hint amber !mt-0 mb-3">{{ t('هذا السجل لم يعد موجودًا في أودو. ما تراه آخر قيمه قبل الحذف، محفوظة في النسخة.', 'This record no longer exists in Odoo. What you see are its last values before the deletion, kept in the copy.') }}</div>
      <div class="kv-grid">
        <KV :k="{ ar: 'النموذج', en: 'Model' }"><button type="button" class="num ltr cursor-pointer border-0 bg-transparent p-0 text-start font-bold text-brand-dark hover:text-violet" @click="nav.openModel(model)">{{ model }}</button></KV>
        <KV :k="{ ar: 'رقم السجل في أودو', en: 'Record id in Odoo' }" :v="`#${r.odooId}`" ltr />
        <KV :k="{ ar: 'آخر تعديل في أودو', en: 'Last written in Odoo' }" :v="fmtAt(r.writeDate)" ltr />
        <KV :k="{ ar: 'الإصدار', en: 'Version' }">
          <span class="num ltr">{{ fmtNum(r.version) }}</span>
          <div class="text-[9px] font-normal text-faint">{{ t('عدد الحالات المختلفة التي رُصدت له', 'how many different states of it were seen') }}</div>
        </KV>
        <KV :k="{ ar: 'أول ظهور في النسخة', en: 'First seen in the copy' }" :v="fmtAt(r.firstSeenAt)" ltr />
        <KV :k="{ ar: 'آخر تغيّر في النسخة', en: 'Last change in the copy' }" :v="fmtAt(r.changedAt)" ltr />
        <KV v-if="r.deletedAt" :k="{ ar: 'رُصد حذفه من أودو', en: 'Deletion noticed' }" :v="fmtAt(r.deletedAt)" ltr />
      </div>

      <Tabs v-model="pane" :tabs="panes" variant="sm" class="!mb-2.5 mt-[18px]" />

      <!-- every value of the record -->
      <template v-if="pane === 'values'">
        <div class="row wrap">
          <TextInput v-model="fieldQ" small type="search" field-class="min-w-[180px] flex-1" :placeholder="{ ar: 'بحث باسم الحقل أو بالقيمة…', en: 'Filter by field name or value…' }" />
          <Tabs v-model="only" :tabs="onlyTabs" variant="pill" class="!mb-0" />
        </div>
        <div class="mt-2.5 overflow-hidden rounded-xl border border-line-2">
          <div v-for="x in shown" :key="x.name" class="flex items-start gap-3 border-t border-line-2 px-3 py-2 first:border-t-0">
            <div class="w-[38%] flex-none">
              <div class="num ltr break-all text-[10.5px] text-sec">{{ x.name }}</div>
              <div v-if="x.type || x.changed" class="mt-px text-[8.5px] text-faint">
                <template v-if="x.type">{{ fieldType(x.type) }}</template>
                <span v-if="x.changed" class="font-bold text-azure" :class="{ 'ms-1': x.type }">{{ t('تغيّر في آخر تعديل', 'changed last time') }}</span>
              </div>
            </div>
            <div class="min-w-0 flex-1 text-[10.5px] font-bold text-ink"><OdooValue :value="x.value" :type="x.type" /></div>
          </div>
          <div v-if="!shown.length" class="empty !p-3.5">{{ rows.length ? t('لا حقول مطابقة.', 'No matching fields.') : t('لا قيم محفوظة لهذا السجل.', 'No value is stored for this record.') }}</div>
        </div>
      </template>

      <!-- what happened to it, newest first -->
      <template v-else>
        <Timeline :items="timeline" :empty-text="{ ar: 'لا تغييرات مسجّلة: نُسخ في النسخة الأولى ولم يتغيّر منذ ذلك، أو مضى على تغييراته أكثر من مدة حفظ السجل.', en: 'No change on record: it came with the first copy and has not changed since, or its changes are older than the feed keeps.' }">
          <template #note="{ item }">
            <OdooFieldChips v-if="item.fields?.length" :fields="item.fields" :max="0" />
            <div v-if="item.writeDate" class="mt-0.5 text-[9px] text-faint">{{ t('وقت الكتابة في أودو', 'Written in Odoo at') }} <span class="num ltr">{{ fmtAt(item.writeDate) }}</span></div>
          </template>
        </Timeline>
        <div v-if="history.length >= 50" class="mt-2 text-[9.5px] text-faint">{{ t('تظهر أحدث 50 تغييرًا فقط.', 'Only the newest 50 changes are shown.') }}</div>
      </template>
    </template>
  </Drawer>
</template>
