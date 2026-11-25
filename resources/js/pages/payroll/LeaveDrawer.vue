<script setup>
// One leave request: GET /payroll/leaves/{id|number} → the row + reason, decisionNote, balance { balance, pending } (a type
// counted against days granted), history [{ at, from, to, by, note }] and `can` — what the caller may do now:
//   edit / submit / withdraw (its owner or whoever entered it) · approve / refuse (`can.step` first | final) · cancel.
// Nobody decides their own leave; the server says why when someone tries.
//   <LeaveDrawer :number="numberOrNull" @close="…" />
import { computed, ref, watch } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV, Timeline } from '@/components';
import { bi, t } from '@/i18n';
import LeaveForm from './LeaveForm.vue';
import { useLeaveActs } from './leaveActs';
import { APPROVAL, FROM_ODOO, LEAVE_STATE, days, fmtAt, fmtDay, own, pname, typeColor } from './payroll';

const props = defineProps({
  number: { type: String, default: null },
});
const emit = defineEmits(['close']);

const q = useGet(() => (props.number ? `/payroll/leaves/${encodeURIComponent(props.number)}` : null));
const leave = computed(() => (props.number ? q.data.value || null : null));
const can = computed(() => leave.value?.can || {});
const acts = useLeaveActs();
const editing = ref(false);
watch(() => props.number, () => { editing.value = false; acts.act.clearError(); });

const history = computed(() => (leave.value?.history || []).map((h) => ({
  at: h.at, by: h.by, note: h.note, label: own(LEAVE_STATE, h.to) || { ar: h.to, en: h.to }, color: (own(LEAVE_STATE, h.to) || {}).fg,
})));
const anyAction = computed(() => ['edit', 'submit', 'withdraw', 'approve', 'refuse', 'cancel'].some((k) => can.value[k]));
</script>

