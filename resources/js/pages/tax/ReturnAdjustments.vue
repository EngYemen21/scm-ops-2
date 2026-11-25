<script setup>
// The adjustment lines of a return (`adjustments[]`): added on a draft, voided with a reason — never removed.
//   POST /tax/returns/{id}/adjustments/{adjustment}/void { reason }
import { api, useAction } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner } from '@/components';
import { fmtDate, t } from '@/i18n';
import { ask } from '@/stores/ui';
import { TONE, boxLabel, sar, signClass, useTaxLookups } from './tax';

const props = defineProps({
  ret: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
});
const emit = defineEmits(['add']);

const lk = useTaxLookups();
const act = useAction({ invalidate: ['tax'] });
const VOIDED = { ar: 'ملغى', en: 'Voided', ...TONE.neutral };

async function voidLine(a) {
  const reason = await ask({ title: { ar: 'إلغاء سطر التسوية', en: 'Void the adjustment line' }, label: { ar: 'السبب', en: 'Reason' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء السطر', en: 'Void' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/tax/returns/${encodeURIComponent(props.ret.id)}/adjustments/${encodeURIComponent(a.id)}/void`, { reason }), { success: t('أُلغي سطر التسوية', 'Adjustment voided') });
}

const columns = [
  { key: 'box', header: { ar: 'البند', en: 'Line' }, width: 'minmax(150px,1.2fr)' },
  { key: 'base', header: { ar: 'الأساس', en: 'Base' }, width: '96px', kind: 'num' },
  { key: 'vat', header: { ar: 'الضريبة', en: 'VAT' }, width: '96px', kind: 'num' },
  { key: 'reason', header: { ar: 'السبب', en: 'Reason' }, width: 'minmax(160px,1.5fr)' },
  { key: 'createdAt', header: { ar: 'أضافه', en: 'Added' }, width: '120px' },
  { key: 'actions', header: '', width: '96px', bare: true },
];
</script>

<template>
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div class="mb-2 flex items-center gap-2">
    <div class="card-title">{{ t('سطور التسوية', 'Adjustment lines') }}</div>
    <Btn v-if="canEdit" size="sm" tone="primary" :label="{ ar: '+ سطر تسوية', en: '+ Adjustment' }" @click="emit('add')" />
  </div>
  <DataTable :columns="columns" :rows="ret.adjustments || []" :row-key="(r) => r.id" :empty-text="{ ar: 'لا سطور تسوية', en: 'No adjustment line' }"
             :row-style="(r) => (r.voided ? { opacity: 0.55 } : null)">
    <template #cell-box="{ row }"><span class="truncate text-[10.5px] font-bold">{{ boxLabel(lk.boxMap.value, row.box) }}</span></template>
    <template #cell-base="{ row }"><span class="num" :class="signClass(row.base)">{{ sar(row.base) }}</span></template>
    <template #cell-vat="{ row }"><span class="num" :class="signClass(row.vat)">{{ sar(row.vat) }}</span></template>
    <template #cell-reason="{ row }">
      <div class="min-w-0">
        <div class="truncate text-[10.5px]" dir="auto" :title="row.reason">{{ row.reason }}</div>
        <div v-if="row.ledgerLineId" class="cell-sub truncate">{{ t('سطر قيد', 'ledger line') }} {{ row.ledgerLineId }}</div>
        <div v-if="row.voided" class="truncate text-[9px] text-muted" dir="auto">{{ t('أُلغي', 'Voided') }}: {{ row.voidReason || '—' }} · {{ row.voidedBy }}</div>
      </div>
    </template>
    <template #cell-createdAt="{ row }">
      <div class="min-w-0 text-center"><div class="text-[10px]">{{ row.createdBy }}</div><div class="cell-sub">{{ fmtDate(row.createdAt) }}</div></div>
    </template>
    <template #cell-actions="{ row }">
      <Chip v-if="row.voided" small :map="{ v: VOIDED }" k="v" />
      <Btn v-else-if="canEdit" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'إلغاء', en: 'Void' }" @click.stop="voidLine(row)" />
      <span v-else class="text-faint">—</span>
    </template>
  </DataTable>
</template>
