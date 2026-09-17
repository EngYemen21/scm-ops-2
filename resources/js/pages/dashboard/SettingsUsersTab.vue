<script setup>
// Settings → users (`user.manage`): list + search, create (POST /users), edit (PATCH /users/:id: names, e-mail, status,
// password, roles, warehouses) and quick activate / deactivate.
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, FormDrawer, TextInput } from '@/components';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { ROLE_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import CardTitle from './CardTitle.vue';
import PillMulti from './PillMulti.vue';
import { roleName } from './settings';

const props = defineProps({
  /** RoleRow[] from GET /users/roles */
  roles: { type: Array, required: true },
  /** [{ code, nameAr, nameEn }] */
  warehouses: { type: Array, required: true },
});

const users = useGet('/users');
const q = ref('');
const rows = computed(() => {
  const s = q.value.trim().toLowerCase();
  const all = users.data.value || [];
  return s ? all.filter((u) => [u.username, u.nameAr, u.nameEn, u.email || '', (u.roles || []).join(' ')].some((x) => String(x || '').toLowerCase().includes(s))) : all;
});
const roleOpts = computed(() => props.roles.map((r) => ({ v: r.key, l: roleName(r, lang.value) })));
const whOpts = computed(() => props.warehouses.map((w) => ({ v: w.code, l: `${w.code} · ${lang.value === 'ar' ? w.nameAr : w.nameEn}` })));

/** { mode: 'create' } | { mode: 'edit', user } | null */
const drawer = ref(null);
const editing = computed(() => (drawer.value?.mode === 'edit' ? drawer.value.user : null));
const editInitial = computed(() => (editing.value ? { nameAr: editing.value.nameAr, nameEn: editing.value.nameEn, email: editing.value.email || '', active: String(editing.value.active), roles: editing.value.roles, warehouses: editing.value.warehouses } : null));

const act = useAction();
const auth = useAuth();
// Your own row has no deactivate button (the server refuses it too: USER_SELF_DEACTIVATE).
const isSelf = (u) => u.id === auth.user?.id;
async function toggleActive(u) {
  if (u.active && !(await confirm({ title: { ar: `إيقاف حساب ${u.username}؟`, en: `Deactivate ${u.username}?` }, sub: { ar: 'تُلغى جلساته فورًا ولن يستطيع الدخول حتى يُعاد تفعيله.', en: 'Their sessions end now and they cannot sign in until reactivated.' }, tone: 'danger', okLabel: { ar: 'إيقاف', en: 'Deactivate' } }))) return;
  act.run(() => api.patch(`/users/${u.id}`, { active: !u.active }), {
    success: u.active ? { ar: `تم إيقاف ${u.username}`, en: `${u.username} deactivated` } : { ar: `تم تفعيل ${u.username}`, en: `${u.username} activated` },
    invalidate: ['users'],
  });
}
function submitEdit(v) {
  const body = { nameAr: v.nameAr, nameEn: v.nameEn, active: v.active === 'true', roles: v.roles, warehouses: v.warehouses ?? [] };
  if (v.email) body.email = v.email;
  if (v.password) body.password = v.password;
  return api.patch(`/users/${editing.value.id}`, body);
}

const cols = [
  { key: 'username', header: { ar: 'اسم المستخدم', en: 'Username' }, width: '130px', kind: 'id', sortable: true },
  { key: 'name', header: { ar: 'الاسم', en: 'Name' }, width: 'minmax(160px,1.4fr)' },
  { key: 'roles', header: { ar: 'الأدوار', en: 'Roles' }, width: 'minmax(160px,1.2fr)' },
  { key: 'warehouses', header: { ar: 'المستودعات', en: 'Warehouses' }, width: '140px' },
  { key: 'active', header: { ar: 'الحالة', en: 'Status' }, width: '90px' },
  { key: 'lastLoginAt', header: { ar: 'آخر دخول', en: 'Last login' }, width: '130px', kind: 'date', sortable: true, value: (u) => fmtDate(u.lastLoginAt) },
  { key: 'act', header: '', width: '150px' },
];

const minLen = (n, ar, en, optional = false) => (v) => ((optional && !v) || String(v || '').length >= n ? null : t(ar, en));
const createFields = [
  { k: 'username', label: { ar: 'اسم المستخدم', en: 'Username' }, required: true, dir: 'ltr', validate: minLen(3, '3 أحرف على الأقل', 'At least 3 characters') },
  { k: 'password', label: { ar: 'كلمة المرور', en: 'Password' }, type: 'password', required: true, validate: minLen(8, '8 أحرف على الأقل', 'At least 8 characters') },
  { k: 'nameAr', label: { ar: 'الاسم (عربي)', en: 'Name (AR)' }, required: true },
  { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (EN)' }, required: true, dir: 'ltr' },
  { k: 'email', label: { ar: 'البريد الإلكتروني', en: 'E-mail' }, dir: 'ltr' },
  { k: 'roles', label: { ar: 'الأدوار', en: 'Roles' }, required: true, full: true, def: [], validate: (v) => (!Array.isArray(v) || v.length === 0 ? t('اختر دورًا واحدًا على الأقل', 'Pick at least one role') : null) },
  { k: 'warehouses', label: { ar: 'المستودعات', en: 'Warehouses' }, full: true, def: [] },
];
const editFields = [
  { k: 'nameAr', label: { ar: 'الاسم (عربي)', en: 'Name (AR)' }, required: true },
  { k: 'nameEn', label: { ar: 'الاسم (إنجليزي)', en: 'Name (EN)' }, required: true, dir: 'ltr' },
  { k: 'email', label: { ar: 'البريد الإلكتروني', en: 'E-mail' }, dir: 'ltr' },
  { k: 'active', label: { ar: 'الحالة', en: 'Status' }, type: 'select', opts: [['true', { ar: 'نشط', en: 'Active' }], ['false', { ar: 'موقوف', en: 'Inactive' }]], required: true },
  { k: 'password', label: { ar: 'كلمة مرور جديدة', en: 'New password' }, type: 'password', hint: { ar: 'اتركه فارغًا للإبقاء على الحالية', en: 'Leave blank to keep the current one' }, validate: minLen(8, '8 أحرف على الأقل', 'At least 8 characters', true) },
  { k: 'roles', label: { ar: 'الأدوار', en: 'Roles' }, full: true },
  { k: 'warehouses', label: { ar: 'المستودعات', en: 'Warehouses' }, full: true },
];
</script>

<template>
  <div class="card">
    <CardTitle>
      {{ t('المستخدمون', 'Users') }} <span class="num font-normal text-faint">({{ fmtNum(users.data.value?.length || 0) }})</span>
      <template #right>
        <span class="row">
          <TextInput v-model="q" small class="w-[180px]" :placeholder="{ ar: 'بحث…', en: 'Search…' }" />
          <Btn tone="dark" size="sm" :label="{ ar: '+ مستخدم جديد', en: '+ New user' }" @click="drawer = { mode: 'create' }" />
        </span>
      </template>
    </CardTitle>
    <div v-if="users.error.value || act.error.value" class="mx-[18px]"><ErrorBanner :error="users.error.value || act.error.value" @close="act.clearError()" /></div>
    <DataTable :columns="cols" :rows="rows" :loading="users.isLoading.value" :row-key="(u) => u.id" :page-size="20" :min-width="860" dense :empty-text="{ ar: 'لا مستخدمين مطابقين.', en: 'No matching users.' }" @row-click="(u) => (drawer = { mode: 'edit', user: u })">
      <template #cell-name="{ row }">
        <div class="row">
          <span class="avatar !h-[26px] !w-[26px] !text-[9px]">{{ row.initials || row.username.slice(0, 2).toUpperCase() }}</span>
          <div><div class="cell-name">{{ lang === 'ar' ? row.nameAr : row.nameEn }}</div><div v-if="row.email" class="cell-sub num" dir="ltr">{{ row.email }}</div></div>
        </div>
      </template>
      <template #cell-roles="{ row }"><span class="row wrap !gap-1"><Chip v-for="r in row.roles" :key="r" small :label="ROLE_LABELS[r] || { ar: r, en: r }" fg="#654e92" bg="#efeaf8" /></span></template>
      <template #cell-warehouses="{ row }"><span class="num">{{ row.warehouses.length ? row.warehouses.join(' · ') : t('الكل', 'All') }}</span></template>
      <template #cell-active="{ row }"><Chip small :label="row.active ? { ar: 'نشط', en: 'Active' } : { ar: 'موقوف', en: 'Inactive' }" :fg="row.active ? '#1d7a3e' : '#b23b3b'" :bg="row.active ? '#e6f9ec' : '#fdecec'" /></template>
      <template #cell-act="{ row }">
        <span class="row !gap-1.5" @click.stop>
          <Btn tone="soft" size="sm" :label="{ ar: 'تعديل', en: 'Edit' }" @click="drawer = { mode: 'edit', user: row }" />
          <Btn v-if="!isSelf(row)" :tone="row.active ? 'softRed' : 'softGreen'" size="sm" :loading="act.pending.value" :label="row.active ? { ar: 'إيقاف', en: 'Deactivate' } : { ar: 'تفعيل', en: 'Activate' }" @click="toggleActive(row)" />
        </span>
      </template>
    </DataTable>

    <FormDrawer :open="drawer?.mode === 'create'" :title="{ ar: 'مستخدم جديد', en: 'New user' }" :sub="{ ar: 'يُطلب تغيير كلمة المرور عند أول دخول حسب سياسة النظام', en: 'Roles decide pages and permissions' }" :fields="createFields"
                :submit="(v) => api.postIdempotent('/users', { ...v, email: v.email || undefined })" :submit-label="{ ar: 'إنشاء', en: 'Create' }"
                :action="{ success: (r) => t(`تم إنشاء المستخدم ${r?.username || ''}`, `User ${r?.username || ''} created`), invalidate: ['users'] }" @close="drawer = null">
      <template #field-roles="{ value, set, error }">
        <label class="field-l">{{ t('الأدوار', 'Roles') }} <span class="text-bad">*</span></label>
        <PillMulti :model-value="value" :options="roleOpts" purple @update:model-value="set" />
        <div v-if="error" class="field-err">{{ error }}</div>
      </template>
      <template #field-warehouses="{ value, set }">
        <label class="field-l">{{ t('المستودعات (فارغ = الكل)', 'Warehouses (empty = all)') }}</label>
        <PillMulti :model-value="value" :options="whOpts" @update:model-value="set" />
      </template>
    </FormDrawer>

    <FormDrawer :open="drawer?.mode === 'edit'" :title="`${t('تعديل المستخدم', 'Edit user')} ${editing?.username || ''}`" :fields="editFields" :initial="editInitial" :submit="submitEdit"
                :action="{ success: { ar: 'تم حفظ المستخدم', en: 'User saved' }, invalidate: ['users', 'auth'] }" @close="drawer = null">
      <template #field-roles="{ value, set, error }">
        <label class="field-l">{{ t('الأدوار', 'Roles') }}</label>
        <PillMulti :model-value="value" :options="roleOpts" purple @update:model-value="set" />
        <div v-if="error" class="field-err">{{ error }}</div>
      </template>
      <template #field-warehouses="{ value, set }">
        <label class="field-l">{{ t('المستودعات (فارغ = الكل)', 'Warehouses (empty = all)') }}</label>
        <PillMulti :model-value="value" :options="whOpts" @update:model-value="set" />
      </template>
    </FormDrawer>
  </div>
</template>
