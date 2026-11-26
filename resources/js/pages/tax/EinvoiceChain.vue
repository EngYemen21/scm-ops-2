<script setup>
// The proof of a unit's hash chain — GET /tax/einvoices/chain[?unit=]: counters 1..n without a gap, each document
// carrying the hash of the one before it, each stored XML still hashing to its recorded hash, and the unit's counter
// being the last document. → { unit, count, first, last, ok, breaks[], complete }.
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, SelectInput } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { TONE } from './tax';

const unit = ref('');
const units = useGet('/tax/units');
const unitOptions = computed(() => (units.data.value?.items || []).map((u) => ({ v: u.id, l: `${u.name}${u.isDefault ? ' ★' : ''}` })));
const q = useGet('/tax/einvoices/chain', () => ({ unit: unit.value || undefined }));
const c = computed(() => q.data.value || null);
const KIND = { gap: { ar: 'فجوة في العداد', en: 'Counter gap' }, link: { ar: 'رابط مكسور', en: 'Broken link' }, content: { ar: 'محتوى معدَّل', en: 'Content changed' }, head: { ar: 'رأس السلسلة', en: 'Chain head' } };
const columns = [
  { key: 'icv', header: { ar: 'العداد', en: 'ICV' }, width: '70px', kind: 'id' },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '130px', value: (r) => bi(KIND[r.kind] || { ar: r.kind, en: r.kind }) },
  { key: 'text', header: { ar: 'الوصف', en: 'Description' }, width: 'minmax(220px,2fr)', value: (r) => bi(r) },
];
</script>

<template>
  <div class="row wrap mb-2 items-end gap-2">
    <SelectInput v-model="unit" small :options="unitOptions" :placeholder="{ ar: '— الوحدة الافتراضية —', en: '— default unit —' }" />
    <Btn size="sm" tone="soft" :loading="q.isFetching.value" :label="{ ar: 'إعادة الفحص', en: 'Check again' }" @click="q.refetch()" />
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="!c && q.isLoading.value" class="skel h-[120px]" />
  <template v-else-if="c">
    <div class="banner" :class="c.ok ? 'green' : 'red'">
      <div class="flex-1">
        <b>{{ c.ok ? t('السلسلة سليمة', 'The chain holds') : t('السلسلة فيها انقطاعات', 'The chain has breaks') }}</b>
        — {{ t('الوحدة', 'Unit') }} <span dir="auto">{{ c.unit?.name }}</span> · {{ t('مستندات', 'documents') }} <span class="num">{{ fmtNum(c.count) }}</span>
        <template v-if="c.count"> · {{ t('من', 'from') }} <span class="num">{{ c.first }}</span> {{ t('إلى', 'to') }} <span class="num">{{ c.last }}</span></template>
        · {{ t('عداد الوحدة', 'unit counter') }} <span class="num">{{ c.unit?.icv }}</span>
        <template v-if="!c.complete"> · {{ t('فُحص جزء من السلسلة فقط (الحد 5000)', 'only part of the chain was checked (limit 5000)') }}</template>
      </div>
      <Chip :label="c.ok ? { ar: 'سليمة', en: 'OK' } : { ar: `${c.breaks.length} انقطاع`, en: `${c.breaks.length} breaks` }" :fg="c.ok ? TONE.ok.fg : TONE.bad.fg" :bg="c.ok ? TONE.ok.bg : TONE.bad.bg" />
    </div>
    <div v-if="c.breaks?.length" class="card">
      <DataTable :columns="columns" :rows="c.breaks" :row-key="(r, i) => `${r.icv}-${r.kind}-${i}`" />
    </div>
    <div class="hint">{{ t('يُبنى الفحص مما هو مخزَّن فقط: لا يتصل بالهيئة.', 'The proof is built from what is stored only: it does not call the authority.') }}</div>
  </template>
</template>
