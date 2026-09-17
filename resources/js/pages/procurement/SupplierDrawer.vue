<script setup>
// Supplier 360° drawer: scorecard tiles, then tabs — overview (meta), recent POs, quotations, products, performance.
//   <SupplierDrawer :code="codeOrNull" @close @new-po="(supplier) => …" @edit="…" @quote="…" />
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useGet, useList } from '@/api/client';
import { Btn, Chip, Drawer, ErrorBanner, Tabs } from '@/components';
import { fmtDateOnly, fmtMoney, fmtNum, lang, t } from '@/i18n';
import { PO_LABELS } from '@/shared';
import { useAuth } from '@/stores/auth';
import { SQ_LABELS, pname, scoreColor } from './shared';

const props = defineProps({
  /** Supplier code; null = closed. */
  code: { type: String, default: null },
});
const emit = defineEmits(['close', 'newPo', 'edit', 'quote']);

const auth = useAuth();
const router = useRouter();
const path = computed(() => (props.code ? `/suppliers/${encodeURIComponent(props.code)}` : null));
/** Supplier + { products[{ price, leadDays, preferred, product }], recentPos[], openPosCount, blocksPo } */
const detail = useGet(path);
/** { otif, fillRate, leadDays, score, ordersCount, totalValue, openPos, basis{ source, ordersWithGrn, onTimeInFull, orderedQty, acceptedQty } } */
const perfQ = useGet(() => (path.value ? `${path.value}/performance` : null));
const quotesQ = useList('/procurement/quotations', () => ({ supplier: props.code || '', pageSize: 10 }), { enabled: () => !!props.code });

const s = computed(() => (props.code ? detail.data.value : null));
const perf = computed(() => (props.code ? perfQ.data.value : null));
const quotes = computed(() => quotesQ.data.value?.items || []);

const tab = ref('over');
watch(() => props.code, () => { tab.value = 'over'; });
const TABS = [
  { k: 'over', label: { ar: 'نظرة عامة', en: 'Overview' } }, { k: 'po', label: { ar: 'أوامر الشراء', en: 'Purchase orders' } }, { k: 'sq', label: { ar: 'عروض الأسعار', en: 'Quotations' } },
  { k: 'prod', label: { ar: 'المنتجات', en: 'Products' } }, { k: 'perf', label: { ar: 'الأداء', en: 'Performance' } },
];

const score = computed(() => perf.value?.score ?? s.value?.score ?? 0);
const tone3 = (v, hi, mid) => (v >= hi ? '#1d7a3e' : v >= mid ? '#b26a16' : '#b23b3b');
const bars = computed(() => (perf.value ? [
  { l: 'OTIF', w: '30%', v: `${fmtNum(perf.value.otif)}%`, pct: perf.value.otif, c: tone3(perf.value.otif, 90, 75) },
  { l: 'Fill rate', w: '15%', v: `${fmtNum(perf.value.fillRate)}%`, pct: perf.value.fillRate, c: tone3(perf.value.fillRate, 95, 85) },
  { l: t('التقييم الكلي', 'Overall score'), w: '/100', v: fmtNum(score.value), pct: score.value, c: scoreColor(score.value) },
] : []));
const overview = computed(() => {
  const x = s.value;
  if (!x) return [];
  return [
    [t('السجل التجاري', 'CR'), x.cr], [t('الرقم الضريبي', 'VAT'), x.vat], [t('جهة الاتصال', 'Contact'), x.contact], [t('البريد', 'Email'), x.email], [t('شروط الدفع', 'Terms'), x.terms], ['IBAN', x.iban],
    [t('حد أدنى للطلب', 'Min order'), x.minOrder != null ? fmtMoney(x.minOrder) : null], [t('الحالة', 'Status'), x.active ? t('نشط', 'Active') : t('معلّق', 'Suspended')],
    [t('مورد جديد', 'New supplier'), x.isNew ? t('نعم — قيد التقييم', 'Yes — under evaluation') : t('لا', 'No')],
  ];
});
const basisText = computed(() => {
  const b = perf.value?.basis;
  if (!b) return '';
  return b.source === 'computed'
    ? t(`محسوب من ${b.ordersWithGrn} أمر شراء مستلم — في الوقت وبالكامل ${b.onTimeInFull} · مقبول ${fmtNum(b.acceptedQty)} من ${fmtNum(b.orderedQty)}`, `Computed from ${b.ordersWithGrn} received POs — OTIF ${b.onTimeInFull} · accepted ${fmtNum(b.acceptedQty)} of ${fmtNum(b.orderedQty)}`)
    : t('لا سجل استلام بعد — القيم المخزنة عند تسجيل المورد', 'No receipt history yet — stored values');
});
</script>

