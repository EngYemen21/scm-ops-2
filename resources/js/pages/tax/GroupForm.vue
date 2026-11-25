<script setup>
// A tax group (vatreturn.manage) — POST /tax/groups { nameAr, nameEn, sequence } · PATCH /tax/groups/{id} (only what
// changed). Archiving is the list's button; the server refuses it while active codes belong to the group.
import { computed, reactive, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { NumberInput, TextInput } from '@/components';
import { t } from '@/i18n';
import TaxFormDrawer from './TaxFormDrawer.vue';
import { changedOnly, textOrNull } from './tax';

const props = defineProps({
  open: { type: Boolean, default: false },
  group: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const act = useAction({ invalidate: ['tax'] });
const f = reactive({ nameAr: '', nameEn: '', sequence: null });
let before = {};
watch(() => props.open, (open) => {
  if (!open) return;
  before = { nameAr: props.group?.nameAr || '', nameEn: props.group?.nameEn || null, sequence: props.group?.sequence ?? null };
  Object.assign(f, { nameAr: before.nameAr, nameEn: before.nameEn || '', sequence: before.sequence });
  act.clearError();
}, { immediate: true });
const ok = computed(() => f.nameAr.trim() !== '');

async function submit() {
  const now = { nameAr: f.nameAr.trim(), nameEn: textOrNull(f.nameEn), sequence: f.sequence == null ? null : Math.round(f.sequence) };
  let r;
  if (props.group) {
    const body = changedOnly(before, now);
    if (body.sequence === null) delete body.sequence;
    if (!Object.keys(body).length) { emit('close'); return; }
    r = await act.run(() => api.patch(`/tax/groups/${encodeURIComponent(props.group.id)}`, body), { success: t('حُفظت المجموعة', 'Group saved') });
  } else {
    r = await act.run(() => api.postIdempotent('/tax/groups', { nameAr: now.nameAr, nameEn: now.nameEn, ...(now.sequence == null ? {} : { sequence: now.sequence }) }), { success: t('أُنشئت المجموعة', 'Group created') });
  }
  if (r) emit('close');
}
</script>

<template>
  <TaxFormDrawer :open="open" :title="group ? { ar: 'تعديل مجموعة ضريبية', en: 'Edit tax group' } : { ar: 'مجموعة ضريبية جديدة', en: 'New tax group' }" :width="460"
                 :pending="act.pending.value" :error="act.error.value" :disabled="!ok" :missing="{ ar: 'الاسم العربي إلزامي', en: 'The Arabic name is required' }"
                 @submit="submit" @close="emit('close')" @clear-error="act.clearError()">
    <div class="form-grid">
      <TextInput v-model="f.nameAr" :label="{ ar: 'الاسم (عربي)', en: 'Name (Arabic)' }" required dir="rtl" />
      <TextInput v-model="f.nameEn" :label="{ ar: 'الاسم (إنجليزي)', en: 'Name (English)' }" dir="ltr" />
      <NumberInput v-model="f.sequence" :label="{ ar: 'الترتيب', en: 'Sequence' }" :min="0" :step="10" :hint="{ ar: 'فارغ = في آخر القائمة', en: 'Empty = at the end' }" />
    </div>
  </TaxFormDrawer>
</template>
