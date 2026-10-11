<script setup>
// Journal detail: header, lines (account · debit · credit · dimensions), links to the source document and the
// reversal pair; actions for drafts (approve / delete) and posted journals (reverse) by permission.
//   <JournalDrawer :number="numberOrNull" @close @changed />
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner } from '@/components';
import { fmtDate, fmtDateOnly, fmtMoney, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import ReasonDrawer from '@/pages/procurement/ReasonDrawer.vue';
import { JOURNAL_KIND_LABELS, JOURNAL_STATUS_LABELS, pname, sourceLink } from './shared';

const props = defineProps({ number: { type: String, default: null } });
const emit = defineEmits(['close', 'changed']);
const auth = useAuth();
const router = useRouter();
const act = useAction({ invalidate: ['finance'] });
const q = useGet(() => (props.number ? `/finance/journals/${encodeURIComponent(props.number)}` : null));
const j = computed(() => (props.number ? q.data.value : null));
const reversing = ref(false);
const link = computed(() => (j.value ? sourceLink(j.value.sourceType, j.value.sourceNumber) : null));

async function approve() {
  const yes = await confirm({ title: { ar: `اعتماد وترحيل ${j.value.number}؟`, en: `Approve and post ${j.value.number}?` }, sub: { ar: `${fmtMoney(j.value.totalDebit)} ر.س — الاعتماد نهائي؛ التصحيح بعده بقيد عكسي`, en: 'Posting is final; corrections are reversals' }, tone: 'dark', okLabel: { ar: 'اعتماد', en: 'Approve' } });
  if (!yes) return;
  const r = await act.run(() => api.postIdempotent(`/finance/journals/${j.value.number}/approve`, {}), { success: t(`رُحّل ${j.value.number}`, `${j.value.number} posted`) });
  if (r) { q.refetch(); emit('changed'); }
}
async function discard() {
  const yes = await confirm({ title: { ar: `حذف المسودة ${j.value.number}؟`, en: `Delete draft ${j.value.number}?` }, okLabel: { ar: 'حذف', en: 'Delete' } });
  if (!yes) return;
  const r = await act.run(() => api.del(`/finance/journals/${j.value.number}`), { success: t('حُذفت المسودة', 'Draft deleted') });
  if (r) { emit('changed'); emit('close'); }
}
async function reverse(reason) {
  const r = await act.run(() => api.postIdempotent(`/finance/journals/${j.value.number}/reverse`, { reason }), { success: (d) => t(`أُنشئ القيد العكسي ${d.number}`, `Reversal ${d.number} posted`) });
  if (r) { reversing.value = false; q.refetch(); emit('changed'); }
}
const columns = [
  { key: 'lineNo', header: '#', width: '30px', kind: 'num' },
  { key: 'account', header: { ar: 'الحساب', en: 'Account' }, width: 'minmax(170px,1.6fr)' },
  { key: 'debit', header: { ar: 'مدين', en: 'Debit' }, width: '100px', kind: 'num', value: (l) => (Number(l.debit) ? fmtMoney(l.debit) : '') },
  { key: 'credit', header: { ar: 'دائن', en: 'Credit' }, width: '100px', kind: 'num', value: (l) => (Number(l.credit) ? fmtMoney(l.credit) : '') },
  { key: 'dims', header: { ar: 'الأبعاد', en: 'Dimensions' }, width: 'minmax(140px,1.2fr)' },
];
</script>

<template>
  <Drawer :open="!!number" :width="760" :title="j ? j.number : t('قيد', 'Journal')" :sub="j ? `${fmtDateOnly(j.journalDate)} · ${j.period?.code} · ${j.createdBy}${j.approvedBy ? ' · ' + t('اعتمده', 'approved by') + ' ' + j.approvedBy : ''}` : null" @close="emit('close')">
    <template #headExtra><div v-if="j" class="row !gap-1"><Chip :map="JOURNAL_STATUS_LABELS" :k="j.status" small /><Chip :map="JOURNAL_KIND_LABELS" :k="j.kind" small /></div></template>
    <ErrorBanner :error="q.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="q.isLoading.value" class="skel min-h-[120px]" />
    <template v-if="j">
      <div class="kv-grid mb-3">
        <div class="kv col-span-full"><div class="kv-k">{{ t('البيان', 'Memo') }}</div><div class="kv-v !font-normal">{{ j.memo }}</div></div>
        <div v-if="j.sourceType" class="kv"><div class="kv-k">{{ t('المستند المصدر', 'Source document') }}</div><div class="kv-v num" :class="link ? 'cursor-pointer !text-violet' : ''" @click="link && router.push(link)">{{ j.sourceType }} · {{ j.sourceNumber }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('الإجمالي', 'Total') }}</div><div class="kv-v num">{{ fmtMoney(j.totalDebit) }} = {{ fmtMoney(j.totalCredit) }}</div></div>
        <div v-if="j.reversalOf" class="kv"><div class="kv-k">{{ t('يعكس القيد', 'Reverses') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/jv/${j.reversalOf.number}`)">{{ j.reversalOf.number }}</div></div>
        <div v-if="j.reversedBy" class="kv"><div class="kv-k">{{ t('عُكس بالقيد', 'Reversed by') }}</div><div class="kv-v num cursor-pointer !text-violet" @click="router.push(`/jv/${j.reversedBy.number}`)">{{ j.reversedBy.number }}</div></div>
        <div v-if="j.postedAt" class="kv"><div class="kv-k">{{ t('رُحّل', 'Posted') }}</div><div class="kv-v ltr num">{{ fmtDate(j.postedAt) }}</div></div>
      </div>
      <div class="overflow-hidden rounded-xl border border-line-2">
        <DataTable :columns="columns" :rows="j.lines" :row-key="(l) => l.lineNo" :min-width="620">
          <template #cell-account="{ row }"><div><div class="text-[10.5px] font-extrabold">{{ row.account.code }} · {{ pname(row.account) }}</div><div v-if="row.description" class="text-[8.5px] text-faint">{{ row.description }}</div></div></template>
          <template #cell-dims="{ row }">
            <div class="text-[9px] text-muted">
              <div v-if="row.partner">{{ row.partnerType === 'customer' ? t('عميل', 'Customer') : t('مورد', 'Supplier') }}: {{ pname(row.partner) }}</div>
              <div v-if="row.costCenter">{{ t('مركز تكلفة', 'CC') }}: {{ row.costCenter.code }}</div>
            </div>
          </template>
        </DataTable>
      </div>
    </template>
    <template v-if="j" #footer>
      <div class="row wrap !gap-1.5">
        <template v-if="j.status === 'draft'">
          <Btn v-if="auth.can('gl.approve')" tone="success" :loading="act.pending.value" :label="{ ar: 'اعتماد وترحيل', en: 'Approve & post' }" @click="approve" />
          <Btn v-if="auth.can('gl.post')" tone="dangerOutline" :label="{ ar: 'حذف المسودة', en: 'Delete draft' }" @click="discard" />
        </template>
        <Btn v-if="j.status === 'posted' && auth.can('gl.approve')" tone="dangerOutline" :label="{ ar: 'قيد عكسي', en: 'Reverse' }" @click="reversing = true" />
        <Btn tone="soft" :label="{ ar: 'فتح الصفحة', en: 'Open page' }" @click="router.push(`/jv/${encodeURIComponent(j.number)}`)" />
      </div>
    </template>
  </Drawer>
  <Teleport v-if="reversing" to="body">
    <ReasonDrawer open :title="{ ar: `قيد عكسي لـ ${j?.number}`, en: `Reverse ${j?.number}` }" :sub="{ ar: 'يُنشأ قيد جديد بسطور معكوسة ويُربط بالقيد الأصلي؛ القيد الأصلي لا يُمس', en: 'A new journal with swapped lines, linked to the original; the original is untouched' }" :ok-label="{ ar: 'ترحيل القيد العكسي', en: 'Post reversal' }" :min="5"
                  :pending="act.pending.value" :error="act.error.value" @submit="reverse" @close="reversing = false" @clear-error="act.clearError()" />
  </Teleport>
</template>
