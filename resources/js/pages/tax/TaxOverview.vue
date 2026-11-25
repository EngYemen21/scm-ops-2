<script setup>
// What the section opens on — GET /tax/overview: the e-invoicing mode (said as the server says it: while no unit is
// onboarded nothing is sent to the authority), the counts of the e-invoice register, what waits for a person, the
// taxpayer's readiness, the open VAT returns with the period the next one would be, and the history that came from Odoo.
import { computed } from 'vue';
import { useGet } from '@/api/client';
import { Btn, Chip, DataTable, ErrorBanner, KpiCard, KpiGrid, SectionCard } from '@/components';
import { bi, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { FREQUENCY, fmtDay, labelOf, money, signClass, useTaxLookups, useTaxNav } from './tax';

const nav = useTaxNav();
const auth = useAuth();
const lk = useTaxLookups();
const q = useGet('/tax/overview');
const d = computed(() => q.data.value || null);
const ei = computed(() => d.value?.einvoicing || null);
const mode = computed(() => ei.value?.mode || null);
const modeCls = computed(() => (mode.value === 'sending' ? 'green' : 'amber'));
const counts = computed(() => Object.entries(ei.value?.counts || {}));
const missing = computed(() => d.value?.profile?.missing || []);
const openReturns = computed(() => d.value?.returns?.open || []);
const suggest = computed(() => d.value?.returns?.suggest || null);
const last = computed(() => d.value?.returns?.last || null);
const canKeepProfile = computed(() => auth.can(['vatreturn.manage', 'einvoice.manage']));

const returnCols = [
  { key: 'periodCode', header: { ar: 'الفترة', en: 'Period' }, width: 'minmax(110px,1fr)', kind: 'id' },
  { key: 'frequency', header: { ar: 'الدورية', en: 'Frequency' }, width: '90px', value: (r) => labelOf(FREQUENCY, r.frequency) },
  { key: 'periodTo', header: { ar: 'نهاية الفترة', en: 'Period end' }, width: '96px', kind: 'date', value: (r) => fmtDay(r.periodTo) },
  { key: 'netDue', header: { ar: 'صافي المستحق', en: 'Net due' }, width: '120px', kind: 'num' },
  { key: 'status', header: { ar: 'الحالة', en: 'Status' }, width: '110px', bare: true },
];
</script>

<template>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <div v-if="!d && q.isLoading.value" class="grid gap-3">
    <div class="skel h-[70px]" /><div class="skel h-[90px]" /><div class="skel h-[160px]" />
  </div>
  <template v-else-if="d">
    <!-- the mode: the server's own words; never a fake "sent" -->
    <div v-if="ei" class="banner !mb-3" :class="modeCls" role="status">
      <div class="flex-1">
        <div class="mb-0.5 text-[11.5px] font-extrabold">
          <template v-if="mode === 'not_onboarded'">{{ t('الربط مع الهيئة: بانتظار الربط (Integration Pending)', 'Authority link: Integration Pending') }}</template>
          <template v-else-if="mode === 'paused'">{{ t('الربط مع الهيئة: الإرسال موقوف', 'Authority link: sending paused') }}</template>
          <template v-else>{{ t('الربط مع الهيئة: يعمل', 'Authority link: live') }}</template>
        </div>
        <div>{{ bi(ei.modeText) }}</div>
        <div v-if="ei.unit" class="mt-1 text-[10px] font-bold opacity-80">
          {{ t('الوحدة', 'Unit') }}: <span dir="auto">{{ ei.unit.name }}</span> · <Chip small :map="lk.unitStates.value" :k="ei.unit.status" />
          <template v-if="ei.unit.environmentLabel"> · {{ bi(ei.unit.environmentLabel) }}</template>
        </div>
      </div>
      <Btn size="sm" tone="soft" :label="{ ar: 'الوحدات والربط', en: 'Units & onboarding' }" @click="nav.go('units')" />
    </div>

    <KpiGrid compact>
      <KpiCard :value="ei?.total ?? null" :label="{ ar: 'فواتير إلكترونية سارية', en: 'Current e-invoices' }" clickable @click="nav.go('einvoices')" />
      <KpiCard :value="ei?.pending ?? null" :label="{ ar: 'فواتير بلا فاتورة إلكترونية', en: 'Invoices without an e-invoice' }" :color="ei?.pending ? '#b26a16' : '#20242E'" clickable @click="nav.go('einvoices', { view: 'pending' })" />
      <KpiCard :value="ei?.withIssues ?? null" :label="{ ar: 'بملاحظات تحقق', en: 'With validation issues' }" :color="ei?.withIssues ? '#b23b3b' : '#20242E'" clickable @click="nav.go('einvoices', { issues: 'yes' })" />
      <KpiCard :value="ei?.overdue ?? null" :label="{ ar: 'مبسطة تجاوزت 24 ساعة', en: 'Simplified past 24 h' }" :color="ei?.overdue ? '#b23b3b' : '#20242E'" />
      <KpiCard :value="ei?.stuck ?? null" :label="{ ar: 'عالقة (5 محاولات+)', en: 'Stuck (5+ attempts)' }" :color="ei?.stuck ? '#b23b3b' : '#20242E'" clickable @click="nav.go('einvoices', { status: 'queued' })" />
      <KpiCard :value="ei?.cancelledAfterAcceptance ?? null" :label="{ ar: 'أُلغيت بعد قبولها', en: 'Cancelled after acceptance' }" :sub="{ ar: 'تحتاج إشعارًا دائنًا', en: 'Need a credit note' }" :color="ei?.cancelledAfterAcceptance ? '#b23b3b' : '#20242E'" />
    </KpiGrid>

    <SectionCard :title="{ ar: 'الفواتير الإلكترونية حسب الحالة', en: 'E-invoices by status' }" class="mb-3">
      <div class="flex flex-wrap gap-2">
        <button v-for="[k, n] in counts" :key="k" type="button" class="pill" @click="nav.go('einvoices', { status: k })">
          <Chip small :map="lk.einvoiceStates.value" :k="k" /><b class="num">{{ fmtNum(n) }}</b>
        </button>
        <span v-if="!counts.length" class="text-[10px] text-muted">{{ t('لا فواتير إلكترونية بعد', 'No e-invoice yet') }}</span>
      </div>
      <div v-if="ei" class="mt-2 text-[10px] text-muted">
        {{ ei.auto ? t('الإنشاء التلقائي مفعّل', 'Automatic making is on') : t('الإنشاء التلقائي متوقف', 'Automatic making is off') }}
        <template v-if="ei.from"> · {{ t('من تاريخ', 'from') }} <span class="num">{{ fmtDay(ei.from) }}</span></template>
      </div>
    </SectionCard>

    <div class="mb-3 grid gap-3 md:grid-cols-2">
      <SectionCard :title="{ ar: 'جاهزية بيانات المكلّف', en: 'Taxpayer readiness' }">
        <template #actions><Btn size="sm" tone="soft" :label="canKeepProfile ? { ar: 'تعديل البيانات', en: 'Edit profile' } : { ar: 'عرض البيانات', en: 'View profile' }" @click="nav.go('parties', { view: 'profile' })" /></template>
        <div v-if="!missing.length" class="hint green !mt-0">{{ t('بيانات المكلّف مكتملة لإصدار الفاتورة الإلكترونية', 'The taxpayer data is complete for e-invoicing') }}</div>
        <div v-else class="hint amber !mt-0">
          <div class="font-extrabold">{{ t('ينقص بيانات المكلّف ما يلي — لا تصدر فاتورة إلكترونية صحيحة قبل إكمالها:', 'The taxpayer data lacks the following — no valid e-invoice before it is completed:') }}</div>
          <ul class="ms-4 list-disc"><li v-for="m in missing" :key="m.field">{{ bi(m) }}</li></ul>
        </div>
        <div class="mt-2 text-[10px] text-muted">
          <span dir="auto">{{ d.profile?.legalNameAr || '—' }}</span> · <span class="num ltr">{{ d.profile?.vatNo || '—' }}</span> · {{ labelOf(FREQUENCY, d.profile?.filingFrequency) }}
        </div>
      </SectionCard>

      <SectionCard :title="{ ar: 'سجل أودو للفوترة الإلكترونية', en: 'Odoo e-invoicing history' }">
        <template #actions><Btn size="sm" tone="soft" :label="{ ar: 'فتح السجل', en: 'Open history' }" @click="nav.go('history')" /></template>
        <div class="kv-grid">
          <div class="tile"><div class="text-[9.5px] text-muted">{{ t('مستندات', 'Documents') }}</div><div class="num text-[18px] font-extrabold">{{ fmtNum(d.history?.total || 0) }}</div></div>
          <div class="tile" :class="d.history?.notAccepted ? 'amber' : ''"><div class="text-[9.5px] text-muted">{{ t('لم تُقبل (لم تُرسل أو تعذّر)', 'Not accepted (not sent or failed)') }}</div><div class="num text-[18px] font-extrabold">{{ fmtNum(d.history?.notAccepted || 0) }}</div></div>
        </div>
        <div v-if="d.projection" class="mt-2 text-[10px] text-muted">
          {{ d.projection.follows ? t('القسم يتبع أودو: الرموز والمواقف القادمة من أودو للقراءة هنا', 'The section follows Odoo: codes and positions from Odoo are read-only here') : t('القسم مُسلَّم للعمليات', 'The section is handed over to OPS') }}
          <template v-if="d.projection.ready"> · {{ t('بانتظار النسخ', 'waiting to copy') }}: <span class="num">{{ fmtNum(d.projection.waiting || 0) }}</span></template>
        </div>
      </SectionCard>
    </div>

    <SectionCard :title="{ ar: 'إقرارات ضريبة القيمة المضافة المفتوحة', en: 'Open VAT returns' }" :count="openReturns.length" :padded="false" class="mb-3">
      <template #actions><Btn size="sm" tone="soft" :label="{ ar: 'كل الإقرارات', en: 'All returns' }" @click="nav.go('returns')" /></template>
      <div v-if="suggest" class="hint teal !m-3">
        {{ t('الفترة المقترحة للإقرار التالي', 'Suggested next period') }}: <b class="num">{{ suggest.code }}</b>
        (<span class="num">{{ fmtDay(suggest.from) }} – {{ fmtDay(suggest.to) }}</span>) ·
        <template v-if="suggest.exists">{{ t('يوجد إقرار لهذه الفترة', 'A return exists for this period') }}</template>
        <template v-else>
          {{ t('لا إقرار لها بعد', 'no return for it yet') }}
          <Btn v-if="auth.can('vatreturn.manage')" size="sm" tone="primary" class="ms-2" :label="{ ar: '+ إقرار لهذه الفترة', en: '+ Return for this period' }" @click="nav.go('returns', { new: 1 })" />
        </template>
      </div>
      <DataTable :columns="returnCols" :rows="openReturns" :row-key="(r) => r.id" :empty-text="{ ar: 'لا إقرارات مفتوحة', en: 'No open return' }" @row-click="(r) => nav.go('returns', { ret: r.id })">
        <template #cell-netDue="{ row }"><span class="num" :class="signClass(row.netDue)">{{ money(row.netDue) }}</span></template>
        <template #cell-status="{ row }"><Chip small :map="lk.returnStates.value" :k="row.status" /></template>
      </DataTable>
      <div v-if="last" class="px-4 py-3 text-[10px] text-muted">
        {{ t('آخر إقرار مقدَّم', 'Last filed return') }}: <b class="num">{{ last.periodCode }}</b> · {{ t('صافي', 'net') }} <span class="num">{{ money(last.netDue) }}</span>
        <template v-if="last.filedOn"> · {{ t('قُدّم في', 'filed on') }} <span class="num">{{ fmtDay(last.filedOn) }}</span></template>
        <template v-if="last.filedReference"> · <span class="num ltr">{{ last.filedReference }}</span></template>
      </div>
    </SectionCard>
  </template>
</template>
