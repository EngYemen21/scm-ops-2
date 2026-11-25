<script setup>
// The e-invoicing units and their onboarding with the authority — GET /tax/units → { items }. Each unit shows the four
// steps (`steps[]` done / next), its certificate facts, and what it holds as "held: yes / no" only (the API never
// returns a key, a request, a certificate or a secret — nothing here tries to show one). einvoice.manage:
//   POST /tax/units/{id}/request · compliance { otp } · checks · production · renew { otp } · retire { reason }
//   PATCH /tax/units/{id} { sending | isDefault | identity }            POST /tax/units (UnitForm)
import { computed, ref } from 'vue';
import { api, useAction, useGet } from '@/api/client';
import { Btn, Chip, EmptyState, ErrorBanner, KV, SectionCard, TextInput } from '@/components';
import { bi, fmtDate, fmtNum, t } from '@/i18n';
import { useAuth } from '@/stores/auth';
import { ask, confirm, toast } from '@/stores/ui';
import UnitForm from './UnitForm.vue';
import { DEFAULT_CHIP, TONE, useTaxLookups } from './tax';

const auth = useAuth();
const lk = useTaxLookups();
const q = useGet('/tax/units');
const units = computed(() => q.data.value?.items || []);
const search = ref('');
const shown = computed(() => {
  const s = search.value.trim().toLowerCase();
  return s ? units.value.filter((u) => [u.name, u.serial, u.branchName, u.location].some((x) => String(x || '').toLowerCase().includes(s))) : units.value;
});
const canManage = computed(() => auth.can('einvoice.manage'));
const act = useAction({ invalidate: ['tax'] });
const editing = ref(null);
const creating = ref(false);
const busy = ref(null);
const YES = { ar: 'نعم', en: 'yes' };
const NO = { ar: 'لا', en: 'no' };
const HOLDS = [['key', { ar: 'مفتاح التوقيع', en: 'Signing key' }], ['request', { ar: 'طلب الشهادة', en: 'Certificate request' }], ['complianceCertificate', { ar: 'شهادة الامتثال', en: 'Compliance certificate' }], ['productionCertificate', { ar: 'شهادة الإنتاج', en: 'Production certificate' }]];
// keys of `complianceChecks`: "<profile>.<kind>" (EinvoiceUnitsService::samples)
const CHECK_NAMES = { 'standard.invoice': { ar: 'فاتورة قياسية', en: 'Standard invoice' }, 'standard.credit_note': { ar: 'إشعار دائن قياسي', en: 'Standard credit note' }, 'standard.debit_note': { ar: 'إشعار مدين قياسي', en: 'Standard debit note' },
  'simplified.invoice': { ar: 'فاتورة مبسطة', en: 'Simplified invoice' }, 'simplified.credit_note': { ar: 'إشعار دائن مبسط', en: 'Simplified credit note' }, 'simplified.debit_note': { ar: 'إشعار مدين مبسط', en: 'Simplified debit note' } };

