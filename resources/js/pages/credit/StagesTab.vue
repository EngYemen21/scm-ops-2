<script setup>
// The stages of the credit workflow, in their order (GET /credit/lookups `stages`): the state a request has in it,
// who decides it (a role, a named person) and how (any one / every approver), its target hours with reminder and
// escalation, what it requires (papers, signature), its flags, how many open requests are in it, and a warning for a
// stage of Odoo that maps to no state («unmapped»). Add / change / archive in StageForm (credit.manage). A stage that
// Odoo owns while the section follows it is changed there. Nothing is deleted: a stage no longer used is archived.
import { computed, ref } from 'vue';
import { Btn, Chip, DataTable, ErrorBanner, TextInput } from '@/components';
import { t } from '@/i18n';
import StageForm from './StageForm.vue';
import { hours, nameOf, useCreditLookups } from './credit';
import { MODE, ORIGIN, STATE, pick } from './labels';

const lk = useCreditLookups();
const search = ref('');
const rows = computed(() => {
  const s = search.value.trim().toLowerCase();
  const all = [...lk.stages.value].sort((a, b) => (a.sequence - b.sequence) || String(a.id).localeCompare(String(b.id)));
  return s ? all.filter((x) => [x.code, x.nameAr, x.nameEn].some((n) => String(n || '').toLowerCase().includes(s))) : all;
});
const form = ref(null);

