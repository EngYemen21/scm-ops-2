<script setup>
// «دفاتر أودو» — the bank and cash journals of Odoo and the OPS bank account each of them is (GET /banking/journals):
// linked or not, kind, active, search and paging in the query string, with the counts of `summary`. The projection
// links by identity only (same IBAN, number or name); what it could not link waits here for a person:
//   «ربط» (bankstatement.manage)  POST /banking/journals/{id}/link { bankAccount }
//   «فك الربط» (bankstatement.manage, a reason)  POST …/unlink { reason } — the projection will not link it again by itself
//   «إنشاء حساب له» (bankstatement.manage + bank.manage)  POST …/adopt { code, nameAr, nameEn } — finance's own service makes it
// Account numbers come masked from the server for a reader who may not see them — shown as they come.
import { computed, reactive, ref } from 'vue';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, Modal, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask } from '@/stores/ui';
import { JOURNAL_KIND, LINKED_BY, nameOf, optionsOf, useBankingLookups, useBankingNav, useSearchParam } from './banking';

const auth = useAuth();
const nav = useBankingNav();
const lk = useBankingLookups();
const canManage = computed(() => auth.can('bankstatement.manage'));
const canAdopt = computed(() => canManage.value && !!lk.can.value.createBank);
const { text, type, apply } = useSearchParam(nav);

const LINKED = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: '0', label: { ar: 'غير المربوطة', en: 'Not linked' } }, { k: '1', label: { ar: 'المربوطة', en: 'Linked' } }];
const linked = computed(() => (['0', '1'].includes(nav.get('linked')) ? nav.get('linked') : ''));
const ACTIVE = [{ v: '1', l: { ar: 'النشطة في أودو', en: 'Active in Odoo' } }, { v: '0', l: { ar: 'المؤرشفة في أودو', en: 'Archived in Odoo' } }];
const SORTS = ['name', 'kind', 'linked'];
const sort = computed(() => (SORTS.includes(nav.get('sort')) ? { key: nav.get('sort'), order: nav.get('order') === 'desc' ? 'desc' : 'asc' } : null));
const setSort = (s) => nav.patch({ sort: s.key, order: s.order, page: null });
const list = useList('/banking/journals', () => ({
  linked: linked.value || undefined, kind: JOURNAL_KIND[nav.get('kind')] ? nav.get('kind') : undefined, active: ['0', '1'].includes(nav.get('active')) ? nav.get('active') : undefined,
  q: nav.get('q') || undefined, sort: sort.value?.key, order: sort.value?.order, page: nav.page.value, pageSize: 25,
}));
const summary = computed(() => list.data.value?.summary || null);
const filtered = computed(() => ['linked', 'kind', 'active', 'q'].some((k) => !!nav.get(k)));
const clear = () => nav.patch({ linked: null, kind: null, active: null, q: null, page: null });

const columns = [
  { key: 'name', header: { ar: 'الدفتر في أودو', en: 'Odoo journal' }, width: 'minmax(170px,1.5fr)', sortable: true },
  { key: 'kind', header: { ar: 'النوع', en: 'Kind' }, width: '80px', bare: true, sortable: true },
  { key: 'account', header: { ar: 'البنك والحساب', en: 'Bank & number' }, width: 'minmax(140px,1.2fr)' },
  { key: 'linked', header: { ar: 'حساب OPS', en: 'OPS account' }, width: 'minmax(150px,1.2fr)', sortable: true },
  { key: 'counts', header: { ar: 'كشوف / سطور', en: 'Statements / lines' }, width: '96px', kind: 'num' },
  { key: 'actions', header: '', width: '190px', bare: true },
];

