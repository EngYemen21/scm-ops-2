<script setup>
// Settings → roles × permissions matrix (`user.manage`). Ticks are edited locally per role; "Save" under a role's
// name sends PUT /users/roles/:key/permissions { permissions } after a confirm. The super admin is locked (not shown).
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, EmptyState, ErrorBanner, TextInput } from '@/components';
import { lang, t } from '@/i18n';
import { confirm } from '@/stores/ui';
import CardTitle from './CardTitle.vue';
import { groupPermissions, permLabel, roleName } from './settings';

const props = defineProps({
  /** RoleRow[] = [{ key, nameAr, nameEn, permissions: string[] }] */
  roles: { type: Array, required: true },
  /** Every permission key (GET /users/permissions). */
  permissions: { type: Array, required: true },
});

const act = useAction();
/**
 * Local ticks: { [roleKey]: Set<permission> }. Reset from the server's roles whenever they arrive; a role whose server
 * permissions did not change keeps its unsaved ticks (saving one role must not discard the edits of another).
 */
const local = ref({});
watch(() => props.roles, (roles, old) => {
  const before = Object.fromEntries((old || []).map((r) => [r.key, r.permissions]));
  const next = {};
  for (const r of roles) {
    const prev = before[r.key];
    const unchanged = !!prev && prev.length === r.permissions.length && prev.every((p) => r.permissions.includes(p));
    next[r.key] = unchanged && local.value[r.key] ? local.value[r.key] : new Set(r.permissions);
  }
  local.value = next;
}, { immediate: true });

const filter = ref('');
const editable = computed(() => props.roles.filter((r) => r.key !== 'super'));
const groups = computed(() => groupPermissions(props.permissions, filter.value, lang.value));
const gridCols = computed(() => `minmax(200px,1.4fr) repeat(${editable.value.length}, 96px)`);

const isOn = (r, p) => local.value[r.key]?.has(p) ?? false;
const isChanged = (r, p) => isOn(r, p) !== r.permissions.includes(p);
function isDirty(r) {
  const s = local.value[r.key];
  return !!s && (s.size !== r.permissions.length || r.permissions.some((p) => !s.has(p)));
}
function toggle(roleKey, p) {
  const n = new Set(local.value[roleKey] || []);
  if (n.has(p)) n.delete(p); else n.add(p);
  local.value = { ...local.value, [roleKey]: n };
}
async function save(r) {
  const ok = await confirm({
    title: { ar: `حفظ صلاحيات دور «${roleName(r, 'ar')}»؟`, en: `Save permissions for "${roleName(r, 'en')}"?` },
    sub: { ar: 'يسري فورًا على جميع مستخدمي هذا الدور ويُسجل في Audit Trail', en: 'Applies immediately to every user with this role and is audited' },
    tone: 'dark',
  });
  if (!ok) return;
  await act.run(() => api.put(`/users/roles/${r.key}/permissions`, { permissions: [...(local.value[r.key] || [])] }), {
    success: { ar: `تم حفظ صلاحيات ${roleName(r, 'ar')}`, en: `${roleName(r, 'en')} permissions saved` }, invalidate: ['users', 'auth'],
  });
}
</script>

<template>
  <div class="card">
    <CardTitle>
      {{ t('مصفوفة الأدوار والصلاحيات', 'Roles × permissions matrix') }} <span class="text-[9.5px] font-normal text-faint">· {{ t('السوبر أدمن يملك كل الصلاحيات ولا يُعدّل', 'super admin holds every permission and is locked') }}</span>
      <template #right><TextInput v-model="filter" small class="w-[200px]" :placeholder="{ ar: 'تصفية الصلاحيات…', en: 'Filter permissions…' }" /></template>
    </CardTitle>
    <div v-if="act.error.value" class="mx-[18px]"><ErrorBanner :error="act.error.value" @close="act.clearError()" /></div>
    <div class="gt-wrap">
      <div :style="{ minWidth: `${200 + editable.length * 96 + 36}px` }">
        <div class="sticky top-0 z-[1] grid items-end gap-1.5 bg-soft px-[18px] py-2" :style="{ gridTemplateColumns: gridCols }">
          <div class="text-[9.5px] font-extrabold text-muted">{{ t('الصلاحية', 'Permission') }}</div>
          <div v-for="r in editable" :key="r.key" class="text-center">
            <div class="text-[9.5px] font-extrabold leading-[1.4] text-violet">{{ roleName(r, lang) }}</div>
            <div class="num text-[8.5px] text-faint">{{ local[r.key]?.size ?? r.permissions.length }}</div>
            <Btn :tone="isDirty(r) ? 'dark' : 'ghost'" size="sm" class="mt-1 !h-6 !text-[9px]" :disabled="!isDirty(r)" :loading="act.pending.value" :label="{ ar: 'حفظ', en: 'Save' }" @click="save(r)" />
          </div>
        </div>
        <template v-for="g in groups" :key="g.key">
          <div class="border-t border-line-2 bg-white px-[18px] pt-2 pb-1 text-[10px] font-extrabold text-brand-dark">{{ lang === 'ar' ? g.label.ar : g.label.en }}</div>
          <div v-for="p in g.perms" :key="p" class="grid items-center gap-1.5 border-t border-[#F7F6FA] px-[18px] py-[5px]" :style="{ gridTemplateColumns: gridCols }">
            <div class="text-[10.5px] text-sec">{{ permLabel(p, lang) }} <span class="num text-[9px] text-faint" dir="ltr">{{ p }}</span></div>
            <div v-for="r in editable" :key="r.key" class="text-center">
              <input type="checkbox" class="h-[15px] w-[15px] cursor-pointer" :checked="isOn(r, p)" :style="{ accentColor: isChanged(r, p) ? '#b26a16' : '#654e92' }" :aria-label="`${roleName(r, lang)} · ${p}`" @change="toggle(r.key, p)">
            </div>
          </div>
        </template>
        <EmptyState v-if="groups.length === 0" />
      </div>
    </div>
  </div>
</template>
