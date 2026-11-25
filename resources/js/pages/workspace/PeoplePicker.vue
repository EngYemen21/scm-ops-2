<script setup>
// Several people of a list — the members of a team or a project, the assignees of a task — chosen by `Person.key`:
// the id of an OPS user, or `name:<name>` for somebody Odoo names who has no OPS user. Such a name can be kept or
// taken off here; it cannot be added (only an OPS user can).
//   <PeoplePicker v-model="f.memberKeys" :users="lookups.users" :known="team.members" :label="{ ar, en }" />
import { computed } from 'vue';
import { Chip } from '@/components';
import { bi, t } from '@/i18n';
import { ODOO_CHIP, arr, personName } from './workspace';

const props = defineProps({
  /** The chosen keys. */
  modelValue: { type: Array, default: () => [] },
  /** lookups.users: [{ id, username, nameAr, nameEn }] */
  users: { type: Array, default: () => [] },
  /** People the record already holds ([Person]) — a name kept from Odoo, or a user who is no longer offered. */
  known: { type: Array, default: () => [] },
  label: { type: [String, Object], default: null },
  hint: { type: [String, Object], default: null },
  emptyText: { type: [String, Object], default: null },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

/** Everybody a key may stand for: key → { name, external }. */
const people = computed(() => {
  const out = new Map();
  for (const p of arr(props.known)) if (p?.key) out.set(String(p.key), { name: personName(p), external: !!p.external });
  for (const u of arr(props.users)) out.set(String(u.id), { name: personName(u), external: false });
  return out;
});
const chosen = computed(() => arr(props.modelValue).map(String).map((key) => ({ key, ...(people.value.get(key) || { name: key.startsWith('name:') ? key.slice(5) : key, external: key.startsWith('name:') }) })));
const offered = computed(() => arr(props.users).filter((u) => !arr(props.modelValue).map(String).includes(String(u.id))));

function add(e) {
  const key = e.target.value;
  e.target.value = '';
  if (key) emit('update:modelValue', [...arr(props.modelValue).map(String), key]);
}
const takeOff = (key) => emit('update:modelValue', arr(props.modelValue).map(String).filter((k) => k !== key));
</script>

<template>
  <div class="field full">
    <div v-if="label" class="field-l">{{ bi(label) }}</div>
    <div class="flex flex-wrap items-center gap-1.5">
      <span v-for="p in chosen" :key="p.key" class="inline-flex items-center gap-1 rounded-full border border-line bg-soft py-1 pe-1 ps-2.5 text-[10.5px] font-bold">
        <span dir="auto">{{ p.name }}</span>
        <Chip v-if="p.external" small :label="ODOO_CHIP" :fg="ODOO_CHIP.fg" :bg="ODOO_CHIP.bg" />
        <button v-if="!disabled" type="button" class="flex h-5 w-5 cursor-pointer items-center justify-center rounded-full border-0 bg-line text-[9px] text-sec" :aria-label="t('إزالة', 'Remove')" @click="takeOff(p.key)">✕</button>
      </span>
      <span v-if="!chosen.length" class="text-[10.5px] text-faint">{{ emptyText ? bi(emptyText) : t('لم يُختر أحد.', 'Nobody chosen.') }}</span>
    </div>
    <select v-if="!disabled && offered.length" class="inp sm mt-1.5 max-w-[320px]" :aria-label="t('إضافة شخص', 'Add a person')" @change="add">
      <option value="">{{ t('+ إضافة شخص…', '+ Add a person…') }}</option>
      <option v-for="u in offered" :key="u.id" :value="u.id">{{ personName(u) }}</option>
    </select>
    <div v-if="hint" class="field-hint">{{ bi(hint) }}</div>
  </div>
</template>
