<script setup>
// Purchase suggestions tab: one card per SKU below its reorder point (available, ADC, lead, safety → suggested qty + why),
// with shortcuts to a PO draft, an RFQ or a PR. Follows the warehouse selector.
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useGet } from '@/api/client';
import { Btn, Chip, ErrorBanner, Tabs } from '@/components';
import { fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { useWarehouse } from '@/stores/warehouse';
import SuggestionConvertDrawer from './SuggestionConvertDrawer.vue';
import { pname, sname } from './shared';

const auth = useAuth();
const wh = useWarehouse();
const router = useRouter();

/** '' = all · '1' = urgent (out of stock) only */
const urgent = ref('');
/** { items: Suggestion[], total, urgent, params: { windowDays, coverDays } } */
const q = useGet('/procurement/suggestions', () => ({ ...wh.whParams, urgent: urgent.value === '1' || undefined, limit: 60 }));
const data = computed(() => q.data.value);
/** { kind: 'po' | 'rfq' | 'pr', s: Suggestion } | null */
const conv = ref(null);

const URGENT_TABS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: '1', label: { ar: 'عاجل — نافد', en: 'Urgent — out of stock' } }];
const tiles = (g) => [[g.avail, t('متاح', 'Avail')], [g.adc, t('استهلاك/يوم', 'ADC')], [g.lead, t('مهلة التوريد', 'Lead time')], [g.safety, t('أمان', 'Safety')]];
const why = (g) => (lang.value === 'ar' ? g.why : g.whyE) || [];
</script>

<template>
  <div class="row wrap mb-2.5">
    <Tabs v-model="urgent" :tabs="URGENT_TABS" variant="pill" class="!mb-0" />
    <span v-if="data" class="muted text-[10px]">{{ fmtNum(data.total) }} {{ t('اقتراح', 'suggestions') }} · {{ fmtNum(data.urgent) }} {{ t('عاجل', 'urgent') }}</span>
  </div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="q.isLoading.value" class="skel min-h-[160px]" />
  <div v-if="data && data.items.length === 0" class="empty success">{{ t('لا اقتراحات معلقة — كل المستويات فوق حدود الطلب ✓', 'No pending suggestions — all levels above reorder points ✓') }}</div>

  <div class="grid grid-cols-[repeat(auto-fit,minmax(330px,1fr))] gap-3">
    <div v-for="g in data?.items || []" :key="`${g.sku}-${g.warehouse || ''}`" class="card sm px-[18px] py-4">
      <div class="row">
        <div class="grow">
          <div class="cursor-pointer text-[12.5px] font-extrabold" @click="router.push(`/product/${encodeURIComponent(g.sku)}`)">{{ pname(g) }}</div>
          <div class="num text-[8.5px] text-faint">{{ g.sku }}{{ g.warehouse ? ` · ${g.warehouse}` : '' }}{{ g.supplier ? ` · ${sname(g.supplier)}` : '' }}</div>
        </div>
        <Chip v-if="g.urgent" fg="#fff" bg="#b23b3b" :label="{ ar: 'عاجل', en: 'Urgent' }" />
      </div>
      <div class="mt-3 grid grid-cols-4 gap-1.5">
        <div v-for="[v, l] in tiles(g)" :key="l" class="tile !p-2 text-center">
          <div class="tile-v !text-center !text-[13px]">{{ fmtNum(v, Number.isInteger(v) ? 0 : 1) }}</div>
          <div class="tile-l !text-[8px] !text-faint">{{ l }}</div>
        </div>
      </div>
      <div class="hint teal !mt-2.5 flex items-center gap-2 !px-[13px] !py-[9px]">
        <span class="num text-[16px] text-brand-dark">{{ fmtNum(g.sug) }}</span>
        <span class="text-[9px] font-extrabold text-brand-dark">{{ t('وحدة — الكمية المقترحة', 'units — suggested qty') }}{{ g.incoming ? ` · ${t('قيد التوريد', 'incoming')} ${fmtNum(g.incoming)}` : '' }}</span>
      </div>
      <div class="col mt-[9px] !gap-1">
        <div v-for="(w, i) in why(g)" :key="i" class="flex items-baseline gap-[7px] text-[9.5px] leading-[1.7] text-muted"><span class="h-[5px] w-[5px] flex-none rounded-full bg-brand" /><span>{{ w }}</span></div>
      </div>
      <div class="row mt-3 !gap-[7px]">
        <Btn v-if="auth.can('po.create')" tone="dark" class="!h-9 flex-1" :label="{ ar: 'تحويل لأمر شراء', en: 'Create PO' }" @click="conv = { kind: 'po', s: g }" />
        <Btn v-if="auth.can('rfq.create')" tone="outline" class="!h-9" :label="{ ar: 'تحويل لـ RFQ', en: 'To RFQ' }" @click="conv = { kind: 'rfq', s: g }" />
        <Btn v-if="auth.can('pr.create')" tone="soft" class="!h-9" label="PR" @click="conv = { kind: 'pr', s: g }" />
      </div>
    </div>
  </div>

  <div class="hint">{{ t(`الاقتراح = استهلاك يومي (آخر ${data?.params?.windowDays ?? 28} يومًا) × مهلة التوريد + مخزون الأمان + تغطية ${data?.params?.coverDays ?? 8} أيام − المتاح. النظام يقترح فقط ولا يُنشئ أوامر تلقائيًا — القرار للمشتريات.`, `Suggestion = ADC (last ${data?.params?.windowDays ?? 28} days) × lead + safety + ${data?.params?.coverDays ?? 8}-day cover − available. The system only suggests; procurement decides.`) }}</div>

  <SuggestionConvertDrawer :conv="conv" @close="conv = null" />
</template>
