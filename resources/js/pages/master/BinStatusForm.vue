<script setup>
// Bin status transition (active | blocked | full | inactive) with an optional note — audited by the server.
//   <BinStatusForm :bin="statusBin" :open="!!statusBin" @close="statusBin = null" />
import { computed } from 'vue';
import { api } from '@/api/client';
import { FormDrawer } from '@/components';
import { serverMsg } from './_shared';
import { BIN_STATUS_LABELS } from './_whForms';

const props = defineProps({
  /** @type {import('./_whForms').Bin} */
  bin: { type: Object, default: null },
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);

const initial = computed(() => ({ status: props.bin?.status === 'active' ? 'blocked' : 'active' }));
const fields = computed(() => [
  { k: 'status', label: { ar: 'الحالة الجديدة', en: 'New status' }, type: 'select', required: true, full: true, opts: Object.entries(BIN_STATUS_LABELS).map(([v, l]) => ({ v, l, disabled: v === props.bin?.status })) },
  { k: 'note', label: { ar: 'ملاحظة', en: 'Note' }, type: 'area', ph: { ar: 'سبب الحظر / الإيقاف — يُسجل في سجل الحالة', en: 'Reason — recorded in the status history' } },
]);
const submit = (v) => api.patch(`/bins/${props.bin.id}/status`, { status: v.status, note: v.note || undefined });
</script>

<template>
  <FormDrawer :open="open && !!bin" :title="{ ar: `تغيير حالة الموقع ${bin?.code || ''}`, en: `Change bin status — ${bin?.code || ''}` }"
              :sub="{ ar: 'المحظور والموقوف يُستثنيان من التخصيص · الموقوف يجب أن يكون فارغًا', en: 'Blocked / inactive bins are skipped by allocation · inactive must be empty' }"
              :fields="fields" :initial="initial" :width="440" :submit="submit"
              :action="{ success: (d) => serverMsg(d, { ar: 'حُدثت حالة الموقع', en: 'Bin status updated' }), invalidate: ['bins', 'warehouses', 'inventory'] }"
              @close="emit('close')" @done="emit('done', $event)" />
</template>