<template>
  <Drawer :open="!!number" :width="560" @close="emit('close')">
    <template #title><span dir="auto">{{ leave ? `${pname(leave.type)} — ${pname(leave.employee)}` : number }}</span></template>
    <template #sub><span v-if="leave" class="num ltr inline-block">{{ leave.number }}</span><span v-if="leave?.externalRef" class="num ltr inline-block"> · Odoo #{{ leave.externalRef }}</span></template>
    <template v-if="leave" #headExtra><Chip :map="LEAVE_STATE" :k="leave.state" /></template>

    <ErrorBanner :error="q.error.value || acts.act.error.value" :closable="!q.error.value" @close="acts.act.clearError()" />
    <div v-if="q.isLoading.value" class="skel h-[200px]" />
    <template v-if="leave">
      <div v-if="leave.locked" class="hint purple !mt-0 mb-3">{{ t('هذا الطلب من أودو والقسم يتبعه: يُقرَّر ويُعدَّل هناك.', 'This request came from Odoo and the section follows it: it is decided and changed there.') }}</div>
      <div class="grid grid-cols-3 gap-2 max-[520px]:grid-cols-2">
        <div class="tile"><div class="tile-v">{{ days(leave.days) }}</div><div class="tile-l">{{ t('أيام عمل', 'Working days') }}{{ leave.halfDay ? ' · ½' : '' }}</div></div>
        <div class="tile purple"><div class="tile-v !text-[13px]">{{ fmtDay(leave.dateFrom) }}</div><div class="tile-l">{{ t('من', 'From') }}</div></div>
        <div class="tile purple"><div class="tile-v !text-[13px]">{{ fmtDay(leave.dateTo) }}</div><div class="tile-l">{{ t('إلى', 'To') }}</div></div>
      </div>
      <div v-if="leave.balance" class="hint">
        {{ t(`رصيد «${pname(leave.type)}» لسنة ${leave.year}`, `Balance of “${pname(leave.type)}” for ${leave.year}`) }}: <b class="num">{{ days(leave.balance.balance) }}</b>
        · {{ t('طلبات أخرى بانتظار القرار', 'other requests waiting') }}: <b class="num">{{ days(leave.balance.pending) }}</b>
      </div>

      <div class="kv-grid mt-3">
        <KV :k="{ ar: 'الموظف', en: 'Employee' }"><span dir="auto">{{ pname(leave.employee) }}</span> <span class="num ltr text-[9.5px] text-muted">{{ leave.employee?.code }}</span></KV>
        <KV :k="{ ar: 'القسم', en: 'Department' }"><span dir="auto">{{ pname(leave.department) }}</span></KV>
        <KV :k="{ ar: 'النوع', en: 'Type' }"><span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: typeColor(leave.type?.color).fg }" />{{ pname(leave.type) }}</span></KV>
        <KV :k="{ ar: 'الأجر', en: 'Pay' }" :v="leave.type?.paid ? t('مدفوعة', 'Paid') : t('غير مدفوعة', 'Unpaid')" />
        <KV :k="{ ar: 'من يعتمد', en: 'Approved by' }" :v="bi(own(APPROVAL, leave.type?.approval) || { ar: leave.type?.approval || '—', en: leave.type?.approval || '—' })" />
        <KV :k="{ ar: 'أُرسل', en: 'Sent' }" :v="fmtAt(leave.submittedAt)" ltr />
        <KV v-if="leave.firstApprovedBy" :k="{ ar: 'اعتماد المدير', en: 'Manager approval' }"><span dir="auto">{{ leave.firstApprovedBy }}</span> · <span class="num ltr">{{ fmtAt(leave.firstApprovedAt) }}</span></KV>
        <KV v-if="leave.decidedBy" :k="{ ar: 'القرار', en: 'Decided' }"><span dir="auto">{{ leave.decidedBy }}</span> · <span class="num ltr">{{ fmtAt(leave.decidedAt) }}</span></KV>
        <KV :k="{ ar: 'أدخله', en: 'Entered by' }"><span dir="auto">{{ leave.createdBy || '—' }}</span> · <span class="num ltr">{{ fmtAt(leave.createdAt) }}</span></KV>
        <KV v-if="leave.source === 'odoo'" :k="{ ar: 'المصدر', en: 'Origin' }"><Chip small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" /></KV>
      </div>
      <div v-if="leave.reason" class="mt-3">
        <div class="field-l mb-1">{{ t('السبب', 'Reason') }}</div>
        <div class="whitespace-pre-wrap rounded-[12px] bg-soft px-3 py-2.5 text-[11px] leading-[1.9] text-sec [overflow-wrap:anywhere]" dir="auto">{{ leave.reason }}</div>
      </div>
      <div v-if="leave.decisionNote" class="hint" :class="leave.state === 'refused' ? 'amber' : ''"><b>{{ leave.state === 'refused' ? t('سبب الرفض', 'Why refused') : t('ملاحظة القرار', 'Decision note') }}:</b> <span dir="auto">{{ leave.decisionNote }}</span></div>

      <div class="card-title mb-1 mt-4">{{ t('سجل الطلب', 'History') }}</div>
      <Timeline :items="history" :empty-text="{ ar: 'لا أحداث مسجّلة.', en: 'Nothing on record.' }" />
    </template>

    <template v-if="leave && anyAction" #footer>
      <div class="row wrap">
        <Btn v-if="can.approve" tone="success" :loading="acts.act.pending.value" :label="can.step === 'first' ? { ar: 'اعتماد المدير (الخطوة الأولى)', en: 'Manager approval (first step)' } : { ar: 'اعتماد', en: 'Approve' }" @click="acts.approve(leave)" />
        <Btn v-if="can.refuse" tone="dangerOutline" :label="{ ar: 'رفض', en: 'Refuse' }" @click="acts.refuse(leave)" />
        <Btn v-if="can.submit" tone="primary" :loading="acts.act.pending.value" :label="{ ar: 'إرسال للاعتماد', en: 'Send for approval' }" @click="acts.submit(leave)" />
        <Btn v-if="can.withdraw" tone="softAmber" :label="{ ar: 'سحب الطلب', en: 'Withdraw' }" @click="acts.withdraw(leave)" />
        <span class="grow" />
        <Btn v-if="can.cancel" tone="ghost" :label="leave.state === 'approved' ? { ar: 'إلغاء الإجازة المعتمدة', en: 'Cancel approved leave' } : { ar: 'إلغاء الطلب', en: 'Cancel request' }" @click="acts.cancel(leave)" />
        <Btn v-if="can.edit" tone="dark" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = true" />
      </div>
    </template>
  </Drawer>

  <LeaveForm :open="editing" :leave="editing ? leave : null" @close="editing = false" />
</template>
