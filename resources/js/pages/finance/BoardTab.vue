<script setup>
// CFO board: cash · receivables · payables · month-to-date P&L · inventory sub-ledger vs GL · VAT position · period state,
// plus the sub-ledger ↔ general-ledger reconciliation the auditor asks for first.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, KpiCard, KpiGrid, SectionCard } from '@/components';
import { fmtMoney, fmtNum, t } from '@/i18n';
import { PERIOD_LABELS } from './shared';

const props = defineProps({ board: { type: Object, default: null }, loading: { type: Boolean, default: false } });
const emit = defineEmits(['go']);
const recQ = useGet('/finance/reconciliation', undefined, { refetchInterval: 300_000, enabled: () => !!props.board });
const rec = computed(() => recQ.data.value);
const sar = t('ر.س', 'SAR');
const b = computed(() => props.board);
</script>

<template>
  <KpiGrid>
    <KpiCard clickable :value="b ? fmtMoney(b.cash) : null" :label="{ ar: 'النقدية والبنوك', en: 'Cash & banks' }" :unit="sar" color="#1d7a3e" :loading="loading" @click="emit('go', 'payments')" />
    <KpiCard clickable :value="b ? fmtMoney(b.receivables.open) : null" :label="{ ar: 'ذمم العملاء المفتوحة', en: 'Open receivables' }" :unit="sar" :sub="b && b.receivables.overdue.count ? `${fmtNum(b.receivables.overdue.count)} ${t('متأخرة', 'overdue')} · ${fmtMoney(b.receivables.overdue.total)}` : null" :color="b?.receivables.overdue.count ? '#b23b3b' : '#3C79F5'" :loading="loading" @click="emit('go', 'invoices')" />
    <KpiCard clickable :value="b ? fmtMoney(b.payables.open) : null" :label="{ ar: 'ذمم الموردين المفتوحة', en: 'Open payables' }" :unit="sar" :sub="b ? `GRNI ${fmtMoney(b.payables.grni)} · ${t('مستحقات', 'accrued')} ${fmtMoney(b.payables.accrued)}` : null" color="#b26a16" :loading="loading" @click="emit('go', 'payments')" />
    <KpiCard :value="b ? fmtMoney(b.monthToDate.revenue) : null" :label="{ ar: 'إيرادات الشهر', en: 'Revenue MTD' }" :unit="sar" :sub="b && b.monthToDate.grossMarginPct != null ? `${t('هامش إجمالي', 'gross margin')} ${fmtNum(b.monthToDate.grossMarginPct, 1)}%` : null" color="#654e92" :loading="loading" />
    <KpiCard :value="b ? fmtMoney(b.monthToDate.netIncome) : null" :label="{ ar: 'صافي الدخل للشهر', en: 'Net income MTD' }" :unit="sar" :sub="b ? `${t('تكلفة المبيعات', 'COGS')} ${fmtMoney(b.monthToDate.cogs)} · ${t('مصروفات', 'opex')} ${fmtMoney(b.monthToDate.opex)}` : null" :color="b && b.monthToDate.netIncome < 0 ? '#b23b3b' : '#0d7f93'" :loading="loading" />
    <KpiCard :value="b ? fmtMoney(b.vat.netPayable) : null" :label="{ ar: 'ضريبة مستحقة للشهر', en: 'VAT payable MTD' }" :unit="sar" :sub="b ? `${t('مخرجات', 'output')} ${fmtMoney(b.vat.outputVat)} − ${t('مدخلات', 'input')} ${fmtMoney(b.vat.inputVat)}` : null" color="#55506a" :loading="loading" />
  </KpiGrid>

  <div class="grid-2 mt-3">
    <SectionCard :title="{ ar: 'المخزون — الدفتر المساعد مقابل الأستاذ', en: 'Inventory — sub-ledger vs GL' }">
      <div v-if="b" class="kv-grid">
        <div class="kv"><div class="kv-k">{{ t('قيمة المخزون المقيَّم', 'Valued stock') }}</div><div class="kv-v num">{{ fmtMoney(b.inventory.subLedger) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('رصيد حساب المخزون', 'GL inventory') }}</div><div class="kv-v num">{{ fmtMoney(b.inventory.glBalance) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('الفرق', 'Difference') }}</div><div class="kv-v num" :class="Math.abs(b.inventory.difference) < 0.01 ? 'text-ok' : 'text-bad'">{{ fmtMoney(b.inventory.difference) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('بضاعة في الطريق للعملاء', 'Goods in transit') }}</div><div class="kv-v num">{{ fmtMoney(b.inventory.goodsInTransit) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('تحويلات في الطريق', 'Transfers in transit') }}</div><div class="kv-v num">{{ fmtMoney(b.inventory.inTransit) }}</div></div>
      </div>
      <div v-if="b?.inventory.unvaluedProducts" class="banner amber mt-2">{{ t(`${fmtNum(b.inventory.unvaluedProducts)} صنف برصيد لم يُقيَّم بعد (مخزون سابق لبدء الدفاتر) — يُقيَّم بأمر الرصيد الافتتاحي بعد اعتماد المسؤول المالي لأساس التقييم`, `${fmtNum(b.inventory.unvaluedProducts)} product(s) with stock are not valued yet (pre-ledger stock) — run the opening-balance command after the CFO approves the valuation basis`) }}</div>
    </SectionCard>

    <SectionCard :title="{ ar: 'الفترة والرقابة', en: 'Period & controls' }">
      <div v-if="b" class="kv-grid">
        <div class="kv"><div class="kv-k">{{ t('الفترة الحالية', 'Current period') }}</div><div class="kv-v num row !gap-1.5">{{ b.period.current }} <Chip :map="PERIOD_LABELS" :k="b.period.status" small /></div></div>
        <div class="kv"><div class="kv-k">{{ t('فترات سابقة مفتوحة', 'Earlier open periods') }}</div><div class="kv-v num">{{ b.period.earlierOpen.length ? b.period.earlierOpen.join('، ') : '—' }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('قيود يدوية بانتظار الاعتماد', 'Drafts awaiting approval') }}</div><div class="kv-v num" :class="b.period.draftJournals ? 'text-warn' : ''">{{ fmtNum(b.period.draftJournals) }}</div></div>
        <div class="kv"><div class="kv-k">{{ t('قيود اليوم', 'Journals today') }}</div><div class="kv-v num">{{ fmtNum(b.journalsToday) }}</div></div>
      </div>
      <div class="row mt-3 !gap-1.5">
        <Btn size="sm" tone="soft" :label="{ ar: 'الفترات', en: 'Periods' }" @click="emit('go', 'periods')" />
        <Btn size="sm" tone="soft" :label="{ ar: 'القيود', en: 'Journals' }" @click="emit('go', 'journals')" />
        <Btn size="sm" tone="softPurple" :label="{ ar: 'التقارير', en: 'Reports' }" @click="emit('go', 'reports')" />
      </div>
    </SectionCard>
  </div>

  <SectionCard class="mt-3" :title="{ ar: 'مطابقة الدفاتر المساعدة مع الأستاذ العام', en: 'Sub-ledger ↔ general ledger reconciliation' }" :sub="{ ar: 'كل رصيد تحكم يساوي مجموع مستنداته؛ وميزان المراجعة متوازن', en: 'Every control balance equals its documents; the trial balance balances' }">
    <div v-if="!rec" class="skel min-h-[60px]" />
    <div v-else class="col !gap-1.5">
      <div v-for="c in rec.checks" :key="c.key" class="row rounded-[11px] border border-line-2 px-[13px] py-[9px] text-[10.5px]">
        <span class="font-extrabold">{{ t(c.labelAr, c.labelEn) }}</span><span class="grow" />
        <span class="num text-muted">{{ t('الدفتر المساعد', 'Sub-ledger') }} {{ fmtMoney(c.subLedger) }}</span>
        <span class="num text-muted">{{ t('الأستاذ', 'GL') }} {{ fmtMoney(c.gl) }}</span>
        <Chip :fg="c.ok ? '#1d7a3e' : '#b23b3b'" :bg="c.ok ? '#e6f9ec' : '#fdecec'" :label="c.ok ? { ar: 'مطابق ✓', en: 'Matches ✓' } : { ar: `فرق ${fmtMoney(c.difference)}`, en: `Δ ${fmtMoney(c.difference)}` }" small />
      </div>
      <div class="row rounded-[11px] border border-line-2 px-[13px] py-[9px] text-[10.5px]">
        <span class="font-extrabold">{{ t('ميزان المراجعة', 'Trial balance') }}</span><span class="grow" />
        <Chip :fg="rec.trialBalanceBalanced ? '#1d7a3e' : '#b23b3b'" :bg="rec.trialBalanceBalanced ? '#e6f9ec' : '#fdecec'" :label="rec.trialBalanceBalanced ? { ar: 'متوازن ✓', en: 'Balanced ✓' } : { ar: 'غير متوازن', en: 'Unbalanced' }" small />
      </div>
    </div>
  </SectionCard>
</template>
