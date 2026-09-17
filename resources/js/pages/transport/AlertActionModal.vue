<script setup>
// Alert action dialog: resolve / snooze (hours) / assign (owner) → POST /transport/alerts/:code/<action> { note, owner, hours }.
//   <AlertActionModal :alert="pending?.alert" :action="pending?.action" @close="pending = null" />
import { computed, ref, watch } from 'vue';
import { api, useAction } from '@/api/client';
import { Btn, ErrorBanner, Modal, NumberInput, TextArea, TextInput } from '@/components';
import { lang, t } from '@/i18n';

const props = defineProps({
  alert: { type: Object, default: null },
  /** 'resolve' | 'snooze' | 'assign' */
  action: { type: String, default: null },
});
const emit = defineEmits(['close']);

const act = useAction();
const note = ref('');
const owner = ref('');
const hours = ref(24);
watch(() => [props.alert?.code, props.action], () => { note.value = ''; owner.value = ''; hours.value = 24; act.clearError(); });

const title = computed(() => (props.action === 'resolve' ? t('حل التنبيه', 'Resolve alert') : props.action === 'snooze' ? t('تأجيل التنبيه', 'Snooze alert') : t('إسناد التنبيه', 'Assign alert')));
const okTone = computed(() => (props.action === 'resolve' ? 'success' : props.action === 'assign' ? 'blue' : 'dark'));

async function submit() {
  const r = await act.run(() => api.postIdempotent(`/transport/alerts/${props.alert.code}/${props.action}`, { note: note.value || undefined, owner: owner.value || undefined, hours: hours.value || undefined }), { success: (x) => x?.message || t('تم', 'Done'), invalidate: ['transport'] });
  if (r !== undefined) emit('close');
}
</script>

<template>
  <Modal :open="!!alert && !!action" :width="460" @close="emit('close')">
    <template #title>
      {{ title }}
      <div v-if="alert" class="drawer-sub"><span class="num">{{ alert.code }}</span> · {{ (lang === 'en' && alert.textEn) || alert.textAr }}</div>
    </template>
    <ErrorBanner :error="act.error.value" @close="act.clearError()" />
    <div class="form-grid">
      <TextInput v-if="action === 'assign'" v-model="owner" :label="{ ar: 'المسؤول (اسم المستخدم)', en: 'Owner (username)' }" required dir="ltr" />
      <NumberInput v-if="action === 'snooze'" v-model="hours" :label="{ ar: 'مدة التأجيل (ساعات)', en: 'Snooze (hours)' }" :min="1" />
      <TextArea v-model="note" :label="{ ar: 'ملاحظة', en: 'Note' }" full />
    </div>
    <template #footer>
      <div class="row">
        <Btn :tone="okTone" class="!h-[42px] flex-1" :loading="act.pending.value" :label="{ ar: 'تأكيد', en: 'Confirm' }" @click="submit" />
        <Btn tone="soft" class="!h-[42px] w-[110px]" :label="{ ar: 'إلغاء', en: 'Cancel' }" @click="emit('close')" />
      </div>
    </template>
  </Modal>
</template>