const otpOk = (v) => /^\d{6}$/.test(v);
async function run(u, to, body, success) {
  busy.value = `${u.id}:${to}`;
  try { return await act.run(() => api.postIdempotent(`/tax/units/${encodeURIComponent(u.id)}/${to}`, body), { success }); } finally { busy.value = null; }
}
async function makeRequest(u) {
  if (u.status === 'csr_ready' && !(await confirm({ title: { ar: 'إنشاء طلب الشهادة من جديد؟', en: 'Make the certificate request again?' }, tone: 'dark' }))) return;
  await run(u, 'request', undefined, t('أُنشئ طلب الشهادة', 'Certificate request made'));
}
async function withOtp(u, to, title, success) {
  const otp = await ask({ title, sub: { ar: 'رمز التحقق من بوابة «فاتورة» في الهيئة — 6 أرقام، صالح لدقائق. لا يُحفظ ولا يُسجَّل.', en: 'The one-time password of the authority\'s Fatoora portal — 6 digits, valid for minutes. It is never stored nor logged.' }, label: { ar: 'رمز التحقق (OTP)', en: 'One-time password (OTP)' }, required: true, tone: 'primary' });
  if (otp === null) return;
  if (!otpOk(otp)) { toast.say({ ar: 'رمز التحقق 6 أرقام — لم يُرسل شيء', en: 'The OTP is 6 digits — nothing was sent' }, 3200); return; }
  await run(u, to, { otp }, success);
}
const compliance = (u) => withOtp(u, 'compliance', { ar: `شهادة الامتثال — ${u.name}`, en: `Compliance certificate — ${u.name}` }, t('صدرت شهادة الامتثال', 'Compliance certificate issued'));
const renew = (u) => withOtp(u, 'renew', { ar: `تجديد شهادة ${u.name}`, en: `Renew the certificate of ${u.name}` }, t('جُدّدت الشهادة', 'Certificate renewed'));
async function checks(u) {
  if (!(await confirm({ title: { ar: 'تشغيل فحوص الامتثال؟', en: 'Run the compliance checks?' }, sub: { ar: 'تُرسل فواتير نموذجية (لا فواتير حقيقية) إلى بيئة الامتثال لدى الهيئة.', en: 'Sample documents (not real invoices) are sent to the authority\'s compliance environment.' }, tone: 'primary' }))) return;
  await run(u, 'checks', undefined, (r) => (r?.checksPassed ? t('اجتازت الوحدة فحوص الامتثال', 'The unit passed the compliance checks') : t('لم تجتز كل الفحوص — راجع النتائج', 'Not every check passed — see the results')));
}
async function production(u) {
  if (!(await confirm({ title: { ar: `طلب شهادة الإنتاج — ${u.name}؟`, en: `Request the production certificate — ${u.name}?` }, sub: { ar: 'بعدها تُختم الفواتير الجديدة وتُرسل إلى الهيئة.', en: 'From then on new documents are stamped and sent to the authority.' }, tone: 'primary' }))) return;
  await run(u, 'production', undefined, t('صدرت شهادة الإنتاج — الوحدة مربوطة', 'Production certificate issued — the unit is onboarded'));
}
async function retire(u) {
  const reason = await ask({ title: { ar: `إيقاف الوحدة ${u.name} نهائيًا`, en: `Retire unit ${u.name} for good` }, sub: { ar: 'لا تُصدر ولا تُرسل شيئًا بعدها؛ تبقى مستنداتها وسلسلتها.', en: 'It makes and sends nothing afterwards; its documents and chain stay.' }, label: { ar: 'السبب (5 أحرف على الأقل)', en: 'Reason (5 characters at least)' }, required: true, tone: 'danger' });
  if (reason === null) return;
  await run(u, 'retire', { reason }, t('أُوقفت الوحدة', 'Unit retired'));
}
async function patch(u, body, success) {
  busy.value = `${u.id}:patch`;
  try { await act.run(() => api.patch(`/tax/units/${encodeURIComponent(u.id)}`, body), { success }); } finally { busy.value = null; }
}
const toggleSending = (u) => patch(u, { sending: !u.sending }, u.sending ? t('أُوقف الإرسال', 'Sending paused') : t('شُغّل الإرسال', 'Sending resumed'));
const makeDefault = (u) => patch(u, { isDefault: true }, t('أصبحت الوحدة الافتراضية', 'Now the default unit'));
const isBusy = (u, to) => busy.value === `${u.id}:${to}`;
const checkRows = (u) => Object.entries(u.complianceChecks || {});
</script>

