<script setup>
// Inbound shipment — deep link /shipments/:number: shipment panel (receiving form + actions), putaway tasks, GRNs, status history.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, ErrorBanner, PageHead, SectionCard } from '@/components';
import { fmtDate, t } from '@/i18n';
import PutawayList from './PutawayList.vue';
import ShipmentHistory from './ShipmentHistory.vue';
import ShipmentPanel from './ShipmentPanel.vue';
import { pn } from './shared';

const route = useRoute();
const router = useRouter();
const number = computed(() => String(route.params.number || ''));
const q = useGet(() => (number.value ? `/inbound/shipments/${encodeURIComponent(number.value)}` : null));
const s = computed(() => q.data.value);

const sub = computed(() => (s.value ? `${pn(s.value.supplier)} · ${s.value.warehouse.code} ${pn(s.value.warehouse)} · PO ${s.value.po.number}${s.value.carrier ? ` · ${t('الناقل', 'Carrier')} ${s.value.carrier}` : ''}` : null));
const historySub = computed(() => {
  const x = s.value;
  if (!x) return null;
  return {
    ar: `أُنشئت ${fmtDate(x.createdAt)}${x.arrivedAt ? ` · وصلت ${fmtDate(x.arrivedAt)}` : ''}${x.completedAt ? ` · اكتملت ${fmtDate(x.completedAt)}` : ''}`,
    en: `Created ${fmtDate(x.createdAt)}${x.arrivedAt ? ` · arrived ${fmtDate(x.arrivedAt)}` : ''}${x.completedAt ? ` · completed ${fmtDate(x.completedAt)}` : ''}`,
  };
});
</script>

<template>
  <PageHead :sub="sub">
    <Btn tone="outline" :label="{ ar: '← الاستلام', en: '← Receiving' }" @click="router.push('/receiving')" />
    <Btn v-if="s" tone="softPurple" :label="{ ar: `أمر الشراء ${s.po.number}`, en: `PO ${s.po.number}` }" @click="router.push(`/po/${encodeURIComponent(s.po.number)}`)" />
  </PageHead>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="skel min-h-[240px]" />

  <template v-if="s">
    <div class="-mt-3.5"><ShipmentPanel :shipment="s" @changed="q.refetch()" @grn-posted="q.refetch()" /></div>
    <PutawayList v-if="['putaway', 'done'].includes(s.status)" :params="{ grn: s.grns[0]?.number, status: s.status === 'done' ? 'done' : 'open' }" />
    <div class="grid-2 mt-3.5">
      <SectionCard :title="{ ar: 'إشعارات الاستلام GRN', en: 'Goods receipts' }" :count="s.grns.length">
        <div v-if="s.grns.length === 0" class="empty">{{ t('لم يُصدر GRN بعد', 'No GRN posted yet') }}</div>
        <div v-else class="col !gap-1.5">
          <RouterLink v-for="g in s.grns" :key="g.number" :to="`/grn/${encodeURIComponent(g.number)}`" class="flex items-center gap-2.5 rounded-[11px] border border-line-2 px-[13px] py-[9px] text-[10.5px] !text-inherit">
            <span class="num text-ok">{{ g.number }}</span><span class="grow" /><span class="cell-date ltr">{{ fmtDate(g.postedAt) }}</span><span v-if="g.postedBy" class="muted text-[9px]">{{ g.postedBy }}</span>
          </RouterLink>
        </div>
      </SectionCard>
      <SectionCard :title="{ ar: 'سجل الحالة', en: 'Status history' }" :sub="historySub">
        <ShipmentHistory :shipment="s" />
      </SectionCard>
    </div>
  </template>
</template>
