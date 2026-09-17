<script setup>
// Small bordered key/value list (prototype fdKv) used by the vehicle / driver drawers and the alert modal.
//   <KvList :rows="[{ k: { ar, en }, v: 'text', c: '#b23b3b', num: true }, cond && { id: 'trip', k, v }]">
//     <template #v-trip="{ row }"> custom markup for the row whose id is "trip" </template>
//   </KvList>
// Row: { k: string | {ar,en}, v: string | number | null, c?: colour, num?: Quicksand + LTR, id?: slot name }. Falsy rows are skipped.
import { computed } from 'vue';
import { bi } from '@/i18n';

const props = defineProps({ rows: { type: Array, required: true } });
const shown = computed(() => props.rows.filter(Boolean));
</script>

<template>
  <div class="overflow-hidden rounded-[13px] border border-line-2">
    <div v-for="(r, i) in shown" :key="r.id || i" class="kv items-baseline">
      <div class="kv-k !w-[130px]">{{ bi(r.k) }}</div>
      <div class="kv-v leading-[1.7]" :style="{ color: r.c }">
        <slot v-if="r.id" :name="`v-${r.id}`" :row="r"><span :class="{ num: r.num }">{{ r.v ?? '—' }}</span></slot>
        <span v-else :class="{ num: r.num }">{{ r.v ?? '—' }}</span>
      </div>
    </div>
  </div>
</template>