const columns = [
  { key: 'sequence', header: { ar: 'الترتيب', en: 'Order' }, width: '64px', kind: 'num' },
  { key: 'name', header: { ar: 'المرحلة', en: 'Stage' }, width: 'minmax(160px,1.3fr)' },
  { key: 'state', header: { ar: 'حالة الطلب فيها', en: 'Request state' }, width: '124px', bare: true },
  { key: 'who', header: { ar: 'من يقرّر', en: 'Who decides' }, width: 'minmax(130px,1fr)' },
  { key: 'hours', header: { ar: 'المدد (س)', en: 'Hours' }, width: '112px' },
  { key: 'flags', header: { ar: 'المتطلبات', en: 'Requires' }, width: '150px', bare: true },
  { key: 'requests', header: { ar: 'طلبات مفتوحة', en: 'Open requests' }, width: '72px', kind: 'num' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
  { key: 'act', header: '', width: '76px', bare: true },
];
const FLAGS = [
  ['requireDocuments', { ar: 'الوثائق', en: 'Papers' }], ['requireSignature', { ar: 'التوقيع', en: 'Signature' }], ['autoSendSignature', { ar: 'إرسال التوقيع آليًا', en: 'Auto-send signature' }],
  ['lockRequestFields', { ar: 'قفل البيانات', en: 'Locks fields' }], ['portalVisible', { ar: 'ظاهرة في البوابة', en: 'Portal' }], ['fold', { ar: 'مطوية', en: 'Folded' }],
];
const IN_USE = { ar: 'مستخدمة', en: 'In use', fg: '#1d7a3e', bg: '#e6f9ec' };
const ARCHIVED = { ar: 'مؤرشفة', en: 'Archived', fg: '#55506a', bg: '#F1EFF6' };
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="search" small type="search" class="w-[220px]" :placeholder="{ ar: 'بحث بالاسم أو الرمز…', en: 'Search name or code…' }" />
    <span class="grow" />
    <Btn size="sm" tone="primary" :label="{ ar: '+ مرحلة', en: '+ Stage' }" @click="form = {}" />
  </div>
  <ErrorBanner :error="lk.query.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :rows="rows" :page-size="50" :loading="lk.query.isLoading.value" :row-key="(x) => x.id"
               :empty-text="search.trim() ? { ar: 'لا مرحلة تطابق البحث.', en: 'No stage matches the search.' } : { ar: 'لا مراحل بعد.', en: 'No stage yet.' }">
      <template #cell-name="{ row }">
        <div class="min-w-0" :class="{ 'opacity-50': !row.active }">
          <div class="cell-name truncate" dir="auto">{{ nameOf(row) }}</div>
          <div class="cell-sub num ltr truncate">{{ row.code }}</div>
          <div v-if="row.unmapped" class="text-[9px] font-extrabold text-bad">{{ t('غير مربوطة بحالة — راجع إعدادها', 'Not mapped to a state — check it') }}</div>
        </div>
      </template>
      <template #cell-state="{ row }"><Chip small :label="pick(STATE, row.state)" :fg="pick(STATE, row.state).fg" :bg="pick(STATE, row.state).bg" /></template>
      <template #cell-who="{ row }">
        <div class="min-w-0 text-[10px]">
          <div v-if="row.responsibleUser" class="truncate text-ink" dir="auto">{{ nameOf(row.responsibleUser) }}<span v-if="row.responsibleUser.linked === false" class="text-[8.5px] text-faint"> · {{ t('غير مرتبط بمستخدم', 'not linked to a user') }}</span></div>
          <div v-if="row.responsibleRole" class="truncate text-sec" dir="auto">{{ t('دور', 'Role') }}: {{ nameOf(row.responsibleRole) }}</div>
          <div v-if="!row.responsibleUser && !row.responsibleRole" class="text-faint">{{ row.work ? t('لم يُحدَّد', 'Not set') : '—' }}</div>
          <div v-if="row.work" class="cell-sub">{{ t(MODE[row.approvalMode]?.ar || row.approvalMode || '—', MODE[row.approvalMode]?.en || row.approvalMode || '—') }}</div>
        </div>
      </template>
      <template #cell-hours="{ row }">
        <div class="text-[9.5px] leading-[1.6] text-sec">
          <div>{{ t('الهدف', 'Target') }} <span class="num">{{ hours(row.slaHours) }}</span></div>
          <div>{{ t('تذكير قبل', 'Remind') }} <span class="num">{{ hours(row.reminderBeforeHours) }}</span></div>
          <div>{{ t('تصعيد بعد', 'Escalate') }} <span class="num">{{ hours(row.escalateAfterHours) }}</span><template v-if="row.escalationRole"> → <span dir="auto">{{ nameOf(row.escalationRole) }}</span></template></div>
        </div>
      </template>
      <template #cell-flags="{ row }">
        <span class="inline-flex flex-wrap justify-center gap-1">
          <template v-for="[k, l] in FLAGS" :key="k"><Chip v-if="row[k]" small :label="l" fg="#654e92" bg="#efeaf8" /></template>
          <span v-if="!FLAGS.some(([k]) => row[k])" class="text-faint">—</span>
        </span>
      </template>
      <template #cell-requests="{ row }"><span :class="row.requests ? 'font-extrabold text-ink' : 'text-faint'">{{ row.requests || 0 }}</span></template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center justify-center gap-1">
          <Chip small :label="row.active ? IN_USE : ARCHIVED" :fg="(row.active ? IN_USE : ARCHIVED).fg" :bg="(row.active ? IN_USE : ARCHIVED).bg" />
          <Chip v-if="row.origin === 'odoo'" small :label="ORIGIN.odoo" :fg="ORIGIN.odoo.fg" :bg="ORIGIN.odoo.bg" />
        </span>
      </template>
      <template #cell-act="{ row }">
        <Btn v-if="!row.ownedBySource" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="form = row" />
        <span v-else class="text-[9px] text-faint" :title="t('تُعدَّل في أودو', 'Changed in Odoo')">{{ t('في أودو', 'in Odoo') }}</span>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('لا تُحذف المراحل: ما لم يعد مستخدمًا يُؤرشف. المسار الذي تمر به الطلبات هو المراحل المستخدمة بترتيبها.', 'Stages are never deleted: what is no longer used is archived. The path requests follow is the stages in use, in their order.') }}</div>

  <StageForm :stage="form" @close="form = null" />
</template>
