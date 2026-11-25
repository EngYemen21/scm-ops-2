<script setup>
// The column mappings kept for a bank account (GET /banking/profiles?bank=…): rename, make the default, switch off —
// PATCH /banking/profiles/{id} { name | isDefault | active } (bankstatement.manage). A mapping is made by an import
// that names it ("save this mapping as …").
//   <ImportProfiles :profiles="items" :loading="bool" />
import { computed } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, Chip, ErrorBanner } from '@/components';
import { bi, fmtDate, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm } from '@/stores/ui';
import { AMOUNT_MODE, IMPORT_FIELD } from './banking';

defineProps({
  profiles: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});

const auth = useAuth();
const canManage = computed(() => auth.can('bankstatement.manage'));
const act = useAction({ invalidate: ['banking'] });
const url = (p) => `/banking/profiles/${p.id}`;
const fieldsOf = (p) => Object.keys(p.mapping || {}).map((f) => bi(IMPORT_FIELD[f] || { ar: f, en: f })).join('، ');

async function rename(p) {
  const name = await ask({ title: { ar: 'إعادة تسمية الربط', en: 'Rename the mapping' }, label: { ar: 'الاسم', en: 'Name' }, value: p.name, required: true, okLabel: { ar: 'حفظ', en: 'Save' } });
  if (name === null || name === p.name) return;
  await act.run(() => api.patch(url(p), { name }), { success: t('حُفظ الاسم', 'Name saved') });
}
async function makeDefault(p) {
  await act.run(() => api.patch(url(p), { isDefault: true }), { success: t(`صار «${p.name}» الافتراضي لهذا الحساب`, `“${p.name}” is now this account’s default`) });
}
async function deactivate(p) {
  if (!(await confirm({ title: { ar: `إيقاف الربط «${p.name}»؟`, en: `Switch off “${p.name}”?` }, sub: { ar: 'لن يُعرض للاستيراد بعد الآن؛ الاستيرادات التي تمت به تبقى كما هي.', en: 'It is no longer offered for imports; the imports made with it stay as they are.' }, tone: 'danger', okLabel: { ar: 'إيقاف', en: 'Switch off' } }))) return;
  await act.run(() => api.patch(url(p), { active: false }), { success: t('أُوقف الربط', 'Mapping switched off') });
}
</script>

<template>
  <div>
    <div class="card-title mb-1.5">{{ t('روابط الأعمدة المحفوظة لهذا الحساب', 'Column mappings kept for this account') }}</div>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div v-if="loading" class="skel h-[60px]" />
    <div v-else-if="!profiles.length" class="empty !p-4">{{ t('لا روابط محفوظة بعد — احفظ الربط باسم عند أول استيراد ناجح، ليُستعمل تلقائيًا في المرات القادمة.', 'No mapping kept yet — name the mapping at the first good import and it is used by itself next time.') }}</div>
    <div class="col !gap-1.5">
      <div v-for="p in profiles" :key="p.id" class="row wrap rounded-[12px] border border-line-2 px-3 py-2">
        <div class="min-w-0 flex-1">
          <div class="row !gap-1.5"><b class="truncate text-[11px] text-ink" dir="auto">{{ p.name }}</b><Chip v-if="p.isDefault" small :label="{ ar: 'الافتراضي', en: 'Default' }" fg="#1d7a3e" bg="#e6f9ec" /><Chip v-if="!p.bank" small :label="{ ar: 'لكل الحسابات', en: 'Any account' }" fg="#55506a" bg="#F1EFF6" /></div>
          <div class="truncate text-[9.5px] text-muted">{{ bi(AMOUNT_MODE[p.options?.amountMode] || AMOUNT_MODE.signed) }} · {{ fieldsOf(p) }}</div>
          <div class="text-[9px] text-faint"><span dir="auto">{{ p.updatedBy || p.createdBy }}</span> · <span class="num ltr">{{ fmtDate(p.updatedAt || p.createdAt) }}</span></div>
        </div>
        <template v-if="canManage">
          <Btn v-if="!p.isDefault && p.bank" size="sm" tone="softGreen" :loading="act.pending.value" :label="{ ar: 'اجعله الافتراضي', en: 'Make default' }" @click="makeDefault(p)" />
          <Btn size="sm" tone="soft" :label="{ ar: 'إعادة تسمية', en: 'Rename' }" @click="rename(p)" />
          <Btn size="sm" tone="ghost" :loading="act.pending.value" :label="{ ar: 'إيقاف', en: 'Switch off' }" @click="deactivate(p)" />
        </template>
      </div>
    </div>
  </div>
</template>
