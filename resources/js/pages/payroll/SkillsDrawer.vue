<script setup>
// The skills and résumé of one employee in a drawer (`emp=<employee id>`), opened from the Skills tab — the same panel
// as in My corner, editable when the answer's `can.edit` says so (payroll.manage, or the person himself).
//   <SkillsDrawer :employee="idOrNull" @close="…" />
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Drawer } from '@/components';
import { t } from '@/i18n';
import SkillsPanel from './SkillsPanel.vue';
import { pname } from './payroll';

const props = defineProps({
  employee: { type: String, default: null },
});
const emit = defineEmits(['close']);

// the same query the panel makes (one request through the cache) — here only for the title
const q = useGet(() => (props.employee ? `/payroll/skills/employees/${encodeURIComponent(props.employee)}` : null));
const who = computed(() => (props.employee ? q.data.value || null : null));
</script>

<template>
  <Drawer :open="!!employee" :width="820" @close="emit('close')">
    <template #title><span dir="auto">{{ who ? pname(who.employee) : t('المهارات والسيرة', 'Skills & résumé') }}</span></template>
    <template #sub><span v-if="who"><span class="num ltr inline-block">{{ who.employee?.code }}</span><template v-if="who.jobTitle"> · <span dir="auto">{{ who.jobTitle }}</span></template><template v-if="who.department"> · <span dir="auto">{{ pname(who.department) }}</span></template></span></template>
    <SkillsPanel v-if="employee" :employee="employee" />
  </Drawer>
</template>
