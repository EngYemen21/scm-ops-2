<script setup>
// Fiscal positions — GET /tax/positions → { items, follows }: which tax code replaces which for the customers and
// suppliers a position applies to. A row opens the position (`pos=<id>`, `pos=new` for a new one; vatreturn.manage).
import { computed, ref } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, TextInput } from '@/components';
import { fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import PositionDrawer from './PositionDrawer.vue';
import { ARCHIVED_CHIP, OWNED_CHIP, nameOf, useTaxNav } from './tax';

const nav = useTaxNav();
const auth = useAuth();
const q = useGet('/tax/positions');
const items = computed(() => q.data.value?.items || []);
const follows = computed(() => !!q.data.value?.follows);
const search = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  return s ? items.value.filter((p) => [p.nameAr, p.nameEn, p.countryCode, p.countryGroup, p.note].some((x) => String(x || '').toLowerCase().includes(s))) : items.value;
});
const selected = computed(() => {
  const id = nav.get('pos');
  return id && id !== 'new' ? items.value.find((p) => p.id === id) || null : null;
});
const columns = [
  { key: 'name', header: { ar: 'الموقف الضريبي', en: 'Fiscal position' }, width: 'minmax(180px,1.6fr)' },
  { key: 'where', header: { ar: 'ينطبق على', en: 'Applies to' }, width: 'minmax(150px,1.2fr)' },
  { key: 'lines', header: { ar: 'استبدالات', en: 'Mappings' }, width: '84px', kind: 'num', value: (r) => fmtNum(r.lines?.length || 0) },
  { key: 'partners', header: { ar: 'أطراف', en: 'Partners' }, width: '72px', kind: 'num', value: (r) => fmtNum(r.partners || 0) },
  { key: 'flags', header: '', width: '120px', bare: true },
];
const where = (p) => [
  p.autoApply ? t('تلقائي', 'automatic') : null, p.vatRequired ? t('برقم ضريبي', 'with a VAT number') : null,
  p.countryCode || p.countryGroup || null, p.zipFrom || p.zipTo ? `${p.zipFrom || '…'}–${p.zipTo || '…'}` : null,
].filter(Boolean).join(' · ') || '—';
</script>

<template>
  <div class="row wrap mb-2.5 items-end gap-2">
    <TextInput v-model="search" small field-class="w-full sm:w-[240px]" :placeholder="{ ar: 'بحث بالاسم أو الدولة…', en: 'Search name or country…' }" />
    <div class="grow" />
    <Btn v-if="auth.can('vatreturn.manage')" tone="primary" :label="{ ar: '+ موقف ضريبي', en: '+ Fiscal position' }" @click="nav.patch({ pos: 'new' })" />
  </div>
  <div v-if="follows" class="hint purple !mt-0 mb-2.5">{{ t('المواقف القادمة من أودو تُعدَّل في أودو ما دام القسم يتبعه.', 'Positions that came from Odoo are changed in Odoo while the section follows it.') }}</div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :loading="q.isLoading.value" :row-key="(r) => r.id" :selected-key="nav.get('pos') || null" :page-size="25"
               :empty-text="search ? { ar: 'لا مواقف تطابق البحث', en: 'No position matches the search' } : { ar: 'لا مواقف ضريبية بعد', en: 'No fiscal position yet' }"
               @row-click="(r) => nav.patch({ pos: r.id })">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
          <div v-if="row.lines?.length" class="cell-sub truncate">{{ row.lines.map((l) => `${l.from?.code || '?'}→${l.to?.code || '?'}`).join(' · ') }}</div>
        </div>
      </template>
      <template #cell-where="{ row }"><span class="truncate text-[10px]">{{ where(row) }}</span></template>
      <template #cell-flags="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
          <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        </span>
      </template>
    </DataTable>
  </div>

  <PositionDrawer :open="nav.get('pos') === 'new' || !!selected" :position="selected" @close="nav.patch({ pos: null })" @saved="(p) => nav.patch({ pos: p?.id || null })" />
</template>
