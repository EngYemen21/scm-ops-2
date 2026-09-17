<script setup>
// PO action buttons, shown only in the right state and with the right permission:
//   draft → submit · pending → approve current tier / reject (reason) · approved → send to supplier · sent → supplier confirmed ·
//   draft|pending|approved → cancel (reason) · sent|confirmed|partial → open the expected shipment.
//   <PoActions :po="po" size="md" @changed="(po) => …" />
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction } from '@/api/client';
import { Btn } from '@/components';
import { fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import ReasonDrawer from './ReasonDrawer.vue';

const props = defineProps({
  /** Po — see shared.js */
  po: { type: Object, required: true },
  size: { type: String, default: 'sm' },
});
const emit = defineEmits(['changed']);

const auth = useAuth();
const router = useRouter();
const act = useAction({ invalidate: ['procurement', 'inbound', 'dashboard'] });
/** null | 'reject' | 'cancel' */
const reasonFor = ref(null);

const S = computed(() => props.po.status);
/** Current approval tier (the step the next approval applies to). */
const step = computed(() => props.po.currentStep || props.po.approvals?.find((a) => a.decision === 'pending'));
const shipment = computed(() => props.po.shipments?.[0]);

async function run(path, body, success) {
  const r = await act.run(() => api.postIdempotent(`/procurement/po/${props.po.number}/${path}`, body), { success });
  if (r) { emit('changed', r.po || r); reasonFor.value = null; }
  return r;
}
const submitPo = () => run('submit', undefined, t(`${props.po.number} أُرسل للاعتماد`, `${props.po.number} submitted`));
async function approve() {
  const po = props.po; const st = step.value;
  const yes = await confirm({
    title: { ar: `اعتماد ${po.number} — ${st.labelAr}؟`, en: `Approve ${po.number} — ${st.labelEn}?` },
    sub: { ar: `الخطوة ${st.step} من ${po.approvals.length} · القيمة ${fmtMoney(po.total)} ر.س`, en: `Step ${st.step} of ${po.approvals.length} · ${fmtMoney(po.total)} SAR` },
    tone: 'dark', okLabel: { ar: 'اعتماد', en: 'Approve' },
  });
  if (yes) void run('approve', {}, t(`اعتُمدت الخطوة ${st.step} من ${po.number}`, `Step ${st.step} of ${po.number} approved`));
}
const send = () => run('send', undefined, (d) => (d?.shipment
  ? t(`أُرسل ${props.po.number} للمورد — ${d.created ? 'أُنشئت' : 'موجودة'} شحنة متوقعة ${d.shipment.number}`, `${props.po.number} sent — expected shipment ${d.shipment.number}`)
  : t(`أُرسل ${props.po.number}`, `${props.po.number} sent`)));
const supplierConfirmed = () => run('confirm', {}, t(`أكّد المورد ${props.po.number}`, `${props.po.number} confirmed by supplier`));
function withReason(reason) {
  const n = props.po.number;
  return reasonFor.value === 'reject' ? run('reject', { reason }, t(`رُفض ${n}`, `${n} rejected`)) : run('cancel', { reason }, t(`أُلغي ${n}`, `${n} cancelled`));
}
function closeReason() { reasonFor.value = null; act.clearError(); }
</script>

<template>
  <div class="row wrap justify-end !gap-[5px]">
    <Btn v-if="S === 'draft' && auth.can('po.create')" tone="success" :size="size" :loading="act.pending.value" :label="{ ar: 'إرسال للاعتماد', en: 'Submit' }" @click="submitPo" />
    <template v-if="S === 'pending' && auth.can('po.approve')">
      <Btn v-if="step" tone="success" :size="size" :loading="act.pending.value" :label="{ ar: `اعتماد — ${step.labelAr}`, en: `Approve — ${step.labelEn}` }" @click="approve" />
      <Btn tone="dangerOutline" :size="size" :label="{ ar: 'رفض', en: 'Reject' }" @click="reasonFor = 'reject'" />
    </template>
    <Btn v-if="S === 'approved' && auth.can('po.send')" tone="blue" :size="size" :loading="act.pending.value" :label="{ ar: 'إرسال للمورد', en: 'Send to supplier' }" @click="send" />
    <Btn v-if="S === 'sent' && auth.can('po.send')" tone="purple" :size="size" :loading="act.pending.value" :label="{ ar: 'تأكيد المورد', en: 'Supplier confirmed' }" @click="supplierConfirmed" />
    <Btn v-if="['draft', 'pending', 'approved'].includes(S) && auth.can('po.cancel')" tone="dangerOutline" :size="size" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="reasonFor = 'cancel'" />
    <Btn v-if="['sent', 'confirmed', 'partial'].includes(S) && shipment" tone="softBlue" :size="size" :label="{ ar: `الشحنة ${shipment.number}`, en: `Shipment ${shipment.number}` }" @click="router.push(`/shipments/${encodeURIComponent(shipment.number)}`)" />

    <!-- Teleported: this component also lives inside clickable table rows; the drawer must not inherit their click / hover. -->
    <Teleport v-if="reasonFor !== null" to="body">
      <ReasonDrawer open
                    :title="reasonFor === 'reject' ? { ar: `رفض ${po.number}`, en: `Reject ${po.number}` } : { ar: `إلغاء أمر الشراء ${po.number}`, en: `Cancel ${po.number}` }"
                    :sub="reasonFor === 'reject' ? { ar: 'الرفض يُلغي أمر الشراء ويُسجَّل السبب في سلسلة الاعتماد', en: 'Rejection cancels the PO; the reason is recorded on the approval chain' } : { ar: 'لا يمكن الإلغاء بعد الإرسال للمورد', en: 'Cannot cancel after sending to the supplier' }"
                    :ok-label="reasonFor === 'reject' ? { ar: 'رفض', en: 'Reject' } : { ar: 'إلغاء أمر الشراء', en: 'Cancel PO' }"
                    :pending="act.pending.value" :error="act.error.value" @submit="withReason" @close="closeReason" @clear-error="act.clearError()" />
    </Teleport>
  </div>
</template>
