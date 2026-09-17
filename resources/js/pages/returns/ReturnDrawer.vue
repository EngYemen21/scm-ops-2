<script setup>
// Return drawer (720px) opened from the returns list (?rtn=RTN-…): GET /api/returns/:number → ReturnDetail.
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Drawer, ErrorBanner } from '@/components';
import { bi, t } from '@/i18n';
import ReturnDetail from './ReturnDetail.vue';
import { RETURN_TYPE_LABELS, labelOf, sourceOf } from './shared';

const props = defineProps({ number: { type: String, default: null } });
const emit = defineEmits(['close']);

const router = useRouter();
const q = useGet(() => (props.number ? `/returns/${encodeURIComponent(props.number)}` : null));
const r = computed(() => (props.number ? q.data.value : null));
const sub = computed(() => (r.value ? `${bi(labelOf(RETURN_TYPE_LABELS, r.value.type))} · ${sourceOf(r.value)}` : null));
</script>

<template>
  <Drawer :open="!!number" :width="720" :sub="sub" @close="emit('close')">
    <template #title><span class="num">{{ number }}</span></template>
    <template #headExtra><Btn size="sm" tone="ghost" :label="{ ar: 'صفحة كاملة', en: 'Full page' }" @click="router.push(`/rtn/${encodeURIComponent(number || '')}`)" /></template>
    <ErrorBanner :error="q.error.value" :closable="false" />
    <div v-if="q.isLoading.value && !r" class="skel h-[140px]" />
    <ReturnDetail v-if="r" :ret="r" @done="q.refetch()" />
    <div class="hint teal mt-3">{{ t('طلب ← اعتماد ← استلام ← فحص ← قرار (للمخزون / حجر / تالف / للمورد)', 'Request → approve → receive → inspect → decide (restock / quarantine / damaged / supplier)') }}</div>
  </Drawer>
</template>
