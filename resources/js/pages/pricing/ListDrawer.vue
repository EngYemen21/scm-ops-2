<script setup>
// One price list in a drawer (`list=<code>` in the query string, on any tab): its head, what may be done with it, and
// two sub-tabs — its rules (search · what they cover · state, paged on the server) and the customers priced with it.
//   GET /pricing/lists/{code} · /lists/{code}/rules · /lists/{code}/customers
//   pricelist.manage: edit · default / not default · archive / restore · copy · paste prices · add / edit / archive rules ·
//                     put a customer on the list / take one off
// A list that came from Odoo is read-only while the section follows Odoo (`owned`): it offers «نسخ» — a copy of it is a
// list of OPS — and customers of OPS may still be put on it. What stands in the way of archiving is said before the
// button is pressed (default list · customers on it · rules of other lists that start from it).
import { computed, ref } from 'vue';
import { api, useAction, useGet, useList } from '@/api/client';
import { Btn, Chip, DataTable, Drawer, ErrorBanner, KV, SelectInput, Tabs, TextInput } from '@/components';
import { bi, fmtAgo, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import ListForm from './ListForm.vue';
import PasteDrawer from './PasteDrawer.vue';
import PickRecord from './PickRecord.vue';
import RuleForm from './RuleForm.vue';
import { APPLIES, ARCHIVED_CHIP, BASIS, BASIS_HELP, DEFAULT_CHIP, OWNED_CHIP, PERIOD, WAITING_CHIP, nameOf, periodText, plain, ruleTarget, usePricingNav, useSearchParam } from './pricing';

const props = defineProps({
  /** The code of the open list (from the query string), or null. */
  code: { type: String, default: null },
});
const emit = defineEmits(['close']);

const auth = useAuth();
const nav = usePricingNav();
const canManage = computed(() => auth.can('pricelist.manage'));
const path = computed(() => (props.code ? `/pricing/lists/${encodeURIComponent(props.code)}` : null));
const detailQ = useGet(path);
const list = computed(() => (props.code ? detailQ.data.value : null));
const editable = computed(() => canManage.value && !!list.value && !list.value.owned);

const sub = computed(() => (nav.get('lt') === 'customers' ? 'customers' : 'rules'));
const subs = computed(() => [
  { k: 'rules', label: { ar: 'القواعد', en: 'Rules' }, badge: list.value?.activeRulesCount },
  { k: 'customers', label: { ar: 'العملاء', en: 'Customers' }, badge: list.value?.customersCount },
]);

// ── rules
const RSTATES = [{ k: 'active', label: { ar: 'الفعّالة', en: 'Active' } }, { k: 'pending', label: { ar: 'بانتظار الربط', en: 'Waiting' } }, { k: 'archived', label: { ar: 'المؤرشفة', en: 'Archived' } }, { k: 'all', label: { ar: 'الكل', en: 'All' } }];
const COVERS = Object.entries(APPLIES).map(([v, l]) => ({ v, l: { ar: l.ar, en: l.en } }));
const rstate = computed(() => (RSTATES.some((s) => s.k === nav.get('rstate')) ? nav.get('rstate') : 'active'));
const rpage = computed(() => Math.max(1, Number(nav.get('rpage')) || 1));
const { text: rtext, type: rtype, apply: rapply } = useSearchParam(nav, 'rq', 'rpage');
const rules = useList(() => (path.value && sub.value === 'rules' ? `${path.value}/rules` : null),
  () => ({ q: nav.get('rq'), state: rstate.value, appliesTo: nav.get('rapplies') || undefined, page: rpage.value, pageSize: 25 }));
const ruleCols = computed(() => [
  { key: 'target', header: { ar: 'تنطبق على', en: 'Covers' }, width: 'minmax(200px,1.7fr)' },
  { key: 'minQty', header: { ar: 'من كمية', en: 'From qty' }, width: '70px', kind: 'num', value: (r) => plain(r.minQty) },
  { key: 'how', header: { ar: 'السعر', en: 'Price' }, width: 'minmax(170px,1.3fr)' },
  { key: 'validity', header: { ar: 'المدة', en: 'Period' }, width: 'minmax(120px,0.9fr)' },
  { key: 'flags', header: { ar: 'الحالة', en: 'Status' }, width: '112px', bare: true },
  { key: 'actions', header: '', width: '128px', bare: true, hidden: !editable.value },
]);

// ── customers of the list
const cpage = computed(() => Math.max(1, Number(nav.get('rpage')) || 1));
const customers = useList(() => (path.value && sub.value === 'customers' ? `${path.value}/customers` : null), () => ({ page: cpage.value, pageSize: 25 }));
const customerCols = computed(() => [
  { key: 'customer', header: { ar: 'العميل', en: 'Customer' }, width: 'minmax(200px,1.8fr)' },
  { key: 'city', header: { ar: 'المدينة', en: 'City' }, width: 'minmax(90px,0.7fr)', value: (c) => c.city || '—' },
  { key: 'basis', header: { ar: 'كيف عُرفت', en: 'How it is known' }, width: '176px', bare: true },
  { key: 'actions', header: '', width: '110px', bare: true, hidden: !canManage.value },
]);
const adding = ref('');

// ── what may be done
const act = useAction({ invalidate: ['pricing'] });
const post = (suffix, body, success) => act.run(() => api.postIdempotent(`/pricing/lists/${encodeURIComponent(list.value.id)}/${suffix}`, body), { success });
/** Why the list cannot be archived right now — said before the button is pressed. */
const archiveBlock = computed(() => {
  const l = list.value;
  if (!l || !l.active) return null;
  if (l.isDefault) return t('هذه القائمة الافتراضية — اجعل قائمة أخرى افتراضية أولًا', 'This is the default list — make another list the default first');
  if (l.customersCount > 0) return t(`${fmtNum(l.customersCount)} عميلًا يُسعَّرون بها — انقلهم أولًا`, `${fmtNum(l.customersCount)} customers are priced with it — move them first`);
  if (l.baseFor > 0) return t(`${fmtNum(l.baseFor)} قاعدة في قوائم أخرى تبدأ من أسعارها`, `${fmtNum(l.baseFor)} rules of other lists start from its prices`);
  return null;
});
async function archive() {
  const reason = await ask({ title: { ar: `أرشفة «${list.value.name}»؟`, en: `Archive “${list.value.name}”?` }, label: { ar: 'السبب (اختياري)', en: 'Reason (optional)' }, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } });
  if (reason === null) return;
  await post('archive', { reason }, { ar: 'أُرشفت القائمة — تبقى مقروءة ولا تُسعِّر أحدًا', en: 'List archived — it stays readable and prices nobody' });
}
async function makeDefault() {
  if (!(await confirm({ title: { ar: `جعل «${list.value.name}» القائمة الافتراضية؟`, en: `Make “${list.value.name}” the default list?` }, sub: { ar: 'كل عميل بلا قائمة خاصة سيُسعَّر بها من الآن.', en: 'Every customer without a list of its own is priced with it from now on.' }, tone: 'dark' }))) return;
  await post('default', undefined, { ar: 'صارت القائمة الافتراضية ✓', en: 'It is the default list now ✓' });
}
async function ruleAction(r, verb) {
  if (verb === 'archive' && !(await confirm({ title: { ar: 'أرشفة القاعدة؟', en: 'Archive the rule?' }, sub: `${ruleTarget(r)} — ${bi(r.text)}`, tone: 'danger', okLabel: { ar: 'أرشفة', en: 'Archive' } }))) return;
  await act.run(() => api.postIdempotent(`/pricing/rules/${encodeURIComponent(r.id)}/${verb}`), { success: verb === 'archive' ? { ar: 'أُرشفت القاعدة', en: 'Rule archived' } : { ar: 'استُعيدت القاعدة ✓', en: 'Rule restored ✓' } });
}
async function addCustomer() {
  if (!adding.value) return;
  const r = await act.run(() => api.postIdempotent(`/pricing/customers/${encodeURIComponent(adding.value)}/assign`, { list: list.value.id }), { success: { ar: 'أُسندت القائمة للعميل ✓', en: 'The customer is on the list ✓' } });
  if (r) adding.value = '';
}
async function removeCustomer(c) {
  if (!(await confirm({ title: { ar: `إخراج ${nameOf(c)} من القائمة؟`, en: `Take ${nameOf(c)} off the list?` }, sub: { ar: 'يعود العميل إلى القائمة الافتراضية.', en: 'The customer goes back to the default list.' }, tone: 'danger' }))) return;
  await act.run(() => api.postIdempotent(`/pricing/customers/${encodeURIComponent(c.code)}/unassign`), { success: { ar: 'عاد العميل إلى القائمة الافتراضية', en: 'The customer is back on the default list' } });
}

