<script setup>
// Renders an error by category: validation = red (+ per-field list); forbidden = neutral "insufficient permission";
// conflict / business rule = amber with the machine code; system / network = red with the request id.
//   <ErrorBanner :error="act.error.value" @close="act.clearError()" />
import { computed } from 'vue';
import { errorMessage, isApiError } from '../api/client';
import { lang, t } from '../i18n';

const props = defineProps({
  error: { type: null, default: null },
  /** Hide the per-field list (forms show those inline). */
  hideDetails: { type: Boolean, default: false },
  closable: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);

const e = computed(() => (isApiError(props.error) ? props.error : null));
const cat = computed(() => e.value?.category || 'SYSTEM');
const tone = computed(() => (cat.value === 'CONFLICT' || cat.value === 'BUSINESS_RULE' ? 'amber' : cat.value === 'FORBIDDEN' ? 'neutral' : 'red'));
const msg = computed(() => (cat.value === 'FORBIDDEN' ? t('صلاحية غير كافية', 'Insufficient permission') : e.value ? e.value.localized(lang.value) : errorMessage(props.error, lang.value)));
const fields = computed(() => (e.value && !props.hideDetails ? Object.entries(e.value.fieldErrors()) : []));
</script>

<template>
  <div v-if="error" class="banner" :class="tone" role="alert">
    <div class="min-w-0 flex-1">
      <div>
        {{ msg }}
        <code v-if="e && (cat === 'CONFLICT' || cat === 'BUSINESS_RULE')">{{ e.code }}</code>
        <span v-if="e && cat === 'FORBIDDEN' && e.message && e.message !== msg" class="font-normal opacity-85"> — {{ e.localized(lang) }}</span>
      </div>
      <ul v-if="fields.length"><li v-for="[path, message] in fields" :key="path"><code>{{ path }}</code> {{ message }}</li></ul>
      <div v-if="e && (cat === 'SYSTEM' || cat === 'NETWORK') && e.requestId" class="mt-[3px] font-normal opacity-85">{{ t('رقم الطلب', 'Request ID') }}: <code>{{ e.requestId }}</code></div>
    </div>
    <button v-if="closable" type="button" class="cursor-pointer border-0 bg-transparent p-0 text-[12px] font-extrabold text-inherit" aria-label="dismiss" @click="emit('close')">✕</button>
  </div>
</template>