<template>
  <Drawer :open="!!code" :width="560" :sub="s ? `${s.code} · ${(lang === 'ar' ? s.category : s.categoryEn) || '—'} · Supplier 360°` : null" @close="emit('close')">
    <template #title>
      <span v-if="s">{{ pname(s) }} <Chip v-if="!s.active" small fg="#b23b3b" bg="#fdecec" :label="{ ar: 'معلّق', en: 'Suspended' }" /></span>
      <template v-else>{{ t('المورد', 'Supplier') }}</template>
    </template>
    <template #headExtra>
      <div v-if="s" class="num flex h-10 w-10 items-center justify-center rounded-[11px] text-[17px] text-white" :style="{ background: scoreColor(score) }" :title="t('التقييم /100', 'Score /100')">{{ fmtNum(score) }}</div>
    </template>

    <ErrorBanner :error="detail.error.value" :closable="false" />
    <div v-if="detail.isLoading.value" class="skel min-h-[160px]" />
    <template v-if="s">
      <div class="kpi-grid compact !mb-3">
        <div class="tile"><div class="tile-v">{{ fmtMoney(perf?.totalValue ?? s.totalValue) }}</div><div class="tile-l">{{ t('إجمالي المشتريات ر.س', 'Purchases SAR') }}</div></div>
        <div class="tile"><div class="tile-v">{{ fmtNum(perf?.ordersCount ?? s.ordersCount) }}</div><div class="tile-l">{{ t('أوامر شراء', 'POs') }}</div></div>
        <div class="tile amber"><div class="tile-v">{{ fmtNum(perf?.openPos ?? s.openPosCount) }}</div><div class="tile-l">{{ t('أوامر مفتوحة', 'Open POs') }}</div></div>
        <div class="tile purple"><div class="tile-v">{{ fmtNum(perf?.leadDays ?? s.leadDays, 1) }}</div><div class="tile-l">{{ t('متوسط التوريد (يوم)', 'Avg lead (days)') }}</div></div>
        <div class="tile green"><div class="tile-v">{{ fmtNum(perf?.otif ?? s.otif) }}%</div><div class="tile-l">OTIF</div></div>
        <div class="tile teal"><div class="tile-v">{{ fmtNum(perf?.fillRate ?? s.fillRate) }}%</div><div class="tile-l">Fill rate</div></div>
      </div>
      <Tabs v-model="tab" :tabs="TABS" variant="pillPurple" class="!mb-2.5" />

      <div v-if="tab === 'over'" class="kv-grid">
        <div v-for="[k, v] in overview" :key="k" class="kv"><div class="kv-k">{{ k }}</div><div class="kv-v num-mixed" dir="auto">{{ v || '—' }}</div></div>
        <div v-if="s.notes" class="kv col-span-full"><div class="kv-k">{{ t('ملاحظات', 'Notes') }}</div><div class="kv-v !font-normal">{{ s.notes }}</div></div>
        <div v-if="s.blocksPo" class="banner amber col-span-full mt-2">{{ t('التقييم دون الحد الأدنى (65) — إنشاء أمر شراء يتطلب استثناءً من مدير المشتريات', 'Score below minimum (65) — creating a PO requires a procurement-manager override') }}</div>
      </div>

      <div v-else-if="tab === 'po'" class="col !gap-1.5">
        <div v-if="!s.recentPos?.length" class="empty">{{ t('لا سجلات', 'No records') }}</div>
        <div v-for="p in s.recentPos || []" :key="p.number" class="row cursor-pointer !gap-2.5 rounded-[11px] border border-line-2 px-[13px] py-[9px]" @click="router.push(`/po/${encodeURIComponent(p.number)}`)">
          <span class="num ltr min-w-[110px] text-[10.5px] text-violet">{{ p.number }}</span><span class="grow" /><span class="num ltr text-[11px]">{{ fmtMoney(p.total) }}</span><span class="cell-date ltr">{{ fmtDateOnly(p.dueDate) }}</span><Chip :map="PO_LABELS" :k="p.status" small />
        </div>
      </div>

      <div v-else-if="tab === 'sq'" class="col !gap-1.5">
        <div v-if="quotes.length === 0" class="empty">{{ t('لا سجلات', 'No records') }}</div>
        <div v-for="q in quotes" :key="q.id" class="row wrap rounded-[11px] border border-line-2 px-[13px] py-[9px] text-[10.5px]">
          <span class="num ltr text-violet">{{ q.number }}</span>
          <span class="grow ellipsis font-extrabold">{{ q.lines.map((l) => pname(l.product)).join('، ') }}</span>
          <span class="num ltr">{{ q.lines.map((l) => fmtMoney(l.price)).join(' / ') }}</span>
          <span class="cell-date ltr">{{ fmtDateOnly(q.validUntil) }}</span>
          <span v-if="q.attachmentName" class="row !gap-[5px]"><span class="num rounded-[5px] bg-bad px-1.5 py-0.5 text-[8px] text-white">{{ q.attachmentType }}</span><span class="num ltr text-[9.5px] text-brand-dark">{{ q.attachmentName }}</span></span>
          <span v-if="q.rfq" class="num ltr text-azure">{{ q.rfq.number }}</span>
          <Chip :map="SQ_LABELS" :k="q.status" small />
        </div>
      </div>

      <div v-else-if="tab === 'prod'" class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-2">
        <div v-if="!s.products?.length" class="empty">{{ t('لا سجلات', 'No records') }}</div>
        <div v-for="ps in s.products || []" :key="ps.product.sku" class="cursor-pointer rounded-[11px] border border-line-2 px-[13px] py-2.5" @click="router.push(`/product/${encodeURIComponent(ps.product.sku)}`)">
          <div class="text-[10.5px] font-extrabold">{{ pname(ps.product) }} <Chip v-if="ps.preferred" small fg="#1d7a3e" bg="#e6f9ec" :label="{ ar: 'مفضل', en: 'Preferred' }" /></div>
          <div class="num mt-0.5 text-[8.5px] text-faint">{{ ps.product.sku }} · {{ t('السعر', 'Price') }} {{ ps.price != null ? fmtMoney(ps.price) : '—' }} · {{ t('مهلة', 'Lead') }} {{ ps.leadDays ?? s.leadDays }}</div>
        </div>
      </div>

      <div v-else-if="tab === 'perf'" class="col !gap-3">
        <div v-for="r in bars" :key="r.l">
          <div class="row text-[10.5px] font-extrabold text-sec"><span>{{ r.l }}</span><span class="text-[9px] text-faint">{{ r.w }}</span><span class="grow" /><span class="num" :style="{ color: r.c }">{{ r.v }}</span></div>
          <div class="progress mt-1"><div :style="{ width: `${Math.min(100, r.pct)}%`, background: r.c }" /></div>
        </div>
        <div v-if="perf" class="hint">{{ basisText }} · {{ t('Score = السعر 30% + الجودة 25% + OTIF 30% + Fill 15%', 'Score = price 30% + quality 25% + OTIF 30% + fill 15%') }}</div>
      </div>
    </template>

    <template v-if="s" #footer>
      <div class="row wrap !gap-1.5">
        <Btn v-if="auth.can('po.create') && s.active" tone="dark" :label="{ ar: '+ أمر شراء', en: '+ New PO' }" @click="emit('newPo', s)" />
        <Btn v-if="auth.can('supquote.create')" tone="soft" :label="{ ar: '+ تسجيل عرض سعر', en: '+ Record quotation' }" @click="emit('quote', s)" />
        <Btn v-if="auth.can('supplier.manage')" tone="outline" :label="{ ar: 'تعديل', en: 'Edit' }" @click="emit('edit', s)" />
      </div>
    </template>
  </Drawer>
</template>
