<script setup>
// Row actions of a purchase return in the list: approve (pending) · ship (approved) · cancel (pending / approved) for
// whoever holds `return.flow`; everyone else sees what the return is waiting for. Same rules as the return page —
// the API enforces all of it.
import { computed } from 'vue';
import { Btn } from '@/components';
import { t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { isFromStock, useReturnActions } from '../returns/shared';

const props = defineProps({ ret: { type: Object, required: true } });
const emit = defineEmits(['done']);

const auth = useAuth();
const { act, flow, approve, cancel, ship } = useReturnActions(() => props.ret, () => emit('done'));
const open = computed(() => ['pending', 'approved'].includes(props.ret.status));
/** Created before returns named their source bin: it can only be cancelled (the page explains why). */
const legacy = computed(() => !isFromStock(props.ret));
const waiting = computed(() => (props.ret.status === 'pending' ? t('بانتظار اعتماد المستودع', 'Awaiting warehouse approval') : t('معتمد — بانتظار الشحن من المستودع', 'Approved — awaiting shipping')));
</script>

<template>
  <div v-if="open && flow" class="row !gap-1.5">
    <Btn v-if="ret.status === 'pending'" tone="dark" size="sm" :loading="act.pending.value" :disabled="legacy" :title="legacy ? t('بلا موقع مصدر — ألغِه وأنشئ مرتجعًا جديدًا', 'No source bin — cancel it and create a new one') : null" :label="{ ar: 'اعتماد', en: 'Approve' }" @click="approve" />
    <Btn v-if="ret.status === 'approved' && !legacy" tone="purple" size="sm" :loading="act.pending.value" :label="{ ar: 'شحن', en: 'Ship' }" @click="ship" />
    <Btn tone="softRed" size="sm" :loading="act.pending.value" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="cancel" />
  </div>
  <span v-else-if="open" class="text-[10px] font-bold text-muted" :title="auth.can('return.create') ? t('الاعتماد والشحن بصلاحية مسار المرتجعات (المستودع)', 'Approval and shipping need the returns-flow permission (warehouse)') : null">{{ waiting }}</span>
  <span v-else class="muted">—</span>
</template>
