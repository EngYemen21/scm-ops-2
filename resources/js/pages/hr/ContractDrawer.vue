<script setup>
// One employment contract (hr.private): GET /hr/contracts/{number} — its terms, its state and what can be done to it
// now. The buttons are the ones the server says are possible (`contract.can`): edit · put in force · end · cancel ·
// renew (a new draft that continues it). Nothing is deleted: a contract entered by mistake is cancelled with a reason.
//   <ContractDrawer :number="nav.get('contract') || null" @close />
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, KV } from '@/components';
import { bi, t } from '@/i18n';
import { ask, confirm } from '@/stores/ui';
import ContractForm from './ContractForm.vue';
import { CONTRACT_STATE, CONTRACT_TYPE, FROM_ODOO, STAGE, daysText, fmtAt, fmtDay, money, pname, todayIso, useHrNav } from './shared';

const props = defineProps({ number: { type: String, default: null } });
const emit = defineEmits(['close']);

const nav = useHrNav();
const detail = useGet(() => (props.number ? `/hr/contracts/${encodeURIComponent(props.number)}` : null));
/** `number` may also be the contract's id (an alert names its contract by id). */
const c = computed(() => (detail.data.value && (detail.data.value.number === props.number || detail.data.value.id === props.number) ? detail.data.value : null));
const act = useAction({ invalidate: ['hr'] });
const editing = ref(false);

const post = (action, body, success) => act.run(() => api.postIdempotent(`/hr/contracts/${c.value.id}/${action}`, body), { success });
async function start() {
  if (!(await confirm({ title: { ar: `تشغيل العقد ${c.value.number}؟`, en: `Put contract ${c.value.number} in force?` }, sub: { ar: 'يصبح العقد الساري للموظف — وللموظف عقد سارٍ واحد في الوقت نفسه.', en: 'It becomes the employee’s running contract — an employee has one running contract at a time.' }, tone: 'dark' }))) return;
  await post('start', undefined, t('العقد سارٍ الآن', 'The contract is in force'));
}
async function end() {
  const reason = await ask({ title: { ar: `إنهاء العقد ${c.value.number} بتاريخ اليوم`, en: `End contract ${c.value.number} as of today` }, label: { ar: 'سبب الإنهاء (اختياري)', en: 'Reason (optional)' }, okLabel: { ar: 'إنهاء العقد', en: 'End the contract' } });
  if (reason === null) return;
  await post('end', { date: todayIso(), reason: reason || undefined }, t('أُنهي العقد', 'The contract was ended'));
}
async function cancel() {
  const reason = await ask({ title: { ar: `إلغاء العقد ${c.value.number}`, en: `Cancel contract ${c.value.number}` }, label: { ar: 'سبب الإلغاء (5 أحرف على الأقل)', en: 'Reason (at least 5 characters)' }, required: true, tone: 'danger', okLabel: { ar: 'إلغاء العقد', en: 'Cancel the contract' } });
  if (reason === null) return;
  await post('cancel', { reason }, t('أُلغي العقد', 'The contract was cancelled'));
}
async function renew() {
  if (!(await confirm({ title: { ar: `تجديد العقد ${c.value.number}؟`, en: `Renew contract ${c.value.number}?` }, sub: { ar: 'يُنشأ عقد جديد كمسودة بالبنود نفسها، يبدأ في اليوم التالي لنهاية هذا العقد. راجع مدته وأجره ثم شغِّله.', en: 'A new draft with the same terms is created, starting the day after this one ends. Review its term and pay, then put it in force.' }, tone: 'dark' }))) return;
  const next = await post('renew', undefined, (n) => t(`أُنشئ عقد التجديد ${n.number}`, `Renewal ${n.number} created`));
  if (next) nav.openContract(next.number);
}
</script>

