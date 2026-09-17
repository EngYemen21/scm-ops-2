<script setup>
// Settings → business policies (`settings.manage`): GET /settings grouped into cards, one SettingEditor per key.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { ErrorBanner } from '@/components';
import { lang, t } from '@/i18n';
import SettingEditor from './SettingEditor.vue';
import { SETTING_GROUP_LABELS } from './settings';

const q = useGet('/settings');
/** [[group, [[key, SettingRow], …]], …] in the API's order. */
const groups = computed(() => {
  const m = new Map();
  for (const [k, r] of Object.entries(q.data.value || {})) { const g = m.get(r.group) || []; g.push([k, r]); m.set(r.group, g); }
  return [...m.entries()];
});
</script>

<template>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value && !q.data.value" class="card p-[18px]"><div class="skel h-3.5 w-2/5" /></div>
  <div class="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-3">
    <div v-for="[g, rows] in groups" :key="g" class="card sm px-[18px] py-4">
      <div class="text-[12.5px] font-extrabold text-violet">{{ lang === 'ar' ? SETTING_GROUP_LABELS[g]?.ar || g : SETTING_GROUP_LABELS[g]?.en || g }}</div>
      <div class="mt-1.5"><SettingEditor v-for="[k, r] in rows" :key="k" :k="k" :row="r" /></div>
    </div>
  </div>
  <div class="hint !mt-3">{{ t('كل قاعدة عمل هنا تُقرأ من قاعدة البيانات وقت التنفيذ — التعديل يسري فورًا ويُسجل في Audit Trail بالقيمة القديمة والجديدة.', 'Every business rule here is read from the database at execution time — changes apply immediately and are audited with old / new values.') }}</div>
</template>