// ---- actions
const act = useAction({ invalidate: ['banking'] });
const linking = ref(null);
const linkBank = ref('');
function startLink(j) { linking.value = j; linkBank.value = ''; act.clearError(); }
async function link() {
  if (!linkBank.value) return;
  const r = await act.run(() => api.postIdempotent(`/banking/journals/${linking.value.id}/link`, { bankAccount: linkBank.value }), { success: (j) => t(`رُبط الدفتر «${j.name}» بالحساب ${j.bank?.code || ''}`, `Journal “${j.name}” linked to ${j.bank?.code || ''}`) });
  if (r) linking.value = null;
}
async function unlink(j) {
  const reason = await ask({ title: { ar: `فك ربط الدفتر «${j.name}» عن ${j.bank?.code || ''}`, en: `Unlink journal “${j.name}” from ${j.bank?.code || ''}` }, label: { ar: 'السبب (5 أحرف على الأقل) — لن يُربط تلقائيًا مرة أخرى', en: 'Reason (5 characters at least) — it will not be linked automatically again' }, required: true, tone: 'danger', okLabel: { ar: 'فك الربط', en: 'Unlink' } });
  if (reason === null) return;
  await act.run(() => api.postIdempotent(`/banking/journals/${j.id}/unlink`, { reason }), { success: t('فُك الربط', 'Unlinked') });
}
const adopting = ref(null);
const adopt = reactive({ code: '', nameAr: '', nameEn: '' });
const adoptErrors = ref({});
function startAdopt(j) {
  adopting.value = j;
  Object.assign(adopt, { code: String(j.code || '').toUpperCase().replace(/[^A-Z0-9_-]/g, '').slice(0, 20), nameAr: j.name || '', nameEn: j.name || '' });
  adoptErrors.value = {};
  act.clearError();
}
async function doAdopt() {
  const code = adopt.code.trim().toUpperCase();
  if (!/^[A-Z0-9][A-Z0-9_-]{1,19}$/.test(code)) { adoptErrors.value = { code: t('من حرفين إلى عشرين: حروف لاتينية وأرقام و - و _', '2 to 20 characters: Latin letters, digits, - and _') }; return; }
  adoptErrors.value = {};
  const r = await act.run(() => api.postIdempotent(`/banking/journals/${adopting.value.id}/adopt`, { code, nameAr: adopt.nameAr.trim() || null, nameEn: adopt.nameEn.trim() || null }), {
    success: (j) => t(`أُنشئ الحساب ${j.bank?.code || code} ورُبط بالدفتر`, `Bank account ${j.bank?.code || code} created and linked`), invalidate: ['banking', 'finance'],
  });
  if (r) adopting.value = null;
}
</script>