<template>
  <Drawer :open="!!number" :title="c ? c.number : t('عقد عمل', 'Employment contract')" :sub="c?.employee ? `${pname(c.employee)} · ${c.employee.code}` : null" :width="520" @close="emit('close')">
    <ErrorBanner :error="detail.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="!c && !detail.error.value" class="skel h-[160px]" />
    <template v-if="c">
      <div class="row wrap mb-3">
        <Chip :map="CONTRACT_STATE" :k="c.state" />
        <Chip v-if="c.stage" :map="STAGE" :k="c.stage" small />
        <Chip v-if="c.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        <span class="grow" />
        <Btn v-if="c.employee" size="sm" tone="ghost" :label="{ ar: 'بطاقة الموظف', en: 'Employee card' }" @click="nav.openEmployee(c.employee.code, 'contracts')" />
      </div>
      <div v-if="c.locked" class="banner teal"><div>{{ t('هذا العقد مصدره أودو والقسم يتبع أودو: بنوده تُعدَّل هناك. يمكن تجديده هنا بعقد جديد.', 'This contract comes from Odoo and the section follows Odoo: its terms are changed there. It can be renewed here with a new contract.') }}</div></div>
      <div v-if="c.state === 'running' && c.endsInDays != null && c.endsInDays <= 60" class="banner" :class="c.endsInDays < 0 ? 'red' : 'amber'">
        <div>{{ c.endsInDays < 0 ? t(`انتهى تاريخ العقد ${daysText(c.endsInDays)} وما زال ساريًا في النظام — جدِّده أو أنهِه.`, `The contract’s end date passed ${daysText(c.endsInDays)} and it is still running here — renew it or end it.`) : t(`ينتهي العقد ${daysText(c.endsInDays)} (${fmtDay(c.endDate)}).`, `The contract ends ${daysText(c.endsInDays)} (${fmtDay(c.endDate)}).`) }}</div>
      </div>

      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'نوع العقد', en: 'Type' }" :v="c.typeLabel || bi(CONTRACT_TYPE[c.type] || c.type)" />
        <KV :k="{ ar: 'المسمى', en: 'Job title' }" :v="c.jobTitle || '—'" />
        <KV :k="{ ar: 'القسم', en: 'Department' }" :v="c.department ? pname(c.department) : '—'" />
        <KV :k="{ ar: 'المرجع', en: 'Reference' }" :v="c.reference || '—'" />
        <KV :k="{ ar: 'البداية', en: 'Start' }" :v="fmtDay(c.startDate)" ltr />
        <KV :k="{ ar: 'النهاية', en: 'End' }" :v="c.endDate ? fmtDay(c.endDate) : t('غير محدد المدة', 'Open-ended')" :ltr="!!c.endDate" />
        <KV v-if="c.trialEndDate" :k="{ ar: 'نهاية التجربة', en: 'Trial ends' }" :v="fmtDay(c.trialEndDate)" ltr />
      </div>
      <div class="card-title mb-1.5">{{ t('الأجر والبدلات (شهريًا)', 'Pay and allowances (monthly)') }}</div>
      <div class="kv-grid mb-4">
        <KV :k="{ ar: 'الأجر الأساسي', en: 'Basic wage' }" :v="money(c.wage)" ltr />
        <KV :k="{ ar: 'بدل السكن', en: 'Housing' }" :v="money(c.housingAllowance)" ltr />
        <KV :k="{ ar: 'بدل النقل', en: 'Transport' }" :v="money(c.transportAllowance)" ltr />
        <KV :k="{ ar: 'بدلات أخرى', en: 'Other' }" :v="money(c.otherAllowances)" ltr />
        <KV :k="{ ar: 'الإجمالي', en: 'Package' }"><b class="num ltr">{{ money(c.package) }} {{ c.currency }}</b></KV>
      </div>
      <div v-if="c.notes" class="mb-3 whitespace-pre-line text-[11px] leading-[1.8] text-sec" dir="auto">{{ c.notes }}</div>
      <div v-if="c.closedAt" class="hint !mt-0 mb-3">{{ c.state === 'cancelled' ? t('أُلغي', 'Cancelled') : t('أُنهي', 'Ended') }} · {{ c.closedBy }} · <span class="num ltr">{{ fmtAt(c.closedAt) }}</span><template v-if="c.closeReason"> — {{ c.closeReason }}</template></div>
      <div class="text-[9.5px] text-faint">{{ t('أُنشئ', 'Created') }} <span class="num ltr">{{ fmtAt(c.createdAt) }}</span> · {{ c.createdBy }}</div>
    </template>
    <template v-if="c && (c.can.edit || c.can.start || c.can.end || c.can.cancel || c.can.renew || c.locked)" #footer>
      <div class="flex flex-wrap gap-2">
        <Btn v-if="c.can.start" tone="success" :loading="act.pending.value" :label="{ ar: 'تشغيل العقد', en: 'Put in force' }" @click="start" />
        <Btn v-if="c.can.renew" tone="primary" :loading="act.pending.value" :label="{ ar: 'تجديد', en: 'Renew' }" @click="renew" />
        <Btn v-if="c.can.edit || c.locked" tone="outline" :label="c.locked ? { ar: 'إضافة ملاحظة', en: 'Add a note' } : { ar: 'تعديل', en: 'Edit' }" @click="editing = true" />
        <Btn v-if="c.can.end" tone="softAmber" :loading="act.pending.value" :label="{ ar: 'إنهاء', en: 'End' }" @click="end" />
        <Btn v-if="c.can.cancel" tone="dangerOutline" :loading="act.pending.value" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="cancel" />
      </div>
    </template>
  </Drawer>
  <ContractForm :open="editing" :contract="c" @close="editing = false" />
</template>
