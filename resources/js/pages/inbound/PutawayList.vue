<script setup>
// Putaway task list (desktop + worker): GET /api/inbound/putaway with the given filter params.
//   <PutawayList :params="{ status: 'open', grn, ...wh.whParams }" big :title="{ ar, en }" />      slot `#title` for rich titles
import { useList } from '@/api/client';
import { ErrorBanner } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import PutawayRow from './PutawayRow.vue';

const props = defineProps({
  /** `{ status?, grn?, warehouse? }` */
  params: { type: Object, required: true },
  big: { type: Boolean, default: false },
  title: { type: [String, Object], default: null },
  emptyText: { type: [String, Object], default: null },
});

const list = useList('/inbound/putaway', () => ({ pageSize: 50, ...props.params }));
</script>

<template>
  <div class="card mt-3.5">
    <div class="card-head">
      <div class="card-title"><slot name="title">{{ title ? bi(title) : t('مهام التخزين Putaway', 'Putaway tasks') }}</slot></div>
      <span v-if="list.data.value" class="card-count num">{{ fmtNum(list.data.value.total) }}</span>
    </div>
    <ErrorBanner :error="list.error.value" :closable="false" class="mx-[18px]" />
    <div v-if="list.isLoading.value && !list.data.value" class="skel m-[18px] min-h-20" />
    <div v-if="list.data.value && list.data.value.items.length === 0" class="empty">{{ emptyText ? bi(emptyText) : t('لا مهام تخزين مفتوحة ✓', 'No open putaway tasks ✓') }}</div>
    <PutawayRow v-for="tk in list.data.value?.items || []" :key="tk.id" :task="tk" :big="big" />
  </div>
</template>