<template>
  <div class="row wrap mb-2.5 items-end gap-2">
    <TextInput v-model="search" small field-class="w-full sm:w-[240px]" :placeholder="{ ar: 'بحث بالاسم أو الرقم التسلسلي أو الفرع…', en: 'Search name, serial, branch…' }" />
    <div class="grow" />
    <Btn v-if="canManage" tone="primary" :label="{ ar: '+ وحدة', en: '+ Unit' }" @click="creating = true" />
  </div>
  <div class="hint teal !mt-0 mb-2.5">{{ t('الربط بالهيئة أربع خطوات: المفتاح وطلب الشهادة ← شهادة الامتثال برمز التحقق من بوابة «فاتورة» ← فحوص الامتثال بفواتير نموذجية ← شهادة الإنتاج. قبل اكتمالها لا يُرسل شيء إلى الهيئة.', 'Onboarding takes four steps: key and certificate request → compliance certificate with the Fatoora portal OTP → compliance checks with sample documents → production certificate. Until then nothing is sent to the authority.') }}</div>
  <ErrorBanner :error="q.error.value" :closable="false" />
  <ErrorBanner :error="act.error.value" @close="act.clearError()" />
  <div v-if="q.isLoading.value && !units.length" class="grid gap-3"><div class="skel h-[180px]" /><div class="skel h-[180px]" /></div>
  <EmptyState v-else-if="!shown.length" tone="dashed" :text="search ? { ar: 'لا وحدات تطابق البحث', en: 'No unit matches the search' } : { ar: 'لا وحدات فوترة بعد — أنشئ أول وحدة من «+ وحدة».', en: 'No e-invoicing unit yet — make the first one with “+ Unit”.' }" />

  <SectionCard v-for="u in shown" :key="u.id" class="mb-3" :title="u.name" :sub="u.environmentLabel ? bi(u.environmentLabel) : u.environment">
    <template #actions>
      <div class="flex flex-wrap items-center gap-1.5">
        <Chip v-if="u.isDefault" small :map="{ d: DEFAULT_CHIP }" k="d" />
        <Chip small :map="lk.unitStates.value" :k="u.status" />
      </div>
    </template>

    <div v-if="u.status !== 'active' && u.status !== 'retired'" class="banner amber !mb-2"><div>{{ t('غير مربوطة بالهيئة (Integration Pending): فواتيرها تُنشأ وتُسلسل ولا تُرسل.', 'Not onboarded (Integration Pending): its documents are made and chained, not sent.') }}</div></div>
    <div v-else-if="u.status === 'active' && !u.sending" class="banner amber !mb-2"><div>{{ t('الوحدة مربوطة لكن الإرسال موقوف يدويًا.', 'The unit is onboarded but sending is paused.') }}</div></div>
    <div v-if="u.environment !== 'production' && u.status !== 'retired'" class="hint purple !mt-0 mb-2">{{ t('بيئة غير الإنتاج: ما يُرسل منها لا يُعدّ إبلاغًا رسميًا للهيئة.', 'Not the production environment: what it sends is not an official report to the authority.') }}</div>

    <!-- the four steps -->
    <div class="mb-3 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-4">
      <div v-for="(s, i) in u.steps || []" :key="s.key" class="tile flex items-start gap-2" :class="s.done ? 'green' : s.next ? 'purple' : ''">
        <span class="num grid h-6 w-6 shrink-0 place-items-center rounded-full text-[10px] font-extrabold" :style="{ background: s.done ? TONE.ok.fg : s.next ? TONE.violet.fg : '#d8d4e3', color: '#fff' }">{{ s.done ? '✓' : i + 1 }}</span>
        <div class="min-w-0">
          <div class="text-[10.5px] font-bold leading-[1.5]">{{ bi(s) }}</div>
          <div class="text-[9px] text-muted">{{ s.done ? t('تمت', 'done') : s.next ? t('الخطوة التالية', 'next step') : t('لاحقًا', 'later') }}</div>
        </div>
      </div>
    </div>

    <div class="kv-grid mb-3">
      <KV :k="{ ar: 'الرقم التسلسلي', en: 'Serial' }" :v="u.serial || '—'" ltr />
      <KV :k="{ ar: 'أنواع الفواتير', en: 'Invoice types' }" :v="[u.standard ? t('قياسية', 'standard') : null, u.simplified ? t('مبسطة', 'simplified') : null].filter(Boolean).join(' · ') + ` (${u.invoiceTypes})`" />
      <KV :k="{ ar: 'الفرع / الموقع / النشاط', en: 'Branch / location / industry' }" :v="[u.branchName, u.location, u.industry].filter(Boolean).join(' · ') || '—'" />
      <KV :k="{ ar: 'العداد الحالي', en: 'Current counter' }" :v="fmtNum(u.icv || 0)" ltr />
      <KV :k="{ ar: 'الإرسال', en: 'Sending' }" :v="u.sending ? t('مفعّل', 'on') : t('موقوف', 'paused')" />
      <KV :k="{ ar: 'أنشأها', en: 'Created by' }" :v="`${u.createdBy || '—'} · ${fmtDate(u.createdAt)}`" />
    </div>

    <div class="mb-3 grid gap-3 md:grid-cols-2">
      <div class="tile">
        <div class="mb-1 text-[11px] font-extrabold">{{ t('ما تحتفظ به الوحدة', 'What the unit holds') }}</div>
        <div v-for="[k, label] in HOLDS" :key="k" class="flex items-center gap-2 py-0.5 text-[10px]">
          <span class="flex-1">{{ bi(label) }}</span>
          <Chip small :label="u.holds?.[k] ? YES : NO" :fg="u.holds?.[k] ? TONE.ok.fg : TONE.neutral.fg" :bg="u.holds?.[k] ? TONE.ok.bg : TONE.neutral.bg" />
        </div>
        <div class="mt-1 text-[9px] text-faint">{{ t('تُعرض كـ«محفوظ: نعم / لا» فقط — لا يُعرض مفتاح أو شهادة أو سر.', 'Shown as “held: yes / no” only — no key, certificate or secret is ever shown.') }}</div>
      </div>
      <div class="tile" :class="u.certificate?.expired ? 'red' : u.certificate && u.certificate.daysLeft != null && u.certificate.daysLeft < 30 ? 'amber' : ''">
        <div class="mb-1 text-[11px] font-extrabold">{{ t('الشهادة', 'Certificate') }}</div>
        <template v-if="u.certificate">
          <div class="text-[10px]"><span class="text-muted">{{ t('الرقم', 'Serial') }}:</span> <span class="num ltr">{{ u.certificate.serial }}</span></div>
          <div class="truncate text-[10px]" :title="u.certificate.issuer"><span class="text-muted">{{ t('المُصدر', 'Issuer') }}:</span> <span class="num ltr">{{ u.certificate.issuer }}</span></div>
          <div class="truncate text-[10px]" :title="u.certificate.subject"><span class="text-muted">{{ t('الموضوع', 'Subject') }}:</span> <span class="num ltr">{{ u.certificate.subject }}</span></div>
          <div class="text-[10px]"><span class="text-muted">{{ t('الصلاحية', 'Valid') }}:</span> <span class="num">{{ fmtDate(u.certificate.notBefore) }} – {{ fmtDate(u.certificate.notAfter) }}</span></div>
          <div class="text-[10px] font-bold" :class="u.certificate.expired ? 'text-bad' : ''">
            {{ u.certificate.expired ? t('منتهية', 'Expired') : t(`متبقٍ ${u.certificate.daysLeft} يومًا`, `${u.certificate.daysLeft} days left`) }}
          </div>
        </template>
        <div v-else class="text-[10px] text-muted">{{ t('لا شهادة بعد', 'No certificate yet') }}</div>
      </div>
    </div>

    <div v-if="checkRows(u).length" class="mb-3">
      <div class="card-title mb-1">{{ t('نتائج فحوص الامتثال', 'Compliance check results') }}</div>
      <div v-for="[k, c] in checkRows(u)" :key="k" class="border-b border-line py-1 text-[10px] last:border-0">
        <div class="flex items-center gap-2">
          <span class="flex-1 font-bold">{{ CHECK_NAMES[k] ? bi(CHECK_NAMES[k]) : k }}</span>
          <span class="num text-muted">{{ c.http || '—' }}</span>
          <Chip small :label="c.passed ? { ar: 'اجتاز', en: 'Passed' } : { ar: 'لم يجتز', en: 'Failed' }" :fg="c.passed ? TONE.ok.fg : TONE.bad.fg" :bg="c.passed ? TONE.ok.bg : TONE.bad.bg" />
        </div>
        <div v-if="c.error" class="text-bad" dir="ltr">{{ c.error }}</div>
        <div v-for="(m, n) in c.messages || []" :key="n" class="text-muted" dir="ltr"><b>{{ m.type }}</b> <span v-if="m.code">{{ m.code }}</span> — {{ m.message }}</div>
      </div>
    </div>

    <div v-if="u.status === 'retired'" class="hint !mt-0">{{ t('أُوقفت', 'Retired') }}: {{ u.retiredBy }} · <span class="num">{{ fmtDate(u.retiredAt) }}</span> — <span dir="auto">{{ u.retireReason }}</span></div>

    <div v-if="canManage && u.status !== 'retired'" class="flex flex-wrap gap-1.5">
      <Btn v-if="['not_onboarded', 'csr_ready'].includes(u.status)" size="sm" :tone="u.status === 'not_onboarded' ? 'primary' : 'soft'" :loading="isBusy(u, 'request')" :label="u.status === 'csr_ready' ? { ar: 'إعادة إنشاء الطلب', en: 'Make the request again' } : { ar: '1 · إنشاء المفتاح والطلب', en: '1 · Make key & request' }" @click="makeRequest(u)" />
      <Btn v-if="['csr_ready', 'compliance'].includes(u.status)" size="sm" :tone="u.status === 'csr_ready' ? 'primary' : 'soft'" :loading="isBusy(u, 'compliance')" :label="{ ar: '2 · شهادة الامتثال (OTP)', en: '2 · Compliance certificate (OTP)' }" @click="compliance(u)" />
      <Btn v-if="['compliance', 'compliant'].includes(u.status)" size="sm" :tone="u.status === 'compliance' ? 'primary' : 'soft'" :loading="isBusy(u, 'checks')" :label="{ ar: '3 · فحوص الامتثال', en: '3 · Compliance checks' }" @click="checks(u)" />
      <Btn v-if="u.status === 'compliant'" size="sm" tone="success" :loading="isBusy(u, 'production')" :label="{ ar: '4 · شهادة الإنتاج', en: '4 · Production certificate' }" @click="production(u)" />
      <Btn v-if="u.status === 'active'" size="sm" tone="softPurple" :loading="isBusy(u, 'renew')" :label="{ ar: 'تجديد الشهادة (OTP)', en: 'Renew certificate (OTP)' }" @click="renew(u)" />
      <Btn size="sm" tone="soft" :loading="isBusy(u, 'patch')" :label="u.sending ? { ar: 'إيقاف الإرسال', en: 'Pause sending' } : { ar: 'تشغيل الإرسال', en: 'Resume sending' }" @click="toggleSending(u)" />
      <Btn v-if="!u.isDefault" size="sm" tone="soft" :loading="isBusy(u, 'patch')" :label="{ ar: 'جعلها الافتراضية', en: 'Make default' }" @click="makeDefault(u)" />
      <Btn size="sm" tone="soft" :label="{ ar: 'تعديل', en: 'Edit' }" @click="editing = u" />
      <div class="grow" />
      <Btn size="sm" tone="dangerOutline" :loading="isBusy(u, 'retire')" :label="{ ar: 'إيقاف نهائي', en: 'Retire' }" @click="retire(u)" />
    </div>
  </SectionCard>

  <UnitForm :open="creating || !!editing" :unit="editing" @close="creating = false; editing = null" />
</template>