// ── forms
const editing = ref(false);
const copying = ref(false);
const pasting = ref(false);
/** `{}` = a new rule, a rule = that rule being changed, null = closed. */
const ruleForm = ref(null);
function copied(copy) { copying.value = false; if (copy?.code) nav.openList(copy.code); }
/** The form refused a twin: show the rule that is already there. */
function findRule(q) { ruleForm.value = null; nav.patch({ lt: null, rq: q, rstate: null, rapplies: null, rpage: null }); }
</script>

<template>
  <Drawer :open="!!code" :width="900" :title="list ? list.name : t('قائمة أسعار', 'Price list')" :sub="list ? `${list.code} · ${list.currency}` : null" @close="emit('close')">
    <template #headExtra>
      <div v-if="list" class="row wrap !gap-1">
        <Chip v-if="list.isDefault" small :label="DEFAULT_CHIP" :fg="DEFAULT_CHIP.fg" :bg="DEFAULT_CHIP.bg" />
        <Chip v-if="!list.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
        <Chip v-else-if="list.period !== 'valid'" small :map="PERIOD" :k="list.period" />
        <Chip v-if="list.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
      </div>
    </template>

    <ErrorBanner :error="detailQ.error.value || act.error.value" @close="act.clearError()" />
    <div v-if="detailQ.isLoading.value" class="skel h-[120px]" />
    <template v-else-if="list">
      <div v-if="list.owned" class="hint purple !mt-0 mb-3">
        {{ t('هذه القائمة قادمة من أودو والقسم ما زال يتبعه: تُعرض هنا وتُسعَّر بها الطلبات، وتُعدَّل في أودو. للتسعير بشكل مختلف انسخها إلى قائمة خاصة بالنظام.',
          'This list comes from Odoo and the section still follows it: it is shown here and prices orders, and is changed in Odoo. To price differently, copy it into a list of OPS.') }}
      </div>
      <div class="kv-grid mb-3">
        <KV :k="{ ar: 'الصلاحية', en: 'Validity' }">{{ periodText(list.validFrom, list.validTo) }}</KV>
        <KV :k="{ ar: 'القواعد الفعّالة', en: 'Active rules' }" ltr>{{ fmtNum(list.activeRulesCount) }}<span v-if="list.pendingRules" class="ms-1 text-[9px] font-bold text-warn">{{ t(`(${fmtNum(list.pendingRules)} بانتظار الربط)`, `(${fmtNum(list.pendingRules)} waiting)`) }}</span></KV>
        <KV :k="{ ar: 'العملاء', en: 'Customers' }" ltr>{{ fmtNum(list.customersCount) }}</KV>
        <KV :k="{ ar: 'آخر تعديل', en: 'Last change' }">{{ list.updatedAt ? fmtAgo(list.updatedAt) : '—' }}<span v-if="list.updatedBy" class="num ltr ms-1 text-muted">{{ list.updatedBy }}</span></KV>
        <KV v-if="list.externalRef" :k="{ ar: 'رقمها في أودو', en: 'Odoo id' }" ltr>#{{ list.externalRef }}</KV>
        <KV v-if="list.note" :k="{ ar: 'ملاحظة', en: 'Note' }" class="col-span-full"><span class="font-normal" dir="auto">{{ list.note }}</span></KV>
      </div>

      <Tabs :model-value="sub" :tabs="subs" variant="sm" @update:model-value="(k) => nav.patch({ lt: k === 'rules' ? null : k, rpage: null })" />

      <!-- ── rules -->
      <template v-if="sub === 'rules'">
        <div class="row wrap mb-2">
          <TextInput small type="search" class="w-[220px]" :model-value="rtext" :placeholder="{ ar: 'بحث بالمنتج أو الفئة…', en: 'Search product or category…' }" @update:model-value="rtype" @enter="rapply" />
          <SelectInput small class="w-[130px]" :model-value="nav.get('rapplies')" :options="COVERS" :placeholder="{ ar: 'كل الأهداف', en: 'Any target' }" @update:model-value="(v) => nav.patch({ rapplies: v, rpage: null })" />
          <Tabs :model-value="rstate" :tabs="RSTATES" variant="pill" class="!mb-0" @update:model-value="(k) => nav.patch({ rstate: k === 'active' ? null : k, rpage: null })" />
          <span class="grow" />
          <Btn v-if="editable" size="sm" tone="soft" :label="{ ar: 'لصق أسعار', en: 'Paste prices' }" @click="pasting = true" />
          <Btn v-if="editable" size="sm" tone="primary" :label="{ ar: '+ قاعدة', en: '+ Rule' }" @click="ruleForm = {}" />
        </div>
        <ErrorBanner :error="rules.error.value" :closable="false" />
        <div class="overflow-hidden rounded-xl border border-line-2">
          <DataTable :columns="ruleCols" :paged="rules.data.value" :loading="rules.isFetching.value" :row-key="(r) => r.id" dense
                     :empty-text="nav.get('rq') || nav.get('rapplies') || rstate !== 'active' ? { ar: 'لا قواعد تطابق البحث.', en: 'No rule matches.' } : { ar: 'لا قواعد في هذه القائمة: كل منتج يُسعَّر بسعره المعلن.', en: 'This list has no rule: every product is priced at its list price.' }"
                     @page="(p) => nav.patch({ rpage: p > 1 ? p : null })">
            <template #cell-target="{ row }">
              <div class="min-w-0">
                <div class="cell-name truncate !text-[10.5px]" dir="auto">{{ ruleTarget(row) }}</div>
                <div class="cell-sub">{{ row.product ? row.product.sku : bi(APPLIES[row.appliesTo]) }}<template v-if="row.externalRef"> · Odoo #{{ row.externalRef }}</template></div>
              </div>
            </template>
            <template #cell-how="{ row }"><span class="text-[10.5px] font-bold" dir="auto" :title="row.note || ''">{{ bi(row.text) }}</span></template>
            <template #cell-validity="{ row }">
              <span class="text-[9.5px] text-sec">{{ periodText(row.validFrom, row.validTo) }}</span>
              <Chip v-if="row.period !== 'valid'" small class="ms-1" :map="PERIOD" :k="row.period" />
            </template>
            <template #cell-flags="{ row }">
              <span class="inline-flex flex-wrap items-center justify-center gap-1">
                <Chip v-if="row.pending" small :label="WAITING_CHIP" :fg="WAITING_CHIP.fg" :bg="WAITING_CHIP.bg" :title="t('منتج هذه القاعدة ليس في النظام بعد — تكتمل تلقائيًا عند ربطه', 'The product of this rule is not in OPS yet — it completes by itself once linked')" />
                <Chip v-if="!row.active" small :label="ARCHIVED_CHIP" :fg="ARCHIVED_CHIP.fg" :bg="ARCHIVED_CHIP.bg" />
                <Chip v-if="row.owned" small :label="OWNED_CHIP" :fg="OWNED_CHIP.fg" :bg="OWNED_CHIP.bg" />
                <span v-if="!row.pending && row.active && !row.owned" class="text-faint">—</span>
              </span>
            </template>
            <template #cell-actions="{ row }">
              <span v-if="!row.owned" class="inline-flex gap-1">
                <Btn v-if="row.active" size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click.stop="ruleForm = row" />
                <Btn v-if="row.active" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click.stop="ruleAction(row, 'archive')" />
                <Btn v-else size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click.stop="ruleAction(row, 'restore')" />
              </span>
            </template>
          </DataTable>
        </div>
      </template>

      <!-- ── customers -->
      <template v-else>
        <div v-if="canManage && list.active" class="row wrap mb-2 items-end">
          <PickRecord kind="customer" small class="w-[280px]" :model-value="adding" :label="{ ar: 'إسناد القائمة لعميل', en: 'Put a customer on the list' }" @update:model-value="(v) => (adding = v)" />
          <Btn size="sm" tone="primary" :disabled="!adding" :loading="act.pending.value" :label="{ ar: 'إسناد', en: 'Assign' }" @click="addCustomer" />
        </div>
        <ErrorBanner :error="customers.error.value" :closable="false" />
        <div class="overflow-hidden rounded-xl border border-line-2">
          <DataTable :columns="customerCols" :paged="customers.data.value" :loading="customers.isFetching.value" :row-key="(c) => c.id" dense
                     :empty-text="{ ar: 'لا عميل على هذه القائمة.', en: 'No customer is on this list.' }" @page="(p) => nav.patch({ rpage: p > 1 ? p : null })">
            <template #cell-customer="{ row }">
              <div class="min-w-0">
                <div class="cell-name truncate !text-[10.5px]" dir="auto">{{ nameOf(row) }}</div>
                <div class="cell-sub">{{ row.code }}</div>
              </div>
            </template>
            <template #cell-basis="{ row }">
              <span v-if="row.assignment" :title="bi(BASIS_HELP[row.assignment.basis])"><Chip small :map="BASIS" :k="row.assignment.basis" /><span v-if="row.assignment.basisRef" class="num ltr ms-1 text-[9px] text-muted">{{ row.assignment.basisRef }}</span></span>
            </template>
            <template #cell-actions="{ row }">
              <Btn v-if="!row.locked" size="sm" tone="softRed" :loading="act.pending.value" :label="{ ar: 'إخراج', en: 'Take off' }" @click.stop="removeCustomer(row)" />
              <span v-else class="text-[9px] text-muted">{{ t('تُدار من أودو', 'Set in Odoo') }}</span>
            </template>
          </DataTable>
        </div>
      </template>
    </template>

    <template v-if="list && canManage" #footer>
      <div v-if="editable && archiveBlock" class="mb-2 text-[9.5px] font-bold text-warn">{{ t('لا تُؤرشف الآن:', 'Cannot be archived now:') }} {{ archiveBlock }}</div>
      <div class="row wrap !gap-1.5">
        <Btn v-if="editable" tone="soft" :label="{ ar: 'تعديل القائمة', en: 'Edit the list' }" @click="editing = true" />
        <Btn tone="soft" :label="{ ar: 'نسخ إلى قائمة جديدة', en: 'Copy into a new list' }" @click="copying = true" />
        <Btn v-if="editable && list.active && !list.isDefault" tone="softBlue" :loading="act.pending.value" :label="{ ar: 'جعلها الافتراضية', en: 'Make it the default' }" @click="makeDefault" />
        <Btn v-if="editable && list.isDefault" tone="soft" :loading="act.pending.value" :label="{ ar: 'إلغاء كونها الافتراضية', en: 'No longer the default' }"
             @click="post('undefault', undefined, { ar: 'لم تعد القائمة الافتراضية', en: 'It is no longer the default list' })" />
        <Btn v-if="editable && list.active" tone="dangerOutline" :disabled="!!archiveBlock" :loading="act.pending.value" :label="{ ar: 'أرشفة', en: 'Archive' }" @click="archive" />
        <Btn v-if="editable && !list.active" tone="success" :loading="act.pending.value" :label="{ ar: 'استعادة', en: 'Restore' }" @click="post('restore', undefined, { ar: 'استُعيدت القائمة ✓', en: 'List restored ✓' })" />
        <span class="grow" />
        <Btn tone="ghost" :label="{ ar: 'فحص سعر بهذه القائمة', en: 'Check a price on this list' }" @click="nav.go('check', { plist: list.code })" />
      </div>
    </template>
  </Drawer>

  <ListForm :open="editing" :list="list" @close="editing = false" />
  <ListForm :open="copying" :list="list" copy @close="copying = false" @done="copied" />
  <RuleForm :open="!!ruleForm" :list="list" :rule="ruleForm && ruleForm.id ? ruleForm : null" @close="ruleForm = null" @find="findRule" />
  <PasteDrawer :open="pasting" :list="list" @close="pasting = false" />
</template>
