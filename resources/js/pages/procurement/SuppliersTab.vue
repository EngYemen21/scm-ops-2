<script setup>
// Suppliers tab: scorecard table (OTIF, fill rate, score bar) + suspend / activate + Supplier 360° drawer.
// Deep links: `?tab=sup&supplier=SUP-…` opens the drawer, `?q=…` pre-fills the search.
// Emits `new-po`, `edit`, `quote` with the supplier record.
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, useAction, useList } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, Tabs } from '@/components';
import { fmtMoney, fmtNum, lang, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { confirm } from '@/stores/ui';
import FilterBar from './FilterBar.vue';
import SupplierDrawer from './SupplierDrawer.vue';
import { qs, scoreColor, sname } from './shared';

const emit = defineEmits(['newPo', 'edit', 'quote']);
const auth = useAuth();
const route = useRoute();
const router = useRouter();
const act = useAction({ invalidate: ['suppliers', 'procurement'] });

/** Supplier code shown in the drawer lives in the query string. */
const sel = computed(() => qs(route.query.supplier) || null);
const setSel = (code) => router.replace({ query: { ...route.query, supplier: code || undefined } });

const q = ref(qs(route.query.q));
const active = ref('');
const page = ref(1);
watch([q, active], () => { page.value = 1; });
const list = useList('/suppliers', () => ({ q: q.value, active: active.value, page: page.value, pageSize: 25 }));

async function suspend(r) {
  const yes = await confirm({ title: { ar: `تعليق المورد ${r.nameAr}؟`, en: `Suspend ${r.nameEn}?` }, sub: { ar: 'لن يمكن إنشاء أوامر شراء جديدة له حتى إعادة تفعيله', en: 'No new POs can be created until reactivated' } });
  if (yes) void act.run(() => api.patch(`/suppliers/${r.id}`, { active: false }), { success: t(`عُلّق المورد ${r.nameAr}`, `${r.nameEn} suspended`) });
}
const activate = (r) => act.run(() => api.patch(`/suppliers/${r.id}`, { active: true }), { success: t(`أُعيد تفعيل ${r.nameAr}`, `${r.nameEn} reactivated`) });
/** Drawer footer buttons: close the drawer, then hand over to the page (forms live there). */
function fromDrawer(event, s) { setSel(null); emit(event, s); }

const ACTIVE_TABS = [{ k: '', label: { ar: 'الكل', en: 'All' } }, { k: 'true', label: { ar: 'نشط', en: 'Active' } }, { k: 'false', label: { ar: 'معلّق', en: 'Suspended' } }];
const columns = [
  { key: 'name', header: { ar: 'المورد', en: 'Supplier' }, width: 'minmax(180px,1.5fr)' },
  { key: 'cat', header: { ar: 'الفئة', en: 'Category' }, width: '120px', class: 'text-[10px] font-bold', value: (r) => (lang.value === 'ar' ? r.category : r.categoryEn) || '—' },
  { key: 'leadDays', header: { ar: 'مهلة التوريد', en: 'Lead time' }, width: '90px', kind: 'num', class: 'text-violet', value: (r) => `${r.leadDays} ${t('يوم', 'days')}` },
  { key: 'otif', header: 'OTIF', width: '70px', kind: 'num', sortable: true, value: (r) => `${fmtNum(r.otif)}%` },
  { key: 'fillRate', header: 'Fill Rate', width: '80px', kind: 'num', value: (r) => `${fmtNum(r.fillRate)}%` },
  { key: 'ordersCount', header: { ar: 'أوامر', en: 'Orders' }, width: '70px', kind: 'num', class: 'muted', value: (r) => fmtNum(r.ordersCount) },
  { key: 'totalValue', header: { ar: 'قيمة المشتريات', en: 'Purchase value' }, width: '120px', kind: 'num', value: (r) => fmtMoney(r.totalValue) },
  { key: 'score', header: { ar: 'التقييم /100', en: 'Score /100' }, width: 'minmax(260px,1.2fr)', sortable: true },
];
</script>

<template>
  <FilterBar v-model="q"><Tabs v-model="active" :tabs="ACTIVE_TABS" variant="pill" class="!mb-0" /></FilterBar>
  <ErrorBanner :error="list.error.value" :closable="false" />
  <div class="card">
    <DataTable :columns="columns" :paged="list.data.value" :loading="list.isLoading.value" :row-key="(r) => r.id" :min-width="1000" :empty-text="{ ar: 'لا موردون مطابقون.', en: 'No matching suppliers.' }" @page="page = $event" @row-click="(r) => setSel(r.code)">
      <template #cell-name="{ row }">
        <div class="cursor-pointer" @click.stop="setSel(row.code)">
          <div class="text-[11.5px] font-extrabold text-violet">{{ sname(row) }} <Chip v-if="row.isNew" small fg="#0d7f93" bg="#d9f4f9" :label="{ ar: 'جديد', en: 'New' }" /></div>
          <div class="num ltr text-start text-[8px] text-faint">{{ row.code }}</div>
        </div>
      </template>
      <template #cell-score="{ row }">
        <div class="row">
          <div class="progress flex-1"><div :style="{ width: `${row.score}%`, background: scoreColor(row.score) }" /></div>
          <span class="num text-[12px]" :style="{ color: scoreColor(row.score) }">{{ fmtNum(row.score) }}</span>
          <Chip v-if="!row.active" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'معلّق', en: 'Suspended' }" />
          <Btn v-if="auth.can('po.create') && row.active" size="sm" tone="dark" class="!h-7 !text-[9px]" :label="{ ar: 'أمر شراء', en: 'New PO' }" @click.stop="emit('newPo', row)" />
          <template v-if="auth.can('supplier.manage')">
            <Btn v-if="row.active" size="sm" tone="dangerOutline" class="!h-7 !text-[9px]" :label="{ ar: 'تعليق', en: 'Suspend' }" @click.stop="suspend(row)" />
            <Btn v-else size="sm" tone="softGreen" class="!h-7 !text-[9px]" :label="{ ar: 'تفعيل', en: 'Activate' }" @click.stop="activate(row)" />
          </template>
        </div>
      </template>
    </DataTable>
  </div>
  <div class="hint">{{ t('Score = السعر 30% + الجودة 25% + OTIF 30% + Fill Rate 15%. المورد دون 65 يُحظر عليه أوامر الشراء الجديدة إلا باستثناء موثق من مدير المشتريات. OTIF/Fill تُحسب من الاستلامات الفعلية.', 'Score = price 30% + quality 25% + OTIF 30% + fill rate 15%. Suppliers below 65 are blocked for new POs unless a documented procurement-manager override is given. OTIF/fill are computed from actual receipts.') }}</div>

  <SupplierDrawer :code="sel" @close="setSel(null)" @new-po="fromDrawer('newPo', $event)" @edit="fromDrawer('edit', $event)" @quote="fromDrawer('quote', $event)" />
</template>
