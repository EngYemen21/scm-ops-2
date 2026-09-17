<script setup>
// Receiving › GRN log: searchable list of goods receipt notes; `sel` (?grn=GRN-…) swaps the list for the GRN detail.
import { ref, watch } from 'vue';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, ErrorBanner, TextInput } from '@/components';
import { fmtDate, fmtNum, lang, t } from '@/i18n';
import { useWarehouse } from '@/stores/warehouse';
import GrnDetail from './GrnDetail.vue';
import { pn } from './shared';

const props = defineProps({ sel: { type: String, default: null } });
const emit = defineEmits(['update:sel']);

const wh = useWarehouse();
const q = ref('');
const page = ref(1);
watch([q, () => wh.whParams.warehouse], () => { page.value = 1; });

const list = useList('/inbound/grns', () => ({ q: q.value, page: page.value, pageSize: 25, ...wh.whParams }));
const detail = useGet(() => (props.sel ? `/inbound/grns/${encodeURIComponent(props.sel)}` : null));

const sumOf = (g, key) => g.lines.reduce((a, l) => a + l[key], 0);
</script>

<template>
  <div class="row wrap mb-2.5">
    <TextInput v-model="q" small type="search" class="!w-[240px]" :placeholder="{ ar: 'بحث: GRN / PO / ملخص', en: 'Search: GRN / PO / summary' }" />
    <Btn v-if="sel" size="sm" tone="soft" :label="{ ar: '← كل الإشعارات', en: '← All GRNs' }" @click="emit('update:sel', null)" />
  </div>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <ErrorBanner :error="sel ? detail.error.value : null" :closable="false" />

  <template v-if="sel">
    <div v-if="detail.isLoading.value" class="skel min-h-[200px]" />
    <GrnDetail v-else-if="detail.data.value" :grn="detail.data.value" />
  </template>
  <div v-else class="card">
    <div class="card-head">
      <div class="card-title">{{ t('سجل إشعارات الاستلام GRN', 'Goods Receipt Notes') }}</div>
      <span v-if="list.data.value" class="card-count num">{{ fmtNum(list.data.value.total) }}</span>
    </div>
    <div v-if="list.isLoading.value && !list.data.value" class="skel m-[18px] min-h-[100px]" />
    <div v-if="list.data.value && list.data.value.items.length === 0" class="empty">{{ t('لا إشعارات استلام بعد.', 'No goods receipts yet.') }}</div>
    <div v-for="g in list.data.value?.items || []" :key="g.id" class="row wrap cursor-pointer border-t border-line-2 px-[18px] py-2.5 !gap-3" @click="emit('update:sel', g.number)">
      <span class="num min-w-[130px] text-[10px] text-ok">{{ g.number }}</span>
      <RouterLink :to="`/po/${encodeURIComponent(g.po.number)}`" class="num min-w-[120px] text-[10px] !text-violet" @click.stop>{{ g.po.number }}</RouterLink>
      <span class="min-w-[150px] text-[10.5px] font-extrabold">{{ pn(g.supplier) }}</span>
      <span class="min-w-[200px] flex-1 text-[10px] leading-[1.7] text-sec">{{ lang === 'ar' ? g.summaryAr : g.summaryEn }}</span>
      <span class="row !gap-1">
        <Chip small fg="#1d7a3e" bg="#e6f9ec" :label="`${fmtNum(sumOf(g, 'acceptedQty'))} ✓`" />
        <Chip v-if="sumOf(g, 'damagedQty') > 0" small fg="#b23b3b" bg="#fdecec" :label="`${fmtNum(sumOf(g, 'damagedQty'))} ${t('تالف', 'dmg')}`" />
        <Chip v-if="sumOf(g, 'rejectedQty') > 0" small fg="#b26a16" bg="#fbf0dd" :label="`${fmtNum(sumOf(g, 'rejectedQty'))} ${t('مرفوض', 'rej')}`" />
        <Chip v-if="sumOf(g, 'remainingQty') > 0" small fg="#7d7990" bg="#F1EFF6" :label="`${fmtNum(sumOf(g, 'remainingQty'))} ${t('متبقٍ', 'open')}`" />
      </span>
      <span class="cell-date ltr !text-[9px]">{{ fmtDate(g.postedAt) }}</span>
    </div>
    <div v-if="list.data.value && list.data.value.pages > 1" class="gt-foot">
      <span class="flex-1">{{ t('صفحة', 'Page') }} <span class="num">{{ list.data.value.page }} / {{ list.data.value.pages }}</span></span>
      <button type="button" class="pg-btn" :disabled="page <= 1" @click="page -= 1">‹</button>
      <button type="button" class="pg-btn" :disabled="page >= list.data.value.pages" @click="page += 1">›</button>
    </div>
  </div>
</template>
