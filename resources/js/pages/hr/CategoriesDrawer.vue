<script setup>
// Expense categories, kept by the reviewers (expense.review): GET /hr/expense-categories?archived=1 · POST · PATCH.
// A category is what a claim line is for and — the reviewers' part — the expense account of the chart its lines are
// charged to (a line of a category without an account must be given one when the claim is posted) and the VAT rate
// proposed on a new line. A category that came from Odoo (an expense product) keeps Odoo's name while the section
// follows Odoo; its account, its VAT rate and whether it is offered are OPS's own. Nothing is deleted: a category
// that is no longer wanted is archived.
//   <CategoriesDrawer :open @close />
import { computed, ref } from 'vue';
import { api, useGet } from '@/api/client';
import { Btn, Chip, Drawer, EmptyState, ErrorBanner, FormDrawer } from '@/components';
import { t } from '@/i18n';
import { FROM_ODOO, TONE, pname, useHrLookups } from './shared';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const list = useGet(() => (props.open ? '/hr/expense-categories' : null), { archived: 1 });
const rows = computed(() => list.data.value?.items || []);
const form = ref(null); // { row: category | null } while the form is open
const lookups = useHrLookups(() => !!form.value);
const ARCHIVED = { ar: 'مؤرشفة', en: 'Archived', ...TONE.off };
const NO_ACCOUNT = { ar: 'بلا حساب', en: 'No account', ...TONE.warn };

const fields = computed(() => {
  const row = form.value?.row;
  return [
    ...(row ? [] : [{ k: 'code', label: { ar: 'الرمز', en: 'Code' }, required: true, dir: 'ltr', hint: { ar: 'حروف لاتينية وأرقام، مثل TRAVEL', en: 'Latin letters and digits, e.g. TRAVEL' }, validate: (v) => (/^[A-Za-z0-9_.-]{2,40}$/.test(String(v || '')) ? null : t('حروف لاتينية وأرقام فقط', 'Latin letters and digits only')) }]),
    { k: 'nameAr', label: { ar: 'الاسم بالعربية', en: 'Name (Arabic)' }, required: true, disabled: !!row?.locked },
    { k: 'nameEn', label: { ar: 'الاسم بالإنجليزية', en: 'Name (English)' }, dir: 'ltr', disabled: !!row?.locked },
    { k: 'accountCode', label: { ar: 'حساب المصروف', en: 'Expense account' }, type: 'select', full: true, ph: { ar: '— يُحدَّد عند الترحيل —', en: '— named when posting —' }, opts: lookups.accountOptions.value },
    { k: 'vatPct', label: { ar: 'ضريبة مقترحة %', en: 'Proposed VAT %' }, type: 'num', min: 0, max: 100 },
    ...(row ? [{ k: 'active', label: { ar: 'الحالة', en: 'State' }, type: 'select', required: true, opts: [['1', { ar: 'معروضة', en: 'Offered' }], ['0', { ar: 'مؤرشفة', en: 'Archived' }]] }] : []),
  ];
});
const initial = computed(() => { const r = form.value?.row; return r ? { nameAr: r.nameAr, nameEn: r.nameEn, accountCode: r.accountCode || '', vatPct: r.vatPct, active: r.active ? '1' : '0' } : { vatPct: 15 }; });
function save(values) {
  const row = form.value?.row;
  const body = { nameAr: values.nameAr, nameEn: values.nameEn ?? null, accountCode: values.accountCode ?? null, vatPct: values.vatPct ?? 0 };
  if (!row) return api.postIdempotent('/hr/expense-categories', { code: values.code, ...body });
  if (row.locked) { delete body.nameAr; delete body.nameEn; } // Odoo's name stays Odoo's
  return api.patch(`/hr/expense-categories/${row.id}`, { ...body, active: values.active === '1' });
}
</script>

<template>
  <Drawer :open="open" :title="{ ar: 'فئات المصروفات', en: 'Expense categories' }" :sub="{ ar: 'ما يختاره الموظف لكل سطر، وحساب المصروف الذي يُحمَّل عليه', en: 'What an employee picks for a line, and the expense account it is charged to' }" :width="560" @close="emit('close')">
    <ErrorBanner :error="list.error.value" :closable="false" />
    <div class="row mb-2"><span class="grow" /><Btn size="sm" tone="primary" :label="{ ar: '+ فئة', en: '+ Category' }" @click="form = { row: null }" /></div>
    <div v-if="list.isLoading.value" class="skel h-[140px]" />
    <EmptyState v-else-if="!rows.length" :text="{ ar: 'لا فئات بعد.', en: 'No categories yet.' }" />
    <div v-else class="m-list">
      <button v-for="r in rows" :key="r.id" type="button" class="m-list-row" @click="form = { row: r }">
        <span class="min-w-0 flex-1">
          <span class="cell-name">{{ pname(r) }}</span> <span class="cell-id ms-1">{{ r.code }}</span>
          <span class="block text-[9.5px] text-muted">{{ t('الحساب', 'Account') }}: <span class="num ltr">{{ r.accountCode || '—' }}</span> · {{ t('ضريبة', 'VAT') }} <span class="num">{{ r.vatPct }}%</span> · {{ t(`${r.lines} سطرًا`, `${r.lines} line(s)`) }}</span>
        </span>
        <Chip v-if="!r.accountCode && r.active" small :label="NO_ACCOUNT" :fg="NO_ACCOUNT.fg" :bg="NO_ACCOUNT.bg" />
        <Chip v-if="r.source === 'odoo'" small :label="FROM_ODOO" :fg="FROM_ODOO.fg" :bg="FROM_ODOO.bg" />
        <Chip v-if="!r.active" small :label="ARCHIVED" :fg="ARCHIVED.fg" :bg="ARCHIVED.bg" />
      </button>
    </div>
    <div class="hint">{{ t('فئة بلا حساب: يُطلب حسابها عند ترحيل كل مطالبة تحملها. اربطها بحساب مصروف هنا مرة واحدة ليصبح الترحيل مباشرًا.', 'A category without an account: its account is asked for every time a claim that carries it is posted. Map it to an expense account here once and posting becomes direct.') }}</div>
  </Drawer>

  <FormDrawer :open="!!form" :title="form?.row ? { ar: `تعديل الفئة ${form.row.code}`, en: `Edit category ${form.row.code}` } : { ar: 'فئة مصروف جديدة', en: 'New expense category' }"
              :sub="form?.row?.locked ? { ar: 'مصدرها أودو: اسمها يُعدَّل هناك — الحساب والضريبة والحالة تُحفظ هنا', en: 'From Odoo: its name is changed there — the account, the VAT rate and the state are kept here' } : null"
              :fields="fields" :initial="initial" :submit="save" :action="{ success: t('حُفظت الفئة', 'Category saved'), invalidate: ['hr'] }" @close="form = null" />
</template>