<template>
  <KpiGrid compact>
    <KpiCard :value="summary?.all" :label="{ ar: 'دفاتر أودو', en: 'Odoo journals' }" :loading="list.isLoading.value" />
    <KpiCard :value="summary?.linked" :label="{ ar: 'مربوطة بحساب OPS', en: 'Linked to an OPS account' }" color="#1d7a3e" :loading="list.isLoading.value" clickable :active="linked === '1'" @click="nav.setFilter('linked', '1')" />
    <KpiCard :value="summary?.unlinked" :label="{ ar: 'نشطة بانتظار الربط', en: 'Active, waiting for a link' }" :color="summary?.unlinked ? '#b26a16' : '#1d7a3e'" :loading="list.isLoading.value" clickable :active="linked === '0'" @click="nav.setFilter('linked', '0')" />
  </KpiGrid>
  <div class="row wrap mb-2">
    <TextInput small type="search" class="w-[240px]" :model-value="text" :placeholder="{ ar: 'بحث بالاسم، الرمز، البنك، الحساب…', en: 'Search name, code, bank, account…' }" @update:model-value="type" @enter="apply" />
    <Tabs :model-value="linked" :tabs="LINKED" variant="pill" class="!mb-0" @update:model-value="(k) => nav.setFilter('linked', k)" />
    <SelectInput small class="w-[120px]" :model-value="nav.get('kind')" :options="optionsOf(JOURNAL_KIND)" :placeholder="{ ar: 'كل الأنواع', en: 'Every kind' }" @update:model-value="(v) => nav.setFilter('kind', v)" />
    <SelectInput small class="w-[160px]" :model-value="nav.get('active')" :options="ACTIVE" :placeholder="{ ar: 'نشطة ومؤرشفة', en: 'Active and archived' }" @update:model-value="(v) => nav.setFilter('active', v)" />
    <Btn v-if="filtered" size="sm" tone="ghost" :label="{ ar: 'إزالة الفلاتر', en: 'Clear filters' }" @click="clear" />
  </div>
  <ErrorBanner :error="list.error.value || (linking || adopting ? null : act.error.value)" :closable="!list.error.value" @close="act.clearError()" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isFetching.value" :row-key="(r) => r.id" :sort="sort" server-sort
               :empty-text="filtered ? { ar: 'لا دفاتر تطابق الفلاتر.', en: 'No journal fits the filters.' } : { ar: 'لا دفاتر من أودو — تظهر هنا بعد مزامنة أودو.', en: 'No Odoo journal — they appear here once Odoo is synchronised.' }"
               @sort="setSort" @page="nav.setPage">
      <template #cell-name="{ row }">
        <div class="min-w-0">
          <div class="cell-name truncate" dir="auto">{{ row.name }}</div>
          <div class="cell-sub">{{ row.code }}<template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template><template v-if="row.ledgerAccount"> · {{ row.ledgerAccount }}</template><template v-if="row.currency"> · {{ row.currency }}</template></div>
          <div v-if="!row.active || row.sourceDeletedAt" class="text-[9px] font-bold text-warn">{{ row.sourceDeletedAt ? t('حُذف من أودو', 'Deleted in Odoo') : t('مؤرشف في أودو', 'Archived in Odoo') }}</div>
        </div>
      </template>
      <template #cell-kind="{ row }"><Chip small :map="JOURNAL_KIND" :k="row.kind" /></template>
      <template #cell-account="{ row }"><div class="min-w-0"><div class="truncate text-[10px]" dir="auto">{{ row.bankName || '—' }}</div><div v-if="row.accountNumber" class="cell-sub">{{ row.accountNumber }}</div></div></template>
      <template #cell-linked="{ row }">
        <div v-if="row.bank" class="min-w-0">
          <div class="truncate text-[10.5px] font-bold text-ok" dir="auto">{{ row.bank.code }} · {{ nameOf(row.bank) }}</div>
          <div class="truncate text-[9px] text-muted">{{ bi(LINKED_BY[row.linkedBy] || { ar: row.linkedBy || '', en: row.linkedBy || '' }) }}<template v-if="row.linkedUser"> · <span dir="auto">{{ row.linkedUser }}</span></template><template v-if="row.linkedAt"> · <span class="num ltr">{{ fmtDate(row.linkedAt) }}</span></template></div>
        </div>
        <span v-else class="text-[10px] font-bold text-warn">{{ t('غير مربوط', 'Not linked') }}</span>
      </template>
      <template #cell-counts="{ row }"><span class="num">{{ row.statements }} / {{ row.lines }}</span></template>
      <template #cell-actions="{ row }">
        <span v-if="canManage" class="inline-flex flex-wrap justify-center gap-1">
          <template v-if="row.linked"><Btn size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'فك الربط', en: 'Unlink' }" @click.stop="unlink(row)" /></template>
          <template v-else>
            <Btn size="sm" tone="softPurple" :label="{ ar: 'ربط بحساب', en: 'Link' }" @click.stop="startLink(row)" />
            <Btn v-if="canAdopt" size="sm" tone="soft" :label="{ ar: 'إنشاء حساب له', en: 'Create account' }" @click.stop="startAdopt(row)" />
          </template>
        </span>
      </template>
    </DataTable>
  </div>

  <Modal :open="!!linking" :width="460" :title="{ ar: `ربط الدفتر «${linking?.name || ''}»`, en: `Link journal “${linking?.name || ''}”` }" @close="linking = null">
    <div class="hint !mt-0 mb-3">{{ t('اختر حساب OPS الذي هو هذا الدفتر. الحساب الواحد يقابل دفترًا واحدًا، وتنتقل إليه كشوف الدفتر التاريخية.', 'Choose the OPS bank account this journal is. One account answers to one journal; the journal’s history statements move to it.') }}</div>
    <SelectInput v-model="linkBank" full required :options="lk.bankOptions.value" :label="{ ar: 'حساب OPS', en: 'OPS bank account' }" :placeholder="{ ar: '— اختر —', en: '— choose —' }" />
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="flex-1" :disabled="!linkBank" :loading="act.pending.value" :label="{ ar: 'ربط', en: 'Link' }" @click="link" />
        <Btn tone="soft" class="w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="linking = null" />
      </div>
    </template>
  </Modal>

  <Modal :open="!!adopting" :width="480" :title="{ ar: `إنشاء حساب بنكي للدفتر «${adopting?.name || ''}»`, en: `Create a bank account for “${adopting?.name || ''}”` }" @close="adopting = null">
    <div class="hint !mt-0 mb-3">{{ t('تنشئه خدمة المالية نفسها (مع حساب الأستاذ تحت البنوك والصناديق) ثم يُربط بالدفتر. رقم الحساب لا يُنسخ — يُدخل في المالية.', 'Finance’s own service makes it (with its ledger account under banks and cash) and it is linked to the journal. The account number is not copied — it is typed in finance.') }}</div>
    <div class="form-grid">
      <TextInput v-model="adopt.code" full required dir="ltr" :label="{ ar: 'الرمز', en: 'Code' }" :error="adoptErrors.code" />
      <TextInput v-model="adopt.nameAr" :label="{ ar: 'الاسم بالعربية', en: 'Name (Arabic)' }" />
      <TextInput v-model="adopt.nameEn" dir="ltr" :label="{ ar: 'الاسم بالإنجليزية', en: 'Name (English)' }" />
    </div>
    <template #footer>
      <ErrorBanner :error="act.error.value" @close="act.clearError()" />
      <div class="flex gap-2">
        <Btn tone="dark" class="flex-1" :loading="act.pending.value" :label="{ ar: 'إنشاء وربط', en: 'Create and link' }" @click="doAdopt" />
        <Btn tone="soft" class="w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="adopting = null" />
      </div>
    </template>
  </Modal>
</template>
