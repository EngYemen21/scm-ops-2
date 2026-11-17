<script setup>
// Inspection of a received return: per line the inspected quantity, the condition found and a note, plus overall findings.
// POST /api/returns/:number/inspect { findings?, lines: [{ lineNo, inspectedQty, condition, note? }] } — starts the
// inspection (received → inspect); opened again while in `inspect` it corrects the record. Mount with v-if.
import { computed, ref } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, SelectInput, TextArea, TextInput } from '@/components';
import { isMobile } from '@/composables/viewport';
import { fmtNum, t } from '@/i18n';
import { RETURN_CONDITION_LABELS, pname } from './shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['close', 'done']);

const act = useAction();
const editing = computed(() => props.ret.status === 'inspect');
const arrived = (l) => l.pendingQty ?? l.receivedQty ?? l.qty;
// only what arrived can be inspected; a line with nothing received is left out
const rows = ref((props.ret.lines || []).filter((l) => arrived(l) > 0).map((l) => ({
  lineNo: l.lineNo, name: pname(l.product), sku: l.product?.sku, batchNo: l.batchNo || l.batch?.batchNo || null, received: arrived(l),
  inspectedQty: l.inspectedQty ?? arrived(l), condition: l.condition && RETURN_CONDITION_LABELS[l.condition] ? l.condition : 'good', note: l.inspectNote || '',
})));
const findings = ref(props.ret.inspection?.findings || '');
const conditionOpts = Object.entries(RETURN_CONDITION_LABELS).map(([v, l]) => ({ v, l }));
const bad = (r) => !(Number.isInteger(r.inspectedQty) && r.inspectedQty >= 0 && r.inspectedQty <= r.received);
const blocker = computed(() => (rows.value.some(bad) ? { ar: 'الكمية المفحوصة بين صفر والكمية المستلمة', en: 'Inspected quantity must be between 0 and the received quantity' } : null));

async function submit() {
  if (blocker.value) return;
  const body = { findings: findings.value.trim() || undefined, lines: rows.value.map((r) => ({ lineNo: r.lineNo, inspectedQty: r.inspectedQty, condition: r.condition, note: r.note.trim() || undefined })) };
  const r = await act.run(() => api.postIdempotent(`/returns/${encodeURIComponent(props.ret.number)}/inspect`, body), {
    success: (x) => x?.message || (editing.value ? t('حُدّثت نتائج الفحص', 'Inspection updated') : t('سُجّل الفحص — القرار مطلوب', 'Inspection recorded — decision needed')), invalidate: ['returns', 'dashboard'],
  });
  if (r !== undefined) { emit('close'); emit('done'); }
}
const GRID = 'grid grid-cols-[minmax(140px,1.3fr)_64px_84px_120px_minmax(110px,1fr)] items-center gap-2';
/** On a phone the column header row is hidden, so every field carries its own caption. */
const cap = (ar, en) => (isMobile.value ? { ar, en } : null);
</script>

<template>
  <Modal open :width="760" @close="emit('close')">
    <template #title>
      {{ editing ? t('تعديل نتائج الفحص', 'Edit inspection') : t('فحص المرتجع', 'Inspect return') }}
      <div class="drawer-sub"><span class="num">{{ ret.number }}</span> · <span class="num">{{ rows.length }}</span> {{ t('سطر مستلم', 'received lines') }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div :class="GRID" class="le-head px-0.5 pb-1 text-[9px] font-extrabold text-faint">
      <div>{{ t('الصنف', 'Item') }}</div><div class="text-center">{{ t('المستلم', 'Received') }}</div><div class="text-center">{{ t('المفحوص', 'Inspected') }}</div><div>{{ t('الحالة', 'Condition') }}</div><div>{{ t('ملاحظة', 'Note') }}</div>
    </div>
    <div v-for="r in rows" :key="r.lineNo" :class="GRID" class="le-row border-t border-[#F7F6FA] py-1.5">
      <div class="min-w-0"><div class="ellipsis text-[11px] font-extrabold">{{ r.name }}</div><div class="cell-sub num">{{ r.sku }}<template v-if="r.batchNo"> · {{ r.batchNo }}</template></div></div>
      <div class="num text-center text-[11px]" :data-label="t('المستلم', 'Received')">{{ fmtNum(r.received) }}</div>
      <NumberInput v-model="r.inspectedQty" :label="cap('المفحوص', 'Inspected')" small center :min="0" :max="r.received" :step="1" :error="bad(r) ? `0 – ${r.received}` : null" />
      <SelectInput v-model="r.condition" :label="cap('الحالة', 'Condition')" small :options="conditionOpts" />
      <TextInput v-model="r.note" :label="cap('ملاحظة', 'Note')" small :placeholder="{ ar: 'اختياري', en: 'optional' }" />
    </div>
    <div v-if="!rows.length" class="empty !p-3.5">{{ t('لا أسطر مستلمة للفحص', 'No received lines to inspect') }}</div>
    <div class="form-grid mt-2.5"><TextArea v-model="findings" :label="{ ar: 'نتائج الفحص العامة (اختياري)', en: 'Overall findings (optional)' }" full /></div>
    <div class="hint teal mt-2.5">{{ t('الفحص يسجّل الحالة فقط ولا يحرّك مخزونًا — القرار (إعادة للمخزون / حجر / تالف / إعدام / للمورد) هو الذي يُخرج الكمية من منطقة المرتجعات.', 'Inspection only records the condition and moves no stock — the decision (restock / quarantine / damaged / scrap / supplier) is what takes the quantity out of the returns area.') }}</div>

    <template #footer>
      <div v-if="blocker" class="mb-1.5 text-[10px] font-bold text-bad">{{ t(blocker.ar, blocker.en) }}</div>
      <div class="row">
        <Btn tone="purple" class="!h-[42px] flex-1" :loading="act.pending.value" :disabled="!!blocker" :label="editing ? { ar: 'حفظ التعديل', en: 'Save changes' } : { ar: 'تسجيل الفحص', en: 'Record inspection' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
